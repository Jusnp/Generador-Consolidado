<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LaMariaActivacionRealTest extends TestCase
{
    public function test_active_catalogs_export_real_months_and_reloads_preserve_costs(): void
    {
        if (getenv('SIRUTA_PRUEBA_REAL') !== '1' || getenv('DB_DATABASE') !== 'json_excel_demo_lamaria_20261008') {
            $this->markTestSkipped('Requiere copia exclusiva de demostracion y activacion expresa de la prueba real.');
        }
        $this->assertSame('json_excel_demo_lamaria_20261008', DB::connection()->getDatabaseName());
        $this->assertSame('127.0.0.1', DB::connection()->getConfig('host'));
        config(['programas.la-maria.politica_dispensacion' => 'CONDICIONADA']);
        $user = User::factory()->create(['role' => 'admin', 'active' => true]);
        $directory = base_path('outputs/activacion-completa-la-maria-20261008');
        $sources = 'C:/Users/Juan/Desktop/Trabajo/RIPS JSON RUTA AUTOINMUNES/pestaña 2/Json/';
        $groups = [
            '2026-06' => ['FEHM614147', 'FEHM614149', 'FEHM614150', 'FEHM614152'],
            '2026-07' => ['FEHM623064', 'FEHM623066', 'FEHM623069', 'FEHM623071'],
            '2026-08' => ['FEHM632724', 'FEHM632728', 'FEHM632903', 'FEHM633817'],
        ];
        $this->actingAs($user)->get(route('la-maria'))->assertSeeText('La María');
        $this->get(route('catalogos-referencia.index'))->assertSeeText('20261008-demo-completa');
        $results = [];
        $routes = getenv('SIRUTA_RUTA') === 'cervix' ? ['cervix'] : ['prostata', 'cervix'];
        $existingResults = is_file($directory.'/resultados_http.json') ? json_decode(file_get_contents($directory.'/resultados_http.json'), true, flags: JSON_THROW_ON_ERROR) : [];
        $results = array_diff_key($existingResults, array_flip($routes));
        foreach ($routes as $route) {
            $this->get(route('la-maria.'.$route))->assertSeeText('Facturación no aprobada');
            foreach ($groups as $month => $invoices) {
                $results[$route][$month] = $this->exportReal($user, $route, $invoices, $sources, $directory.'/'.$route.'_'.$month.'.xlsx');
                $this->get(route('la-maria.'.$route))->assertSeeText($month);
                file_put_contents($directory.'/resultados_http.json', json_encode($results, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            }
            $all = array_merge(...array_values($groups));
            $combined = $this->exportReal($user, $route, $all, $sources, $directory.'/CONSOLIDADO_REAL_'.strtoupper($route).'.xlsx');
            $repeated = $this->exportReal($user, $route, [...$all, $all[0]], $sources, $directory.'/'.$route.'_recarga.xlsx');
            $reversed = $this->exportReal($user, $route, array_reverse($all), $sources, $directory.'/'.$route.'_inverso.xlsx');
            $this->assertSame($combined, $repeated);
            $this->assertSame($combined, $reversed);
            $results[$route]['multimes'] = $combined;
            file_put_contents($directory.'/resultados_http.json', json_encode($results, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
        $this->assertDatabaseHas('programa_ejecuciones', ['user_id' => $user->id, 'archivos_procesados' => 12]);
    }

    public function test_final_multimes_exports_expose_categories_medications_and_anonymized_patients(): void
    {
        if (getenv('SIRUTA_PRUEBA_REAL') !== '1' || getenv('DB_DATABASE') !== 'json_excel_demo_lamaria_20261008') {
            $this->markTestSkipped('Solo copia exclusiva de demostracion.');
        }
        $this->assertSame('json_excel_demo_lamaria_20261008', DB::connection()->getDatabaseName());
        config(['programas.la-maria.politica_dispensacion' => 'CONDICIONADA']);
        $user = User::factory()->create(['role' => 'admin', 'active' => true]);
        $sources = 'C:/Users/Juan/Desktop/Trabajo/RIPS JSON RUTA AUTOINMUNES/pestaña 2/Json/';
        $invoices = ['FEHM614147', 'FEHM614149', 'FEHM614150', 'FEHM614152', 'FEHM623064', 'FEHM623066', 'FEHM623069', 'FEHM623071', 'FEHM632724', 'FEHM632728', 'FEHM632903', 'FEHM633817'];
        $expected = ['prostata' => [2549084466.68, 2714285783.60, 2692534916.18], 'cervix' => [436375193.43, 496257069.91, 500798309.07]];
        $persistentEvidence = [];
        foreach ($expected as $route => $prudent) {
            $destination = base_path('outputs/activacion-completa-la-maria-20261008/CONSOLIDADO_REAL_'.strtoupper($route).'.xlsx');
            $rows = $this->exportReal($user, $route, $invoices, $sources, $destination);
            foreach ($prudent as $index => $cost) {
                $this->assertSame($cost, (float) $rows[$index][3]);
            }
            $this->get(route('la-maria.'.$route))->assertSeeText('registros anonimizados')
                ->assertSeeText('Costos por categoría y medicamentos')
                ->assertSeeText('Hospitalización y prevención de doble cobro');
            if (getenv('LA_MARIA_LIBRO_PAQUETES') === 'PERSISTENTE') {
                $movements = DB::table('hospital_paquete_movimientos')->count();
                $segments = DB::table('hospital_segmentos')->count();
                $repeated = $this->exportReal($user, $route, $invoices, $sources, dirname($destination).'/PERSISTENTE_RECARGA_'.strtoupper($route).'.xlsx');
                $this->assertSame($rows, $repeated);
                $this->assertSame($movements, DB::table('hospital_paquete_movimientos')->count());
                $this->assertSame($segments, DB::table('hospital_segmentos')->count());
                $persistentEvidence[$route] = ['movimientos_acumulados' => $movements, 'segmentos_acumulados' => $segments, 'recarga_sin_movimientos' => true, 'costos' => $rows];
            }
        }
        if ($persistentEvidence !== []) {
            file_put_contents(base_path('outputs/activacion-completa-la-maria-20261008/persistencia_http.json'), json_encode($persistentEvidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
    }

    /** @param list<string> $invoices
     * @return list<array<int, mixed>>
     */
    private function exportReal(User $user, string $route, array $invoices, string $sources, string $destination): array
    {
        $uploads = array_map(static fn (string $invoice): UploadedFile => new UploadedFile($sources.$invoice.'.json', $invoice.'.json', 'application/json', null, true), $invoices);
        $response = $this->actingAs($user)->post(route('la-maria.'.$route.'.export'), ['archivos' => $uploads]);
        $response->assertDownload();
        file_put_contents($destination, $response->streamedContent());
        // COSTOS Y BRECHAS ya no se exporta: sus filas siguen calculándose y quedan en la sesión de la ruta.
        $rows = session('la-maria.resultado.'.$route)['costos'] ?? [];
        $this->assertNotEmpty($rows);

        return $rows;
    }
}
