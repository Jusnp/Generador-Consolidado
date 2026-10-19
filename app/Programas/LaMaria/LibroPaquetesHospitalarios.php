<?php

namespace App\Programas\LaMaria;

use App\Exceptions\ConcurrenciaContinuidadException;
use DateTimeImmutable;

/**
 * Reconoce los paquetes hospitalarios acumulando las cargas de RIPS en
 * cualquier orden: guarda cada segmento de hospitalización (versionado por
 * factura, paciente y consecutivo, nunca por contenido), reconstruye los
 * episodios del paciente con TODOS sus segmentos vigentes y publica en el libro
 * solo la diferencia con lo ya reconocido (+1 reconocimiento, −1 reverso). Así,
 * para el mismo conjunto completo de RIPS, el neto por mes y por bloque es el
 * mismo sin importar el orden ni la agrupación de las cargas.
 */
class LibroPaquetesHospitalarios
{
    public const REINTENTOS = 5;

    public const MOTIVO_RECONOCIMIENTO = 'RECONOCIMIENTO';

    public const MOTIVO_REVERSO = 'REVERSO_POR_CONTINUIDAD';

    public const MOTIVO_CORRECCION = 'CORRECCION_HISTORICA';

    /**
     * Paquete ya informado en una ejecución histórica e incorporado al libro.
     */
    public const MOTIVO_SALDO_INICIAL = 'SALDO_INICIAL';

    public function __construct(
        private AlmacenContinuidadHospitalaria $almacen,
        private ReconstructorEpisodiosHospitalarios $reconstructor = new ReconstructorEpisodiosHospitalarios,
    ) {}

    /**
     * Sin tipo y número de documento no hay identidad de paciente: el segmento
     * no entra al libro (se cuenta en sin_identidad) y nunca se le inventa una.
     *
     * @param  list<array{ruta: string, prestador?: string, factura: string, tipoDocumento: string, documento: string, consecutivo: string, inicio: string, fin: string, destino: string, archivo?: string}>  $segmentos
     * @param  string|null  $motivoReconocimiento  SALDO_INICIAL al incorporar paquetes ya informados en ejecuciones históricas
     * @param  array<string, string>  $mesInicioPorRuta  inicio del libro por ruta (aaaa-mm): los bloques de meses anteriores no generan movimientos económicos
     * @return array{nuevos: int, sin_cambio: int, pendientes_revision: int, corregidos: int, movimientos: int, reintentos: int, sin_identidad: int, retenidos: list<string>, inicios_no_verificados: list<string>, detalle_movimientos: list<array{ruta: string, paciente: string, bloque_inicio: string, mes: string, signo: int, motivo: string}>, bloques_historicos: list<array{ruta: string, paciente: string, bloque_inicio: string, mes: string}>, diferencias_historicas: list<array{ruta: string, paciente: string, bloque_inicio: string, mes: string}>}
     *
     * @throws ConcurrenciaContinuidadException
     */
    public function registrarCarga(array $segmentos, string $ejecucion, bool $esCorreccion = false, ?string $motivoReconocimiento = null, ?int $programaEjecucionId = null, array $mesInicioPorRuta = []): array
    {
        $resumen = ['nuevos' => 0, 'sin_cambio' => 0, 'pendientes_revision' => 0, 'corregidos' => 0, 'movimientos' => 0, 'reintentos' => 0, 'sin_identidad' => 0, 'retenidos' => [], 'inicios_no_verificados' => [], 'detalle_movimientos' => [], 'bloques_historicos' => [], 'diferencias_historicas' => []];
        $porPaciente = [];

        foreach ($segmentos as $segmento) {
            if (trim((string) ($segmento['tipoDocumento'] ?? '')) === '' || trim((string) ($segmento['documento'] ?? '')) === '') {
                $resumen['sin_identidad']++;

                continue;
            }

            $porPaciente[$segmento['ruta']][$this->paciente($segmento)][] = $segmento;
        }

        foreach ($porPaciente as $ruta => $pacientes) {
            foreach ($pacientes as $paciente => $lista) {
                for ($intento = 0; ; $intento++) {
                    try {
                        $parcial = $this->conciliar((string) $ruta, (string) $paciente, $lista, $ejecucion, $esCorreccion, $motivoReconocimiento, $programaEjecucionId, $mesInicioPorRuta[(string) $ruta] ?? null);

                        break;
                    } catch (ConcurrenciaContinuidadException $exception) {
                        if ($intento >= self::REINTENTOS) {
                            throw $exception;
                        }

                        $resumen['reintentos']++;
                    }
                }

                foreach ($parcial as $campo => $valor) {
                    $resumen[$campo] = is_array($valor) ? [...$resumen[$campo], ...$valor] : $resumen[$campo] + $valor;
                }
            }
        }

        return $resumen;
    }

    /**
     * Paquetes y valor netos por mes contable (mes en que inicia cada bloque).
     *
     * @return array<string, array{paquetes: int, valor: float}>
     */
    public function netoPorMes(string $ruta): array
    {
        $meses = [];

        foreach ($this->almacen->movimientos($ruta) as $movimiento) {
            $meses[$movimiento['mes_imputado']]['paquetes'] = ($meses[$movimiento['mes_imputado']]['paquetes'] ?? 0) + $movimiento['signo'];
            $meses[$movimiento['mes_imputado']]['valor'] = round(($meses[$movimiento['mes_imputado']]['valor'] ?? 0.0) + $movimiento['valor'], 2);
        }

        ksort($meses);

        return array_filter($meses, static fn (array $mes): bool => $mes['paquetes'] !== 0 || $mes['valor'] !== 0.0);
    }

    /**
     * Bloques con reconocimiento vigente (neto positivo) y su mes contable.
     *
     * @return array<string, string>
     */
    public function bloquesVigentes(string $ruta): array
    {
        $neto = [];
        $mes = [];

        foreach ($this->almacen->movimientos($ruta) as $movimiento) {
            $bloque = $movimiento['paciente'].'|'.$movimiento['bloque_inicio'];
            $neto[$bloque] = ($neto[$bloque] ?? 0) + $movimiento['signo'];
            $mes[$bloque] = $movimiento['mes_imputado'];
        }

        $vigentes = array_intersect_key($mes, array_filter($neto, static fn (int $valor): bool => $valor > 0));
        ksort($vigentes);

        return $vigentes;
    }

    /**
     * @param  list<array<string, string>>  $entrada
     * @return array<string, int|list<string>>
     */
    private function conciliar(string $ruta, string $paciente, array $entrada, string $ejecucion, bool $esCorreccion, ?string $motivoReconocimiento, ?int $programaEjecucionId, ?string $mesInicio): array
    {
        $version = $this->almacen->version($ruta, $paciente);
        $existentes = $this->almacen->segmentos($ruta, $paciente);
        $stats = ['nuevos' => 0, 'sin_cambio' => 0, 'pendientes_revision' => 0, 'corregidos' => 0, 'movimientos' => 0, 'retenidos' => [], 'inicios_no_verificados' => [], 'detalle_movimientos' => [], 'bloques_historicos' => [], 'diferencias_historicas' => []];
        $nuevos = [];
        $reemplazados = [];

        foreach ($entrada as $segmento) {
            $segmento['inicio'] = self::fechaNormalizada((string) $segmento['inicio']);
            $segmento['fin'] = self::fechaNormalizada((string) $segmento['fin']);
            $identidad = self::identidad($ruta, $paciente, $segmento);
            $hash = self::huella($segmento);
            $versiones = array_values(array_filter([...$existentes, ...$nuevos], static fn (array $guardado): bool => $guardado['identidad'] === $identidad));
            $vigente = null;

            foreach ($versiones as $guardado) {
                if ($guardado['estado'] === 'VIGENTE' && ! in_array($guardado['identidad'].'#'.$guardado['version'], $reemplazados, true)) {
                    $vigente = $guardado;
                }
            }

            $yaRegistrado = $vigente !== null && $vigente['hash_contenido'] === $hash
                || (! $esCorreccion && collect($versiones)->contains(static fn (array $guardado): bool => $guardado['estado'] === 'PENDIENTE_REVISION' && $guardado['hash_contenido'] === $hash));

            if ($yaRegistrado) {
                $stats['sin_cambio']++;

                continue;
            }

            $estado = $vigente === null || $esCorreccion ? 'VIGENTE' : 'PENDIENTE_REVISION';

            if ($vigente !== null && $esCorreccion) {
                $reemplazados[] = $vigente['identidad'].'#'.$vigente['version'];
                $stats['corregidos']++;
            } else {
                $stats[$estado === 'VIGENTE' ? 'nuevos' : 'pendientes_revision']++;
            }

            $nuevos[] = [
                'identidad' => $identidad,
                'version' => count($versiones) + 1,
                'ruta' => $ruta,
                'paciente' => $paciente,
                'prestador' => (string) ($segmento['prestador'] ?? ''),
                'tipo_documento' => strtoupper(trim($segmento['tipoDocumento'])),
                'documento' => trim($segmento['documento']),
                'factura' => $segmento['factura'],
                'consecutivo' => $segmento['consecutivo'],
                'inicio' => $segmento['inicio'],
                'fin' => $segmento['fin'],
                'fecha_corte' => $segmento['destino'] === ReconstructorEpisodiosHospitalarios::DESTINO_CORTE_FACTURACION ? $segmento['fin'] : null,
                'destino' => $segmento['destino'],
                'autorizacion' => (string) ($segmento['autorizacion'] ?? ''),
                'prestador_servicio' => (string) ($segmento['prestadorServicio'] ?? ''),
                'hash_contenido' => $hash,
                'estado' => $estado,
                'reemplaza' => $vigente !== null && $esCorreccion ? $vigente['identidad'].'#'.$vigente['version'] : null,
                'archivo' => $segmento['archivo'] ?? '',
                'ejecucion' => $ejecucion,
                'programa_ejecucion_id' => $programaEjecucionId,
            ];
        }

        $vigentes = array_values(array_filter(
            [...$existentes, ...$nuevos],
            static fn (array $guardado): bool => $guardado['estado'] === 'VIGENTE' && ! in_array($guardado['identidad'].'#'.$guardado['version'], $reemplazados, true)
        ));
        $mesesCargados = array_flip([...$this->almacen->mesesCargados($ruta), ...array_map(static fn (array $guardado): string => substr($guardado['inicio'], 0, 7), $vigentes)]);
        $deseados = [];

        foreach ($this->reconstructor->reconstruir(array_map(static fn (array $guardado): array => [
            'clave' => $guardado['identidad'].'#'.$guardado['version'],
            'paciente' => $paciente,
            'inicio' => $guardado['inicio'],
            'fin' => $guardado['fin'],
            'destino' => $guardado['destino'],
            'autorizacion' => (string) ($guardado['autorizacion'] ?? ''),
            'prestadorServicio' => (string) ($guardado['prestador_servicio'] ?? ''),
            'ruta' => $ruta,
        ], $vigentes)) as $episodio) {
            if ($episodio['retenidoPorContinuidadNoConfirmada'] ?? false) {
                $stats['retenidos'][] = $episodio['segmentoFinal'];
            }

            if ($this->inicioNoVerificado((string) $episodio['inicio'], $mesesCargados)) {
                $stats['inicios_no_verificados'][] = $episodio['segmentosOrdenados'][0]['clave'];
            }

            foreach ($episodio['bloques'] as $bloque) {
                $deseados[$bloque['inicio']] = [
                    'mes' => $bloque['mes'],
                    'numero' => $bloque['numero'],
                    'ancla' => $episodio['segmentosOrdenados'][0]['clave'],
                    'episodio' => $ruta.'|'.$paciente.'|'.$episodio['segmentosOrdenados'][0]['clave'],
                    'segmentos' => $episodio['segmentos'],
                    'estado_episodio' => $episodio['estado'],
                ];
            }
        }

        $neto = [];
        $mesActual = [];

        foreach ($this->almacen->movimientos($ruta, $paciente) as $movimiento) {
            $neto[$movimiento['bloque_inicio']] = ($neto[$movimiento['bloque_inicio']] ?? 0) + $movimiento['signo'];
            $mesActual[$movimiento['bloque_inicio']] = $movimiento['mes_imputado'];
        }

        $movimientos = [];
        $base = static fn (string $inicio, string $mes, int $signo, string $motivo, array $detalle): array => [
            'ruta' => $ruta,
            'paciente' => $paciente,
            'bloque_inicio' => $inicio,
            'numero_bloque' => $detalle['numero'] ?? null,
            'mes_imputado' => $mes,
            'signo' => $signo,
            'valor' => $signo * PoliticaPaqueteHospitalario::VALOR_PAQUETE,
            'motivo' => $motivo,
            'segmento_ancla' => $detalle['ancla'] ?? null,
            'episodio_clave' => $detalle['episodio'] ?? null,
            'tarifa_paquete' => PoliticaPaqueteHospitalario::VALOR_PAQUETE,
            'programa_ejecucion_id' => $programaEjecucionId,
            'segmentos' => $detalle['segmentos'] ?? [],
            'estado_episodio' => $detalle['estado_episodio'] ?? '',
            'ejecucion' => $ejecucion,
        ];

        // Antes del inicio del libro no hay movimientos económicos: el bloque se
        // informa como simulación y, si difiere de lo registrado, como diferencia.
        $historico = static fn (string $mes): bool => $mesInicio !== null && $mes < $mesInicio;

        foreach ($deseados as $inicio => $detalle) {
            if ($historico($detalle['mes'])) {
                $stats['bloques_historicos'][] = ['ruta' => $ruta, 'paciente' => $paciente, 'bloque_inicio' => (string) $inicio, 'mes' => $detalle['mes']];

                // Solo hay referencia histórica si el mes fue incorporado al libro.
                if (($neto[$inicio] ?? 0) <= 0 && in_array($detalle['mes'], $mesActual, true)) {
                    $stats['diferencias_historicas'][] = ['ruta' => $ruta, 'paciente' => $paciente, 'bloque_inicio' => (string) $inicio, 'mes' => $detalle['mes']];
                }

                continue;
            }

            if (($neto[$inicio] ?? 0) <= 0) {
                $movimientos[] = $base((string) $inicio, $detalle['mes'], 1, $motivoReconocimiento ?? ($esCorreccion ? self::MOTIVO_CORRECCION : self::MOTIVO_RECONOCIMIENTO), $detalle);
            }
        }

        foreach ($neto as $inicio => $valor) {
            if ($valor > 0 && ! isset($deseados[$inicio]) && $historico($mesActual[$inicio])) {
                $stats['diferencias_historicas'][] = ['ruta' => $ruta, 'paciente' => $paciente, 'bloque_inicio' => (string) $inicio, 'mes' => $mesActual[$inicio]];

                continue;
            }

            if ($valor > 0 && ! isset($deseados[$inicio])) {
                $movimientos[] = $base((string) $inicio, $mesActual[$inicio], -1, $esCorreccion ? self::MOTIVO_CORRECCION : self::MOTIVO_REVERSO, []);
            }
        }

        if ($nuevos !== [] || $movimientos !== []) {
            $this->almacen->confirmar($ruta, $paciente, $version, ['segmentos' => $nuevos, 'reemplazados' => $reemplazados, 'movimientos' => $movimientos]);
        }

        $stats['movimientos'] = count($movimientos);
        $stats['detalle_movimientos'] = array_map(static fn (array $movimiento): array => [
            'ruta' => $ruta, 'paciente' => $paciente, 'bloque_inicio' => $movimiento['bloque_inicio'], 'mes' => $movimiento['mes_imputado'],
            'signo' => $movimiento['signo'], 'motivo' => $movimiento['motivo'],
        ], $movimientos);

        return $stats;
    }

    /**
     * Señal (no regla): un episodio que inicia el primer día del mes puede ser la
     * continuación de un corte 08 del mes anterior. Si ese mes no está cargado,
     * su ingreso real no se puede verificar: se usa el conocido y se informa. No
     * bloquea la carga ni cambia los paquetes.
     *
     * @param  array<string, int>  $mesesCargados
     */
    private function inicioNoVerificado(string $inicio, array $mesesCargados): bool
    {
        $fecha = PoliticaPaqueteHospitalario::fecha($inicio);

        if ($fecha === null || $fecha->format('d') !== '01') {
            return false;
        }

        return ! isset($mesesCargados[(new DateTimeImmutable($fecha->format('Y-m-01')))->modify('-1 month')->format('Y-m')]);
    }

    /**
     * Identidad de procedencia de un segmento: ruta, prestador (obligado),
     * factura, paciente y consecutivo del servicio. Nunca su contenido.
     *
     * @param  array<string, mixed>  $segmento
     */
    public static function identidad(string $ruta, string $paciente, array $segmento): string
    {
        return implode('|', [$ruta, (string) ($segmento['prestador'] ?? ''), (string) $segmento['factura'], $paciente, (string) $segmento['consecutivo']]);
    }

    /**
     * Huella del contenido de un segmento: solo detecta cambios de una misma identidad.
     *
     * @param  array<string, mixed>  $segmento
     */
    public static function huella(array $segmento): string
    {
        return hash('sha256', implode('|', [
            self::fechaNormalizada((string) $segmento['inicio']),
            self::fechaNormalizada((string) $segmento['fin']),
            (string) $segmento['destino'],
            (string) ($segmento['autorizacion'] ?? ''),
            (string) ($segmento['prestadorServicio'] ?? ''),
        ]));
    }

    /**
     * Fecha del RIPS en el formato del reconstructor; si no se interpreta se
     * conserva tal cual (el episodio quedará sin fechas válidas).
     */
    public static function fechaNormalizada(string $fecha): string
    {
        return PoliticaPaqueteHospitalario::fecha($fecha)?->format('Y-m-d H:i') ?? trim($fecha);
    }

    /**
     * @param  array<string, string>  $segmento
     */
    private function paciente(array $segmento): string
    {
        return strtoupper(trim($segmento['tipoDocumento'])).'|'.trim($segmento['documento']);
    }
}
