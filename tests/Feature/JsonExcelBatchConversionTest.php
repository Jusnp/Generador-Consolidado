<?php

namespace Tests\Feature;

use App\Models\CodigoMedicamento;
use App\Models\CodigoMedicamentoNt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

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
            $this->jsonWithMedication('100', '2026-08-11')
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

        $response->assertSessionHasErrors('subsidiado_files');
    }

    private function jsonWithMedication(string $documento, string $fecha): string
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
                        'cantidadMedicamento' => 1,
                    ]],
                ],
            ]],
        ], JSON_THROW_ON_ERROR);
    }
}
