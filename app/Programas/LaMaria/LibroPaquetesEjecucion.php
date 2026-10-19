<?php

namespace App\Programas\LaMaria;

use App\Exceptions\LibroPaquetesException;
use App\Models\ActivityLog;
use App\Services\SeguimientoRutaClassifier;

/**
 * Conecta el libro de paquetes persistente con una ejecución de La María.
 *
 * Distingue el costo vigente de un período (lo que muestra el informe) del
 * movimiento económico que produce la ejecución (lo nuevo en el libro): al
 * reprocesar los mismos RIPS el informe conserva el costo del paquete y el
 * movimiento adicional es cero.
 *
 * Períodos de una ruta:
 *  - HISTORICO_NO_VERIFICADO: anteriores al inicio del libro sin incorporación
 *    conciliada; sus paquetes se informan como simulación y no generan cobros.
 *  - INCORPORADO_Y_CONCILIADO: anteriores al inicio con incorporación histórica aplicada.
 *  - PROCESADO_CON_LIBRO_NUEVO: desde el inicio del libro.
 */
class LibroPaquetesEjecucion
{
    public const ACCION_INICIO = 'libro_paquetes_inicio';

    public const ACCION_INCORPORACION = 'continuidad_incorporacion_historica';

    public const PERIODO_HISTORICO = 'HISTORICO_NO_VERIFICADO';

    public const PERIODO_INCORPORADO = 'INCORPORADO_Y_CONCILIADO';

    public const PERIODO_LIBRO = 'PROCESADO_CON_LIBRO_NUEVO';

    public function __construct(private AlmacenContinuidadHospitalaria $almacen = new AlmacenContinuidadHospitalariaBaseDatos) {}

    public static function modo(): ModoLibroPaquetes
    {
        return ModoLibroPaquetes::configurado();
    }

    /**
     * Último punto de inicio registrado por ruta (aaaa-mm).
     *
     * @return array<string, string>
     */
    public function iniciosPorRuta(): array
    {
        $inicios = [];

        foreach (ActivityLog::query()->where('action', self::ACCION_INICIO)->orderBy('id')->get(['metadata']) as $log) {
            $inicios[(string) ($log->metadata['ruta'] ?? '')] = (string) ($log->metadata['mes_inicio'] ?? '');
        }

        return array_filter($inicios);
    }

    public function estadoPeriodo(string $ruta, string $mes): string
    {
        $inicio = $this->iniciosPorRuta()[$ruta] ?? null;

        if ($inicio !== null && $mes >= $inicio) {
            return self::PERIODO_LIBRO;
        }

        $registros = ActivityLog::query()->where('action', self::ACCION_INCORPORACION)->get(['id', 'metadata']);
        $revertidos = $registros->pluck('metadata.reversion_de')->filter()->map(static fn ($id): int => (int) $id)->all();
        $incorporado = $registros->contains(static fn (ActivityLog $log): bool => ($log->metadata['aplicado'] ?? false) === true
            && ! in_array($log->id, $revertidos, true)
            && ($log->metadata['ruta'] ?? null) === $ruta
            && in_array($mes, $log->metadata['meses'] ?? [], true));

        return $incorporado ? self::PERIODO_INCORPORADO : self::PERIODO_HISTORICO;
    }

    /**
     * Prepara el clasificador para el modo PERSISTENTE. Sin punto de inicio para
     * la ruta, el modo se bloquea: nunca vuelve en silencio al cálculo por lote.
     *
     * @throws LibroPaquetesException
     */
    public function prepararClasificador(SeguimientoRutaClassifier $clasificador, string $ruta, bool $esCorreccion): void
    {
        $inicios = $this->iniciosPorRuta();

        if (! isset($inicios[$ruta])) {
            throw new LibroPaquetesException('El libro de paquetes persistente no tiene un punto de inicio para la ruta '.$ruta.'. Regístralo con php artisan continuidad:establecer-inicio antes de procesar en modo PERSISTENTE.');
        }

        $clasificador->usarLibroPersistente($this->almacen, $inicios, $esCorreccion);
    }

    /**
     * Registra en el libro los segmentos de la ruta de esta ejecución y concilia:
     * cada paquete que el informe suma debe estar reconocido en el libro.
     *
     * @param  array<int, array<string, mixed>>  $files
     * @param  array<int, array<string, mixed>>  $allProfiles
     * @param  array<int, array<string, mixed>>  $routeProfiles
     * @return array<string, mixed>
     *
     * @throws LibroPaquetesException
     */
    public function registrar(SeguimientoRutaClassifier $clasificador, array $files, array $allProfiles, array $routeProfiles, string $ruta, string $ejecucion, bool $esCorreccion): array
    {
        $inicios = $this->iniciosPorRuta();
        $segmentos = array_values(array_filter(
            $clasificador->hospitalSegmentsForLedger($files, $allProfiles),
            static fn (array $segmento): bool => $segmento['ruta'] === $ruta
        ));
        $libro = new LibroPaquetesHospitalarios($this->almacen);
        $resumen = $libro->registrarCarga($segmentos, $ejecucion, $esCorreccion, mesInicioPorRuta: $inicios);
        $meses = [];
        $reportados = [];
        $simulados = ['paquetes' => 0, 'valor' => 0.0];

        foreach ($routeProfiles as $profile) {
            $meses[(string) $profile['mesAtencion']] = true;
            $simulados['paquetes'] += (int) ($profile['controlHospitalario']['paquetesSimulados'] ?? 0);
            $simulados['valor'] += (float) ($profile['controlHospitalario']['costoPaquetesSimulados'] ?? 0.0);

            foreach ($profile['episodiosHospitalarios'] ?? [] as $bloque) {
                if ($bloque['suma']) {
                    $reportados[$bloque['paciente'].'|'.$bloque['inicioBloque']] = (string) $bloque['mesImputado'];
                }
            }
        }

        $faltantes = array_diff_key($reportados, $libro->bloquesVigentes($ruta));

        if ($faltantes !== []) {
            throw new LibroPaquetesException(sprintf(
                'El informe suma %d paquete(s) que el libro no tiene reconocidos (%s). No se registró nada.',
                count($faltantes),
                implode(', ', array_slice(array_keys($faltantes), 0, 3))
            ));
        }

        $movimientos = ['nuevos' => [0, 0.0], 'reversos' => [0, 0.0], 'ajustes_otros_periodos' => [0, 0.0]];

        foreach ($resumen['detalle_movimientos'] as $movimiento) {
            $tipo = match (true) {
                ! isset($meses[$movimiento['mes']]) => 'ajustes_otros_periodos',
                $movimiento['signo'] < 0 => 'reversos',
                default => 'nuevos',
            };
            $movimientos[$tipo][0] += $movimiento['signo'];
            $movimientos[$tipo][1] += $movimiento['signo'] * PoliticaPaqueteHospitalario::VALOR_PAQUETE;
        }

        ksort($meses);

        return [
            'modo' => ModoLibroPaquetes::Persistente->value,
            'inicio' => $inicios[$ruta] ?? '',
            'periodos' => array_map(fn (string $mes): string => $this->estadoPeriodo($ruta, $mes), array_combine(array_keys($meses), array_keys($meses))),
            'paquetes_reportados' => count($reportados),
            'valor_reportado' => round(count($reportados) * PoliticaPaqueteHospitalario::VALOR_PAQUETE, 2),
            'movimientos' => array_map(static fn (array $par): array => ['paquetes' => $par[0], 'valor' => round($par[1], 2)], $movimientos),
            'simulados' => ['paquetes' => $simulados['paquetes'], 'valor' => round($simulados['valor'], 2)],
            'resumen' => $resumen,
        ];
    }

    /**
     * Filas del bloque LIBRO DE PAQUETES del control de cargue.
     *
     * @param  array<string, mixed>|null  $resultado
     * @return list<array{0: string, 1: string|int|float, 2: string}>
     */
    public static function filasControl(?array $resultado): array
    {
        if ($resultado === null) {
            return [['MODO', ModoLibroPaquetes::PorLote->value, 'Paquetes calculados solo con los archivos de esta ejecución; no se usa ni se modifica el libro persistente.']];
        }

        $filas = [
            ['MODO', $resultado['modo'], 'Paquetes con el libro persistente entre ejecuciones.'],
            ['INICIO DEL LIBRO DE LA RUTA', $resultado['inicio'], 'Los meses anteriores no generan cobros: se informan como simulación.'],
            ['PAQUETES NETOS ATRIBUIDOS A LOS PERIODOS DEL INFORME', $resultado['paquetes_reportados'], 'Costo vigente: $'.number_format($resultado['valor_reportado'], 2, '.', ',')],
            ['MOVIMIENTOS NUEVOS DE ESTA EJECUCION', $resultado['movimientos']['nuevos']['paquetes'], '$'.number_format($resultado['movimientos']['nuevos']['valor'], 2, '.', ',')],
            ['MOVIMIENTOS DE REVERSO', $resultado['movimientos']['reversos']['paquetes'], '$'.number_format($resultado['movimientos']['reversos']['valor'], 2, '.', ',')],
            ['AJUSTES SOBRE OTROS PERIODOS', $resultado['movimientos']['ajustes_otros_periodos']['paquetes'], '$'.number_format($resultado['movimientos']['ajustes_otros_periodos']['valor'], 2, '.', ',').' (fuera de los meses de este informe; no suman aquí)'],
            ['PAQUETES SIMULADOS EN PERIODOS HISTORICOS', $resultado['simulados']['paquetes'], '$'.number_format($resultado['simulados']['valor'], 2, '.', ',').' del modelo nuevo, no cobrados: requieren revisión frente al histórico'],
            ['SEGMENTOS PENDIENTES DE REVISION', $resultado['resumen']['pendientes_revision'], 'Versión distinta de una factura ya registrada sin corrección autorizada: el informe usa la versión registrada.'],
            ['CONTINUIDADES NO CONFIRMADAS RETENIDAS', count($resultado['resumen']['retenidos']), 'No generan paquete propio hasta revisarlas.'],
            ['INICIOS NO VERIFICABLES', count($resultado['resumen']['inicios_no_verificados']), 'Inician el día 1 sin el mes anterior cargado: no se inventa el ingreso.'],
        ];

        foreach ($resultado['periodos'] as $mes => $estado) {
            $filas[] = ['ESTADO DEL PERIODO '.$mes, $estado, ''];
        }

        return $filas;
    }
}
