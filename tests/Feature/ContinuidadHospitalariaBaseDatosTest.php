<?php

namespace Tests\Feature;

use App\Exceptions\ConcurrenciaContinuidadException;
use App\Programas\LaMaria\AlmacenContinuidadHospitalariaBaseDatos;
use App\Programas\LaMaria\LibroPaquetesHospitalarios;
use App\Programas\LaMaria\PoliticaPaqueteHospitalario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Libro de paquetes con el almacén en base de datos. Corre en SQLite en memoria
 * con la suite normal y en MySQL solo contra la base independiente de pruebas:
 *
 *   DB_CONNECTION=mysql DB_DATABASE=json_excel_pruebas_continuidad php artisan test tests/Feature/ContinuidadHospitalariaBaseDatosTest.php
 */
class ContinuidadHospitalariaBaseDatosTest extends TestCase
{
    use RefreshDatabase;

    public const BASE_PRUEBAS = 'json_excel_pruebas_continuidad';

    private const PAQUETE = PoliticaPaqueteHospitalario::VALOR_PAQUETE;

    protected function setUp(): void
    {
        // Antes de arrancar la aplicación: nunca migrar ni truncar otra base MySQL.
        if (getenv('DB_CONNECTION') === 'mysql' && getenv('DB_DATABASE') !== self::BASE_PRUEBAS) {
            $this->markTestSkipped('Las pruebas MySQL de continuidad solo corren en '.self::BASE_PRUEBAS.'.');
        }

        parent::setUp();
    }

    public function test_june_and_july_together_separately_or_reversed_give_the_same_episodes_packages_and_cost(): void
    {
        $junio = [$this->segmento('FJ', '2026-06-10 08:00', '2026-06-30 23:59', '08')];
        $julio = [$this->segmento('FL', '2026-07-01 00:30', '2026-07-25 10:00', '01')];

        $juntos = $this->escenario([[...$junio, ...$julio]]);
        $separados = $this->escenario([$junio, $julio]);
        $invertidos = $this->escenario([$julio, $junio]);

        $this->assertSame(['episodios' => 1, 'paquetes_netos' => 2, 'costo_por_mes' => ['2026-06' => round(self::PAQUETE, 2), '2026-07' => round(self::PAQUETE, 2)], 'costo_acumulado' => round(2 * self::PAQUETE, 2)], $juntos['equivalencia']);
        $this->assertSame($juntos['equivalencia'], $separados['equivalencia']);
        $this->assertSame($juntos['equivalencia'], $invertidos['equivalencia']);
        $this->assertSame(0, $separados['ajustes']);
        $this->assertSame(1, $invertidos['ajustes']);
        $this->assertSame(0.0, $invertidos['residuo']);
    }

    public function test_repeating_the_same_json_or_the_same_invoice_changes_nothing(): void
    {
        $archivo = [$this->segmento('FJ', '2026-06-10 08:00', '2026-06-30 23:59', '08'), $this->segmento('FL', '2026-07-01 00:30', '2026-07-03 10:00', '01')];
        $copiaEnOtroArchivo = array_map(static fn (array $segmento): array => [...$segmento, 'archivo' => 'copia-'.$segmento['archivo']], $archivo);
        $libro = $this->libro();

        $libro->registrarCarga($archivo, 'ejecucion-1');
        $mismoJson = $libro->registrarCarga($archivo, 'ejecucion-2');
        $mismaFactura = $libro->registrarCarga($copiaEnOtroArchivo, 'ejecucion-3');
        $facturaConDiferencias = $libro->registrarCarga([$this->segmento('FL', '2026-07-01 00:30', '2026-07-09 10:00', '01')], 'ejecucion-4');

        $this->assertSame([2, 0], [$mismoJson['sin_cambio'], $mismoJson['movimientos']]);
        $this->assertSame([2, 0], [$mismaFactura['sin_cambio'], $mismaFactura['movimientos']]);
        $this->assertSame([1, 0], [$facturaConDiferencias['pendientes_revision'], $facturaConDiferencias['movimientos']]);
        $this->assertSame(3, DB::table('hospital_segmentos')->count());
        $this->assertSame(1, DB::table('hospital_paquete_movimientos')->count());
    }

    public function test_an_authorized_correction_replaces_the_invoice_with_compensating_movements_and_keeps_history(): void
    {
        $junio = [$this->segmento('FJ', '2026-06-10 08:00', '2026-06-30 23:59', '08')];
        $julio = [$this->segmento('FL', '2026-07-01 00:30', '2026-07-03 10:00', '01')];
        $julioCorregido = [$this->segmento('FL', '2026-07-01 00:30', '2026-07-08 10:00', '01')];

        $corregido = $this->escenario([[...$junio, ...$julio], $julioCorregido], [1 => true]);
        $referencia = $this->escenario([[...$junio, ...$julioCorregido]]);

        $this->assertSame($referencia['equivalencia'], $corregido['equivalencia']);
        $this->assertSame(['VIGENTE', 'REEMPLAZADO', 'VIGENTE'], $corregido['estados_segmentos']);
        $this->assertSame(['RECONOCIMIENTO', 'CORRECCION_HISTORICA'], $corregido['motivos']);
    }

    public function test_split_stays_are_charged_by_real_duration_and_a_real_discharge_starts_a_new_episode(): void
    {
        $veinte = $this->escenario([[$this->segmento('F1', '2026-06-01 08:00', '2026-06-10 08:00', '08'), $this->segmento('F2', '2026-06-11 00:00', '2026-06-21 08:00', '01')]]);
        $treinta = $this->escenario([[$this->segmento('F1', '2026-06-01 08:00', '2026-06-15 08:00', '08'), $this->segmento('F2', '2026-06-16 00:00', '2026-07-01 08:00', '01')]]);
        $sesenta = $this->escenario([[
            $this->segmento('F1', '2026-06-01 08:00', '2026-06-20 23:59', '08'),
            $this->segmento('F2', '2026-06-21 00:30', '2026-07-10 23:59', '08'),
            $this->segmento('F3', '2026-07-11 00:30', '2026-07-31 08:00', '01'),
        ]]);
        $reingreso = $this->escenario([[$this->segmento('FJ', '2026-06-10 08:00', '2026-06-30 23:59', '01'), $this->segmento('FL', '2026-07-03 08:00', '2026-07-05 10:00', '01')]]);

        $this->assertSame([1, 1], [$veinte['equivalencia']['episodios'], $veinte['equivalencia']['paquetes_netos']]);
        $this->assertSame([1, 2], [$treinta['equivalencia']['episodios'], $treinta['equivalencia']['paquetes_netos']]);
        $this->assertSame([1, 3], [$sesenta['equivalencia']['episodios'], $sesenta['equivalencia']['paquetes_netos']]);
        $this->assertSame(round(3 * self::PAQUETE, 2), $sesenta['equivalencia']['costo_acumulado']);
        $this->assertSame([2, 2], [$reingreso['equivalencia']['episodios'], $reingreso['equivalencia']['paquetes_netos']]);
    }

    public function test_two_possible_continuations_are_left_pending_without_additional_charges(): void
    {
        $ambigua = $this->escenario([[
            $this->segmento('FJ', '2026-06-10 08:00', '2026-06-30 23:59', '08'),
            $this->segmento('FL1', '2026-07-01 00:30', '2026-07-25 10:00', '01'),
            $this->segmento('FL2', '2026-07-01 06:00', '2026-07-02 12:00', '01', '7'),
        ]]);

        $this->assertSame(['2026-06' => round(self::PAQUETE, 2)], $ambigua['equivalencia']['costo_por_mes']);
        $this->assertCount(2, $ambigua['resumenes'][0]['retenidos']);
        $this->assertSame('CONTINUIDAD_NO_CONFIRMADA', $ambigua['estado_episodio']);
    }

    public function test_continuity_is_only_linked_with_full_evidence_and_never_by_proximity_alone(): void
    {
        $paciente = fn (string $documento, array $segmentos): array => array_map(static fn (array $segmento): array => [...$segmento, 'documento' => $documento], $segmentos);
        $libro = $this->libro();
        $resumen = $libro->registrarCarga([
            // H: misma autorización en una estancia no contigua → no se une (revisión).
            ...$paciente('201', [$this->segmento('H1', '2026-06-10 08:00', '2026-06-30 23:59', '08'), $this->segmento('H2', '2026-08-20 08:00', '2026-08-22 08:00', '01')]),
            // I: continuación contigua con otra autorización → revisión, sin unir.
            ...$paciente('202', [$this->segmento('I1', '2026-06-10 08:00', '2026-06-30 23:59', '08'), [...$this->segmento('I2', '2026-07-01 00:30', '2026-07-25 10:00', '01'), 'autorizacion' => 'AUT-9']]),
            // J: corte 08 sin segmento siguiente → continuidad pendiente.
            ...$paciente('203', [$this->segmento('J1', '2026-06-10 08:00', '2026-06-30 23:59', '08')]),
            // K: dos candidatos que se superponen → no se enlaza ninguno.
            ...$paciente('204', [$this->segmento('K1', '2026-06-10 08:00', '2026-06-30 23:59', '08'), $this->segmento('K2', '2026-07-01 00:30', '2026-07-25 10:00', '01'), $this->segmento('K3', '2026-07-01 06:00', '2026-07-02 12:00', '01', '7')]),
            // Evidencia completa → se une: 45 días, 2 paquetes en un solo episodio.
            ...$paciente('205', [$this->segmento('C1', '2026-06-10 08:00', '2026-06-30 23:59', '08'), $this->segmento('C2', '2026-07-01 00:30', '2026-07-25 10:00', '01')]),
        ], 'evidencia');

        $porPaciente = DB::table('hospital_paquete_movimientos')->selectRaw('num_documento, COUNT(*) AS paquetes, COUNT(DISTINCT episodio_clave) AS episodios, MAX(estado_episodio) AS estado')
            ->groupBy('num_documento')->get()->keyBy('num_documento');

        $this->assertSame(4, count($resumen['retenidos']));
        $this->assertSame(['1', 'CONTINUIDAD_NO_CONFIRMADA'], [(string) $porPaciente['201']->paquetes, $porPaciente['201']->estado]);
        $this->assertSame(['1', 'CONTINUIDAD_NO_CONFIRMADA'], [(string) $porPaciente['202']->paquetes, $porPaciente['202']->estado]);
        $this->assertSame(['1', 'CONTINUIDAD_HOSPITALARIA_PENDIENTE'], [(string) $porPaciente['203']->paquetes, $porPaciente['203']->estado]);
        $this->assertSame(['1', 'CONTINUIDAD_NO_CONFIRMADA'], [(string) $porPaciente['204']->paquetes, $porPaciente['204']->estado]);
        $this->assertSame(['2', '1', 'CERRADO'], [(string) $porPaciente['205']->paquetes, (string) $porPaciente['205']->episodios, $porPaciente['205']->estado]);
    }

    public function test_an_error_in_the_middle_of_the_transaction_leaves_nothing_and_the_load_can_be_repeated(): void
    {
        $carga = [$this->segmento('F1', '2026-06-01 08:00', '2026-06-15 08:00', '08'), $this->segmento('F2', '2026-06-16 00:00', '2026-07-01 08:00', '01')];
        $falla = new class extends AlmacenContinuidadHospitalariaBaseDatos
        {
            protected function insertarMovimiento(string $ruta, string $tipo, string $documento, array $movimiento, array $ids, mixed $ahora): void
            {
                parent::insertarMovimiento($ruta, $tipo, $documento, $movimiento, $ids, $ahora);

                throw new RuntimeException('Fallo simulado después del primer movimiento.');
            }
        };

        try {
            (new LibroPaquetesHospitalarios($falla))->registrarCarga($carga, 'con-error');
            $this->fail('La carga debía fallar.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Fallo simulado después del primer movimiento.', $exception->getMessage());
        }

        $this->assertSame(0, DB::table('hospital_segmentos')->count());
        $this->assertSame(0, DB::table('hospital_paquete_movimientos')->count());

        $this->libro()->registrarCarga($carga, 'repeticion');
        $this->assertSame(2, (int) DB::table('hospital_paquete_movimientos')->sum('signo'));
    }

    public function test_reversal_and_later_reprocessing_keep_full_history_with_distinct_idempotency_keys(): void
    {
        $original = [$this->segmento('F1', '2026-06-01 08:00', '2026-06-15 08:00', '08'), $this->segmento('F2', '2026-06-16 00:00', '2026-07-01 08:00', '01')];
        $acortada = [$this->segmento('F2', '2026-06-16 00:00', '2026-06-20 08:00', '01')];
        $libro = $this->libro();

        $libro->registrarCarga($original, 'original');
        $libro->registrarCarga($acortada, 'correccion-1', esCorreccion: true);
        $this->assertSame(1, (int) DB::table('hospital_paquete_movimientos')->sum('signo'));

        $libro->registrarCarga([$original[1]], 'correccion-2', esCorreccion: true);

        $movimientos = DB::table('hospital_paquete_movimientos')->orderBy('id')->get();
        $this->assertSame([1, 1, -1, 1], $movimientos->pluck('signo')->map(static fn ($signo): int => (int) $signo)->all());
        $this->assertSame(4, $movimientos->pluck('clave_idempotencia')->unique()->count());
        $this->assertSame((int) $movimientos[1]->id, (int) $movimientos[2]->movimiento_revertido_id);
        $this->assertSame(2, $movimientos->whereNotNull('bloque_vigente')->count());
        $this->assertSame(['VIGENTE', 'REEMPLAZADO', 'REEMPLAZADO', 'VIGENTE'], DB::table('hospital_segmentos')->orderBy('id')->pluck('estado')->all());
    }

    public function test_the_unique_keys_reject_a_second_recognition_of_the_same_block(): void
    {
        $libro = $this->libro();
        $libro->registrarCarga([$this->segmento('F1', '2026-06-01 08:00', '2026-06-10 08:00', '01')], 'primera');
        $movimiento = ['bloque_inicio' => '2026-06-01 08:00', 'numero_bloque' => 1, 'mes_imputado' => '2026-06', 'signo' => 1, 'valor' => self::PAQUETE,
            'motivo' => 'RECONOCIMIENTO', 'estado_episodio' => 'CERRADO', 'ejecucion' => 'forzada', 'segmentos' => []];
        $almacen = new AlmacenContinuidadHospitalariaBaseDatos;

        $this->expectException(ConcurrenciaContinuidadException::class);
        $almacen->confirmar('PROSTATA', 'CC|100', $almacen->version('PROSTATA', 'CC|100'), ['segmentos' => [], 'reemplazados' => [], 'movimientos' => [$movimiento]]);
    }

    public function test_a_patient_without_document_gets_no_invented_identity(): void
    {
        $resumen = $this->libro()->registrarCarga([[...$this->segmento('F1', '2026-06-01 08:00', '2026-06-10 08:00', '01'), 'documento' => '']], 'sin-documento');

        $this->assertSame(1, $resumen['sin_identidad']);
        $this->assertSame(0, DB::table('hospital_segmentos')->count());
    }

    /**
     * Ejecuta las cargas dentro de una transacción que se revierte al final, para
     * comparar varios órdenes en la misma prueba.
     *
     * @param  list<list<array<string, string>>>  $lotes
     * @param  array<int, bool>  $correcciones
     * @return array<string, mixed>
     */
    private function escenario(array $lotes, array $correcciones = []): array
    {
        DB::beginTransaction();

        try {
            $libro = $this->libro();
            $resumenes = [];

            foreach ($lotes as $indice => $lote) {
                $resumenes[] = $libro->registrarCarga($lote, 'ejecucion-'.$indice, $correcciones[$indice] ?? false);
            }

            $movimientos = DB::table('hospital_paquete_movimientos')->orderBy('id')->get();
            $porMes = [];

            foreach ($movimientos as $movimiento) {
                $porMes[$movimiento->mes_contable] = round(($porMes[$movimiento->mes_contable] ?? 0.0) + (float) $movimiento->valor, 2);
            }

            ksort($porMes);
            $porMes = array_filter($porMes, static fn (float $valor): bool => $valor !== 0.0);
            $vigentes = $movimientos->whereNotNull('bloque_vigente');

            return [
                'equivalencia' => [
                    'episodios' => $vigentes->pluck('episodio_clave')->unique()->count(),
                    'paquetes_netos' => (int) $movimientos->sum('signo'),
                    'costo_por_mes' => $porMes,
                    'costo_acumulado' => round(array_sum($porMes), 2),
                ],
                'ajustes' => $movimientos->where('signo', '<', 0)->count(),
                'residuo' => round((float) $movimientos->sum('valor') - (float) $vigentes->sum('valor'), 2),
                'motivos' => $movimientos->pluck('motivo')->all(),
                'estados_segmentos' => DB::table('hospital_segmentos')->orderBy('id')->pluck('estado')->all(),
                'estado_episodio' => $movimientos->first()?->estado_episodio,
                'resumenes' => $resumenes,
            ];
        } finally {
            DB::rollBack();
        }
    }

    private function libro(): LibroPaquetesHospitalarios
    {
        return new LibroPaquetesHospitalarios(new AlmacenContinuidadHospitalariaBaseDatos);
    }

    /**
     * @return array<string, string>
     */
    private function segmento(string $factura, string $inicio, string $fin, string $destino, string $consecutivo = '1'): array
    {
        return [
            'ruta' => 'PROSTATA', 'prestador' => '900123456', 'factura' => $factura, 'tipoDocumento' => 'CC', 'documento' => '100',
            'consecutivo' => $consecutivo, 'inicio' => $inicio, 'fin' => $fin, 'destino' => $destino, 'archivo' => $factura.'.json',
            'autorizacion' => 'AUT-1', 'prestadorServicio' => '050010608601',
        ];
    }
}
