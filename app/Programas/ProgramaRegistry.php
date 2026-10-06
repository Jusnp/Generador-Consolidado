<?php

namespace App\Programas;

use App\Programas\Contracts\ProgramaPipeline;
use InvalidArgumentException;
use RuntimeException;

class ProgramaRegistry
{
    /**
     * @return array<string, array{slug: string, nombre: string, descripcion: string, habilitado: bool, ruta: string|null, hub: bool, ejecuciones: list<array{slug: string, nombre: string, descripcion: string, ruta: string}>, pipeline: ProgramaPipeline|null}>
     */
    public function all(): array
    {
        $definitions = [];

        foreach (config('programas', []) as $slug => $config) {
            $definitions[$slug] = $this->hydrate($slug, is_array($config) ? $config : []);
        }

        return $definitions;
    }

    /**
     * @return array{slug: string, nombre: string, descripcion: string, habilitado: bool, ruta: string|null, hub: bool, ejecuciones: list<array{slug: string, nombre: string, descripcion: string, ruta: string}>, pipeline: ProgramaPipeline|null}
     */
    public function get(string $slug): array
    {
        $config = config('programas.'.$slug);

        if (! is_array($config)) {
            throw new InvalidArgumentException("El programa [{$slug}] no está registrado.");
        }

        return $this->hydrate($slug, $config);
    }

    public function exists(string $slug): bool
    {
        return is_array(config('programas.'.$slug));
    }

    public function pipelineFor(string $slug): ?ProgramaPipeline
    {
        return $this->get($slug)['pipeline'];
    }

    /**
     * @return list<array{slug: string, nombre: string, descripcion: string, habilitado: bool, ruta: string|null, hub: bool, ejecuciones: list<array{slug: string, nombre: string, descripcion: string, ruta: string}>, pipeline: ProgramaPipeline|null}>
     */
    public function habilitados(): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (array $definition): bool => $definition['habilitado']
        ));
    }

    /**
     * @return array<string, string> catalogType => ownership label
     */
    public function catalogOwnershipMap(): array
    {
        $map = [];

        foreach ($this->all() as $definition) {
            foreach ($this->pipelinesOf($definition) as $pipeline) {
                foreach ($pipeline->ownedCatalogTypes() as $tipo) {
                    $label = $pipeline->catalogOwnershipLabel();

                    if (isset($map[$tipo]) && $map[$tipo] !== $label) {
                        throw new RuntimeException(
                            "El tipo de catálogo [{$tipo}] está asignado a más de un programa."
                        );
                    }

                    $map[$tipo] = $label;
                }
            }
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{slug: string, nombre: string, descripcion: string, habilitado: bool, ruta: string|null, hub: bool, ejecuciones: list<array{slug: string, nombre: string, descripcion: string, ruta: string}>, pipeline: ProgramaPipeline|null}
     */
    private function hydrate(string $slug, array $config): array
    {
        $pipeline = $this->resolvePipeline($slug, $config['pipeline'] ?? null);
        $ejecuciones = $this->hydrateEjecuciones($slug, $config['ejecuciones'] ?? []);
        $hub = (bool) ($config['hub'] ?? false) && $ejecuciones !== [];
        $ruta = $pipeline?->entryRouteName();

        if ($hub) {
            $ruta = 'la-maria';
        }

        return [
            'slug' => $slug,
            'nombre' => (string) ($config['nombre'] ?? $slug),
            'descripcion' => (string) ($config['descripcion'] ?? ''),
            'habilitado' => $ruta !== null,
            'ruta' => $ruta,
            'hub' => $hub,
            'ejecuciones' => $ejecuciones,
            'pipeline' => $pipeline,
        ];
    }

    /**
     * @param  array<string, mixed>  $ejecuciones
     * @return list<array{slug: string, nombre: string, descripcion: string, ruta: string, pipeline: ProgramaPipeline}>
     */
    private function hydrateEjecuciones(string $programaSlug, array $ejecuciones): array
    {
        $hydrated = [];

        foreach ($ejecuciones as $slug => $config) {
            if (! is_array($config)) {
                continue;
            }

            $pipeline = $this->resolvePipeline(
                $programaSlug.'.'.$slug,
                $config['pipeline'] ?? null
            );

            if ($pipeline === null) {
                continue;
            }

            $hydrated[] = [
                'slug' => (string) $slug,
                'nombre' => (string) ($config['nombre'] ?? $slug),
                'descripcion' => (string) ($config['descripcion'] ?? ''),
                'ruta' => $pipeline->entryRouteName(),
                'pipeline' => $pipeline,
            ];
        }

        return $hydrated;
    }

    /**
     * @param  array{pipeline: ProgramaPipeline|null, ejecuciones: list<array{pipeline: ProgramaPipeline}>}  $definition
     * @return list<ProgramaPipeline>
     */
    private function pipelinesOf(array $definition): array
    {
        $pipelines = [];

        if ($definition['pipeline'] instanceof ProgramaPipeline) {
            $pipelines[] = $definition['pipeline'];
        }

        foreach ($definition['ejecuciones'] as $ejecucion) {
            if (($ejecucion['pipeline'] ?? null) instanceof ProgramaPipeline) {
                $pipelines[] = $ejecucion['pipeline'];
            }
        }

        return $pipelines;
    }

    private function resolvePipeline(string $slug, mixed $pipelineClass): ?ProgramaPipeline
    {
        if ($pipelineClass === null || $pipelineClass === '') {
            return null;
        }

        if (! is_string($pipelineClass) || ! is_a($pipelineClass, ProgramaPipeline::class, true)) {
            throw new RuntimeException(
                "El pipeline del programa [{$slug}] debe implementar ".ProgramaPipeline::class.'.'
            );
        }

        $pipeline = app($pipelineClass);

        if (! $pipeline instanceof ProgramaPipeline) {
            throw new RuntimeException(
                "No se pudo resolver el pipeline del programa [{$slug}]."
            );
        }

        return $pipeline;
    }
}
