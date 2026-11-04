<?php

namespace Tests\Feature;

use App\Models\CodigoCups;
use App\Models\CodigoInsumoNt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ImportarInsumosNtTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $archivos = [];

    protected function tearDown(): void
    {
        foreach ($this->archivos as $archivo) {
            @unlink($archivo);
        }

        parent::tearDown();
    }

    public function test_writes_one_supplies_record_per_repeated_code_and_never_changes_cups_tariffs(): void
    {
        CodigoCups::create(['codigo' => 'T34004', 'hoja' => 'CUPS', 'tipo_servicio' => 'TRANSPORTE', 'descripcion' => 'TRANSPORTE', 'tarifa_2025' => 165000]);

        $this->artisan('catalogo:importar-insumos-nt', ['archivo' => $this->tarifarioContrato()])
            ->expectsOutputToContain('Nuevos registros: 2')
            ->assertSuccessful();

        $this->assertDatabaseHas('codigo_insumos_nt', ['codigo' => 'T34004', 'hoja' => 'INSUMOS', 'nt' => 'Si', 'tarifa_unitario' => 135000]);
        $this->assertDatabaseHas('codigo_insumos_nt', ['codigo' => 'INS-01', 'hoja' => 'INSUMOS', 'tarifa_unitario' => 1200]);
        $this->assertSame(2, CodigoInsumoNt::query()->count());
        $this->assertDatabaseHas('codigo_cups', ['codigo' => 'T34004', 'hoja' => 'CUPS', 'tarifa_2025' => 165000]);
        $this->assertSame(1, CodigoCups::query()->count());
    }

    public function test_rejects_the_cups_sheet_without_writing_supplies(): void
    {
        $this->artisan('catalogo:importar-insumos-nt', ['archivo' => $this->tarifarioContrato(), '--hoja' => 'CUPS'])
            ->expectsOutputToContain("La hoja 'CUPS' no es un tarifario de insumos")
            ->assertFailed();

        $this->assertSame(0, CodigoInsumoNt::query()->count());
    }

    public function test_import_order_keeps_both_t34004_tariffs_with_the_sheet_they_come_from(): void
    {
        $archivo = $this->tarifarioContrato();

        $this->artisan('catalogo:importar-insumos-nt', ['archivo' => $archivo])->assertSuccessful();
        $this->artisan('catalogo:importar-cups', ['archivo' => $archivo])->assertSuccessful();
        $this->artisan('catalogo:importar-insumos-nt', ['archivo' => $archivo])
            ->expectsOutputToContain('Registros actualizados: 0')
            ->assertSuccessful();

        // La discrepancia queda trazable: el transporte vale 165.000 en CUPS y la hoja de insumos conserva sus 135.000.
        $this->assertDatabaseHas('codigo_cups', ['codigo' => 'T34004', 'hoja' => 'CUPS', 'tipo_servicio' => 'TRANSPORTE', 'tarifa_2025' => 165000]);
        $this->assertDatabaseHas('codigo_insumos_nt', ['codigo' => 'T34004', 'hoja' => 'INSUMOS', 'tarifa_unitario' => 135000]);
        $this->assertDatabaseMissing('codigo_cups', ['codigo' => 'INS-01']);
    }

    /**
     * Libro con la forma del tarifario contractual: la hoja INSUMOS repite T34004 con otra tarifa.
     */
    private function tarifarioContrato(): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->setTitle('CUPS')->fromArray([
            ['CUPS', 'Tipo Servicio', 'Descripción Servicio', 'Tarifa 2025'],
            ['T34004', 'TRANSPORTE', 'TRANSPORTE', 165000],
        ]);
        $spreadsheet->createSheet()->setTitle('INSUMOS')->fromArray([
            ['CODIGO', 'DESCRIPCION', 'NT', 'Tarifa UNITARIO'],
            ['T34004', 'TRANSPORTE NO ASISTENCIAL TERRESTRE EN ZONA DE DISPERSION', 'Si', 135000],
            ['T34004', 'TRANSPORTE NO ASISTENCIAL TERRESTRE EN ZONA DE DISPERSION', 'Si', 135000],
            ['T34004', 'TRANSPORTE NO ASISTENCIAL TERRESTRE EN ZONA DE DISPERSION', 'Si', 135000],
            ['INS-01', 'GASA ESTERIL', 'Si', 1200],
            ['INS-01', 'GASA ESTERIL', 'Si', 1200],
        ]);

        $archivo = sys_get_temp_dir().DIRECTORY_SEPARATOR.'siruta-tarifario-'.Str::uuid().'.xlsx';
        (new Xlsx($spreadsheet))->save($archivo);
        $this->archivos[] = $archivo;

        return $archivo;
    }
}
