<?php

namespace Tests\Feature;

use App\Http\Controllers\SeguimientoRutaController;
use App\Programas\LaMaria\HistorialAdministrativo;
use App\Programas\LaMaria\ValorizacionAdministrativa;
use App\Services\SeguimientoRutaClassifier;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use ReflectionMethod;
use Tests\TestCase;

class LaMariaPuenteEconomicoTest extends TestCase
{
    public function test_isolated_sixteen_file_copies_preserve_all_services_and_september_routes(): void
    {
        $folder = getenv('SIRUTA_AUDITORIA_COPIAS');
        if (! $folder || ! is_dir($folder)) {
            $this->markTestSkipped('Requiere copias locales de los dieciséis JSON; no usa base de datos.');
        }
        $files = array_map(static fn (string $path): array => ['path' => $path, 'fileName' => basename($path)], glob($folder.'/*.json'));
        $classifier = new SeguimientoRutaClassifier;

        $processed = $classifier->processableFiles($classifier->screenFiles($files));
        $profiles = $classifier->profilesForFiles($processed);

        $this->assertCount(16, $processed);
        $this->assertSame(62591, array_sum(array_column($profiles, 'serviceCount')));
        $september = collect($profiles)->where('mesAtencion', '2026-09');
        $this->assertSame(11360, $september->where('ruta', 'PROSTATA')->sum('serviceCount'));
        $this->assertSame(6954, $september->where('ruta', 'CERVIX')->sum('serviceCount'));
        $this->assertSame(1034, $september->where('ruta', 'PROSTATA')->count());
        $this->assertSame(442, $september->where('ruta', 'CERVIX')->count());
        $periods = array_values(array_unique(array_column($profiles, 'mesAtencion')));
        sort($periods);
        $this->assertSame(['2026-06', '2026-07', '2026-08', '2026-09'], $periods);
    }

    public function test_exported_bridge_reconstructs_active_cost_without_adding_unknown_values(): void
    {
        $historial = new HistorialAdministrativo;
        $historial->registrar('PROSTATA', '2026-08', 'AP', '911107', 50.0);
        $valoracion = new ValorizacionAdministrativa('PROSTATA', $historial, [], static fn (string $cum): array => []);
        $valoracion->registrar($this->detalle('consultas', '890294'), ['respaldado' => 100.0]);
        $valoracion->registrar($this->detalle('procedimientos', '911107'), ['respaldado' => 0.0, 'condicionadoIncluido' => 20.0]);
        $valoracion->registrar($this->detalle('otrosServicios', 'SIN-PRECIO'), ['respaldado' => 0.0]);
        $valoracion->atribuirPaquetes([['2026-09', 1, 6015314.53]]);
        $path = tempnam(sys_get_temp_dir(), 'puente-');
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('PUENTE ECONOMICO');
        $costo = array_fill(0, 10, 0);
        $costo[1] = '2026-09';
        $costo[9] = 6015434.53;

        (new ReflectionMethod(SeguimientoRutaController::class, 'writeEconomicBridge'))->invoke(
            new SeguimientoRutaController, $writer, ['PROSTATA' => $valoracion], ['PROSTATA' => ['costos' => [$costo]]]
        );
        $writer->close();
        $reader = new Reader;
        $reader->open($path);
        $rows = [];
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $rows[] = $row->toArray();
                }
            }
        } finally {
            $reader->close();
            unlink($path);
        }

        $this->assertSame(['RUTA', ...ValorizacionAdministrativa::PUENTE_HEADERS], $rows[0]);
        $byMovement = collect(array_slice($rows, 1))->keyBy(2);
        $this->assertSame(6015434.53, (float) $byMovement['SALDO INICIAL: COSTO ACTIVO'][9]);
        $this->assertSame(6015464.53, (float) $byMovement['TOTAL MENSUAL RECONSTRUIDO'][9]);
        $this->assertSame('RECONCILIADO', $byMovement['CONTROL BASE ACTIVA'][5]);
        $this->assertSame(0.0, (float) $byMovement['CONTROL CONTRA TOTAL MOSTRADO'][9]);
        $pending = collect($rows)->first(static fn (array $row): bool => ($row[4] ?? '') === 'SIN-PRECIO');
        $this->assertSame('NO CUANTIFICADO', $pending[10]);
        $this->assertSame(0.0, (float) $pending[8]);
        $this->assertSame(30.0, round(array_sum(array_column(array_slice($rows, 1), 8)), 2));
    }

    public function test_bridge_exposes_an_unexplained_active_balance_without_balancing_it(): void
    {
        $valoracion = new ValorizacionAdministrativa('PROSTATA', new HistorialAdministrativo, [], static fn (string $cum): array => []);
        $valoracion->registrar($this->detalle('consultas', '890294'), ['respaldado' => 100.0]);

        $rows = collect($valoracion->filasPuente(['2026-09' => 120.0]))->keyBy(1);

        $this->assertSame('DIFERENCIA SIN EXPLICAR', $rows['CONTROL BASE ACTIVA'][4]);
        $this->assertSame(20.0, $rows['CONTROL CONTRA TOTAL MOSTRADO'][8]);
        $this->assertSame(0.0, $rows['CONTROL CONTRA TOTAL MOSTRADO'][7]);
    }

    public function test_bridge_exposes_applied_scheme_prices_without_adding_them_twice(): void
    {
        $historial = new HistorialAdministrativo;
        foreach (range(1, 10) as $index) {
            $historial->registrar('CERVIX', '2026-08', 'AP', '992511', 2000000.0, '', 'CARBOPLATINO');
            $historial->registrar('CERVIX', '2026-08', 'AP', '992511', 32000000.0, '', 'PEMBROLIZUMAB');
        }
        $nota = [['codigo' => '992511', 'categoria' => 'ONCOLOGIA_QUIMIOTERAPIA', 'tarifaReferencia' => 444690.0,
            'metadatos' => ['tarifa_medicamento' => 1431182.14, 'esquema' => 'Carboplatino Pembrolizumab']]];
        $valoracion = new ValorizacionAdministrativa('CERVIX', $historial, $nota, static fn (string $cum): array => []);
        $caso = ['casoId' => 'P1', 'factura' => 'F1', 'fechaAtencion' => '2026-09-03 08:00'];
        $valoracion->registrar([...$this->detalle('procedimientos', '992511'), ...$caso], ['respaldado' => 444690.0]);
        $valoracion->registrar([...$this->detalle('medicamentos', 'PEMBRO'), ...$caso,
            'principioActivo' => 'PEMBROLIZUMAB', 'cumResuelto' => 'CUM-PEMBRO'], ['respaldado' => 0.0]);

        $rows = collect($valoracion->filasPuente(['2026-09' => 444690.0]));
        $scheme = $rows->firstWhere(1, 'DETALLE DE TARIFA DE ESQUEMA');

        $this->assertStringContainsString('Precio por registro aplicado: 32000000.0000', $scheme[11]);
        $this->assertSame(32000000.0, $scheme[9]);
        $this->assertSame(0.0, $scheme[7]);
        $this->assertSame(32000000.0, $rows->firstWhere(1, 'TOTAL MENSUAL RECONSTRUIDO')[8]);
    }

    public function test_missing_note_observation_distinguishes_absorption_from_pending_presentation(): void
    {
        $method = new ReflectionMethod(SeguimientoRutaController::class, 'notFoundValues');
        $base = ['factura' => 'F1', 'usuarioJson' => [], 'servicioJson' => [], 'tipoServicio' => 'medicamentos',
            'estadoTarifario' => 'REVISAR_PRESENTACION', 'fuenteTarifa' => '', 'motivoNoSuma' => 'REVISAR_PRESENTACION'];
        $pendiente = $method->invoke(new SeguimientoRutaController, $base);
        $incluido = $method->invoke(new SeguimientoRutaController, [...$base, 'decisionPaquete' => 'INCLUIDO_EN_PAQUETE', 'motivoNoSuma' => 'INCLUIDO_EN_PAQUETE']);

        $this->assertStringContainsString('REVISAR_PRESENTACION', $pendiente[10]);
        $this->assertStringContainsString('PAQUETE: INCLUIDO_EN_PAQUETE', $incluido[10]);
        $this->assertStringContainsString('NO SUMA AL COSTO ACTIVO: INCLUIDO_EN_PAQUETE', $incluido[10]);
    }

    /** @return array<string, mixed> */
    private function detalle(string $tipo, string $codigo): array
    {
        return ['mesAtencion' => '2026-09', 'tipoServicio' => $tipo, 'codigo' => $codigo, 'cantidadAplicada' => 1.0,
            'descripcionOficial' => $codigo, 'casoId' => 'P0', 'motivoNoSuma' => '', 'decisionPaquete' => '',
            'fuenteTarifa' => 'NOTA_TECNICA', 'tarifaReferencia' => 0];
    }
}
