<?php

namespace Tests\Unit;

use App\Programas\LaMaria\DecisionPaqueteHospitalario;
use App\Programas\LaMaria\PoliticaPaqueteHospitalario;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PoliticaPaqueteHospitalarioTest extends TestCase
{
    /**
     * Hoja "Hx": menos de 24 horas es urgencia; un paquete por cada bloque de
     * hasta 600 horas (25 días) de duración real; 25 días y un minuto ya
     * superan el máximo y generan el segundo.
     *
     * @return array<string, array{string, string, int}>
     */
    public static function stays(): array
    {
        return [
            '2 horas (23:00 a 01:00)' => ['2026-10-01 23:00', '2026-10-02 01:00', 0],
            '23h59m' => ['2026-10-01 08:00', '2026-10-02 07:59', 0],
            '24 h exactas' => ['2026-10-01 08:00', '2026-10-02 08:00', 1],
            '24h01m' => ['2026-10-01 08:00', '2026-10-02 08:01', 1],
            '10 dias' => ['2026-10-01 08:00', '2026-10-11 08:00', 1],
            '25 dias exactos' => ['2026-10-01 08:00', '2026-10-26 08:00', 1],
            '25 dias y 1 minuto' => ['2026-10-01 08:00', '2026-10-26 08:01', 2],
            '50 dias exactos' => ['2026-10-01 08:00', '2026-11-20 08:00', 2],
            '50 dias y 1 minuto' => ['2026-10-01 08:00', '2026-11-20 08:01', 3],
            '51 dias' => ['2026-10-01 08:00', '2026-11-21 08:00', 3],
            'cruza dos medianoches en menos de 48 h' => ['2026-10-01 23:00', '2026-10-03 01:00', 1],
        ];
    }

    #[DataProvider('stays')]
    public function test_packages_follow_the_real_duration_of_the_stay(string $ingreso, string $egreso, int $paquetes): void
    {
        $this->assertSame($paquetes, (new PoliticaPaqueteHospitalario)->paquetesPorEstancia($ingreso, $egreso));
    }

    public function test_package_value_is_the_weighted_hospitalization_cost_of_the_note(): void
    {
        $this->assertSame(6015314.53, PoliticaPaqueteHospitalario::VALOR_PAQUETE);
    }

    public function test_stay_without_valid_dates_does_not_generate_packages(): void
    {
        $politica = new PoliticaPaqueteHospitalario;

        $this->assertNull($politica->paquetesPorEstancia('2026-10-10', '2026-10-01'));
        $this->assertNull($politica->paquetesPorEstancia('sin fecha', '2026-10-01'));
    }

    /**
     * @return array<string, array{string, string, string, string, DecisionPaqueteHospitalario}>
     */
    public static function hospitalServices(): array
    {
        return [
            'H7 medicamento comun' => ['medicamentos', '19935303-4', 'PARACETAMOL', 'N02BE01', DecisionPaqueteHospitalario::IncluidoEnPaquete],
            'H9 laboratorio' => ['procedimientos', '902210', '', '', DecisionPaqueteHospitalario::IncluidoEnPaquete],
            'imagen ordinaria' => ['procedimientos', '879420', '', '', DecisionPaqueteHospitalario::IncluidoEnPaquete],
            'H10 dispositivo' => ['otrosServicios', 'DM00114', '', '', DecisionPaqueteHospitalario::IncluidoEnPaquete],
            'estancia' => ['otrosServicios', '129M02', '', '', DecisionPaqueteHospitalario::IncluidoEnPaquete],
            'H11 cirugia' => ['procedimientos', '550202', '', '', DecisionPaqueteHospitalario::CobrablePorFuera],
            'H12 quimioterapia' => ['procedimientos', '992505', '', '', DecisionPaqueteHospitalario::CobrablePorFuera],
            'H13 resonancia' => ['procedimientos', '883440', 'RESONANCIA MAGNETICA DE PELVIS', '', DecisionPaqueteHospitalario::CobrablePorFuera],
            'H14 gammagrafia' => ['procedimientos', '920901', '', '', DecisionPaqueteHospitalario::CobrablePorFuera],
            'H15 teleterapia' => ['procedimientos', '922444', '', '', DecisionPaqueteHospitalario::CobrablePorFuera],
            'braquiterapia' => ['procedimientos', '922615', '', '', DecisionPaqueteHospitalario::CobrablePorFuera],
            'H16 PET-TC' => ['procedimientos', '921401', '', '', DecisionPaqueteHospitalario::ReglaNoDefinida],
            'H17 transfusion' => ['procedimientos', '911107', '', '', DecisionPaqueteHospitalario::ReglaNoDefinida],
            'H18 leuprorelina' => ['medicamentos', '20032905-1', 'LEUPRORELINA', 'L02AE02', DecisionPaqueteHospitalario::ReglaNoDefinida],
            'antineoplasico' => ['medicamentos', 'X', 'DOCETAXEL', 'L01CD02', DecisionPaqueteHospitalario::ReglaNoDefinida],
            'radioisotopos' => ['procedimientos', '922804', '', '', DecisionPaqueteHospitalario::ReglaNoDefinida],
            'procedimiento fuera de la nota' => ['procedimientos', '873501', 'FLUOROSCOPIA', '', DecisionPaqueteHospitalario::ReglaNoDefinida],
        ];
    }

    #[DataProvider('hospitalServices')]
    public function test_classifies_services_during_a_hospitalization(
        string $tipoServicio,
        string $codigo,
        string $descripcion,
        string $atc,
        DecisionPaqueteHospitalario $decision
    ): void {
        $this->assertSame($decision, (new PoliticaPaqueteHospitalario)->decidir($tipoServicio, $codigo, $descripcion, $atc));
    }

    /**
     * Códigos clasificados como Imágenes Diagnósticas en la nota oficial de
     * Próstata y como QUIRÚRGICO en el agrupador de la nota de Cérvix.
     *
     * @return array<string, array{string}>
     */
    public static function conflictingCodes(): array
    {
        $codigos = ['601101', '601102', '441302', '332601', '053114', '877812'];

        return array_combine($codigos, array_map(static fn (string $codigo): array => [$codigo], $codigos));
    }

    #[DataProvider('conflictingCodes')]
    public function test_each_route_keeps_its_own_classification_for_conflicting_codes(string $codigo): void
    {
        $politica = new PoliticaPaqueteHospitalario;

        $prostata = $politica->clasificar('procedimientos', $codigo);
        $cervix = $politica->clasificar('procedimientos', $codigo, agrupador: 'QUIRÚRGICO');

        $this->assertSame(DecisionPaqueteHospitalario::IncluidoEnPaquete, $prostata['decision']);
        $this->assertSame(PoliticaPaqueteHospitalario::FUENTE_FALLBACK, $prostata['fuente']);
        $this->assertSame(DecisionPaqueteHospitalario::CobrablePorFuera, $cervix['decision']);
        $this->assertSame(PoliticaPaqueteHospitalario::FUENTE_AGRUPADOR, $cervix['fuente']);
    }

    public function test_note_service_takes_precedence_over_grouper_and_fallback(): void
    {
        $politica = new PoliticaPaqueteHospitalario;

        $porServicio = $politica->clasificar('procedimientos', '601101', servicio: 'Imágenes Diagnósticas', agrupador: 'QUIRÚRGICO');
        $porAgrupador = $politica->clasificar('procedimientos', '902210', agrupador: 'LABORATORIO CLÍNICO');
        $porFallback = $politica->clasificar('procedimientos', '902210');

        $this->assertSame([DecisionPaqueteHospitalario::IncluidoEnPaquete, PoliticaPaqueteHospitalario::FUENTE_SERVICIO], [$porServicio['decision'], $porServicio['fuente']]);
        $this->assertSame([DecisionPaqueteHospitalario::IncluidoEnPaquete, PoliticaPaqueteHospitalario::FUENTE_AGRUPADOR], [$porAgrupador['decision'], $porAgrupador['fuente']]);
        $this->assertSame([DecisionPaqueteHospitalario::IncluidoEnPaquete, PoliticaPaqueteHospitalario::FUENTE_FALLBACK], [$porFallback['decision'], $porFallback['fuente']]);
    }

    public function test_code_rules_take_precedence_over_note_groupers(): void
    {
        $transfusion = (new PoliticaPaqueteHospitalario)->clasificar('procedimientos', '911107', agrupador: 'LABORATORIO CLÍNICO');

        $this->assertSame(DecisionPaqueteHospitalario::ReglaNoDefinida, $transfusion['decision']);
        $this->assertSame(PoliticaPaqueteHospitalario::FUENTE_CODIGO, $transfusion['fuente']);
    }

    public function test_hospitalization_record_itself_has_no_package_decision(): void
    {
        $this->assertNull((new PoliticaPaqueteHospitalario)->decidir('hospitalizacion', ''));
    }

    public function test_contract_exceptions_take_precedence_over_the_general_classification(): void
    {
        $politica = new PoliticaPaqueteHospitalario(
            ['procedimientos' => ['550202']],
            ['medicamentos' => ['19935303-4']],
        );

        $this->assertSame(DecisionPaqueteHospitalario::IncluidoEnPaquete, $politica->decidir('procedimientos', '550202'));
        $this->assertSame(DecisionPaqueteHospitalario::CobrablePorFuera, $politica->decidir('medicamentos', '19935303-4'));
        $this->assertSame(DecisionPaqueteHospitalario::CobrablePorFuera, $politica->decidir('procedimientos', '992505'));
    }

    public function test_a_code_cannot_be_included_and_billable_outside_at_the_same_time(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PoliticaPaqueteHospitalario(
            ['medicamentos' => ['OMEPRAZOL']],
            ['medicamentos' => ['OMEPRAZOL']],
        );
    }
}
