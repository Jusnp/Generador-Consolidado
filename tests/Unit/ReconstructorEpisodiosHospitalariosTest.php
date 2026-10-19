<?php

namespace Tests\Unit;

use App\Programas\LaMaria\ReconstructorEpisodiosHospitalarios;
use PHPUnit\Framework\TestCase;

class ReconstructorEpisodiosHospitalariosTest extends TestCase
{
    public function test_stay_split_by_billing_cut_08_totalling_20_days_is_one_package(): void
    {
        $episodios = $this->reconstruir([
            $this->segmento('FAC-1', '2026-06-20 08:00', '2026-06-30 23:59', '08'),
            $this->segmento('FAC-2', '2026-07-01 00:00', '2026-07-10 08:00', '01'),
        ]);

        $this->assertCount(1, $episodios);
        $this->assertSame(1, $episodios[0]['paquetes']);
        $this->assertSame(1, $episodios[0]['continuidadesResueltas']);
        $this->assertSame(1, $episodios[0]['cortesFacturacion']);
        $this->assertSame(ReconstructorEpisodiosHospitalarios::ESTADO_CERRADO, $episodios[0]['estado']);
        $this->assertSame(['2026-06-20 08:00', '2026-07-10 08:00'], [$episodios[0]['inicio'], $episodios[0]['fin']]);
    }

    public function test_stay_split_by_billing_cut_08_totalling_30_days_charges_by_blocks_not_by_invoice(): void
    {
        $episodios = $this->reconstruir([
            $this->segmento('FAC-1', '2026-06-20 08:00', '2026-06-30 23:59', '08'),
            $this->segmento('FAC-2', '2026-07-01 00:00', '2026-07-20 08:00', '01'),
        ]);

        $this->assertCount(1, $episodios);
        $this->assertSame(2, $episodios[0]['paquetes']);
        $this->assertSame(
            [['numero' => 1, 'inicio' => '2026-06-20 08:00', 'mes' => '2026-06'], ['numero' => 2, 'inicio' => '2026-07-15 08:00', 'mes' => '2026-07']],
            array_map(static fn (array $bloque): array => array_diff_key($bloque, ['segmento' => true]), $episodios[0]['bloques'])
        );
        $this->assertSame(['FAC-1', 'FAC-2'], array_column($episodios[0]['bloques'], 'segmento'));
    }

    public function test_continuation_does_not_need_to_start_on_the_first_day_at_midnight(): void
    {
        $episodios = $this->reconstruir([
            $this->segmento('FAC-1', '2026-06-25 08:00', '2026-06-30 18:00', '08'),
            $this->segmento('FAC-2', '2026-07-01 10:30', '2026-07-05 08:00', '01'),
        ]);

        $this->assertCount(1, $episodios);
        $this->assertSame(1, $episodios[0]['continuidadesResueltas']);
    }

    public function test_cut_08_without_continuation_keeps_only_the_packages_of_the_known_duration(): void
    {
        $episodios = $this->reconstruir([
            $this->segmento('FAC-1', '2026-08-06 13:28', '2026-08-31 16:36', '08'),
        ]);

        $this->assertSame(ReconstructorEpisodiosHospitalarios::ESTADO_CONTINUIDAD_PENDIENTE, $episodios[0]['estado']);
        $this->assertSame(ReconstructorEpisodiosHospitalarios::TIPO_HOSPITALIZACION, $episodios[0]['tipo']);
        $this->assertSame(2, $episodios[0]['paquetes']);
    }

    public function test_short_cut_08_at_month_end_is_not_an_emergency(): void
    {
        $episodios = $this->reconstruir([
            $this->segmento('FAC-1', '2026-08-31 01:18', '2026-08-31 18:50', '08', [
                ['codigo' => '890701', 'consecutivo' => '2', 'fecha' => '2026-08-31 02:00'],
            ]),
        ]);

        $this->assertSame(ReconstructorEpisodiosHospitalarios::ESTADO_CONTINUIDAD_PENDIENTE, $episodios[0]['estado']);
        $this->assertSame(ReconstructorEpisodiosHospitalarios::TIPO_HOSPITALIZACION, $episodios[0]['tipo']);
        $this->assertSame(0, $episodios[0]['paquetes']);
        $this->assertNull($episodios[0]['consultaUrgencia']);
    }

    public function test_closed_stay_under_24_hours_is_an_emergency_with_its_single_urgency_consult(): void
    {
        $consulta = ['codigo' => '890701', 'consecutivo' => '2', 'fecha' => '2026-08-10 02:00'];
        $una = $this->reconstruir([$this->segmento('FAC-1', '2026-08-10 01:00', '2026-08-10 20:00', '01', [$consulta])]);
        $dos = $this->reconstruir([$this->segmento('FAC-1', '2026-08-10 01:00', '2026-08-10 20:00', '01', [
            $consulta,
            ['codigo' => '890702', 'consecutivo' => '3', 'fecha' => '2026-08-10 05:00'],
        ])]);

        $this->assertSame(ReconstructorEpisodiosHospitalarios::TIPO_URGENCIA, $una[0]['tipo']);
        $this->assertSame([...$consulta, 'segmento' => 'FAC-1'], $una[0]['consultaUrgencia']);
        $this->assertNull($dos[0]['consultaUrgencia']);
        $this->assertSame(2, $dos[0]['consultasUrgencia']);
    }

    public function test_a_later_stay_without_any_evidence_of_continuity_is_independent(): void
    {
        $episodios = $this->reconstruir([
            $this->segmento('FAC-1', '2026-06-20 08:00', '2026-06-30 23:59', '08'),
            [...$this->segmento('FAC-2', '2026-07-10 08:00', '2026-07-12 08:00', '01'), 'autorizacion' => 'AUT-2'],
        ]);

        $this->assertCount(2, $episodios);
        $this->assertSame(ReconstructorEpisodiosHospitalarios::ESTADO_CONTINUIDAD_PENDIENTE, $episodios[0]['estado']);
        $this->assertSame(ReconstructorEpisodiosHospitalarios::ESTADO_CERRADO, $episodios[1]['estado']);
        $this->assertSame(1, $episodios[1]['paquetes']);
    }

    public function test_the_same_authorization_in_a_stay_that_is_not_contiguous_is_not_joined_automatically(): void
    {
        $episodios = array_column($this->reconstruir([
            $this->segmento('FAC-1', '2026-06-20 08:00', '2026-06-30 23:59', '08'),
            $this->segmento('FAC-2', '2026-09-10 08:00', '2026-09-12 08:00', '01'),
        ]), null, 'segmentoFinal');

        $this->assertCount(2, $episodios);
        $this->assertSame(ReconstructorEpisodiosHospitalarios::ESTADO_CONTINUIDAD_NO_CONFIRMADA, $episodios['FAC-1']['estado']);
        $this->assertSame('FECHAS_NO_CONTIGUAS', $episodios['FAC-1']['motivoContinuidad']);
        $this->assertSame(1, $episodios['FAC-1']['paquetes']);
        $this->assertTrue($episodios['FAC-2']['retenidoPorContinuidadNoConfirmada']);
        $this->assertSame(0, $episodios['FAC-2']['paquetes']);
    }

    public function test_a_contiguous_continuation_with_another_authorization_is_marked_for_review(): void
    {
        $episodios = array_column($this->reconstruir([
            $this->segmento('FAC-1', '2026-06-20 08:00', '2026-06-30 23:59', '08'),
            [...$this->segmento('FAC-2', '2026-07-01 00:30', '2026-07-12 08:00', '01'), 'autorizacion' => 'AUT-9'],
        ]), null, 'segmentoFinal');

        $this->assertSame(ReconstructorEpisodiosHospitalarios::ESTADO_CONTINUIDAD_NO_CONFIRMADA, $episodios['FAC-1']['estado']);
        $this->assertSame('AUTORIZACION_NO_COINCIDE', $episodios['FAC-1']['motivoContinuidad']);
        $this->assertTrue($episodios['FAC-2']['retenidoPorContinuidadNoConfirmada']);
    }

    public function test_continuity_requires_an_identified_patient_and_a_compatible_provider(): void
    {
        $sinDocumento = $this->reconstruir([
            [...$this->segmento('FAC-1', '2026-06-20 08:00', '2026-06-30 23:59', '08'), 'paciente' => 'SIN_DOCUMENTO|a.json|0'],
            [...$this->segmento('FAC-2', '2026-07-01 00:30', '2026-07-05 08:00', '01'), 'paciente' => 'SIN_DOCUMENTO|a.json|0'],
        ]);
        $otroPrestador = array_column($this->reconstruir([
            $this->segmento('FAC-1', '2026-06-20 08:00', '2026-06-30 23:59', '08'),
            [...$this->segmento('FAC-2', '2026-07-01 00:30', '2026-07-05 08:00', '01'), 'prestadorServicio' => '999999999999'],
        ]), null, 'segmentoFinal');

        $this->assertCount(2, $sinDocumento);
        $this->assertSame(0, $sinDocumento[0]['continuidadesResueltas']);
        $this->assertSame('PRESTADOR_NO_COMPATIBLE', $otroPrestador['FAC-1']['motivoContinuidad']);
    }

    public function test_identical_records_are_the_same_stay_and_other_patients_never_join(): void
    {
        $episodios = $this->reconstruir([
            $this->segmento('FAC-1', '2026-06-20 08:00', '2026-06-25 08:00', '01'),
            $this->segmento('FAC-2', '2026-06-20 08:00', '2026-06-25 08:00', '01'),
            [...$this->segmento('FAC-3', '2026-06-20 08:00', '2026-06-25 08:00', '01'), 'paciente' => 'CC|200'],
        ]);

        $this->assertCount(2, $episodios);
        $this->assertSame(['FAC-1', 'FAC-2'], $episodios[0]['segmentos']);
        $this->assertSame(1, $episodios[0]['paquetes']);
    }

    public function test_repeated_invoice_with_differences_is_never_chained(): void
    {
        $episodios = $this->reconstruir([
            $this->segmento('FAC-1', '2026-06-20 08:00', '2026-06-30 23:59', '08'),
            [...$this->segmento('FAC-2', '2026-07-01 00:00', '2026-07-10 08:00', '01'), 'excluido' => true],
        ]);

        $this->assertCount(2, $episodios);
        $this->assertTrue($episodios[1]['excluido']);
    }

    public function test_cut_08_with_two_possible_continuations_is_not_joined_to_either_and_they_add_no_charges(): void
    {
        $episodios = $this->reconstruir([
            $this->segmento('FAC-JUN', '2026-06-10 08:00', '2026-06-30 23:59', '08'),
            $this->segmento('FAC-JUL-A', '2026-07-01 00:30', '2026-07-25 10:00', '01'),
            $this->segmento('FAC-JUL-B', '2026-07-01 06:00', '2026-07-02 12:00', '01'),
        ]);
        $porSegmento = array_column($episodios, null, 'segmentoFinal');

        $this->assertCount(3, $episodios);
        $this->assertSame(ReconstructorEpisodiosHospitalarios::ESTADO_CONTINUIDAD_NO_CONFIRMADA, $porSegmento['FAC-JUN']['estado']);
        $this->assertSame(['FAC-JUL-A', 'FAC-JUL-B'], $porSegmento['FAC-JUN']['candidatosContinuidad']);
        $this->assertSame(1, $porSegmento['FAC-JUN']['paquetes']);
        $this->assertSame(0, $porSegmento['FAC-JUN']['continuidadesResueltas']);

        foreach (['FAC-JUL-A', 'FAC-JUL-B'] as $candidato) {
            $this->assertTrue($porSegmento[$candidato]['retenidoPorContinuidadNoConfirmada']);
            $this->assertSame(ReconstructorEpisodiosHospitalarios::ESTADO_CONTINUIDAD_NO_CONFIRMADA, $porSegmento[$candidato]['estado']);
            $this->assertSame(ReconstructorEpisodiosHospitalarios::TIPO_HOSPITALIZACION, $porSegmento[$candidato]['tipo']);
            $this->assertSame(0, $porSegmento[$candidato]['paquetes']);
        }
    }

    public function test_a_duplicated_continuation_is_one_candidate_and_is_joined(): void
    {
        $episodios = $this->reconstruir([
            $this->segmento('FAC-JUN', '2026-06-10 08:00', '2026-06-30 23:59', '08'),
            $this->segmento('FAC-JUL-A', '2026-07-01 00:30', '2026-07-25 10:00', '01'),
            $this->segmento('FAC-JUL-A-COPIA', '2026-07-01 00:30', '2026-07-25 10:00', '01'),
        ]);

        $this->assertCount(1, $episodios);
        $this->assertSame(ReconstructorEpisodiosHospitalarios::ESTADO_CERRADO, $episodios[0]['estado']);
        $this->assertSame(2, $episodios[0]['paquetes']);
    }

    public function test_invalid_dates_are_reported_without_packages(): void
    {
        $episodios = $this->reconstruir([$this->segmento('FAC-1', '2026-06-20', '', '01')]);

        $this->assertSame(ReconstructorEpisodiosHospitalarios::ESTADO_SIN_FECHAS, $episodios[0]['estado']);
        $this->assertSame(0, $episodios[0]['paquetes']);
    }

    /**
     * @param  list<array<string, mixed>>  $segmentos
     * @return list<array<string, mixed>>
     */
    private function reconstruir(array $segmentos): array
    {
        return (new ReconstructorEpisodiosHospitalarios)->reconstruir($segmentos);
    }

    /**
     * @param  list<array{codigo: string, consecutivo: string, fecha: string}>  $consultas
     * @return array<string, mixed>
     */
    private function segmento(string $clave, string $inicio, string $fin, string $destino, array $consultas = []): array
    {
        return [
            'clave' => $clave,
            'paciente' => 'CC|100',
            'inicio' => $inicio,
            'fin' => $fin,
            'destino' => $destino,
            'autorizacion' => 'AUT-1',
            'prestadorServicio' => '050010608601',
            'consultasUrgencia' => $consultas,
        ];
    }
}
