<?php

namespace Tests\Feature;

use App\Models\CatalogoReferencia;
use App\Models\CatalogoReferenciaItem;
use App\Models\ProgramaEjecucion;
use App\Models\User;
use App\Programas\LaMaria\LaMariaPrograma;
use App\Programas\LaMaria\LibroPaquetesEjecucion;
use App\Programas\LaMaria\PoliticaPaqueteHospitalario;
use App\Services\SeguimientoRutaClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use RuntimeException;
use Tests\TestCase;

/**
 * Modo PERSISTENTE del libro de paquetes a través de la ejecución real de La
 * María. Corre en SQLite con la suite normal y en MySQL solo contra la base de
 * pruebas:
 *
 *   DB_CONNECTION=mysql DB_DATABASE=json_excel_pruebas_continuidad php artisan test tests/Feature/LibroPaquetesPersistenteTest.php
 */
class LibroPaquetesPersistenteTest extends TestCase
{
    use RefreshDatabase;

    private const PAQUETE = PoliticaPaqueteHospitalario::VALOR_PAQUETE;

    private const CONSULTA = 5000.0;

    private User $user;

    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') === 'mysql' && getenv('DB_DATABASE') !== ContinuidadHospitalariaBaseDatosTest::BASE_PRUEBAS) {
            $this->markTestSkipped('En MySQL solo corre en '.ContinuidadHospitalariaBaseDatosTest::BASE_PRUEBAS.'.');
        }

        parent::setUp();
        config(['programas.la-maria.libro_paquetes' => 'PERSISTENTE']);
        $this->user = User::factory()->create();
        $this->createCatalogs();
    }

    public function test_june_and_july_together_separately_or_reversed_give_the_same_ledger_and_reconciled_reports(): void
    {
        $this->inicio('2026-06');
        $juntos = $this->report([$this->junio(), $this->julio()]);
        $this->assertSame([2, 2 * self::PAQUETE], [$juntos['libro']['PAQUETES NETOS ATRIBUIDOS A LOS PERIODOS DEL INFORME'], $juntos['paquetes_valor']]);
        $neto = $this->ledgerNet();

        $this->resetLedger();
        $junio = $this->report([$this->junio()]);
        $julio = $this->report([$this->julio()]);
        $this->assertSame([1, 1], [$junio['paquetes'], $julio['paquetes']]);
        $this->assertSame([1, 0, 0], [$julio['libro']['MOVIMIENTOS NUEVOS DE ESTA EJECUCION'], $julio['libro']['MOVIMIENTOS DE REVERSO'], $julio['libro']['AJUSTES SOBRE OTROS PERIODOS']]);
        $this->assertSame($neto, $this->ledgerNet());

        $this->resetLedger();
        $primeroJulio = $this->report([$this->julio()]);
        $despuesJunio = $this->report([$this->junio()]);
        $this->assertSame([1, 1], [$primeroJulio['paquetes'], $despuesJunio['paquetes']]);
        $this->assertSame(0, $despuesJunio['libro']['AJUSTES SOBRE OTROS PERIODOS'], 'Reverso del bloque de julio y nuevo bloque de julio: neto cero en otros períodos.');
        $this->assertSame($neto, $this->ledgerNet());
    }

    public function test_reloading_july_keeps_the_period_cost_and_produces_no_new_economic_movement(): void
    {
        $this->inicio('2026-06');
        $this->report([$this->junio()]);
        $primera = $this->report([$this->julio()]);
        $recarga = $this->report([$this->julio()]);

        $this->assertSame($primera['costo_total'], $recarga['costo_total']);
        $this->assertSame(1, $recarga['paquetes']);
        $this->assertSame(self::PAQUETE, $recarga['paquetes_valor']);
        $this->assertSame([0, 0, 0], [$recarga['libro']['MOVIMIENTOS NUEVOS DE ESTA EJECUCION'], $recarga['libro']['MOVIMIENTOS DE REVERSO'], $recarga['libro']['AJUSTES SOBRE OTROS PERIODOS']]);
        $this->assertSame(2, DB::table('hospital_paquete_movimientos')->count());
    }

    public function test_split_stays_are_charged_by_real_duration(): void
    {
        $this->inicio('2026-06');
        $veinte = $this->report([
            $this->rips('F1', '100', [['2026-06-01 08:00', '2026-06-10 08:00', '08']], '2026-06-02 10:00'),
            $this->rips('F2', '100', [['2026-06-11 00:00', '2026-06-21 08:00', '01']], '2026-06-12 10:00'),
        ]);
        $treinta = $this->report([
            $this->rips('F3', '200', [['2026-06-01 08:00', '2026-06-15 08:00', '08']], '2026-06-02 10:00'),
            $this->rips('F4', '200', [['2026-06-16 00:00', '2026-07-01 08:00', '01']], '2026-06-17 10:00'),
        ]);

        $this->assertSame(1, $veinte['paquetes']);
        $this->assertSame(2, $treinta['paquetes']);
    }

    public function test_an_invoice_correction_creates_compensating_movements_only_when_authorized(): void
    {
        $this->inicio('2026-06');
        $this->report([$this->junio(), $this->julio()]);
        $corregido = $this->rips('FAC-JUL', '100', [['2026-07-01 00:30', '2026-07-03 10:00', '01']], '2026-07-02 10:00');

        $sinAutorizar = $this->report([$corregido]);
        $this->assertSame(1, $sinAutorizar['libro']['SEGMENTOS PENDIENTES DE REVISION']);
        $this->assertSame([0, 0], [$sinAutorizar['libro']['MOVIMIENTOS NUEVOS DE ESTA EJECUCION'], $sinAutorizar['libro']['MOVIMIENTOS DE REVERSO']]);
        $this->assertSame(1, $sinAutorizar['paquetes'], 'El informe usa la versión registrada mientras la nueva espera revisión.');

        $autorizada = $this->report([$corregido], correccion: true);
        $this->assertSame(-1, $autorizada['libro']['MOVIMIENTOS DE REVERSO']);
        $this->assertSame(0, $autorizada['paquetes']);
        $this->assertSame(['VIGENTE', 'REEMPLAZADO', 'PENDIENTE_REVISION', 'VIGENTE'], DB::table('hospital_segmentos')->orderBy('id')->pluck('estado')->all());
        $this->assertSame(1, (int) DB::table('hospital_paquete_movimientos')->sum('signo'));
    }

    public function test_an_old_month_before_the_start_is_loaded_without_historical_charges(): void
    {
        $this->inicio('2026-07');
        $junio = $this->report([$this->junio()]);

        $this->assertSame(0, $junio['paquetes']);
        $this->assertSame(1, $junio['libro']['PAQUETES SIMULADOS EN PERIODOS HISTORICOS']);
        $this->assertSame(LibroPaquetesEjecucion::PERIODO_HISTORICO, $junio['libro']['ESTADO DEL PERIODO 2026-06']);
        $this->assertSame(0, DB::table('hospital_paquete_movimientos')->count());
        $this->assertSame(1, DB::table('hospital_segmentos')->count());
        $this->assertStringContainsString('PERIODO HISTORICO NO VERIFICADO', $junio['observacion_junio']);
    }

    public function test_persistent_mode_is_blocked_without_a_start_point(): void
    {
        $response = $this->actingAs($this->user)->post(route('la-maria.prostata.export'), ['archivos' => [UploadedFile::fake()->createWithContent('junio.json', $this->junio())]]);

        $response->assertSessionHasErrors(['archivos' => 'El libro de paquetes persistente no tiene un punto de inicio para la ruta PROSTATA. Regístralo con php artisan continuidad:establecer-inicio antes de procesar en modo PERSISTENTE.']);
        $this->assertSame(0, DB::table('hospital_segmentos')->count());
        $this->assertSame(0, ProgramaEjecucion::query()->count());
    }

    public function test_an_error_after_registering_in_the_ledger_rolls_back_the_whole_execution(): void
    {
        $this->inicio('2026-06');
        $this->app->bind(LibroPaquetesEjecucion::class, static fn () => new class extends LibroPaquetesEjecucion
        {
            public function registrar(SeguimientoRutaClassifier $clasificador, array $files, array $allProfiles, array $routeProfiles, string $ruta, string $ejecucion, bool $esCorreccion): array
            {
                parent::registrar($clasificador, $files, $allProfiles, $routeProfiles, $ruta, $ejecucion, $esCorreccion);

                throw new RuntimeException('Fallo simulado durante la ejecución.');
            }
        });

        $this->actingAs($this->user)->post(route('la-maria.prostata.export'), ['archivos' => [UploadedFile::fake()->createWithContent('junio.json', $this->junio())]])
            ->assertSessionHasErrors('archivos');

        $this->assertSame(0, DB::table('hospital_segmentos')->count());
        $this->assertSame(0, DB::table('hospital_paquete_movimientos')->count());
        $this->assertSame(0, ProgramaEjecucion::query()->count());
    }

    public function test_switching_back_to_batch_mode_keeps_the_ledger_and_its_traceability(): void
    {
        $this->inicio('2026-06');
        $this->report([$this->junio()]);
        $antes = [DB::table('hospital_segmentos')->count(), DB::table('hospital_paquete_movimientos')->count()];

        config(['programas.la-maria.libro_paquetes' => 'POR_LOTE']);
        $porLote = $this->report([$this->julio()]);

        $this->assertSame('POR_LOTE', $porLote['libro']['MODO']);
        $this->assertSame(1, $porLote['paquetes'], 'Por lote: solo julio, sin el segmento de junio.');
        $this->assertSame($antes, [DB::table('hospital_segmentos')->count(), DB::table('hospital_paquete_movimientos')->count()]);
        $this->assertSame('POR_LOTE', $this->lastExecutionLog()['modo_paquetes']);

        config(['programas.la-maria.libro_paquetes' => 'PERSISTENTE']);
        $persistente = $this->report([$this->julio()]);
        $this->assertSame(1, $persistente['libro']['MOVIMIENTOS NUEVOS DE ESTA EJECUCION']);
        $this->assertSame(2, (int) DB::table('hospital_paquete_movimientos')->sum('signo'));
        $this->assertArrayHasKey('julio.json', $this->lastExecutionLog()['files_sha256']);
    }

    /**
     * @param  list<string>  $files
     * @return array<string, mixed>
     */
    private function report(array $files, bool $correccion = false): array
    {
        $uploads = [];

        foreach ($files as $index => $content) {
            $nombre = (json_decode($content, true)['numFactura'] ?? 'f'.$index) === 'FAC-JUN' ? 'junio.json' : ((json_decode($content, true)['numFactura'] ?? '') === 'FAC-JUL' ? 'julio.json' : 'f'.$index.'.json');
            $uploads[] = UploadedFile::fake()->createWithContent($nombre, $content);
        }

        $response = $this->actingAs($this->user)->post(route('la-maria.prostata.export'), ['archivos' => $uploads, 'correccion_autorizada' => $correccion ? '1' : '0']);
        $response->assertDownload();
        $path = storage_path('framework/testing/'.Str::uuid().'.xlsx');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $response->streamedContent());

        try {
            $workbook = IOFactory::load($path);
        } finally {
            File::delete($path);
        }

        $libro = array_map(static fn (array $fila): mixed => is_numeric($fila['VALOR']) ? (int) $fila['VALOR'] : $fila['VALOR'], $this->block($workbook, 'LIBRO DE PAQUETES'));
        $control = $this->block($workbook, 'CONTROL HOSPITALARIO (MES)');
        $costoTotal = round((float) collect($this->records($workbook, 'SEGUIMIENTO PACIENTE MES'))->sum(static fn (array $row): float => (float) $row['COSTO ACTIVO (A + CONDICIONADO)']), 2);
        $detalle = round((float) collect($this->records($workbook, 'DETALLE RIPS'))->sum(static fn (array $row): float => (float) $row['COSTO']), 2);
        $paquetes = (int) $control['TOTAL']['PAQUETES'];
        $valorPaquetes = round((float) $control['TOTAL']['COSTO PAQUETES'], 2);

        // Conciliación: RIPS cobrable + paquetes netos atribuidos al período = total consolidado.
        $this->assertSame($costoTotal, round($detalle + $valorPaquetes, 2));
        $this->assertSame([], array_values(array_diff(array_unique(array_column($this->records($workbook, 'DETALLE RIPS'), 'tipoServicio')), ['consultas', 'hospitalizacion'])), 'El DETALLE RIPS solo contiene servicios del RIPS, nunca líneas de paquete.');

        if (($libro['MODO'] ?? '') === 'PERSISTENTE') {
            $this->assertSame($paquetes, $libro['PAQUETES NETOS ATRIBUIDOS A LOS PERIODOS DEL INFORME']);
        }

        return ['costo_total' => $costoTotal, 'paquetes' => $paquetes, 'paquetes_valor' => $valorPaquetes, 'libro' => $libro,
            'observacion_junio' => (string) ($control['2026-06']['OBSERVACION'] ?? '')];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function block(Spreadsheet $workbook, string $title): array
    {
        $rows = $workbook->getSheetByName('CONTROL DE CARGUE')->toArray(null, true, false, false);
        $start = collect($rows)->search(static fn (array $row): bool => ($row[0] ?? null) === $title);
        $this->assertNotFalse($start, 'Falta el bloque '.$title);
        $header = array_map('strval', $rows[$start]);
        $block = [];

        for ($i = $start + 1; $i < count($rows) && (string) ($rows[$i][0] ?? '') !== ''; $i++) {
            $block[(string) $rows[$i][0]] = array_combine($header, $rows[$i]);
        }

        return $block;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function records(Spreadsheet $workbook, string $sheet): array
    {
        $rows = $workbook->getSheetByName($sheet)->toArray(null, true, false, false);
        $header = array_map('strval', array_shift($rows));
        $records = [];

        // La tabla termina en la primera fila vacía; debajo puede haber otros bloques (resumen por servicio).
        foreach ($rows as $row) {
            if (array_filter($row, static fn ($value): bool => $value !== null && $value !== '') === []) {
                break;
            }

            $records[] = array_combine($header, $row);
        }

        return $records;
    }

    /**
     * @return array<string, int>
     */
    private function ledgerNet(): array
    {
        return DB::table('hospital_paquete_movimientos')->selectRaw('mes_contable, SUM(signo) AS neto')->groupBy('mes_contable')->orderBy('mes_contable')
            ->pluck('neto', 'mes_contable')->map(static fn ($neto): int => (int) $neto)->filter()->all();
    }

    private function resetLedger(): void
    {
        DB::table('hospital_paquete_movimientos')->update(['movimiento_revertido_id' => null]);
        DB::table('hospital_paquete_movimientos')->delete();
        DB::table('hospital_segmentos')->update(['reemplaza_segmento_id' => null]);
        DB::table('hospital_segmentos')->delete();
    }

    /**
     * @return array<string, mixed>
     */
    private function lastExecutionLog(): array
    {
        return json_decode((string) DB::table('activity_logs')->where('action', 'seguimiento_rutas')->orderByDesc('id')->value('metadata'), true);
    }

    private function inicio(string $mes): void
    {
        $this->artisan('continuidad:establecer-inicio', ['ruta' => 'PROSTATA', 'mes' => $mes, '--motivo' => 'Prueba', '--aplicar' => true])->assertSuccessful();
    }

    private function junio(): string
    {
        return $this->rips('FAC-JUN', '100', [['2026-06-10 08:00', '2026-06-30 23:59', '08']], '2026-06-12 10:00');
    }

    private function julio(): string
    {
        return $this->rips('FAC-JUL', '100', [['2026-07-01 00:30', '2026-07-25 10:00', '01']], '2026-07-02 10:00');
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string}>  $estancias
     */
    private function rips(string $factura, string $documento, array $estancias, string $fechaConsulta): string
    {
        return json_encode([
            'numDocumentoIdObligado' => '890905177',
            'numFactura' => $factura,
            'tipoNota' => null,
            'numNota' => null,
            'usuarios' => [[
                'tipoDocumentoIdentificacion' => 'CC',
                'numDocumentoIdentificacion' => $documento,
                'codSexo' => 'M',
                'fechaNacimiento' => '1960-01-01',
                'codMunicipioResidencia' => '05001',
                'servicios' => [
                    'consultas' => [[
                        'codPrestador' => '050010608601', 'fechaInicioAtencion' => $fechaConsulta, 'codConsulta' => '890201',
                        'codDiagnosticoPrincipal' => 'C61X', 'vrServicio' => 0, 'consecutivo' => 1,
                    ]],
                    'hospitalizacion' => array_map(static fn (array $estancia, int $i): array => [
                        'codPrestador' => '050010608601', 'viaIngresoServicioSalud' => '01', 'fechaInicioAtencion' => $estancia[0],
                        'fechaEgreso' => $estancia[1], 'codDiagnosticoPrincipal' => 'C61X', 'condicionDestinoUsuarioEgreso' => $estancia[2],
                        'numAutorizacion' => 'AUT-1', 'consecutivo' => $i + 1,
                    ], $estancias, array_keys($estancias)),
                ],
            ]],
        ], JSON_THROW_ON_ERROR);
    }

    private function createCatalogs(): void
    {
        $nota = CatalogoReferencia::create(['programa_slug' => LaMariaPrograma::SLUG, 'tipo' => 'NOTA_PROSTATA', 'version' => '2026-01', 'archivo_origen' => 'prostata.xlsx', 'activo' => true]);
        CatalogoReferenciaItem::create(['catalogo_referencia_id' => $nota->id, 'codigo' => '890201', 'ruta' => 'PROSTATA', 'categoria' => 'SERVICIO_TECNICO', 'descripcion' => 'Consulta', 'tarifa_referencia' => self::CONSULTA]);
    }
}
