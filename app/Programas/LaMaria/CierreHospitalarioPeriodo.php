<?php

namespace App\Programas\LaMaria;

use App\Programas\LaMaria\ReconstructorEpisodiosHospitalarios as Reconstructor;
use InvalidArgumentException;

/**
 * Continuidad hospitalaria mensual con antecedente (RN-16 en la estimación).
 *
 * Con los episodios de un mes (reconstruidos por mes, POR_LOTE) y el cierre del
 * mes anterior: une cada tramo que inicia el día 1 a las 00:00 con la estancia
 * abierta del antecedente con las reglas del reconstructor (paciente con tipo y
 * número de documento, misma autorización, mismo prestador, corte 08 el día
 * anterior, candidato único y un solo tramo que la reclama), suma solo los
 * bloques que la estancia completa alcanza menos los ya reconocidos y produce el
 * cierre del mes. Lo que depende de un antecedente ausente, incompleto, ambiguo
 * o no acreditado queda condicionado; las estancias del antecedente sin
 * continuación quedan como evidencia, sin unidades.
 */
final class CierreHospitalarioPeriodo
{
    public const VERSION_REGLAS = 'RN16-600H-INGRESO-ORIGINAL;UNION-08-AUTORIZACION-DIA-SIGUIENTE;2026-10-28';

    public function __construct(
        private PoliticaPaqueteHospitalario $politica,
        private string $secreto,
    ) {
        if (strlen($secreto) < 32) {
            throw new InvalidArgumentException('El historial hospitalario requiere un secreto propio de al menos 32 caracteres (LA_MARIA_HISTORIAL_SECRETO).');
        }
    }

    /**
     * Identidad seudónima del paciente (TIPO|documento, como el reconstructor): HMAC con el secreto del historial,
     * nunca el documento.
     */
    public function clavePaciente(string $paciente): string
    {
        return hash_hmac('sha256', mb_strtoupper(trim($paciente)), $this->secreto);
    }

    /**
     * Identifica el secreto con que se seudonimizó un cierre sin revelarlo.
     */
    public function huellaSecreto(): string
    {
        return substr(hash_hmac('sha256', 'la-maria|historial-hospitalario|huella', $this->secreto), 0, 16);
    }

    /**
     * El tramo que inicia el día 1 a las 00:00 continúa la estancia abierta del antecedente: paciente identificado
     * con tipo y número de documento, misma autorización, mismo prestador y corte 08 el día anterior.
     *
     * @param  array<string, mixed>  $previa
     * @param  array<string, mixed>  $segmento  primer segmento del tramo
     */
    public function continua(array $previa, array $segmento, string $inicio): bool
    {
        $paciente = (string) ($segmento['paciente'] ?? '');
        $autorizacion = mb_strtoupper(trim((string) ($segmento['autorizacion'] ?? '')));
        $prestador = trim((string) ($segmento['prestadorServicio'] ?? ''));

        return substr($inicio, 8, 8) === '01 00:00'
            && preg_match('/^[^|]+\|[^|]+$/', $paciente) === 1 && ! str_starts_with($paciente, 'SIN_DOCUMENTO|')
            && $autorizacion !== '' && $prestador !== ''
            && $previa['clavePaciente'] === $this->clavePaciente($paciente)
            && $previa['autorizacion'] === $autorizacion
            && $previa['codPrestador'] === $prestador
            && substr((string) $previa['finCorte'], 0, 10) === date('Y-m-d', (int) strtotime($inicio.' -1 day'));
    }

    /**
     * @param  list<array{factura: string, archivo: string, sha256: string}>  $origenCargado
     */
    public function estadoAntecedente(?AntecedenteHospitalario $antecedente, HistorialHospitalario $historial, array $origenCargado): EstadoAntecedenteHospitalario
    {
        if ($antecedente === null) {
            return EstadoAntecedenteHospitalario::Ausente;
        }

        // Otro secreto (las claves del paciente no se comparan con la carga) o una versión reemplazada.
        if ($antecedente->huellaSecreto !== $this->huellaSecreto() || ! $antecedente->vigente) {
            return EstadoAntecedenteHospitalario::Incompleto;
        }

        if ($antecedente->version === 0) {
            return EstadoAntecedenteHospitalario::Comprobado;
        }

        $cerrados = array_column($antecedente->origen, 'sha256', 'factura');

        foreach ($origenCargado as $archivo) {
            if (isset($cerrados[$archivo['factura']]) && $cerrados[$archivo['factura']] !== $archivo['sha256']) {
                return EstadoAntecedenteHospitalario::Incompleto;
            }
        }

        // Toda la cadena hasta el arranque debe seguir vigente con el contenido con que se calculó cada cierre.
        for ($actual = $antecedente; $actual->tipo !== AntecedenteHospitalario::TIPO_ARRANQUE; $actual = $previo) {
            $previo = $historial->vigente($actual->ruta, self::mesAnterior($actual->periodo));

            if ($previo === null || $previo->huellaSecreto !== $this->huellaSecreto() || $previo->hashContenido() !== $actual->antecedenteHash) {
                return EstadoAntecedenteHospitalario::Incompleto;
            }
        }

        return EstadoAntecedenteHospitalario::Comprobado;
    }

    /**
     * @param  list<array<string, mixed>>  $episodios  episodios del mes y la ruta
     * @param  array<string, array<string, mixed>>  $segmentos
     * @param  list<array{factura: string, archivo: string, sha256: string}>  $origen
     * @return array{continuidades: list<array<string, mixed>>, cierre: AntecedenteHospitalario}
     */
    public function procesar(
        string $ruta,
        string $periodo,
        array $episodios,
        array $segmentos,
        ?AntecedenteHospitalario $antecedente,
        EstadoAntecedenteHospitalario $estado,
        array $origen = [],
    ): array {
        $pendientes = $antecedente?->abiertas() ?? [];
        $usadas = [];
        $ambiguas = [];
        $estancias = [];
        $continuidades = [];
        $etiqueta = $antecedente === null ? 'NINGUNO' : $antecedente->etiqueta().' · '.$estado->value;
        $candidatosPorEpisodio = array_map(fn (array $episodio): array => array_keys(array_filter($pendientes,
            fn (array $previa): bool => $this->continua($previa, $segmentos[$episodio['segmentosOrdenados'][0]['clave']], (string) $episodio['inicio']))), $episodios);
        $reclamos = array_count_values(array_merge(...array_values($candidatosPorEpisodio)));

        foreach ($episodios as $indiceEpisodio => $episodio) {
            $ordenados = $episodio['segmentosOrdenados'];
            $primero = $segmentos[$ordenados[0]['clave']];
            $ultimo = $segmentos[$ordenados[array_key_last($ordenados)]['clave']];
            $paciente = $this->clavePaciente((string) ($primero['paciente'] ?? ''));
            $autorizacion = mb_strtoupper(trim((string) $primero['autorizacion']));
            $inicio = (string) $episodio['inicio'];
            $fin = (string) $episodio['fin'];
            $ingreso = $inicio;
            $reconocidos = 0;
            $bloquesPrevios = [];
            $cortesPrevios = 0;
            $bloquesMes = (int) $episodio['paquetes'];
            $estadoEstancia = EstadoEstanciaHospitalaria::AbiertaVerificada;
            $motivo = 'INICIO EN EL PERIODO';

            if (substr($inicio, 8, 8) === '01 00:00') {
                $candidatos = $candidatosPorEpisodio[$indiceEpisodio];

                if ($antecedente !== null && count($candidatos) === 1 && $reclamos[$candidatos[0]] === 1) {
                    $previa = $pendientes[$candidatos[0]];
                    $usadas[$candidatos[0]] = true;
                    $ingreso = (string) $previa['ingresoOriginal'];
                    $reconocidos = (int) $previa['bloquesReconocidos'];
                    $bloquesPrevios = $previa['bloquesPeriodo'];
                    $cortesPrevios = (int) $previa['cortes08'];
                    $bloquesMes = max(0, ($this->politica->paquetesPorEstancia($ingreso, $fin) ?? 0) - $reconocidos);
                    $verificada = $estado === EstadoAntecedenteHospitalario::Comprobado
                        && EstadoEstanciaHospitalaria::from((string) $previa['estado'])->verificada();
                    $motivo = $verificada ? Reconstructor::CONTINUACION_UNIDA : Reconstructor::CONTINUACION_NO_COMPROBADA;
                    $estadoEstancia = $verificada ? EstadoEstanciaHospitalaria::ContinuacionAcreditada : EstadoEstanciaHospitalaria::PendienteDeSoporte;
                } elseif ($candidatos !== []) {
                    // Varias estancias o varios tramos posibles: no se elige ninguno ni se cuenta como estancia nueva.
                    $ambiguas += array_fill_keys($candidatos, true);
                    $motivo = Reconstructor::CONTINUACION_NO_COMPROBADA;
                    $estadoEstancia = EstadoEstanciaHospitalaria::PendienteDeSoporte;
                } elseif ($estado === EstadoAntecedenteHospitalario::Comprobado) {
                    // El antecedente comprobado no tiene el corte: la ausencia está verificada y la estancia es nueva.
                    $motivo = Reconstructor::INICIO_SIN_CORTE_PREVIO;
                } else {
                    $motivo = Reconstructor::INICIO_SIN_ANTECEDENTE;
                    $estadoEstancia = EstadoEstanciaHospitalaria::PendienteDeSoporte;
                }

                $continuidades[] = [
                    'ruta' => $ruta, 'mes' => $periodo, 'documento' => (string) $primero['documento'], 'inicio' => $ingreso, 'fin' => $fin,
                    'bloquesLote' => (int) $episodio['paquetes'], 'bloquesEstancia' => $bloquesMes, 'ajuste' => $bloquesMes - (int) $episodio['paquetes'],
                    'motivo' => $motivo, 'antecedente' => $etiqueta,
                ];
            }

            $abierta = (string) $ultimo['destino'] === Reconstructor::DESTINO_CORTE_FACTURACION;

            if (! $abierta && $motivo !== Reconstructor::CONTINUACION_UNIDA) {
                continue;
            }

            $estancias[] = $this->fila(
                $paciente, $autorizacion, trim((string) $primero['prestadorServicio']), $ingreso, $inicio, $fin, (string) $ultimo['destino'],
                $cortesPrevios + count(array_filter($ordenados, static fn (array $s): bool => (string) $s['destino'] === Reconstructor::DESTINO_CORTE_FACTURACION)),
                $reconocidos + $bloquesMes, [...$bloquesPrevios, ...$this->bloques($ingreso, $reconocidos, $bloquesMes, $inicio)],
                array_map(static fn (array $s): string => $segmentos[$s['clave']]['factura'].'#'.$segmentos[$s['clave']]['consecutivo'], $ordenados),
                $abierta ? $estadoEstancia : EstadoEstanciaHospitalaria::CerradaConSoporte, $motivo,
            );
        }

        foreach ($pendientes as $indice => $previa) {
            if (! isset($usadas[$indice])) {
                $estancias[] = [...$previa, 'estado' => EstadoEstanciaHospitalaria::SinContinuacionObservada->value,
                    'continuidad' => (isset($ambiguas[$indice]) ? 'CONTINUACION AMBIGUA' : 'SIN CONTINUACION').' EN LOS RIPS DE '.$periodo];
            }
        }

        return [
            'continuidades' => $continuidades,
            'cierre' => new AntecedenteHospitalario($ruta, $periodo, 0, AntecedenteHospitalario::TIPO_PROVISIONAL, $estancias, $origen, self::VERSION_REGLAS,
                $antecedente?->id, $antecedente?->hashContenido(), $antecedente?->periodo, $antecedente?->version, huellaSecreto: $this->huellaSecreto()),
        ];
    }

    public static function mesAnterior(string $periodo): string
    {
        return date('Y-m', (int) strtotime($periodo.'-01 -1 month'));
    }

    public static function mesSiguiente(string $periodo): string
    {
        return date('Y-m', (int) strtotime($periodo.'-01 +1 month'));
    }

    /**
     * Período económico de cada bloque nuevo: el mes en que empieza su tramo de 600 horas (como la imputación de
     * paquetes), o el mes del tramo si el bloque empezó antes y la estancia lo alcanza con la evidencia de ese tramo.
     *
     * @return list<array{numero: int, inicio: string, periodo: string}>
     */
    private function bloques(string $ingreso, int $reconocidos, int $nuevos, string $inicioTramo): array
    {
        $bloques = [];

        for ($numero = $reconocidos; $numero < $reconocidos + $nuevos; $numero++) {
            $inicio = date('Y-m-d H:i', (int) strtotime($ingreso) + $numero * PoliticaPaqueteHospitalario::SEGUNDOS_POR_BLOQUE);
            $bloques[] = ['numero' => $numero + 1, 'inicio' => $inicio, 'periodo' => max(substr($inicio, 0, 7), substr($inicioTramo, 0, 7))];
        }

        return $bloques;
    }

    /**
     * Estancia en orden canónico de campos: la huella del cierre depende de él.
     *
     * @param  list<array{numero: int, inicio: string, periodo: string}>  $bloquesPeriodo
     * @param  list<string>  $tramos
     * @return array<string, mixed>
     */
    private function fila(
        string $paciente, string $autorizacion, string $prestador, string $ingreso, string $inicioTramo, string $fin, string $destino,
        int $cortes08, int $bloquesReconocidos, array $bloquesPeriodo, array $tramos, EstadoEstanciaHospitalaria $estado, string $continuidad,
    ): array {
        return [
            'clavePaciente' => $paciente, 'autorizacion' => $autorizacion, 'codPrestador' => $prestador, 'ingresoOriginal' => $ingreso,
            'inicioTramo' => $inicioTramo, 'finCorte' => $fin, 'destinoFinal' => $destino, 'cortes08' => $cortes08,
            'bloquesReconocidos' => $bloquesReconocidos, 'bloquesPeriodo' => $bloquesPeriodo, 'tramosOrigen' => $tramos,
            'estado' => $estado->value, 'continuidad' => $continuidad,
        ];
    }
}
