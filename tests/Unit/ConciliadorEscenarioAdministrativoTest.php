<?php

namespace Tests\Unit;

use App\Programas\LaMaria\ConciliadorEscenarioAdministrativo;
use PHPUnit\Framework\TestCase;

class ConciliadorEscenarioAdministrativoTest extends TestCase
{
    public function test_consolida_los_componentes_sin_sumar_pendientes_ni_paquetes(): void
    {
        $componentes = [
            ['id' => 'AC-1', 'tipo' => 'AC', 'codigo' => '890201', 'centavos' => 10000, 'estado' => 'ADITIVO'],
            ['id' => 'AP-1', 'tipo' => 'AP', 'codigo' => '992511', 'centavos' => 50000, 'estado' => 'CONDICIONADO'],
            ['id' => 'AM-1', 'tipo' => 'AM', 'codigo' => 'MED', 'centavos' => 30000, 'estado' => 'NO_ADITIVO'],
            ['id' => 'AH-1', 'tipo' => 'AH', 'codigo' => 'PAQUETE', 'centavos' => null, 'estado' => 'PENDIENTE'],
        ];

        $resultado = (new ConciliadorEscenarioAdministrativo)->consolidarComponentes($componentes, ['AC' => 12000, 'AP' => 55000]);

        $this->assertSame(60000, $resultado['total_calculado']);
        $this->assertSame(50000, $resultado['total_condicionado_incluido']);
        $this->assertSame(2000, $resultado['categorias']['AC']['diferencia_bruta']);
        $this->assertSame(30000, $resultado['categorias']['AM']['no_aditivo']);
        $this->assertSame(1, $resultado['categorias']['AH']['pendientes']);
        $this->assertNull($resultado['categorias']['AH']['calculado_mostrable']);
        $this->assertNull($resultado['categorias']['AH']['diferencia_bruta']);
    }

    public function test_rechaza_componentes_con_identidad_repetida(): void
    {
        $componente = ['id' => 'AP-1', 'tipo' => 'AP', 'codigo' => '992511', 'centavos' => 100, 'estado' => 'ADITIVO'];
        $this->expectException(\LogicException::class);
        (new ConciliadorEscenarioAdministrativo)->consolidarComponentes([$componente, $componente], []);
    }

    public function test_el_integral_sustituye_la_aplicacion_y_la_candidata_no_se_confirma(): void
    {
        $base = ['tipo' => 'AP', 'ruta' => 'PROSTATA', 'codigo' => '992511', 'detalle_tecnico' => ['cantidad' => 1, 'tarifa' => 444690]];
        $resultado = (new ConciliadorEscenarioAdministrativo)->escenarioQuimioterapia([
            [...$base, 'indice_rips' => 1, 'estado' => 'REFERENCIA PROVISIONAL NO CONFIRMADA', 'centavos' => 732882188, 'fuente' => 'hoja!fila 10'],
            [...$base, 'indice_rips' => 2, 'estado' => 'NO DETERMINADO', 'centavos' => null, 'fuente' => ''],
        ]);

        $this->assertSame(88938000, $resultado['aplicacion_centavos']);
        $this->assertSame(777351188, $resultado['escenario_centavos']);
        $this->assertSame(688413188, $resultado['incremento_centavos']);
        $this->assertSame(1, $resultado['pendientes']);
        $this->assertSame('CANDIDATA MENSUAL; NO CONFIRMADA', $resultado['detalle'][0]['certeza_integral']);
        $this->assertNull($resultado['detalle'][1]['integral_centavos']);
    }

    public function test_el_integral_no_reutiliza_la_misma_fila_administrativa(): void
    {
        $base = ['estado' => 'REFERENCIA ADMINISTRATIVA EN FECHA', 'centavos' => 100000, 'fuente' => 'hoja!fila 10', 'detalle_tecnico' => ['cantidad' => 1, 'tarifa' => 10]];
        $this->expectException(\LogicException::class);
        (new ConciliadorEscenarioAdministrativo)->escenarioQuimioterapia([[...$base, 'indice_rips' => 1], [...$base, 'indice_rips' => 2]]);
    }

    public function test_la_alternativa_de_otro_dia_permanece_condicionada(): void
    {
        $resultado = (new ConciliadorEscenarioAdministrativo)->escenarioQuimioterapia([[
            'indice_rips' => 1, 'ruta' => 'CERVIX', 'codigo' => '992505',
            'estado' => 'REFERENCIA ADMINISTRATIVA EN FECHA', 'centavos' => 3278716559,
            'fuente' => 'hoja!fila 10', 'detalle_tecnico' => ['cantidad' => 1, 'tarifa' => 444690, 'central_centavos' => 611531256],
        ]]);

        $this->assertSame('CONDICIONADA: DISTINTIVO EN OTRA FECHA', $resultado['detalle'][0]['certeza_integral']);
        $this->assertSame(3278716559, $resultado['escenario_centavos']);
    }

    public function test_service_in_hospital_package_is_not_added_to_the_scenario(): void
    {
        $base = ['ruta' => 'PROSTATA', 'mes' => '2026-09', 'tipo' => 'AP', 'codigo' => '906610', 'paciente' => 'P1', 'fecha' => '2026-09-01'];
        $rips = [[...$base, 'paquete' => 'INCLUIDO_EN_PAQUETE']];
        $motor = new ConciliadorEscenarioAdministrativo;
        $conciliacion = $motor->conciliar([[...$base, 'centavos' => 100, 'comparable' => true]], $rips);
        $escenario = $motor->escenarioProvisional($conciliacion, $rips);
        $this->assertSame(0, $escenario['valores']['exactos']);
        $this->assertSame(100, $escenario['valores']['paquetes_no_aditivos']);
        $this->assertSame('POSIBLE INCLUSION EN AH; NO ADITIVO', $escenario['detalle_rips'][0]['estado']);
        $this->assertSame(0, $escenario['control']);
    }

    public function test_hospital_scenario_requires_an_identified_discharge_and_is_provisional(): void
    {
        $admin = ['ruta' => 'PROSTATA', 'paciente' => 'P1', 'tipo' => 'AH', 'fecha' => '2026-09-02', 'centavos' => 600,
            'nivel_correspondencia' => 'PENDIENTE DE UNIDAD O IDENTIDAD'];
        $rips = [['ruta' => 'PROSTATA', 'paciente' => 'P1', 'tipo' => 'AH', 'fecha_egreso' => '2026-09-02 12:00', 'destino' => '01']];
        $vinculos = [['indice_admin' => 0, 'indice_rips' => 0, 'evidencia' => 'Egreso documentado']];
        $resultado = (new ConciliadorEscenarioAdministrativo)->escenarioProvisional(['detalle' => [$admin], 'administrativo' => 600], $rips, $vinculos);
        $this->assertSame(600, $resultado['valores']['candidatos']);
        $this->assertSame('REFERENCIA PROVISIONAL NO CONFIRMADA', $resultado['detalle_rips'][0]['estado']);
        $this->assertSame(0, $resultado['control']);
    }

    public function test_billing_cut_cannot_be_recognized_as_hospital_discharge(): void
    {
        $this->expectException(\LogicException::class);
        $admin = ['ruta' => 'PROSTATA', 'paciente' => 'P1', 'tipo' => 'AH', 'fecha' => '2026-09-02', 'centavos' => 600,
            'nivel_correspondencia' => 'PENDIENTE DE UNIDAD O IDENTIDAD'];
        $rips = [['ruta' => 'PROSTATA', 'paciente' => 'P1', 'tipo' => 'AH', 'fecha_egreso' => '2026-09-02 12:00', 'destino' => '08']];
        (new ConciliadorEscenarioAdministrativo)->escenarioProvisional(['detalle' => [$admin], 'administrativo' => 600], $rips,
            [['indice_admin' => 0, 'indice_rips' => 0, 'evidencia' => 'Corte']]);
    }

    public function test_provisional_scenario_keeps_monthly_candidates_separate_and_covers_every_rips_line(): void
    {
        $base = ['ruta' => 'PROSTATA', 'mes' => '2026-09', 'tipo' => 'AP', 'codigo' => '992511', 'paciente' => 'P1', 'fecha' => '2026-09-01'];
        $admin = [[...$base, 'centavos' => 200, 'comparable' => true, 'fuente' => 'fila 1']];
        $rips = [[...$base, 'fecha' => '2026-09-25'], [...$base, 'paciente' => 'P2']];
        $motor = new ConciliadorEscenarioAdministrativo;
        $escenario = $motor->escenarioProvisional($motor->conciliar($admin, $rips), $rips);

        $this->assertSame(200, $escenario['valores']['candidatos']);
        $this->assertSame(0, $escenario['valores']['exactos']);
        $this->assertSame(0, $escenario['control']);
        $this->assertSame(2, $escenario['lineas_rips']);
        $this->assertSame('REFERENCIA PROVISIONAL NO CONFIRMADA', $escenario['detalle_rips'][0]['estado']);
        $this->assertNull($escenario['detalle_rips'][1]['centavos']);
    }

    public function test_provisional_scenario_rejects_a_reused_rips_line(): void
    {
        $this->expectException(\LogicException::class);
        $fila = ['tipo' => 'AP', 'centavos' => 100, 'nivel_correspondencia' => 'CANDIDATA UNICA POR PERIODO', 'indice_rips_candidato' => 0];
        (new ConciliadorEscenarioAdministrativo)->escenarioProvisional(['detalle' => [$fila, $fila], 'administrativo' => 200], [[]]);
    }

    public function test_transport_rows_competing_for_one_rips_line_require_unit_review(): void
    {
        $linea = ['ruta' => 'PROSTATA', 'mes' => '2026-09', 'tipo' => 'AT', 'codigo' => 'T34004', 'paciente' => 'P1', 'fecha' => '2026-09-04'];
        $admin = [[...$linea, 'centavos' => 100, 'comparable' => true], [...$linea, 'centavos' => 100, 'comparable' => true]];
        $resultado = (new ConciliadorEscenarioAdministrativo)->conciliar($admin, [$linea]);
        $this->assertSame('COINCIDENCIA EXACTA', $resultado['detalle'][0]['nivel_correspondencia']);
        $this->assertSame('PENDIENTE DE UNIDAD DE TRANSPORTE', $resultado['detalle'][1]['nivel_correspondencia']);
        $this->assertArrayNotHasKey('indice_rips_candidato', $resultado['detalle'][1]);
    }

    public function test_episode_is_candidate_only_when_the_shared_identifier_is_present(): void
    {
        $linea = ['ruta' => 'CERVIX', 'mes' => '2026-09', 'tipo' => 'AP', 'codigo' => '992511', 'paciente' => 'P1', 'fecha' => '2026-09-04'];
        $admin = [[...$linea, 'ciclo' => 'C1', 'centavos' => 100, 'comparable' => true]];
        $resultado = (new ConciliadorEscenarioAdministrativo)->conciliar($admin, [[...$linea, 'fecha' => '2026-09-09', 'ciclo' => 'C1']]);
        $this->assertSame('CANDIDATA CON ciclo DOCUMENTADO', $resultado['detalle'][0]['nivel_correspondencia']);
        $this->assertSame('SIN RIPS EXACTO', $resultado['detalle'][0]['estado']);
    }

    public function test_multiple_same_day_rips_events_are_not_assigned_arbitrarily(): void
    {
        $linea = ['ruta' => 'CERVIX', 'mes' => '2026-09', 'tipo' => 'AP', 'codigo' => '992511', 'paciente' => 'P1', 'fecha' => '2026-09-04'];
        $resultado = (new ConciliadorEscenarioAdministrativo)->conciliar([[...$linea, 'centavos' => 100, 'comparable' => true]], [$linea, $linea]);
        $this->assertSame('SIN RIPS EXACTO', $resultado['detalle'][0]['estado']);
        $this->assertSame('CANDIDATURA AMBIGUA POR PERIODO', $resultado['detalle'][0]['nivel_correspondencia']);
        $this->assertArrayNotHasKey('indice_rips_candidato', $resultado['detalle'][0]);
    }

    public function test_monthly_candidates_are_not_confirmed_or_reused(): void
    {
        $linea = ['ruta' => 'PROSTATA', 'mes' => '2026-09', 'tipo' => 'AP', 'codigo' => '992511', 'paciente' => 'P1', 'fecha' => '2026-09-01'];
        $admin = [[...$linea, 'centavos' => 100, 'comparable' => true]];
        $rips = [[...$linea, 'fecha' => '2026-09-25']];
        $resultado = (new ConciliadorEscenarioAdministrativo)->conciliar($admin, $rips);
        $this->assertSame('CANDIDATA UNICA POR PERIODO', $resultado['detalle'][0]['nivel_correspondencia']);
        $this->assertSame('SIN RIPS EXACTO', $resultado['detalle'][0]['estado']);
        $this->assertSame(0, $resultado['rips_exactos']);
        $this->assertSame(0, $resultado['detalle'][0]['indice_rips_candidato']);

        $admin[] = [...$linea, 'fecha' => '2026-09-02', 'centavos' => 100, 'comparable' => true];
        $ambiguo = (new ConciliadorEscenarioAdministrativo)->conciliar($admin, $rips);
        $this->assertSame(['CANDIDATURA AMBIGUA POR PERIODO', 'CANDIDATURA AMBIGUA POR PERIODO'], array_column($ambiguo['detalle'], 'nivel_correspondencia'));
        $this->assertArrayNotHasKey('indice_rips_candidato', $ambiguo['detalle'][0]);
        $this->assertArrayNotHasKey('indice_rips_candidato', $ambiguo['detalle'][1]);
    }

    public function test_event_data_and_code_differences_are_not_silently_equated(): void
    {
        $linea = ['ruta' => 'PROSTATA', 'mes' => '2026-09', 'tipo' => 'AP', 'codigo' => '405406', 'paciente' => 'P1', 'fecha' => '2026-09-01'];
        $admin = [[...$linea, 'autorizacion' => 'A', 'centavos' => 100, 'comparable' => true]];
        $contradictorio = (new ConciliadorEscenarioAdministrativo)->conciliar($admin, [[...$linea, 'autorizacion' => 'B']]);
        $this->assertSame('SIN RIPS EXACTO', $contradictorio['detalle'][0]['estado']);
        $codigos = (new ConciliadorEscenarioAdministrativo)->conciliar($admin, [[...$linea, 'codigo' => '405407', 'autorizacion' => 'A']]);
        $this->assertSame('DIFERENCIA DE CODIGO POR REVISAR', $codigos['detalle'][0]['nivel_correspondencia']);
        $this->assertSame(0, $codigos['rips_exactos']);
    }

    public function test_matches_each_rips_line_once_and_keeps_uncomparable_services_separate(): void
    {
        $base = ['ruta' => 'CERVIX', 'mes' => '2026-09', 'tipo' => 'AP', 'codigo' => '992511', 'paciente' => 'P1', 'fecha' => '2026-09-04'];
        $administrativos = [
            [...$base, 'centavos' => 2619469000, 'comparable' => true],
            [...$base, 'centavos' => 2619469000, 'comparable' => true],
            [...$base, 'codigo' => '129M02', 'tipo' => 'AH', 'centavos' => 601531453, 'comparable' => false],
            [...$base, 'codigo' => '405406', 'centavos' => 380561510, 'comparable' => true],
        ];
        $rips = [$base, [...$base, 'codigo' => '405407']];
        $resultado = (new ConciliadorEscenarioAdministrativo)->conciliar($administrativos, $rips);

        $this->assertSame(6221030963, $resultado['administrativo']);
        $this->assertSame(2619469000, $resultado['rips_exactos']);
        $this->assertSame(3000030510, $resultado['no_localizados']);
        $this->assertSame(601531453, $resultado['no_comparables']);
        $this->assertSame(['COINCIDENCIA EXACTA', 'SIN RIPS EXACTO', 'NO COMPARABLE', 'SIN RIPS EXACTO'], array_column($resultado['detalle'], 'estado'));
        $this->assertSame('405407', $resultado['rips_sin_fila'][0]['codigo']);
    }

    public function test_missing_patient_or_date_is_not_a_match(): void
    {
        $linea = ['ruta' => 'PROSTATA', 'mes' => '2026-09', 'tipo' => 'AP', 'codigo' => '906610', 'paciente' => '', 'fecha' => ''];
        $resultado = (new ConciliadorEscenarioAdministrativo)->conciliar([[...$linea, 'centavos' => 100, 'comparable' => true]], [$linea]);
        $this->assertSame(100, $resultado['no_comparables']);
        $this->assertSame(0, $resultado['rips_exactos']);
    }

    public function test_does_not_choose_an_arbitrary_price_when_multiple_administrative_rows_compete_for_one_service(): void
    {
        $linea = ['ruta' => 'CERVIX', 'mes' => '2026-09', 'tipo' => 'AP', 'codigo' => '992511', 'paciente' => 'P1', 'fecha' => '2026-09-04'];
        $administrativos = [[...$linea, 'centavos' => 100, 'comparable' => true], [...$linea, 'centavos' => 200, 'comparable' => true]];

        $resultado = (new ConciliadorEscenarioAdministrativo)->conciliar($administrativos, [$linea]);

        $this->assertSame(300, $resultado['no_comparables']);
        $this->assertSame(0, $resultado['rips_exactos']);
    }
}
