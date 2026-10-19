<?php

namespace Tests\Feature;

use App\Exceptions\ConcurrenciaContinuidadException;
use App\Programas\LaMaria\AlmacenContinuidadHospitalariaBaseDatos;
use App\Programas\LaMaria\LibroPaquetesHospitalarios;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Concurrencia real con dos conexiones MySQL y transacciones confirmadas. Solo
 * corre contra la base independiente de pruebas:
 *
 *   DB_CONNECTION=mysql DB_DATABASE=json_excel_pruebas_continuidad php artisan test tests/Feature/ContinuidadHospitalariaConcurrenciaMysqlTest.php
 */
class ContinuidadHospitalariaConcurrenciaMysqlTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        // Antes de arrancar la aplicación: solo MySQL y solo la base de pruebas.
        if (getenv('DB_CONNECTION') !== 'mysql' || getenv('DB_DATABASE') !== ContinuidadHospitalariaBaseDatosTest::BASE_PRUEBAS) {
            $this->markTestSkipped('Requiere MySQL en '.ContinuidadHospitalariaBaseDatosTest::BASE_PRUEBAS.'.');
        }

        parent::setUp();
        config(['database.connections.mysql_concurrente' => config('database.connections.mysql')]);
    }

    public function test_two_processes_loading_the_same_episode_recognize_each_package_once(): void
    {
        $carga = $this->carga();
        $procesoB = new LibroPaquetesHospitalarios(new AlmacenContinuidadHospitalariaBaseDatos('mysql_concurrente'));
        $almacenA = new class($procesoB, $carga) extends AlmacenContinuidadHospitalariaBaseDatos
        {
            private bool $otroProcesoEjecutado = false;

            public function __construct(private LibroPaquetesHospitalarios $otroProceso, private array $carga)
            {
                parent::__construct();
            }

            protected function antesDeConfirmar(): void
            {
                if (! $this->otroProcesoEjecutado) {
                    $this->otroProcesoEjecutado = true;
                    $this->otroProceso->registrarCarga($this->carga, 'proceso-b');
                }
            }
        };

        $resumenA = (new LibroPaquetesHospitalarios($almacenA))->registrarCarga($carga, 'proceso-a');

        $this->assertSame(1, $resumenA['reintentos']);
        $this->assertSame(0, $resumenA['movimientos']);
        $this->assertSame(['proceso-b', 'proceso-b'], DB::table('hospital_paquete_movimientos')->orderBy('id')->pluck('ejecucion_origen')->all());
        $this->assertSame(2, DB::table('hospital_paquete_movimientos')->whereNotNull('bloque_vigente')->count());
        $this->assertSame(2, DB::table('hospital_segmentos')->count());
    }

    public function test_a_patient_locked_by_another_process_is_not_written_until_the_lock_is_released(): void
    {
        $bloqueo = 'lmc:'.sha1('la-maria|PROSTATA|CC|100');
        $this->assertSame(1, (int) DB::connection('mysql_concurrente')->scalar('SELECT GET_LOCK(?, 0)', [$bloqueo]));

        try {
            (new LibroPaquetesHospitalarios(new AlmacenContinuidadHospitalariaBaseDatos(esperaBloqueoSegundos: 0)))->registrarCarga($this->carga(), 'bloqueado');
            $this->fail('La carga debía rechazarse mientras otro proceso tiene el bloqueo.');
        } catch (ConcurrenciaContinuidadException) {
            $this->assertSame(0, DB::table('hospital_segmentos')->count());
        } finally {
            DB::connection('mysql_concurrente')->scalar('SELECT RELEASE_LOCK(?)', [$bloqueo]);
        }

        (new LibroPaquetesHospitalarios(new AlmacenContinuidadHospitalariaBaseDatos))->registrarCarga($this->carga(), 'liberado');
        $this->assertSame(2, (int) DB::table('hospital_paquete_movimientos')->sum('signo'));
    }

    public function test_a_failure_in_the_middle_of_a_committed_transaction_is_fully_rolled_back(): void
    {
        $falla = new class extends AlmacenContinuidadHospitalariaBaseDatos
        {
            protected function insertarMovimiento(string $ruta, string $tipo, string $documento, array $movimiento, array $ids, mixed $ahora): void
            {
                parent::insertarMovimiento($ruta, $tipo, $documento, $movimiento, $ids, $ahora);

                throw new RuntimeException('Fallo simulado.');
            }
        };

        try {
            (new LibroPaquetesHospitalarios($falla))->registrarCarga($this->carga(), 'con-error');
        } catch (RuntimeException) {
        }

        $this->assertSame(0, DB::connection('mysql_concurrente')->table('hospital_segmentos')->count());
        $this->assertSame(0, DB::connection('mysql_concurrente')->table('hospital_paquete_movimientos')->count());
        $this->assertSame(0, (int) DB::connection('mysql_concurrente')->scalar('SELECT IS_USED_LOCK(?) IS NOT NULL', ['lmc:'.sha1('la-maria|PROSTATA|CC|100')]));
    }

    /**
     * @return list<array<string, string>>
     */
    private function carga(): array
    {
        return [
            ['ruta' => 'PROSTATA', 'prestador' => '900123456', 'factura' => 'FJ', 'tipoDocumento' => 'CC', 'documento' => '100', 'consecutivo' => '1',
                'inicio' => '2026-06-10 08:00', 'fin' => '2026-06-30 23:59', 'destino' => '08', 'archivo' => 'FJ.json', 'autorizacion' => 'AUT-1', 'prestadorServicio' => '050010608601'],
            ['ruta' => 'PROSTATA', 'prestador' => '900123456', 'factura' => 'FL', 'tipoDocumento' => 'CC', 'documento' => '100', 'consecutivo' => '1',
                'inicio' => '2026-07-01 00:30', 'fin' => '2026-07-25 10:00', 'destino' => '01', 'archivo' => 'FL.json', 'autorizacion' => 'AUT-1', 'prestadorServicio' => '050010608601'],
        ];
    }
}
