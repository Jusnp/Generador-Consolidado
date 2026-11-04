<?php

namespace Tests\Feature;

use App\Models\CatalogoReferencia;
use App\Models\CodigoCups;
use App\Models\CodigoInsumoNt;
use App\Models\CodigoMedicamento;
use App\Models\CodigoMedicamentoNt;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;
use ZipArchive;

class JsonExcelBatchConversionTest extends TestCase
{
    use RefreshDatabase;

    private string $almacenamientoAislado;

    protected function setUp(): void
    {
        parent::setUp();

        // Los consolidados, los RIPS temporales y los temporales de OpenSpout de cada prueba quedan fuera del storage real.
        $this->almacenamientoAislado = sys_get_temp_dir().DIRECTORY_SEPARATOR.'siruta-autoinmunes-'.Str::uuid();
        $this->app->useStoragePath($this->almacenamientoAislado);
        config(['filesystems.disks.local.root' => $this->almacenamientoAislado.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'private']);
        Storage::forgetDisk('local');
    }

    protected function tearDown(): void
    {
        $almacenamientoAislado = $this->almacenamientoAislado;

        parent::tearDown();

        // En Windows un RIPS borrado sigue ocupando su carpeta mientras el lector en streaming lo retenga.
        gc_collect_cycles();
        (new Filesystem)->deleteDirectory($almacenamientoAislado);
    }

    public function test_medication_key_shows_dispensing_day_without_changing_monthly_duplicates(): void
    {
        $user = User::factory()->create();
        CodigoMedicamentoNt::create(['cums' => 'MED-001', 'pertenece_nt' => 'SI', 'tarifa_nt' => 100]);
        $payload = json_decode($this->jsonWithMedication('100', '2026-08-10'), true, 512, JSON_THROW_ON_ERROR);
        $payload['usuarios'][0]['servicios']['medicamentos'][] = [
            ...$payload['usuarios'][0]['servicios']['medicamentos'][0],
            'fechaDispensAdmon' => '2026-08-11',
        ];

        $path = $this->convertFixture($user, $payload);
        $sheet = IOFactory::load($path)->getSheetByName('AM');
        $headers = $sheet->rangeToArray('A1:S1')[0];
        $keyColumn = Coordinate::stringFromColumnIndex(array_search('LLAVE DÍA MES AÑO', $headers, true) + 1);
        $duplicateColumn = Coordinate::stringFromColumnIndex(array_search('DUPLICADOS MES AÑO', $headers, true) + 1);
        $this->assertSame('100-MED-001-10/08/2026', (string) $sheet->getCell($keyColumn.'2')->getValue());
        $this->assertSame('100-MED-001-11/08/2026', (string) $sheet->getCell($keyColumn.'3')->getValue());
        $this->assertSame(2, (int) $sheet->getCell($duplicateColumn.'2')->getValue());
        $this->assertSame(2, (int) $sheet->getCell($duplicateColumn.'3')->getValue());
    }

    public function test_transport_from_cups_and_insumos_is_counted_once_by_rips_units(): void
    {
        $user = User::factory()->create();
        CodigoCups::create(['codigo' => 'T34004', 'descripcion' => 'TRANSPORTE NO ASISTENCIAL', 'tarifa_2025' => 135000, 'activo' => true]);
        CodigoInsumoNt::create(['codigo' => 'T34004', 'descripcion' => 'TRANSPORTE NO ASISTENCIAL', 'nt' => 'SI', 'tarifa_unitario' => 135000, 'activo' => true]);
        CodigoCups::create(['codigo' => 'T34003', 'tipo_servicio' => 'TRANSPORTE', 'descripcion' => 'OTRO TRANSPORTE', 'tarifa_2025' => 165000, 'activo' => true]);
        // Transporte tipificado en CUPS sin tarifa: con tarifa NT de la hoja de insumos, o pendiente; nunca como insumo.
        CodigoCups::create(['codigo' => '6020', 'tipo_servicio' => 'TRANSPORTE', 'descripcion' => 'TRANSPORTE', 'tarifa_2025' => null, 'activo' => true]);
        CodigoInsumoNt::create(['codigo' => '6020', 'descripcion' => 'Traslado Asistencial Medicalizado Aéreo Primario', 'nt' => 'SI', 'tarifa_unitario' => 135000, 'activo' => true]);
        CodigoCups::create(['codigo' => 'T34009', 'tipo_servicio' => 'TRANSPORTE', 'descripcion' => 'TRANSPORTE', 'tarifa_2025' => null, 'activo' => true]);
        $payload = json_decode($this->jsonWithMedication('100', '2026-08-10'), true, 512, JSON_THROW_ON_ERROR);
        $payload['usuarios'][0]['servicios']['medicamentos'] = [];
        $payload['usuarios'][0]['servicios']['otrosServicios'] = [
            ['fechaSuministroTecnologia' => '2026-08-10', 'codTecnologiaSalud' => 'T34004', 'tipoOS' => '04', 'cantidadOS' => 2, 'vrServicio' => 0],
            ['fechaSuministroTecnologia' => '2026-08-11', 'codTecnologiaSalud' => 'T34003', 'tipoOS' => '04', 'cantidadOS' => 1, 'vrServicio' => 0],
            ['fechaSuministroTecnologia' => '2026-08-12', 'codTecnologiaSalud' => 'T34004', 'tipoOS' => '04', 'cantidadOS' => 0, 'vrServicio' => 0],
            ['fechaSuministroTecnologia' => '2026-08-13', 'codTecnologiaSalud' => '6020', 'tipoOS' => '04', 'cantidadOS' => 1, 'vrServicio' => 0],
            ['fechaSuministroTecnologia' => '2026-08-14', 'codTecnologiaSalud' => 'T34009', 'tipoOS' => '04', 'cantidadOS' => 1, 'vrServicio' => 0],
        ];

        $path = $this->convertFixture($user, $payload);
        $workbook = IOFactory::load($path);
        $other = $workbook->getSheetByName('OTROS');
        $this->assertSame('270000', (string) $other->getCell('O2')->getValue());
        $this->assertSame('165000', (string) $other->getCell('O3')->getValue());
        $this->assertSame('0', (string) $other->getCell('O4')->getValue());
        $this->assertSame('TRANSPORTE SIN UNIDAD RIPS', (string) $other->getCell('P4')->getValue());
        $this->assertSame(['135000', 'ENCONTRADO TRANSPORTE INSUMOS NT'], [(string) $other->getCell('O5')->getValue(), (string) $other->getCell('P5')->getValue()]);
        $this->assertSame(['0', 'TRANSPORTE SIN TARIFA'], [(string) $other->getCell('O6')->getValue(), (string) $other->getCell('P6')->getValue()]);
        $summary = $workbook->getSheetByName('RESUMEN')->toArray(null, false, false);
        $transport = collect($summary)->first(fn (array $row): bool => in_array('Transporte', $row, true));
        $insumos = collect($summary)->first(fn (array $row): bool => in_array('Insumos', $row, true));
        $this->assertSame(570000, (int) (string) end($transport));
        $this->assertSame(0, (int) (string) end($insumos));
    }

    public function test_wrong_medication_homologue_uses_exact_cum_or_remains_pending(): void
    {
        $user = User::factory()->create();
        CodigoMedicamento::create(['codigo' => '20062328-1', 'nt' => 'SI', 'cums_homologo' => '20100066-3', 'tarifa_unitario' => 39589.4080, 'activo' => true]);
        CodigoMedicamentoNt::create(['cums' => '20100066-3', 'pertenece_nt' => 'SI', 'tarifa_nt' => 39589.4080, 'activo' => true]);
        CodigoMedicamentoNt::create(['cums' => '20062328-1', 'pertenece_nt' => 'SI', 'tarifa_nt' => 796087.3331, 'activo' => true]);
        CodigoMedicamento::create(['codigo' => '19943979-1', 'nt' => 'SI', 'cums_homologo' => '19963298-2', 'tarifa_unitario' => 3505.806, 'activo' => true]);
        CodigoMedicamentoNt::create(['cums' => '19963298-2', 'pertenece_nt' => 'SI', 'tarifa_nt' => 3505.806, 'activo' => true]);
        $payload = json_decode($this->jsonWithMedication('100', '2026-08-10'), true, 512, JSON_THROW_ON_ERROR);
        $payload['usuarios'][0]['servicios']['medicamentos'] = [
            ['fechaDispensAdmon' => '2026-08-10', 'codTecnologiaSalud' => '20062328-1', 'nomTecnologiaSalud' => 'TOCILIZUMAB', 'cantidadMedicamento' => 1],
            ['fechaDispensAdmon' => '2026-08-10', 'codTecnologiaSalud' => '19943979-1', 'nomTecnologiaSalud' => 'LEVOTIROXINA', 'cantidadMedicamento' => 1],
        ];

        $path = $this->convertFixture($user, $payload);
        $sheet = IOFactory::load($path)->getSheetByName('AM');
        $this->assertSame('20062328-1', (string) $sheet->getCell('M2')->getValue());
        $this->assertEqualsWithDelta(796087.3331, (float) (string) $sheet->getCell('O2')->getValue(), 0.01);
        $this->assertSame('19943979-1', (string) $sheet->getCell('M3')->getValue());
        $this->assertSame(0, (int) (string) $sheet->getCell('O3')->getValue());
        $this->assertStringContainsString('REVISAR HOMOLOGACION', (string) $sheet->getCell('S3')->getValue());
    }

    public function test_medication_without_tariff_uses_the_nt_product_with_the_same_registry_ingredients_strength_and_form(): void
    {
        $user = User::factory()->create();
        $registro = CatalogoReferencia::create(['tipo' => 'MEDICAMENTOS_CUM', 'version' => 'prueba', 'archivo_origen' => 'CUM.xlsx', 'activo' => true]);
        $cum = fn (string $codigo, string $activo, float $cantidad, string $forma, string $unidad = 'mg', bool $vigente = true) => $registro->items()->create(['codigo' => $codigo,
            'hoja' => 'Data', 'categoria' => 'CUM', 'descripcion' => $activo, 'activo' => $vigente,
            'metadatos' => ['principio_activo' => $activo, 'cantidad' => $cantidad, 'unidad_medida' => $unidad, 'forma_farmaceutica' => $forma]]);
        // RIPS: mofetil 500 mg con homólogo anterior sin registro; mofetil 250 mg tableta sin equivalente; dos NT de leflunomida con tarifas distintas.
        $cum('216049-3', 'MICOFENOLATO DE MOFETILO', 500, 'TABLETA');
        $cum('19996121-3', 'MICOFENOLATO MOFETILO', 500, 'TABLETA RECUBIERTA');
        $cum('216049-9', 'MICOFENOLATO DE MOFETILO', 250, 'TABLETA');
        $cum('LEF-RIPS', 'LEFLUNOMIDA', 20, 'TABLETA');
        $cum('LEF-NT-1', 'LEFLUNOMIDA', 20, 'TABLETA');
        $cum('LEF-NT-2', 'LEFLUNOMIDA', 20, 'TABLETA RECUBIERTA');
        $cum('FUERA-NT', 'MICOFENOLATO DE MOFETILO', 500, 'TABLETA');
        // Cubierta entérica (liberación modificada) y fila de registro inactiva: sin homólogo. «æg» es µg mal codificado: equivale a mcg, no a g.
        $cum('MOF-ENTERICO', 'MICOFENOLATO DE MOFETILO', 500, 'TABLETA CON CUBIERTA ENTERICA');
        $cum('MOF-INACTIVO', 'MICOFENOLATO DE MOFETILO', 500, 'TABLETA', 'mg', false);
        $cum('LEVO-NT', 'LEVOTIROXINA SODICA', 100, 'TABLETA', 'mcg');
        $cum('LEVO-RIPS', 'LEVOTIROXINA SODICA', 100, 'TABLETA', 'æg');
        $cum('LEVO-GRAMOS', 'LEVOTIROXINA SODICA', 100, 'TABLETA', 'g');
        CodigoMedicamento::create(['codigo' => '216049-3', 'nt' => 'SI', 'cums_homologo' => '20143172-1', 'tarifa_unitario' => 7495, 'activo' => true]);
        CodigoMedicamento::create(['codigo' => 'FUERA-NT', 'nt' => 'NO', 'cums_homologo' => '', 'activo' => true]);
        CodigoMedicamentoNt::create(['cums' => '20143172-1', 'pertenece_nt' => 'SI', 'tarifa_nt' => 7494.93, 'activo' => true]);
        CodigoMedicamentoNt::create(['cums' => '19996121-3', 'pertenece_nt' => 'SI', 'tarifa_nt' => 4666.628, 'activo' => true]);
        CodigoMedicamentoNt::create(['cums' => 'LEF-NT-1', 'pertenece_nt' => 'SI', 'tarifa_nt' => 3912.40, 'activo' => true]);
        CodigoMedicamentoNt::create(['cums' => 'LEF-NT-2', 'pertenece_nt' => 'SI', 'tarifa_nt' => 3505.81, 'activo' => true]);
        CodigoMedicamentoNt::create(['cums' => 'LEVO-NT', 'pertenece_nt' => 'SI', 'tarifa_nt' => 156.70, 'activo' => true]);
        $payload = json_decode($this->jsonWithMedication('100', '2026-08-10'), true, 512, JSON_THROW_ON_ERROR);
        $payload['usuarios'][0]['servicios']['medicamentos'] = array_map(static fn (string $codigo): array => [
            'fechaDispensAdmon' => '2026-08-10', 'codTecnologiaSalud' => $codigo, 'nomTecnologiaSalud' => $codigo, 'cantidadMedicamento' => 10,
        ], ['216049-3', '216049-9', 'LEF-RIPS', 'FUERA-NT', 'MOF-ENTERICO', 'MOF-INACTIVO', 'LEVO-RIPS', 'LEVO-GRAMOS']);

        $workbook = IOFactory::load($this->convertFixture($user, $payload));
        $sheet = $workbook->getSheetByName('AM');

        // Mofetil 500 mg: 19996121-3 tiene el mismo principio activo, concentración y forma oral, pero la nota técnica tarifa por CUM
        // y no define equivalencias: el homólogo queda como referencia técnica y su precio condicionado, sin sumarse.
        $this->assertSame(['19996121-3', 4666.628, 0.0, 'PRECIO CONDICIONADO'], [(string) $sheet->getCell('M2')->getValue(), (float) (string) $sheet->getCell('N2')->getValue(),
            (float) (string) $sheet->getCell('O2')->getValue(), (string) $sheet->getCell('R2')->getValue()]);
        $this->assertStringStartsWith('PRECIO CONDICIONADO SIN RESPALDO EN LA NOTA TECNICA: VALOR DE REFERENCIA 46666.28; HOMOLOGACION TECNICA REGISTRO CUM: MICOFENOLATO MOFETILO 500 MG | TABLETA',
            (string) $sheet->getCell('S2')->getValue());
        $this->assertStringContainsString('HOMOLOGO ANTERIOR DESCARTADO: HOMOLOGO 20143172-1 SIN REGISTRO CUM VERIFICABLE', (string) $sheet->getCell('S2')->getValue());
        $medicamentos = collect($workbook->getSheetByName('RESUMEN')->toArray(null, false, false))->first(fn (array $row): bool => in_array('Medicamentos', $row, true));
        $this->assertSame(0.0, (float) (string) $medicamentos[array_key_last($medicamentos)]);
        // Otra concentración, tarifas NT distintas para la misma clave, código fuera de la NT, cubierta entérica,
        // registro inactivo y gramos frente a microgramos: siguen sin tarifa.
        foreach ([3, 4, 5, 6, 7, 9] as $fila) {
            $this->assertSame(0, (int) (string) $sheet->getCell('O'.$fila)->getValue(), 'fila '.$fila);
        }
        $this->assertSame('LEVO-NT', (string) $sheet->getCell('M8')->getValue());
        $this->assertStringStartsWith('PRECIO CONDICIONADO SIN RESPALDO EN LA NOTA TECNICA: VALOR DE REFERENCIA 1567.00;', (string) $sheet->getCell('S8')->getValue());
    }

    public function test_homologated_medication_keeps_the_duplicate_classification_warning(): void
    {
        $user = User::factory()->create();
        $registro = CatalogoReferencia::create(['tipo' => 'MEDICAMENTOS_CUM', 'version' => 'prueba', 'archivo_origen' => 'CUM.xlsx', 'activo' => true]);
        foreach (['216049-3' => 'TABLETA', '19996121-3' => 'TABLETA RECUBIERTA'] as $codigo => $forma) {
            $registro->items()->create(['codigo' => $codigo, 'hoja' => 'Data', 'categoria' => 'CUM', 'descripcion' => 'MICOFENOLATO MOFETILO', 'activo' => true,
                'metadatos' => ['principio_activo' => 'MICOFENOLATO MOFETILO', 'cantidad' => 500, 'unidad_medida' => 'mg', 'forma_farmaceutica' => $forma]]);
        }
        CodigoMedicamentoNt::create(['cums' => '19996121-3', 'pertenece_nt' => 'SI', 'tarifa_nt' => 4666.628, 'activo' => true]);
        CodigoMedicamento::create(['codigo' => '20062328-1', 'nt' => 'SI', 'cums_homologo' => '20100066-3', 'tarifa_unitario' => 39589.4080, 'activo' => true]);
        CodigoMedicamentoNt::create(['cums' => '20062328-1', 'pertenece_nt' => 'SI', 'tarifa_nt' => 796087.3331, 'activo' => true]);
        $payload = json_decode($this->jsonWithMedication('100', '2026-08-10'), true, 512, JSON_THROW_ON_ERROR);
        // Dos dispensaciones del mismo CUM en el mes, sin clasificación de servicio: el valor se divide y la fila exige fuente.
        $payload['usuarios'][0]['servicios']['medicamentos'] = array_map(static fn (array $dispensacion): array => [
            'fechaDispensAdmon' => $dispensacion[1], 'codTecnologiaSalud' => $dispensacion[0], 'nomTecnologiaSalud' => $dispensacion[0], 'cantidadMedicamento' => 2,
        ], [['216049-3', '2026-08-10'], ['216049-3', '2026-08-20'], ['20062328-1', '2026-08-10'], ['20062328-1', '2026-08-20']]);

        $sheet = IOFactory::load($this->convertFixture($user, $payload))->getSheetByName('AM');

        foreach ([2, 3] as $fila) {
            $this->assertStringStartsWith('REQUIERE FUENTE DE CLASIFICACION; PRECIO CONDICIONADO SIN RESPALDO EN LA NOTA TECNICA: VALOR DE REFERENCIA 4666.63; HOMOLOGACION TECNICA REGISTRO CUM: ',
                (string) $sheet->getCell('S'.$fila)->getValue());
        }
        foreach ([4, 5] as $fila) {
            $this->assertStringStartsWith('REQUIERE FUENTE DE CLASIFICACION; CUM RIPS EXACTO; HOMOLOGO DESCARTADO: ', (string) $sheet->getCell('S'.$fila)->getValue());
        }
    }

    public function test_summary_declares_no_reference_without_a_monthly_cost_and_computes_execution_with_it(): void
    {
        $user = User::factory()->create();
        CodigoMedicamentoNt::create(['cums' => 'MED-001', 'pertenece_nt' => 'SI', 'tarifa_nt' => 100, 'activo' => true]);
        $payload = json_decode($this->jsonWithMedication('100', '2026-08-10'), true, 512, JSON_THROW_ON_ERROR);
        $fila = static function (array $resumen, string $concepto): array {
            return collect($resumen)->first(fn (array $row): bool => in_array($concepto, $row, true)) ?? [];
        };

        $sinReferencia = IOFactory::load($this->convertFixture($user, $payload, null))->getSheetByName('RESUMEN')->toArray(null, false, false);
        $conReferencia = IOFactory::load($this->convertFixture($user, $payload, 1000))->getSheetByName('RESUMEN')->toArray(null, false, false);

        $ultimo = static fn (array $row): mixed => $row[array_key_last($row)];

        $this->assertSame(['SIN REFERENCIA', 'SIN REFERENCIA'], [(string) $fila($sinReferencia, 'Consulta Medica Especializada')[0],
            (string) $ultimo($fila($sinReferencia, '% EJECUCIÓN JSON'))]);
        $this->assertSame(1000.0, (float) (string) $fila($conReferencia, 'Consulta Medica Especializada')[0]);
        $this->assertSame(0.2, round((float) (string) $ultimo($fila($conReferencia, '% EJECUCIÓN JSON')), 4));
    }

    public function test_t34004_is_valued_as_transport_with_the_cups_tariff_and_is_not_added_as_a_supply(): void
    {
        $user = User::factory()->create();
        // Tarifario contractual: la hoja CUPS fija 165.000 y la hoja INSUMOS registra 135.000 para el mismo código.
        CodigoCups::create(['codigo' => 'T34004', 'hoja' => 'CUPS', 'tipo_servicio' => 'TRANSPORTE', 'descripcion' => 'TRANSPORTE', 'tarifa_2025' => 165000, 'activo' => true]);
        CodigoInsumoNt::create(['codigo' => 'T34004', 'hoja' => 'INSUMOS', 'descripcion' => 'TRANSPORTE NO ASISTENCIAL TERRESTRE EN ZONA DE DISPERSION',
            'nt' => 'Si', 'tarifa_unitario' => 135000, 'activo' => true]);
        $payload = json_decode($this->jsonWithMedication('100', '2026-08-10'), true, 512, JSON_THROW_ON_ERROR);
        $payload['usuarios'][0]['servicios']['otrosServicios'] = [
            ['fechaSuministroTecnologia' => '2026-08-10', 'codTecnologiaSalud' => 'T34004', 'tipoOS' => '04', 'cantidadOS' => 2, 'vrServicio' => 0],
        ];

        $path = $this->convertFixture($user, $payload);
        $this->assertWorkbookStructure($path);
        $workbook = IOFactory::load($path);
        $otros = $workbook->getSheetByName('OTROS');
        $transporte = $this->valoresPorEncabezado($otros, 2);

        $this->assertSame(['T34004', 'Transporte', 165000.0, 330000.0, 'ENCONTRADO TRANSPORTE CUPS'], [(string) $transporte['CODIGO'],
            (string) $transporte['Tipo Servicio'], (float) $transporte['Tarifa UNITARIO'], (float) $transporte['Tarifa'], (string) $transporte['ESTADO']]);
        // Una sola fila: el código no se repite como insumo.
        $this->assertSame(2, $otros->getHighestRow());
        $resumen = collect($workbook->getSheetByName('RESUMEN')->toArray(null, false, false));
        $total = static function (string $concepto) use ($resumen): float {
            $fila = $resumen->first(fn (array $row): bool => in_array($concepto, $row, true));

            return (float) (string) $fila[array_key_last($fila)];
        };
        $this->assertSame([330000.0, 0.0], [$total('Transporte'), $total('Insumos')]);
    }

    public function test_conditioned_registry_homologation_keeps_the_original_cum_and_never_reaches_the_totals_whether_the_registry_is_active_or_not(): void
    {
        $user = User::factory()->create();
        $registro = CatalogoReferencia::create(['tipo' => 'MEDICAMENTOS_CUM', 'version' => 'prueba', 'archivo_origen' => 'CUM.xlsx', 'activo' => true]);
        foreach (['MOF-RIPS' => 'TABLETA', '19996121-3' => 'TABLETA RECUBIERTA'] as $codigo => $forma) {
            $registro->items()->create(['codigo' => $codigo, 'hoja' => 'Data', 'categoria' => 'CUM', 'descripcion' => 'MICOFENOLATO MOFETILO', 'activo' => true,
                'metadatos' => ['principio_activo' => 'MICOFENOLATO MOFETILO', 'cantidad' => 500, 'unidad_medida' => 'mg', 'forma_farmaceutica' => $forma]]);
        }
        CodigoMedicamentoNt::create(['cums' => '19996121-3', 'pertenece_nt' => 'SI', 'tarifa_nt' => 4666.628, 'activo' => true]);
        // MED-001 tiene tarifa contractual propia: es lo único que suma (2 x 100 subsidiado + 1 x 100 contributivo).
        CodigoMedicamentoNt::create(['cums' => 'MED-001', 'pertenece_nt' => 'SI', 'tarifa_nt' => 100, 'activo' => true]);
        $payload = json_decode($this->jsonWithMedication('100', '2026-08-10', 2), true, 512, JSON_THROW_ON_ERROR);
        $payload['usuarios'][0]['servicios']['medicamentos'][] = [
            'fechaDispensAdmon' => '2026-08-10', 'codTecnologiaSalud' => 'MOF-RIPS', 'nomTecnologiaSalud' => 'MICOFENOLATO', 'cantidadMedicamento' => 10,
        ];

        $conRegistro = IOFactory::load($this->convertFixture($user, $payload));
        $registro->update(['activo' => false]);
        $sinRegistro = IOFactory::load($this->convertFixture($user, $payload));

        $condicionado = $this->filaPorCodigo($conRegistro->getSheetByName('AM'), 'MOF-RIPS');
        $this->assertSame(['19996121-3', 0.0, 'PRECIO CONDICIONADO'], [(string) $condicionado['CUMS HOMOLOGO'],
            (float) $condicionado['VALOR TOTAL DIVIDO TODO - llave solo mes año'], (string) $condicionado['REGLA LIQUIDACION']]);
        $this->assertStringStartsWith('PRECIO CONDICIONADO SIN RESPALDO EN LA NOTA TECNICA: VALOR DE REFERENCIA 46666.28; HOMOLOGACION TECNICA REGISTRO CUM: ',
            (string) $condicionado['VALIDACION LIQUIDACION']);
        // Sin el registro externo la fila sigue visible con su CUM original, sin tarifa y sin valor.
        $pendiente = $this->filaPorCodigo($sinRegistro->getSheetByName('AM'), 'MOF-RIPS');
        $this->assertSame(['', 0.0, 'SIN TARIFA NT'], [(string) $pendiente['CUMS HOMOLOGO'],
            (float) $pendiente['VALOR TOTAL DIVIDO TODO - llave solo mes año'], (string) $pendiente['REGLA LIQUIDACION']]);

        $medicamentos = static function ($workbook): float {
            $fila = collect($workbook->getSheetByName('RESUMEN')->toArray(null, false, false))->first(fn (array $row): bool => in_array('Medicamentos', $row, true));

            return (float) (string) $fila[array_key_last($fila)];
        };
        $this->assertSame([300.0, 300.0], [$medicamentos($conRegistro), $medicamentos($sinRegistro)]);
    }

    public function test_dm0425_goes_from_not_found_to_valued_per_rips_unit_as_a_supply_once_its_authorized_tariff_is_registered(): void
    {
        $user = User::factory()->create();
        CodigoMedicamentoNt::create(['cums' => 'MED-001', 'pertenece_nt' => 'SI', 'tarifa_nt' => 100, 'activo' => true]);
        // Como en la base real, el código anterior del equipo figura en ambos catálogos con su propia tarifa.
        CodigoInsumoNt::create(['codigo' => 'DM0425-1', 'hoja' => 'INSUMOS', 'descripcion' => 'EQUIPO DE INFUSION PARA BOMBA INFUSOMAT SPACE SAFESET PUR SA',
            'nt' => 'Si', 'tarifa_unitario' => 600, 'activo' => true]);
        CodigoCups::create(['codigo' => 'DM0425-1', 'hoja' => 'INSUMOS', 'descripcion' => 'EQUIPO DE INFUSION PARA BOMBA INFUSOMAT SPACE SAFESET PUR SA',
            'tarifa_2025' => 600, 'activo' => true]);
        $equipo = ['tipoOS' => '04', 'nomTecnologiaSalud' => 'EQUIPO DE INFUSION PARA BOMBA INFUSOMAT SPACE SAFESET PUR SA', 'vrUnitOS' => 0, 'vrServicio' => 0];
        $payload = json_decode($this->jsonWithMedication('100', '2026-08-10'), true, 512, JSON_THROW_ON_ERROR);
        $payload['usuarios'][0]['servicios']['otrosServicios'] = [
            [...$equipo, 'fechaSuministroTecnologia' => '2026-08-10', 'codTecnologiaSalud' => 'DM0425', 'cantidadOS' => 1],
            [...$equipo, 'fechaSuministroTecnologia' => '2026-08-11', 'codTecnologiaSalud' => 'DM0425', 'cantidadOS' => 3],
            [...$equipo, 'fechaSuministroTecnologia' => '2026-08-12', 'codTecnologiaSalud' => 'DM0425-1', 'cantidadOS' => 1],
            ['fechaSuministroTecnologia' => '2026-08-13', 'codTecnologiaSalud' => 'DM20-2', 'tipoOS' => '01', 'nomTecnologiaSalud' => 'CATETER INTRAVENOSO', 'cantidadOS' => 1, 'vrServicio' => 0],
        ];
        $otros = function ($workbook, int $fila): array {
            $valores = $this->valoresPorEncabezado($workbook->getSheetByName('OTROS'), $fila);

            return [(string) $valores['CODIGO'], (float) $valores['CANT'], (string) $valores['Tipo Servicio'], (float) $valores['Tarifa UNITARIO'],
                (float) $valores['Tarifa'], (string) $valores['ESTADO']];
        };
        $resumen = static function ($workbook): array {
            $filas = collect($workbook->getSheetByName('RESUMEN')->toArray(null, false, false))->keyBy(1);

            return collect(['Atención domiciliaria', 'Insumos', 'Medicamentos', 'Transporte', 'Valor Mes'])
                ->mapWithKeys(fn (string $concepto): array => [$concepto => (float) (string) $filas[$concepto][array_key_last($filas[$concepto])]])
                ->all();
        };

        $sinTarifa = IOFactory::load($this->convertFixture($user, $payload));
        // Tarifa autorizada el 2026-10-27, registrada en la nota técnica de insumos con su origen en la hoja.
        CodigoInsumoNt::create(['codigo' => 'DM0425', 'hoja' => 'AUTORIZACION DM0425 20261027', 'descripcion' => 'EQUIPO DE BOMBA DE INFUSIÓN',
            'nt' => 'Si', 'tarifa_unitario' => 65673, 'activo' => true]);
        $path = $this->convertFixture($user, $payload);
        $this->assertWorkbookStructure($path);
        $conTarifa = IOFactory::load($path);

        $this->assertSame(['DM0425', 3.0, 'NO ENCONTRADO', 0.0, 0.0, 'NO ENCONTRADO'], $otros($sinTarifa, 3));
        $this->assertSame(['Atención domiciliaria' => 0.0, 'Insumos' => 600.0, 'Medicamentos' => 200.0, 'Transporte' => 0.0, 'Valor Mes' => 800.0], $resumen($sinTarifa));
        // Cantidad RIPS x tarifa unitaria, con una y con varias unidades.
        $this->assertSame(['DM0425', 1.0, 'Insumos', 65673.0, 65673.0, 'ENCONTRADO INSUMO NT'], $otros($conTarifa, 2));
        $this->assertSame(['DM0425', 3.0, 'Insumos', 65673.0, 197019.0, 'ENCONTRADO INSUMO NT'], $otros($conTarifa, 3));
        // El código anterior conserva su tarifa y el insumo sin catálogo sigue sin valor.
        $this->assertSame(['DM0425-1', 1.0, 'Insumos', 600.0, 600.0, 'ENCONTRADO INSUMO NT'], $otros($conTarifa, 4));
        $this->assertSame(['DM20-2', 1.0, 'NO ENCONTRADO', 0.0, 0.0, 'NO ENCONTRADO'], $otros($conTarifa, 5));
        // Una fila por línea RIPS y ninguna en AC-AP: el equipo se valoriza una sola vez, como insumo y no como atención domiciliaria.
        $this->assertSame(5, $conTarifa->getSheetByName('OTROS')->getHighestRow());
        $this->assertSame(1, $conTarifa->getSheetByName('AC-AP')->getHighestRow());
        $this->assertSame(['Atención domiciliaria' => 0.0, 'Insumos' => 263292.0, 'Medicamentos' => 200.0, 'Transporte' => 0.0, 'Valor Mes' => 263492.0], $resumen($conTarifa));
    }

    public function test_home_care_reaches_the_summary_only_from_services_whose_cups_type_is_home_care(): void
    {
        $user = User::factory()->create();
        CodigoMedicamentoNt::create(['cums' => 'MED-001', 'pertenece_nt' => 'SI', 'tarifa_nt' => 100, 'activo' => true]);
        CodigoCups::create(['codigo' => '890105', 'hoja' => 'CUPS', 'tipo_servicio' => 'Atención domiciliaria', 'descripcion' => 'Atencion (Visita) Domiciliaria, Por Enfermeria',
            'tarifa_2025' => 71594.88, 'activo' => true]);
        CodigoCups::create(['codigo' => '890201', 'hoja' => 'CUPS', 'tipo_servicio' => 'Consulta Medica Especializada', 'descripcion' => 'Consulta especializada',
            'tarifa_2025' => 18500, 'activo' => true]);
        $payload = json_decode($this->jsonWithMedication('100', '2026-08-10'), true, 512, JSON_THROW_ON_ERROR);
        $payload['usuarios'][0]['servicios']['procedimientos'] = [['fechaInicioAtencion' => '2026-08-10', 'codProcedimiento' => '890105', 'vrServicio' => 0]];
        $payload['usuarios'][0]['servicios']['consultas'] = [['fechaInicioAtencion' => '2026-08-11', 'codConsulta' => '890201', 'vrServicio' => 0]];

        $workbook = IOFactory::load($this->convertFixture($user, $payload));

        $domiciliaria = $this->valoresPorEncabezado($workbook->getSheetByName('AC-AP'), 3);
        $this->assertSame(['890105', 'Atención domiciliaria', 71594.88, 'ENCONTRADO'], [(string) $domiciliaria['CODIGO'], (string) $domiciliaria['Tipo Servicio'],
            (float) $domiciliaria['VALOR TOTAL'], (string) $domiciliaria['ESTADO']]);
        $filas = collect($workbook->getSheetByName('RESUMEN')->toArray(null, false, false))->keyBy(1);
        $total = static fn (string $concepto): float => (float) (string) $filas[$concepto][array_key_last($filas[$concepto])];
        $this->assertSame([71594.88, 18500.0], [$total('Atención domiciliaria'), $total('Consulta Medica Especializada')]);
    }

    private function convertFixture(User $user, array $payload, int|float|null $valorAdministrativo = 0): string
    {
        $content = json_encode($payload, JSON_THROW_ON_ERROR);
        $response = $this->actingAs($user)->post(route('json-excel.convert'), array_filter([
            'subsidiado_files' => [UploadedFile::fake()->createWithContent('sub.json', $content)],
            'contributivo_files' => [UploadedFile::fake()->createWithContent('con.json', $this->jsonWithMedication('200', '2026-08-12'))],
            'valor_administrativo' => $valorAdministrativo,
        ], static fn (mixed $valor): bool => $valor !== null));
        $response->assertOk()->assertDownload();

        return $response->baseResponse->getFile()->getPathname();
    }

    public function test_it_accepts_several_json_files_per_regimen(): void
    {
        $user = User::factory()->create();

        CodigoMedicamento::create([
            'codigo' => 'MED-001',
            'nt' => 'SI',
            'cums_homologo' => 'CUMS-001',
            'divide_por_duplicados' => true,
        ]);

        CodigoMedicamentoNt::create([
            'cums' => 'CUMS-001',
            'pertenece_nt' => 'SI',
            'tarifa_nt' => 100,
        ]);

        $subsidiadoOne = UploadedFile::fake()->createWithContent(
            'subsidiado-1.json',
            $this->jsonWithMedication('100', '2026-08-10')
        );
        $subsidiadoTwo = UploadedFile::fake()->createWithContent(
            'subsidiado-2.json',
            json_encode([
                'rips' => json_decode($this->jsonWithMedication('100', '2026-08-11'), true, 512, JSON_THROW_ON_ERROR),
                'xmlFevFile' => base64_encode('<Factura/>'),
            ], JSON_THROW_ON_ERROR)
        );
        $contributivo = UploadedFile::fake()->createWithContent(
            'contributivo-1.json',
            $this->jsonWithMedication('200', '2026-08-12')
        );

        $response = $this->actingAs($user)->post(route('json-excel.convert'), [
            'subsidiado_files' => [$subsidiadoOne, $subsidiadoTwo],
            'contributivo_files' => [$contributivo],
            'valor_administrativo' => 0,
        ]);

        $response->assertOk();
        $response->assertDownload();
        $this->assertWorkbookStructure($response->baseResponse->getFile()->getPathname());
    }

    public function test_it_accepts_a_combined_fev_rips_file_without_changing_its_regimen(): void
    {
        $user = User::factory()->create();
        $combined = UploadedFile::fake()->createWithContent('contributivo.json', json_encode([
            'rips' => json_decode($this->jsonWithMedication('200', '2026-08-12', 3), true, 512, JSON_THROW_ON_ERROR),
            'xmlFevFile' => base64_encode('<Factura/>'),
        ], JSON_THROW_ON_ERROR));

        $response = $this->actingAs($user)->post(route('json-excel.convert'), [
            'subsidiado_files' => [UploadedFile::fake()->createWithContent('subsidiado.json', $this->jsonWithMedication('100', '2026-08-10'))],
            'contributivo_files' => [$combined],
            'valor_administrativo' => 0,
        ]);

        $response->assertOk()->assertDownload();
        $path = $response->baseResponse->getFile()->getPathname();
        $this->assertWorkbookStructure($path);
        $workbook = IOFactory::load($path);
        $contributivo = $workbook->getSheetByName('CONTRIBUTIVO');
        $this->assertSame(2, $contributivo->getHighestRow());
        $headers = $contributivo->rangeToArray('A1:BH1')[0];
        $quantityColumn = array_search('cantidadMedicamento', $headers, true);
        $this->assertNotFalse($quantityColumn);
        $this->assertSame('3', (string) $contributivo->getCell(Coordinate::stringFromColumnIndex($quantityColumn + 1).'2')->getValue());
    }

    public function test_it_rejects_a_json_without_rips_users(): void
    {
        $user = User::factory()->create();
        $invalidJson = UploadedFile::fake()->createWithContent(
            'invalido.json',
            json_encode(['sin_usuarios' => []], JSON_THROW_ON_ERROR)
        );
        $validJson = UploadedFile::fake()->createWithContent(
            'contributivo.json',
            $this->jsonWithMedication('200', '2026-08-12')
        );

        $response = $this->from('/json-excel')
            ->actingAs($user)
            ->post(route('json-excel.convert'), [
                'subsidiado_files' => [$invalidJson],
                'contributivo_files' => [$validJson],
                'valor_administrativo' => 0,
            ]);

        $response->assertSessionHasErrors('subsidiado_files.0');
    }

    public function test_it_rejects_a_cuv_response_with_the_file_name(): void
    {
        $this->assertInvalidSubsidiado('FV80459_CUV.json', json_encode(['ResultState' => true, 'CodigoUnicoValidacion' => 'CUV'], JSON_THROW_ON_ERROR));
    }

    public function test_it_rejects_incomplete_and_malformed_json(): void
    {
        $this->assertInvalidSubsidiado('incompleto.json', json_encode(['usuarios' => [['numDocumentoIdentificacion' => '100', 'servicios' => ['medicamentos' => 'no es una lista']]]], JSON_THROW_ON_ERROR));
        $this->assertInvalidSubsidiado('malformado.json', '{"usuarios":[{');
    }

    public function test_it_rejects_an_unhandled_service_instead_of_silently_discarding_it(): void
    {
        $this->assertInvalidSubsidiado('otro-servicio.json', json_encode(['usuarios' => [[
            'numDocumentoIdentificacion' => '100',
            'servicios' => ['prestacionDesconocida' => [['codigo' => 'X']]],
        ]]], JSON_THROW_ON_ERROR));
    }

    public function test_it_reports_a_generation_incident_without_sending_an_excel(): void
    {
        $user = User::factory()->create();
        $logger = $this->mock(ActivityLogger::class);
        $logger->shouldReceive('log')->once()->andThrow(new \RuntimeException('detalle interno de prueba'));

        $response = $this->actingAs($user)->withHeaders([
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ])->post(route('json-excel.convert'), [
            'subsidiado_files' => [UploadedFile::fake()->createWithContent('subsidiado.json', $this->jsonWithMedication('100', '2026-08-10'))],
            'contributivo_files' => [UploadedFile::fake()->createWithContent('contributivo.json', $this->jsonWithMedication('200', '2026-08-12'))],
            'valor_administrativo' => 0,
        ]);

        $response->assertStatus(500)->assertJsonPath('message', $response->json('message'));
        $this->assertStringContainsString('Incidente:', $response->json('message'));
        $this->assertStringNotContainsString('detalle interno de prueba', $response->json('message'));
        $this->assertFalse($response->headers->has('content-disposition'));
    }

    public function test_real_subsidiado_and_contributivo_files_generate_a_complete_excel(): void
    {
        $subsidiadoPath = getenv('SIRUTA_REAL_SUBSIDIADO_JSON');
        $contributivoPath = getenv('SIRUTA_REAL_CONTRIBUTIVO_JSON');

        if (! is_string($subsidiadoPath) || ! is_file($subsidiadoPath)
            || ! is_string($contributivoPath) || ! is_file($contributivoPath)) {
            $this->markTestSkipped('Los RIPS reales se proporcionan solo mediante variables locales de entorno.');
        }

        $catalogSnapshotPath = getenv('AUTOINMUNES_CATALOG_SNAPSHOT');
        $serviceSnapshotPath = getenv('AUTOINMUNES_SERVICE_SNAPSHOT');
        if (is_string($catalogSnapshotPath) && is_file($catalogSnapshotPath)
            && is_string($serviceSnapshotPath) && is_file($serviceSnapshotPath)) {
            $catalog = json_decode(file_get_contents($catalogSnapshotPath), true, 512, JSON_THROW_ON_ERROR);
            $services = json_decode(file_get_contents($serviceSnapshotPath), true, 512, JSON_THROW_ON_ERROR);
            foreach (['codigo_medicamentos' => $catalog['mapping'], 'codigo_medicamentos_nt' => $catalog['nt'],
                'codigo_cups' => $services['cups'], 'codigo_insumos_nt' => $services['insumos']] as $table => $rows) {
                foreach (array_chunk($rows, 200) as $chunk) {
                    DB::table($table)->insert(array_map(static fn (array $row): array => [...$row, 'activo' => true], $chunk));
                }
            }
        }

        $ruleSnapshotPath = getenv('AUTOINMUNES_RULE_SNAPSHOT');
        if (is_string($ruleSnapshotPath) && is_file($ruleSnapshotPath)) {
            $rules = json_decode(file_get_contents($ruleSnapshotPath), true, 512, JSON_THROW_ON_ERROR);
            foreach (array_chunk($rules['classifications'], 200) as $chunk) {
                DB::table('medication_service_classifications')->insert($chunk);
            }
            foreach (array_chunk($rules['adjustments'], 200) as $chunk) {
                DB::table('medication_adjustments')->insert(array_map(
                    static fn (array $row): array => [...$row, 'motivo' => 'COPIA AISLADA', 'activo' => true],
                    $chunk
                ));
            }
        }

        $user = User::factory()->create();
        $response = $this->actingAs($user)->post(route('json-excel.convert'), [
            'subsidiado_files' => [new UploadedFile($subsidiadoPath, basename($subsidiadoPath), 'application/json', null, true)],
            'contributivo_files' => [new UploadedFile($contributivoPath, basename($contributivoPath), 'application/json', null, true)],
            'valor_administrativo' => 0,
        ]);

        $response->assertOk()->assertDownload();
        $path = $response->baseResponse->getFile()->getPathname();

        try {
            $this->assertWorkbookStructure($path, false);
            $zip = new ZipArchive;
            $this->assertSame(true, $zip->open($path));

            try {
                $this->assertSame(38096, substr_count($zip->getFromName('xl/worksheets/sheet1.xml'), '<row '));
                $this->assertSame(1630, substr_count($zip->getFromName('xl/worksheets/sheet2.xml'), '<row '));
            } finally {
                $zip->close();
            }

            $outputPath = getenv('AUTOINMUNES_REAL_OUTPUT');
            if (is_string($outputPath) && $outputPath !== '') {
                $this->assertTrue(copy($path, $outputPath));
            }
        } finally {
            unlink($path);
        }
    }

    private function assertInvalidSubsidiado(string $name, string $content): void
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->withHeaders([
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ])->post(route('json-excel.convert'), [
            'subsidiado_files' => [UploadedFile::fake()->createWithContent($name, $content)],
            'contributivo_files' => [UploadedFile::fake()->createWithContent('contributivo.json', $this->jsonWithMedication('200', '2026-08-12'))],
            'valor_administrativo' => 0,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('subsidiado_files.0');
        $this->assertStringContainsString($name, $response->json('errors')['subsidiado_files.0'][0]);
    }

    private function assertWorkbookStructure(string $path, bool $assertMedicationFixture = true): void
    {
        $zip = new ZipArchive;
        $this->assertSame(true, $zip->open($path));

        try {
            $workbook = $zip->getFromName('xl/workbook.xml');
            $this->assertIsString($workbook);

            foreach (['SUBSIDIADA', 'CONTRIBUTIVO', 'AC-AP', 'AM', 'OTROS', 'RESUMEN'] as $sheet) {
                $this->assertStringContainsString('name="'.$sheet.'"', $workbook);
            }

            if ($assertMedicationFixture) {
                $this->assertStringContainsString('MED-001', $zip->getFromName('xl/worksheets/sheet1.xml'));
                $this->assertStringContainsString('MED-001', $zip->getFromName('xl/worksheets/sheet2.xml'));
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * @return array<string, mixed> valores de la fila indexados por el encabezado de la hoja
     */
    private function valoresPorEncabezado(Worksheet $sheet, int $fila): array
    {
        $valores = [];
        $columnas = Coordinate::columnIndexFromString($sheet->getHighestColumn());

        for ($columna = 1; $columna <= $columnas; $columna++) {
            $valores[(string) $sheet->getCell([$columna, 1])->getValue()] = $sheet->getCell([$columna, $fila])->getValue();
        }

        return $valores;
    }

    /**
     * @return array<string, mixed>
     */
    private function filaPorCodigo(Worksheet $sheet, string $codigo): array
    {
        for ($fila = 2; $fila <= $sheet->getHighestRow(); $fila++) {
            $valores = $this->valoresPorEncabezado($sheet, $fila);

            if ((string) ($valores['Codigo'] ?? '') === $codigo) {
                return $valores;
            }
        }

        $this->fail("La hoja {$sheet->getTitle()} no tiene una fila con el código {$codigo}.");
    }

    private function jsonWithMedication(string $documento, string $fecha, int $cantidad = 1): string
    {
        return json_encode([
            'usuarios' => [[
                'numDocumentoIdentificacion' => $documento,
                'tipoDocumentoIdentificacion' => 'CC',
                'servicios' => [
                    'medicamentos' => [[
                        'fechaDispensAdmon' => $fecha,
                        'codTecnologiaSalud' => 'MED-001',
                        'nomTecnologiaSalud' => 'Medicamento de prueba',
                        'cantidadMedicamento' => $cantidad,
                    ]],
                ],
            ]],
        ], JSON_THROW_ON_ERROR);
    }
}
