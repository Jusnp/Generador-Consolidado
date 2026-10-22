<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

class LaMariaConsolidadoAdministrativoCuatroMesesTest extends TestCase
{
    public function test_september_prostata_preserves_totals_with_invoice_linkage_and_verified_transport_unit(): void
    {
        if (getenv('SIRUTA_PRUEBA_SEPTIEMBRE') !== '1' || getenv('DB_DATABASE') !== 'json_excel_demo_lamaria_20261008') {
            $this->markTestSkipped('Solo se ejecuta con los cuatro JSON de septiembre y la base aislada de demostración.');
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
        DB::beginTransaction();
        $path = tempnam(sys_get_temp_dir(), 'siruta-sep-');

        try {
            $user = User::factory()->create(['role' => 'admin', 'active' => true]);
            $uploads = array_map(static fn (string $invoice): UploadedFile => new UploadedFile(
                $base.'Septiembre json/'.$invoice.'.json', $invoice.'.json', 'application/json', null, true
            ), ['FEHM643504', 'FEHM643505', 'FEHM643506', 'FEHM643507']);
            $response = $this->actingAs($user)->post(route('la-maria.consolidado.export'), ['archivos' => $uploads]);
            $response->assertDownload();
            file_put_contents($path, $response->streamedContent());
            $auditExport = getenv('SIRUTA_RN14_EXPORT');

            if (is_string($auditExport) && $auditExport !== '') {
                copy($path, $auditExport);
            }
            $this->assertWorkbookStructure($path);
            $resumen = $this->internalSummaries()['2026-09']['PROSTATA'];
            $this->assertSame(2137528852.70, round((float) $resumen['TOTAL'][5], 2));
            $this->assertSame(3708242181.90, round((float) $resumen['TOTAL'][6], 2));
            $this->assertSame(61192083.10, round((float) $resumen['AT'][6], 2));
            $this->assertSame(14172581.38, round((float) $resumen['AM'][6], 2));
            $this->assertGreaterThan(0, (float) $resumen['AM'][7]);
        } finally {
            DB::rollBack();
            @unlink($path);
        }
    }

    public function test_sixteen_json_batch_matches_the_finished_monthly_exports(): void
    {
        if (getenv('SIRUTA_PRUEBA_LOTE_RN15') !== '1' || getenv('DB_DATABASE') !== 'json_excel_demo_lamaria_20261008') {
            $this->markTestSkipped('Solo se ejecuta con los 16 JSON y la base aislada de demostración.');
        }

        $this->assertSame('json_excel_demo_lamaria_20261008', DB::connection()->getDatabaseName());
        $baselinePath = base_path('outputs/rn14-rn15-automatico-20261019/verificacion_rutas_habituales.json');

        if (! is_file($baselinePath)) {
            $this->markTestSkipped('La comparación abreviada requiere el resultado mensual local; la prueba completa genera ambas fuentes.');
        }

        $baseline = json_decode((string) file_get_contents($baselinePath), true, flags: JSON_THROW_ON_ERROR);
        $base = 'C:/Users/Juan/Desktop/Trabajo/RIPS JSON RUTA AUTOINMUNES/pestaña 2/';
        $invoices = ['FEHM614147', 'FEHM614149', 'FEHM614150', 'FEHM614152',
            'FEHM623064', 'FEHM623066', 'FEHM623069', 'FEHM623071',
            'FEHM632724', 'FEHM632728', 'FEHM632903', 'FEHM633817',
            'FEHM643504', 'FEHM643505', 'FEHM643506', 'FEHM643507'];
        config(['programas.la-maria.politica_dispensacion' => 'CONDICIONADA']);
        config(['programas.la-maria.libro_paquetes' => 'POR_LOTE']);
        config(['programas.la-maria.informes_administrativos' => [
            'requerido' => true,
            'PROSTATA' => $base.'Nueva carpeta/INFORME ADMIN PROSTATA PARA JUAN.xlsx',
            'CERVIX' => $base.'Nueva carpeta/INFORME ADMIN CERVIX PARA JUAN.xlsx',
        ]]);

        $destination = tempnam(sys_get_temp_dir(), 'siruta-lote-');
        $this->assertNotFalse($destination);
        $before = DB::table('programa_ejecuciones')->count();
        DB::beginTransaction();

        try {
            $user = User::factory()->create(['role' => 'admin', 'active' => true]);
            $uploads = array_map(static fn (string $invoice): UploadedFile => new UploadedFile(
                $base.(str_starts_with($invoice, 'FEHM643') ? 'Septiembre json/' : 'Json/').$invoice.'.json',
                $invoice.'.json', 'application/json', null, true
            ), $invoices);
            $response = $this->actingAs($user)->post(route('la-maria.consolidado.export'), ['archivos' => $uploads]);
            $response->assertDownload();
            file_put_contents($destination, $response->streamedContent());
            $this->assertWorkbookStructure($destination);
            $batch = $this->internalSummaries();

            foreach ($baseline as $month => $routes) {
                foreach ($routes as $route => $expected) {
                    foreach ($expected['porTipo'] as $type => $values) {
                        $row = $batch[$month][$route][$type] ?? null;
                        $this->assertNotNull($row, "$month $route $type");

                        foreach (['registros' => 3, 'costoA' => 5, 'unidades' => 4] as $metric => $column) {
                            $this->assertSame(round((float) $values[$metric], 2), round((float) ($row[$column] ?? 0), 2),
                                "$month $route $type $metric");
                        }
                    }

                    $types = $batch[$month][$route];
                    $this->assertSame(round(array_sum(array_map(
                        static fn (string $type): float => (float) ($types[$type][6] ?? 0), ['AC', 'AP', 'AT', 'AM', 'AH']
                    )), 2), round((float) ($types['TOTAL'][6] ?? 0), 2), "$month $route sin AU aditivo");
                }
            }
        } finally {
            DB::rollBack();
            @unlink($destination);
        }

        $this->assertSame($before, DB::table('programa_ejecuciones')->count());
    }

    public function test_each_month_uses_the_same_rules_and_preserves_september_controls(): void
    {
        if (getenv('SIRUTA_PRUEBA_CUATRO_MESES') !== '1' || getenv('DB_DATABASE') !== 'json_excel_demo_lamaria_20261008') {
            $this->markTestSkipped('Solo se ejecuta con los JSON y la base aislada de demostración.');
        }

        $this->assertSame('json_excel_demo_lamaria_20261008', DB::connection()->getDatabaseName());
        $base = 'C:/Users/Juan/Desktop/Trabajo/RIPS JSON RUTA AUTOINMUNES/pestaña 2/';
        $groups = [
            '2026-06' => ['FEHM614147', 'FEHM614149', 'FEHM614150', 'FEHM614152'],
            '2026-07' => ['FEHM623064', 'FEHM623066', 'FEHM623069', 'FEHM623071'],
            '2026-08' => ['FEHM632724', 'FEHM632728', 'FEHM632903', 'FEHM633817'],
            '2026-09' => ['FEHM643504', 'FEHM643505', 'FEHM643506', 'FEHM643507'],
        ];
        config(['programas.la-maria.politica_dispensacion' => 'CONDICIONADA']);
        config(['programas.la-maria.libro_paquetes' => 'POR_LOTE']);
        config(['programas.la-maria.informes_administrativos' => [
            'requerido' => true,
            'PROSTATA' => $base.'Nueva carpeta/INFORME ADMIN PROSTATA PARA JUAN.xlsx',
            'CERVIX' => $base.'Nueva carpeta/INFORME ADMIN CERVIX PARA JUAN.xlsx',
        ]]);
        $destination = getenv('SIRUTA_RN15_DESTINATION') ?: sys_get_temp_dir().'/siruta-cuatro-meses-'.uniqid();

        if (! is_dir($destination)) {
            mkdir($destination, 0775, true);
        }

        $before = DB::table('programa_ejecuciones')->count();
        $result = [];

        foreach ($groups as $month => $invoices) {
            DB::beginTransaction();

            try {
                $user = User::factory()->create(['role' => 'admin', 'active' => true]);
                $uploads = array_map(static fn (string $invoice): UploadedFile => new UploadedFile(
                    $base.(str_starts_with($invoice, 'FEHM643') ? 'Septiembre json/' : 'Json/').$invoice.'.json',
                    $invoice.'.json', 'application/json', null, true
                ), $invoices);
                $response = $this->actingAs($user)->post(route('la-maria.consolidado.export'), ['archivos' => $uploads]);
                $response->assertDownload();
                $path = $destination.'/FUENTE_ADMINISTRATIVA_'.$month.'.xlsx';
                file_put_contents($path, $response->streamedContent());
                $this->assertWorkbookStructure($path);
                $result[$month] = $this->internalSummaries()[$month] ?? [];

                foreach (['PROSTATA', 'CERVIX'] as $route) {
                    $this->assertArrayHasKey($route, $result[$month]);
                    $rows = $result[$month][$route];
                    $this->assertSame(round((float) $rows['TOTAL'][6] + (float) $rows['TOTAL'][7], 2), round((float) $rows['TOTAL'][8], 2));
                }

                if ($month === '2026-09') {
                    $this->assertSame(2137528852.70, round((float) $result[$month]['PROSTATA']['TOTAL'][5], 2));
                    $this->assertSame(3708242181.90, round((float) $result[$month]['PROSTATA']['TOTAL'][6], 2));
                    $this->assertSame(521906976.77, round((float) $result[$month]['CERVIX']['TOTAL'][5], 2));
                    $this->assertSame(1470200269.99, round((float) $result[$month]['CERVIX']['TOTAL'][6], 2));
                    $this->assertGreaterThan(0, (float) $result[$month]['PROSTATA']['AU'][12]);
                    $this->assertGreaterThan(0, (float) $result[$month]['CERVIX']['AU'][12]);
                }
            } finally {
                DB::rollBack();
            }
        }

        DB::beginTransaction();

        try {
            $user = User::factory()->create(['role' => 'admin', 'active' => true]);
            $uploads = [];

            foreach ($groups as $invoices) {
                foreach ($invoices as $invoice) {
                    $uploads[] = new UploadedFile(
                        $base.(str_starts_with($invoice, 'FEHM643') ? 'Septiembre json/' : 'Json/').$invoice.'.json',
                        $invoice.'.json', 'application/json', null, true
                    );
                }
            }

            $response = $this->actingAs($user)->post(route('la-maria.consolidado.export'), ['archivos' => $uploads]);
            $response->assertDownload();
            $batchPath = $destination.'/FUENTE_ADMINISTRATIVA_LOTE_16.xlsx';
            file_put_contents($batchPath, $response->streamedContent());
            $this->assertWorkbookStructure($batchPath);
            $batch = $this->internalSummaries();

            foreach (array_keys($groups) as $month) {
                foreach (['PROSTATA', 'CERVIX'] as $route) {
                    foreach (['AC', 'AP', 'AT', 'AU', 'AM', 'AH', 'TOTAL'] as $type) {
                        foreach ([3, 4, 5, 6, 7, 12] as $column) {
                            $this->assertSame(
                                round((float) ($result[$month][$route][$type][$column] ?? 0), 2),
                                round((float) ($batch[$month][$route][$type][$column] ?? 0), 2),
                                "$month $route $type columna $column"
                            );
                        }
                    }

                    $this->assertSame($result[$month][$route]['TOTAL'][6], $batch[$month][$route]['TOTAL'][6] ?? null);
                }
            }
        } finally {
            DB::rollBack();
        }

        file_put_contents($destination.'/resultados_mensuales.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->assertNotSame($result['2026-06']['PROSTATA']['TOTAL'][6], $result['2026-07']['PROSTATA']['TOTAL'][6]);
        $this->assertNotSame($result['2026-07']['CERVIX']['TOTAL'][6], $result['2026-08']['CERVIX']['TOTAL'][6]);
        $this->assertSame($before, DB::table('programa_ejecuciones')->count());
    }

    /** @return array<string, array<string, array<string, array<int, mixed>>>> */
    private function internalSummaries(): array
    {
        $result = [];

        foreach (session('la-maria.resultado.consolidado.estimacion', []) as $row) {
            $result[(string) $row[1]][(string) $row[0]][(string) $row[2]] = $row;
        }

        return $result;
    }

    private function assertWorkbookStructure(string $path): void
    {
        $reader = new Reader;
        $reader->open($path);
        $sheets = [];
        $monthlyRows = [];

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $sheets[] = $sheet->getName();

                if ($sheet->getName() === 'SEGUIMIENTO MENSUAL') {
                    foreach ($sheet->getRowIterator() as $row) {
                        $monthlyRows[] = $row->toArray();
                    }
                }
            }
        } finally {
            $reader->close();
        }

        $this->assertSame(['SEGUIMIENTO MENSUAL', 'CATALOGOS APLICADOS', 'CONTROL DE CARGUE'], $sheets);
        $this->assertSame('CONTROL DE CARGUE', end($sheets));
        $this->assertSame(['RUTA', 'MES', 'PACIENTES', 'SERVICIOS', 'VALOR TOTAL MES'], $monthlyRows[0]);

        $periods = array_values(array_filter($monthlyRows, static fn (array $row): bool => in_array($row[0] ?? '', ['PROSTATA', 'CERVIX'], true)));
        $serviceHeaders = array_keys(array_filter($monthlyRows, static fn (array $row): bool => ($row[0] ?? '') === 'TIPO RIPS / SERVICIO'));
        $this->assertNotEmpty($periods);
        $this->assertCount(count($periods), $serviceHeaders);

        foreach ($periods as $index => $period) {
            $this->assertCount(5, $period);
            $this->assertSame('2026-', substr((string) $period[1], 0, 5));
            $this->assertGreaterThanOrEqual(0, (float) $period[4]);

            $header = $serviceHeaders[$index];
            $this->assertSame(['TIPO RIPS / SERVICIO', 'VALOR ADMINISTRATIVO'], $monthlyRows[$header]);
            $services = array_slice($monthlyRows, $header + 1, 7);
            $this->assertSame(['AC', 'AP', 'AT', 'AU', 'AM', 'AH', 'TOTAL'], array_map(
                static fn (array $row): string => explode(' ', (string) $row[0])[0], $services
            ));
            $this->assertStringContainsString('no aditivo', (string) $services[3][0]);
            $this->assertSame(round((float) $period[4], 2), round((float) $services[6][1], 2));
            $this->assertSame(round((float) $period[4], 2), round(array_sum(array_map(
                static fn (array $row): float => (float) $row[1], [$services[0], $services[1], $services[2], $services[4], $services[5]]
            )), 2));
        }
    }
}
