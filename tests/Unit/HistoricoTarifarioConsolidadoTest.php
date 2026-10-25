<?php

namespace Tests\Unit;

use App\Http\Controllers\SeguimientoRutaController;
use App\Programas\LaMaria\HistorialAdministrativo;
use App\Programas\LaMaria\HistoricoTarifarioConsolidado;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class HistoricoTarifarioConsolidadoTest extends TestCase
{
    use RefreshDatabase;

    public function test_consolidated_reference_preserves_price_classes_and_units_without_patient_identifiers(): void
    {
        $history = new HistorialAdministrativo;

        foreach (['2026-06', '2026-07', '2026-08'] as $month) {
            $history->registrar('CERVIX', $month, 'AP', '992511', 1875872.14, 'Monoterapia', 'Cisplatino', $month.'-03', 'PACIENTE-A');
            $history->registrar('CERVIX', $month, 'AP', '992511', 26194690.0, 'Monoterapia', 'Pembrolizumab', $month.'-07', 'PACIENTE-B');
        }

        $consolidated = $history->exportarConsolidado();
        $restored = HistorialAdministrativo::desdeConsolidado($consolidated);

        $this->assertSame($history->precio('CERVIX', '2026-09', 'AP', '992511'), $restored->precio('CERVIX', '2026-09', 'AP', '992511'));
        $this->assertSame($history->clasesPrecio('CERVIX', '2026-09', 'AP', '992511'), $restored->clasesPrecio('CERVIX', '2026-09', 'AP', '992511'));
        $this->assertSame($history->unidadPorPaciente('CERVIX', '2026-09', 'AP', '992511'), $restored->unidadPorPaciente('CERVIX', '2026-09', 'AP', '992511'));
        $this->assertSame($consolidated['items'][0]['unidades'], $restored->exportarConsolidado()['items'][0]['unidades']);
        $this->assertFalse($restored->cubreMes('CERVIX', '2026-08'));
        $this->assertStringNotContainsString('PACIENTE-A', json_encode($consolidated));
        $this->assertStringNotContainsString('PACIENTE-B', json_encode($consolidated));
    }

    public function test_active_version_supplies_prices_without_administrative_workbook(): void
    {
        $history = new HistorialAdministrativo;

        foreach (['2026-06', '2026-07', '2026-08'] as $month) {
            $history->registrar('PROSTATA', $month, 'AP', '992505', 7965668.98, 'Politerapia', 'Apalutamida');
            $history->registrar('CERVIX', $month, 'AP', '992511', 1875872.14, 'Monoterapia', 'Cisplatino');
        }

        $consolidated = $history->exportarConsolidado();
        $consolidated['fuentes'] = [[
            'archivo' => 'histórico.xlsx', 'ruta' => 'PROSTATA/CERVIX', 'filas' => 6,
            'meses' => '2026-06 a 2026-08', 'hoja' => 'inf admin', 'advertencias' => [],
        ]];
        $catalogue = HistoricoTarifarioConsolidado::guardarInactivo($consolidated, 'prueba-2026-08');
        $this->assertFalse($catalogue->activo);
        $this->assertNull(HistoricoTarifarioConsolidado::activo());

        $catalogue->update(['activo' => true]);
        config(['programas.la-maria.informes_administrativos' => ['requerido' => true, 'PROSTATA' => '', 'CERVIX' => '']]);
        $method = new ReflectionMethod(SeguimientoRutaController::class, 'administrativeHistory');
        $restored = $method->invoke(new SeguimientoRutaController, [], ['PROSTATA', 'CERVIX']);

        $this->assertSame(7965668.98, $restored->precio('PROSTATA', '2026-09', 'AP', '992505')['mediana']);
        $this->assertSame(1875872.14, $restored->precio('CERVIX', '2026-09', 'AP', '992511')['mediana']);
        $this->assertNull($restored->precio('PROSTATA', '2026-09', 'AP', '992511'));
        $this->assertSame('PENDIENTE', $catalogue->items()->where('codigo', '992505')->first()->metadatos['estado_contractual']);
    }
}
