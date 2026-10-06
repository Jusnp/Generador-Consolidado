<?php

namespace Tests\Feature;

use App\Models\CatalogoReferencia;
use App\Models\CatalogoReferenciaItem;
use App\Models\ProgramaEjecucion;
use App\Models\User;
use App\Programas\LaMaria\LaMariaPrograma;
use App\Programas\LaMaria\LaMariaProstataPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ProgramaEjecucionTraceabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_seguimiento_export_stores_execution_with_catalog_versions(): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();

        $response = $this->actingAs($user)->post(route('la-maria.prostata.export'), [
            'archivos' => [
                UploadedFile::fake()->createWithContent(
                    'junio.json',
                    $this->routeJson('FAC-061', 'C61X', '2026-06-10', 'consultas')
                ),
            ],
        ]);

        $response->assertDownload();

        $ejecucion = ProgramaEjecucion::query()->first();

        $this->assertNotNull($ejecucion);
        $this->assertSame(LaMariaProstataPipeline::SLUG, $ejecucion->programa_slug);
        $this->assertSame($user->id, $ejecucion->user_id);
        $this->assertSame(now()->format('Y-m'), $ejecucion->periodo);
        $this->assertSame(1, $ejecucion->archivos_procesados);
        $this->assertNotEmpty($ejecucion->catalogos_aplicados);
        $this->assertSame(
            LaMariaPrograma::SLUG,
            $ejecucion->catalogos_aplicados[0]['programa_slug']
        );
        $this->assertContains(
            'ANEXOS_2706',
            array_column($ejecucion->catalogos_aplicados, 'tipo')
        );
    }

    private function routeJson(
        string $factura,
        string $diagnostico,
        string $fechaAtencion,
        string $tipoServicio
    ): string {
        $servicio = [
            'codConsulta' => '890201',
            'codDiagnosticoPrincipal' => $diagnostico,
            'fechaInicioAtencion' => $fechaAtencion,
        ];

        return json_encode([
            'numFactura' => $factura,
            'usuarios' => [[
                'tipoDocumentoIdentificacion' => 'CC',
                'numDocumentoIdentificacion' => '100',
                'servicios' => [$tipoServicio => [$servicio]],
            ]],
        ], JSON_THROW_ON_ERROR);
    }

    private function createReferenceCatalogs(): void
    {
        $anexos = CatalogoReferencia::create([
            'programa_slug' => LaMariaPrograma::SLUG,
            'tipo' => 'ANEXOS_2706',
            'version' => '2026-01',
            'archivo_origen' => 'anexos.xlsx',
            'activo' => true,
        ]);
        CatalogoReferenciaItem::create([
            'catalogo_referencia_id' => $anexos->id,
            'codigo' => '890201',
            'categoria' => 'ANEXO_2',
            'descripcion' => 'Consulta oficial de prueba',
        ]);

        $prostata = CatalogoReferencia::create([
            'programa_slug' => LaMariaPrograma::SLUG,
            'tipo' => 'NOTA_PROSTATA',
            'version' => '2026-01',
            'archivo_origen' => 'prostata.xlsx',
            'activo' => true,
        ]);
        CatalogoReferenciaItem::create([
            'catalogo_referencia_id' => $prostata->id,
            'codigo' => '890201',
            'ruta' => 'PROSTATA',
            'categoria' => 'SERVICIO_TECNICO',
            'descripcion' => 'Consulta de referencia próstata',
            'tarifa_referencia' => 5000,
        ]);
    }
}
