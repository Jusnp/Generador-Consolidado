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
            $resumen = $this->readMonthlyReport($path, '2026-09')['summary']['PROSTATA'];

            $this->assertSame(2137528852.70, round((float) $resumen['TOTAL'][3], 2));
            $this->assertSame(3564010500.79, round((float) $resumen['TOTAL'][4], 2));
            $this->assertSame(59061015.53, round((float) $resumen['AT'][4], 2));
            $this->assertSame(14172581.38, round((float) $resumen['AM'][4], 2));
            $cobertura = $this->readSeptemberMedicationCoverage($path);
            $this->assertSame(2947, array_sum(array_column($cobertura, 'registros')));
            $this->assertSame(414, $cobertura['CONDICIONADO AP']['registros']);
            $this->assertSame(754852071.28, round($cobertura['CONDICIONADO AP']['potencial'], 2));
            $this->assertSame(1374, ($cobertura['INCLUIDO DEMOSTRADO EN AH']['registros'] ?? 0)
                + ($cobertura['CONDICIONADO AH']['registros'] ?? 0));
        } finally {
            DB::rollBack();
            @unlink($path);
        }
    }

    /** @return array<string, array{registros: int, potencial: float}> */
    private function readSeptemberMedicationCoverage(string $path): array
    {
        $reader = new Reader;
        $reader->open($path);
        $coverage = [];

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                if ($sheet->getName() !== 'COBERTURA AM RN14') {
                    continue;
                }

                foreach ($sheet->getRowIterator() as $row) {
                    $values = $row->toArray();

                    if (($values[0] ?? '') !== 'PROSTATA' || ($values[1] ?? '') !== '2026-09') {
                        continue;
                    }

                    $class = (string) ($values[17] ?? '');
                    $coverage[$class] ??= ['registros' => 0, 'potencial' => 0.0];
                    $coverage[$class]['registros']++;
                    $coverage[$class]['potencial'] += (float) ($values[19] ?? 0);
                }
            }
        } finally {
            $reader->close();
        }

        return $coverage;
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
            $batch = $this->readCombinedSummaries($destination);

            foreach ($baseline as $month => $routes) {
                foreach ($routes as $route => $expected) {
                    foreach ($expected['porTipo'] as $type => $values) {
                        $row = $batch['summary'][$month][$route][$type] ?? null;
                        $this->assertNotNull($row, "$month $route $type");

                        foreach (['registros' => 2, 'costoA' => 3, 'central' => 4,
                            'condicionado' => 6, 'unidades' => 8, 'pendiente' => 10] as $metric => $column) {
                            $this->assertSame(round((float) $values[$metric], 2), round((float) ($row[$column] ?? 0), 2),
                                "$month $route $type $metric");
                        }
                    }

                    $this->assertSame(round((float) $expected['central'], 2), $batch['manager'][$month][$route] ?? null);
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
        $expectedAdministrative = [
            '2026-06' => ['PROSTATA' => 3101718599.63, 'CERVIX' => 2345268719.19],
            '2026-07' => ['PROSTATA' => 3221920626.87, 'CERVIX' => 857538295.96],
            '2026-08' => ['PROSTATA' => 3234122470.60, 'CERVIX' => 1071433141.16],
            '2026-09' => ['PROSTATA' => 3564010500.79, 'CERVIX' => 1397026965.97],
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
                $result[$month] = $this->readMonthlyReport($path, $month);

                foreach (['PROSTATA', 'CERVIX'] as $route) {
                    $this->assertArrayHasKey($route, $result[$month]['summary']);
                    $rows = $result[$month]['summary'][$route];
                    $this->assertSame(['AC', 'AP', 'AT', 'AU', 'AM', 'AH', 'TOTAL'], array_keys($rows));
                    $this->assertSame($expectedAdministrative[$month][$route], round((float) $rows['TOTAL'][4], 2));
                    $this->assertSame(round((float) $rows['TOTAL'][4], 2), $result[$month]['manager'][$route]);
                    $reference = $route === 'PROSTATA' ? 4000000000.0 : 2000000000.0;
                    $this->assertSame($reference, $result[$month]['indicators'][$route]['INDICADOR GERENCIAL FIJO']);
                    $this->assertSame(round((float) $rows['TOTAL'][4] - $reference, 2),
                        $result[$month]['indicators'][$route]['VARIACIÓN CONTRA INDICADOR']);
                    $this->assertSame((float) $result[$month]['patients'][$route],
                        $result[$month]['indicators'][$route]['PACIENTES ÚNICOS']);
                    $this->assertEqualsWithDelta((float) $rows['TOTAL'][4] / $reference,
                        $result[$month]['indicators'][$route]['PORCENTAJE DEL INDICADOR'], 1e-9);
                    $this->assertSame((float) $rows['TOTAL'][10], $result[$month]['indicators'][$route]['REGISTROS PENDIENTES']);
                    $differences = array_values(array_filter($result[$month]['differences'],
                        static fn (array $row): bool => $row[0] === $route));

                    if ($month !== '2026-09') {
                        $observado = array_sum(array_map(static fn (array $row): float => (float) ($row[16] ?? 0), $differences));
                        $desviacion = array_sum(array_map(static fn (array $row): float => (float) ($row[17] ?? 0), $differences));
                        $this->assertEqualsWithDelta((float) $rows['TOTAL'][4] - $observado, $desviacion, 0.05);
                    } else {
                        $this->assertSame('', $differences[0][16] ?? '');
                    }
                }

                if ($month === '2026-09') {
                    $this->assertSame(2137528852.70, round((float) $result[$month]['summary']['PROSTATA']['TOTAL'][3], 2));
                    $this->assertSame(3564010500.79, round((float) $result[$month]['summary']['PROSTATA']['TOTAL'][4], 2));
                    $this->assertSame(521906976.77, round((float) $result[$month]['summary']['CERVIX']['TOTAL'][3], 2));
                    $this->assertSame(1397026965.97, round((float) $result[$month]['summary']['CERVIX']['TOTAL'][4], 2));
                    $this->assertGreaterThan(0, (float) $result[$month]['summary']['PROSTATA']['AU'][9]);
                    $this->assertGreaterThan(0, (float) $result[$month]['summary']['CERVIX']['AU'][9]);
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
            $batch = $this->readCombinedSummaries($batchPath);

            foreach (array_keys($groups) as $month) {
                foreach (['PROSTATA', 'CERVIX'] as $route) {
                    foreach (['AC', 'AP', 'AT', 'AU', 'AM', 'AH', 'TOTAL'] as $type) {
                        foreach ([2, 3, 4, 5, 6, 8, 9, 10] as $column) {
                            $this->assertSame(
                                round((float) ($result[$month]['summary'][$route][$type][$column] ?? 0), 2),
                                round((float) ($batch['summary'][$month][$route][$type][$column] ?? 0), 2),
                                "$month $route $type columna $column"
                            );
                        }
                    }

                    foreach (['AC', 'AP', 'AT', 'AU', 'AM', 'AH'] as $type) {
                        foreach ([2, 3, 4] as $column) {
                            $this->assertSame(
                                round((float) ($result[$month]['managerRows'][$route][$type][$column] ?? 0), 2),
                                round((float) ($batch['managerRows'][$month][$route][$type][$column] ?? 0), 2),
                                "$month $route vista gerencial $type columna $column"
                            );
                        }
                    }

                    $this->assertSame($result[$month]['manager'][$route], $batch['manager'][$month][$route] ?? null);
                }
            }
        } finally {
            DB::rollBack();
        }

        file_put_contents($destination.'/resultados_mensuales.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->assertNotSame($result['2026-06']['summary']['PROSTATA']['TOTAL'][4], $result['2026-07']['summary']['PROSTATA']['TOTAL'][4]);
        $this->assertNotSame($result['2026-07']['summary']['CERVIX']['TOTAL'][4], $result['2026-08']['summary']['CERVIX']['TOTAL'][4]);
        $this->assertSame($before, DB::table('programa_ejecuciones')->count());
    }

    /** @return array<string, mixed> */
    private function readCombinedSummaries(string $path): array
    {
        $reader = new Reader;
        $reader->open($path);
        $result = ['summary' => [], 'managerRows' => [], 'manager' => []];

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                if ($sheet->getName() !== 'SEGUIMIENTO MENSUAL') {
                    continue;
                }

                $route = '';
                $month = '';
                $inSummary = false;
                $inManager = false;

                foreach ($sheet->getRowIterator() as $row) {
                    $values = $row->toArray();
                    $first = (string) ($values[0] ?? '');

                    if (str_starts_with($first, 'RESUMEN ECONÓMICO POR SERVICIO')) {
                        $route = str_contains($first, 'PRÓSTATA') ? 'PROSTATA' : 'CERVIX';
                        preg_match('/20\d{2}-\d{2}/', $first, $matches);
                        $month = $matches[0] ?? '';
                        $inSummary = false;
                        $inManager = false;
                    } elseif ($first === 'TIPO RIPS' && ($values[2] ?? '') === 'REGISTROS') {
                        $inSummary = true;
                        $inManager = false;
                    } elseif (str_starts_with($first, 'VISTA GERENCIAL RECLASIFICADA')) {
                        $inSummary = false;
                        $inManager = true;
                    } elseif ($inSummary && in_array($first, ['AC', 'AP', 'AT', 'AU', 'AM', 'AH', 'TOTAL'], true)) {
                        $result['summary'][$month][$route][$first] = $values;

                        if ($first === 'TOTAL') {
                            $inSummary = false;
                        }
                    } elseif ($inManager && in_array($first, ['AC', 'AP', 'AT', 'AU', 'AM', 'AH'], true)) {
                        $result['managerRows'][$month][$route][$first] = $values;
                    } elseif ($inManager && $first === 'TOTAL') {
                        $result['manager'][$month][$route] = round((float) ($values[4] ?? 0), 2);
                        $inManager = false;
                    }
                }
            }
        } finally {
            $reader->close();
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function readMonthlyReport(string $path, string $month): array
    {
        $reader = new Reader;
        $reader->open($path);
        $result = ['summary' => [], 'manager' => [], 'managerRows' => [], 'differences' => [], 'pending' => [], 'rules' => [], 'indicators' => [], 'patients' => []];

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $sheetName = $sheet->getName();
                $route = '';
                $inSummary = false;
                $inManager = false;

                foreach ($sheet->getRowIterator() as $row) {
                    $values = $row->toArray();
                    $first = (string) ($values[0] ?? '');

                    if ($sheetName === 'SEGUIMIENTO MENSUAL') {
                        if (in_array($first, ['PROSTATA', 'CERVIX'], true) && ($values[1] ?? '') === $month) {
                            $result['patients'][$first] = (int) ($values[2] ?? 0);
                        } elseif (str_starts_with($first, 'RESUMEN ECONÓMICO POR SERVICIO')) {
                            $route = str_contains($first, 'PRÓSTATA') ? 'PROSTATA' : 'CERVIX';
                            $inSummary = false;
                            $inManager = false;
                        } elseif ($first === 'TIPO RIPS' && ($values[2] ?? '') === 'REGISTROS') {
                            $inSummary = true;
                            $inManager = false;
                        } elseif ($route !== '' && in_array($first, ['PACIENTES ÚNICOS', 'INDICADOR GERENCIAL FIJO', 'VARIACIÓN CONTRA INDICADOR', 'PORCENTAJE DEL INDICADOR', 'REGISTROS PENDIENTES'], true)) {
                            $result['indicators'][$route][$first] = (float) ($values[3] ?? 0);
                        } elseif (str_starts_with($first, 'VISTA GERENCIAL RECLASIFICADA')) {
                            $inSummary = false;
                            $inManager = true;
                        } elseif ($inSummary && in_array($first, ['AC', 'AP', 'AT', 'AU', 'AM', 'AH', 'TOTAL'], true)) {
                            $result['summary'][$route][$first] = $values;
                            if ($first === 'TOTAL') {
                                $inSummary = false;
                            }
                        } elseif ($inManager && in_array($first, ['AC', 'AP', 'AT', 'AU', 'AM', 'AH'], true)) {
                            $result['managerRows'][$route][$first] = $values;
                        } elseif ($inManager && $first === 'TOTAL') {
                            $result['manager'][$route] = round((float) ($values[4] ?? 0), 2);
                            $inManager = false;
                        }
                    } elseif ($sheetName === 'DIFERENCIAS HISTÓRICAS' && in_array($first, ['PROSTATA', 'CERVIX'], true) && ($values[1] ?? '') === $month) {
                        $result['differences'][] = $values;
                    } elseif ($sheetName === 'PENDIENTES DE VALORIZACIÓN' && in_array($first, ['PROSTATA', 'CERVIX'], true) && ($values[1] ?? '') === $month) {
                        $result['pending'][] = $values;
                    } elseif ($sheetName === 'REGLAS APLICADAS' && in_array($first, ['PROSTATA', 'CERVIX'], true)) {
                        $result['rules'][] = $values;
                    }
                }
            }
        } finally {
            $reader->close();
        }

        return $result;
    }
}
