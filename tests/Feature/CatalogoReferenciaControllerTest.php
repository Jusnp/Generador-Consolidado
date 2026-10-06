<?php

namespace Tests\Feature;

use App\Models\CatalogoReferencia;
use App\Models\CatalogoReferenciaItem;
use App\Models\CodigoCups;
use App\Models\ReferenciaManual;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class CatalogoReferenciaControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_is_redirected_from_reference_catalogs(): void
    {
        $this->get(route('catalogos-referencia.index'))
            ->assertRedirectToRoute('login');
    }

    public function test_non_admin_user_cannot_manage_reference_catalogs(): void
    {
        $user = User::factory()->create(['role' => 'user']);

        $this->actingAs($user)
            ->get(route('catalogos-referencia.index'))
            ->assertForbidden();
    }

    public function test_admin_user_can_access_reference_catalogs(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('catalogos-referencia.index'))
            ->assertOk();
    }

    public function test_non_admin_user_cannot_read_or_write_any_catalog_resource(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $catalog = CatalogoReferencia::create([
            'programa_slug' => 'la-maria',
            'tipo' => 'NOTA_PROSTATA',
            'version' => 'seguridad',
            'archivo_origen' => 'seguridad.xlsx',
            'activo' => true,
        ]);
        $item = CatalogoReferenciaItem::create([
            'catalogo_referencia_id' => $catalog->id,
            'codigo' => '890201',
            'descripcion' => 'Registro protegido',
            'activo' => true,
        ]);
        $reference = ReferenciaManual::create([
            'programa_slug' => 'la-maria',
            'ruta' => 'PROSTATA',
            'tipo' => 'PROCEDIMIENTO',
            'codigo' => '890201',
            'nombre' => 'Referencia protegida',
            'nombre_normalizado' => 'REFERENCIA PROTEGIDA',
            'tarifa' => 1000,
            'activo' => true,
        ]);
        $cups = CodigoCups::create(['codigo' => '890201']);

        $requests = [
            ['GET', route('catalogos-referencia.show', $catalog), []],
            ['GET', route('catalogos-referencia.download', $catalog), []],
            ['GET', route('catalogos-referencia.consolidado.show', 'CUPS_CONTRATO'), []],
            ['GET', route('referencias-manuales.index'), []],
            ['POST', route('catalogos-referencia.store'), []],
            ['POST', route('catalogos-referencia.consolidado.store'), []],
            ['POST', route('catalogos-referencia.items.store', $catalog), []],
            ['PATCH', route('catalogos-referencia.items.update', [$catalog, $item->id]), []],
            ['PATCH', route('catalogos-referencia.items.toggle', [$catalog, $item]), []],
            ['POST', route('catalogos-referencia.toggle-file', $catalog), []],
            ['POST', route('catalogos-referencia.consolidado.store-row'), []],
            ['PATCH', route('catalogos-referencia.consolidado.update', ['cups', $cups->id]), []],
            ['PATCH', route('catalogos-referencia.consolidado.toggle', ['cups', $cups->id]), []],
            ['POST', route('catalogos-referencia.consolidado.toggle-file', 'CUPS_CONTRATO'), []],
            ['POST', route('referencias-manuales.store'), []],
            ['PUT', route('referencias-manuales.update', $reference), []],
            ['PATCH', route('referencias-manuales.toggle', $reference), []],
        ];

        foreach ($requests as [$method, $uri, $data]) {
            $this->actingAs($user)
                ->call($method, $uri, $data)
                ->assertForbidden();
        }
    }

    public function test_admin_must_select_catalog_type_version_and_excel_file(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->from(route('catalogos-referencia.index'))
            ->actingAs($admin)
            ->post(route('catalogos-referencia.store'))
            ->assertRedirectToRoute('catalogos-referencia.index')
            ->assertSessionHasErrors([
                'tipo' => 'Selecciona el tipo de catálogo que vas a cargar.',
                'version' => 'Indica la versión o fecha de referencia del catálogo.',
                'archivo' => 'Selecciona el archivo Excel de referencia.',
            ]);
    }

    public function test_admin_can_import_annex_reference_catalog_and_replace_the_active_version(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $oldCatalog = CatalogoReferencia::create([
            'programa_slug' => 'la-maria',
            'tipo' => 'ANEXOS_2706',
            'version' => 'anterior',
            'archivo_origen' => 'anterior.xlsx',
            'activo' => true,
        ]);

        $this->actingAs($admin)
            ->post(route('catalogos-referencia.store'), [
                'tipo' => 'ANEXOS_2706',
                'version' => '2026-09-18',
                'archivo' => $this->annexWorkbook(),
            ])
            ->assertRedirectToRoute('catalogos-referencia.index')
            ->assertSessionHas('success');

        $this->assertDatabaseHas('catalogo_referencias', [
            'programa_slug' => 'la-maria',
            'tipo' => 'ANEXOS_2706',
            'version' => '2026-09-18',
            'archivo_origen' => 'anexos.xlsx',
            'activo' => true,
            'imported_by' => $admin->id,
        ]);
        $this->assertDatabaseHas('catalogo_referencia_items', [
            'codigo' => '890201',
            'categoria' => 'ANEXO_2',
            'descripcion' => 'Consulta de referencia',
        ]);
        $this->assertFalse($oldCatalog->fresh()->activo);
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $admin->id,
            'action' => 'catalogo_referencia_importado',
        ]);
    }

    public function test_admin_can_import_cum_data_for_medication_identification(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->post(route('catalogos-referencia.store'), [
                'tipo' => 'MEDICAMENTOS_CUM',
                'version' => '2026-09-18',
                'archivo' => $this->cumWorkbook(),
            ])
            ->assertRedirectToRoute('catalogos-referencia.index');

        $item = CatalogoReferencia::query()
            ->where('tipo', 'MEDICAMENTOS_CUM')
            ->firstOrFail()
            ->items()
            ->firstOrFail();

        $this->assertSame('20085509-2', $item->codigo);
        $this->assertSame('Pembrolizumab', $item->metadatos['principio_activo']);
        $this->assertSame('L01XC18', $item->metadatos['atc']);
    }

    public function test_admin_can_import_medication_technical_note_by_cum_and_principio(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->post(route('catalogos-referencia.store'), [
                'tipo' => 'NOTA_MEDICAMENTOS',
                'version' => '2026-09-22',
                'archivo' => $this->medicationTechnicalNoteWorkbook(),
            ])
            ->assertRedirectToRoute('catalogos-referencia.index')
            ->assertSessionHas('success');

        $catalog = CatalogoReferencia::query()
            ->where('tipo', 'NOTA_MEDICAMENTOS')
            ->where('activo', true)
            ->firstOrFail();

        $this->assertSame('la-maria', $catalog->programa_slug);
        $this->assertSame(3, $catalog->items()->count());

        $prostata = $catalog->items()->where('codigo', '19935327-1')->firstOrFail();
        $this->assertSame('PROSTATA', $prostata->ruta);
        $this->assertSame('MEDICAMENTO_NT', $prostata->categoria);
        $this->assertSame('BICALUTAMIDA 50 MG TABLETA', $prostata->descripcion);
        $this->assertSame(12500.0, $prostata->tarifa_referencia);
        $this->assertSame('BICALUTAMIDA', $prostata->metadatos['principio_activo']);

        $paliativo = $catalog->items()->where('codigo', '19900001-1')->firstOrFail();
        $this->assertNull($paliativo->ruta);
        $this->assertSame('MEDICAMENTO_NT_PALIATIVO', $paliativo->categoria);
        $this->assertSame(800.0, $paliativo->tarifa_referencia);
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

    private function cumWorkbook(): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Data');
        $sheet->fromArray([
            [
                'expedientecum', 'consecutivocum', 'producto', 'descripcioncomercial', 'atc',
                'descripcionatc', 'viaadministracion', 'concentracion', 'principioactivo',
                'formaFarmaceutica', 'estadocum',
            ],
            [
                '20085509', '2', 'Pembrolizumab', 'Pembrolizumab 100 mg', 'L01XC18',
                'Anticuerpos monoclonales', 'Intravenosa', '100 mg', 'Pembrolizumab',
                'Solución inyectable', 'Activo',
            ],
        ]);

        $path = tempnam(sys_get_temp_dir(), 'catalogo-cum-');

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
            'cum.xlsx',
            $content,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );
    }

    private function medicationTechnicalNoteWorkbook(): UploadedFile
    {
        $spreadsheet = new Spreadsheet;

        $prostata = $spreadsheet->getActiveSheet();
        $prostata->setTitle('CA PROSTATA');
        $prostata->fromArray([
            ['CUM', 'DESCRIPCION ESTANDARIZADA', 'PRINCIPIO ACTIVO', 'TARIFA'],
            ['19935327-1', 'BICALUTAMIDA 50 MG TABLETA', 'BICALUTAMIDA', 12500],
        ]);

        $cervix = $spreadsheet->createSheet();
        $cervix->setTitle('CA CERVIX');
        $cervix->fromArray([
            ['CUM', 'DESCRIPCION ESTANDARIZADA', 'PRINCIPIO ACTIVO', 'TARIFA MIN REG'],
            ['19999999-1', 'CISPLATINO 50 MG', 'CISPLATINO', 45000],
        ]);

        $paliativos = $spreadsheet->createSheet();
        $paliativos->setTitle('Medicamentos paliativos');
        $paliativos->fromArray([
            ['CUM', 'DESCRIPCION ESTANDARIZADA', 'PRINCIPIO ACTIVO', 'TARIFA'],
            ['19900001-1', 'ACETAMINOFEN 500 MG TABLETA', 'ACETAMINOFEN', 800],
        ]);

        $path = tempnam(sys_get_temp_dir(), 'catalogo-nt-meds-');

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
            'nota-medicamentos.xlsx',
            $content,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );
    }
}
