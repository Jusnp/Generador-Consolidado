<?php

namespace App\Programas\LaMaria;

use App\Models\CatalogoReferencia;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Referencia provisional sin pacientes dentro del catálogo versionado existente. */
final class HistoricoTarifarioConsolidado
{
    public const TIPO = 'HISTORICO_TARIFARIO_LA_MARIA';

    public static function activo(): ?HistorialAdministrativo
    {
        $catalogos = CatalogoReferencia::query()->forPrograma(LaMariaPrograma::SLUG)
            ->active()->where('tipo', self::TIPO)->get();

        if ($catalogos->isEmpty()) {
            return null;
        }

        if ($catalogos->count() !== 1) {
            throw new RuntimeException('Existe más de una versión activa del histórico tarifario.');
        }

        return self::desdeVersion($catalogos->first());
    }

    public static function desdeVersion(CatalogoReferencia $catalogo): HistorialAdministrativo
    {
        if ($catalogo->programa_slug !== LaMariaPrograma::SLUG || $catalogo->tipo !== self::TIPO) {
            throw new \InvalidArgumentException('La versión no pertenece al histórico tarifario de La María.');
        }

        $consolidado = ['version' => 1, 'fuentes' => [], 'items' => []];

        foreach ($catalogo->items()->where('activo', true)->cursor() as $item) {
            $metadata = $item->metadatos ?? [];

            if ($item->codigo === '__FUENTES__') {
                $consolidado['fuentes'] = array_map(static fn (array $fuente): array => [
                    ...$fuente,
                    'archivo' => 'CATALOGO '.$catalogo->version.' / '.($fuente['archivo'] ?? ''),
                    'advertencias' => [...($fuente['advertencias'] ?? []), 'Precio observado provisional; no acredita tarifa contractual.'],
                ], $metadata['fuentes'] ?? []);

                continue;
            }

            if (($metadata['naturaleza'] ?? '') !== 'HISTORICO_ADMINISTRATIVO_PROVISIONAL') {
                throw new RuntimeException('La versión tarifaria contiene una procedencia desconocida.');
            }

            $consolidado['items'][] = [
                'ruta' => (string) $item->ruta,
                'tipo' => (string) ($metadata['tipo'] ?? ''),
                'codigo' => (string) $item->codigo,
                'descripcion' => (string) $item->descripcion,
                'valores' => $metadata['valores'] ?? [],
                'composiciones' => $metadata['composiciones'] ?? [],
                'unidades' => $metadata['unidades'] ?? [],
            ];
        }

        if ($consolidado['items'] === [] || $consolidado['fuentes'] === []) {
            throw new RuntimeException('La versión tarifaria activa está incompleta.');
        }

        return HistorialAdministrativo::desdeConsolidado($consolidado);
    }

    /**
     * Prepara una versión inactiva. La activación exige la revisión habitual del catálogo.
     *
     * @param  array{version: int, fuentes: list<array<string, mixed>>, items: list<array<string, mixed>>}  $consolidado
     */
    public static function guardarInactivo(array $consolidado, string $version): CatalogoReferencia
    {
        if (($consolidado['version'] ?? 0) !== 1 || ($consolidado['items'] ?? []) === [] || ($consolidado['fuentes'] ?? []) === []) {
            throw new \InvalidArgumentException('El histórico consolidado debe tener fuentes y tarifas verificables.');
        }

        return DB::transaction(function () use ($consolidado, $version): CatalogoReferencia {
            if (CatalogoReferencia::query()->forPrograma(LaMariaPrograma::SLUG)
                ->where('tipo', self::TIPO)->where('version', $version)->exists()) {
                throw new \InvalidArgumentException('Ya existe esta versión de la referencia tarifaria.');
            }

            $catalogo = CatalogoReferencia::create([
                'programa_slug' => LaMariaPrograma::SLUG,
                'tipo' => self::TIPO,
                'version' => $version,
                'archivo_origen' => 'Referencia histórica consolidada sin identificadores personales',
                'activo' => false,
            ]);
            $catalogo->items()->create([
                'codigo' => '__FUENTES__', 'categoria' => 'METADATOS', 'activo' => true,
                'metadatos' => ['version' => 1, 'fuentes' => $consolidado['fuentes']],
            ]);

            foreach ($consolidado['items'] as $item) {
                $catalogo->items()->create([
                    'codigo' => $item['codigo'],
                    'ruta' => $item['ruta'],
                    'categoria' => $item['tipo'],
                    'descripcion' => $item['descripcion'],
                    'tarifa_referencia' => null,
                    'activo' => true,
                    'metadatos' => [
                        'naturaleza' => 'HISTORICO_ADMINISTRATIVO_PROVISIONAL',
                        'estado_contractual' => 'PENDIENTE',
                        'tipo' => $item['tipo'],
                        'valores' => $item['valores'],
                        'composiciones' => $item['composiciones'],
                        'unidades' => $item['unidades'],
                    ],
                ]);
            }

            return $catalogo;
        });
    }
}
