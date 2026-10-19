<?php

namespace Tests\Unit;

use App\Models\CatalogoReferencia;
use App\Models\CatalogoReferenciaItem;
use App\Programas\LaMaria\PoliticaDispensacion;
use App\Services\ReferenciaRutaMatcher;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ReferenciaRutaMatcherMedicamentoTest extends TestCase
{
    private int $nextCatalogId = 1;

    public static function reportedMedicationQuantities(): array
    {
        return [
            'ausente' => [null, 0.0, 'NO_INFORMADA'],
            'vacia' => ['', 0.0, 'NO_INFORMADA'],
            'cero' => [0, 0.0, 'CERO'],
            'positiva' => [2, 175934.0, 'POSITIVA_VALIDA'],
            'decimal' => [1.5, 131950.5, 'POSITIVA_VALIDA'],
            'negativa invalida' => [-1, 0.0, 'INVALIDA'],
            'texto invalido' => ['pendiente', 0.0, 'INVALIDA'],
        ];
    }

    #[DataProvider('reportedMedicationQuantities')]
    public function test_medication_cost_requires_a_reported_quantity(
        int|float|string|null $quantity,
        float $expectedCost,
        string $expectedState
    ): void {
        $record = $this->record('20137853-1', 'APALUTAMIDA', 1);
        $record['cantidad'] = $quantity;

        $match = $this->matcher()->match($record, 'PROSTATA');

        $this->assertSame($expectedCost, $match['costoEstimadoReferencia']);
        $this->assertSame($expectedState, $match['estadoCantidad']);
    }

    public function test_exact_cum_is_priced_with_the_technical_note(): void
    {
        $match = $this->matcher()->match($this->record('20137853-1', 'APALUTAMIDA', 120), 'PROSTATA');

        $this->assertSame('CUM_EXACTO', $match['metodoResolucion']);
        $this->assertSame('20137853-1', $match['cumResuelto']);
        $this->assertSame(87967.0, $match['tarifaReferencia']);
        $this->assertSame(87967.0 * 120, $match['costoEstimadoReferencia']);
    }

    public function test_exact_ium_with_a_single_commercial_presentation_is_resolved_and_priced_per_unit(): void
    {
        $match = $this->matcher()->match($this->record('1A1027731000100', 'APALUTAMIDA', 120), 'PROSTATA');

        $this->assertSame('RESUELTO', $match['resolucionMedicamento']);
        $this->assertSame('IUM_EXACTO', $match['metodoResolucion']);
        $this->assertSame('IUM_EXACTO', $match['formaCruce']);
        $this->assertSame('20137853-1', $match['cumResuelto']);
        $this->assertSame('NOTA_TECNICA', $match['fuenteTarifa']);
        // La caja trae 120 tabletas (cantidad_cum); el costo usa cantidadMedicamento × tarifa por unidad.
        $this->assertSame(87967.0 * 120, $match['costoEstimadoReferencia']);
    }

    public function test_ium_with_several_presentations_is_ambiguous_and_not_priced(): void
    {
        $matcher = $this->matcher([
            $this->cum('20195068-1', 'ENZALUTAMIDA', 40, 'CAPSULA BLANDA', 120, 'No', '1E1033301999100'),
            $this->cum('20178073-1', 'ENZALUTAMIDA', 80, 'TABLETA RECUBIERTA', 56, 'No', '1E1033301999100'),
        ]);

        $match = $matcher->match($this->record('1E1033301999100', 'ENZALUTAMIDA', 120), 'PROSTATA');

        $this->assertSame('AMBIGUO', $match['resolucionMedicamento']);
        $this->assertSame('REVISAR_PRESENTACION', $match['estadoTarifario']);
        $this->assertSame(0.0, $match['costoEstimadoReferencia']);
    }

    public function test_combination_product_with_one_registry_row_per_ingredient_is_a_single_presentation(): void
    {
        $matcher = $this->matcher([
            $this->cum('20071759-5', 'ACETAMINOFEN', 500, 'TABLETA RECUBIERTA', 48, 'No', '2C1022161002102'),
            $this->cum('20071759-5', 'CAFEINA', 65, 'TABLETA RECUBIERTA', 48, 'No', '2C1022161002102'),
            $this->cum('20071759-17', 'ACETAMINOFEN', 500, 'TABLETA RECUBIERTA', 48, 'Si', '2C1022161002102'),
        ]);

        $match = $matcher->match($this->record('2C1022161002102', 'ACETAMINOFEN', 10), 'PROSTATA');

        $this->assertSame('RESUELTO', $match['resolucionMedicamento']);
        $this->assertSame('IUM_EXACTO', $match['metodoResolucion']);
        $this->assertSame('20071759-5', $match['cumResuelto']);
    }

    public function test_ium_contradicted_by_the_dispensation_of_the_same_period_is_a_conflict_and_not_priced(): void
    {
        $matcher = $this->matcher(dispensations: [$this->dispensation('20195068-1', '700', '2026-07-08', 46624.0)]);

        $match = $matcher->match($this->record('1E1033861000100', 'ENZALUTAMIDA', 56), 'PROSTATA');

        $this->assertSame('CONFLICTO', $match['resolucionMedicamento']);
        $this->assertStringContainsString('CUM 20178073-1', $match['evidenciaResolucion']);
        $this->assertStringContainsString('CUM 20195068-1 (fila 10)', $match['evidenciaResolucion']);
        $this->assertSame('REVISAR_PRESENTACION', $match['estadoTarifario']);
        $this->assertSame(0.0, $match['costoEstimadoReferencia']);
    }

    public function test_ium_confirmed_by_the_dispensation_or_without_dispensation_is_priced(): void
    {
        $confirmed = $this->matcher(dispensations: [
            $this->dispensation('20195068-1', '700', '2026-07-08', 46624.0),
            $this->dispensation('20178073-1', '700', '2026-07-20', 187033.6),
        ])->match($this->record('1E1033861000100', 'ENZALUTAMIDA', 56), 'PROSTATA');
        $withoutDispensation = $this->matcher()->match($this->record('1E1033861000100', 'ENZALUTAMIDA', 56), 'PROSTATA');

        $this->assertSame('RESUELTO', $confirmed['resolucionMedicamento']);
        $this->assertSame(187033.6 * 56, $confirmed['costoEstimadoReferencia']);
        $this->assertSame('RESUELTO', $withoutDispensation['resolucionMedicamento']);
        $this->assertSame(187033.6 * 56, $withoutDispensation['costoEstimadoReferencia']);
    }

    public function test_another_brand_with_the_same_strength_dispensed_in_the_period_is_not_a_conflict(): void
    {
        $matcher = $this->matcher(
            cums: [
                $this->cum('19959519-2', 'PEGFILGRASTIM', 6, 'SOLUCION INYECTABLE', 1, 'No', '1P1029381006100'),
                $this->cum('20066850-1', 'PEGFILGRASTIM (FACTOR ESTIMULANTE DE COLONIAS DE GRANULOCITOS)', 6, 'SOLUCION INYECTABLE', 1, 'No', ''),
                $this->cum('20135022-2', 'BICALUTAMIDA', 50, 'TABLETA RECUBIERTA', 14, 'No', '1B1004961002101'),
                $this->cum('20065188-1', 'BICALUTAMIDA (FORMA H1)', 150, 'TABLETA', 30, 'No', ''),
            ],
            dispensations: [
                $this->dispensation('20066850-1', '700', '2026-07-08', 3049856.16),
                $this->dispensation('20065188-1', '700', '2026-07-08', 4988.0),
            ]
        );

        $sameStrength = $matcher->match($this->record('1P1029381006100', 'PEGFILGRASTIM', 1), 'PROSTATA');
        $otherStrength = $matcher->match($this->record('1B1004961002101', 'BICALUTAMIDA', 30), 'PROSTATA');

        $this->assertSame('RESUELTO', $sameStrength['resolucionMedicamento']);
        $this->assertSame('19959519-2', $sameStrength['cumResuelto']);
        $this->assertSame('CONFLICTO', $otherStrength['resolucionMedicamento']);
        $this->assertSame(0.0, $otherStrength['costoEstimadoReferencia']);
    }

    public function test_medical_sample_ium_is_not_billed_without_commercial_evidence(): void
    {
        $match = $this->matcher()->match($this->record('1A1060601000101', 'APALUTAMIDA', 30), 'PROSTATA');

        $this->assertSame('MUESTRA_MEDICA', $match['resolucionMedicamento']);
        $this->assertTrue($match['muestraMedica']);
        $this->assertSame(0.0, $match['costoEstimadoReferencia']);
    }

    public function test_medical_sample_ium_uses_the_commercial_presentation_dispensed_to_the_same_patient_and_month(): void
    {
        $matcher = $this->matcher(dispensations: [
            $this->dispensation('20254463-1', '700', '2026-07-08', 339686.0),
        ]);

        $match = $matcher->match($this->record('1A1060601000101', 'APALUTAMIDA', 30), 'PROSTATA');

        $this->assertSame('RESUELTO', $match['resolucionMedicamento']);
        $this->assertSame('DISPENSACION_PACIENTE_PERIODO', $match['metodoResolucion']);
        $this->assertSame('20254463-1', $match['cumResuelto']);
        $this->assertStringContainsString('muestra médica 20254463-2', $match['evidenciaResolucion']);
        $this->assertSame('NOTA_TECNICA', $match['fuenteTarifa']);
        $this->assertSame(339686.0 * 30, $match['costoEstimadoReferencia']);
    }

    public function test_dispensation_of_another_patient_or_month_is_not_evidence(): void
    {
        $matcher = $this->matcher(dispensations: [
            $this->dispensation('20195068-1', '999', '2026-07-08', 46624.0),
            $this->dispensation('20195068-1', '700', '2026-06-08', 46624.0),
        ]);

        $match = $matcher->match($this->record('1E1033301000101', 'ENZALUTAMIDA', 120), 'PROSTATA');

        $this->assertSame('SIN_RESOLUCION', $match['resolucionMedicamento']);
        $this->assertSame(0.0, $match['costoEstimadoReferencia']);
    }

    public function test_unknown_ium_is_resolved_only_by_a_single_dispensed_presentation(): void
    {
        $single = $this->matcher(dispensations: [$this->dispensation('20195068-1', '700', '2026-07-08', 46624.0)])
            ->match($this->record('1E1033301000101', 'ENZALUTAMIDA', 120), 'PROSTATA');
        $several = $this->matcher(dispensations: [
            $this->dispensation('20195068-1', '700', '2026-07-08', 46624.0),
            $this->dispensation('20178073-1', '700', '2026-07-20', 187033.6),
        ])->match($this->record('1E1033301000101', 'ENZALUTAMIDA', 120), 'PROSTATA');

        $this->assertSame('DISPENSACION_PACIENTE_PERIODO', $single['metodoResolucion']);
        $this->assertSame(46624.0 * 120, $single['costoEstimadoReferencia']);
        $this->assertSame('AMBIGUO', $several['resolucionMedicamento']);
        $this->assertSame(0.0, $several['costoEstimadoReferencia']);
    }

    public function test_unregistered_ium_is_resolved_by_its_registry_family_with_the_exact_dispensed_quantity(): void
    {
        $match = $this->familyMatcher([
            $this->dispensation('20195068-1', '700', '2026-07-08', 46624.0, 120),
            $this->dispensation('20178073-1', '700', '2026-07-20', 187033.6, 56),
        ])->match($this->record('1E1033301000101', 'ENZALUTAMIDA', 120), 'PROSTATA');

        $this->assertSame('RESUELTO', $match['resolucionMedicamento']);
        $this->assertSame('IUM_FAMILIA_DISPENSACION', $match['metodoResolucion']);
        $this->assertSame('20195068-1', $match['cumResuelto']);
        $this->assertSame('NOTA_TECNICA', $match['fuenteTarifa']);
        $this->assertSame(46624.0, $match['tarifaReferencia']);
        $this->assertSame(5594880.0, $match['costoEstimadoReferencia']);
        $this->assertStringContainsString('IUM 1E1033301000101 no existe en el registro', $match['evidenciaResolucion']);
        $this->assertStringContainsString('familia 1E10333010', $match['evidenciaResolucion']);
        $this->assertStringContainsString('descartadas por concentración o forma: CUM 20178073-1', $match['evidenciaResolucion']);
    }

    public function test_family_resolution_rejects_every_case_that_is_not_fully_demonstrable(): void
    {
        $both = fn (float $units40, float $units80 = 56): array => [
            $this->dispensation('20195068-1', '700', '2026-07-08', 46624.0, $units40),
            $this->dispensation('20178073-1', '700', '2026-07-20', 187033.6, $units80),
        ];
        $cases = [
            'CANTIDAD_NO_COINCIDE' => [$this->familyMatcher($both(240)), $this->record('1E1033301000101', 'ENZALUTAMIDA', 120)],
            'FAMILIA_IUM_CON_VARIAS_PRESENTACIONES' => [$this->familyMatcher($both(120), [$this->cum('20999999-1', 'ENZALUTAMIDA', 160, 'CAPSULA BLANDA', 30, 'No', '1E1033301009100')]), $this->record('1E1033301000101', 'ENZALUTAMIDA', 120)],
            'VARIOS_CUM_DISPENSADOS_CON_LA_PRESENTACION' => [$this->familyMatcher([
                $this->dispensation('20195068-1', '700', '2026-07-08', 46624.0, 120),
                $this->dispensation('20198992-1', '700', '2026-07-09', 46624.0, 120),
                $this->dispensation('20178073-1', '700', '2026-07-20', 187033.6, 56),
            ]), $this->record('1E1033301000101', 'ENZALUTAMIDA', 120)],
            'SIN_DISPENSACION_DE_LA_PRESENTACION_DE_LA_FAMILIA' => [$this->familyMatcher([
                $this->dispensation('20224710-1', '700', '2026-07-08', 46624.0, 120),
                $this->dispensation('20178073-1', '700', '2026-07-20', 187033.6, 56),
            ]), $this->record('1E1033301000101', 'ENZALUTAMIDA', 120)],
            'FAMILIA_IUM_SIN_REGISTROS_COMERCIALES' => [$this->familyMatcher($both(120)), $this->record('1E1033991000101', 'ENZALUTAMIDA', 120)],
        ];

        foreach ($cases as $reason => [$matcher, $record]) {
            $match = $matcher->match($record, 'PROSTATA');

            $this->assertSame('AMBIGUO', $match['resolucionMedicamento'], $reason);
            $this->assertStringContainsString('AMBIGUO — NO SUMA AUTOMÁTICAMENTE: '.$reason, $match['evidenciaResolucion']);
            $this->assertSame(0.0, $match['costoEstimadoReferencia'], $reason);
        }
    }

    public function test_family_resolution_never_uses_another_patient_a_medical_sample_or_a_different_principle(): void
    {
        $otherPatient = $this->familyMatcher([
            $this->dispensation('20195068-1', '701', '2026-07-08', 46624.0, 120),
            $this->dispensation('20178073-1', '701', '2026-07-20', 187033.6, 56),
        ])->match($this->record('1E1033301000101', 'ENZALUTAMIDA', 120), 'PROSTATA');
        $sample = $this->familyMatcher([
            $this->dispensation('20195068-2', '700', '2026-07-08', 46624.0, 120),
            $this->dispensation('20178073-1', '700', '2026-07-20', 187033.6, 56),
            $this->dispensation('20224710-1', '700', '2026-07-21', 46624.0, 112),
        ], [$this->cum('20195068-2', 'ENZALUTAMIDA', 40, 'CAPSULA BLANDA', 12, 'Si', '1E1033301002101')])
            ->match($this->record('1E1033301000101', 'ENZALUTAMIDA', 120), 'PROSTATA');
        $otherPrinciple = $this->familyMatcher([
            $this->dispensation('20195068-1', '700', '2026-07-08', 46624.0, 120),
            $this->dispensation('20178073-1', '700', '2026-07-20', 187033.6, 56),
        ])->match($this->record('1E1033301000101', 'APALUTAMIDA', 120), 'PROSTATA');

        $this->assertSame('SIN_RESOLUCION', $otherPatient['resolucionMedicamento']);
        $this->assertNotSame('RESUELTO', $sample['resolucionMedicamento']);
        $this->assertNotSame('RESUELTO', $otherPrinciple['resolucionMedicamento']);
        $this->assertSame(0.0, $otherPatient['costoEstimadoReferencia'] + $sample['costoEstimadoReferencia'] + $otherPrinciple['costoEstimadoReferencia']);
    }

    public function test_only_a_verified_complement_price_in_force_is_a_tariff(): void
    {
        $complement = fn (array ...$rows): array => [$this->catalog('COMPLEMENTO_MEDICAMENTOS_PROSTATA', array_map(
            fn (array $row): CatalogoReferenciaItem => $this->item('20178975-1', 'PROSTATA', 'MEDICAMENTO_COMPLEMENTARIO', 'FOSAPREPITANT', $row[0], $row[1]),
            $rows
        ))];
        $verified = ['estado_contractual' => 'VERIFICADO', 'evidencia_contractual' => 'Otrosí 3', 'vigencia_desde' => '2026-04-01', 'vigencia_hasta' => ''];
        $cums = [$this->cum('20178975-1', 'FOSAPREPITANT', 150, 'POLVO PARA SOLUCION INYECTABLE', 1, 'No', '1F1000000000100')];
        $record = ['codigo' => '20178975-1', 'tipoServicio' => 'medicamentos', 'nombreServicio' => 'FOSAPREPITANT', 'cantidad' => 2,
            'numDocumentoIdentificacion' => '700', 'fechaAtencion' => '2026-07-10 09:00', 'valorRips' => 0];

        $priced = $this->matcher($cums, [], 'PROSTATA', $complement([147460.0, $verified]))->match($record, 'PROSTATA');
        $pending = $this->matcher($cums, [], 'PROSTATA', $complement([147460.0, ['estado_contractual' => 'PENDIENTE']]))->match($record, 'PROSTATA');
        $twoPending = $this->matcher($cums, [], 'PROSTATA', $complement([147460.0, ['estado_contractual' => 'PENDIENTE']], [73333.0, ['estado_contractual' => 'PENDIENTE']]))->match($record, 'PROSTATA');
        $expired = $this->matcher($cums, [], 'PROSTATA', $complement([147460.0, [...$verified, 'vigencia_hasta' => '2026-06-30']]))->match($record, 'PROSTATA');
        $cervix = $this->matcher($cums, [], 'CERVIX', $complement([147460.0, $verified]))->match($record, 'CERVIX');

        $this->assertSame('COMPLEMENTO', $priced['fuenteTarifa']);
        $this->assertSame(294920.0, $priced['costoEstimadoReferencia']);
        $this->assertSame('VERIFICADO', $priced['estadoContractualComplemento']);
        $this->assertSame(0.0, $pending['costoEstimadoReferencia']);
        $this->assertSame(294920.0, $pending['valorCondicionado']);
        $this->assertSame('COMPLEMENTO_PENDIENTE_VERIFICACION', $pending['motivoCondicionado']);
        $this->assertSame(0.0, $twoPending['costoEstimadoReferencia'] + $twoPending['valorCondicionado']);
        $this->assertStringContainsString('COMPLEMENTO_PENDIENTE_CON_VARIOS_PRECIOS: 147460 / 73333', $twoPending['motivoCondicionado']);
        $this->assertSame('COMPLEMENTO_VERIFICADO_FUERA_DE_VIGENCIA', $expired['motivoCondicionado']);
        $this->assertSame(0.0, $expired['costoEstimadoReferencia']);
        $this->assertSame(0.0, $cervix['costoEstimadoReferencia'] + $cervix['valorCondicionado']);
    }

    public function test_official_medication_list_prevails_over_a_verified_complement_and_complement_reaches_ium_lines(): void
    {
        $verified = ['estado_contractual' => 'VERIFICADO', 'evidencia_contractual' => 'Contrato', 'vigencia_desde' => '2026-01-01'];
        $complement = [$this->catalog('COMPLEMENTO_MEDICAMENTOS_PROSTATA', [
            $this->item('20195068-1', 'PROSTATA', 'MEDICAMENTO_COMPLEMENTARIO', 'ENZALUTAMIDA', 99999.0, $verified),
            $this->item('20999000-1', 'PROSTATA', 'MEDICAMENTO_COMPLEMENTARIO', 'DENOSUMAB', 1355147.0, $verified),
        ])];
        $cums = [
            $this->cum('20195068-1', 'ENZALUTAMIDA', 40, 'CAPSULA BLANDA', 120, 'No', '1E1033301002100'),
            $this->cum('20999000-1', 'DENOSUMAB', 120, 'SOLUCION INYECTABLE', 1, 'No', '1D1000000000100'),
        ];

        $listed = $this->matcher($cums, [], 'PROSTATA', $complement)->match($this->record('20195068-1', 'ENZALUTAMIDA', 1), 'PROSTATA');
        $byIum = $this->matcher($cums, [], 'PROSTATA', $complement)->match($this->record('1D1000000000100', 'DENOSUMAB', 1), 'PROSTATA');

        $this->assertSame('NOTA_TECNICA', $listed['fuenteTarifa']);
        $this->assertSame(46624.0, $listed['costoEstimadoReferencia']);
        $this->assertSame('COMPLEMENTO', $byIum['fuenteTarifa']);
        $this->assertSame('IUM_EXACTO', $byIum['formaCruce']);
        $this->assertSame(1355147.0, $byIum['costoEstimadoReferencia']);
    }

    public function test_conditioned_dispensation_policy_reports_the_administrative_price_without_adding_it(): void
    {
        $cums = [$this->cum('19932754-2', 'CLORURO DE SODIO', 900, 'SOLUCION INYECTABLE', 1, 'No', '1C1000000000100')];
        $dispensations = [$this->dispensation('19932754-2', '700', '2026-07-08', 2613.91, 1)];
        $record = $this->record('19932754-2', 'SODIO CLORURO', 3);

        $phaseOne = $this->matcher($cums, $dispensations)->match($record, 'PROSTATA');
        $conditioned = $this->matcher($cums, $dispensations, 'PROSTATA', [], PoliticaDispensacion::Condicionada)->match($record, 'PROSTATA');

        $this->assertSame('DISPENSACION', $phaseOne['fuenteTarifa']);
        $this->assertSame(7841.73, $phaseOne['costoEstimadoReferencia']);
        $this->assertSame('FASE_1', $phaseOne['politicaDispensacion']);
        $this->assertSame(0.0, $conditioned['costoEstimadoReferencia']);
        $this->assertSame(7841.73, $conditioned['valorCondicionado']);
        $this->assertSame('DISPENSACION_ADMINISTRATIVA_CONDICIONADA', $conditioned['motivoCondicionado']);

        // El escenario referencial es el mismo con ambas políticas: solo cambia si el precio suma al costo activo.
        foreach ([$phaseOne, $conditioned] as $match) {
            $this->assertSame(7841.73, $match['costoEscenarioReferencial']);
            $this->assertSame(2613.91, $match['tarifaEscenarioReferencial']);
            $this->assertSame('DISPENSACION', $match['fuenteEscenarioReferencial']);
        }
    }

    public function test_referential_scenario_never_prices_a_deduced_presentation_or_a_line_with_a_contractual_tariff(): void
    {
        $deduced = $this->matcher(
            [$this->cum('20085509-2', 'PEMBROLIZUMAB', 100, 'SOLUCION INYECTABLE', 2, 'No', '1P1040411000101')],
            [$this->dispensation('20085509-2', '700', '2026-07-08', 13763776.0)],
            'PROSTATA',
            [],
            PoliticaDispensacion::Condicionada
        )->match($this->record('1P1040411000101', 'PEMBROLIZUMAB', 2), 'PROSTATA');
        $contractual = $this->matcher(
            dispensations: [$this->dispensation('20137853-1', '700', '2026-07-08', 91046.0)],
            policy: PoliticaDispensacion::Condicionada
        )->match($this->record('20137853-1', 'APALUTAMIDA', 120), 'PROSTATA');

        $this->assertSame(0.0, $deduced['costoEscenarioReferencial']);
        $this->assertSame('', $deduced['fuenteEscenarioReferencial']);
        // El precio del mismo paciente y mes queda como externo potencial, nunca como costo.
        $this->assertSame(0.0, $deduced['costoEstimadoReferencia']);
        $this->assertSame(27527552.0, $deduced['valorCondicionado']);
        $this->assertSame('PRESENTACION_DEDUCIDA_PRECIO_ADMINISTRATIVO_MISMO_PACIENTE', $deduced['motivoCondicionado']);
        $this->assertSame('100 mg / SOLUCION INYECTABLE', $deduced['presentacionCum']);
        $this->assertSame(87967.0 * 120, $contractual['costoEscenarioReferencial']);
        $this->assertSame('NOTA_TECNICA', $contractual['fuenteEscenarioReferencial']);
    }

    public function test_presentation_deduced_from_ium_does_not_inherit_the_administrative_dispensation_price(): void
    {
        $matcher = $this->matcher(
            [$this->cum('20085509-2', 'PEMBROLIZUMAB', 100, 'SOLUCION INYECTABLE', 2, 'No', '1P1040411000101')],
            [$this->dispensation('20085509-2', '700', '2026-07-08', 13763776.0)],
            'CERVIX'
        );

        $match = $matcher->match($this->record('1P1040411000101', 'PEMBROLIZUMAB', 2), 'CERVIX');

        $this->assertSame('IUM_EXACTO', $match['metodoResolucion']);
        $this->assertSame('20085509-2', $match['cumResuelto']);
        $this->assertSame('NINGUNA', $match['fuenteTarifa']);
        $this->assertSame(0.0, $match['costoEstimadoReferencia']);
    }

    public function test_note_and_dispensation_never_price_the_same_line_twice(): void
    {
        $matcher = $this->matcher(dispensations: [$this->dispensation('20137853-1', '700', '2026-07-08', 91046.0)]);

        $match = $matcher->match($this->record('20137853-1', 'APALUTAMIDA', 120), 'PROSTATA');

        $this->assertSame('NOTA_TECNICA', $match['fuenteTarifa']);
        $this->assertSame(87967.0 * 120, $match['costoEstimadoReferencia']);

        $samePrice = $this->matcher(dispensations: [$this->dispensation('20137853-1', '700', '2026-07-08', 87967.0)])
            ->match($this->record('20137853-1', 'APALUTAMIDA', 120), 'PROSTATA');

        $this->assertSame('NOTA_TECNICA', $samePrice['fuenteTarifa']);
        $this->assertSame('TARIFA_UNITARIA_MEDICAMENTO', $samePrice['tipoValorReferencia']);
    }

    public function test_radiotherapy_treatment_rate_is_not_applied_to_rips_lines_yet(): void
    {
        $matcher = new ReferenciaRutaMatcher(new Collection([
            $this->catalog('NOTA_PROSTATA', [
                $this->item('922444', 'PROSTATA', 'ONCOLOGIA_RADIOTERAPIA', 'TELETERAPIA', 8852976.0),
            ]),
        ]));

        $match = $matcher->match(['codigo' => '922444', 'tipoServicio' => 'procedimientos', 'cantidad' => 1, 'valorRips' => 0], 'PROSTATA');

        $this->assertSame('TARIFA_TRATAMIENTO_PENDIENTE', $match['tipoValorReferencia']);
        $this->assertSame('SIN_TARIFA', $match['estadoTarifario']);
        $this->assertSame(0.0, $match['costoEstimadoReferencia']);
    }

    /**
     * @param  list<CatalogoReferenciaItem>|null  $cums
     * @param  list<CatalogoReferenciaItem>  $dispensations
     */
    private function matcher(?array $cums = null, array $dispensations = [], string $route = 'PROSTATA', array $extraCatalogs = [], ?PoliticaDispensacion $policy = null): ReferenciaRutaMatcher
    {
        return new ReferenciaRutaMatcher(new Collection(array_values(array_filter([
            ...$extraCatalogs,
            $this->catalog('MEDICAMENTOS_CUM', $cums ?? [
                $this->cum('20137853-1', 'APALUTAMIDA', 60, 'TABLETA RECUBIERTA', 120, 'No', '1A1027731000100'),
                $this->cum('20254463-1', 'APALUTAMIDA', 240, 'TABLETA RECUBIERTA', 30, 'No', '1A1060601000100'),
                $this->cum('20254463-2', 'APALUTAMIDA', 240, 'TABLETA RECUBIERTA', 30, 'Si', '1A1060601000101'),
                $this->cum('20195068-1', 'ENZALUTAMIDA', 40, 'CAPSULA BLANDA', 120, 'No', '1E1033301002100'),
                $this->cum('20178073-1', 'ENZALUTAMIDA', 80, 'TABLETA RECUBIERTA', 56, 'No', '1E1033861000100'),
            ]),
            $this->catalog('NOTA_MEDICAMENTOS', [
                $this->item('20254463-1', 'PROSTATA', 'MEDICAMENTO_NT', 'APALUTAMIDA 240 MG TABLETA RECUBIERTA', 339686.0, ['principio_activo' => 'APALUTAMIDA']),
                $this->item('20137853-1', 'PROSTATA', 'MEDICAMENTO_NT', 'APALUTAMIDA 60 MG TABLETA RECUBIERTA', 87967.0, ['principio_activo' => 'APALUTAMIDA']),
                $this->item('20195068-1', 'PROSTATA', 'MEDICAMENTO_NT', 'ENZALUTAMIDA 40 MG CAPSULA BLANDA', 46624.0, ['principio_activo' => 'ENZALUTAMIDA']),
                $this->item('20178073-1', 'PROSTATA', 'MEDICAMENTO_NT', 'ENZALUTAMIDA 80 MG TABLETA RECUBIERTA', 187033.6, ['principio_activo' => 'ENZALUTAMIDA']),
            ]),
            $dispensations === [] ? null : $this->catalog('DISPENSACION_'.$route, $dispensations),
        ]))), null, $policy);
    }

    /**
     * Registro con la familia 1E10333010 (enzalutamida 40 mg cápsula blanda) y
     * otras presentaciones del principio, como en el registro INVIMA.
     *
     * @param  list<CatalogoReferenciaItem>  $dispensations
     * @param  list<CatalogoReferenciaItem>  $extraCums
     */
    private function familyMatcher(array $dispensations, array $extraCums = []): ReferenciaRutaMatcher
    {
        return $this->matcher([
            $this->cum('20195068-1', 'ENZALUTAMIDA', 40, 'CAPSULA BLANDA', 120, 'No', '1E1033301002100'),
            $this->cum('20198992-1', 'ENZALUTAMIDA', 40, 'CAPSULA BLANDA', 120, 'No', '1E1033301001100'),
            $this->cum('20224710-1', 'ENZALUTAMIDA', 40, 'TABLETA RECUBIERTA', 112, 'No', '1E1033871000100'),
            $this->cum('20178073-1', 'ENZALUTAMIDA', 80, 'TABLETA RECUBIERTA', 56, 'No', '1E1033861000100'),
            ...$extraCums,
        ], $dispensations);
    }

    /**
     * @return array<string, mixed>
     */
    private function record(string $code, string $name, int $quantity): array
    {
        return [
            'codigo' => $code,
            'tipoServicio' => 'medicamentos',
            'nombreServicio' => $name,
            'cantidad' => $quantity,
            'numDocumentoIdentificacion' => '700',
            'fechaAtencion' => '2026-07-10 09:00',
            'valorRips' => 0,
        ];
    }

    private function cum(string $code, string $principio, float $concentration, string $form, int $package, string $sample, string $ium): CatalogoReferenciaItem
    {
        return $this->item($code, null, 'CUM', $principio, null, [
            'principio_activo' => $principio,
            'cantidad' => $concentration,
            'unidad_medida' => 'mg',
            'forma_farmaceutica' => $form,
            'cantidad_cum' => $package,
            'muestra_medica' => $sample,
            'ium' => $ium,
        ]);
    }

    private function dispensation(string $code, string $document, string $date, float $rate, ?float $units = null): CatalogoReferenciaItem
    {
        return $this->item($code, 'PROSTATA', 'DISPENSACION_ADMINISTRATIVA', 'DISPENSADO', $rate, [
            'documento_paciente' => $document,
            'fecha_dispensacion' => $date,
            'fila' => 10,
            ...($units === null ? [] : ['unidades_dispensadas' => $units]),
        ]);
    }

    /**
     * @param  array<int, CatalogoReferenciaItem>  $items
     */
    private function catalog(string $type, array $items): CatalogoReferencia
    {
        $catalog = new CatalogoReferencia([
            'tipo' => $type,
            'version' => '2026-01',
            'archivo_origen' => strtolower($type).'.xlsx',
            'activo' => true,
        ]);
        $catalog->id = $this->nextCatalogId++;
        $catalog->setRelation('items', new Collection($items));

        return $catalog;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function item(string $code, ?string $route, string $category, string $description, ?float $rate = null, array $metadata = []): CatalogoReferenciaItem
    {
        return new CatalogoReferenciaItem([
            'codigo' => $code,
            'ruta' => $route,
            'categoria' => $category,
            'descripcion' => $description,
            'tarifa_referencia' => $rate,
            'metadatos' => $metadata,
        ]);
    }
}
