<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\ProgramaEjecucion;
use App\Programas\LaMaria\AlmacenContinuidadHospitalariaBaseDatos;
use App\Programas\LaMaria\LibroPaquetesEjecucion;
use App\Programas\LaMaria\LibroPaquetesHospitalarios;
use App\Services\ActivityLogger;
use App\Services\SeguimientoRutaClassifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class IncorporarContinuidadHistorica extends Command
{
    protected $signature = 'continuidad:incorporar-historico
        {directorio? : Carpeta con los JSON RIPS de la ejecución histórica de referencia}
        {--ejecucion= : Id de programa_ejecuciones cuyo informe entregado ya incluyó esos paquetes}
        {--aplicar : Registra el saldo inicial; solo se permite con una referencia verificable}
        {--revertir= : Id del registro de auditoría de una incorporación aplicada que se debe revertir}';

    protected $description = 'Incorpora al libro de paquetes de La María, como SALDO_INICIAL, los paquetes de una ejecución histórica verificable (por defecto solo simula)';

    public function handle(SeguimientoRutaClassifier $classifier, ActivityLogger $logger): int
    {
        if ($this->option('revertir') !== null) {
            return $this->revertir((int) $this->option('revertir'), $logger);
        }

        $ejecucion = ProgramaEjecucion::query()->find((int) $this->option('ejecucion'));

        if ($ejecucion === null || ! str_starts_with((string) $ejecucion->programa_slug, 'la-maria-')) {
            $this->error('Indica con --ejecucion el id de una ejecución histórica de La María.');

            return self::FAILURE;
        }

        $ruta = strtoupper(substr((string) $ejecucion->programa_slug, strlen('la-maria-')));
        $rutas = glob(rtrim((string) $this->argument('directorio'), '/\\').'/*.json') ?: [];
        $huellas = [];

        foreach ($rutas as $path) {
            $huellas[basename($path)] = (string) hash_file('sha256', $path);
        }

        ksort($huellas);
        $registro = ActivityLog::query()->where('action', 'seguimiento_rutas')->get()
            ->first(static fn (ActivityLog $log): bool => ($log->metadata['batch_id'] ?? null) === $ejecucion->batch_id);
        $esperados = $registro === null ? [] : array_values(array_map('strval', $registro->metadata['files'] ?? []));
        sort($esperados);
        $huellasEjecucion = $registro?->metadata['files_sha256'] ?? null;

        // Rechazos: fuente incompleta o contradictoria con lo que registró la ejecución.
        $rechazos = array_values(array_filter([
            $registro === null ? 'La ejecución no tiene registro de auditoría con su lista de archivos.' : null,
            $registro !== null && ($registro->metadata['ruta'] ?? '') !== $ruta ? 'La auditoría de la ejecución es de otra ruta.' : null,
            $huellas === [] ? 'La carpeta no tiene archivos JSON.' : null,
            $registro !== null && array_keys($huellas) !== $esperados ? sprintf(
                'Los archivos no coinciden con los de la ejecución (faltan: %s; sobran: %s).',
                implode(', ', array_diff($esperados, array_keys($huellas))) ?: 'ninguno',
                implode(', ', array_diff(array_keys($huellas), $esperados)) ?: 'ninguno'
            ) : null,
            is_array($huellasEjecucion) && array_diff_assoc($huellas, $huellasEjecucion) !== [] ? 'El contenido (SHA-256) de algún archivo difiere del procesado en la ejecución.' : null,
        ]));

        // Verificable solo si la ejecución guardó las huellas de sus archivos y su informe existe.
        $noVerificable = array_values(array_filter([
            is_array($huellasEjecucion) ? null : 'La ejecución no guardó las huellas SHA-256 de sus archivos.',
            is_file(storage_path('app/generated/'.$ejecucion->archivo_salida)) ? null : 'El informe entregado de la ejecución ('.$ejecucion->archivo_salida.') no existe.',
        ]));
        $aplicar = (bool) $this->option('aplicar');
        $auditoria = [
            'ejecucion_id' => $ejecucion->id,
            'ruta' => $ruta,
            'archivos_sha256' => $huellas,
            'rechazos' => $rechazos,
            'referencia_verificable' => $noVerificable === [],
            'motivos_no_verificable' => $noVerificable,
            'aplicado' => false,
        ];

        if ($rechazos !== []) {
            array_map(fn (string $motivo) => $this->error($motivo), $rechazos);
            $logger->log(LibroPaquetesEjecucion::ACCION_INCORPORACION, 'Incorporación histórica rechazada.', $auditoria);

            return self::FAILURE;
        }

        if ($aplicar && $noVerificable !== []) {
            $this->error('REFERENCIA HISTÓRICA NO VERIFICADA: no se permite --aplicar.');
            array_map(fn (string $motivo) => $this->line(' - '.$motivo), $noVerificable);
            $logger->log(LibroPaquetesEjecucion::ACCION_INCORPORACION, 'Incorporación histórica no aplicada: referencia no verificable.', $auditoria);

            return self::FAILURE;
        }

        $archivos = array_map(static fn (string $path): array => ['path' => $path, 'fileName' => basename($path)], $rutas);
        $procesables = $classifier->processableFiles($classifier->screenFiles($archivos));
        $segmentos = array_values(array_filter(
            $classifier->hospitalSegmentsForLedger($procesables, $classifier->profilesForFiles($procesables)),
            static fn (array $segmento): bool => $segmento['ruta'] === $ruta
        ));

        if ($segmentos === []) {
            $this->error('Los archivos no tienen segmentos hospitalarios de la ruta '.$ruta.'.');
            $logger->log(LibroPaquetesEjecucion::ACCION_INCORPORACION, 'Incorporación histórica rechazada: sin segmentos de la ruta.', $auditoria);

            return self::FAILURE;
        }

        $origen = 'INCORPORACION_HISTORICA #'.$ejecucion->id;

        DB::beginTransaction();

        try {
            $ultimoMovimiento = (int) DB::table('hospital_paquete_movimientos')->max('id');
            $resumen = (new LibroPaquetesHospitalarios(new AlmacenContinuidadHospitalariaBaseDatos))
                ->registrarCarga($segmentos, $origen, motivoReconocimiento: LibroPaquetesHospitalarios::MOTIVO_SALDO_INICIAL, programaEjecucionId: $ejecucion->id);
            $porMes = DB::table('hospital_paquete_movimientos')
                ->where('id', '>', $ultimoMovimiento)
                ->selectRaw('ruta, mes_contable, COUNT(*) AS movimientos, SUM(signo) AS paquetes, SUM(valor) AS valor, COUNT(DISTINCT episodio_clave) AS episodios')
                ->groupBy('ruta', 'mes_contable')
                ->orderBy('ruta')->orderBy('mes_contable')
                ->get();
            $auditoria = [...$auditoria, 'aplicado' => $aplicar, 'origen' => $origen, 'meses' => $porMes->pluck('mes_contable')->values()->all(),
                'segmentos_nuevos' => $resumen['nuevos'], 'paquetes' => (int) $porMes->sum('paquetes'), 'valor' => round((float) $porMes->sum('valor'), 2)];
            $logger->log(LibroPaquetesEjecucion::ACCION_INCORPORACION, $aplicar ? 'Incorporación histórica aplicada.' : 'Incorporación histórica simulada.', $auditoria);
        } catch (\Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        // En simulación se revierte todo, salvo dejar constancia de la simulación.
        if ($aplicar) {
            DB::commit();
        } else {
            DB::rollBack();
            $logger->log(LibroPaquetesEjecucion::ACCION_INCORPORACION, 'Incorporación histórica simulada.', [...$auditoria, 'aplicado' => false]);
        }

        $this->info(($aplicar ? 'APLICADO' : 'SIMULACION (no se guardó nada)').' · ejecución de referencia '.$ejecucion->id.' ('.$ejecucion->programa_slug.')'
            .($noVerificable === [] ? '' : ' · REFERENCIA HISTÓRICA NO VERIFICADA'));
        $this->table(['Ruta', 'Mes contable', 'Episodios', 'Paquetes', 'Valor', 'Movimientos'], $porMes->map(static fn (object $fila): array => [
            $fila->ruta, $fila->mes_contable, $fila->episodios, (int) $fila->paquetes, number_format((float) $fila->valor, 2, ',', '.'), $fila->movimientos,
        ])->all());
        $this->line(sprintf(
            'Segmentos nuevos: %d · ya registrados: %d · pendientes de revisión: %d · sin identidad: %d · continuidades no confirmadas: %d · inicios no verificables: %d',
            $resumen['nuevos'], $resumen['sin_cambio'], $resumen['pendientes_revision'], $resumen['sin_identidad'], count($resumen['retenidos']), count($resumen['inicios_no_verificados'])
        ));
        $this->line('La ejecución histórica y su informe no se modifican.');

        return self::SUCCESS;
    }

    /**
     * Revierte una incorporación aplicada con movimientos compensatorios y anula
     * los segmentos que solo ella registró; nada se borra.
     */
    private function revertir(int $registroId, ActivityLogger $logger): int
    {
        $registro = ActivityLog::query()->find($registroId);

        if ($registro === null || $registro->action !== LibroPaquetesEjecucion::ACCION_INCORPORACION || ($registro->metadata['aplicado'] ?? false) !== true) {
            $this->error('El registro indicado no es una incorporación histórica aplicada.');

            return self::FAILURE;
        }

        $origen = (string) $registro->metadata['origen'];

        if (ActivityLog::query()->where('action', LibroPaquetesEjecucion::ACCION_INCORPORACION)->get(['metadata'])
            ->contains(static fn (ActivityLog $log): bool => (int) ($log->metadata['reversion_de'] ?? 0) === $registroId)) {
            $this->error('Esta incorporación ya fue revertida.');

            return self::FAILURE;
        }

        $compensados = DB::transaction(function () use ($origen): int {
            $ahora = now();
            $vigentes = DB::table('hospital_paquete_movimientos')->where('ejecucion_origen', $origen)->where('signo', 1)->whereNotNull('bloque_vigente')->lockForUpdate()->get();

            foreach ($vigentes as $movimiento) {
                $ordinal = DB::table('hospital_paquete_movimientos')
                    ->where('ruta', $movimiento->ruta)->where('tipo_documento', $movimiento->tipo_documento)->where('num_documento', $movimiento->num_documento)
                    ->where('bloque_inicio', $movimiento->bloque_inicio)->count() + 1;
                DB::table('hospital_paquete_movimientos')->where('id', $movimiento->id)->update(['bloque_vigente' => null, 'updated_at' => $ahora]);
                DB::table('hospital_paquete_movimientos')->insert([
                    ...collect((array) $movimiento)->except(['id', 'bloque_vigente', 'clave_idempotencia', 'created_at', 'updated_at'])->all(),
                    'signo' => -1,
                    'valor' => -1 * (float) $movimiento->valor,
                    'motivo' => 'REVERSO_SALDO_INICIAL',
                    'movimiento_revertido_id' => $movimiento->id,
                    'bloque_vigente' => null,
                    'clave_idempotencia' => implode('|', [$movimiento->programa_slug, $movimiento->ruta, $movimiento->tipo_documento, $movimiento->num_documento, substr((string) $movimiento->bloque_inicio, 0, 16), $ordinal]),
                    'ejecucion_origen' => 'REVERSO '.$origen,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ]);
            }

            DB::table('hospital_segmentos')->where('ejecucion_origen', $origen)->where('estado', 'VIGENTE')
                ->update(['estado' => 'ANULADO', 'identidad_vigente' => null, 'updated_at' => $ahora]);

            return $vigentes->count();
        });

        $logger->log(LibroPaquetesEjecucion::ACCION_INCORPORACION, 'Incorporación histórica revertida.', [
            ...$registro->metadata, 'aplicado' => false, 'revertido' => true, 'reversion_de' => $registroId, 'movimientos_compensados' => $compensados,
        ]);
        $this->info('Incorporación revertida: '.$compensados.' movimientos compensados; segmentos anulados; nada se borró.');

        return self::SUCCESS;
    }
}
