<?php

namespace Tests\Feature;

use App\Models\CatalogoReferencia;
use App\Models\User;
use App\Programas\LaMaria\HistoricoTarifarioConsolidado;
use App\Services\CatalogoReferenciaActivator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OpenSpout\Reader\XLSX\Reader;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LaMariaConciliacionSeptiembreTest extends TestCase
{
    /** Hojas retiradas del Excel habitual; los cálculos permanecen en el motor. */
    private const HOJAS_RETIRADAS = ['COSTOS Y BRECHAS', 'EXPLICACION BRECHA', 'MEDICAMENTOS', 'SERVICIOS SIN TARIFA', 'HOSPITALIZACION',
        'RESUMEN POR TIPO RIPS', 'SERVICIOS POR CODIGO', 'CATEGORIAS NOTA', 'AT Y AU', 'EPISODIOS AU', 'CONTROL DOS VISTAS',
        'COSTO A ACTUAL', 'ESTIMACIÓN ADMINISTRATIVA'];

    private const BASE = 'C:/Users/Juan/Desktop/Trabajo/RIPS JSON RUTA AUTOINMUNES/pestaña 2/';

    /**
     * La presencia de un informe de septiembre no altera el cálculo autónomo ni el costo A.
     *
     * @return array<string, array{0: array{PROSTATA: string, CERVIX: string}, 1: array{prostata: float, cervix: float}, 2: string}>
     */
    public static function informesAdministrativos(): array
    {
        return [
            'informes anteriores (sin informe del mes)' => [[
                'PROSTATA' => self::BASE.'Nueva carpeta/INFORME ADMIN PROSTATA PARA JUAN.xlsx',
                'CERVIX' => self::BASE.'Nueva carpeta/INFORME ADMIN CERVIX PARA JUAN.xlsx',
            ], ['prostata' => 3443824624.01, 'cervix' => 1484004673.66], 'RESUMEN_AT'],
            'informes nuevos (con informe del mes)' => [[
                'PROSTATA' => 'C:/Users/Juan/Downloads/Informe Administrativo CaP - Oct 2025 - sept 2026 nueva clasificación EJECUCION.xlsx',
                'CERVIX' => 'C:/Users/Juan/Downloads/Informe Administrativo CCU - Oct 2025 - septi 2026.xlsx nueva clasificación Ejecución.xlsx',
            ], ['prostata' => 3443824624.01, 'cervix' => 1484004673.66], 'INFORME_DEL_MES'],
        ];
    }

    /**
     * @param  array{PROSTATA: string, CERVIX: string}  $informes
     * @param  array{prostata: float, cervix: float}  $centrales
     */
    #[DataProvider('informesAdministrativos')]
    public function test_four_september_jsons_generate_clean_route_workbooks_with_temporally_linked_schemes(array $informes, array $centrales, string $sufijo): void
    {
        if (getenv('SIRUTA_PRUEBA_SEPTIEMBRE') !== '1' || getenv('DB_DATABASE') !== 'json_excel_demo_lamaria_20261008') {
            $this->markTestSkipped('Solo se ejecuta en la base aislada de demostración, con autorización expresa.');
        }

        if (! is_file($informes['PROSTATA']) || ! is_file($informes['CERVIX'])) {
            $this->markTestSkipped('Requiere los informes administrativos en su carpeta privada.');
        }

        $this->assertSame('json_excel_demo_lamaria_20261008', DB::connection()->getDatabaseName());
        $this->assertGreaterThan(0, CatalogoReferencia::query()->active()->count(),
            'La base aislada debe tener los catálogos clínicos activos antes de procesar los JSON.');
        config(['programas.la-maria.politica_dispensacion' => 'CONDICIONADA']);
        config(['programas.la-maria.libro_paquetes' => 'POR_LOTE']);
        config(['programas.la-maria.informes_administrativos' => ['requerido' => true, ...$informes]]);
        $sources = self::BASE.'Septiembre json/';
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
                'prostata' => ['pacientes' => 1034, 'registros' => 11360, 'principal' => 2137528852.70, 'central' => $centrales['prostata']],
                'cervix' => ['pacientes' => 442, 'registros' => 6954, 'principal' => 521906976.77, 'central' => $centrales['cervix']],
            ] as $route => $expected) {
                $uploads = array_map(static fn (string $invoice): UploadedFile => new UploadedFile(
                    $sources.$invoice.'.json', $invoice.'.json', 'application/json', null, true
                ), $invoices);
                $response = $this->actingAs($user)->post(route('la-maria.'.$route.'.export'), ['archivos' => $uploads]);
                $response->assertDownload();
                $output = $destination.'/LA_MARIA_'.strtoupper($route).'_SEPTIEMBRE_'.$sufijo.'.xlsx';
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
                $this->assertSame(['TIPO RIPS / SERVICIO', 'REGISTROS RIPS', 'UNIDADES VALORIZADAS', 'VALOR ADMINISTRATIVO'], $first[$serviceHeader]);
                $services = array_slice($first, $serviceHeader + 1, 7);
                $this->assertSame(['AC', 'AP', 'AT', 'AU', 'AM', 'AH', 'TOTAL'], array_map(
                    static fn (array $row): string => explode(' ', (string) $row[0])[0], $services
                ));
                $this->assertSame($expected['registros'], (int) $services[6][1]);
                $this->assertSame($route === 'prostata' ? [1170, 4975, 2178, 25, 2947, 65] : [700, 2069, 1830, 29, 2293, 33],
                    array_map(static fn (array $row): int => (int) $row[1], array_slice($services, 0, 6)));
                $this->assertSame(0.0, (float) $services[3][3]);
                $this->assertSame($expected['central'], round((float) $services[6][3], 2));
                $costA = collect($first)->first(static fn (array $row): bool => ($row[0] ?? '') === 'COSTO A ACREDITADO (NO SUMA AL ADMINISTRATIVO)');
                $this->assertNotNull($costA);
                $this->assertSame($expected['principal'], round((float) $costA[3], 2));
                $this->assertSame($expected['central'], round(array_sum(array_map(
                    static fn (array $row): float => (float) $row[3], [$services[0], $services[1], $services[2], $services[4], $services[5]]
                )), 2));
                $auHeader = array_search('DETALLE AU (NO ADITIVO)', array_column($first, 0), true);
                $this->assertNotFalse($auHeader);
                $this->assertSame($route === 'prostata' ? 25 : 29, (int) $first[$auHeader + 1][1]);
                $this->assertSame($route === 'prostata' ? [23, 13, 522542.93] : [28, 22, 884303.42],
                    [(int) $first[$auHeader + 2][1], (int) $first[$auHeader + 2][2], round((float) $first[$auHeader + 2][3], 2)]);
                $this->assertSame($route === 'prostata' ? [12, 482347.32] : [18, 723520.98],
                    [(int) $first[$auHeader + 4][1], round((float) $first[$auHeader + 4][3], 2)]);
                $this->assertNotContains('CONSULTA 890701 EN INFORME DEL MES (REFERENCIA)', array_column($first, 0));

                $stateHeader = array_search('ESTADO DE REGISTROS', array_column($first, 0), true);
                $this->assertNotFalse($stateHeader);

                foreach (array_slice($first, $stateHeader + 1, 6) as $index => $state) {
                    $counts = array_map('intval', explode(' / ', (string) $state[3]));
                    $this->assertSame((int) $services[$index][1], (int) $state[1] + (int) $state[2] + array_sum($counts));
                }
                $estados = collect($first)->keyBy(0);
                $this->assertSame($route === 'prostata' ? [56, 50, 9] : [25, 32, 8],
                    [(int) $estados['AH'][1], (int) $estados['AH: REGISTROS RIPS CON EGRESO'][3], (int) $estados['AH: REGISTROS INCLUIDOS'][1]]);
                $this->assertSame($route === 'prostata' ? 6 : 2,
                    (int) $estados['AH: INTERNACIONES SOLO COMO SERVICIO AP'][1]);
                $this->assertSame(0,
                    (int) $estados['AP: CONDICIONADO POR POSIBLE INCLUSIÓN EN PAQUETE AH'][1]);
                $consultasSinValor = array_values(array_filter($first, static fn (array $row): bool => ($row[0] ?? '') === 'CONSULTA 890701 SIN VALOR INDEPENDIENTE'));
                $this->assertSame($route === 'prostata' ? 10 : 6, array_sum(array_map(static fn (array $row): int => (int) $row[1], $consultasSinValor)));
                $this->assertSame(['INCLUIDO EN PAQUETE HOSPITALARIO AH'], array_unique(array_column($consultasSinValor, 3)));
                // La fuente del total queda declarada sin comparación con el informe IPS.
                $fuente = array_slice($first, $serviceHeader + 9, 3);
                $this->assertSame('FUENTE DEL TOTAL', $fuente[0][0]);
                $this->assertStringStartsWith('CÁLCULO AUTÓNOMO', (string) $fuente[0][1]);
                $this->assertNotContains('VALOR AUTÓNOMO (SIN INFORME DEL MES)', array_column($first, 0));
                $this->assertNotContains('AJUSTE RESPALDADO POR LA CONCILIACIÓN DEL MES', array_column($first, 0));

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

    public function test_active_demo_catalogue_values_original_jsons_without_access_to_historical_workbooks(): void
    {
        if (getenv('SIRUTA_PRUEBA_SEPTIEMBRE') !== '1' || getenv('DB_DATABASE') !== 'json_excel_demo_lamaria_20261008') {
            $this->markTestSkipped('Solo se ejecuta en la base aislada de demostración.');
        }

        $this->assertSame('json_excel_demo_lamaria_20261008', DB::connection()->getDatabaseName());
        $this->assertGreaterThan(0, CatalogoReferencia::query()->active()->count(),
            'La base aislada debe tener los catálogos clínicos activos antes de procesar los JSON.');
        $privateSnapshot = (string) (getenv('SIRUTA_CATALOGO_PRIVADO') ?: base_path('outputs/catalogo-tarifario-20261024/consolidado-tarifario-reproducible-20261025-privado.json'));
        $this->assertFileExists($privateSnapshot, 'Falta el consolidado privado; la prueba no sustituye sus precios.');
        $payload = json_decode((string) file_get_contents($privateSnapshot), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(541, $payload['items']);
        $this->assertGreaterThan(0, count(array_filter($payload['items'],
            static fn (array $item): bool => $item['tipo'] === 'AP' && $item['unidades'] !== [])),
            'El consolidado debe conservar las unidades por paciente y fecha para evitar repetir AP idénticos.');
        $version = 'prueba-aislada-'.Str::uuid();

        $beforeUsers = DB::table('users')->count();
        $beforeExecutions = DB::table('programa_ejecuciones')->count();
        $destination = getenv('SIRUTA_SEPTIEMBRE_DESTINATION') ?: base_path('outputs/catalogo-tarifario-20261024');
        if (! is_dir($destination)) {
            mkdir($destination, 0775, true);
        }

        DB::beginTransaction();
        try {
            $catalogue = HistoricoTarifarioConsolidado::guardarInactivo($payload, $version);
            $this->assertFalse($catalogue->activo);
            $this->assertSame(542, $catalogue->items()->count());
            $user = User::factory()->create(['role' => 'admin', 'active' => true]);
            $this->actingAs($user);
            app(CatalogoReferenciaActivator::class)->activar($catalogue, []);
            $this->assertTrue($catalogue->fresh()->activo);
            $this->assertStringContainsString('CATALOGO ', HistoricoTarifarioConsolidado::activo()->fuentes()[0]['archivo']);

            config(['programas.la-maria.politica_dispensacion' => 'CONDICIONADA']);
            config(['programas.la-maria.libro_paquetes' => 'POR_LOTE']);
            foreach (['con_historico' => false, 'sin_historico' => true] as $mode => $withoutHistory) {
                config(['programas.la-maria.informes_administrativos' => $withoutHistory
                    ? ['requerido' => true, 'PROSTATA' => 'C:/ruta-inexistente/prostata.xlsx', 'CERVIX' => 'C:/ruta-inexistente/cervix.xlsx']
                    : ['requerido' => true, ...self::informesAdministrativos()['informes anteriores (sin informe del mes)'][0]]]);

                foreach (['prostata' => [1034, 11360, 2137528852.70, 3443824624.01],
                    'cervix' => [442, 6954, 521906976.77, 1484004673.66]] as $route => $expected) {
                    $sources = self::BASE.'Septiembre json/';
                    $uploads = array_map(static fn (string $invoice): UploadedFile => new UploadedFile(
                        $sources.$invoice.'.json', $invoice.'.json', 'application/json', null, true
                    ), ['FEHM643506', 'FEHM643507', 'FEHM643504', 'FEHM643505']);
                    $response = $this->post(route('la-maria.'.$route.'.export'), ['archivos' => $uploads]);
                    $response->assertDownload();
                    $output = $destination.'/LA_MARIA_'.strtoupper($route).'_SEPTIEMBRE_CATALOGO_'.strtoupper($mode).'.xlsx';
                    file_put_contents($output, $response->streamedContent());
                    [$names, $first] = $this->firstSheet($output);
                    $this->assertCount(9, $names);
                    $this->assertSame('CONTROL DE CARGUE', $names[8]);
                    $this->assertSame(['2026-09', $expected[0], $expected[1], $expected[3]],
                        [(string) $first[1][0], (int) $first[1][1], (int) $first[1][2], round((float) $first[1][3], 2)]);
                    $costA = collect($first)->first(static fn (array $row): bool => ($row[0] ?? '') === 'COSTO A ACREDITADO (NO SUMA AL ADMINISTRATIVO)');
                    $this->assertSame($expected[2], round((float) $costA[3], 2));
                    $serviceHeader = array_search('TIPO RIPS / SERVICIO', array_column($first, 0), true);
                    $services = array_slice($first, $serviceHeader + 1, 7);
                    $this->assertSame(0.0, (float) $services[3][3]);
                    $this->assertSame($expected[3], round(array_sum(array_map(
                        static fn (array $row): float => (float) $row[3], [$services[0], $services[1], $services[2], $services[4], $services[5]]
                    )), 2));
                }
            }
        } finally {
            DB::rollBack();
        }

        $this->assertFalse(CatalogoReferencia::query()->where('version', $version)->exists());
        $this->assertFalse(CatalogoReferencia::query()->where('tipo', HistoricoTarifarioConsolidado::TIPO)->active()->exists());
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
