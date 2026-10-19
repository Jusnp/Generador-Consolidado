<?php

namespace Tests\Unit;

use App\Programas\LaMaria\ResumenEconomico;
use PHPUnit\Framework\TestCase;

class ResumenEconomicoTest extends TestCase
{
    public function test_backed_result_and_referential_scenario_come_from_the_same_lines_without_double_counting(): void
    {
        $report = new ResumenEconomico('PROSTATA');
        $backed = $report->registrar(['mesAtencion' => '2026-06', 'sumaAlResumen' => 'SI', 'fuenteTarifa' => 'NOTA_TECNICA', 'costoEstimadoReferencia' => 100.25]);
        $conditioned = $report->registrar(['mesAtencion' => '2026-06', 'sumaAlResumen' => 'SI', 'fuenteTarifa' => 'COMPLEMENTO', 'costoEstimadoReferencia' => 200.75]);
        $administrative = $report->registrar(['mesAtencion' => '2026-06', 'sumaAlResumen' => 'NO', 'sumaEscenarioReferencial' => 'SI',
            'motivoNoSuma' => 'SIN_TARIFA', 'estadoTarifario' => 'SIN_TARIFA', 'costoEscenarioReferencial' => 300.0, 'valorCondicionado' => 300.0]);
        $external = $report->registrar(['mesAtencion' => '2026-06', 'sumaAlResumen' => 'NO', 'sumaEscenarioReferencial' => 'NO',
            'motivoNoSuma' => 'SIN_TARIFA', 'estadoTarifario' => 'SIN_TARIFA', 'valorCondicionado' => 40.5]);

        $rows = $report->filas([['2026-06', 1, 4, 6015615.53]], 'PROSTATA');

        $this->assertSame('COSTO RESPALDADO', $backed['estado']);
        $this->assertSame('COSTO CONDICIONADO', $conditioned['estado']);
        $this->assertSame('ESCENARIO REFERENCIAL (PRECIO ADMINISTRATIVO)', $administrative['estado']);
        $this->assertSame(0.0, $administrative['condicionadoExterno']);
        $this->assertSame(300.0, $administrative['referencialAdicional']);
        $this->assertSame(40.5, $external['condicionadoExterno']);

        [$ruta, $mes, $referencia, $respaldado, $condicionado, $escenario, $externos, $brecha, $brechaReferencial, $activo] = $rows[0];
        $this->assertSame(['PROSTATA', '2026-06', 3622024541.0], [$ruta, $mes, $referencia]);
        $this->assertSame(6015414.78, $respaldado);
        $this->assertSame(500.75, $condicionado);
        $this->assertSame(6015915.53, $escenario);
        $this->assertSame($respaldado + $condicionado, $escenario);
        $this->assertSame(40.5, $externos);
        $this->assertSame(6015615.53, $activo);
        $this->assertSame(round(3622024541.0 - 6015414.78, 2), $brecha);
        $this->assertSame(round(3622024541.0 - 6015915.53, 2), $brechaReferencial);
        $this->assertSame('NO CALCULADA', $rows[0][14]);
    }

    public function test_absorbed_duplicated_and_quantity_missing_services_add_nothing_to_either_result(): void
    {
        $report = new ResumenEconomico('PROSTATA');
        $absorbed = $report->registrar(['mesAtencion' => '2026-06', 'sumaAlResumen' => 'NO', 'sumaEscenarioReferencial' => 'NO', 'decisionPaquete' => 'INCLUIDO_EN_PAQUETE', 'motivoNoSuma' => 'INCLUIDO_EN_PAQUETE', 'valorCondicionado' => 500]);
        $duplicate = $report->registrar(['mesAtencion' => '2026-06', 'sumaAlResumen' => 'NO', 'sumaEscenarioReferencial' => 'NO', 'motivoNoSuma' => 'DUPLICADO_EXACTO', 'valorCondicionado' => 500]);
        $missing = $report->registrar(['mesAtencion' => '2026-06', 'sumaAlResumen' => 'NO', 'sumaEscenarioReferencial' => 'NO', 'motivoNoSuma' => 'CANTIDAD_MEDICAMENTO_NO_INFORMADA', 'valorCondicionado' => 500]);

        $this->assertSame('SERVICIO ABSORBIDO', $absorbed['estado']);
        $this->assertSame('EXCLUIDO SIN COSTO', $duplicate['estado']);

        foreach ([$absorbed, $duplicate, $missing] as $line) {
            $this->assertSame(0.0, $line['condicionadoExterno']);
            $this->assertSame(0.0, $line['escenarioReferencial']);
        }

        $total = $report->totalesMensuales([['2026-06', 1, 3, 0.0]])['2026-06'];
        $this->assertSame([0.0, 0.0, 0.0, 0], [$total['respaldado'], $total['escenario'], $total['externo'], $total['sinTarifa']]);
    }

    public function test_radiotherapy_pending_unit_is_an_alternative_without_an_invented_cost(): void
    {
        $report = new ResumenEconomico('CERVIX');
        $radiotherapy = $report->registrar(['sumaAlResumen' => 'NO', 'estadoTarifario' => 'CON_TARIFA', 'tipoValorReferencia' => 'TARIFA_TRATAMIENTO_PENDIENTE']);

        $this->assertSame('ALTERNATIVA NO ADITIVA', $radiotherapy['estado']);
        $this->assertSame(0.0, $radiotherapy['respaldado']);
        $this->assertSame(0.0, $radiotherapy['escenarioReferencial']);
    }

    public function test_medications_and_services_without_tariff_are_aggregated_with_their_source_and_reason(): void
    {
        $report = new ResumenEconomico('CERVIX');
        $line = ['mesAtencion' => '2026-06', 'tipoServicio' => 'medicamentos', 'codigo' => '20085509-2', 'cumResuelto' => '20085509-2',
            'principioActivo' => 'PEMBROLIZUMAB', 'concentracionMedicamento' => '100', 'unidadMedida' => 'MG', 'formaFarmaceutica' => 'SOLUCION',
            'cantidad' => '2', 'sumaAlResumen' => 'NO', 'sumaEscenarioReferencial' => 'SI', 'motivoNoSuma' => 'SIN_TARIFA', 'estadoTarifario' => 'SIN_TARIFA',
            'tarifaEscenarioReferencial' => 13763776.0, 'costoEscenarioReferencial' => 27527552.0, 'fuenteEscenarioReferencial' => 'DISPENSACION',
            'valorCondicionado' => 27527552.0, 'motivoCondicionado' => 'DISPENSACION_ADMINISTRATIVA_CONDICIONADA'];
        $report->registrar($line);
        $report->registrar([...$line, 'mesAtencion' => '2026-07']);
        $report->registrar(['mesAtencion' => '2026-06', 'tipoServicio' => 'otrosServicios', 'codigo' => 'T34004', 'nombreServicio' => 'TRANSPORTE',
            'cantidad' => '1', 'sumaAlResumen' => 'NO', 'motivoNoSuma' => 'SIN_TARIFA', 'estadoTarifario' => 'SIN_TARIFA', 'estadoCruce' => 'SIN_REFERENCIA']);

        $medication = $report->filasMedicamentos()[0];
        $withoutTariff = collect($report->filasSinTarifa())->keyBy(1);

        $this->assertSame(['PEMBROLIZUMAB', '20085509-2', '', '100 MG SOLUCION', 4.0, 13763776.0, 'DISPENSACION (ESCENARIO REFERENCIAL)'], array_slice($medication, 0, 7));
        $this->assertSame([0.0, 55055104.0, 0.0, 55055104.0, 2, '2026-06, 2026-07'], array_slice($medication, 7, 6));
        $this->assertSame('DISPENSACION_ADMINISTRATIVA_CONDICIONADA', $medication[13]);
        $this->assertSame('TRANSPORTE_ALBERGUE', $withoutTariff['T34004'][3]);
        $this->assertSame(1, $withoutTariff['T34004'][6]);
        $this->assertSame(2, $withoutTariff['20085509-2'][6]);
        $this->assertSame(55055104.0, $withoutTariff['20085509-2'][9]);
    }

    public function test_multi_month_rows_end_with_a_period_total_against_one_reference_per_month(): void
    {
        $report = new ResumenEconomico('CERVIX');
        $report->registrar(['mesAtencion' => '2026-06', 'sumaAlResumen' => 'SI', 'fuenteTarifa' => 'NOTA_TECNICA', 'costoEstimadoReferencia' => 10]);
        $report->registrar(['mesAtencion' => '2026-07', 'sumaAlResumen' => 'SI', 'fuenteTarifa' => 'NOTA_TECNICA', 'costoEstimadoReferencia' => 20]);

        $rows = $report->filas([['2026-06', 1, 1, 10.0], ['2026-07', 1, 1, 20.0]], 'CERVIX');

        $this->assertSame('TOTAL PERIODO', $rows[2][1]);
        $this->assertSame(1892436504.0 * 2, $rows[2][2]);
        $this->assertSame(30.0, $rows[2][3]);
    }
}
