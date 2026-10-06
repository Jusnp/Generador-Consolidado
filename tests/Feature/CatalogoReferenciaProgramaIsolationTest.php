<?php

namespace Tests\Feature;

use App\Models\CatalogoReferencia;
use App\Models\CatalogoReferenciaItem;
use App\Models\User;
use App\Programas\LaMaria\LaMariaPrograma;
use App\Services\CatalogoReferenciaImporter;
use App\Services\ReferenciaRutaMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class CatalogoReferenciaProgramaIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_activating_catalog_for_one_programa_does_not_deactivate_another_programa(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $otherProgramaCatalog = CatalogoReferencia::create([
            'programa_slug' => 'amarilla',
            'tipo' => 'ANEXOS_2706',
            'version' => 'amarilla-activa',
            'archivo_origen' => 'amarilla.xlsx',
            'activo' => true,
        ]);

        $this->actingAs($admin)
            ->post(route('catalogos-referencia.store'), [
                'tipo' => 'ANEXOS_2706',
                'version' => 'la-maria-2026',
                'archivo' => $this->annexWorkbook(),
            ])
            ->assertRedirectToRoute('catalogos-referencia.index');

        $this->assertTrue($otherProgramaCatalog->fresh()->activo);
        $this->assertDatabaseHas('catalogo_referencias', [
            'programa_slug' => LaMariaPrograma::SLUG,
            'tipo' => 'ANEXOS_2706',
            'version' => 'la-maria-2026',
            'activo' => true,
        ]);
    }

    public function test_matcher_loads_only_la_maria_active_catalogs(): void
    {
        CatalogoReferencia::create([
            'programa_slug' => 'amarilla',
            'tipo' => 'ANEXOS_2706',
            'version' => 'otra',
            'archivo_origen' => 'otra.xlsx',
            'activo' => true,
        ]);

        $oncologico = CatalogoReferencia::create([
            'programa_slug' => LaMariaPrograma::SLUG,
            'tipo' => 'ANEXOS_2706',
            'version' => 'la-maria',
            'archivo_origen' => 'la-maria.xlsx',
            'activo' => true,
        ]);
        CatalogoReferenciaItem::create([
            'catalogo_referencia_id' => $oncologico->id,
            'codigo' => '890201',
            'categoria' => 'ANEXO_2',
            'descripcion' => 'Consulta La María',
        ]);

        $matcher = app(ReferenciaRutaMatcher::class);
        $snapshot = $matcher->appliedCatalogsSnapshot();

        $this->assertCount(1, $snapshot);
        $this->assertSame(LaMariaPrograma::SLUG, $snapshot[0]['programa_slug']);
        $this->assertSame('la-maria', $snapshot[0]['version']);
    }

    public function test_importer_rejects_foreign_programa_slug(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->expectException(\InvalidArgumentException::class);

        app(CatalogoReferenciaImporter::class)->import(
            $this->annexWorkbook(),
            'ANEXOS_2706',
            'x',
            $admin,
            'amarilla'
        );
    }

    private function annexWorkbook(): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('ANEXO 2 SIN PUNTOS (.) SIN TILD');
        $sheet->fromArray([
            ['CODIGO', 'DESCRIPCION'],
            ['890201', 'Consulta de referencia'],
        ]);

        $path = tempnam(sys_get_temp_dir(), 'catalogo-anexos-');

        if ($path === false) {
            $this->fail('No fue posible crear el archivo temporal de prueba.');
        }

        (new Xlsx($spreadsheet))->save($path);
        $content = file_get_contents($path);
        unlink($path);

        if ($content === false) {
            $this->fail('No fue posible leer el archivo temporal de prueba.');
        }

        return UploadedFile::fake()->createWithContent(
            'anexos.xlsx',
            $content,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );
    }
}
