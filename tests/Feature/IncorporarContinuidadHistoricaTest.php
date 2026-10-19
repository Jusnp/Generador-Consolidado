<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ProgramaEjecucion;
use App\Models\User;
use App\Programas\LaMaria\LibroPaquetesEjecucion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class IncorporarContinuidadHistoricaTest extends TestCase
{
    use RefreshDatabase;

    private string $directorio;

    /** @var list<string> */
    private array $salidas = [];

    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') === 'mysql' && getenv('DB_DATABASE') !== ContinuidadHospitalariaBaseDatosTest::BASE_PRUEBAS) {
            $this->markTestSkipped('En MySQL solo corre en '.ContinuidadHospitalariaBaseDatosTest::BASE_PRUEBAS.'.');
        }

        parent::setUp();
        $this->directorio = sys_get_temp_dir().'/continuidad-'.uniqid();
        mkdir($this->directorio);
        file_put_contents($this->directorio.'/junio.json', $this->rips('FJ', '2026-06-10 08:00', '2026-06-30 23:59', '08'));
        file_put_contents($this->directorio.'/julio.json', $this->rips('FL', '2026-07-01 00:30', '2026-07-25 10:00', '01'));
    }

    protected function tearDown(): void
    {
        gc_collect_cycles();
        File::deleteDirectory($this->directorio);
        File::delete($this->salidas);
        parent::tearDown();
    }

    public function test_an_unverifiable_historical_reference_can_be_simulated_but_never_applied(): void
    {
        $ejecucion = $this->ejecucion('la-maria-prostata', 'PROSTATA', conHuellas: false);
        $antes = $ejecucion->fresh()->getAttributes();

        $this->artisan('continuidad:incorporar-historico', ['directorio' => $this->directorio, '--ejecucion' => $ejecucion->id])
            ->expectsOutputToContain('REFERENCIA HISTÓRICA NO VERIFICADA')
            ->assertSuccessful();
        $this->artisan('continuidad:incorporar-historico', ['directorio' => $this->directorio, '--ejecucion' => $ejecucion->id, '--aplicar' => true])
            ->expectsOutputToContain('REFERENCIA HISTÓRICA NO VERIFICADA: no se permite --aplicar.')
            ->assertFailed();

        $this->assertSame(0, DB::table('hospital_segmentos')->count());
        $this->assertSame(0, DB::table('hospital_paquete_movimientos')->count());
        $this->assertSame(2, ActivityLog::query()->where('action', 'continuidad_incorporacion_historica')->count());
        $this->assertEquals($antes, $ejecucion->fresh()->getAttributes());
    }

    public function test_incomplete_contradictory_or_other_route_sources_are_rejected(): void
    {
        $prostata = $this->ejecucion('la-maria-prostata', 'PROSTATA');
        $logOtraRuta = $this->ejecucion('la-maria-prostata', 'CERVIX');
        $cervix = $this->ejecucion('la-maria-cervix', 'CERVIX');

        file_put_contents($this->directorio.'/sobrante.json', $this->rips('FX', '2026-08-01 08:00', '2026-08-03 08:00', '01'));
        $this->artisan('continuidad:incorporar-historico', ['directorio' => $this->directorio, '--ejecucion' => $prostata->id])
            ->expectsOutputToContain('sobran: sobrante.json')->assertFailed();
        unlink($this->directorio.'/sobrante.json');

        file_put_contents($this->directorio.'/julio.json', $this->rips('FL', '2026-07-01 00:30', '2026-07-26 10:00', '01'));
        $this->artisan('continuidad:incorporar-historico', ['directorio' => $this->directorio, '--ejecucion' => $prostata->id])
            ->expectsOutputToContain('El contenido (SHA-256) de algún archivo difiere')->assertFailed();
        file_put_contents($this->directorio.'/julio.json', $this->rips('FL', '2026-07-01 00:30', '2026-07-25 10:00', '01'));

        $this->artisan('continuidad:incorporar-historico', ['directorio' => $this->directorio, '--ejecucion' => $logOtraRuta->id])
            ->expectsOutputToContain('La auditoría de la ejecución es de otra ruta.')->assertFailed();
        $this->artisan('continuidad:incorporar-historico', ['directorio' => $this->directorio, '--ejecucion' => $cervix->id, '--aplicar' => true])
            ->expectsOutputToContain('no tienen segmentos hospitalarios de la ruta CERVIX')->assertFailed();

        $this->assertSame(0, DB::table('hospital_paquete_movimientos')->count());
    }

    public function test_a_verifiable_reference_is_applied_once_audited_and_can_be_reverted_with_compensating_movements(): void
    {
        $ejecucion = $this->ejecucion('la-maria-prostata', 'PROSTATA');
        $argumentos = ['directorio' => $this->directorio, '--ejecucion' => $ejecucion->id, '--aplicar' => true];

        $this->artisan('continuidad:incorporar-historico', $argumentos)->expectsOutputToContain('APLICADO')->assertSuccessful();
        $this->artisan('continuidad:incorporar-historico', $argumentos)->expectsOutputToContain('Segmentos nuevos: 0 · ya registrados: 2')->assertSuccessful();

        $movimientos = DB::table('hospital_paquete_movimientos')->get();
        $this->assertSame(2, $movimientos->count());
        $this->assertSame(['SALDO_INICIAL'], $movimientos->pluck('motivo')->unique()->values()->all());
        $this->assertSame([$ejecucion->id], $movimientos->pluck('programa_ejecucion_id')->map(static fn ($id): int => (int) $id)->unique()->values()->all());
        $this->assertSame(LibroPaquetesEjecucion::PERIODO_INCORPORADO, app(LibroPaquetesEjecucion::class)->estadoPeriodo('PROSTATA', '2026-06'));

        $aplicacion = ActivityLog::query()->where('action', 'continuidad_incorporacion_historica')->get()->first(static fn (ActivityLog $log): bool => $log->metadata['aplicado'] === true);
        $this->artisan('continuidad:incorporar-historico', ['--revertir' => $aplicacion->id])->expectsOutputToContain('2 movimientos compensados')->assertSuccessful();
        $this->artisan('continuidad:incorporar-historico', ['--revertir' => $aplicacion->id])->assertFailed();

        $this->assertSame(0, (int) DB::table('hospital_paquete_movimientos')->sum('signo'));
        $this->assertSame(4, DB::table('hospital_paquete_movimientos')->count());
        $this->assertSame(['ANULADO'], DB::table('hospital_segmentos')->pluck('estado')->unique()->values()->all());
        $this->assertSame(LibroPaquetesEjecucion::PERIODO_HISTORICO, app(LibroPaquetesEjecucion::class)->estadoPeriodo('PROSTATA', '2026-06'));
    }

    /**
     * Ejecución histórica con su registro de auditoría; con huellas y con su informe
     * en disco es una referencia verificable.
     */
    private function ejecucion(string $programa, string $rutaAuditoria, bool $conHuellas = true): ProgramaEjecucion
    {
        $salida = 'HISTORICA_'.uniqid().'.xlsx';
        $ejecucion = ProgramaEjecucion::create([
            'programa_slug' => $programa, 'user_id' => User::factory()->create()->id, 'periodo' => '2026-08',
            'batch_id' => uniqid('lote-'), 'archivo_salida' => $salida, 'catalogos_aplicados' => [], 'archivos_procesados' => 2,
        ]);
        $huellas = [];

        foreach (glob($this->directorio.'/*.json') as $path) {
            $huellas[basename($path)] = hash_file('sha256', $path);
        }

        ActivityLog::create(['action' => 'seguimiento_rutas', 'metadata' => [
            'batch_id' => $ejecucion->batch_id, 'files' => array_keys($huellas), 'ruta' => $rutaAuditoria,
            ...($conHuellas ? ['files_sha256' => $huellas] : []),
        ]]);

        if ($conHuellas) {
            File::ensureDirectoryExists(storage_path('app/generated'));
            file_put_contents(storage_path('app/generated/'.$salida), 'informe');
            $this->salidas[] = storage_path('app/generated/'.$salida);
        }

        return $ejecucion;
    }

    private function rips(string $factura, string $ingreso, string $egreso, string $destino): string
    {
        return json_encode([
            'numDocumentoIdObligado' => '890905177',
            'numFactura' => $factura,
            'tipoNota' => null,
            'numNota' => null,
            'usuarios' => [[
                'tipoDocumentoIdentificacion' => 'CC',
                'numDocumentoIdentificacion' => '100',
                'codSexo' => 'M',
                'fechaNacimiento' => '1960-01-01',
                'codMunicipioResidencia' => '05001',
                'servicios' => [
                    'consultas' => [[
                        'codPrestador' => '050010608601', 'fechaInicioAtencion' => $ingreso, 'codConsulta' => '890201',
                        'codDiagnosticoPrincipal' => 'C61X', 'vrServicio' => 0, 'consecutivo' => 1,
                    ]],
                    'hospitalizacion' => [[
                        'codPrestador' => '050010608601', 'viaIngresoServicioSalud' => '01', 'fechaInicioAtencion' => $ingreso,
                        'fechaEgreso' => $egreso, 'codDiagnosticoPrincipal' => 'C61X', 'condicionDestinoUsuarioEgreso' => $destino, 'numAutorizacion' => 'AUT-1', 'consecutivo' => 1,
                    ]],
                ],
            ]],
        ], JSON_THROW_ON_ERROR);
    }
}
