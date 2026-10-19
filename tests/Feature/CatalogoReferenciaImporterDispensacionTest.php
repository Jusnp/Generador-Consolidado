<?php

namespace Tests\Feature;

use App\Models\CatalogoReferencia;
use App\Models\CatalogoReferenciaItem;
use App\Models\User;
use App\Services\CatalogoReferenciaImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class CatalogoReferenciaImporterDispensacionTest extends TestCase
{
    use RefreshDatabase;

    private const HEADERS = [
        'TIPO DOCUMENTO PACIENTE', 'DOCUMENTO PACIENTE', 'FECHA DISPENSACION', 'NOMBRE GENERICO',
        'UNIDADES DISPENSADAS', 'VALOR UNITARIO MEDICAMENTOS DISPENSADOS', 'VALOR TOTAL MEDICAMENTOS DISPENSADOS', 'CODIGO CUM',
    ];

    public function test_excel_serial_dates_use_the_1900_date_system(): void
    {
        $this->import([
            'DISPEN 2026' => [self::HEADERS, ['CC', 700, 46174, 'ENZALUTAMIDA 40 MG', 120, 46624, 5594880, '20195068-1'], ['CC', 701, 46218.5, 'ENZALUTAMIDA 40 MG', 120, 46624, 5594880, '20195068-1']],
        ]);

        $this->assertSame(['2026-06-01', '2026-07-15'], $this->items()->map(fn (CatalogoReferenciaItem $item): string => $item->metadatos['fecha_dispensacion'])->all());
    }

    public function test_excel_serial_dates_use_the_1904_date_system_when_the_workbook_declares_it(): void
    {
        $this->import([
            'DISPEN 2026' => [self::HEADERS, ['CC', 700, 44712, 'ENZALUTAMIDA 40 MG', 120, 46624, 5594880, '20195068-1']],
        ], date1904: true);

        $this->assertSame('2026-06-01', $this->items()->first()->metadatos['fecha_dispensacion']);
    }

    public function test_money_formats_are_read_with_their_explicit_meaning(): void
    {
        $this->import([
            'DISPEN 2026' => [
                self::HEADERS,
                ['CC', 700, 46174, 'COMA DE MILES', 60, ' $ 3,335 ', '$200,100', '20000001-1'],
                ['CC', 701, 46174, 'PUNTO DE MILES', 60, '$ 3.335', '$ 200.100', '20000002-1'],
                ['CC', 702, 46174, 'NUMERO DE EXCEL', 1, 339686, 339686, '20000003-1'],
                ['CC', 703, 46174, 'DECIMAL CON COMA', 2, '3.335,50', '6.671,00', '20000004-1'],
                ['CC', 704, 46174, 'DECIMAL CON PUNTO', 2, '3,335.50', '6,671.00', '20000005-1'],
                ['CC', 705, 46174, 'DEVOLUCION', 1, '$ -3,335', '$ -3,335', '20000006-1'],
                ['CC', 706, 46174, 'TOTAL VACIO', 3, '$ 1,000', null, '20000007-1'],
            ],
        ]);

        $items = $this->items()->keyBy('codigo');

        $this->assertSame(3335.0, $items['20000001-1']->tarifa_referencia);
        $this->assertSame(3335.0, $items['20000002-1']->tarifa_referencia);
        $this->assertSame(200100.0, (float) $items['20000002-1']->metadatos['valor_total_dispensado']);
        $this->assertSame(339686.0, $items['20000003-1']->tarifa_referencia);
        $this->assertSame(3335.5, $items['20000004-1']->tarifa_referencia);
        $this->assertSame(3335.5, $items['20000005-1']->tarifa_referencia);
        $this->assertSame(-3335.0, $items['20000006-1']->tarifa_referencia);
        $this->assertNull($items['20000007-1']->metadatos['valor_total_dispensado']);
        $this->assertSame(
            ['CUADRA', 'CUADRA', 'CUADRA', 'CUADRA', 'CUADRA', 'CUADRA', 'SIN_VALOR'],
            $items->map(fn (CatalogoReferenciaItem $item): string => $item->metadatos['control_valor'])->values()->all()
        );
    }

    public function test_units_times_price_inconsistencies_are_flagged_without_changing_values(): void
    {
        $this->import([
            'DISPEN 2026' => [
                self::HEADERS,
                ['CC', 700, 46174, 'REDONDEO', 60, '$ 3,335', '$200,070', '20000001-1'],
                ['CC', 701, 46174, 'INCONSISTENTE', 2, '$ 100', '$ 500', '20000002-1'],
            ],
        ]);

        $items = $this->items()->keyBy('codigo');

        $this->assertSame('REDONDEO', $items['20000001-1']->metadatos['control_valor']);
        $this->assertSame('INCONSISTENTE', $items['20000002-1']->metadatos['control_valor']);
        $this->assertSame(100.0, $items['20000002-1']->tarifa_referencia);
        $this->assertSame(500.0, (float) $items['20000002-1']->metadatos['valor_total_dispensado']);
    }

    public function test_shifted_headers_never_turn_a_wrong_column_into_document_date_quantity_or_price(): void
    {
        $shifted = [self::HEADERS];

        for ($row = 0; $row < 12; $row++) {
            // Los datos quedaron una columna a la derecha de su encabezado.
            $shifted[] = ['', 'CC', 'JUAN PEREZ', 46174, 'PEMBROLIZUMAB', 2, '$ 13,763,776', '$ 27,527,552', '20085509-2'];
        }

        $result = $this->import([
            'DISPEN 2025' => $shifted,
            'DISPEN 2026' => [self::HEADERS, ['CC', 700, 46174, 'PEMBROLIZUMAB', 2, '$ 13,763,776', '$ 27,527,552', '20085509-2']],
        ]);

        $this->assertSame(1, $this->items()->count());
        $this->assertSame('ENCABEZADOS_NO_CONFIABLES', $result['diagnostico']['DISPEN 2025']['hoja_rechazada']);
        $this->assertSame(12, $result['diagnostico']['DISPEN 2025']['rechazadas']['DOCUMENTO_INVALIDO']);
        $this->assertSame(1, $result['diagnostico']['DISPEN 2026']['aceptadas']);
    }

    public function test_rows_with_invalid_fields_are_rejected_with_their_reason(): void
    {
        $result = $this->import([
            'DISPEN 2026' => [
                self::HEADERS,
                ['CC', 700, 46174, 'OK', 1, 100, 100, '20000001-1'],
                ['CC', 701, 46174, 'CODIGO INTERNO', 1, 100, 100, 'J01032'],
                ['CC', 702, 'no es fecha', 'FECHA', 1, 100, 100, '20000002-1'],
                ['CC', 703, 46174, 'SIN UNIDADES', null, 100, 100, '20000003-1'],
                ['CC', 704, 46174, 'PRECIO TEXTO', 1, 'gratis', 100, '20000004-1'],
            ],
        ]);

        $this->assertSame(['20000001-1'], $this->items()->pluck('codigo')->all());
        $this->assertSame(
            ['CUM_INVALIDO' => 1, 'FECHA_INVALIDA' => 1, 'UNIDADES_INVALIDAS' => 1, 'VALOR_UNITARIO_NO_INTERPRETABLE' => 1],
            $result['diagnostico']['DISPEN 2026']['rechazadas']
        );
    }

    public function test_cum_registry_keeps_ium_presentation_and_sample_condition(): void
    {
        $this->import([
            'Data' => [
                ['expediente', 'producto', 'registrosanitario', 'estadoregistro', 'expedientecum', 'consecutivocum', 'cantidadcum', 'descripcioncomercial', 'estadocum', 'fechaactivo', 'fechainactivo', 'muestramedica', 'unidad', 'atc', 'concentracion', 'principioactivo', 'unidadmedida', 'cantidad', 'unidadreferencia', 'formafarmaceutica', 'IUM'],
                ['20254463', 'ERLEADA 240 MG', 'INVIMA 2023M', 'Vigente', '20254463', '2', '30', 'M. MEDICA: CAJA X 30', 'Activo', '09/28/2023', '', 'Si', 'U', 'L02BB05', 'A', 'APALUTAMIDA', 'mg', '240', 'TABLETA', 'TABLETA RECUBIERTA', '1A1060601000101'],
            ],
        ], type: CatalogoReferenciaImporter::TYPE_MEDICAMENTOS_CUM);

        $metadata = $this->items()->first()->metadatos;

        $this->assertSame('20254463-2', $this->items()->first()->codigo);
        $this->assertSame('1A1060601000101', $metadata['ium']);
        $this->assertSame('Si', $metadata['muestra_medica']);
        $this->assertSame(30.0, (float) $metadata['cantidad_cum']);
        $this->assertSame(240.0, (float) $metadata['cantidad']);
        $this->assertSame('mg', $metadata['unidad_medida']);
        $this->assertSame('2023-09-28', $metadata['fecha_activo']);
        $this->assertSame('TABLETA RECUBIERTA', $metadata['forma_farmaceutica']);
    }

    public function test_a_new_version_can_be_imported_without_activating_it_and_keeps_previous_versions(): void
    {
        $sheet = ['DISPEN 2026' => [self::HEADERS, ['CC', 700, 46174, 'X', 1, 100, 100, '20000001-1']]];
        $first = $this->import($sheet);
        $staged = $this->import($sheet, activar: false);

        $this->assertTrue($first['catalogo']->fresh()->activo);
        $this->assertFalse($staged['catalogo']->fresh()->activo);

        $active = $this->import($sheet);

        $this->assertTrue($active['catalogo']->fresh()->activo);
        $this->assertFalse($first['catalogo']->fresh()->activo);
        $this->assertSame(3, CatalogoReferencia::query()->where('tipo', CatalogoReferenciaImporter::TYPE_DISPENSACION_CERVIX)->count());
        $this->assertSame(3, CatalogoReferenciaItem::query()->count());
    }

    /**
     * @param  array<string, list<list<mixed>>>  $sheets
     * @return array<string, mixed>
     */
    private function import(array $sheets, bool $date1904 = false, string $type = CatalogoReferenciaImporter::TYPE_DISPENSACION_CERVIX, bool $activar = true): array
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);

        if ($date1904) {
            $spreadsheet->setExcelCalendar(Date::CALENDAR_MAC_1904);
        }

        foreach ($sheets as $name => $rows) {
            $spreadsheet->createSheet()->setTitle($name)->fromArray($rows, null, 'A1', true);
        }

        $path = tempnam(sys_get_temp_dir(), 'importador-');
        (new Xlsx($spreadsheet))->save($path);
        $content = (string) file_get_contents($path);
        unlink($path);

        return app(CatalogoReferenciaImporter::class)->import(
            UploadedFile::fake()->createWithContent('fuente.xlsx', $content),
            $type,
            'prueba',
            User::factory()->create(),
            activar: $activar
        );
    }

    /**
     * @return Collection<int, CatalogoReferenciaItem>
     */
    private function items(): Collection
    {
        return CatalogoReferenciaItem::query()->orderBy('id')->get();
    }
}
