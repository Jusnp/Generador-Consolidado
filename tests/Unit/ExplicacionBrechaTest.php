<?php

namespace Tests\Unit;

use App\Programas\LaMaria\ExplicacionBrecha;
use App\Programas\LaMaria\PoliticaPaqueteHospitalario;
use App\Programas\LaMaria\ResumenEconomico;
use PHPUnit\Framework\TestCase;

class ExplicacionBrechaTest extends TestCase
{
    public function test_gap_is_split_into_demonstrable_activity_causes_and_unquantified_units_without_forcing_it(): void
    {
        $package = PoliticaPaqueteHospitalario::VALOR_PAQUETE;
        $note = [
            $this->concept('RESUMEN:CONSULTA_EXTERNA', 'RESUMEN_TECNICO', 15000),
            $this->concept('RESUMEN:HOSPITALIZACION', 'RESUMEN_TECNICO', 2 * $package),
            $this->concept('RESUMEN:RADIOTERAPIA', 'RESUMEN_TECNICO', 1000),
            $this->concept('RESUMEN:VALOR_TOTAL_MES', 'RESUMEN_TECNICO', 16000 + 2 * $package),
            $this->concept('890201', 'SERVICIO_TECNICO', 5000, ['servicio' => 'Consulta externa', 'cantidad_mensual' => 3]),
            $this->concept('922444', 'ONCOLOGIA_RADIOTERAPIA', 10000, ['cantidad_referencia' => 0.1]),
        ];
        $report = new ResumenEconomico('PROSTATA', $note);
        $report->registrar(['mesAtencion' => '2026-06', 'tipoServicio' => 'consultas', 'codigo' => '890201', 'cantidadAplicada' => 1.0,
            'sumaAlResumen' => 'SI', 'fuenteTarifa' => 'NOTA_TECNICA', 'costoEstimadoReferencia' => 5000, 'estadoTarifario' => 'CON_TARIFA']);
        $report->registrar(['mesAtencion' => '2026-07', 'tipoServicio' => 'procedimientos', 'codigo' => '922444', 'cantidadAplicada' => 1.0,
            'sumaAlResumen' => 'NO', 'motivoNoSuma' => 'SIN_TARIFA', 'estadoTarifario' => 'SIN_TARIFA', 'tipoValorReferencia' => 'TARIFA_TRATAMIENTO_PENDIENTE']);
        $monthly = [['2026-06', 1, 1, 5000 + $package], ['2026-07', 1, 1, 0.0]];
        $explanation = new ExplicacionBrecha($report);

        $rows = collect($explanation->filas($report->totalesMensuales($monthly)))->keyBy(static fn (array $row): string => $row[1].'|'.$row[2]);
        $costs = $report->filas($monthly, 'PROSTATA', $explanation->totalesPorMes());

        $consultations = $rows['2026-06|CONSULTA_EXTERNA'];
        $this->assertSame([15000.0, 5000.0, 10000.0, 3.0, 1.0], [$consultations[3], $consultations[4], $consultations[7], $consultations[8], $consultations[9]]);
        $this->assertSame([0.0, 10000.0, 0.0, 0.0, 10000.0, 0.0, 0.0], array_slice($consultations, 10, 7));

        $hospital = $rows['2026-06|HOSPITALIZACION'];
        $this->assertSame(round($package, 2), $hospital[4]);
        $this->assertSame(round($package, 2), $hospital[11]);
        $this->assertSame(0.0, $hospital[16]);

        $this->assertSame(1000.0, $rows['2026-06|RADIOTERAPIA'][10]);
        $this->assertSame(1000.0, $rows['2026-07|RADIOTERAPIA'][15]);
        $this->assertSame(0.0, $rows['2026-07|RADIOTERAPIA'][14]);

        foreach (['2026-06', '2026-07'] as $month) {
            $total = $rows[$month.'|TOTAL'];
            $this->assertEqualsWithDelta($total[7], $total[14] + $total[15] + $total[16], 0.01);
            // Solo queda el redondeo del total de la nota a pesos enteros.
            $this->assertEqualsWithDelta(0.0, $total[16], 0.5);
        }

        $this->assertSame($rows['2026-06|TOTAL'][7], $costs[0][7]);
        $this->assertSame([$rows['2026-06|TOTAL'][14], $rows['2026-06|TOTAL'][15], $rows['2026-06|TOTAL'][16]], array_slice($costs[0], 14, 3));
        $this->assertSame(1.0, $costs[0][17]);
    }

    public function test_more_activity_than_projected_reduces_the_gap_and_unattributed_differences_stay_unexplained(): void
    {
        $note = [
            $this->concept('RESUMEN:CONSULTA_EXTERNA', 'RESUMEN_TECNICO', 5000),
            $this->concept('RESUMEN:MEDICAMENTOS', 'RESUMEN_TECNICO', 9000),
            $this->concept('RESUMEN:VALOR_TOTAL_MES', 'RESUMEN_TECNICO', 14000),
            $this->concept('890201', 'SERVICIO_TECNICO', 5000, ['servicio' => 'Consulta externa', 'cantidad_mensual' => 1]),
        ];
        $report = new ResumenEconomico('CERVIX', $note);

        foreach ([1, 2] as $consecutive) {
            $report->registrar(['mesAtencion' => '2026-08', 'tipoServicio' => 'consultas', 'codigo' => '890201', 'cantidadAplicada' => 1.0,
                'consecutivoRips' => $consecutive, 'sumaAlResumen' => 'SI', 'fuenteTarifa' => 'NOTA_TECNICA', 'costoEstimadoReferencia' => 5000, 'estadoTarifario' => 'CON_TARIFA']);
        }

        $report->registrar(['mesAtencion' => '2026-08', 'tipoServicio' => 'medicamentos', 'codigo' => 'X', 'cantidad' => '1', 'sumaAlResumen' => 'SI',
            'fuenteTarifa' => 'NOTA_TECNICA', 'costoEstimadoReferencia' => 1000, 'estadoTarifario' => 'CON_TARIFA']);
        $explanation = new ExplicacionBrecha($report);
        $rows = collect($explanation->filas($report->totalesMensuales([['2026-08', 1, 3, 11000.0]])))->keyBy(2);

        $this->assertSame(-5000.0, $rows['CONSULTA_EXTERNA'][7]);
        $this->assertSame(5000.0, $rows['CONSULTA_EXTERNA'][12]);
        $this->assertSame(-5000.0, $rows['CONSULTA_EXTERNA'][14]);
        $this->assertSame(8000.0, $rows['MEDICAMENTOS'][16]);
        $this->assertSame(3000.0, $rows['TOTAL'][7]);
        $this->assertSame(8000.0, $rows['TOTAL'][16]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function concept(string $code, string $category, float $rate, array $metadata = []): array
    {
        return ['codigo' => $code, 'categoria' => $category, 'tarifaReferencia' => $rate, 'metadatos' => $metadata, 'ruta' => 'PROSTATA'];
    }
}
