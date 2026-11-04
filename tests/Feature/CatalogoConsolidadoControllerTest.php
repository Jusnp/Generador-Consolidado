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

    public function test_cups_import_keeps_the_transport_tariff_when_the_supplies_sheet_repeats_the_code(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin']);
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->setTitle('CUPS')->fromArray([
            ['CUPS', 'Tipo Servicio', 'Descripción Servicio', 'Tarifa 2025'],
            ['T34004', 'TRANSPORTE', 'TRANSPORTE', 165000],
        ]);
        $spreadsheet->createSheet()->setTitle('INSUMOS')->fromArray([
            ['CODIGO', 'DESCRIPCION', 'NT', 'Tarifa UNITARIO'],
            ['T34004', 'TRANSPORTE NO ASISTENCIAL TERRESTRE EN ZONA DE DISPERSION', 'Si', 135000],
            ['INS-01', 'GASA ESTERIL', 'Si', 1200],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'tarifario-contrato-');
        (new Xlsx($spreadsheet))->save($path);
        $archivo = fn (): UploadedFile => UploadedFile::fake()->createWithContent('CODIGOS Y TARIFAS NT AUTOINMUNE.xlsx', (string) file_get_contents($path),
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        foreach (['CUPS_CONTRATO', 'INSUMOS_CONTRATO'] as $tipo) {
            $this->actingAs($admin)->post(route('catalogos-referencia.consolidado.store'), ['tipo' => $tipo, 'version' => '2026-10', 'archivo' => $archivo()])
                ->assertSessionHas('success');
        }

        unlink($path);

        // El tarifario CUPS conserva el transporte de su hoja; la hoja de insumos solo alimenta el catálogo de insumos.
        $this->assertDatabaseHas('codigo_cups', ['codigo' => 'T34004', 'hoja' => 'CUPS', 'tipo_servicio' => 'TRANSPORTE', 'tarifa_2025' => 165000]);
        $this->assertDatabaseMissing('codigo_cups', ['codigo' => 'INS-01']);
        $this->assertDatabaseHas('codigo_insumos_nt', ['codigo' => 'T34004', 'tarifa_unitario' => 135000]);
        $this->assertSame(1, CatalogoConsolidadoImport::query()->where('tipo', 'CUPS_CONTRATO')->value('registros_procesados'));
    }

    public function test_cups_import_with_an_empty_tariff_keeps_the_transport_tariff_and_reports_it(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin']);
        CodigoCups::create(['codigo' => 'T34004', 'hoja' => 'CUPS', 'tipo_servicio' => 'TRANSPORTE', 'descripcion' => 'TRANSPORTE', 'tarifa_2025' => 165000]);
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->setTitle('CUPS')->fromArray([
            ['CUPS', 'Tipo Servicio', 'Descripción Servicio', 'Tarifa 2025'],
            ['T34004', 'TRANSPORTE', 'TRANSPORTE', ''],
            ['890299', 'Consulta', 'Consulta sin tarifa', ''],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'tarifario-contrato-');
        (new Xlsx($spreadsheet))->save($path);
        $archivo = UploadedFile::fake()->createWithContent('tarifario.xlsx', (string) file_get_contents($path),
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        unlink($path);

        $this->actingAs($admin)
            ->post(route('catalogos-referencia.consolidado.store'), ['tipo' => 'CUPS_CONTRATO', 'version' => '2026-10', 'archivo' => $archivo])
            ->assertSessionHas('success', 'Tarifario contractual actualizado: 2 filas procesadas. Se conservó la tarifa vigente de T34004 '
                .'porque el archivo no trae un valor válido. Sin tarifa válida: 890299.');

        $this->assertDatabaseHas('codigo_cups', ['codigo' => 'T34004', 'hoja' => 'CUPS', 'tarifa_2025' => 165000]);
        $this->assertDatabaseHas('codigo_cups', ['codigo' => '890299', 'tarifa_2025' => null]);
    }

    public function test_a_single_row_supplies_file_registers_dm0425_with_its_authorization_and_leaves_every_other_tariff_unchanged(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin']);
        CodigoInsumoNt::create(['codigo' => 'DM0425-1', 'hoja' => 'INSUMOS', 'descripcion' => 'EQUIPO DE INFUSION PARA BOMBA INFUSOMAT SPACE SAFESET PUR SA', 'nt' => 'Si', 'tarifa_unitario' => 600]);
        CodigoInsumoNt::create(['codigo' => 'T34004', 'hoja' => 'INSUMOS', 'descripcion' => 'TRANSPORTE NO ASISTENCIAL TERRESTRE EN ZONA DE DISPERSION', 'nt' => 'Si', 'tarifa_unitario' => 135000]);
        CodigoCups::create(['codigo' => 'T34004', 'hoja' => 'CUPS', 'tipo_servicio' => 'TRANSPORTE', 'descripcion' => 'TRANSPORTE', 'tarifa_2025' => 165000]);
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->setTitle('AUTORIZACION DM0425 20261027')->fromArray([
            ['CODIGO', 'DESCRIPCION', 'NT', 'TARIFA UNITARIO'],
            ['DM0425', 'EQUIPO DE BOMBA DE INFUSIÓN', 'Si', 65673],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'autorizacion-insumo-');
        (new Xlsx($spreadsheet))->save($path);
        $archivo = UploadedFile::fake()->createWithContent('DM0425_AUTORIZACION_20261027.xlsx', (string) file_get_contents($path),
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        unlink($path);

        $this->actingAs($admin)
            ->post(route('catalogos-referencia.consolidado.store'), ['tipo' => 'INSUMOS_CONTRATO', 'version' => 'Autorización DM0425 2026-10-27', 'archivo' => $archivo])
            ->assertSessionHas('success', 'Tarifario contractual actualizado: 1 filas procesadas.');

        $this->assertDatabaseHas('codigo_insumos_nt', ['codigo' => 'DM0425', 'hoja' => 'AUTORIZACION DM0425 20261027', 'descripcion' => 'EQUIPO DE BOMBA DE INFUSIÓN',
            'nt' => 'Si', 'tarifa_unitario' => 65673, 'activo' => true]);
        $this->assertDatabaseHas('codigo_insumos_nt', ['codigo' => 'DM0425-1', 'hoja' => 'INSUMOS', 'tarifa_unitario' => 600]);
        $this->assertDatabaseHas('codigo_insumos_nt', ['codigo' => 'T34004', 'hoja' => 'INSUMOS', 'tarifa_unitario' => 135000]);
        $this->assertDatabaseHas('codigo_cups', ['codigo' => 'T34004', 'hoja' => 'CUPS', 'tarifa_2025' => 165000]);
        $this->assertDatabaseMissing('codigo_cups', ['codigo' => 'DM0425']);
        $this->assertSame([3, 1], [CodigoInsumoNt::query()->count(), CodigoCups::query()->count()]);
        $import = CatalogoConsolidadoImport::query()->sole();
        $this->assertSame(['INSUMOS_CONTRATO', 'Autorización DM0425 2026-10-27', 'DM0425_AUTORIZACION_20261027.xlsx', 1, 0],
            [$import->tipo, $import->version, $import->archivo_origen, $import->registros_nuevos, $import->registros_actualizados]);
        Storage::disk('local')->assertExists($import->archivo_almacenado);
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
