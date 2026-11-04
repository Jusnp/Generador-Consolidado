<?php

namespace Tests\Unit;

use App\Programas\LaMaria\EscenarioHospitalarioAdministrativo;
use PHPUnit\Framework\TestCase;

class EscenarioHospitalarioAdministrativoTest extends TestCase
{
    public function test_short_stay_and_twenty_five_day_crossing_are_separate_units(): void
    {
        $base = ['estado' => 'CERRADO', 'excluido' => false, 'continuidadesResueltas' => 0];
        $episodios = [[...$base, 'id' => 'A', 'inicio' => '2026-09-02 10:00', 'fin' => '2026-09-02 20:00'],
            [...$base, 'id' => 'B', 'inicio' => '2026-08-20 10:00', 'fin' => '2026-09-16 10:00']];
        $resultado = (new EscenarioHospitalarioAdministrativo)->calcular($episodios, 601531453);
        $this->assertSame(['EGRESO MENOR 24H', 'CRUCE 25 DIAS', 'EGRESO'], array_column($resultado['unidades'], 'tipo'));
        $this->assertSame(['2026-09-02', '2026-09-14', '2026-09-16'], array_column($resultado['unidades'], 'fecha'));
        $this->assertCount(0, $resultado['pendientes']);
    }

    public function test_unverified_continuation_does_not_create_a_package(): void
    {
        $episodio = ['id' => 'A', 'estado' => 'CERRADO', 'inicio' => '2026-09-01 00:00', 'fin' => '2026-09-05 10:00', 'continuidadesResueltas' => 0];
        $resultado = (new EscenarioHospitalarioAdministrativo)->calcular([$episodio], 601531453);
        $this->assertCount(0, $resultado['unidades']);
        $this->assertCount(1, $resultado['pendientes']);
    }

    public function test_a_stay_ending_at_the_25_day_boundary_is_only_one_unit(): void
    {
        $episodio = ['id' => 'A', 'estado' => 'CERRADO', 'inicio' => '2026-08-20 10:00', 'fin' => '2026-09-14 10:00', 'continuidadesResueltas' => 1];
        $resultado = (new EscenarioHospitalarioAdministrativo)->calcular([$episodio], 100);
        $this->assertCount(1, $resultado['unidades']);
        $this->assertSame('2026-09-14', $resultado['unidades'][0]['fecha']);
    }
}
