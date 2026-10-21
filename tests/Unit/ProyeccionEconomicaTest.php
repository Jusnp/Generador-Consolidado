<?php

namespace Tests\Unit;

use App\Programas\LaMaria\ProyeccionEconomica;
use PHPUnit\Framework\TestCase;

class ProyeccionEconomicaTest extends TestCase
{
    public function test_projects_three_labelled_scenarios_without_changing_real_history(): void
    {
        $historico = [10, 20, 30, 40];
        $original = $historico;
        $forecast = (new ProyeccionEconomica)->proyectar($historico, 100, 1);

        $this->assertSame($original, $historico);
        $this->assertSame(3300.0, $forecast['central']);
        $this->assertSame(2310.0, $forecast['conservador']);
        $this->assertSame(4290.0, $forecast['alto']);
        $this->assertSame(0.1, $forecast['tendencia']);
        $this->assertSame(0.3, $forecast['variabilidad']);
    }

    public function test_rejects_missing_history_or_negative_activity(): void
    {
        $calculator = new ProyeccionEconomica;

        try {
            $calculator->proyectar([1, 2, 3], 10, 1);
            $this->fail('La serie incompleta se aceptó.');
        } catch (\InvalidArgumentException) {
            $this->assertTrue(true);
        }

        $this->expectException(\InvalidArgumentException::class);
        $calculator->proyectar([1, -2, 3, 4], 10, 1);
    }

    public function test_backtest_uses_only_three_prior_months_and_separates_units_from_cost(): void
    {
        $calculator = new ProyeccionEconomica;
        $history = [10, 20, 30];

        $weighted = $calculator->proyectarServicio($history, 100, 1, 'ponderado');
        $median = $calculator->proyectarServicio($history, 100, 1, 'mediana');

        $this->assertSame([10, 20, 30], $history);
        $this->assertSame(25.3, $weighted['unidades_central']);
        $this->assertSame(2530.0, $weighted['central']);
        $this->assertSame(22.0, $median['unidades_central']);
        $this->assertSame(2200.0, $median['central']);
    }

    public function test_sparse_activity_is_averaged_without_extrapolating_a_single_spike(): void
    {
        $forecast = (new ProyeccionEconomica)->proyectarServicio([0, 0, 0, 12], 50, 2, 'ponderado');

        $this->assertTrue($forecast['historial_escaso']);
        $this->assertSame(0.0, $forecast['tendencia']);
        $this->assertSame(3.0, $forecast['unidades_central']);
        $this->assertSame(150.0, $forecast['central']);
    }

    public function test_unpriced_service_keeps_projected_activity_without_inventing_money(): void
    {
        $forecast = (new ProyeccionEconomica)->proyectarServicio([4, 5, 6, 7], 0, 1, 'mediana');

        $this->assertGreaterThan(0, $forecast['unidades_central']);
        $this->assertSame(0.0, $forecast['central']);
    }

    public function test_two_month_history_is_validated_without_extrapolating_a_trend(): void
    {
        $forecast = (new ProyeccionEconomica)->proyectarServicio([10, 20], 100, 1, 'ponderado');

        $this->assertSame(15.0, $forecast['unidades_central']);
        $this->assertSame(1500.0, $forecast['central']);
        $this->assertSame(0.0, $forecast['tendencia']);
    }

    public function test_two_consecutive_months_without_activity_prevent_a_dormant_code_from_reappearing(): void
    {
        $calculator = new ProyeccionEconomica;
        $adjusted = $calculator->proyectarServicio([9, 0, 0], 3500000, 1, 'ponderado');
        $previous = $calculator->proyectarServicio([9, 0, 0], 3500000, 1, 'ponderado', false);

        $this->assertTrue($adjusted['inactividad_confirmada']);
        $this->assertSame(0.0, $adjusted['unidades_central']);
        $this->assertSame(0.0, $adjusted['central']);
        $this->assertSame(10500000.0, $previous['central']);
    }

    public function test_one_month_without_activity_does_not_erase_a_service(): void
    {
        $forecast = (new ProyeccionEconomica)->proyectarServicio([0, 2, 0], 284000.64, 1, 'ponderado');

        $this->assertFalse($forecast['inactividad_confirmada']);
        $this->assertGreaterThan(0, $forecast['central']);
    }
}
