<?php

namespace Tests\Feature;

use App\Models\CatalogoReferencia;
use App\Models\User;
use App\Services\CatalogoReferenciaImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class CatalogoReferenciaImporterComplementoTest extends TestCase
{
    use RefreshDatabase;

    public function test_complement_keeps_every_price_with_its_source_and_never_invents_validity(): void
    {
        $result = app(CatalogoReferenciaImporter::class)->import(
            $this->workbook([
                ['CUM', 'IUM', 'PRINCIPIO ACTIVO', 'CONCENTRACION', 'FORMA FARMACEUTICA', 'TARIFA', 'VIGENCIA DESDE', 'VIGENCIA HASTA', 'DOCUMENTO FUENTE', 'EVIDENCIA CONTRACTUAL', 'ESTADO CONTRACTUAL', 'PERIODO PRECIO OBSERVADO', 'OBSERVACION'],
                ['20178975-1', '', 'FOSAPREPITANT', '150 mg', 'POLVO', 147460, '', '', 'Nota Tecnica Prostata Chat.xlsx', '', 'PENDIENTE', '2026-04', ''],
                ['20178975-1', '', 'FOSAPREPITANT', '150 mg', 'POLVO', 73333, '', '', 'Nota Tecnica Prostata Chat.xlsx', '', '', '2026-08', 'Dos precios'],
                ['20052945-1', '', 'DENOSUMAB', '120 mg', 'SOLUCION', 1355147, '2026-02-01', '', 'Otrosí', 'Otrosí 4 firmado', 'VERIFICADO', '', ''],
                ['20071241-3', '', 'PREDNISOLONA', '5 mg', 'TABLETA', 55, '', '', 'Chat', '', 'VERIFICADO', '', ''],
                ['921405', '', 'PET-TC', '', '', 100, '', '', 'Chat', '', 'PENDIENTE', '', ''],
                ['20052945-1', '', 'DENOSUMAB', '120 mg', 'SOLUCION', 1402576, '2026-08-01', '2026-01-01', 'Chat', '', 'PENDIENTE', '', ''],
            ]),
            CatalogoReferenciaImporter::TYPE_COMPLEMENTO_MEDICAMENTOS_PROSTATA,
            '2026-10-07',
            User::factory()->create(),
            activar: false
        );

        $catalog = CatalogoReferencia::query()->findOrFail($result['catalogo']->id);
        $items = $catalog->items()->orderBy('id')->get();

        $this->assertFalse($catalog->activo);
        $this->assertSame(3, $result['items']);
        $this->assertSame(['20178975-1', '20178975-1', '20052945-1'], $items->pluck('codigo')->all());
        $this->assertSame([147460.0, 73333.0], $items->where('codigo', '20178975-1')->pluck('tarifa_referencia')->values()->all());
        $this->assertSame('PENDIENTE', $items[1]->metadatos['estado_contractual']);
        $this->assertSame('', $items[0]->metadatos['vigencia_desde']);
        $this->assertSame('2026-04', $items[0]->metadatos['periodo_precio_observado']);
        $this->assertSame('PROSTATA', $items[2]->ruta);
        $this->assertSame('MEDICAMENTO_COMPLEMENTARIO', $items[2]->categoria);
        $this->assertSame('Otrosí 4 firmado', $items[2]->metadatos['evidencia_contractual']);
        $this->assertSame(
            ['VERIFICADO_SIN_EVIDENCIA_O_VIGENCIA' => 1, 'CUM_INVALIDO' => 1, 'VIGENCIA_INVALIDA' => 1],
            $result['diagnostico']['Complemento']['rechazadas']
        );
    }

    /**
     * @param  list<list<mixed>>  $rows
     */
    private function workbook(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->setTitle('Complemento')->fromArray($rows);
        $path = tempnam(sys_get_temp_dir(), 'complemento-');
        (new Xlsx($spreadsheet))->save($path);
        $content = file_get_contents($path);
        unlink($path);

        return UploadedFile::fake()->createWithContent('complemento.xlsx', $content, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }
}
