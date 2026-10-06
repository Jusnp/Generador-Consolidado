<?php

namespace Tests\Feature;

use App\Models\CatalogoConsolidadoImport;
use App\Models\CodigoCups;
use App\Models\CodigoInsumoNt;
use App\Models\CodigoMedicamento;
use App\Models\CodigoMedicamentoNt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class CatalogoConsolidadoControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_update_a_contractual_cups_tariff(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin']);
        CodigoCups::create([
            'codigo' => '890201',
            'tipo_servicio' => 'Consulta',
            'descripcion' => 'Tarifa anterior',
            'tarifa_2025' => 10000,
        ]);

        $this->actingAs($admin)
            ->post(route('catalogos-referencia.consolidado.store'), [
                'tipo' => 'CUPS_CONTRATO',
                'version' => 'Contrato 2026-10',
                'archivo' => $this->cupsWorkbook(),
            ])
            ->assertRedirectToRoute('catalogos-referencia.index')
            ->assertSessionHas('success');

        $this->assertDatabaseHas('codigo_cups', [
            'codigo' => '890201',
            'tipo_servicio' => 'Consulta especializada',
            'descripcion' => 'Consulta médica especializada',
            'tarifa_2025' => 18500,
        ]);

        $import = CatalogoConsolidadoImport::query()->firstOrFail();

        $this->assertSame('CUPS_CONTRATO', $import->tipo);
        $this->assertSame('Contrato 2026-10', $import->version);
        $this->assertSame(1, $import->registros_procesados);
        $this->assertSame(1, $import->registros_actualizados);
        Storage::disk('local')->assertExists($import->archivo_almacenado);
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $admin->id,
            'action' => 'catalogo_consolidado_importado',
        ]);
    }

    public function test_catalog_page_exposes_contractual_tariffs_and_progress_areas(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('catalogos-referencia.index'))
            ->assertOk()
            ->assertSeeText('Tarifarios del consolidado RIPS')
            ->assertSee('data-upload-progress-form', false)
            ->assertSeeText('Tarifario CUPS del contrato');
    }

    public function test_catalog_page_recognizes_the_existing_base_tariffs_without_a_new_upload(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        CodigoCups::create(['codigo' => '890201']);
        CodigoMedicamento::create(['codigo' => '19999999-1']);
        CodigoMedicamentoNt::create(['cums' => '19999999-1']);
        CodigoInsumoNt::create(['codigo' => 'INS-001']);

        $this->actingAs($admin)
            ->get(route('catalogos-referencia.index'))
            ->assertOk()
            ->assertSeeText('Carga inicial vigente')
            ->assertSeeText('Catálogo vigente del sistema')
            ->assertSeeText('ACTIVO')
            ->assertSeeText('Las tarifas presentes en la carga inicial se muestran como vigentes');
    }

    private function cupsWorkbook(): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('CUPS');
        $sheet->fromArray([
            ['CUPS', 'Tipo Servicio', 'Descripción Servicio', 'Tarifa 2025'],
            ['890201', 'Consulta especializada', 'Consulta médica especializada', 18500],
        ]);

        $path = tempnam(sys_get_temp_dir(), 'tarifario-cups-');

        if ($path === false) {
            $this->fail('No fue posible crear el tarifario temporal.');
        }

        (new Xlsx($spreadsheet))->save($path);
        $content = file_get_contents($path);
        unlink($path);

        if ($content === false) {
            $this->fail('No fue posible leer el tarifario temporal.');
        }

        return UploadedFile::fake()->createWithContent(
            'tarifario-cups.xlsx',
            $content,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );
    }
}
