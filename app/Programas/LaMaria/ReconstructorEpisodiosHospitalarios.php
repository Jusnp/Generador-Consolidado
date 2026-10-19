<?php

namespace App\Programas\LaMaria;

use DateTimeImmutable;

/**
 * Reconstruye las estancias hospitalarias lógicas de La María a partir de los
 * registros de hospitalización del RIPS.
 *
 * Un registro que egresa con condicionDestinoUsuarioEgreso = 08 es un corte de
 * facturación (el paciente continúa en el servicio), no un alta. Un corte solo
 * se une con su continuación cuando hay evidencia, sin ventanas de horas:
 *
 *  1. paciente identificado con tipo y número de documento;
 *  2. misma ruta de La María (si se conoce la de ambos registros);
 *  3. mismo prestador del servicio (codPrestador);
 *  4. el registro anterior termina en 08 (no es un alta real);
 *  5. el mismo número de autorización;
 *  6. fechas coherentes: la continuación inicia cuando terminó el corte o
 *     después, el mismo día calendario del corte o el siguiente;
 *  7. la continuación es la estancia inmediatamente siguiente (no hay otra
 *     estancia ni alta de por medio);
 *  8. un único candidato.
 *
 * Con todo lo anterior la continuidad queda CONFIRMADA y se une. Si solo hay
 * parte de la evidencia (por ejemplo, la misma autorización con fechas que no
 * son contiguas, o fechas contiguas con otra autorización) o hay varios
 * candidatos, queda CONTINUIDAD_NO_CONFIRMADA: no se une y la posible
 * continuación no genera paquetes propios hasta revisarla (tampoco se la trata
 * como una estancia nueva definitiva). Si la siguiente estancia no tiene
 * ninguna evidencia de continuidad, es una estancia independiente y el corte
 * queda con continuidad pendiente: solo se cobran los paquetes de su duración
 * conocida y nunca se inventa la fecha final.
 *
 * Registros idénticos del mismo paciente (mismo ingreso y egreso) son la misma
 * estancia. Los registros de facturas repetidas con contenido diferente forman
 * estancias separadas que no suman.
 */
class ReconstructorEpisodiosHospitalarios
{
    public const DESTINO_CORTE_FACTURACION = '08';

    public const ESTADO_CERRADO = 'CERRADO';

    public const ESTADO_CONTINUIDAD_PENDIENTE = 'CONTINUIDAD_HOSPITALARIA_PENDIENTE';

    public const ESTADO_SIN_FECHAS = 'SIN_FECHAS_VALIDAS';

    public const ESTADO_CONTINUIDAD_NO_CONFIRMADA = 'CONTINUIDAD_NO_CONFIRMADA';

    public const TIPO_HOSPITALIZACION = 'HOSPITALIZACION';

    public const TIPO_URGENCIA = 'URGENCIA';

    /**
     * Códigos de consulta de urgencias de la nota técnica.
     *
     * @var list<string>
     */
    public const CONSULTAS_URGENCIA = ['890701', '890702'];

    public function __construct(private PoliticaPaqueteHospitalario $politica = new PoliticaPaqueteHospitalario) {}

    /**
     * @param  list<array{clave: string, paciente: string, inicio: string, fin: string, destino: string, autorizacion?: string, prestadorServicio?: string, ruta?: string, excluido?: bool, consultasUrgencia?: list<array{codigo: string, consecutivo: string, fecha: string}>}>  $segmentos
     * @return list<array<string, mixed>>
     */
    public function reconstruir(array $segmentos): array
    {
        $porPaciente = [];

        foreach ($segmentos as $segmento) {
            $porPaciente[$segmento['paciente']][] = $segmento + [
                'excluido' => false,
                'consultasUrgencia' => [],
                'autorizacion' => '',
                'prestadorServicio' => '',
                'ruta' => '',
                'inicioFecha' => PoliticaPaqueteHospitalario::fecha($segmento['inicio']),
                'finFecha' => PoliticaPaqueteHospitalario::fecha($segmento['fin']),
            ];
        }

        $episodios = [];

        foreach ($porPaciente as $paciente => $lista) {
            usort($lista, static fn (array $a, array $b): int => [$a['inicio'], $a['fin'], $a['clave']] <=> [$b['inicio'], $b['fin'], $b['clave']]);
            $usados = [];
            [$enlaces, $noConfirmados, $retenidos] = $this->evaluarCortes((string) $paciente, $lista);

            foreach ($lista as $indice => $segmento) {
                if (isset($usados[$indice])) {
                    continue;
                }

                $usados[$indice] = true;
                $cadena = [$segmento];
                $duplicados = [];
                $valido = $this->tieneFechasValidas($segmento);

                if ($valido && ! $segmento['excluido']) {
                    $this->agregarCopias($lista, $usados, $segmento, $duplicados);
                    $actual = $indice;

                    while (isset($enlaces[$actual]) && ! isset($usados[$enlaces[$actual]])) {
                        $actual = $enlaces[$actual];
                        $usados[$actual] = true;
                        $cadena[] = $lista[$actual];
                        $this->agregarCopias($lista, $usados, $lista[$actual], $duplicados);
                    }
                }

                $ultimo = $cadena[array_key_last($cadena)]['clave'];
                $episodios[] = $this->episodio(
                    (string) $paciente,
                    $cadena,
                    $duplicados,
                    $valido,
                    $noConfirmados[$ultimo]['candidatos'] ?? [],
                    $noConfirmados[$ultimo]['motivo'] ?? '',
                    $retenidos[$indice] ?? ''
                );
            }
        }

        return $episodios;
    }

    /**
     * Evalúa la continuidad de cada corte 08 del paciente con la evidencia
     * disponible. Devuelve los enlaces confirmados (índice del corte → índice de
     * su continuación), los cortes no confirmados con sus candidatos y motivo, y
     * los segmentos retenidos (posibles continuaciones) con su motivo.
     *
     * @param  list<array<string, mixed>>  $lista
     * @return array{0: array<int, int>, 1: array<string, array{candidatos: list<string>, motivo: string}>, 2: array<int, string>}
     */
    private function evaluarCortes(string $paciente, array $lista): array
    {
        $enlaces = [];
        $noConfirmados = [];
        $retenidos = [];

        foreach ($lista as $indiceCorte => $corte) {
            if ($corte['destino'] !== self::DESTINO_CORTE_FACTURACION || $corte['excluido'] || ! $this->tieneFechasValidas($corte)
                || str_starts_with($paciente, 'SIN_DOCUMENTO|')) {
                continue;
            }

            // Estancias que inician cuando terminó el corte o después; la primera
            // fecha de inicio define la estancia inmediatamente siguiente.
            $posteriores = array_filter(
                $lista,
                fn (array $otro): bool => ! $otro['excluido'] && $this->tieneFechasValidas($otro)
                    && ! ($otro['inicio'] === $corte['inicio'] && $otro['fin'] === $corte['fin'])
                    && $otro['inicioFecha'] >= $corte['finFecha']
            );

            if ($posteriores === []) {
                continue;
            }

            // La estancia inmediatamente siguiente y las que se superponen con ella:
            // si son varias estancias distintas, los datos no permiten elegir.
            $primera = $posteriores[array_key_first($posteriores)];
            $siguientes = array_filter($posteriores, static fn (array $otro): bool => $otro['inicioFecha'] < $primera['finFecha'] || $otro['inicio'] === $primera['inicio']);
            $estancias = array_unique(array_map(static fn (array $otro): string => $otro['inicio'].'|'.$otro['fin'], $siguientes));

            if (count($estancias) > 1) {
                $this->noConfirmar($noConfirmados, $retenidos, $corte, $siguientes, 'VARIOS_CANDIDATOS');

                continue;
            }

            $indiceSiguiente = array_key_first($siguientes);
            $siguiente = $siguientes[$indiceSiguiente];
            $mismaAutorizacion = $corte['autorizacion'] !== '' && $corte['autorizacion'] === $siguiente['autorizacion'];
            $fechasContiguas = $siguiente['inicioFecha']->format('Y-m-d') <= $corte['finFecha']->modify('+1 day')->format('Y-m-d');
            $faltantes = array_keys(array_filter([
                'RUTA_DISTINTA' => $corte['ruta'] !== '' && $siguiente['ruta'] !== '' && $corte['ruta'] !== $siguiente['ruta'],
                'PRESTADOR_NO_COMPATIBLE' => $corte['prestadorServicio'] === '' || $corte['prestadorServicio'] !== $siguiente['prestadorServicio'],
                'AUTORIZACION_NO_COINCIDE' => ! $mismaAutorizacion,
                'FECHAS_NO_CONTIGUAS' => ! $fechasContiguas,
            ]));

            match (true) {
                $faltantes === [] => $enlaces[$indiceCorte] = $indiceSiguiente,
                // Sin autorización común ni fechas contiguas no hay evidencia de continuidad: estancia independiente.
                ! $mismaAutorizacion && ! $fechasContiguas => null,
                default => $this->noConfirmar($noConfirmados, $retenidos, $corte, $siguientes, implode('+', $faltantes)),
            };
        }

        return [$enlaces, $noConfirmados, $retenidos];
    }

    /**
     * @param  array<string, array{candidatos: list<string>, motivo: string}>  $noConfirmados
     * @param  array<int, string>  $retenidos
     * @param  array<string, mixed>  $corte
     * @param  array<int, array<string, mixed>>  $candidatos
     */
    private function noConfirmar(array &$noConfirmados, array &$retenidos, array $corte, array $candidatos, string $motivo): void
    {
        $noConfirmados[$corte['clave']] = [
            'candidatos' => array_values(array_map(static fn (array $candidato): string => $candidato['clave'], $candidatos)),
            'motivo' => $motivo,
        ];

        foreach (array_keys($candidatos) as $indice) {
            $retenidos[$indice] = $motivo;
        }
    }

    /**
     * Las copias idénticas (mismo ingreso y egreso) de un segmento son la misma estancia.
     *
     * @param  list<array<string, mixed>>  $lista
     * @param  array<int, true>  $usados
     * @param  array<string, mixed>  $segmento
     * @param  list<array<string, mixed>>  $duplicados
     */
    private function agregarCopias(array $lista, array &$usados, array $segmento, array &$duplicados): void
    {
        foreach ($lista as $otro => $candidato) {
            if (! isset($usados[$otro]) && ! $candidato['excluido']
                && $candidato['inicio'] === $segmento['inicio'] && $candidato['fin'] === $segmento['fin']) {
                $usados[$otro] = true;
                $duplicados[] = $candidato;
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $cadena
     * @param  list<array<string, mixed>>  $duplicados
     * @param  list<string>  $candidatosNoConfirmados  continuaciones posibles del último corte cuando no se confirmó
     * @param  string  $motivoRetenido  si no está vacío, el episodio es una posible continuación no confirmada de otro corte
     * @return array<string, mixed>
     */
    private function episodio(string $paciente, array $cadena, array $duplicados, bool $valido, array $candidatosNoConfirmados = [], string $motivoNoConfirmada = '', string $motivoRetenido = ''): array
    {
        $primero = $cadena[0];
        $ultimo = $cadena[array_key_last($cadena)];
        $base = [
            'id' => $paciente.'|'.$primero['clave'],
            'paciente' => $paciente,
            'segmentos' => array_map(static fn (array $segmento): string => $segmento['clave'], [...$cadena, ...$duplicados]),
            'segmentosOrdenados' => array_map(static fn (array $segmento): array => [
                'clave' => $segmento['clave'],
                'inicio' => $segmento['inicio'],
                'fin' => $segmento['fin'],
                'destino' => $segmento['destino'],
            ], $cadena),
            'segmentoFinal' => $ultimo['clave'],
            'cortesFacturacion' => count(array_filter(
                $cadena,
                static fn (array $segmento): bool => $segmento['destino'] === self::DESTINO_CORTE_FACTURACION
            )),
            'continuidadesResueltas' => count($cadena) - 1,
            'excluido' => $primero['excluido'],
            'inicio' => $primero['inicio'],
            'fin' => $ultimo['fin'],
            'segundos' => null,
            'paquetes' => 0,
            'bloques' => [],
            'consultaUrgencia' => null,
            'consultasUrgencia' => 0,
            'candidatosContinuidad' => $candidatosNoConfirmados,
            'motivoContinuidad' => $motivoRetenido !== '' ? $motivoRetenido : $motivoNoConfirmada,
            'retenidoPorContinuidadNoConfirmada' => $motivoRetenido !== '',
        ];

        if (! $valido) {
            return [...$base, 'estado' => self::ESTADO_SIN_FECHAS, 'tipo' => self::TIPO_HOSPITALIZACION];
        }

        $segundos = $ultimo['finFecha']->getTimestamp() - $primero['inicioFecha']->getTimestamp();

        // Su pertenencia a la estancia del corte es incierta: no genera cargos propios.
        if ($motivoRetenido !== '') {
            return [...$base, 'estado' => self::ESTADO_CONTINUIDAD_NO_CONFIRMADA, 'tipo' => self::TIPO_HOSPITALIZACION, 'segundos' => $segundos];
        }

        $pendiente = $ultimo['destino'] === self::DESTINO_CORTE_FACTURACION;
        $paquetes = $this->politica->paquetesPorDuracion($segundos);
        $esUrgencia = ! $pendiente && $paquetes === 0;
        $consultas = [];

        foreach ($esUrgencia ? $cadena : [] as $segmento) {
            foreach ($segmento['consultasUrgencia'] as $consulta) {
                $consultas[] = [...$consulta, 'segmento' => $segmento['clave']];
            }
        }

        return [
            ...$base,
            'estado' => match (true) {
                $candidatosNoConfirmados !== [] => self::ESTADO_CONTINUIDAD_NO_CONFIRMADA,
                $pendiente => self::ESTADO_CONTINUIDAD_PENDIENTE,
                default => self::ESTADO_CERRADO,
            },
            'tipo' => $esUrgencia ? self::TIPO_URGENCIA : self::TIPO_HOSPITALIZACION,
            'segundos' => $segundos,
            'paquetes' => $paquetes,
            'bloques' => $this->bloques($primero['inicioFecha'], $paquetes, $cadena),
            'consultaUrgencia' => count($consultas) === 1 ? $consultas[0] : null,
            'consultasUrgencia' => count($consultas),
        ];
    }

    /**
     * Cada paquete se imputa al mes en que comienza su bloque de 600 horas.
     *
     * @param  list<array<string, mixed>>  $cadena
     * @return list<array{numero: int, inicio: string, mes: string, segmento: string}>
     */
    private function bloques(DateTimeImmutable $ingreso, int $paquetes, array $cadena): array
    {
        $bloques = [];

        for ($numero = 1; $numero <= $paquetes; $numero++) {
            $inicio = $ingreso->modify('+'.(($numero - 1) * PoliticaPaqueteHospitalario::SEGUNDOS_POR_BLOQUE).' seconds');
            $segmento = $cadena[0];

            foreach ($cadena as $candidato) {
                if ($candidato['inicioFecha'] <= $inicio) {
                    $segmento = $candidato;
                }
            }

            $bloques[] = [
                'numero' => $numero,
                'inicio' => $inicio->format('Y-m-d H:i'),
                'mes' => $inicio->format('Y-m'),
                'segmento' => $segmento['clave'],
            ];
        }

        return $bloques;
    }

    /**
     * @param  array<string, mixed>  $segmento
     */
    private function tieneFechasValidas(array $segmento): bool
    {
        return $segmento['inicioFecha'] !== null
            && $segmento['finFecha'] !== null
            && $segmento['finFecha'] >= $segmento['inicioFecha'];
    }
}
