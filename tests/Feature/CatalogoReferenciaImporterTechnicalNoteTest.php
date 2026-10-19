<?php

namespace Tests\Feature;

use App\Models\CatalogoReferenciaItem;
use App\Models\User;
use App\Services\CatalogoReferenciaImporter;
use App\Services\ReferenciaRutaMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class CatalogoReferenciaImporterTechnicalNoteTest extends TestCase
{
    use RefreshDatabase;

    public function test_official_service_tariff_keeps_source_precision_after_persistence(): void
    {
        $user = User::factory()->create();
        $rate = 56922.727272727;
        $result = app(CatalogoReferenciaImporter::class)->import(
            $this->workbook([
                ['RECLASIFICACION', 'CUPS', 'NOMBRE DEL CUPS', 'Tarifa'],
                ['Consulta externa', '890226', 'CONSULTA DE UROLOGIA', $rate],
            ]),
            CatalogoReferenciaImporter::TYPE_NOTA_PROSTATA,
            'CANDIDATA-PRECISION',
            $user,
            activar: false
        );
        $catalog = $result['catalogo']->fresh()->load('items');
        $matcher = new ReferenciaRutaMatcher(collect([$catalog]));
        $match = $matcher->match([
            'codigo' => '890226', 'tipoServicio' => 'consultas', 'cantidad' => 120, 'valorRips' => 0,
        ], 'PROSTATA');

        $this->assertFalse($catalog->activo);
        $this->assertSame($rate, $match['tarifaReferencia']);
        $this->assertSame(6830727.27, $match['costoEstimadoReferencia']);
    }

    public function test_technical_note_keeps_service_and_grouper_of_each_code(): void
    {
        $user = User::factory()->create();

        app(CatalogoReferenciaImporter::class)->import(
            $this->workbook([
                ['AGRUPADOR', 'CUPS', 'Servicio', 'NOMBRE DEL CUPS', 'Tarifa'],
                ['QUIRÚRGICO', '601101', 'Imágenes Diagnósticas', 'BIOPSIA CERRADA DE PROSTATA', 250000],
                ['LABORATORIO CLÍNICO', '902210', 'Laboratorio Clínico', 'HEMOGRAMA IV', 19011.87],
            ]),
            CatalogoReferenciaImporter::TYPE_NOTA_CERVIX,
            '2026-10',
            $user
        );

        $items = CatalogoReferenciaItem::query()->where('categoria', 'SERVICIO_TECNICO')->get()->keyBy('codigo');

        $this->assertSame('QUIRÚRGICO', $items['601101']->metadatos['agrupador']);
        $this->assertSame('Imágenes Diagnósticas', $items['601101']->metadatos['servicio']);
        $this->assertSame('Laboratorio Clínico', $items['902210']->metadatos['servicio']);
    }

    public function test_technical_note_without_service_column_keeps_only_the_grouper(): void
    {
        $user = User::factory()->create();

        app(CatalogoReferenciaImporter::class)->import(
            $this->workbook([
                ['RECLASIFICACION', 'CUPS', 'NOMBRE DEL CUPS', 'Tarifa'],
                ['Laboratorio Clínico', '902210', 'HEMOGRAMA IV', 19011.87],
            ]),
            CatalogoReferenciaImporter::TYPE_NOTA_PROSTATA,
            '2026-10',
            $user
        );

        $metadatos = CatalogoReferenciaItem::query()->where('codigo', '902210')->firstOrFail()->metadatos;

        $this->assertSame('Laboratorio Clínico', $metadatos['agrupador']);
        $this->assertArrayNotHasKey('servicio', $metadatos);
    }

    /**
     * @param  list<list<mixed>>  $rows
     */
    private function workbook(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('servicios y tecnologías');
        $sheet->fromArray($rows);

        $path = tempnam(sys_get_temp_dir(), 'nota-tecnica-');

        if ($path === false) {
            $this->fail('No fue posible crear el archivo temporal de prueba.');
        }

        (new Xlsx($spreadsheet))->save($path);
        $content = file_get_contents($path);
        unlink($path);

        if ($content === false) {
            $this->fail('No fue posible leer el archivo temporal de prueba.');
        }

        return UploadedFile::fake()->createWithContent('nota-tecnica.xlsx', $content);
    }

    public function test_official_oncology_preserves_seven_tariffs_schemes_and_projected_quantities(): void
    {
        $rows = [
            [null, null, 'QUIMIOTERAPIA'],
            [null, null, 'CUPS', 'Valores', 'Total general', 'Frec Año', 'Cantidad mes', 'Tarifa aplicación ciclo', 'Costo total', 'Tarifa medicamento', 'Costo mes', 'Mxs contemplados en el esquema'],
            [null, null, 992505, 'Politerapia alta', 201, 0.08716392020815264, 222, 444690, 98721180, 7520978.976219699, 1768378512.7207732, 'Abiraterona, apalutamida, enzalutamida, darolutamida, docetaxel'],
            [null, null, 992509, 'Monoterapia baja', 427, 0.18516912402428448, 82, 286114, 23461348, 2114153.8587961555, 196821964.42128477, 'Leuprolide, goserelina, degarelix, bicalutamida'],
            [null, null, 992511, 'Monoterapia alta', 201, 0.08716392020815264, 0.5, 444690, 222345, 6884131.884057972, 3664410.942028986, 'Cabazitaxel'],
            [null, null, 'RADIOTERAPIA'],
            [null, null, 'CUPS', 'Valores', 'Total general', 'Frec Año', 'Cantidad mes', 'Tarifa tratamiento', 'Costo total', 'Costo mes'],
            [null, null, 922444, 'IMRT', 57, 0.024718126626192542, 34, 8852976],
            [null, null, 922446, 'VMAT', 9, 0.0039028620988725065, 0.75, 8852976],
            [null, null, 922443, '3D CRT', 27, 0.011708586296617519, 2.25, 5373000],
            [null, null, 922615, 'Braquiterapia', 3, 0.0013009540329575022, 3, 9345000],
        ];
        $result = app(CatalogoReferenciaImporter::class)->import(
            $this->workbookForSheet('quimio y Radio - Prostata', $rows),
            CatalogoReferenciaImporter::TYPE_NOTA_PROSTATA,
            'METADATOS-ONCOLOGIA',
            User::factory()->create(),
            activar: false
        );
        $items = $result['catalogo']->load('items')->items->keyBy('codigo');

        $this->assertFalse($result['catalogo']->activo);
        $this->assertCount(7, $items);
        foreach (['992505' => 222, '992509' => 82, '992511' => 0.5, '922444' => 34, '922446' => 0.75, '922443' => 2.25, '922615' => 3] as $code => $quantity) {
            $this->assertEquals($quantity, $items[$code]->metadatos['cantidad_referencia']);
            $this->assertNull($items[$code]->metadatos['unidad_contractual']);
        }
        $this->assertSame('Cabazitaxel', $items['992511']->metadatos['esquema']);
        $this->assertSame('H3', $items['992505']->metadatos['fuente_celdas']['tarifa']['celda']);
        $this->assertSame('L3', $items['992505']->metadatos['fuente_celdas']['esquema']['celda']);
        $this->assertSame('G3', $items['992505']->metadatos['fuente_celdas']['cantidad_referencia']['celda']);
        $this->assertEqualsWithDelta(7520978.976219699, $items['992505']->metadatos['tarifa_medicamento'], 0.00000001);
        foreach (['992505' => 444690, '992509' => 286114, '992511' => 444690, '922444' => 8852976, '922446' => 8852976, '922443' => 5373000, '922615' => 9345000] as $code => $tariff) {
            $this->assertEquals($tariff, $items[$code]->metadatos['tarifa_fuente_precision']);
        }
        $match = (new ReferenciaRutaMatcher(collect([$result['catalogo']->load('items')])))->match([
            'codigo' => '992505', 'tipoServicio' => 'procedimientos', 'cantidad' => 1, 'valorRips' => 0,
        ], 'PROSTATA');
        $this->assertSame(444690.0, $match['costoEstimadoReferencia']);
    }

    public function test_cervix_oncology_uses_shifted_source_columns_without_inventing_contractual_unit(): void
    {
        $result = app(CatalogoReferenciaImporter::class)->import(
            $this->workbookForSheet('quimio y Radio - Cervix', [
                [null, null, null, 'QUIMIOTERAPIA'],
                [null, null, null, 'CUPS', 'Valores', 'Total general', 'Frec Año', 'Cantidad mes', 'Tarifa aplicación ciclo', 'Costo total', 'Tarifa medicamento', 'Costo mes', 'Mxs contemplados en el esquema'],
                [null, null, null, 992505, 'Politerapia', null, null, 18, 444690, 8004420, 32342475.593058884, 590168980.6750599, 'Pembrolizumab, Bevacizumab'],
            ]),
            CatalogoReferenciaImporter::TYPE_NOTA_CERVIX,
            'METADATOS-CERVIX',
            User::factory()->create(),
            activar: false
        );
        $metadata = $result['catalogo']->load('items')->items->first()->metadatos;
        $this->assertSame('Pembrolizumab, Bevacizumab', $metadata['esquema']);
        $this->assertEquals(18, $metadata['cantidad_referencia']);
        $this->assertSame('M3', $metadata['fuente_celdas']['esquema']['celda']);
        $this->assertSame('I3', $metadata['fuente_celdas']['tarifa']['celda']);
        $this->assertNull($metadata['unidad_contractual']);
    }

    public function test_transport_and_lodging_repeated_label_keeps_each_column_block_and_original_cells(): void
    {
        foreach ([CatalogoReferenciaImporter::TYPE_NOTA_PROSTATA, CatalogoReferenciaImporter::TYPE_NOTA_CERVIX] as $type) {
            $result = app(CatalogoReferenciaImporter::class)->import(
                $this->workbookForSheet('Transporte + albergue', [
                    [],
                    ['Tarifa Transporte', 304438.2243983406, null, null, 'Tarifa Albergue', 43644.137223555976],
                    ['Frecuencia año transporte', 0.198761792705991, null, null, 'Frecuencia año Albergue', 0.18287117217105547],
                    ['Total transpotes año', 359.7942286206335, null, null, 'Total transpotes año', 331.02937658426924],
                    ['Costo Total Transporte año', 109535116.11003628, null, null, 'Costo Total Albergue año', 14447491.536672033],
                ]),
                $type,
                'METADATOS-TRANSPORTE',
                User::factory()->create(),
                activar: false
            );
            $items = $result['catalogo']->load('items')->items->keyBy('codigo');
            $this->assertCount(2, $items);
            $transport = $items['TRANSPORTE:TRANSPORTE']->metadatos;
            $lodging = $items['TRANSPORTE:ALBERGUE']->metadatos;
            $this->assertEqualsWithDelta(359.7942286206335, $transport['valores_referencia']['Total transpotes año'], 0.00000001);
            $this->assertEqualsWithDelta(331.02937658426924, $lodging['valores_referencia']['Total transpotes año'], 0.00000001);
            $this->assertSame('B4', $transport['fuente_celdas']['cantidad_proyectada']['celda']);
            $this->assertSame('F4', $lodging['fuente_celdas']['cantidad_proyectada']['celda']);
            $this->assertEqualsWithDelta(304438.2243983406, $items['TRANSPORTE:TRANSPORTE']->metadatos['tarifa_fuente_precision'], 0.00000001);
            $this->assertNull($transport['unidad_contractual']);
            $this->assertNull($lodging['unidad_contractual']);
            $this->assertArrayNotHasKey('T34004', $items->all());
        }
    }

    /**
     * @param  list<list<mixed>>  $rows
     */
    private function workbookForSheet(string $sheetName, array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->setTitle($sheetName)->fromArray($rows);
        $path = tempnam(sys_get_temp_dir(), 'nota-metadatos-');
        if ($path === false) {
            $this->fail('No fue posible crear el libro de prueba.');
        }
        (new Xlsx($spreadsheet))->save($path);
        $content = file_get_contents($path);
        unlink($path);
        if ($content === false) {
            $this->fail('No fue posible leer el libro de prueba.');
        }

        return UploadedFile::fake()->createWithContent('nota-metadatos.xlsx', $content);
    }
}
