<?php

namespace Tests\Feature;

use App\Models\CodigoCups;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ImportarCupsTest extends TestCase
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

    public function test_reads_only_the_cups_sheet_and_keeps_its_transport_tariff_over_the_supplies_sheet(): void
    {
        $archivo = $this->tarifarioContrato();

        $this->artisan('catalogo:importar-cups', ['archivo' => $archivo])
            ->expectsOutputToContain('Nuevos registros: 2')
            ->assertSuccessful();

        $this->assertDatabaseHas('codigo_cups', ['codigo' => 'T34004', 'hoja' => 'CUPS', 'tipo_servicio' => 'TRANSPORTE', 'tarifa_2025' => 165000]);
        $this->assertDatabaseHas('codigo_cups', ['codigo' => '890201', 'hoja' => 'CUPS', 'tarifa_2025' => 18500]);
        $this->assertDatabaseMissing('codigo_cups', ['codigo' => 'INS-01']);
        $this->assertSame(2, CodigoCups::query()->count());
    }

    public function test_rejects_the_supplies_sheet_and_leaves_cups_tariffs_unchanged(): void
    {
        CodigoCups::create(['codigo' => 'T34004', 'hoja' => 'CUPS', 'tipo_servicio' => 'TRANSPORTE', 'descripcion' => 'TRANSPORTE', 'tarifa_2025' => 165000]);

        $this->artisan('catalogo:importar-cups', ['archivo' => $this->tarifarioContrato(), '--hoja' => 'INSUMOS'])
            ->expectsOutputToContain("La hoja 'INSUMOS' no es un tarifario CUPS")
            ->assertFailed();

        $this->assertDatabaseHas('codigo_cups', ['codigo' => 'T34004', 'hoja' => 'CUPS', 'tarifa_2025' => 165000]);
        $this->assertDatabaseMissing('codigo_cups', ['codigo' => 'INS-01']);
        $this->assertSame(1, CodigoCups::query()->count());
    }

    public function test_repeated_imports_change_nothing_and_keep_codes_absent_from_the_file(): void
    {
        // Código cargado antes desde la hoja de insumos y código CUPS que ya no viene en el archivo.
        CodigoCups::create(['codigo' => 'DM0005', 'hoja' => 'INSUMOS', 'descripcion' => 'FILTRO PARA INFUSION', 'tarifa_2025' => 600, 'activo' => true]);
        CodigoCups::create(['codigo' => '890202', 'hoja' => 'CUPS', 'tipo_servicio' => 'Consulta', 'descripcion' => 'Consulta anterior', 'tarifa_2025' => 20000, 'activo' => true]);
        $archivo = $this->tarifarioContrato();

        $this->artisan('catalogo:importar-cups', ['archivo' => $archivo])->assertSuccessful();
        $this->artisan('catalogo:importar-cups', ['archivo' => $archivo])
            ->expectsOutputToContain('Nuevos registros: 0')
            ->expectsOutputToContain('Registros actualizados: 0')
            ->assertSuccessful();

        $this->assertDatabaseHas('codigo_cups', ['codigo' => 'DM0005', 'hoja' => 'INSUMOS', 'tarifa_2025' => 600, 'activo' => true]);
        $this->assertDatabaseHas('codigo_cups', ['codigo' => '890202', 'hoja' => 'CUPS', 'tarifa_2025' => 20000, 'activo' => true]);
        $this->assertDatabaseHas('codigo_cups', ['codigo' => 'T34004', 'hoja' => 'CUPS', 'tarifa_2025' => 165000]);
        $this->assertSame(4, CodigoCups::query()->count());
    }

    public function test_updates_a_tariff_changed_in_the_cups_sheet_using_the_calculated_value_of_a_formula(): void
    {
        CodigoCups::create(['codigo' => '890201', 'hoja' => 'CUPS', 'tipo_servicio' => 'Consulta', 'descripcion' => 'Tarifa anterior', 'tarifa_2025' => 10000]);
        $archivo = $this->libro([
            'CUPS' => [
                ['CUPS', 'Tipo Servicio', 'Descripción Servicio', 'Tarifa 2025'],
                ['890201', 'Consulta', 'Consulta médica especializada', '=18000+500'],
            ],
        ]);

        $this->artisan('catalogo:importar-cups', ['archivo' => $archivo])
            ->expectsOutputToContain('Registros actualizados: 1')
            ->assertSuccessful();

        $this->assertDatabaseHas('codigo_cups', ['codigo' => '890201', 'hoja' => 'CUPS', 'descripcion' => 'Consulta médica especializada', 'tarifa_2025' => 18500]);
    }

    public function test_empty_null_blank_or_text_tariffs_keep_the_existing_tariff_and_its_source_sheet(): void
    {
        CodigoCups::create(['codigo' => 'T34004', 'hoja' => 'CUPS', 'tipo_servicio' => 'TRANSPORTE', 'descripcion' => 'TRANSPORTE', 'tarifa_2025' => 165000]);
        CodigoCups::create(['codigo' => '890201', 'hoja' => 'CUPS', 'tipo_servicio' => 'Consulta', 'descripcion' => 'Consulta', 'tarifa_2025' => 18500]);
        CodigoCups::create(['codigo' => '890202', 'hoja' => 'CUPS', 'tipo_servicio' => 'Consulta', 'descripcion' => 'Control', 'tarifa_2025' => 20000]);
        CodigoCups::create(['codigo' => 'DM0005', 'hoja' => 'INSUMOS', 'descripcion' => 'FILTRO PARA INFUSION', 'tarifa_2025' => 600, 'activo' => true]);
        $archivo = $this->libro([
            'CUPS' => [
                ['CUPS', 'Tipo Servicio', 'Descripción Servicio', 'Tarifa 2025'],
                ['T34004', 'TRANSPORTE', 'TRANSPORTE', ''],
                ['890201', 'Consulta', 'Consulta', null],
                ['890202', 'Consulta', 'Control', '   '],
                ['DM0005', 'INSUMO', 'FILTRO PARA INFUSION', 'N/A'],
            ],
            'INSUMOS' => [
                ['CODIGO', 'DESCRIPCION', 'NT', 'Tarifa UNITARIO'],
                ['T34004', 'TRANSPORTE NO ASISTENCIAL TERRESTRE EN ZONA DE DISPERSION', 'Si', 135000],
            ],
        ]);

        $this->artisan('catalogo:importar-cups', ['archivo' => $archivo])
            ->expectsOutputToContain('Tarifas vigentes conservadas (celda vacía o no numérica): 4')
            ->expectsOutputToContain('Se conservó la tarifa vigente y su hoja de origen para: T34004, 890201, 890202, DM0005')
            ->assertSuccessful();

        $this->assertDatabaseHas('codigo_cups', ['codigo' => 'T34004', 'hoja' => 'CUPS', 'tipo_servicio' => 'TRANSPORTE', 'tarifa_2025' => 165000]);
        $this->assertDatabaseHas('codigo_cups', ['codigo' => '890201', 'hoja' => 'CUPS', 'tarifa_2025' => 18500]);
        $this->assertDatabaseHas('codigo_cups', ['codigo' => '890202', 'hoja' => 'CUPS', 'tarifa_2025' => 20000]);
        $this->assertDatabaseHas('codigo_cups', ['codigo' => 'DM0005', 'hoja' => 'INSUMOS', 'tarifa_2025' => 600, 'activo' => true]);
    }

    public function test_a_new_code_without_a_valid_tariff_is_reported_and_gets_no_invented_value(): void
    {
        $archivo = $this->libro([
            'CUPS' => [
                ['CUPS', 'Tipo Servicio', 'Descripción Servicio', 'Tarifa 2025'],
                ['890299', 'Consulta', 'Consulta sin tarifa en el contrato', ''],
            ],
        ]);

        $this->artisan('catalogo:importar-cups', ['archivo' => $archivo])
            ->expectsOutputToContain('Códigos sin tarifa válida: 1')
            ->expectsOutputToContain('Revise el tarifario: no se asignó ninguna tarifa a 890299')
            ->assertSuccessful();

        $this->assertDatabaseHas('codigo_cups', ['codigo' => '890299', 'hoja' => 'CUPS', 'tarifa_2025' => null]);
    }

    public function test_a_repeated_code_keeps_its_valid_tariff_when_a_later_row_of_the_file_is_empty(): void
    {
        $archivo = $this->libro([
            'CUPS' => [
                ['CUPS', 'Tipo Servicio', 'Descripción Servicio', 'Tarifa 2025'],
                ['T34004', 'TRANSPORTE', 'TRANSPORTE', 165000],
                ['T34004', 'TRANSPORTE', 'TRANSPORTE', 165000],
                ['T34004', 'TRANSPORTE', 'TRANSPORTE', ''],
            ],
        ]);

        $this->artisan('catalogo:importar-cups', ['archivo' => $archivo])
            ->expectsOutputToContain('Nuevos registros: 1')
            ->expectsOutputToContain('Códigos sin tarifa válida: 0')
            ->assertSuccessful();

        $this->assertDatabaseHas('codigo_cups', ['codigo' => 'T34004', 'hoja' => 'CUPS', 'tarifa_2025' => 165000]);
        $this->assertSame(1, CodigoCups::query()->count());
    }

    /**
     * Libro con la forma del tarifario contractual: la hoja INSUMOS repite T34004 con otra tarifa.
     */
    private function tarifarioContrato(): string
    {
        return $this->libro([
            'CUPS' => [
                ['CUPS', 'Tipo Servicio', 'Descripción Servicio', 'Tarifa 2025'],
                ['T34004', 'TRANSPORTE', 'TRANSPORTE', 165000],
                ['890201', 'Consulta', 'Consulta médica especializada', 18500],
            ],
            'INSUMOS' => [
                ['CODIGO', 'DESCRIPCION', 'NT', 'Tarifa UNITARIO'],
                ['T34004', 'TRANSPORTE NO ASISTENCIAL TERRESTRE EN ZONA DE DISPERSION', 'Si', 135000],
                ['T34004', 'TRANSPORTE NO ASISTENCIAL TERRESTRE EN ZONA DE DISPERSION', 'Si', 135000],
                ['INS-01', 'GASA ESTERIL', 'Si', 1200],
            ],
        ]);
    }

    /**
     * @param  array<string, list<list<mixed>>>  $hojas
     */
    private function libro(array $hojas): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);

        foreach ($hojas as $nombre => $filas) {
            $spreadsheet->createSheet()->setTitle($nombre)->fromArray($filas);
        }

        $archivo = sys_get_temp_dir().DIRECTORY_SEPARATOR.'siruta-tarifario-'.Str::uuid().'.xlsx';
        (new Xlsx($spreadsheet))->save($archivo);
        $this->archivos[] = $archivo;

        return $archivo;
    }
}
