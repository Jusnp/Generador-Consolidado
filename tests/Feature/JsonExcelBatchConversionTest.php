<?php

namespace Tests\Feature;

use App\Models\CodigoMedicamento;
use App\Models\CodigoMedicamentoNt;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;
use ZipArchive;

class JsonExcelBatchConversionTest extends TestCase
{
    use RefreshDatabase;

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
