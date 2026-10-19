<?php

namespace App\Console\Commands;

use App\Exceptions\ActivacionCatalogoException;
use App\Models\ActivityLog;
use App\Services\CatalogoReferenciaActivator;
use Illuminate\Console\Command;

class RevertirOperacionCatalogo extends Command
{
    protected $signature = 'catalogo:revertir-operacion
        {operacion : Id del registro de auditoría de la activación o inactivación}';

    protected $description = 'Restaura exactamente las versiones activas de La María que había antes de una activación o inactivación';

    public function handle(CatalogoReferenciaActivator $activator): int
    {
        $operacion = ActivityLog::query()->find((int) $this->argument('operacion'));

        if ($operacion === null) {
            $this->error('No existe el registro de auditoría indicado.');

            return self::FAILURE;
        }

        try {
            $reversion = $activator->revertir($operacion);
        } catch (ActivacionCatalogoException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Operación %d revertida. Versiones activas de %s: [%s] (registro %d).',
            $operacion->id,
            $reversion->metadata['tipo'] ?? '',
            implode(', ', $reversion->metadata['activos_nuevos'] ?? []),
            $reversion->id
        ));

        return self::SUCCESS;
    }
}
