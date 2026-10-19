<?php

namespace App\Services;

use App\Exceptions\ActivacionCatalogoException;
use App\Models\ActivityLog;
use App\Models\CatalogoReferencia;
use App\Programas\LaMaria\EstadoContractual;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Activa, inactiva y revierte versiones de catálogos de referencia de La María.
 *
 * El ámbito es programa + tipo (el tipo ya identifica la ruta: NOTA_PROSTATA,
 * DISPENSACION_CERVIX…): en cada ámbito hay como máximo una versión activa. Cada
 * operación bloquea las versiones del ámbito, comprueba que las versiones activas
 * sean las que el administrador vio (otro pudo cambiarlas entretanto), verifica
 * la versión a activar, aplica el cambio en una sola transacción y registra en
 * la auditoría el conjunto anterior y el nuevo, para poder restaurar exactamente
 * el conjunto anterior.
 */
class CatalogoReferenciaActivator
{
    public const ACCION_ACTIVACION = 'catalogo_version_activada';

    public const ACCION_INACTIVACION = 'catalogo_version_inactivada';

    public const ACCION_REVERSION = 'catalogo_operacion_revertida';

    public function __construct(private ActivityLogger $logger) {}

    /**
     * @param  list<int>|null  $activosEsperados  versiones activas que vio el administrador; null solo para procesos internos
     *
     * @throws ActivacionCatalogoException
     */
    public function activar(CatalogoReferencia $catalogo, ?array $activosEsperados, ?Request $request = null): ActivityLog
    {
        return DB::transaction(function () use ($catalogo, $activosEsperados, $request): ActivityLog {
            $ambito = $this->bloquearAmbito($catalogo);
            $anteriores = $this->activos($ambito);
            $this->comprobarEsperados($anteriores, $activosEsperados);

            if ($anteriores === [$catalogo->id]) {
                throw new ActivacionCatalogoException('Esta versión ya es la única activa de su tipo.');
            }

            $verificacion = $this->verificar($catalogo);
            CatalogoReferencia::query()->whereKey($ambito->pluck('id'))->whereKeyNot($catalogo->id)->update(['activo' => false]);
            CatalogoReferencia::query()->whereKey($catalogo->id)->update(['activo' => true]);

            return $this->registrar(self::ACCION_ACTIVACION, 'Versión de catálogo activada.', $catalogo, $anteriores, [$catalogo->id], $request, ['verificacion' => $verificacion]);
        });
    }

    /**
     * @param  list<int>|null  $activosEsperados
     *
     * @throws ActivacionCatalogoException
     */
    public function inactivar(CatalogoReferencia $catalogo, ?array $activosEsperados, ?Request $request = null): ActivityLog
    {
        return DB::transaction(function () use ($catalogo, $activosEsperados, $request): ActivityLog {
            $ambito = $this->bloquearAmbito($catalogo);
            $anteriores = $this->activos($ambito);
            $this->comprobarEsperados($anteriores, $activosEsperados);

            if (! in_array($catalogo->id, $anteriores, true)) {
                throw new ActivacionCatalogoException('Esta versión ya está inactiva.');
            }

            CatalogoReferencia::query()->whereKey($catalogo->id)->update(['activo' => false]);
            $nuevos = array_values(array_diff($anteriores, [$catalogo->id]));

            return $this->registrar(self::ACCION_INACTIVACION, 'Versión de catálogo inactivada.', $catalogo, $anteriores, $nuevos, $request);
        });
    }

    /**
     * Restaura exactamente las versiones activas que había antes de una
     * activación o inactivación, si nadie las cambió después.
     *
     * @throws ActivacionCatalogoException
     */
    public function revertir(ActivityLog $operacion, ?Request $request = null): ActivityLog
    {
        if (! in_array($operacion->action, [self::ACCION_ACTIVACION, self::ACCION_INACTIVACION], true)) {
            throw new ActivacionCatalogoException('Solo se pueden revertir activaciones o inactivaciones de catálogos.');
        }

        return DB::transaction(function () use ($operacion, $request): ActivityLog {
            $metadata = $operacion->metadata ?? [];
            $catalogo = CatalogoReferencia::query()->findOrFail($metadata['catalogo_id'] ?? 0);
            $ambito = $this->bloquearAmbito($catalogo);
            $yaRevertida = ActivityLog::query()
                ->where('action', self::ACCION_REVERSION)
                ->get(['metadata'])
                ->contains(fn (ActivityLog $log): bool => (int) ($log->metadata['operacion_revertida'] ?? 0) === $operacion->id);

            if ($yaRevertida) {
                throw new ActivacionCatalogoException('Esta operación ya fue revertida.');
            }

            $actuales = $this->activos($ambito);

            if ($actuales !== $this->ids($metadata['activos_nuevos'] ?? [])) {
                throw ActivacionCatalogoException::conflicto('Las versiones activas cambiaron después de esta operación; revierte primero las operaciones posteriores.');
            }

            $restaurar = $this->ids($metadata['activos_anteriores'] ?? []);
            CatalogoReferencia::query()->whereKey($ambito->pluck('id'))->update(['activo' => false]);
            CatalogoReferencia::query()->whereKey($restaurar)->update(['activo' => true]);

            return $this->registrar(self::ACCION_REVERSION, 'Operación de catálogo revertida.', $catalogo, $actuales, $restaurar, $request, ['operacion_revertida' => $operacion->id]);
        });
    }

    /**
     * Comprueba que la versión terminó de importarse y que sus datos son
     * utilizables antes de activarla.
     *
     * @return array<string, int>
     *
     * @throws ActivacionCatalogoException
     */
    public function verificar(CatalogoReferencia $catalogo): array
    {
        $resumen = ['items' => 0, 'items_activos' => 0, 'errores' => 0];
        $tarifasPorCodigo = [];
        $errores = [];

        foreach ($catalogo->items()->select(['id', 'codigo', 'ruta', 'categoria', 'tarifa_referencia', 'activo', 'metadatos'])->cursor() as $item) {
            $resumen['items']++;
            $resumen['items_activos'] += $item->activo ? 1 : 0;
            $metadata = $item->metadatos ?? [];

            $error = match ($catalogo->tipo) {
                CatalogoReferenciaImporter::TYPE_DISPENSACION_CERVIX, CatalogoReferenciaImporter::TYPE_DISPENSACION_PROSTATA => match (true) {
                    preg_match('/^(\d{4})-\d{2}-\d{2}$/', (string) ($metadata['fecha_dispensacion'] ?? ''), $fecha) !== 1
                        || (int) $fecha[1] < 2000 || (int) $fecha[1] > 2100 => 'FECHA_DISPENSACION_INVALIDA',
                    preg_match('/^\d{3,9}-\d{1,3}$/', (string) $item->codigo) !== 1 => 'CUM_INVALIDO',
                    default => null,
                },
                CatalogoReferenciaImporter::TYPE_COMPLEMENTO_MEDICAMENTOS_PROSTATA => match (true) {
                    preg_match('/^\d{3,9}-\d{1,3}$/', (string) $item->codigo) !== 1 => 'CUM_INVALIDO',
                    EstadoContractual::tryFrom((string) ($metadata['estado_contractual'] ?? '')) === null => 'ESTADO_CONTRACTUAL_INVALIDO',
                    ($metadata['estado_contractual'] ?? '') === EstadoContractual::Verificado->value
                        && (($metadata['evidencia_contractual'] ?? '') === '' || ($metadata['vigencia_desde'] ?? '') === '') => 'VERIFICADO_SIN_EVIDENCIA_O_VIGENCIA',
                    default => null,
                },
                default => null,
            };

            if ($error !== null) {
                $errores[$error] = ($errores[$error] ?? 0) + 1;
            }

            if (in_array($catalogo->tipo, [CatalogoReferenciaImporter::TYPE_NOTA_PROSTATA, CatalogoReferenciaImporter::TYPE_NOTA_CERVIX], true)
                && $item->activo && $item->tarifa_referencia !== null) {
                $tarifasPorCodigo[$item->ruta.'|'.$item->categoria.'|'.$item->codigo][number_format((float) $item->tarifa_referencia, 4, '.', '')] = true;
            }

            if ($catalogo->tipo === CatalogoReferenciaImporter::TYPE_MEDICAMENTOS_CUM && ($metadata['ium'] ?? '') !== '') {
                $resumen['items_con_ium'] = ($resumen['items_con_ium'] ?? 0) + 1;
            }

            if ($item->categoria === 'SERVICIO_TECNICO' && $item->tarifa_referencia !== null) {
                $resumen['servicios_con_tarifa'] = ($resumen['servicios_con_tarifa'] ?? 0) + 1;
            }
        }

        $duplicados = count(array_filter($tarifasPorCodigo, static fn (array $tarifas): bool => count($tarifas) > 1));

        if ($duplicados > 0) {
            $errores['CODIGO_CON_VARIAS_TARIFAS'] = $duplicados;
        }

        if ($resumen['items_activos'] === 0) {
            $errores['IMPORTACION_SIN_REGISTROS'] = 1;
        }

        if ($catalogo->tipo === CatalogoReferenciaImporter::TYPE_MEDICAMENTOS_CUM && ($resumen['items_con_ium'] ?? 0) === 0) {
            $errores['CUM_SIN_IUM'] = 1;
        }

        if (in_array($catalogo->tipo, [CatalogoReferenciaImporter::TYPE_NOTA_PROSTATA, CatalogoReferenciaImporter::TYPE_NOTA_CERVIX], true)
            && ($resumen['servicios_con_tarifa'] ?? 0) === 0) {
            $errores['NOTA_SIN_SERVICIOS_CON_TARIFA'] = 1;
        }

        $resumen['errores'] = array_sum($errores);

        if ($errores !== []) {
            throw new ActivacionCatalogoException('La versión no supera la verificación: '.implode(', ', array_map(
                static fn (string $motivo, int $cantidad): string => $motivo.' ('.$cantidad.')',
                array_keys($errores),
                $errores
            )).'.');
        }

        return $resumen;
    }

    /**
     * Resultado de la verificación sin lanzar excepción, para mostrarlo al
     * cargar una versión (que queda inactiva aunque tenga validaciones pendientes).
     *
     * @return array{estado: string, detalle: string}
     */
    public function estadoVerificacion(CatalogoReferencia $catalogo): array
    {
        try {
            $resumen = $this->verificar($catalogo);

            return ['estado' => 'VERIFICADO', 'detalle' => $resumen['items_activos'].' registros activos sin errores.'];
        } catch (ActivacionCatalogoException $exception) {
            return ['estado' => 'VALIDACIONES_PENDIENTES', 'detalle' => $exception->getMessage()];
        }
    }

    /**
     * Última verificación registrada al importar cada versión.
     *
     * @return array<int, array{estado: string, detalle: string}>
     */
    public function verificacionesRegistradas(): array
    {
        $verificaciones = [];

        foreach (ActivityLog::query()->where('action', 'catalogo_referencia_importado')->orderBy('id')->get(['metadata']) as $log) {
            if (isset($log->metadata['catalogo_id'], $log->metadata['verificacion'])) {
                $verificaciones[(int) $log->metadata['catalogo_id']] = $log->metadata['verificacion'];
            }
        }

        return $verificaciones;
    }

    /**
     * Última activación o inactivación de cada tipo que todavía se puede revertir
     * (no revertida y sin operaciones posteriores en el mismo tipo).
     *
     * @return array<string, ActivityLog>
     */
    public function operacionesReversibles(string $programa): array
    {
        $logs = ActivityLog::query()
            ->whereIn('action', [self::ACCION_ACTIVACION, self::ACCION_INACTIVACION, self::ACCION_REVERSION])
            ->orderByDesc('id')
            ->get();
        $reversibles = [];
        $vistos = [];

        foreach ($logs as $log) {
            $tipo = (string) ($log->metadata['tipo'] ?? '');

            if (($log->metadata['programa'] ?? null) !== $programa || isset($vistos[$tipo])) {
                continue;
            }

            $vistos[$tipo] = true;

            if ($log->action !== self::ACCION_REVERSION) {
                $reversibles[$tipo] = $log;
            }
        }

        return $reversibles;
    }

    /**
     * @return Collection<int, CatalogoReferencia>
     */
    private function bloquearAmbito(CatalogoReferencia $catalogo): Collection
    {
        return CatalogoReferencia::query()
            ->forPrograma($catalogo->programa_slug)
            ->where('tipo', $catalogo->tipo)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'activo']);
    }

    /**
     * @param  Collection<int, CatalogoReferencia>  $ambito
     * @return list<int>
     */
    private function activos(Collection $ambito): array
    {
        return $this->ids($ambito->where('activo', true)->pluck('id')->all());
    }

    /**
     * @param  list<int>  $actuales
     * @param  list<int>|null  $esperados
     *
     * @throws ActivacionCatalogoException
     */
    private function comprobarEsperados(array $actuales, ?array $esperados): void
    {
        if ($esperados !== null && $actuales !== $this->ids($esperados)) {
            throw ActivacionCatalogoException::conflicto('Otro administrador cambió las versiones activas de este catálogo. Recarga la página y revisa antes de continuar.');
        }
    }

    /**
     * @param  array<int, int|string>  $ids
     * @return list<int>
     */
    private function ids(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        return $ids;
    }

    /**
     * @param  list<int>  $anteriores
     * @param  list<int>  $nuevos
     * @param  array<string, mixed>  $extra
     */
    private function registrar(string $accion, string $descripcion, CatalogoReferencia $catalogo, array $anteriores, array $nuevos, ?Request $request, array $extra = []): ActivityLog
    {
        return $this->logger->log($accion, $descripcion, [
            'programa' => $catalogo->programa_slug,
            'tipo' => $catalogo->tipo,
            'catalogo_id' => $catalogo->id,
            'version' => $catalogo->version,
            'activos_anteriores' => $anteriores,
            'activos_nuevos' => $nuevos,
            ...$extra,
        ], $request);
    }
}
