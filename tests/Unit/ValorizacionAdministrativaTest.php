<?php

namespace Tests\Unit;

use App\Programas\LaMaria\HistorialAdministrativo;
use App\Programas\LaMaria\ValorizacionAdministrativa;
use PHPUnit\Framework\TestCase;

class ValorizacionAdministrativaTest extends TestCase
{
    private const ESQUEMA = 'Abiraterona, apalutamida, enzalutamida';

    public function test_integral_scheme_requires_temporal_link_within_the_same_invoice(): void
    {
        $valorizacion = $this->valorizacionCervix(static fn (string $cum): array => []);
        $caso = ['mesAtencion' => '2026-10', 'casoId' => 'C1', 'factura' => 'F1'];

        $valorizacion->registrar($this->detalle('procedimientos', '992511', 1.0, [...$caso, 'fechaAtencion' => '2026-10-03 08:00']), ['respaldado' => 444690.0]);
        $valorizacion->registrar($this->detalle('procedimientos', '992511', 1.0, [...$caso, 'fechaAtencion' => '2026-10-23 08:00']), ['respaldado' => 444690.0]);
        $valorizacion->registrar($this->detalle('medicamentos', 'PEMBRO', 1.0, [...$caso,
            'fechaAtencion' => '2026-10-03 09:00', 'principioActivo' => 'PEMBROLIZUMAB',
            'cumResuelto' => 'CUM-PEMBRO']), ['respaldado' => 0.0]);

        $tratamientos = $valorizacion->filasTratamientos();
        $this->assertSame(34000000.0, array_sum(array_column($tratamientos, 8)));
        $precios = array_values(array_unique(array_column($tratamientos, 6)));
        sort($precios);
        $this->assertSame([2000000.0, 32000000.0], $precios);
        $this->assertContains('PRECIO BASE PROVISIONAL; MEDICAMENTO DISTINTIVO EN OTRA FECHA DE LA FACTURA', array_column($tratamientos, 3));
        $this->assertContains('MEDICAMENTO DISTINTIVO EN LA FECHA DEL AP (PEMB)', array_column($tratamientos, 3));
        $this->assertSame(ValorizacionAdministrativa::COBERTURA_INCLUIDO_AP,
            collect($valorizacion->filasCoberturaMedicamentos())->firstWhere(6, 'CUM-PEMBRO')[16]);
    }

    public function test_session_with_its_own_medication_joins_the_scheme_series_of_another_integral_in_the_invoice(): void
    {
        $valorizacion = $this->valorizacionCervix(static fn (string $cum): array => []);
        $caso = ['mesAtencion' => '2026-10', 'casoId' => 'C1', 'factura' => 'F1'];
        $medicamento = fn (string $fecha, string $principio, string $cum): array => $this->detalle('medicamentos', $cum, 1.0,
            [...$caso, 'fechaAtencion' => $fecha.' 09:00', 'principioActivo' => $principio, 'cumResuelto' => $cum]);

        foreach (['2026-10-02', '2026-10-09', '2026-10-22'] as $fecha) {
            $valorizacion->registrar($this->detalle('procedimientos', '992511', 1.0, [...$caso, 'fechaAtencion' => $fecha.' 08:00']), ['respaldado' => 444690.0]);
        }

        $valorizacion->registrar($medicamento('2026-10-02', 'CARBOPLATINO', 'CUM-CARBO'), ['respaldado' => 0.0]);
        $valorizacion->registrar($medicamento('2026-10-09', 'PACLITAXEL', 'CUM-PACLI'), ['respaldado' => 0.0]);
        $valorizacion->registrar($medicamento('2026-10-22', 'PEMBROLIZUMAB', 'CUM-PEMBRO'), ['respaldado' => 0.0]);

        $tratamientos = collect($valorizacion->filasTratamientos())->keyBy(3);

        // La sesión con carboplatino pertenece a la serie con pembrolizumab; el distintivo propio de la base (paclitaxel) la conserva.
        $this->assertSame([32000000.0, 0.0], [$tratamientos['DISTINTIVO EN OTRA SESION INTEGRAL DEL MISMO CUPS Y FACTURA (PEMB): SERIE DEL ESQUEMA'][8],
            $tratamientos['DISTINTIVO EN OTRA SESION INTEGRAL DEL MISMO CUPS Y FACTURA (PEMB): SERIE DEL ESQUEMA'][9]]);
        $this->assertSame(32000000.0, $tratamientos['MEDICAMENTO DISTINTIVO EN LA FECHA DEL AP (PEMB)'][8]);
        $this->assertSame(2000000.0, $tratamientos['MEDICAMENTO DISTINTIVO EN LA FECHA DEL AP (PACL)'][8]);
        $this->assertSame(66000000.0, $tratamientos->sum(8));
        $this->assertSame(0.0, $tratamientos->sum(9));
        $this->assertSame(ValorizacionAdministrativa::COBERTURA_INCLUIDO_AP,
            collect($valorizacion->filasCoberturaMedicamentos())->firstWhere(6, 'CUM-PEMBRO')[16]);
    }

    public function test_session_without_temporal_link_keeps_provisional_base_and_conditions_only_the_scheme_difference(): void
    {
        $valorizacion = $this->valorizacionCervix(static fn (string $cum): array => [['mes' => '2026-09', 'tarifa' => 9000000.0]]);
        $caso = ['mesAtencion' => '2026-10', 'casoId' => 'C1', 'factura' => 'F1'];

        $valorizacion->registrar($this->detalle('procedimientos', '992511', 1.0, [...$caso, 'fechaAtencion' => '2026-10-09 08:00']), ['respaldado' => 444690.0]);

        foreach (['2026-10-04', '2026-10-29'] as $fecha) {
            $valorizacion->registrar($this->detalle('medicamentos', 'PEMBRO', 1.0, [...$caso, 'fechaAtencion' => $fecha.' 09:00',
                'principioActivo' => 'PEMBROLIZUMAB', 'cumResuelto' => 'CUM-PEMBRO']), ['respaldado' => 0.0]);
        }

        [$tratamiento] = $valorizacion->filasTratamientos();
        $this->assertStringStartsWith('ESQUEMA INDETERMINADO · ', $tratamiento[2]);
        $this->assertSame(['PRECIO BASE PROVISIONAL; MEDICAMENTO DISTINTIVO EN OTRA FECHA DE LA FACTURA', 2000000.0, 30000000.0],
            [$tratamiento[3], $tratamiento[8], $tratamiento[9]]);

        // El pembrolizumab de otras fechas no suma ni agrega otro potencial: su valor ya está en la diferencia condicionada.
        $coberturas = collect($valorizacion->filasCoberturaMedicamentos())->where(6, 'CUM-PEMBRO');
        $this->assertSame([ValorizacionAdministrativa::COBERTURA_CONDICIONADO_AP], $coberturas->pluck(16)->unique()->values()->all());
        $this->assertSame(0.0, $coberturas->sum(18));
        $resumen = collect($valorizacion->resumenServicios('2026-10')['filas'])->keyBy(0);
        $this->assertSame([2000000.0, 30000000.0], [$resumen['AP'][4], $resumen['AP'][6]]);
        $this->assertSame([0.0, 0.0], [$resumen['AM'][4], $resumen['AM'][6]]);
        $this->assertSame('Vínculo de la sesión con el esquema de mayor precio registrado en otra fecha de la factura (orden o protocolo de la sesión).',
            collect($valorizacion->filasPendientes())->firstWhere(4, ValorizacionAdministrativa::CONDICIONADA.' / ESQUEMA INDETERMINADO')[10]);
    }

    public function test_hospital_estimate_uses_reconstructed_blocks_and_keeps_cost_a_packages(): void
    {
        $valorizacion = $this->valorizacion();
        $estancia = fn (string $mes, string $ingreso, string $egreso, string $destino): array => $this->detalle('hospitalizacion', '', 1.0,
            ['mesAtencion' => $mes, 'fechaAtencion' => $ingreso, 'servicioJson' => ['fechaEgreso' => $egreso, 'condicionDestinoUsuarioEgreso' => $destino]]);

        // Septiembre: estancia de 30 días (2 paquetes), menor de 24 h (sin paquete) y corte 08 (1 paquete por mes independiente).
        $valorizacion->registrar($estancia('2026-09', '2026-09-01 08:00', '2026-09-30 10:00', '01'), ['respaldado' => 0.0]);
        $valorizacion->registrar($estancia('2026-09', '2026-09-10 08:00', '2026-09-10 20:00', '01'), ['respaldado' => 0.0]);
        $valorizacion->registrar($estancia('2026-09', '2026-09-20 08:00', '2026-09-30 23:59', '08'), ['respaldado' => 0.0]);
        // Octubre: solo la continuación del corte, que egresa; su mes se valora con sus propios registros.
        $valorizacion->registrar($estancia('2026-10', '2026-10-01 00:00', '2026-10-05 10:00', '01'), ['respaldado' => 0.0]);
        $valorizacion->atribuirPaquetes([['2026-09', 3, 18045943.59], ['2026-10', 1, 6015314.53], ['TOTAL', 4, 24061258.12]]);

        $septiembre = collect($valorizacion->resumenServicios('2026-09')['filas'])->keyBy(0);
        $octubre = collect($valorizacion->resumenServicios('2026-10')['filas'])->keyBy(0);

        $this->assertSame([3, 18045943.59, 18045943.59, 0.0], array_map(static fn (int $i): mixed => $septiembre['AH'][$i], [2, 3, 4, 6]));
        $this->assertSame([1, 6015314.53, 6015314.53], array_map(static fn (int $i): mixed => $octubre['AH'][$i], [2, 3, 4]));
        $this->assertStringContainsString('RN-16', $septiembre['AH'][7]);
        $estados = collect($valorizacion->filasDetalle())->where(1, 'AH')->pluck(6)->unique()->sort()->values()->all();
        $this->assertSame(['BLOQUE HOSPITALARIO DE 25 DIAS x VALOR DEL PAQUETE', 'CORTE DE ESTANCIA (CONTINUA)', 'REGISTRO DE INTERNACION CON EGRESO'], $estados);
        $pendiente = collect($valorizacion->filasPendientes())->first(static fn (array $fila): bool => $fila[0] === '2026-09' && $fila[2] === 'REGISTRO_INTERNACION_EGRESO');
        $this->assertSame([3.0, 18045943.59], [$pendiente[6], $pendiente[7]]);
        $this->assertStringContainsString('3 bloques reconstruidos de 25 días', $pendiente[9]);
        $this->assertStringContainsString('1 cortes 08 son trazas clínicas', $pendiente[9]);
    }

    public function test_integral_scheme_requires_a_shared_invoice_and_keeps_other_invoice_medication_conditional(): void
    {
        $historial = new HistorialAdministrativo;

        foreach (['2026-06', '2026-07', '2026-08'] as $mes) {
            $historial->registrar('PROSTATA', $mes, 'AP', '992505', 1000.0, '', 'MEDALFA');
            $historial->registrar('PROSTATA', $mes, 'AP', '992505', 200.0, '', 'MEDBETA');
        }

        $nota = [['codigo' => '992505', 'categoria' => 'ONCOLOGIA_QUIMIOTERAPIA', 'tarifaReferencia' => 100.0,
            'metadatos' => ['tarifa_medicamento' => 100.0, 'esquema' => 'Medalfa o Medbeta']]];
        $valorizacion = new ValorizacionAdministrativa('PROSTATA', $historial, $nota, static fn (string $cum): array => []);

        foreach ([
            [$this->detalle('procedimientos', '992505', 1.0, ['casoId' => 'P1', 'factura' => 'F1']), ['respaldado' => 100.0]],
            [$this->detalle('medicamentos', 'MEDALFA', 1.0, ['casoId' => 'P1', 'factura' => 'F1',
                'principioActivo' => 'MEDALFA', 'esquemaOncologico' => 'Medalfa', 'cumResuelto' => 'MEDALFA']), ['respaldado' => 500.0]],
            [$this->detalle('procedimientos', '992505', 1.0, ['casoId' => 'P2', 'factura' => 'F2']), ['respaldado' => 100.0]],
            [$this->detalle('medicamentos', 'MEDALFA', 1.0, ['casoId' => 'P2', 'factura' => 'F3',
                'principioActivo' => 'MEDALFA', 'esquemaOncologico' => 'Medalfa', 'cumResuelto' => 'MEDALFA']), ['respaldado' => 500.0]],
        ] as [$detalle, $economico]) {
            $valorizacion->registrar($detalle, $economico);
        }

        $tratamientos = $valorizacion->filasTratamientos();
        $cobertura = collect($valorizacion->filasCoberturaMedicamentos())->pluck(16, 1);
        $this->assertSame(1200.0, array_sum(array_column($tratamientos, 8)));
        $this->assertSame(0.0, collect($valorizacion->filasComparacion([]))->firstWhere(1, 'AM')[5]);
        $this->assertContains('ASOCIACION SIN FACTURA COMPARTIDA', array_column($valorizacion->filasDetalle(), 6));
        // MEDALFA distingue la clase de $1.000 aplicada al integral de su misma factura: cobertura demostrada.
        $this->assertContains('B · INCLUIDO EN TRATAMIENTO INTEGRAL 992505', array_column($valorizacion->filasDetalle(), 6));
        $this->assertSame(['F1' => ValorizacionAdministrativa::COBERTURA_INCLUIDO_AP, 'F3' => ValorizacionAdministrativa::COBERTURA_CONDICIONADO_AP], $cobertura->all());
    }

    public function test_rn14_classifies_medication_in_any_route_and_month_from_the_price_class_evidence(): void
    {
        $historial = new HistorialAdministrativo;

        foreach (['2026-07', '2026-08', '2026-09'] as $mes) {
            for ($i = 0; $i < 3; $i++) {
                $historial->registrar('CERVIX', $mes, 'AP', '992511', 32000000.0, '', 'PEMBROLIZUMAB + CARBOPLATINO');
            }

            for ($i = 0; $i < 7; $i++) {
                $historial->registrar('CERVIX', $mes, 'AP', '992511', 2000000.0, '', 'CARBOPLATINO + PACLITAXEL');
            }
        }

        foreach (['2026-03', '2026-04', '2026-05'] as $mes) {
            $historial->registrar('PROSTATA', $mes, 'AP', '992505', 7965668.98, '', 'ENZALUTAMIDA');
            $historial->registrar('PROSTATA', $mes, 'AP', '992505', 7965668.98, '', 'APALUTAMIDA');
        }

        $precios = static fn (string $cum): array => match ($cum) {
            'CUM-CARBO' => [['mes' => '2026-09', 'tarifa' => 100.0]],
            'CUM-ENZA' => [['mes' => '2026-05', 'tarifa' => 46624.0]],
            default => [],
        };
        $nota = [
            ['codigo' => '992511', 'categoria' => 'ONCOLOGIA_QUIMIOTERAPIA', 'tarifaReferencia' => 444690.0, 'metadatos' => ['tarifa_medicamento' => 1431182.14, 'esquema' => 'Pembrolizumab, carboplatino']],
            ['codigo' => '992505', 'categoria' => 'ONCOLOGIA_QUIMIOTERAPIA', 'tarifaReferencia' => 444690.0, 'metadatos' => ['tarifa_medicamento' => 7520978.98, 'esquema' => self::ESQUEMA]],
        ];
        $cervix = new ValorizacionAdministrativa('CERVIX', $historial, $nota, $precios);
        $octubre = ['mesAtencion' => '2026-10', 'casoId' => 'C1', 'factura' => 'F1', 'esquemaOncologico' => 'Pembrolizumab, carboplatino', 'resolucionMedicamento' => 'RESUELTO'];

        foreach ([
            [$this->detalle('procedimientos', '992511', 1.0, $octubre), ['respaldado' => 444690.0]],
            [$this->detalle('medicamentos', 'PEMBRO', 2.0, [...$octubre, 'principioActivo' => 'PEMBROLIZUMAB', 'cumResuelto' => 'CUM-PEMBRO']), ['respaldado' => 27527552.0]],
            [$this->detalle('medicamentos', 'CARBO', 3.0, [...$octubre, 'principioActivo' => 'CARBOPLATINO', 'cumResuelto' => 'CUM-CARBO']), ['respaldado' => 900.0]],
        ] as [$detalle, $economico]) {
            $cervix->registrar($detalle, $economico);
        }

        $prostata = new ValorizacionAdministrativa('PROSTATA', $historial, $nota, $precios);
        $junio = ['mesAtencion' => '2026-06', 'casoId' => 'P1', 'factura' => 'F9', 'esquemaOncologico' => self::ESQUEMA, 'resolucionMedicamento' => 'RESUELTO'];
        $prostata->registrar($this->detalle('procedimientos', '992505', 1.0, $junio), ['respaldado' => 444690.0]);
        $prostata->registrar($this->detalle('medicamentos', 'ENZA', 30.0, [...$junio, 'principioActivo' => 'ENZALUTAMIDA', 'cumResuelto' => 'CUM-ENZA']), ['respaldado' => 1000000.0]);

        $coberturaCervix = collect($cervix->filasCoberturaMedicamentos())->keyBy(6);
        $coberturaProstata = collect($prostata->filasCoberturaMedicamentos())->keyBy(6);
        $amCervix = collect($cervix->filasComparacion([]))->firstWhere(1, 'AM');

        // Pembrolizumab distingue la clase de $32.000.000 aplicada: incluido demostrado. Carboplatino está en
        // ambas clases (no distintivo): inclusión incierta, condicionado con su precio histórico, sin sumar.
        $this->assertSame([ValorizacionAdministrativa::COBERTURA_INCLUIDO_AP, '992511', 0.0], [$coberturaCervix['CUM-PEMBRO'][16], $coberturaCervix['CUM-PEMBRO'][12], $coberturaCervix['CUM-PEMBRO'][18]]);
        $this->assertSame([ValorizacionAdministrativa::COBERTURA_CONDICIONADO_AP, 100.0, 300.0], [$coberturaCervix['CUM-CARBO'][16], $coberturaCervix['CUM-CARBO'][17], $coberturaCervix['CUM-CARBO'][18]]);
        $this->assertSame(['2026-10', 'F1'], [$coberturaCervix['CUM-CARBO'][0], $coberturaCervix['CUM-CARBO'][1]]);
        $this->assertSame([0.0, 300.0], [$amCervix[5], $amCervix[6]]);
        $this->assertSame(32000000.0, collect($cervix->filasComparacion([]))->firstWhere(1, 'AP')[5]);

        // Próstata junio con precio único del CUPS: la misma regla RN-14, sin depender de septiembre.
        $this->assertSame([ValorizacionAdministrativa::COBERTURA_CONDICIONADO_AP, round(30 * 46624.0, 2)], [$coberturaProstata['CUM-ENZA'][16], $coberturaProstata['CUM-ENZA'][18]]);
        $this->assertSame(ValorizacionAdministrativa::SUBTIPO_RN14_AP, collect($prostata->filasDetalle())->firstWhere(2, 'ENZA')[6]);
        $this->assertSame(0.0, collect($prostata->filasComparacion([]))->firstWhere(1, 'AM')[5]);
    }

    public function test_rn14_also_evaluates_medication_without_oncology_scheme_and_never_counts_it_twice(): void
    {
        $valorizacion = $this->valorizacionCervix(static fn (string $cum): array => [['mes' => '2026-09', 'tarifa' => 100.0]]);
        $caso = ['mesAtencion' => '2026-10', 'casoId' => 'C1', 'factura' => 'F1', 'resolucionMedicamento' => 'RESUELTO'];
        $valorizacion->registrar($this->detalle('procedimientos', '992511', 1.0, $caso), ['respaldado' => 444690.0]);
        // Sin esquemaOncologico (la nota no nombra el medicamento), pero sus prefijos eligen la clase del integral.
        $valorizacion->registrar($this->detalle('medicamentos', 'PEMBRO', 2.0, [...$caso, 'principioActivo' => 'PEMBROLIZUMAB', 'cumResuelto' => 'CUM-PEMBRO']), ['respaldado' => 0.0]);
        $valorizacion->registrar($this->detalle('medicamentos', 'CARBO', 3.0, [...$caso, 'principioActivo' => 'CARBOPLATINO', 'cumResuelto' => 'CUM-CARBO']), ['respaldado' => 0.0]);
        $valorizacion->registrar($this->detalle('medicamentos', 'SUERO', 2.0, [...$caso, 'principioActivo' => 'SODIO CLORURO', 'cumResuelto' => 'CUM-SUERO']), ['respaldado' => 0.0]);

        $cobertura = collect($valorizacion->filasCoberturaMedicamentos())->keyBy(6);
        $comparacion = collect($valorizacion->filasComparacion([]));

        $this->assertSame([ValorizacionAdministrativa::COBERTURA_INCLUIDO_AP, 0.0], [$cobertura['CUM-PEMBRO'][16], $cobertura['CUM-PEMBRO'][18]]);
        $this->assertSame([ValorizacionAdministrativa::COBERTURA_CONDICIONADO_AP, 300.0], [$cobertura['CUM-CARBO'][16], $cobertura['CUM-CARBO'][18]]);
        $this->assertSame(ValorizacionAdministrativa::COBERTURA_INDEPENDIENTE, $cobertura['CUM-SUERO'][16], 'Un AM ajeno a la composición conserva su propio precio.');
        $this->assertSame(32000000.0, $comparacion->firstWhere(1, 'AP')[5]);
        $this->assertSame([200.0, 300.0], [$comparacion->firstWhere(1, 'AM')[5], $comparacion->firstWhere(1, 'AM')[6]], 'Solo suma el suero; pembrolizumab ya está en el AP.');
    }

    public function test_medication_already_in_the_scheme_mixture_adds_no_second_conditioned_value(): void
    {
        $historial = new HistorialAdministrativo;

        foreach (['2026-07', '2026-08', '2026-09'] as $mes) {
            foreach ([[3, 32000000.0, 'PEMBROLIZUMAB + CARBOPLATINO'], [5, 2000000.0, 'CARBOPLATINO + PACLITAXEL'], [2, 8000000.0, 'BEVACIZUMAB + CARBOPLATINO']] as [$veces, $valor, $texto]) {
                for ($i = 0; $i < $veces; $i++) {
                    $historial->registrar('CERVIX', $mes, 'AP', '992511', $valor, '', $texto);
                }
            }
        }

        $valorizacion = new ValorizacionAdministrativa('CERVIX', $historial, $this->notaCervix(), static fn (string $cum): array => [['mes' => '2026-09', 'tarifa' => 1000000.0]]);
        $caso = ['mesAtencion' => '2026-10', 'casoId' => 'C1', 'factura' => 'F1', 'esquemaOncologico' => 'Pembrolizumab, bevacizumab', 'resolucionMedicamento' => 'RESUELTO'];
        $valorizacion->registrar($this->detalle('procedimientos', '992511', 1.0, $caso), ['respaldado' => 444690.0]);
        $valorizacion->registrar($this->detalle('medicamentos', 'PEMBRO', 2.0, [...$caso, 'principioActivo' => 'PEMBROLIZUMAB', 'cumResuelto' => 'CUM-PEMBRO']), ['respaldado' => 0.0]);
        $valorizacion->registrar($this->detalle('medicamentos', 'BEVA', 2.0, [...$caso, 'principioActivo' => 'BEVACIZUMAB', 'cumResuelto' => 'CUM-BEVA']), ['respaldado' => 0.0]);

        $comparacion = collect($valorizacion->filasComparacion([]));
        $mezcla = collect($valorizacion->filasDetalle())->firstWhere(6, 'MEZCLA HISTORICA DE ESQUEMAS');
        $cobertura = collect($valorizacion->filasCoberturaMedicamentos())->keyBy(6);

        // Varios esquemas posibles: AP base + mezcla condicionada; los medicamentos de las clases mezcladas no repiten su valor.
        $this->assertSame(2000000.0, $comparacion->firstWhere(1, 'AP')[5]);
        $this->assertSame(10200000.0, $mezcla[18]);
        $this->assertSame([ValorizacionAdministrativa::COBERTURA_CONDICIONADO_AP, 0.0], [$cobertura['CUM-PEMBRO'][16], $cobertura['CUM-PEMBRO'][18]]);
        $this->assertSame(0.0, $cobertura['CUM-BEVA'][18]);
        $this->assertSame([0.0, 0.0], [$comparacion->firstWhere(1, 'AM')[5], $comparacion->firstWhere(1, 'AM')[6]]);
        $this->assertSame(10200000.0, $comparacion->firstWhere(1, 'TOTAL')[6], 'El condicionado total es solo la mezcla.');
    }

    public function test_integral_of_the_same_patient_in_another_invoice_keeps_its_medication_conditioned(): void
    {
        $historial = new HistorialAdministrativo;

        foreach (['2026-07', '2026-08', '2026-09'] as $mes) {
            $historial->registrar('CERVIX', $mes, 'AP', '992511', 2000000.0, '', 'CARBOPLATINO + PACLITAXEL');
            $historial->registrar('CERVIX', $mes, 'AP', '992504', 6611663.25, '', 'BEVACIZUMAB/PACLITAXEL');
        }

        $valorizacion = new ValorizacionAdministrativa('CERVIX', $historial, $this->notaCervix(), static fn (string $cum): array => []);
        $base = ['mesAtencion' => '2026-10', 'casoId' => 'C1', 'esquemaOncologico' => 'Bevacizumab', 'resolucionMedicamento' => 'RESUELTO'];
        $valorizacion->registrar($this->detalle('procedimientos', '992504', 1.0, [...$base, 'factura' => 'F2']), ['respaldado' => 444690.0]);
        $valorizacion->registrar($this->detalle('procedimientos', '992511', 1.0, [...$base, 'factura' => 'F1']), ['respaldado' => 444690.0]);
        $valorizacion->registrar($this->detalle('medicamentos', 'BEVA', 1.0, [...$base, 'factura' => 'F1', 'principioActivo' => 'BEVACIZUMAB', 'cumResuelto' => 'CUM-BEVA']), ['respaldado' => 612563.0]);

        $detalle = collect($valorizacion->filasDetalle())->firstWhere(2, 'BEVA');
        $cobertura = collect($valorizacion->filasCoberturaMedicamentos())->firstWhere(6, 'CUM-BEVA');

        // El 992504 de la otra factura ya lleva bevacizumab en su precio: no se suma como otro esquema.
        $this->assertSame(['ASOCIACION SIN FACTURA COMPARTIDA', 'NO', 0.0, 612563.0], [$detalle[6], $detalle[5], $detalle[17], $detalle[18]]);
        $this->assertSame([ValorizacionAdministrativa::COBERTURA_CONDICIONADO_AP, '992504'], [$cobertura[16], $cobertura[12]]);
        $this->assertSame(0.0, collect($valorizacion->filasComparacion([]))->firstWhere(1, 'AM')[5]);
    }

    public function test_urgency_absorbed_medication_and_included_lines_keep_a_coherent_coverage(): void
    {
        $valorizacion = $this->valorizacionCervix(static fn (string $cum): array => $cum === 'CUM-X' ? [['mes' => '2026-09', 'tarifa' => 10.0]] : []);
        $caso = ['mesAtencion' => '2026-10', 'casoId' => 'C1', 'factura' => 'F1', 'resolucionMedicamento' => 'RESUELTO'];
        $valorizacion->registrar($this->detalle('medicamentos', 'MEDX', 3.0, [...$caso, 'decisionPaquete' => 'INCLUIDO_EN_URGENCIA', 'cumResuelto' => 'CUM-X']), ['respaldado' => 0.0]);
        $valorizacion->registrar($this->detalle('procedimientos', '992511', 1.0, $caso), ['respaldado' => 444690.0]);
        // Pembrolizumab con solo precio de dispensación del mismo mes: incluido en el AP, sin potencial residual.
        $valorizacion->registrar($this->detalle('medicamentos', 'PEMBRO', 2.0, [...$caso, 'principioActivo' => 'PEMBROLIZUMAB', 'cumResuelto' => 'CUM-SIN-HISTORIA',
            'fuenteEscenarioReferencial' => 'DISPENSACION']), ['respaldado' => 0.0, 'referencialAdicional' => 5000000.0]);

        $cobertura = collect($valorizacion->filasCoberturaMedicamentos())->keyBy(6);

        $this->assertSame([ValorizacionAdministrativa::COBERTURA_INCLUIDO_URGENCIA, 0.0], [$cobertura['CUM-X'][16], $cobertura['CUM-X'][18]]);
        $this->assertSame([ValorizacionAdministrativa::COBERTURA_INCLUIDO_AP, 0.0], [$cobertura['CUM-SIN-HISTORIA'][16], $cobertura['CUM-SIN-HISTORIA'][18]]);
        $this->assertSame([0.0, 0.0], [collect($valorizacion->filasComparacion([]))->firstWhere(1, 'AM')[5], collect($valorizacion->filasComparacion([]))->firstWhere(1, 'AM')[6]]);
    }

    public function test_short_stay_without_accredited_urgency_tariff_keeps_components_conditional_and_does_not_duplicate_a_billed_urgency(): void
    {
        $history = new HistorialAdministrativo;
        foreach (['2026-06', '2026-07', '2026-08'] as $month) {
            $history->registrar('CERVIX', $month, 'AC', '890701', 40195.61);
            $history->registrar('CERVIX', $month, 'AC', '890702', 247400.0);
            $history->registrar('CERVIX', $month, 'AP', '123456', 200.0);
            $history->registrar('CERVIX', $month, 'AP', '654321', 500.0);
            $history->registrar('CERVIX', $month, 'AT', 'T34004', 100.0);
        }
        $valuacion = new ValorizacionAdministrativa('CERVIX', $history, [],
            static fn (string $cum): array => $cum === 'CUM-X' ? [['mes' => '2026-08', 'tarifa' => 10.0]] : []);
        $pending = ['decisionPaquete' => 'URGENCIA_TARIFA_PENDIENTE', 'casoId' => 'SIN-URGENCIA',
            'factura' => 'F1', 'fechaAtencion' => '2026-09-03 10:00'];

        $valuacion->registrar($this->detalle('procedimientos', '123456', 1.0, $pending), ['respaldado' => 0.0]);
        $valuacion->registrar($this->detalle('procedimientos', '654321', 1.0, [...$pending,
            'casoId' => 'CIRUGIA', 'grupoServicios' => '04', 'tarifaReferencia' => 500.0]), ['respaldado' => 0.0]);
        $valuacion->registrar($this->detalle('otrosServicios', 'T34004', 2.0, $pending), ['respaldado' => 0.0]);
        $valuacion->registrar($this->detalle('medicamentos', 'MED-X', 3.0, [...$pending, 'cumResuelto' => 'CUM-X',
            'resolucionMedicamento' => 'RESUELTO']), ['respaldado' => 0.0]);
        $billed = ['casoId' => 'CON-URGENCIA', 'factura' => 'F2', 'fechaAtencion' => '2026-09-04 10:00'];
        $valuacion->registrar($this->detalle('consultas', '890701', 1.0, [...$billed,
            'decisionPaquete' => 'COBRADO_COMO_URGENCIA']), ['respaldado' => 40195.61]);
        $valuacion->registrar($this->detalle('medicamentos', 'MED-X', 3.0, [...$billed,
            'decisionPaquete' => 'INCLUIDO_EN_URGENCIA', 'cumResuelto' => 'CUM-X']), ['respaldado' => 0.0]);
        $valuacion->registrar($this->detalle('consultas', '890702', 1.0, [...$billed, 'factura' => 'F3',
            'decisionPaquete' => 'COBRADO_COMO_URGENCIA']), ['respaldado' => 247400.0]);

        $rows = collect($valuacion->resumenServicios('2026-09')['filas'])->keyBy(0);
        $this->assertSame([0.0, 0.0, 0.0, 287595.61], [$rows['AP'][4], $rows['AT'][4], $rows['AM'][4], $rows['AC'][4]]);
        $cirugia = collect($valuacion->filasDetalle())->firstWhere(2, '654321');
        $this->assertSame(['CIRUGÍA EN ESTANCIA <24H · ÁMBITO SIN ACREDITAR', 0.0, 500.0],
            [$cirugia[6], $cirugia[17], $cirugia[18]]);
        $coverage = collect($valuacion->filasCoberturaMedicamentos())->keyBy(1);
        $this->assertSame([ValorizacionAdministrativa::COBERTURA_CONDICIONADO_URGENCIA, 30.0], [$coverage['F1'][16], $coverage['F1'][18]]);
        $this->assertSame([ValorizacionAdministrativa::COBERTURA_INCLUIDO_URGENCIA, 0.0], [$coverage['F2'][16], $coverage['F2'][18]]);
        $this->assertSame(3, collect($valuacion->filasDetalle())->where(6, 'URGENCIA <24H SIN TARIFA ACREDITADA')->sum(7));
    }

    public function test_unit_basis_compares_the_history_with_every_billed_line_of_the_code(): void
    {
        $historial = new HistorialAdministrativo;

        foreach (['2026-06', '2026-07', '2026-08'] as $mes) {
            for ($i = 0; $i < 100; $i++) {
                $historial->registrar('PROSTATA', $mes, 'AT', 'DM00500', 10000.0);
            }
        }

        $valorizacion = new ValorizacionAdministrativa('PROSTATA', $historial, [], static fn (string $cum): array => []);

        for ($i = 0; $i < 80; $i++) {
            $valorizacion->registrar($this->detalle('otrosServicios', 'DM00500', 1.0), ['respaldado' => 10000.0]);
        }

        for ($i = 0; $i < 20; $i++) {
            $valorizacion->registrar($this->detalle('otrosServicios', 'DM00500', 5.0, ['decisionPaquete' => 'REGLA_NO_DEFINIDA']), ['respaldado' => 0.0, 'condicionadoIncluido' => 50000.0]);
        }

        $pendiente = collect($valorizacion->filasDetalle())->first(static fn (array $fila): bool => $fila[2] === 'DM00500' && $fila[4] === ValorizacionAdministrativa::CONDICIONADA);

        // La frecuencia histórica no demuestra que el insumo se cobre aparte del procedimiento.
        $this->assertSame(['NO VERIFICADA', 20, 0.0, 200000.0], [$pendiente[9], $pendiente[7], $pendiente[17], $pendiente[18]]);
        $this->assertSame(800000.0, collect($valorizacion->filasComparacion([]))->firstWhere(1, 'AT')[5]);
    }

    public function test_albergue_uses_compatible_units_while_other_at_stays_pending(): void
    {
        $historial = new HistorialAdministrativo;

        foreach (['2026-06', '2026-07', '2026-08'] as $month) {
            for ($unit = 0; $unit < 3; $unit++) {
                $historial->registrar('PROSTATA', $month, 'AT', 'A', 40000.0, 'ALBERGUE');
            }

            $historial->registrar('PROSTATA', $month, 'AT', 'DM00500', 10000.0, 'INSUMO');
        }

        $valuation = new ValorizacionAdministrativa('PROSTATA', $historial, [], static fn (string $cum): array => []);
        $valuation->registrar($this->detalle('otrosServicios', 'A', 3.0, ['descripcionOficial' => 'ALBERGUE']), ['respaldado' => 0.0]);
        $valuation->registrar($this->detalle('otrosServicios', 'DM00500', 1.0, ['descripcionOficial' => 'INSUMO']), ['respaldado' => 0.0]);

        $rows = collect($valuation->filasDetalle())->keyBy(2);
        $this->assertSame(['UNIDAD', 120000.0], [$rows['A'][9], $rows['A'][17]]);
        $this->assertSame([ValorizacionAdministrativa::CONDICIONADA, 'NO', 0.0], [$rows['DM00500'][4], $rows['DM00500'][5], $rows['DM00500'][17]]);
        $this->assertSame(120000.0, collect($valuation->filasComparacion([]))->firstWhere(1, 'AT')[5]);
    }

    public function test_same_month_dispensation_price_of_the_same_event_is_conditioned_not_historical(): void
    {
        $precios = static fn (string $cum): array => match ($cum) {
            'CUM-MISMO-MES' => [['mes' => '2026-07', 'tarifa' => 17767.8419]],
            'CUM-HISTORICO' => [['mes' => '2026-06', 'tarifa' => 50.0], ['mes' => '2026-07', 'tarifa' => 99.0]],
            default => [],
        };
        $valorizacion = new ValorizacionAdministrativa('CERVIX', new HistorialAdministrativo, [], $precios);
        $base = ['mesAtencion' => '2026-07', 'resolucionMedicamento' => 'RESUELTO'];
        $valorizacion->registrar($this->detalle('medicamentos', 'MISMO', 2.0, [...$base, 'cumResuelto' => 'CUM-MISMO-MES',
            'fuenteTarifa' => 'NINGUNA', 'fuenteEscenarioReferencial' => 'DISPENSACION']), ['respaldado' => 0.0, 'referencialAdicional' => 35535.68]);
        $valorizacion->registrar($this->detalle('medicamentos', 'HISTO', 2.0, [...$base, 'cumResuelto' => 'CUM-HISTORICO',
            'fuenteTarifa' => 'NINGUNA', 'fuenteEscenarioReferencial' => 'DISPENSACION']), ['respaldado' => 0.0, 'referencialAdicional' => 198.0]);
        $valorizacion->registrar($this->detalle('medicamentos', 'COMPLE', 1.0, [...$base, 'cumResuelto' => 'CUM-COMPLEMENTO',
            'fuenteTarifa' => 'COMPLEMENTO']), ['respaldado' => 0.0, 'condicionadoIncluido' => 300.0]);

        $detalle = collect($valorizacion->filasDetalle())->keyBy(2);
        $cobertura = collect($valorizacion->filasCoberturaMedicamentos())->keyBy(6);
        $am = collect($valorizacion->filasComparacion([]))->firstWhere(1, 'AM');

        // La dispensación del mismo paciente, CUM y mes no es una referencia histórica: queda condicionada.
        $this->assertSame([ValorizacionAdministrativa::CONDICIONADA, 'NO', ValorizacionAdministrativa::SUBTIPO_DISPENSACION_MISMO_PERIODO, 0.0, 35535.68],
            [$detalle['MISMO'][4], $detalle['MISMO'][5], $detalle['MISMO'][6], $detalle['MISMO'][17], $detalle['MISMO'][18]]);
        $this->assertSame([ValorizacionAdministrativa::COBERTURA_CONDICIONADO_PRECIO, 35535.68], [$cobertura['CUM-MISMO-MES'][16], $cobertura['CUM-MISMO-MES'][18]]);
        // Con precio del CUM en un mes anterior, el valor es histórico (no usa el precio del mes): suma.
        $this->assertSame(['SI', 100.0, '2026-06'], [$detalle['HISTO'][5], $detalle['HISTO'][17], $detalle['HISTO'][15]]);
        // Una tarifa no contractual de otra fuente (complemento) no proviene del mismo evento: suma.
        $this->assertSame(['SI', 'C · TARIFA NO CONTRACTUAL DE LA LINEA', 300.0], [$detalle['COMPLE'][5], $detalle['COMPLE'][6], $detalle['COMPLE'][17]]);
        $this->assertSame([400.0, 35535.68, 0.0], [$am[5], $am[6], $am[4]]);
        $this->assertStringContainsString('Precio del CUM en meses anteriores', collect($valorizacion->filasPendientes())->firstWhere(2, 'MISMO')[10]);
    }

    public function test_rn14_keeps_ap_medication_out_of_the_central_total_and_values_only_compatible_history_as_conditional(): void
    {
        $historial = new HistorialAdministrativo;
        $historial->registrar('PROSTATA', '2026-08', 'AP', '992505', 1000.0, '', 'MEDALFA');
        $nota = [['codigo' => '992505', 'categoria' => 'ONCOLOGIA_QUIMIOTERAPIA', 'tarifaReferencia' => 100.0,
            'metadatos' => ['tarifa_medicamento' => 900.0, 'esquema' => 'MEDALFA']]];
        $valorizacion = new ValorizacionAdministrativa('PROSTATA', $historial, $nota,
            static fn (string $cum): array => $cum === 'CUM-1' ? [['mes' => '2026-08', 'tarifa' => 30.0]] : []);
        $valorizacion->registrar($this->detalle('procedimientos', '992505', 1.0, ['casoId' => 'P1', 'factura' => 'F1']), ['respaldado' => 100.0]);
        $valorizacion->registrar($this->detalle('medicamentos', 'MEDALFA', 3.0, ['casoId' => 'P1', 'factura' => 'F1',
            'principioActivo' => 'MEDALFA', 'esquemaOncologico' => 'MEDALFA', 'cumResuelto' => 'CUM-1']), ['respaldado' => 150.0]);

        $medicamento = collect($valorizacion->filasMedicamentosAsociados())->firstWhere(3, 'CUM-1');
        $detalle = collect($valorizacion->filasDetalle())->firstWhere(2, 'MEDALFA');

        $this->assertSame(0.0, $medicamento[7]);
        $this->assertSame(90.0, $medicamento[8]);
        $this->assertSame('RN-14 · COBERTURA ECONOMICA AP NO DEMOSTRADA', $detalle[6]);
        $this->assertSame(1000.0, collect($valorizacion->filasComparacion([]))->firstWhere(1, 'AP')[5]);
        $this->assertSame(0.0, collect($valorizacion->filasComparacion([]))->firstWhere(1, 'AM')[5]);
    }

    public function test_rn14_requires_a_net_hospital_package_before_marking_ah_medication_as_covered(): void
    {
        $valorizacion = new ValorizacionAdministrativa('PROSTATA', new HistorialAdministrativo, [],
            static fn (string $cum): array => $cum === 'CUM-1' ? [['mes' => '2026-08', 'tarifa' => 25.0]] : []);
        $base = ['decisionPaquete' => 'INCLUIDO_EN_PAQUETE', 'cumResuelto' => 'CUM-1', 'resolucionMedicamento' => 'RESUELTO'];
        $valorizacion->registrar($this->detalle('medicamentos', 'MED-1', 2.0, [...$base, 'episodioPaquetesSumados' => 1]), ['respaldado' => 0.0]);
        $valorizacion->registrar($this->detalle('medicamentos', 'MED-1', 3.0, [...$base, 'episodioPaquetesSumados' => 0]), ['respaldado' => 0.0]);

        $filas = $valorizacion->filasCoberturaMedicamentos();

        $this->assertSame('INCLUIDO DEMOSTRADO EN AH', $filas[0][16]);
        $this->assertSame(0.0, $filas[0][18]);
        $this->assertSame('CONDICIONADO AH', $filas[1][16]);
        $this->assertSame(75.0, $filas[1][18]);
        $this->assertSame(0.0, collect($valorizacion->filasComparacion([]))->firstWhere(1, 'AM')[5]);
    }

    public function test_rn15_varies_by_month_from_ap_activity_and_keeps_unpriced_medication_pending(): void
    {
        $historial = new HistorialAdministrativo;
        $historial->registrar('PROSTATA', '2026-06', 'AP', '992505', 1000.0, '', 'MEDALFA');
        $nota = [['codigo' => '992505', 'categoria' => 'ONCOLOGIA_QUIMIOTERAPIA', 'tarifaReferencia' => 100.0,
            'metadatos' => ['tarifa_medicamento' => 900.0, 'esquema' => 'MEDALFA']]];
        $valorizacion = new ValorizacionAdministrativa('PROSTATA', $historial, $nota, static fn (string $cum): array => []);
        $valorizacion->registrar($this->detalle('procedimientos', '992505', 1.0, ['mesAtencion' => '2026-07', 'casoId' => 'P1']), ['respaldado' => 100.0]);

        foreach (['P2', 'P3', 'P4'] as $caso) {
            $valorizacion->registrar($this->detalle('procedimientos', '992505', 1.0, ['mesAtencion' => '2026-08', 'casoId' => $caso]), ['respaldado' => 100.0]);
        }

        $valorizacion->registrar($this->detalle('medicamentos', 'IUM-SIN-CUM', 2.0, ['mesAtencion' => '2026-08', 'casoId' => 'P4']), ['respaldado' => 0.0]);

        $julio = $valorizacion->resumenServicios('2026-07');
        $agosto = $valorizacion->resumenServicios('2026-08');
        $variacion = $valorizacion->principalesVariacionesCups('2026-07', '2026-08');

        $this->assertSame(1000.0, $julio['totales']['estimado']);
        $this->assertSame(3000.0, $agosto['totales']['estimado']);
        $this->assertSame(1, collect($agosto['filas'])->firstWhere(0, 'AM')[10]);
        $this->assertSame(['codigo' => '992505', 'registrosAnterior' => 1, 'registrosActual' => 3,
            'unidadesAnterior' => 1.0, 'unidadesActual' => 3.0, 'valorAnterior' => 1000.0,
            'valorActual' => 3000.0, 'diferencia' => 2000.0], $variacion[0]);
    }

    public function test_t34004_takes_its_unit_from_the_history_of_any_route_and_month_without_september_constants(): void
    {
        $historial = new HistorialAdministrativo;

        foreach (['2026-08', '2026-09', '2026-10'] as $mes) {
            for ($i = 0; $i < 6; $i++) {
                $historial->registrar('CERVIX', $mes, 'AT', 'T34004', 300000.0);
            }
        }

        for ($i = 0; $i < 6; $i++) {
            $historial->registrar('CERVIX', '2026-11', 'AT', 'T34004', 999999.0);
        }

        foreach (['2026-05', '2026-06', '2026-07'] as $mes) {
            for ($i = 0; $i < 40; $i++) {
                $historial->registrar('PROSTATA', $mes, 'AT', 'T34004', 300000.0);
            }
        }

        $cervix = new ValorizacionAdministrativa('CERVIX', $historial, [], static fn (string $cum): array => []);
        $noviembre = ['mesAtencion' => '2026-11'];
        $cervix->registrar($this->detalle('otrosServicios', 'T34004', 3.0, $noviembre), ['respaldado' => 0.0]);
        $cervix->registrar($this->detalle('otrosServicios', 'T34004', 3.0, $noviembre), ['respaldado' => 0.0]);
        $prostata = new ValorizacionAdministrativa('PROSTATA', $historial, [], static fn (string $cum): array => []);
        $prostata->registrar($this->detalle('otrosServicios', 'T34004', 3.0, ['mesAtencion' => '2026-08']), ['respaldado' => 0.0]);

        $fila = collect($cervix->filasDetalle())->firstWhere(2, 'T34004');
        $incompatible = collect($prostata->filasDetalle())->firstWhere(2, 'T34004');

        // Seis filas administrativas por mes frente a 2 registros y 6 unidades RIPS: una fila por unidad.
        // La cantidad sale del RIPS (6) y el precio de la ventana anterior (ago-oct); noviembre no se usa.
        $this->assertSame(['UNIDAD', 6.0, 1800000.0, 'SI', 'INFORME ADMINISTRATIVO (UNA FILA POR UNIDAD RIPS)'], [$fila[9], $fila[8], $fila[17], $fila[5], $fila[19]]);
        $this->assertStringContainsString('CERVIX T34004 · ventana 2026-08, 2026-09, 2026-10', $fila[20]);
        $this->assertStringContainsString('Control documental del mismo mes (no usado para valorar): 6 filas', $fila[20]);
        $this->assertStringNotContainsString('216', $fila[20]);
        // Sin equivalencia de unidad (40 filas por mes frente a 1 registro y 3 unidades): condicionado, sin inventar valor.
        $this->assertSame([ValorizacionAdministrativa::CONDICIONADA, 'NO', 0.0, 'NO VERIFICADA'], [$incompatible[4], $incompatible[5], $incompatible[17], $incompatible[9]]);
    }

    public function test_rn11_and_rn12_expose_line_and_unit_controls_without_changing_the_estimate(): void
    {
        $valorizacion = $this->valorizacion();

        foreach ($this->lineasSeptiembre() as [$detalle, $economico]) {
            $valorizacion->registrar($detalle, $economico);
        }

        $antes = $valorizacion->totalesPorMes()['2026-09'];
        $medicamentos = $valorizacion->conciliacionMedicamentos('2026-09');
        $unidades = $valorizacion->conciliacionUnidades('2026-09');
        $transporte = collect($unidades)->firstWhere('codigo', 'T34004');

        $this->assertSame(6.0, $transporte['unidades']);
        $this->assertSame(2, $transporte['registros']);
        $this->assertSame('UNIDAD', $transporte['base']);
        $this->assertSame(1826629.32, $transporte['estimado']);
        $this->assertGreaterThan(0, array_sum(array_column($medicamentos, 'registros')));
        $this->assertSame($antes, $valorizacion->totalesPorMes()['2026-09']);
        $this->assertContains('RN-11', array_column($valorizacion->filasReglas(), 0));
        $this->assertContains('RN-12', array_column($valorizacion->filasReglas(), 0));
    }

    public function test_administrative_au_reclassification_uses_its_components_and_preserves_the_total(): void
    {
        $historial = new HistorialAdministrativo;
        $historial->registrar('PROSTATA', '2026-08', 'AT', 'T34004', 40.0);
        $valorizacion = new ValorizacionAdministrativa('PROSTATA', $historial, [],
            static fn (string $cum): array => $cum === 'CUM-1' ? [['mes' => '2026-08', 'tarifa' => 30.0]] : []);
        $episodio = ['casoId' => 'CASO-PRIVADO', 'factura' => 'F1', 'fechaAtencion' => '2026-09-10',
            'servicioJson' => ['fechaEgreso' => '2026-09-11']];
        $valorizacion->registrar($this->detalle('urgencias', '', 1.0, $episodio), ['respaldado' => 0.0]);
        $valorizacion->registrar($this->detalle('consultas', '890294', 1.0, $episodio), ['respaldado' => 100.0]);
        $valorizacion->registrar($this->detalle('medicamentos', 'MED-1', 2.0,
            [...$episodio, 'cumResuelto' => 'CUM-1', 'resolucionMedicamento' => 'RESUELTO']), ['respaldado' => 0.0]);
        $valorizacion->atribuirUrgencias([['2026-09', 'F1', 'LM-PRIVADO', '2026-09-10', '2026-09-11', 2, 'AC:1 | AM:1', 100.0]],
            ['2026-09' => ['AC' => 100.0]]);

        $resumen = $valorizacion->resumenServicios('2026-09');
        $gerencial = collect($valorizacion->vistaGerencial('2026-09'))->keyBy('tipo');

        $this->assertSame(160.0, $resumen['totales']['estimado']);
        $this->assertSame(100.0, $resumen['urgencias']['atribuido']);
        $this->assertSame(160.0, $resumen['urgencias']['atribuidoAdministrativo']);
        $this->assertSame(160.0, $gerencial['AU']['gerencial']);
        $this->assertSame(0.0, $gerencial['AC']['gerencial']);
        $this->assertSame(0.0, $gerencial['AM']['gerencial']);
        $this->assertSame(160.0, round($gerencial->sum('gerencial'), 2));
        $this->assertStringNotContainsString('CASO-PRIVADO', json_encode([$resumen, $gerencial->all()]));
    }

    public function test_september_estimate_keeps_cost_a_and_controls_and_never_counts_twice(): void
    {
        $valorizacion = $this->valorizacion();

        foreach ($this->lineasSeptiembre() as [$detalle, $economico]) {
            $valorizacion->registrar($detalle, $economico);
        }

        $valorizacion->atribuirPaquetes([['2026-09', 2, 12030629.06], ['TOTAL', 2, 12030629.06]]);
        $valorizacion->atribuirUrgencias([['2026-09', 'F1', 'LM-1', '', '', 3, 'AC:1', 85000.0]]);
        $costoA = ['2026-09' => 16060319.06];

        $costos = $valorizacion->filasCostoA($costoA);
        $comparacion = collect($valorizacion->filasComparacion($costoA));
        $porTipo = $comparacion->filter(static fn (array $fila): bool => in_array($fila[1], ValorizacionAdministrativa::TIPOS_ORDEN, true))->keyBy(1);
        $total = $comparacion->firstWhere(1, 'TOTAL');

        // Costo A conservado al centavo; AC igual al costo A y AH por bloques reconstruidos (RN-16).
        $this->assertSame(['2026-09', 'TOTAL', 18, 126.0, 16060319.06, 'IGUAL AL COSTO RESPALDADO DE COSTOS Y BRECHAS'], end($costos));
        $this->assertSame(16060319.06, $total[4]);
        $this->assertSame($porTipo['AC'][4], $porTipo['AC'][5]);
        $this->assertSame([12030629.06, 12030629.06], [$porTipo['AH'][4], $porTipo['AH'][5]]);
        $this->assertSame(0.0, $porTipo['AU'][5]);

        // AP integral + 911107 estimado; AT por unidad compatible; AM A + C.
        $this->assertSame(8531868.98, $porTipo['AP'][5]);
        $this->assertSame(1826629.32, $porTipo['AT'][5]);
        $this->assertSame(29527552.0, $porTipo['AM'][5]);
        $this->assertSame(52001679.36, $total[5]);
        $this->assertSame(round($porTipo->sum(5), 2), $total[5]);

        // Componente condicionado: medicamento sin asociación demostrada (no suma) y unidad no compatible.
        $this->assertSame(500000.0, $porTipo['AM'][6]);
        $this->assertSame(1000.0, $porTipo['AT'][6]);
        $this->assertSame(501000.0, $total[6]);
        $this->assertSame(52502679.36, $total[7]);

        // Importes no aditivos visibles y separados.
        $noAditivos = $comparacion->where(1, 'NO ADITIVO')->pluck(11, 12);
        // ENZA queda en RN-14 (precio único del CUPS): su costo A no se presenta como incluido ni reemplazado.
        $this->assertSame(1000000.0, $noAditivos[ValorizacionAdministrativa::NO_ADITIVO_COSTO_A_RN14]);
        $this->assertArrayNotHasKey(ValorizacionAdministrativa::NO_ADITIVO_COSTO_A_INCLUIDO, $noAditivos->all());
        $this->assertSame(6015314.53, $noAditivos['AH SEGUN METODOLOGIA ADMINISTRATIVA (REGISTRO x PAQUETE)']);
        $this->assertSame(85000.0, $noAditivos['COSTO YA RECONOCIDO EN AC/AP/AM Y ATRIBUIDO A EPISODIOS AU']);
        $this->assertSame(16497537.96, $porTipo['AP'][9]);
    }

    public function test_service_summary_shows_au_attributed_and_totals_only_additive_amounts(): void
    {
        $valorizacion = $this->valorizacion();

        foreach ($this->lineasSeptiembre() as [$detalle, $economico]) {
            $valorizacion->registrar($detalle, $economico);
        }

        $valorizacion->atribuirPaquetes([['2026-09', 2, 12030629.06], ['TOTAL', 2, 12030629.06]]);
        $valorizacion->atribuirUrgencias([['2026-09', 'F1', 'LM-1', '', '', 3, 'AC:1', 85000.0]], ['2026-09' => ['AM' => 5000.0, 'AT' => 0.0, 'AC' => 80000.0]]);
        $comparacion = collect($valorizacion->filasComparacion(['2026-09' => 16060319.06]))->keyBy(1);

        $resumen = $valorizacion->resumenServicios('2026-09');
        $filas = collect($resumen['filas'])->keyBy(0);

        $this->assertSame(['AC', 'AP', 'AT', 'AU', 'AM', 'AH', 'TOTAL'], $filas->keys()->all());
        $this->assertSame(['AC', 'Consultas', 2, 85000.0, 85000.0, '', 0.0], array_slice($filas['AC'], 0, 7));
        $this->assertSame(85000.0, $filas['AU'][5]);
        $this->assertSame(0.0, $filas['AU'][4]);
        $this->assertStringContainsString('ATRIBUIDO, NO ADITIVO: 1 EPISODIOS; COSTO YA RECONOCIDO EN AC/AM', $filas['AU'][7]);

        // El total suma solo lo aditivo: igual al TOTAL de la comparación y sin la atribución AU.
        $this->assertSame($comparacion['TOTAL'][4], $filas['TOTAL'][3]);
        $this->assertSame($comparacion['TOTAL'][5], $filas['TOTAL'][4]);
        $this->assertSame($comparacion['TOTAL'][6], $filas['TOTAL'][6]);
        $this->assertSame(round($filas->except('TOTAL')->sum(4), 2), $filas['TOTAL'][4]);
        $this->assertSame(['episodios' => 1, 'atribuido' => 85000.0, 'porTipo' => ['AC' => 80000.0, 'AM' => 5000.0]],
            array_intersect_key($resumen['urgencias'], array_flip(['episodios', 'atribuido', 'porTipo'])));
        $this->assertSame(0.0, $resumen['urgencias']['cobroIndependiente']);
        $this->assertTrue($resumen['conHistorial']);
    }

    public function test_each_line_state_has_its_source_and_no_value_without_a_compatible_unit(): void
    {
        $valorizacion = $this->valorizacion();

        foreach ($this->lineasSeptiembre() as [$detalle, $economico]) {
            $valorizacion->registrar($detalle, $economico);
        }

        $detalle = collect($valorizacion->filasDetalle());
        $fila = static fn (string $tipo, string $codigo, string $estado): array => $detalle
            ->first(static fn (array $f): bool => $f[1] === $tipo && $f[2] === $codigo && $f[4] === $estado) ?? [];

        $integral = $fila('AP', '992505', ValorizacionAdministrativa::ESTIMADA);
        $this->assertSame(['SI', 'TRATAMIENTO INTEGRAL · ESQUEMA UNICO DEL CUPS', 444690.0, 7965668.98], [$integral[5], $integral[6], $integral[10], $integral[17]]);
        $this->assertSame([ValorizacionAdministrativa::NO_ADITIVO_CONDICIONADO, 500000.0], [$fila('AM', 'LEUPROLIDE', ValorizacionAdministrativa::CONDICIONADA)[21], $fila('AM', 'LEUPROLIDE', ValorizacionAdministrativa::CONDICIONADA)[18]]);
        $incluido = $fila('AM', 'ENZA', ValorizacionAdministrativa::CONDICIONADA);
        $this->assertSame(['NO', 'RN-14 · COBERTURA ECONOMICA AP NO DEMOSTRADA', 0.0], [$incluido[5], $incluido[6], $incluido[17]]);
        $this->assertSame(2000000.0, $fila('AM', 'ENZA', ValorizacionAdministrativa::RESPALDADA)[17]);
        $historico = $fila('AM', '1P1040411000101', ValorizacionAdministrativa::ESTIMADA);
        $this->assertSame([27527552.0, 13763776.0, 'DISPENSACION ADMINISTRATIVA DEL CUM 20085509-2'], [$historico[17], $historico[12], $historico[19]]);
        $this->assertSame(0.0, $fila('AM', 'IUM-AMBIGUO', ValorizacionAdministrativa::CONDICIONADA)[17]);

        $transporte = $fila('AT', 'T34004', ValorizacionAdministrativa::ESTIMADA);
        $this->assertSame(['UNIDAD', 2, 6.0, 1826629.32], [$transporte[9], $transporte[7], $transporte[8], $transporte[17]]);
        $incompatible = $fila('AT', 'T99', ValorizacionAdministrativa::CONDICIONADA);
        $this->assertSame(['NO', 0.0, 1000.0], [$incompatible[5], $incompatible[17], $incompatible[18]]);
        $this->assertSame(['NO', 0.0], [$fila('AT', 'DM00114', ValorizacionAdministrativa::INCLUIDA)[5], $fila('AT', 'DM00114', ValorizacionAdministrativa::INCLUIDA)[17]]);
        $this->assertSame(0.0, $fila('AT', 'DM00999', ValorizacionAdministrativa::SIN_VALOR)[17]);
        $this->assertSame([0.0, 6015314.53], [$fila('AT', '129M02', ValorizacionAdministrativa::INCLUIDA)[17], $fila('AT', '129M02', ValorizacionAdministrativa::INCLUIDA)[18]]);
        $this->assertSame(0.0, $fila('AP', '992505', ValorizacionAdministrativa::SIN_VALOR)[17], 'El duplicado exacto no se valoriza.');
        $this->assertSame(0.0, $fila('AC', '890266', ValorizacionAdministrativa::SIN_VALOR)[17]);

        foreach ($detalle as $linea) {
            if ($linea[17] > 0 || $linea[18] > 0) {
                $this->assertNotSame('', $linea[19], 'Todo valor tiene fuente: '.$linea[2]);
                $this->assertTrue($linea[4] === ValorizacionAdministrativa::RESPALDADA || $linea[12] !== '' || $linea[10] > 0,
                    'Todo valor tiene precio histórico o su propio costo A: '.$linea[2]);
            }
        }

        $pendientes = collect($valorizacion->filasPendientes())->pluck(10, 2);
        $this->assertStringContainsString('Evidencia de cobro independiente', $pendientes['T99']);
        $this->assertStringContainsString('Tarifa contractual vigente del CUM', $pendientes['1P1040411000101']);
    }

    public function test_integral_treatment_takes_the_price_of_its_real_scheme_and_classifies_its_medications(): void
    {
        $historial = new HistorialAdministrativo;

        foreach (['2026-06', '2026-07', '2026-08'] as $mes) {
            for ($i = 0; $i < 3; $i++) {
                $historial->registrar('CERVIX', $mes, 'AP', '992511', 26194690.0, '', 'PEMBROLIZUMAB');
            }

            for ($i = 0; $i < 7; $i++) {
                $historial->registrar('CERVIX', $mes, 'AP', '992511', 1875872.14, '', $i < 5 ? 'CISPLATINO' : 'GEMCITABINA');
            }

            $historial->registrar('CERVIX', $mes, 'AP', '992504', 6611663.25, '', 'BEVACIZUMAB/PACLITAXEL');
        }

        $nota = [
            ['codigo' => '992511', 'categoria' => 'ONCOLOGIA_QUIMIOTERAPIA', 'tarifaReferencia' => 444690.0, 'metadatos' => ['tarifa_medicamento' => 1431182.14, 'esquema' => 'Cisplatino, Pembrolizumab']],
            ['codigo' => '992504', 'categoria' => 'ONCOLOGIA_QUIMIOTERAPIA', 'tarifaReferencia' => 444690.0, 'metadatos' => ['tarifa_medicamento' => 6166973.25, 'esquema' => 'Bevacizumab']],
        ];
        $valorizacion = new ValorizacionAdministrativa('CERVIX', $historial, $nota, static fn (string $cum): array => []);
        $oncologico = ['esquemaOncologico' => 'Cisplatino, Pembrolizumab'];

        foreach ([
            [$this->detalle('procedimientos', '992511', 1.0, ['casoId' => 'P5']), ['respaldado' => 444690.0]],
            [$this->detalle('medicamentos', 'PEMBRO', 2.0, ['casoId' => 'P5', 'principioActivo' => 'PEMBROLIZUMAB', ...$oncologico]), ['respaldado' => 27527552.0]],
            [$this->detalle('procedimientos', '992511', 1.0, ['casoId' => 'P6']), ['respaldado' => 444690.0]],
            [$this->detalle('medicamentos', 'CISPLA', 1.0, ['casoId' => 'P6', 'principioActivo' => 'CISPLATINO', ...$oncologico]), ['respaldado' => 62779.0]],
            [$this->detalle('medicamentos', 'BEVA', 1.0, ['casoId' => 'P6', 'principioActivo' => 'BEVACIZUMAB', ...$oncologico]), ['respaldado' => 612563.0]],
            [$this->detalle('medicamentos', 'OTRO', 1.0, ['casoId' => 'P6', 'principioActivo' => 'DESCONOCIMAB', ...$oncologico]), ['respaldado' => 300000.0]],
            [$this->detalle('procedimientos', '992511', 1.0, ['casoId' => 'P7']), ['respaldado' => 444690.0]],
            [$this->detalle('procedimientos', '992511', 1.0, ['casoId' => 'P8']), ['respaldado' => 444690.0]],
            [$this->detalle('medicamentos', 'GEMCI', 1.0, ['casoId' => 'P8', 'principioActivo' => 'GEMCITABINA', ...$oncologico]), ['respaldado' => 215000.0]],
        ] as [$detalle, $economico]) {
            $valorizacion->registrar($detalle, $economico);
        }

        $tratamientos = collect($valorizacion->filasTratamientos())->keyBy(static fn (array $fila): string => $fila[3]);
        $medicamentos = collect($valorizacion->filasMedicamentosAsociados())->keyBy(2);
        $ap = collect($valorizacion->filasComparacion([]))->firstWhere(1, 'AP');
        $am = collect($valorizacion->filasComparacion([]))->firstWhere(1, 'AM');

        // RN-03: un precio por esquema real; sin evidencia, base + mezcla condicionada.
        $pembro = $tratamientos['MEDICAMENTO DISTINTIVO EN LA FECHA DEL AP (PEMB)'];
        $this->assertSame([1, 26194690.0, 26194690.0, 0.0], [$pembro[4], $pembro[6], $pembro[8], $pembro[9]]);
        $this->assertSame(1875872.14, $tratamientos['MEDICAMENTO DISTINTIVO EN LA FECHA DEL AP (CISP)'][8]);
        $this->assertSame(1875872.14, $tratamientos['MEDICAMENTO SOLO EN LA COMPOSICION HISTORICA DE ESTA CLASE (GEMC)'][8]);
        $mezcla = $tratamientos['SIN EVIDENCIA DEL ESQUEMA (BASE + MEZCLA CONDICIONADA)'];
        $this->assertSame([1875872.14, 7295645.36], [$mezcla[8], $mezcla[9]]);
        $this->assertSame([round(26194690.0 + 3 * 1875872.14, 2), 7295645.36], [$ap[5], $ap[6]]);

        // RN-14: el medicamento que distingue la clase de precio aplicada (pembrolizumab, cisplatino) queda incluido con cobertura demostrada.
        $this->assertSame([ValorizacionAdministrativa::GRUPO_INCLUIDO, 0.0, 0.0], [$medicamentos['PEMBROLIZUMAB'][1], $medicamentos['PEMBROLIZUMAB'][7], $medicamentos['PEMBROLIZUMAB'][8]]);
        $this->assertSame(ValorizacionAdministrativa::GRUPO_INCLUIDO, $medicamentos['CISPLATINO'][1]);
        $this->assertSame([ValorizacionAdministrativa::GRUPO_OTRO_ESQUEMA, 612563.0], [$medicamentos['BEVACIZUMAB'][1], $medicamentos['BEVACIZUMAB'][7]]);
        $this->assertSame([ValorizacionAdministrativa::GRUPO_DESCONOCIDO, 0.0, 300000.0], [$medicamentos['DESCONOCIMAB'][1], $medicamentos['DESCONOCIMAB'][7], $medicamentos['DESCONOCIMAB'][8]]);
        $this->assertSame([612563.0, 300000.0], [$am[5], $am[6]]);
    }

    public function test_radiotherapy_keeps_record_and_treatment_valuations_apart(): void
    {
        $historial = new HistorialAdministrativo;

        foreach (['2026-06', '2026-07', '2026-08'] as $mes) {
            $historial->registrar('CERVIX', $mes, 'AP', '922444', 8852976.0);
        }

        $nota = [['codigo' => '922444', 'categoria' => 'ONCOLOGIA_RADIOTERAPIA', 'tarifaReferencia' => 8852976.0, 'metadatos' => []]];
        $valorizacion = new ValorizacionAdministrativa('CERVIX', $historial, $nota, static fn (string $cum): array => []);

        foreach (['P1', 'P1', 'P1', 'P2'] as $caso) {
            $valorizacion->registrar($this->detalle('procedimientos', '922444', 1.0, ['casoId' => $caso, 'tipoValorReferencia' => 'TARIFA_TRATAMIENTO_PENDIENTE']), ['respaldado' => 0.0]);
        }

        $filas = collect($valorizacion->filasCondicionadas())->keyBy(6);
        $ap = collect($valorizacion->filasComparacion([]))->firstWhere(1, 'AP');

        $this->assertSame([4, 35411904.0, ValorizacionAdministrativa::NO_ADITIVO_CONDICIONADO], [$filas['RADIOTERAPIA · POR REGISTRO'][7], $filas['RADIOTERAPIA · POR REGISTRO'][18], $filas['RADIOTERAPIA · POR REGISTRO'][21]]);
        $this->assertSame([17705952.0, ValorizacionAdministrativa::NO_ADITIVO_ALTERNATIVA], [$filas['RADIOTERAPIA · POR TRATAMIENTO'][18], $filas['RADIOTERAPIA · POR TRATAMIENTO'][21]]);
        $this->assertSame([0.0, 35411904.0], [$ap[5], $ap[6]], 'Solo la valoración por registro entra al componente condicionado; la alternativa no se mezcla.');
    }

    public function test_radiotherapy_record_needs_documented_correspondence_in_the_same_month_not_a_volume_ratio(): void
    {
        $historial = new HistorialAdministrativo;

        // Ventana con nueve filas por mes (otros pacientes): la razón 9/5 no decide nada.
        foreach (['2026-04', '2026-05', '2026-06'] as $mes) {
            for ($i = 1; $i <= 9; $i++) {
                $historial->registrar('CERVIX', $mes, 'AP', '922444', 8852976.0, '', '', $mes.'-1'.$i, 'X'.$i.$mes);
            }
        }

        // Julio en el informe: cuatro filas del mismo paciente, fecha y código que los RIPS.
        foreach (['A' => '2026-07-02', 'B' => '2026-07-09', 'C' => '2026-07-16', 'D' => '2026-07-23'] as $documento => $fecha) {
            $historial->registrar('CERVIX', '2026-07', 'AP', '922444', 8852976.0, '', '', $fecha, $documento);
        }

        $nota = [['codigo' => '922444', 'categoria' => 'ONCOLOGIA_RADIOTERAPIA', 'tarifaReferencia' => 8852976.0, 'metadatos' => []]];
        $valorizacion = new ValorizacionAdministrativa('CERVIX', $historial, $nota, static fn (string $cum): array => []);

        foreach ([['A', '2026-07-02'], ['B', '2026-07-09'], ['C', '2026-07-16'], ['D', '2026-07-23'], ['E', '2026-07-23']] as [$documento, $fecha]) {
            $valorizacion->registrar($this->detalle('procedimientos', '922444', 1.0, ['mesAtencion' => '2026-07', 'casoId' => $documento,
                'numDocumentoIdentificacion' => '00'.$documento, 'fechaAtencion' => $fecha.' 08:00', 'tipoValorReferencia' => 'TARIFA_TRATAMIENTO_PENDIENTE']), ['respaldado' => 0.0]);
        }

        $ap = collect($valorizacion->resumenServicios('2026-07')['filas'])->keyBy(0)['AP'];
        $estimada = collect($valorizacion->filasDetalle())->first(static fn (array $fila): bool => $fila[6] === 'RADIOTERAPIA · POR REGISTRO' && $fila[4] === ValorizacionAdministrativa::ESTIMADA);
        $tratamiento = collect($valorizacion->filasCondicionadas())->firstWhere(6, 'RADIOTERAPIA · POR TRATAMIENTO');

        // Cuatro registros con fila 1:1 suman; el quinto (sin fila administrativa) queda condicionado.
        $this->assertSame([0.0, 35411904.0, 8852976.0], [$ap[3], $ap[4], $ap[6]]);
        $this->assertSame([4, 'SI'], [$estimada[7], $estimada[5]]);
        $this->assertStringContainsString('4 de 5 registros con fila administrativa del mismo paciente, fecha y código', $estimada[20]);
        $this->assertSame([44264880.0, ValorizacionAdministrativa::NO_ADITIVO_ALTERNATIVA], [$tratamiento[18], $tratamiento[21]]);
    }

    public function test_radiotherapy_without_the_month_report_uses_the_historical_unit_per_patient_and_rejects_repetitions(): void
    {
        $historial = new HistorialAdministrativo;

        foreach (['2026-06', '2026-07', '2026-08'] as $mes) {
            foreach (['P1', 'P2', 'P3'] as $n => $documento) {
                $historial->registrar('CERVIX', $mes, 'AP', '922444', 8852976.0, '', '', $mes.'-0'.($n + 1), $documento.$mes);
            }
        }

        $nota = [['codigo' => '922444', 'categoria' => 'ONCOLOGIA_RADIOTERAPIA', 'tarifaReferencia' => 8852976.0, 'metadatos' => []]];
        $valorizacion = new ValorizacionAdministrativa('CERVIX', $historial, $nota, static fn (string $cum): array => []);

        // A repite paciente-mes, C repite la misma fecha y D no trae documento.
        foreach ([['A', '2026-09-02'], ['A', '2026-09-20'], ['B', '2026-09-05'], ['C', '2026-09-08'], ['C', '2026-09-08'], ['', '2026-09-10']] as [$documento, $fecha]) {
            $valorizacion->registrar($this->detalle('procedimientos', '922444', 1.0, ['casoId' => $documento ?: 'D',
                'numDocumentoIdentificacion' => $documento, 'fechaAtencion' => $fecha.' 08:00', 'tipoValorReferencia' => 'TARIFA_TRATAMIENTO_PENDIENTE']), ['respaldado' => 0.0]);
        }

        $ap = collect($valorizacion->resumenServicios('2026-09')['filas'])->keyBy(0)['AP'];
        $estimada = collect($valorizacion->filasDetalle())->first(static fn (array $fila): bool => $fila[6] === 'RADIOTERAPIA · POR REGISTRO' && $fila[4] === ValorizacionAdministrativa::ESTIMADA);

        $this->assertSame([26558928.0, 26558928.0], [$ap[4], $ap[6]]);
        $this->assertStringContainsString('una fila por paciente y fecha y hasta 1 por paciente-mes; 3 de 6 registros', $estimada[20]);
    }

    public function test_undistinguished_integral_uses_the_dominant_class_only_with_enough_comparable_records(): void
    {
        $valorizacion = $this->valorizacionClases(['ABIRATERONA' => 6, 'ENZALUTAMIDA' => 6, 'LEUPROLIDE' => 4, 'DOCETAXEL' => 4], 2);
        $caso = ['mesAtencion' => '2026-09', 'factura' => 'F1'];

        $valorizacion->registrar($this->detalle('procedimientos', '992505', 1.0, [...$caso, 'casoId' => 'P1', 'fechaAtencion' => '2026-09-03 08:00']), ['respaldado' => 444690.0]);
        $valorizacion->registrar($this->detalle('procedimientos', '992505', 1.0, [...$caso, 'casoId' => 'P2', 'fechaAtencion' => '2026-09-04 08:00']), ['respaldado' => 444690.0]);
        $valorizacion->registrar($this->detalle('medicamentos', 'LEUP', 1.0, [...$caso, 'casoId' => 'P2', 'fechaAtencion' => '2026-09-04 09:00',
            'principioActivo' => 'LEUPRORELINA', 'cumResuelto' => 'CUM-LEUP']), ['respaldado' => 0.0]);

        $tratamientos = collect($valorizacion->filasTratamientos());

        // Sin medicamento y con leuprorelina (presente en ambas clases): la clase con 20 de 22 registros representa el CUPS.
        $this->assertSame([7965668.98], $tratamientos->pluck(6)->unique()->values()->all());
        $this->assertSame(15931337.96, $tratamientos->sum(8));
        $this->assertSame(0.0, $tratamientos->sum(9));
        $this->assertSame(['CLASE DOMINANTE (90,9 % DE 22 REGISTROS HISTORICOS COMPARABLES)'], $tratamientos->pluck(3)->unique()->values()->all());
    }

    public function test_without_a_clear_dominant_class_the_integral_stays_provisional_and_conditioned(): void
    {
        $valorizacion = $this->valorizacionClases(['ABIRATERONA' => 3, 'ENZALUTAMIDA' => 3], 3);
        $valorizacion->registrar($this->detalle('procedimientos', '992505', 1.0, ['casoId' => 'P1', 'factura' => 'F1', 'fechaAtencion' => '2026-09-03 08:00']), ['respaldado' => 444690.0]);

        [$tratamiento] = $valorizacion->filasTratamientos();

        // Nueve registros (6 frente a 3): sin predominancia suficiente ni precio máximo por defecto.
        $this->assertSame(['SIN EVIDENCIA DEL ESQUEMA (BASE + MEZCLA CONDICIONADA)', 2400267.86], [$tratamiento[3], $tratamiento[8]]);
        $this->assertSame(3710452.93, $tratamiento[9]);
    }

    public function test_transport_absorbed_by_the_hospital_package_is_valued_as_the_administrative_history_bills_it(): void
    {
        $historial = new HistorialAdministrativo;

        foreach (['2026-06', '2026-07', '2026-08'] as $mes) {
            $historial->registrar('PROSTATA', $mes, 'AT', '601T01', 304438.22, 'TRASLADO ASISTENCIAL BASICO');
            $historial->registrar('PROSTATA', $mes, 'AT', '601T01', 304438.22, 'TRASLADO ASISTENCIAL BASICO');
            $historial->registrar('PROSTATA', $mes, 'AT', 'DM00114', 5000.0);
        }

        $valorizacion = new ValorizacionAdministrativa('PROSTATA', $historial, [], static fn (string $cum): array => []);
        $absorbida = ['decisionPaquete' => 'INCLUIDO_EN_PAQUETE'];
        $valorizacion->registrar($this->detalle('otrosServicios', '601T01', 1.0, $absorbida), ['respaldado' => 0.0]);
        $valorizacion->registrar($this->detalle('otrosServicios', '601T01', 1.0, $absorbida), ['respaldado' => 0.0]);
        $valorizacion->registrar($this->detalle('otrosServicios', 'DM00114', 3.0, $absorbida), ['respaldado' => 0.0]);

        $at = collect($valorizacion->resumenServicios('2026-09')['filas'])->keyBy(0)['AT'];
        $detalle = collect($valorizacion->filasDetalle());

        $this->assertSame([3, 0.0, 608876.44], [$at[2], $at[3], $at[4]]);
        $this->assertSame(ValorizacionAdministrativa::ESTIMADA, $detalle->firstWhere(2, '601T01')[4]);
        $this->assertSame([ValorizacionAdministrativa::INCLUIDA, 'INCLUIDO EN PAQUETE HOSPITALARIO AH'], [$detalle->firstWhere(2, 'DM00114')[4], $detalle->firstWhere(2, 'DM00114')[6]]);
    }

    public function test_hospitalization_registered_only_as_a_service_counts_once_for_a_case_without_a_stay(): void
    {
        $historial = new HistorialAdministrativo;

        foreach (['2026-06', '2026-07', '2026-08'] as $mes) {
            $historial->registrar('PROSTATA', $mes, 'AH', '129M02', 6015314.53, 'INTERNACIÓN ADULTOS COMPLEJIDAD MEDIANA HABITACION MÚLTIPLE');
            $historial->registrar('PROSTATA', $mes, 'AH', '890466', 6015314.53, 'INTERCONSULTA POR ESPECIALISTA EN MEDICINA INTERNA');
        }

        $valorizacion = new ValorizacionAdministrativa('PROSTATA', $historial, [], static fn (string $cum): array => []);
        $estancia = ['fechaAtencion' => '2026-09-10 08:00', 'servicioJson' => ['fechaEgreso' => '2026-09-14 10:00', 'condicionDestinoUsuarioEgreso' => '01']];

        // P1 solo trae la internación como servicio (dos fechas): una unidad. P2 tiene hospitalización: su línea no agrega otra.
        $valorizacion->registrar($this->detalle('otrosServicios', '129M02', 1.0, ['casoId' => 'P1', 'fechaAtencion' => '2026-09-03 08:00']), ['respaldado' => 0.0]);
        $valorizacion->registrar($this->detalle('otrosServicios', '129M02', 1.0, ['casoId' => 'P1', 'fechaAtencion' => '2026-09-20 08:00']), ['respaldado' => 0.0]);
        $valorizacion->registrar($this->detalle('otrosServicios', '129M02', 1.0, ['casoId' => 'P2', 'fechaAtencion' => '2026-09-12 08:00']), ['respaldado' => 0.0]);
        $valorizacion->registrar($this->detalle('hospitalizacion', '', 1.0, ['casoId' => 'P2', ...$estancia]), ['respaldado' => 0.0]);
        // P3: código clasificado AH en el historial pero sin descripción de internación.
        $valorizacion->registrar($this->detalle('consultas', '890466', 1.0, ['casoId' => 'P3']), ['respaldado' => 0.0]);
        // P4: la línea ocurre en una urgencia que egresa a hospitalización (preingreso de una estancia del mes siguiente).
        $valorizacion->registrar($this->detalle('urgencias', '', 1.0, ['casoId' => 'P4', 'fechaAtencion' => '2026-09-29 14:00',
            'servicioJson' => ['fechaEgreso' => '2026-09-30 23:00', 'condicionDestinoUsuarioEgreso' => '03']]), ['respaldado' => 0.0]);
        $valorizacion->registrar($this->detalle('otrosServicios', '129M02', 1.0, ['casoId' => 'P4', 'fechaAtencion' => '2026-09-29 20:00']), ['respaldado' => 0.0]);
        // P5: urgencia que egresa a domicilio: la internación es una estancia propia.
        $valorizacion->registrar($this->detalle('urgencias', '', 1.0, ['casoId' => 'P5', 'fechaAtencion' => '2026-09-15 08:00',
            'servicioJson' => ['fechaEgreso' => '2026-09-16 10:00', 'condicionDestinoUsuarioEgreso' => '01']]), ['respaldado' => 0.0]);
        $valorizacion->registrar($this->detalle('otrosServicios', '129M02', 1.0, ['casoId' => 'P5', 'fechaAtencion' => '2026-09-15 12:00']), ['respaldado' => 0.0]);
        // P6: corte 08 del mismo caso (la unidad corresponde a su egreso) y caso sin documento: no suman.
        $valorizacion->registrar($this->detalle('otrosServicios', '129M02', 1.0, ['casoId' => 'P6', 'fechaAtencion' => '2026-09-01 08:00']), ['respaldado' => 0.0]);
        $valorizacion->registrar($this->detalle('hospitalizacion', '', 1.0, ['casoId' => 'P6', 'fechaAtencion' => '2026-09-27 08:00',
            'servicioJson' => ['fechaEgreso' => '2026-09-30 23:59', 'condicionDestinoUsuarioEgreso' => '08']]), ['respaldado' => 0.0]);
        $valorizacion->registrar($this->detalle('otrosServicios', '129M02', 1.0, ['casoId' => 'SIN_DOCUMENTO-F1-U1-2026-09', 'fechaAtencion' => '2026-09-05 08:00']), ['respaldado' => 0.0]);
        $valorizacion->atribuirPaquetes([['2026-09', 2, 12030629.06]]);

        $ah = collect($valorizacion->resumenServicios('2026-09')['filas'])->keyBy(0)['AH'];
        $unidad = collect($valorizacion->filasPendientes())->firstWhere(2, 'REGISTRO_INTERNACION_EGRESO');

        // Unidades: egreso de P2 + estancias solo como servicio de P1 y P5. El costo A conserva sus paquetes.
        $this->assertSame([12030629.06, 24061258.12], [$ah[3], $ah[4]]);
        $this->assertStringContainsString('2 internaciones registradas solo como servicio', $unidad[9]);
        $this->assertStringContainsString('1 cortes 08 son trazas clínicas', $unidad[9]);
    }

    public function test_repeated_lines_of_the_same_service_do_not_add_units_beyond_the_historical_unit_per_patient_and_date(): void
    {
        $historial = new HistorialAdministrativo;

        foreach (['2026-06', '2026-07', '2026-08'] as $mes) {
            foreach (['H1', 'H2', 'H3'] as $n => $documento) {
                $historial->registrar('PROSTATA', $mes, 'AP', '601101', 1316829.0, '', '', $mes.'-1'.$n, $documento.$mes);
            }
            // Sin documento en el historial: no hay unidad por paciente identificable.
            $historial->registrar('PROSTATA', $mes, 'AP', '898221', 176744.0);
        }

        $valorizacion = new ValorizacionAdministrativa('PROSTATA', $historial, [], static fn (string $cum): array => []);
        $biopsia = fn (string $documento, string $fecha): array => $this->detalle('procedimientos', '601101', 1.0,
            ['casoId' => $documento, 'numDocumentoIdentificacion' => $documento, 'fechaAtencion' => $fecha.' 08:00', 'tarifaReferencia' => 1316829.0]);

        // A repite la biopsia en la misma fecha (dos líneas RIPS); B tiene una; A vuelve otro día.
        $valorizacion->registrar($biopsia('A', '2026-09-03'), ['respaldado' => 1316829.0]);
        $valorizacion->registrar($biopsia('A', '2026-09-03'), ['respaldado' => 1316829.0]);
        $valorizacion->registrar($biopsia('B', '2026-09-03'), ['respaldado' => 1316829.0]);
        $valorizacion->registrar($biopsia('A', '2026-09-20'), ['respaldado' => 1316829.0]);
        $valorizacion->registrar($this->detalle('procedimientos', '898221', 1.0, ['casoId' => 'A', 'numDocumentoIdentificacion' => 'A', 'fechaAtencion' => '2026-09-03 08:00']), ['respaldado' => 176744.0]);
        $valorizacion->registrar($this->detalle('procedimientos', '898221', 1.0, ['casoId' => 'A', 'numDocumentoIdentificacion' => 'A', 'fechaAtencion' => '2026-09-03 08:00']), ['respaldado' => 176744.0]);

        $ap = collect($valorizacion->resumenServicios('2026-09')['filas'])->keyBy(0)['AP'];
        $repetida = collect($valorizacion->filasDetalle())->first(static fn (array $fila): bool => $fila[2] === '601101' && $fila[6] === 'REPETICION DEL MISMO SERVICIO');

        // Costo A conserva las cuatro biopsias y los dos estudios; la estimación cuenta tres biopsias (una por paciente y fecha) y ambos estudios.
        $this->assertSame([5620804.0, 4303975.0], [$ap[3], $ap[4]]);
        $this->assertSame([1, ValorizacionAdministrativa::INCLUIDA], [$repetida[7], $repetida[4]]);
    }

    public function test_repeated_line_that_differs_in_any_rips_field_still_adds_and_is_flagged_for_review(): void
    {
        $historial = new HistorialAdministrativo;

        foreach (['2026-06', '2026-07', '2026-08'] as $mes) {
            foreach (['H1', 'H2'] as $n => $documento) {
                $historial->registrar('PROSTATA', $mes, 'AP', '601101', 1316829.0, '', '', $mes.'-1'.$n, $documento.$mes);
            }
        }

        $valorizacion = new ValorizacionAdministrativa('PROSTATA', $historial, [], static fn (string $cum): array => []);
        $biopsia = fn (string $hora, string $consecutivo): array => $this->detalle('procedimientos', '601101', 1.0, ['casoId' => 'A', 'numDocumentoIdentificacion' => 'A',
            'factura' => 'F1', 'fechaAtencion' => '2026-09-03 '.$hora, 'servicioJson' => ['fechaInicioAtencion' => '2026-09-03 '.$hora, 'consecutivo' => $consecutivo]]);

        // La segunda línea difiere en la hora (otra prestación posible); la tercera repite la primera salvo el consecutivo.
        $valorizacion->registrar($biopsia('08:00', '1'), ['respaldado' => 1316829.0]);
        $valorizacion->registrar($biopsia('10:30', '2'), ['respaldado' => 1316829.0]);
        $valorizacion->registrar($biopsia('08:00', '3'), ['respaldado' => 1316829.0]);

        $ap = collect($valorizacion->resumenServicios('2026-09')['filas'])->keyBy(0)['AP'];
        $revision = collect($valorizacion->filasPendientes())->firstWhere(4, ValorizacionAdministrativa::CONDICIONADA.' / '.ValorizacionAdministrativa::SUBTIPO_REPETICION_POR_REVISAR);

        $this->assertSame([3950487.0, 2633658.0, 0.0], [$ap[3], $ap[4], $ap[6]]);
        $this->assertStringContainsString('1 línea(s) superan la unidad por paciente y fecha (1)', $revision[9]);
    }

    public function test_month_report_rows_of_the_same_service_raise_the_unit_per_patient_and_date(): void
    {
        $historial = new HistorialAdministrativo;

        foreach (['2026-06', '2026-07', '2026-08'] as $mes) {
            $historial->registrar('CERVIX', $mes, 'AP', '898221', 176744.0, '', '', $mes.'-10', 'H'.$mes);
        }

        // El informe de septiembre cobra tres especímenes del mismo paciente y fecha.
        for ($i = 0; $i < 3; $i++) {
            $historial->registrar('CERVIX', '2026-09', 'AP', '898221', 176744.0, '', '', '2026-09-03', 'A');
        }

        $valorizacion = new ValorizacionAdministrativa('CERVIX', $historial, [], static fn (string $cum): array => []);

        for ($i = 0; $i < 4; $i++) {
            $valorizacion->registrar($this->detalle('procedimientos', '898221', 1.0, ['casoId' => 'A', 'numDocumentoIdentificacion' => 'A',
                'fechaAtencion' => '2026-09-03 08:00', 'servicioJson' => ['consecutivo' => (string) $i]]), ['respaldado' => 176744.0]);
        }

        $ap = collect($valorizacion->resumenServicios('2026-09')['filas'])->keyBy(0)['AP'];

        // Cuatro líneas idénticas: tres son unidades documentadas por el informe del mes y la cuarta repite el servicio.
        $this->assertSame([706976.0, 530232.0], [$ap[3], $ap[4]]);
        $this->assertStringContainsString('Unidad por paciente y fecha: 3',
            collect($valorizacion->filasDetalle())->firstWhere(6, 'REPETICION DEL MISMO SERVICIO')[20]);
    }

    public function test_scheme_documented_in_the_month_report_resolves_only_sessions_backed_by_the_rips(): void
    {
        $historial = new HistorialAdministrativo;

        foreach (['2026-07', '2026-08', '2026-09'] as $mes) {
            for ($i = 0; $i < 3; $i++) {
                $historial->registrar('CERVIX', $mes, 'AP', '992511', 32000000.0, '', 'PEMBROLIZUMAB + CARBOPLATINO');
            }

            for ($i = 0; $i < 7; $i++) {
                $historial->registrar('CERVIX', $mes, 'AP', '992511', 2000000.0, '', 'CARBOPLATINO + PACLITAXEL');
            }
        }

        // Informe de octubre: D1 con pembrolizumab en la fecha de su sesión y en otra sesión sin línea RIPS; D2 con
        // pembrolizumab sin respaldo en su factura; D3 con el esquema base.
        foreach ([['D1', '2026-10-09', 32000000.0, 'PEMBROLIZUMAB + CARBOPLATINO'], ['D1', '2026-10-30', 32000000.0, 'PEMBROLIZUMAB + CARBOPLATINO'],
            ['D2', '2026-10-12', 32000000.0, 'PEMBROLIZUMAB + CARBOPLATINO'], ['D3', '2026-10-15', 2000000.0, 'CARBOPLATINO + PACLITAXEL']] as [$documento, $fecha, $valor, $medicamento]) {
            $historial->registrar('CERVIX', '2026-10', 'AP', '992511', $valor, '', $medicamento, $fecha, $documento);
        }

        $valorizacion = new ValorizacionAdministrativa('CERVIX', $historial, $this->notaCervix(), static fn (string $cum): array => []);
        $sesion = fn (string $documento, string $fecha): array => $this->detalle('procedimientos', '992511', 1.0, ['mesAtencion' => '2026-10',
            'casoId' => $documento, 'factura' => 'F'.$documento, 'numDocumentoIdentificacion' => $documento, 'fechaAtencion' => $fecha.' 08:00']);

        foreach ([['D1', '2026-10-09'], ['D2', '2026-10-12'], ['D3', '2026-10-15']] as [$documento, $fecha]) {
            $valorizacion->registrar($sesion($documento, $fecha), ['respaldado' => 444690.0]);
        }

        // El pembrolizumab de D1 está en otra fecha de la misma factura (esquema indeterminado sin el informe).
        $valorizacion->registrar($this->detalle('medicamentos', 'PEMBRO', 1.0, ['mesAtencion' => '2026-10', 'casoId' => 'D1', 'factura' => 'FD1',
            'fechaAtencion' => '2026-10-04 09:00', 'principioActivo' => 'PEMBROLIZUMAB', 'cumResuelto' => 'CUM-PEMBRO']), ['respaldado' => 0.0]);

        $tratamientos = collect($valorizacion->filasTratamientos());
        $documentados = $tratamientos->filter(static fn (array $fila): bool => str_starts_with($fila[3], 'ESQUEMA DOCUMENTADO EN EL INFORME DEL MES'));

        // D1 toma el esquema con pembrolizumab y D3 el base; D2 conserva la base provisional y su mezcla condicionada.
        // La segunda sesión administrativa de D1 no crea una sesión RIPS.
        $this->assertSame(3, $tratamientos->sum(4));
        $this->assertSame([36000000.0, 9000000.0], [$tratamientos->sum(8), $tratamientos->sum(9)]);
        $this->assertSame([2000000.0, 32000000.0], $documentados->pluck(6)->sort()->values()->all());
        $this->assertStringContainsString('RESPALDO RIPS: DISTINTIVO PEMB EN LA FACTURA', $documentados->firstWhere(6, 32000000.0)[3]);
        $this->assertSame(ValorizacionAdministrativa::COBERTURA_INCLUIDO_AP,
            collect($valorizacion->filasCoberturaMedicamentos())->firstWhere(6, 'CUM-PEMBRO')[16]);
    }

    public function test_package_procedure_billed_apart_in_the_month_report_is_conditioned_and_never_central(): void
    {
        $historial = new HistorialAdministrativo;
        $historial->registrar('PROSTATA', '2026-09', 'AP', '902210', 19000.0, '', '', '2026-09-05', 'A');
        $valorizacion = new ValorizacionAdministrativa('PROSTATA', $historial, [], static fn (string $cum): array => []);
        $absorbida = fn (string $documento, string $codigo): array => $this->detalle('procedimientos', $codigo, 1.0, ['casoId' => $documento,
            'numDocumentoIdentificacion' => $documento, 'fechaAtencion' => '2026-09-05 10:00', 'decisionPaquete' => 'INCLUIDO_EN_PAQUETE']);

        // A tiene fila del mismo servicio en el informe del mes; B y el otro examen de A no la tienen.
        $valorizacion->registrar($absorbida('A', '902210'), ['respaldado' => 0.0]);
        $valorizacion->registrar($absorbida('B', '902210'), ['respaldado' => 0.0]);
        $valorizacion->registrar($absorbida('A', '903859'), ['respaldado' => 0.0]);

        $ap = collect($valorizacion->resumenServicios('2026-09')['filas'])->keyBy(0)['AP'];
        $detalle = collect($valorizacion->filasDetalle());

        $cobrada = $detalle->firstWhere(6, ValorizacionAdministrativa::SUBTIPO_ESTANCIA_COBRADA_APARTE);

        $this->assertSame([0.0, 0.0, 19000.0], [$ap[3], $ap[4], $ap[6]]);
        $this->assertSame(['902210', 1, 'NO', 19000.0], [$cobrada[2], $cobrada[7], $cobrada[5], $cobrada[18]]);
        $this->assertSame(2, $detalle->where(6, 'INCLUIDO EN PAQUETE HOSPITALARIO AH')->sum(7));

        // La única fila del informe corresponde a la línea cobrada del mismo servicio, en cualquier orden: el AP de la estancia no la reutiliza.
        foreach ([true, false] as $primeroAbsorbido) {
            $otra = new ValorizacionAdministrativa('PROSTATA', $historial, [], static fn (string $cum): array => []);
            $cobradaAparte = $this->detalle('procedimientos', '902210', 1.0, ['casoId' => 'A', 'numDocumentoIdentificacion' => 'A',
                'fechaAtencion' => '2026-09-05 06:00', 'tarifaReferencia' => 19000.0]);
            $lineas = [[$absorbida('A', '902210'), ['respaldado' => 0.0]], [$cobradaAparte, ['respaldado' => 19000.0]]];

            foreach ($primeroAbsorbido ? $lineas : array_reverse($lineas) as [$linea, $economico]) {
                $otra->registrar($linea, $economico);
            }

            $this->assertSame([19000.0, 19000.0, 0.0], array_map(static fn (int $i): mixed => collect($otra->resumenServicios('2026-09')['filas'])->keyBy(0)['AP'][$i], [3, 4, 6]));
        }
    }

    public function test_higher_month_report_price_keeps_the_accredited_tariff_and_conditions_only_the_difference(): void
    {
        $historial = new HistorialAdministrativo;
        $historial->registrar('PROSTATA', '2026-09', 'AP', '906610', 91387.0, '', '', '2026-09-03', 'A');
        $historial->registrar('PROSTATA', '2026-09', 'AP', '906610', 57590.0, '', '', '2026-09-03', 'B');
        $valorizacion = new ValorizacionAdministrativa('PROSTATA', $historial, [], static fn (string $cum): array => []);

        foreach (['A', 'B', 'C'] as $documento) {
            $valorizacion->registrar($this->detalle('procedimientos', '906610', 1.0, ['casoId' => $documento, 'numDocumentoIdentificacion' => $documento,
                'fechaAtencion' => '2026-09-03 07:00', 'tarifaReferencia' => 57590.0]), ['respaldado' => 57590.0]);
        }

        $ap = collect($valorizacion->resumenServicios('2026-09')['filas'])->keyBy(0)['AP'];
        $diferencia = collect($valorizacion->filasDetalle())->firstWhere(6, ValorizacionAdministrativa::SUBTIPO_PRECIO_INFORME_MES);

        $this->assertSame([172770.0, 172770.0, 33797.0], [$ap[3], $ap[4], $ap[6]]);
        $this->assertSame([0, 33797.0], [$diferencia[7], $diferencia[18]]);
    }

    public function test_documented_equivalence_prices_the_rips_code_with_its_equivalent_and_keeps_the_cups(): void
    {
        $historial = new HistorialAdministrativo;

        foreach (['2026-02', '2026-03'] as $mes) {
            $historial->registrar('PROSTATA', $mes, 'AP', '405407', 5052320.0, 'LINFADENECTOMIA RADICAL PELVICA VIA LAPAROSCOPICA', '', $mes.'-10', 'X'.$mes);
        }

        foreach (['2026-06', '2026-07', '2026-08'] as $mes) {
            foreach (['Y1', 'Y2'] as $n => $documento) {
                $historial->registrar('PROSTATA', $mes, 'AP', '405406', 3805615.0, 'LINFADENECTOMIA RADICAL PELVICA VIA ABIERTA', '', $mes.'-1'.$n, $documento.$mes);
            }
        }

        $historial->registrar('PROSTATA', '2026-09', 'AP', '405406', 3805615.0, '', '', '2026-09-04', 'A');
        $historial->registrar('PROSTATA', '2026-09', 'AP', '405406', 3805615.0, '', '', '2026-09-11', 'B');
        $linfadenectomia = fn (string $documento, string $fecha): array => $this->detalle('procedimientos', '405407', 1.0, ['casoId' => $documento,
            'numDocumentoIdentificacion' => $documento, 'fechaAtencion' => $fecha.' 07:00']);
        $valorizacion = new ValorizacionAdministrativa('PROSTATA', $historial, [], static fn (string $cum): array => []);
        $valorizacion->registrar($linfadenectomia('A', '2026-09-04'), ['respaldado' => 0.0]);
        $valorizacion->registrar($linfadenectomia('B', '2026-09-11'), ['respaldado' => 0.0]);

        $fila = collect($valorizacion->filasDetalle())->firstWhere(6, 'EQUIVALENCIA DOCUMENTADA CON 405406');

        $this->assertSame(['405407', 2, 7611230.0], [$fila[2], $fila[7], $fila[17]]);
        $this->assertStringContainsString('Informe del mes: 2 de 2 líneas con fila 405406 del mismo paciente y fecha; ninguna fila 405407.', $fila[20]);

        // Si el informe del mes cobra el código propio, la equivalencia no se aplica.
        $historial->registrar('PROSTATA', '2026-09', 'AP', '405407', 5052320.0, '', '', '2026-09-20', 'C');
        $propia = new ValorizacionAdministrativa('PROSTATA', $historial, [], static fn (string $cum): array => []);
        $propia->registrar($linfadenectomia('A', '2026-09-04'), ['respaldado' => 0.0]);

        $this->assertNull(collect($propia->filasDetalle())->firstWhere(6, 'EQUIVALENCIA DOCUMENTADA CON 405406'));
    }

    public function test_rn05_result_does_not_depend_on_the_order_of_the_lines(): void
    {
        $historial = new HistorialAdministrativo;

        // Unidad histórica: dos filas por paciente y fecha.
        foreach (['2026-06', '2026-07', '2026-08'] as $mes) {
            $historial->registrar('PROSTATA', $mes, 'AP', '550202', 1218650.0, '', '', $mes.'-10', 'H'.$mes);
            $historial->registrar('PROSTATA', $mes, 'AP', '550202', 1218650.0, '', '', $mes.'-10', 'H'.$mes);
        }

        $linea = fn (string $hora, string $factura, string $consecutivo): array => $this->detalle('procedimientos', '550202', 1.0, ['casoId' => 'A',
            'numDocumentoIdentificacion' => 'A', 'factura' => $factura, 'fechaAtencion' => '2026-09-03 '.$hora,
            'servicioJson' => ['fechaInicioAtencion' => '2026-09-03 '.$hora, 'consecutivo' => $consecutivo]]);
        $centrales = [];

        // Dos líneas idénticas y una distinta, en tres órdenes (incluido el de otra factura cargada antes).
        foreach ([[['08:00', 'F1'], ['08:00', 'F1'], ['10:30', 'F2']], [['08:00', 'F1'], ['10:30', 'F2'], ['08:00', 'F1']], [['10:30', 'F2'], ['08:00', 'F1'], ['08:00', 'F1']]] as $orden) {
            $valorizacion = new ValorizacionAdministrativa('PROSTATA', $historial, [], static fn (string $cum): array => []);

            foreach ($orden as $n => [$hora, $factura]) {
                $valorizacion->registrar($linea($hora, $factura, (string) $n), ['respaldado' => 1218650.0]);
            }

            $ap = collect($valorizacion->resumenServicios('2026-09')['filas'])->keyBy(0)['AP'];
            $centrales[] = [$ap[3], $ap[4]];
        }

        // Dos unidades por paciente y fecha: la distinta y una de las idénticas; la otra idéntica no suma.
        $this->assertSame(array_fill(0, 3, [3655950.0, 2437300.0]), $centrales);
    }

    public function test_month_report_price_difference_does_not_depend_on_the_order_of_the_report_rows(): void
    {
        $condicionados = [];

        foreach ([[57590.0, 91387.0], [91387.0, 57590.0]] as $valores) {
            $historial = new HistorialAdministrativo;

            foreach ($valores as $valor) {
                $historial->registrar('PROSTATA', '2026-09', 'AP', '906610', $valor, '', '', '2026-09-03', 'A');
            }

            $valorizacion = new ValorizacionAdministrativa('PROSTATA', $historial, [], static fn (string $cum): array => []);

            foreach (['07:00', '15:00'] as $hora) {
                $valorizacion->registrar($this->detalle('procedimientos', '906610', 1.0, ['casoId' => 'A', 'numDocumentoIdentificacion' => 'A',
                    'fechaAtencion' => '2026-09-03 '.$hora, 'servicioJson' => ['fechaInicioAtencion' => '2026-09-03 '.$hora], 'tarifaReferencia' => 57590.0]), ['respaldado' => 57590.0]);
            }

            $condicionados[] = collect($valorizacion->resumenServicios('2026-09')['filas'])->keyBy(0)['AP'][6];
        }

        $this->assertSame([33797.0, 33797.0], $condicionados);
    }

    public function test_documented_scheme_row_is_not_reused_by_another_session_and_its_value_must_match_the_class(): void
    {
        $historial = new HistorialAdministrativo;

        foreach (['2026-07', '2026-08', '2026-09'] as $mes) {
            for ($i = 0; $i < 3; $i++) {
                $historial->registrar('CERVIX', $mes, 'AP', '992511', 32000000.0, '', 'PEMBROLIZUMAB + CARBOPLATINO');
            }

            for ($i = 0; $i < 7; $i++) {
                $historial->registrar('CERVIX', $mes, 'AP', '992511', 2000000.0, '', 'CARBOPLATINO + PACLITAXEL');
            }
        }

        // D1: una sola fila del informe, la de la sesión del 09 (que ya tiene pembrolizumab en su fecha).
        // D2: la fila dice pembrolizumab pero vale el precio base: no documenta el esquema.
        $historial->registrar('CERVIX', '2026-10', 'AP', '992511', 32000000.0, '', 'PEMBROLIZUMAB + CARBOPLATINO', '2026-10-09', 'D1');
        $historial->registrar('CERVIX', '2026-10', 'AP', '992511', 2000000.0, '', 'PEMBROLIZUMAB + CARBOPLATINO', '2026-10-12', 'D2');
        $valorizacion = new ValorizacionAdministrativa('CERVIX', $historial, $this->notaCervix(), static fn (string $cum): array => []);
        $registrar = function (string $tipo, string $documento, string $fecha, string $codigo, array $extra = []) use ($valorizacion): void {
            $valorizacion->registrar($this->detalle($tipo, $codigo, 1.0, ['mesAtencion' => '2026-10', 'casoId' => $documento, 'factura' => 'F'.$documento,
                'numDocumentoIdentificacion' => $documento, 'fechaAtencion' => $fecha.' 08:00', ...$extra]), ['respaldado' => $tipo === 'procedimientos' ? 444690.0 : 0.0]);
        };
        $pembro = ['principioActivo' => 'PEMBROLIZUMAB', 'cumResuelto' => 'CUM-PEMBRO'];

        $registrar('procedimientos', 'D1', '2026-10-09', '992511');
        $registrar('procedimientos', 'D1', '2026-10-23', '992511');
        $registrar('medicamentos', 'D1', '2026-10-09', 'PEMBRO', $pembro);
        $registrar('procedimientos', 'D2', '2026-10-12', '992511');
        $registrar('medicamentos', 'D2', '2026-10-04', 'PEMBRO', $pembro);

        $tratamientos = collect($valorizacion->filasTratamientos());

        // D1 del 09 por su propio distintivo; D1 del 23 y D2 siguen indeterminadas (base provisional + 30 M condicionados).
        $this->assertSame([], $tratamientos->filter(static fn (array $fila): bool => str_starts_with($fila[3], 'ESQUEMA DOCUMENTADO'))->all());
        $this->assertSame([36000000.0, 60000000.0], [$tratamientos->sum(8), $tratamientos->sum(9)]);
    }

    public function test_review_annotation_does_not_remove_the_code_from_the_au_attribution(): void
    {
        $historial = new HistorialAdministrativo;

        foreach (['2026-06', '2026-07', '2026-08'] as $mes) {
            $historial->registrar('PROSTATA', $mes, 'AP', '879111', 300000.0, '', '', $mes.'-10', 'H'.$mes);
        }

        $valorizacion = new ValorizacionAdministrativa('PROSTATA', $historial, [], static fn (string $cum): array => []);
        $valorizacion->registrar($this->detalle('urgencias', '', 1.0, ['casoId' => 'A', 'factura' => 'F1', 'fechaAtencion' => '2026-09-03 07:00',
            'servicioJson' => ['fechaEgreso' => '2026-09-03 20:00']]), ['respaldado' => 0.0]);

        // Dos líneas distintas del mismo paciente y fecha (la segunda queda por revisar) durante la urgencia; B fuera de ella.
        foreach ([['A', '09:00'], ['A', '11:00'], ['B', '09:00']] as [$documento, $hora]) {
            $valorizacion->registrar($this->detalle('procedimientos', '879111', 1.0, ['casoId' => $documento, 'numDocumentoIdentificacion' => $documento,
                'factura' => 'F1', 'fechaAtencion' => '2026-09-03 '.$hora, 'servicioJson' => ['fechaInicioAtencion' => '2026-09-03 '.$hora]]), ['respaldado' => 0.0]);
        }

        $resumen = $valorizacion->resumenServicios('2026-09');

        $this->assertSame(900000.0, collect($resumen['filas'])->keyBy(0)['AP'][4]);
        $this->assertSame(600000.0, $resumen['urgencias']['atribuidoAdministrativo']);
    }

    public function test_documented_equivalence_needs_its_start_date_and_enough_correspondence_and_works_without_the_month_report(): void
    {
        $historial = new HistorialAdministrativo;

        foreach (['2026-01', '2026-02', '2026-03', '2026-06', '2026-07', '2026-08'] as $mes) {
            $historial->registrar('PROSTATA', $mes, 'AP', '405406', 3805615.0, 'LINFADENECTOMIA RADICAL PELVICA VIA ABIERTA', '', $mes.'-10', 'Y'.$mes);
        }

        $linfadenectomia = fn (string $mes, string $documento, string $fecha): array => $this->detalle('procedimientos', '405407', 1.0, ['mesAtencion' => $mes,
            'casoId' => $documento, 'numDocumentoIdentificacion' => $documento, 'fechaAtencion' => $fecha.' 07:00']);
        $subtipo = static fn (ValorizacionAdministrativa $valorizacion): ?string => collect($valorizacion->filasDetalle())->firstWhere(2, '405407')[6] ?? null;

        // Sin informe del mes: equivalencia autónoma desde abril; antes de la vigencia, precio propio.
        $septiembre = new ValorizacionAdministrativa('PROSTATA', $historial, [], static fn (string $cum): array => []);
        $septiembre->registrar($linfadenectomia('2026-09', 'A', '2026-09-04'), ['respaldado' => 0.0]);
        $marzo = new ValorizacionAdministrativa('PROSTATA', $historial, [], static fn (string $cum): array => []);
        $marzo->registrar($linfadenectomia('2026-03', 'A', '2026-03-04'), ['respaldado' => 0.0]);
        $fila = collect($septiembre->filasDetalle())->firstWhere(2, '405407');

        $this->assertSame(['EQUIVALENCIA DOCUMENTADA CON 405406', 3805615.0], [$fila[6], $fila[17]]);
        $this->assertStringNotContainsString('Informe del mes', $fila[20]);
        $this->assertNotSame('EQUIVALENCIA DOCUMENTADA CON 405406', $subtipo($marzo));

        // Cérvix tarifa 405407 en su propia nota: la equivalencia documentada es solo de Próstata, aunque 405406 tenga precio en Cérvix.
        foreach (['2026-06', '2026-07', '2026-08'] as $mes) {
            $historial->registrar('CERVIX', $mes, 'AP', '405406', 3805615.0, '', '', $mes.'-10', 'Z'.$mes);
        }

        $cervix = new ValorizacionAdministrativa('CERVIX', $historial, [], static fn (string $cum): array => []);
        $cervix->registrar($linfadenectomia('2026-09', 'A', '2026-09-04'), ['respaldado' => 0.0]);
        $this->assertNotSame('EQUIVALENCIA DOCUMENTADA CON 405406', $subtipo($cervix));

        // Con informe del mes y fila 405406 en 1 de 2 líneas (menos del 90 %): no se aplica.
        $historial->registrar('PROSTATA', '2026-09', 'AP', '405406', 3805615.0, '', '', '2026-09-04', 'A');
        $parcial = new ValorizacionAdministrativa('PROSTATA', $historial, [], static fn (string $cum): array => []);
        $parcial->registrar($linfadenectomia('2026-09', 'A', '2026-09-04'), ['respaldado' => 0.0]);
        $parcial->registrar($linfadenectomia('2026-09', 'B', '2026-09-11'), ['respaldado' => 0.0]);

        $this->assertNotSame('EQUIVALENCIA DOCUMENTADA CON 405406', $subtipo($parcial));
    }

    public function test_without_administrative_history_only_cost_a_and_medication_history_are_used(): void
    {
        $valorizacion = new ValorizacionAdministrativa('CERVIX', new HistorialAdministrativo, [], static fn (string $cum): array => []);
        $valorizacion->registrar($this->detalle('procedimientos', '911107', 1.0), ['respaldado' => 0.0, 'condicionadoIncluido' => 566200.0]);
        $valorizacion->registrar($this->detalle('consultas', '890294', 1.0), ['respaldado' => 87000.0]);

        $total = collect($valorizacion->filasComparacion(['2026-09' => 87000.0]))->firstWhere(1, 'TOTAL');

        $this->assertSame([87000.0, 'SIN HISTORIAL', 87000.0], [$total[4], $total[9], $total[5]]);
        $this->assertSame(ValorizacionAdministrativa::SIN_VALOR, collect($valorizacion->filasDetalle())->firstWhere(2, '911107')[4]);
        $this->assertContains('No se cargó historial administrativo para la ruta: AP, AT y AC sin tarifa quedan sin valorización histórica.',
            collect($valorizacion->filasReglas())->pluck(2)->all());
    }

    /**
     * Cérvix con 992511 en dos clases: pembrolizumab (distintivo, $32.000.000) y carboplatino/paclitaxel ($2.000.000).
     *
     * @param  \Closure(string): list<array{mes: string, tarifa: float}>  $precios
     */
    private function valorizacionCervix(\Closure $precios): ValorizacionAdministrativa
    {
        $historial = new HistorialAdministrativo;

        foreach (['2026-07', '2026-08', '2026-09'] as $mes) {
            for ($i = 0; $i < 3; $i++) {
                $historial->registrar('CERVIX', $mes, 'AP', '992511', 32000000.0, '', 'PEMBROLIZUMAB + CARBOPLATINO');
            }

            for ($i = 0; $i < 7; $i++) {
                $historial->registrar('CERVIX', $mes, 'AP', '992511', 2000000.0, '', 'CARBOPLATINO + PACLITAXEL');
            }
        }

        return new ValorizacionAdministrativa('CERVIX', $historial, $this->notaCervix(), $precios);
    }

    /**
     * Historial del 992505 de Próstata con una clase a $7.965.668,98 (medicamentos dados) y otra
     * de leuprolide a $2.400.267,86, sin medicamentos distintivos.
     *
     * @param  array<string, int>  $dominante  Medicamento => registros mensuales repartidos en la ventana.
     */
    private function valorizacionClases(array $dominante, int $minoritaria): ValorizacionAdministrativa
    {
        $historial = new HistorialAdministrativo;

        foreach ($dominante as $medicamento => $registros) {
            for ($i = 0; $i < $registros; $i++) {
                $historial->registrar('PROSTATA', ['2026-06', '2026-07', '2026-08'][$i % 3], 'AP', '992505', 7965668.98, '', $medicamento);
            }
        }

        for ($i = 0; $i < $minoritaria; $i++) {
            $historial->registrar('PROSTATA', '2026-07', 'AP', '992505', 2400267.86, '', 'LEUPROLIDE 45MG');
        }

        $nota = [['codigo' => '992505', 'categoria' => 'ONCOLOGIA_QUIMIOTERAPIA', 'tarifaReferencia' => 444690.0,
            'metadatos' => ['tarifa_medicamento' => 7520978.98, 'esquema' => self::ESQUEMA]]];

        return new ValorizacionAdministrativa('PROSTATA', $historial, $nota, static fn (string $cum): array => []);
    }

    /** @return list<array<string, mixed>> */
    private function notaCervix(): array
    {
        return [
            ['codigo' => '992511', 'categoria' => 'ONCOLOGIA_QUIMIOTERAPIA', 'tarifaReferencia' => 444690.0, 'metadatos' => ['tarifa_medicamento' => 1431182.14, 'esquema' => 'Carboplatino']],
            ['codigo' => '992504', 'categoria' => 'ONCOLOGIA_QUIMIOTERAPIA', 'tarifaReferencia' => 444690.0, 'metadatos' => ['tarifa_medicamento' => 6166973.25, 'esquema' => 'Bevacizumab']],
        ];
    }

    private function valorizacion(): ValorizacionAdministrativa
    {
        $historial = new HistorialAdministrativo;

        foreach (['2026-06', '2026-07', '2026-08'] as $mes) {
            $historial->registrar('PROSTATA', $mes, 'AC', '890294', 85000.0);
            $historial->registrar('PROSTATA', $mes, 'AP', '992505', 7965668.98, '', 'ENZALUTAMIDA/ABIRATERONA');
            $historial->registrar('PROSTATA', $mes, 'AP', '992505', 7965668.98, '', 'APALUTAMIDA');
            $historial->registrar('PROSTATA', $mes, 'AP', '911107', 566200.0);
            $historial->registrar('PROSTATA', $mes, 'AH', '129M02', 6015314.53);
            $historial->registrar('PROSTATA', $mes, 'AU', '890701', 40195.61);

            for ($i = 0; $i < 6; $i++) {
                $historial->registrar('PROSTATA', $mes, 'AT', 'T34004', 304438.22);
            }

            for ($i = 0; $i < 40; $i++) {
                $historial->registrar('PROSTATA', $mes, 'AT', 'T99', 1000.0);
            }
        }

        $nota = [['codigo' => '992505', 'categoria' => 'ONCOLOGIA_QUIMIOTERAPIA', 'tarifaReferencia' => 444690.0,
            'metadatos' => ['tarifa_medicamento' => 7520978.98, 'esquema' => self::ESQUEMA]]];
        $precios = static fn (string $cum): array => $cum === '20085509-2'
            ? [['mes' => '2026-06', 'tarifa' => 13763776.0], ['mes' => '2026-07', 'tarifa' => 14245508.0], ['mes' => '2026-08', 'tarifa' => 13763776.0], ['mes' => '2026-09', 'tarifa' => 99.0]]
            : [];

        return new ValorizacionAdministrativa('PROSTATA', $historial, $nota, $precios);
    }

    /**
     * @return list<array{0: array<string, mixed>, 1: array<string, float>}>
     */
    private function lineasSeptiembre(): array
    {
        return [
            [$this->detalle('consultas', '890294', 1.0), ['respaldado' => 85000.0]],
            [$this->detalle('consultas', '890266', 1.0), ['respaldado' => 0.0]],
            [$this->detalle('procedimientos', '992505', 1.0, ['casoId' => 'P1']), ['respaldado' => 444690.0]],
            [$this->detalle('procedimientos', '992505', 1.0, ['casoId' => 'P1', 'motivoNoSuma' => 'DUPLICADO_EXACTO']), ['respaldado' => 0.0]],
            [$this->detalle('medicamentos', 'ENZA', 30.0, ['casoId' => 'P1', 'esquemaOncologico' => self::ESQUEMA, 'cumResuelto' => 'ENZA']), ['respaldado' => 1000000.0]],
            [$this->detalle('medicamentos', 'ENZA', 60.0, ['casoId' => 'P2', 'esquemaOncologico' => self::ESQUEMA, 'cumResuelto' => 'ENZA']), ['respaldado' => 2000000.0]],
            [$this->detalle('medicamentos', 'LEUPROLIDE', 1.0, ['casoId' => 'P1', 'esquemaOncologico' => 'Leuprolide, goserelina', 'cumResuelto' => 'LEUPROLIDE']), ['respaldado' => 500000.0]],
            [$this->detalle('medicamentos', '1P1040411000101', 2.0, ['casoId' => 'P3', 'cumResuelto' => '20085509-2', 'resolucionMedicamento' => 'RESUELTO']), ['respaldado' => 0.0]],
            [$this->detalle('medicamentos', 'IUM-AMBIGUO', 3.0, ['casoId' => 'P3', 'resolucionMedicamento' => 'AMBIGUO']), ['respaldado' => 0.0]],
            [$this->detalle('procedimientos', '911107', 1.0), ['respaldado' => 0.0, 'condicionadoIncluido' => 566200.0]],
            [$this->detalle('otrosServicios', 'T34004', 3.0), ['respaldado' => 0.0]],
            [$this->detalle('otrosServicios', 'T34004', 3.0), ['respaldado' => 0.0]],
            [$this->detalle('otrosServicios', 'DM00114', 5.0, ['decisionPaquete' => 'INCLUIDO_EN_PAQUETE']), ['respaldado' => 0.0]],
            [$this->detalle('otrosServicios', 'DM00999', 1.0), ['respaldado' => 0.0]],
            [$this->detalle('otrosServicios', '129M02', 1.0), ['respaldado' => 0.0]],
            [$this->detalle('otrosServicios', 'T99', 10.0), ['respaldado' => 0.0]],
            [$this->detalle('urgencias', '', 1.0), ['respaldado' => 0.0]],
            [$this->detalle('hospitalizacion', '', 1.0), ['respaldado' => 0.0]],
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function detalle(string $tipo, string $codigo, float $cantidad, array $extra = []): array
    {
        return [
            'mesAtencion' => '2026-09', 'tipoServicio' => $tipo, 'codigo' => $codigo, 'cantidadAplicada' => $cantidad,
            'descripcionOficial' => $codigo, 'casoId' => 'P0', 'motivoNoSuma' => '', 'decisionPaquete' => '',
            'fuenteTarifa' => 'NOTA_TECNICA', 'tarifaReferencia' => 0, ...$extra,
        ];
    }
}
