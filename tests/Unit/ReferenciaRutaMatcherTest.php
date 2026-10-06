<?php

namespace Tests\Unit;

use App\Models\CatalogoReferencia;
use App\Models\CatalogoReferenciaItem;
use App\Services\ReferenciaRutaMatcher;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class ReferenciaRutaMatcherTest extends TestCase
{
    private int $nextCatalogId = 1;

    public function test_matches_official_and_technical_references_and_estimates_cost(): void
    {
        $matcher = new ReferenciaRutaMatcher(new Collection([
            $this->catalog('ANEXOS_2706', [
                $this->item('890201', null, 'ANEXO_2', 'Consulta oficial'),
            ]),
            $this->catalog('NOTA_PROSTATA', [
                $this->item('890201', 'PROSTATA', 'SERVICIO_TECNICO', 'Consulta de próstata', 12500),
            ]),
        ]));

        $match = $matcher->match([
            'codigo' => '890201',
            'tipoServicio' => 'consultas',
            'cantidad' => 2,
            'valorRips' => 0,
        ], 'PROSTATA');

        $this->assertSame('Consulta oficial', $match['descripcionOficial']);
        $this->assertSame('Consulta de próstata', $match['nombreNotaTecnica']);
        $this->assertTrue($match['encontradoEnNotaTecnica']);
        $this->assertSame(12500.0, $match['tarifaReferencia']);
        $this->assertSame(25000.0, $match['costoEstimadoReferencia']);
        $this->assertSame('CRUCE_COMPLETO', $match['estadoCruce']);
    }

    public function test_identifies_cum_and_oncology_scheme_without_inventing_a_drug_rate(): void
    {
        $matcher = new ReferenciaRutaMatcher(new Collection([
            $this->catalog('MEDICAMENTOS_CUM', [
                $this->item(
                    '20085509-2',
                    null,
                    'CUM',
                    'Pembrolizumab',
                    null,
                    ['principio_activo' => 'Pembrolizumab', 'atc' => 'L01XC18']
                ),
            ]),
            $this->catalog('NOTA_CERVIX', [
                $this->item(
                    '992505',
                    'CERVIX',
                    'ONCOLOGIA_QUIMIOTERAPIA',
                    'Aplicación de quimioterapia',
                    45000,
                    ['esquema' => 'PEMBROLIZUMAB']
                ),
            ]),
        ]));

        $match = $matcher->match([
            'codigo' => '20085509-2',
            'tipoServicio' => 'medicamentos',
            'cantidad' => 1,
            'nombreServicio' => 'Pembrolizumab',
            'valorRips' => 0,
        ], 'CERVIX');

        $this->assertSame('20085509-2', $match['cum']);
        $this->assertSame('Pembrolizumab', $match['principioActivo']);
        $this->assertSame('L01XC18', $match['atc']);
        $this->assertSame('PEMBROLIZUMAB', $match['esquemaOncologico']);
        $this->assertSame('Pembrolizumab', $match['nombreNotaTecnica']);
        $this->assertFalse($match['encontradoEnNotaTecnica']);
        $this->assertSame(0.0, $match['tarifaReferencia']);
        $this->assertSame(0.0, $match['costoEstimadoReferencia']);
        $this->assertSame('MEDICAMENTO_CUM_IDENTIFICADO', $match['estadoCruce']);
    }

    public function test_matches_medication_technical_note_by_cum_and_returns_nt_name_and_rate(): void
    {
        $matcher = new ReferenciaRutaMatcher(new Collection([
            $this->catalog('NOTA_MEDICAMENTOS', [
                $this->item(
                    '19935327-1',
                    'PROSTATA',
                    'MEDICAMENTO_NT',
                    'BICALUTAMIDA 50 MG TABLETA',
                    12500.0,
                    ['principio_activo' => 'BICALUTAMIDA']
                ),
            ]),
        ]));

        $match = $matcher->match([
            'codigo' => '19935327-1',
            'tipoServicio' => 'medicamentos',
            'cantidad' => 2,
            'nombreServicio' => 'OTRO NOMBRE',
            'valorRips' => 0,
        ], 'PROSTATA');

        $this->assertSame('BICALUTAMIDA 50 MG TABLETA', $match['nombreNotaTecnica']);
        $this->assertTrue($match['encontradoEnNotaTecnica']);
        $this->assertSame('BICALUTAMIDA', $match['principioActivo']);
        $this->assertSame(12500.0, $match['tarifaReferencia']);
        $this->assertSame(25000.0, $match['costoEstimadoReferencia']);
        $this->assertSame('CRUCE_TECNICO', $match['estadoCruce']);
    }

    public function test_uses_administrative_dispensation_when_medication_is_not_in_the_technical_note(): void
    {
        $matcher = new ReferenciaRutaMatcher(new Collection([
            $this->catalog('DISPENSACION_CERVIX', [
                $this->item(
                    '20085509-2',
                    'CERVIX',
                    'DISPENSACION_ADMINISTRATIVA',
                    'Pembrolizumab dispensado',
                    2450000.0,
                    [
                        'documento_paciente' => '100',
                        'fecha_dispensacion' => '2026-08-15',
                    ]
                ),
            ]),
        ]));

        $match = $matcher->match([
            'codigo' => '20085509-2',
            'tipoServicio' => 'medicamentos',
            'cantidad' => 2,
            'numDocumentoIdentificacion' => '100',
            'fechaAtencion' => '2026-08-18',
            'nombreServicio' => 'Pembrolizumab',
            'valorRips' => 0,
        ], 'CERVIX');

        $this->assertSame(2450000.0, $match['tarifaReferencia']);
        $this->assertSame(4900000.0, $match['costoEstimadoReferencia']);
        $this->assertSame('MEDICAMENTO_CON_DISPENSACION', $match['estadoCruce']);
        $this->assertSame('TARIFA_DISPENSACION_ADMINISTRATIVA', $match['tipoValorReferencia']);
    }

    public function test_falls_back_to_principio_activo_and_paracetamol_alias_when_cum_differs(): void
    {
        $matcher = new ReferenciaRutaMatcher(new Collection([
            $this->catalog('NOTA_MEDICAMENTOS', [
                $this->item(
                    '19900001-1',
                    null,
                    'MEDICAMENTO_NT_PALIATIVO',
                    'ACETAMINOFEN 500 MG TABLETA',
                    800.0,
                    ['principio_activo' => 'ACETAMINOFEN']
                ),
            ]),
        ]));

        $match = $matcher->match([
            'codigo' => '99999999-9',
            'tipoServicio' => 'medicamentos',
            'cantidad' => 1,
            'nombreServicio' => 'PARACETAMOL',
            'valorRips' => 0,
        ], 'CERVIX');

        $this->assertSame('ACETAMINOFEN 500 MG TABLETA', $match['nombreNotaTecnica']);
        $this->assertTrue($match['encontradoEnNotaTecnica']);
        $this->assertSame('ACETAMINOFEN', $match['principioActivo']);
        $this->assertSame(800.0, $match['tarifaReferencia']);
        $this->assertSame('CRUCE_TECNICO', $match['estadoCruce']);
    }

    public function test_uses_cum_principio_to_match_nt_and_fills_name_when_nt_missing(): void
    {
        $matcher = new ReferenciaRutaMatcher(new Collection([
            $this->catalog('MEDICAMENTOS_CUM', [
                $this->item(
                    '20011388-9',
                    null,
                    'CUM',
                    'Furosemida 20 mg',
                    null,
                    ['principio_activo' => 'OMEPRAZOL', 'atc' => 'A02BC01']
                ),
            ]),
            $this->catalog('NOTA_MEDICAMENTOS', [
                $this->item(
                    '16478-1',
                    null,
                    'MEDICAMENTO_NT_PALIATIVO',
                    'OMEPRAZOL 20 MG CAPSULA DURA',
                    1200.0,
                    ['principio_activo' => 'OMEPRAZOL']
                ),
            ]),
        ]));

        $matched = $matcher->match([
            'codigo' => '20011388-9',
            'tipoServicio' => 'medicamentos',
            'cantidad' => 1,
            'nombreServicio' => '',
            'valorRips' => 0,
        ], 'CERVIX');

        $this->assertSame('OMEPRAZOL 20 MG CAPSULA DURA', $matched['nombreNotaTecnica']);
        $this->assertTrue($matched['encontradoEnNotaTecnica']);
        $this->assertSame(1200.0, $matched['tarifaReferencia']);

        $fallback = (new ReferenciaRutaMatcher(new Collection([
            $this->catalog('MEDICAMENTOS_CUM', [
                $this->item(
                    '32606-2',
                    null,
                    'CUM',
                    'Lactato de sodio',
                    null,
                    ['principio_activo' => 'LACTATO DE SODIO']
                ),
            ]),
        ])))->match([
            'codigo' => '32606-2',
            'tipoServicio' => 'medicamentos',
            'cantidad' => 1,
            'nombreServicio' => '',
            'valorRips' => 0,
        ], 'CERVIX');

        $this->assertSame('LACTATO DE SODIO', $fallback['nombreNotaTecnica']);
        $this->assertFalse($fallback['encontradoEnNotaTecnica']);
        $this->assertSame('MEDICAMENTO_CUM_IDENTIFICADO', $fallback['estadoCruce']);
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
    private function item(
        string $code,
        ?string $route,
        string $category,
        string $description,
        ?float $rate = null,
        array $metadata = []
    ): CatalogoReferenciaItem {
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
