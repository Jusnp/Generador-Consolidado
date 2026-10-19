<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

class LaMariaConciliacionSeptiembreTest extends TestCase
{
    /** Hojas retiradas del Excel: lo necesario quedó en el resumen de SEGUIMIENTO MENSUAL. */
    private const HOJAS_RETIRADAS = ['COSTOS Y BRECHAS', 'EXPLICACION BRECHA', 'MEDICAMENTOS', 'SERVICIOS SIN TARIFA', 'HOSPITALIZACION',
        'RESUMEN POR TIPO RIPS', 'SERVICIOS POR CODIGO', 'CATEGORIAS NOTA', 'AT Y AU', 'EPISODIOS AU', 'CONTROL DOS VISTAS',
        'COSTO A ACTUAL', 'ESTIMACIÓN ADMINISTRATIVA'];

    public function test_four_september_jsons_preserve_baseline_and_reconcile_both_economic_views(): void
    {
        if (getenv('SIRUTA_PRUEBA_SEPTIEMBRE') !== '1' || getenv('DB_DATABASE') !== 'json_excel_demo_lamaria_20261008') {
            $this->markTestSkipped('Solo se ejecuta en la base aislada de demostración, con autorización expresa.');
        }

        $this->assertSame('json_excel_demo_lamaria_20261008', DB::connection()->getDatabaseName());
        $base = 'C:/Users/Juan/Desktop/Trabajo/RIPS JSON RUTA AUTOINMUNES/pestaña 2/';
        config(['programas.la-maria.politica_dispensacion' => 'CONDICIONADA']);
        config(['programas.la-maria.libro_paquetes' => 'POR_LOTE']);
        config(['programas.la-maria.informes_administrativos' => [
            'requerido' => true,
            'PROSTATA' => $base.'Nueva carpeta/INFORME ADMIN PROSTATA PARA JUAN.xlsx',
            'CERVIX' => $base.'Nueva carpeta/INFORME ADMIN CERVIX PARA JUAN.xlsx',
        ]]);
        $sources = $base.'Septiembre json/';
        $invoices = ['FEHM643506', 'FEHM643507', 'FEHM643504', 'FEHM643505'];
        $destination = getenv('SIRUTA_SEPTIEMBRE_DESTINATION') ?: base_path('outputs/rn14-rn15-automatico-20261019');

        if (! is_dir($destination)) {
            mkdir($destination, 0775, true);
        }
        $beforeUsers = DB::table('users')->count();
        $beforeExecutions = DB::table('programa_ejecuciones')->count();
        DB::beginTransaction();

        try {
            $user = User::factory()->create(['role' => 'admin', 'active' => true]);

            foreach ([
                'prostata' => ['registros' => 11360, 'principal' => 2137528852.70, 'condicionado' => 57638471.19, 'au' => 25, 'central' => 3564010500.79, 'atribuidoAu' => 7785684.80],
                'cervix' => ['registros' => 6954, 'principal' => 521906976.77, 'condicionado' => 68052277.63, 'au' => 29, 'central' => 1397026965.97, 'atribuidoAu' => 12055420.22],
            ] as $route => $expected) {
                $uploads = array_map(static fn (string $invoice): UploadedFile => new UploadedFile(
                    $sources.$invoice.'.json', $invoice.'.json', 'application/json', null, true
                ), $invoices);
                $response = $this->actingAs($user)->post(route('la-maria.'.$route.'.export'), ['archivos' => $uploads]);
                $response->assertDownload();
                $output = $destination.'/RUTA_'.strtoupper($route).'_SEPTIEMBRE.xlsx';
                file_put_contents($output, $response->streamedContent());

                [$names, $first] = $this->firstSheet($output);
                $summary = $this->summary($first);
                $this->assertSame([], array_values(array_intersect(self::HOJAS_RETIRADAS, $names)));
                $this->assertSame($expected['registros'], (int) $first[1][2]);
                $this->assertSame($expected['principal'], round((float) $summary['servicios']['TOTAL'][3], 2));
                $this->assertSame(['AC', 'AP', 'AT', 'AU', 'AM', 'AH', 'TOTAL'], array_keys($summary['servicios']));
                $this->assertSame($expected['registros'], (int) $summary['servicios']['TOTAL'][2]);
                $this->assertSame($expected['principal'], round((float) $summary['servicios']['TOTAL'][3], 2));
                $this->assertSame($expected['central'], round((float) $summary['servicios']['TOTAL'][4], 2));
                $this->assertSame($expected['atribuidoAu'], round((float) $summary['servicios']['AU'][5], 2));
                $this->assertSame(0.0, (float) $summary['indicadores']['CONTROL: COSTO A POR SERVICIO − COSTO A']);
                $this->assertSame($expected['au'], (int) $summary['urgencias']['EPISODIOS AU']);
            }
        } finally {
            DB::rollBack();
        }

        $this->assertSame($beforeUsers, DB::table('users')->count());
        $this->assertSame($beforeExecutions, DB::table('programa_ejecuciones')->count());
    }

    /** @return array{0: list<string>, 1: list<array<int, mixed>>} */
    private function firstSheet(string $path): array
    {
        $reader = new Reader;
        $reader->open($path);
        $names = [];
        $rows = [];

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $names[] = $sheet->getName();

                if ($sheet->getName() !== 'SEGUIMIENTO MENSUAL') {
                    continue;
                }

                foreach ($sheet->getRowIterator() as $row) {
                    $rows[] = $row->toArray();
                }
            }
        } finally {
            $reader->close();
        }

        return [$names, $rows];
    }

    /**
     * @param  list<array<int, mixed>>  $rows
     * @return array{servicios: array<string, array<int, mixed>>, indicadores: array<string, mixed>, urgencias: array<string, mixed>}
     */
    private function summary(array $rows): array
    {
        $summary = ['servicios' => [], 'indicadores' => [], 'urgencias' => []];
        $block = null;

        foreach ($rows as $row) {
            $first = (string) ($row[0] ?? '');

            if (str_starts_with($first, 'VISTA GERENCIAL RECLASIFICADA')) {
                break;
            }

            $block = match (true) {
                $first === '' => null,
                $first === 'TIPO RIPS' => 'servicios',
                $first === 'INDICADOR' => 'indicadores',
                $first === 'URGENCIAS (AU)' => 'urgencias',
                default => $block,
            };

            if ($block === null || in_array($first, ['TIPO RIPS', 'INDICADOR', 'URGENCIAS (AU)'], true)) {
                continue;
            }

            $summary[$block][$first] = $block === 'servicios' ? $row : $row[3];
        }

        return $summary;
    }
}
