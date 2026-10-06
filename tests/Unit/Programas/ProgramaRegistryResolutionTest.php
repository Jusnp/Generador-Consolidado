<?php

namespace Tests\Unit\Programas;

use App\Programas\Autoinmunes\AutoinmunesPipeline;
use App\Programas\LaMaria\LaMariaCervixPipeline;
use App\Programas\LaMaria\LaMariaPrograma;
use App\Programas\LaMaria\LaMariaProstataPipeline;
use App\Programas\ProgramaRegistry;
use App\Services\CatalogoConsolidadoImporter;
use App\Services\CatalogoReferenciaImporter;
use InvalidArgumentException;
use Tests\TestCase;

class ProgramaRegistryResolutionTest extends TestCase
{
    public function test_resolves_autoinmunes_pipeline_and_la_maria_hub_ejecuciones(): void
    {
        $registry = app(ProgramaRegistry::class);

        $autoinmunes = $registry->pipelineFor('autoinmunes');
        $laMaria = $registry->get('la-maria');

        $this->assertInstanceOf(AutoinmunesPipeline::class, $autoinmunes);
        $this->assertSame('json-excel', $autoinmunes->entryRouteName());
        $this->assertTrue($laMaria['hub']);
        $this->assertTrue($laMaria['habilitado']);
        $this->assertSame('la-maria', $laMaria['ruta']);
        $this->assertNull($laMaria['pipeline']);
        $this->assertCount(2, $laMaria['ejecuciones']);
        $this->assertInstanceOf(LaMariaProstataPipeline::class, $laMaria['ejecuciones'][0]['pipeline']);
        $this->assertInstanceOf(LaMariaCervixPipeline::class, $laMaria['ejecuciones'][1]['pipeline']);
        $this->assertSame('la-maria.prostata', $laMaria['ejecuciones'][0]['ruta']);
        $this->assertSame('la-maria.cervix', $laMaria['ejecuciones'][1]['ruta']);
        $this->assertSame('PROSTATA', $laMaria['ejecuciones'][0]['pipeline']->rutaCodigo());
        $this->assertSame('CERVIX', $laMaria['ejecuciones'][1]['pipeline']->rutaCodigo());
    }

    public function test_pending_programas_have_no_pipeline(): void
    {
        $registry = app(ProgramaRegistry::class);

        foreach (['salud-mental', 'coosalud', 'nueva-eps'] as $slug) {
            $definition = $registry->get($slug);

            $this->assertFalse($definition['habilitado']);
            $this->assertNull($definition['pipeline']);
            $this->assertNull($definition['ruta']);
            $this->assertFalse($definition['hub']);
        }
    }

    public function test_catalog_types_are_owned_by_a_single_programa_label(): void
    {
        $map = app(ProgramaRegistry::class)->catalogOwnershipMap();

        foreach (array_keys(CatalogoReferenciaImporter::types()) as $tipo) {
            $this->assertSame(
                LaMariaPrograma::CATALOG_OWNERSHIP_LABEL,
                $map[$tipo]
            );
        }

        foreach (array_keys(CatalogoConsolidadoImporter::types()) as $tipo) {
            $this->assertSame(
                'Autoinmunes · tarifarios contractuales',
                $map[$tipo]
            );
        }
    }

    public function test_unknown_programa_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(ProgramaRegistry::class)->get('no-existe');
    }
}
