<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

class LaMariaConciliacionSeptiembreTest extends TestCase
{
    /** Hojas retiradas del Excel habitual; los cálculos permanecen en el motor. */
    private const HOJAS_RETIRADAS = ['COSTOS Y BRECHAS', 'EXPLICACION BRECHA', 'MEDICAMENTOS', 'SERVICIOS SIN TARIFA', 'HOSPITALIZACION',
        'RESUMEN POR TIPO RIPS', 'SERVICIOS POR CODIGO', 'CATEGORIAS NOTA', 'AT Y AU', 'EPISODIOS AU', 'CONTROL DOS VISTAS',
        'COSTO A ACTUAL', 'ESTIMACIÓN ADMINISTRATIVA'];

    public function test_four_september_jsons_generate_clean_route_workbooks_without_changing_internal_totals(): void
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
        $destination = getenv('SIRUTA_SEPTIEMBRE_DESTINATION') ?: base_path('outputs/septiembre-resumen-at-20261021');

        if (! is_dir($destination)) {
            mkdir($destination, 0775, true);
        }
        $beforeUsers = DB::table('users')->count();
        $beforeExecutions = DB::table('programa_ejecuciones')->count();
        DB::beginTransaction();

        try {
            $user = User::factory()->create(['role' => 'admin', 'active' => true]);

            foreach ([
                'prostata' => ['pacientes' => 1034, 'registros' => 11360, 'principal' => 2137528852.70, 'central' => 3564010500.79],
                'cervix' => ['pacientes' => 442, 'registros' => 6954, 'principal' => 521906976.77, 'central' => 1397026965.97],
            ] as $route => $expected) {
                $uploads = array_map(static fn (string $invoice): UploadedFile => new UploadedFile(
                    $sources.$invoice.'.json', $invoice.'.json', 'application/json', null, true
                ), $invoices);
                $response = $this->actingAs($user)->post(route('la-maria.'.$route.'.export'), ['archivos' => $uploads]);
                $response->assertDownload();
                $output = $destination.'/LA_MARIA_'.strtoupper($route).'_SEPTIEMBRE_RESUMEN_AT.xlsx';
                file_put_contents($output, $response->streamedContent());

                [$names, $first] = $this->firstSheet($output);
                $this->assertSame([
                    'SEGUIMIENTO MENSUAL', 'SEGUIMIENTO PACIENTE MES', 'DETALLE RIPS',
                    'CASOS A REVISAR', 'NO ENCONTRADOS NOTA TECNICA', 'REPETICIONES Y DUPLICADOS',
                    'REFERENCIA TECNICA', 'CATALOGOS APLICADOS', 'CONTROL DE CARGUE',
                ], $names);
                $this->assertSame([], array_values(array_intersect(self::HOJAS_RETIRADAS, $names)));
                $this->assertSame(['MES', 'PACIENTES', 'SERVICIOS', 'VALOR TOTAL MES'], $first[0]);
                $this->assertCount(4, $first[1]);
                $this->assertSame('2026-09', $first[1][0]);
                $this->assertSame($expected['pacientes'], (int) $first[1][1]);
                $this->assertSame($expected['registros'], (int) $first[1][2]);
                $this->assertSame($expected['central'], round((float) $first[1][3], 2));
                $serviceHeader = array_search('TIPO RIPS / SERVICIO', array_column($first, 0), true);
                $this->assertNotFalse($serviceHeader);
                $this->assertSame(['TIPO RIPS / SERVICIO', 'VALOR ADMINISTRATIVO'], $first[$serviceHeader]);
                $services = array_slice($first, $serviceHeader + 1, 7);
                $this->assertSame(['AC', 'AP', 'AT', 'AU', 'AM', 'AH', 'TOTAL'], array_map(
                    static fn (array $row): string => explode(' ', (string) $row[0])[0], $services
                ));
                $this->assertSame($expected['central'], round((float) $services[6][1], 2));
                $this->assertSame($expected['central'], round(array_sum(array_map(
                    static fn (array $row): float => (float) $row[1], [$services[0], $services[1], $services[2], $services[4], $services[5]]
                )), 2));

                $result = session('la-maria.resultado.'.$route);
                $cost = collect($result['costos'])->first(static fn (array $row): bool => ($row[1] ?? '') === '2026-09');
                $total = collect($result['estimacion'])->first(static fn (array $row): bool => ($row[1] ?? '') === '2026-09' && ($row[2] ?? '') === 'TOTAL');
                $this->assertSame($expected['principal'], round((float) $cost[3], 2));
                $this->assertSame($expected['central'], round((float) $total[6], 2));
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
