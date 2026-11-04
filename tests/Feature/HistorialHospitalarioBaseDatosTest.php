<?php

namespace Tests\Feature;

use App\Models\AhAntecedente;
use App\Models\AhEstanciaAbierta;
use App\Programas\LaMaria\AntecedenteHospitalario;
use App\Programas\LaMaria\CierreHospitalarioPeriodo;
use App\Programas\LaMaria\EstadoEstanciaHospitalaria;
use App\Programas\LaMaria\HistorialHospitalarioBaseDatos;
use App\Programas\LaMaria\HistorialHospitalarioEnMemoria;
use App\Programas\LaMaria\PoliticaPaqueteHospitalario;
use App\Programas\LaMaria\ReconstructorEpisodiosHospitalarios as Reconstructor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

class HistorialHospitalarioBaseDatosTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_the_same_closure_twice_keeps_a_single_version(): void
    {
        $historial = new HistorialHospitalarioBaseDatos;

        $primero = $historial->guardar($this->cierre([$this->estancia('A')]));
        $segundo = $historial->guardar($this->cierre([$this->estancia('A')]));

        $this->assertSame($primero->id, $segundo->id);
        $this->assertSame(1, AhAntecedente::query()->count());
        $this->assertSame(1, $historial->vigente('PROSTATA', '2026-08')->version);
    }

    public function test_a_correction_creates_a_new_version_and_never_changes_the_previous_one(): void
    {
        $historial = new HistorialHospitalarioBaseDatos;
        $v1 = $historial->guardar($this->cierre([$this->estancia('A'), $this->estancia('B')]));
        $filasV1 = AhEstanciaAbierta::query()->where('antecedente_id', $v1->id)->get()->toArray();

        $v2 = $historial->guardar($this->cierre([$this->estancia('A'), $this->estancia('B', bloques: 2)], 'FAC-AGO-CORREGIDA'));

        $this->assertSame(2, $v2->version);
        $this->assertSame($v2->id, $historial->vigente('PROSTATA', '2026-08')->id);
        $this->assertFalse($historial->version('PROSTATA', '2026-08', 1)->vigente);
        $this->assertSame('REEMPLAZADO', AhAntecedente::query()->find($v1->id)->estado);
        $this->assertSame($v1->hashContenido(), $historial->version('PROSTATA', '2026-08', 1)->hashContenido(), 'La versión anterior se puede reconstruir igual.');
        $this->assertSame($filasV1, AhEstanciaAbierta::query()->where('antecedente_id', $v1->id)->get()->toArray());
        $rectificadas = array_values(array_filter($v2->estancias, static fn (array $e): bool => $e['estado'] === EstadoEstanciaHospitalaria::Rectificada->value));
        $this->assertCount(1, $rectificadas);
        $this->assertSame(1, $rectificadas[0]['bloquesReconocidos']);
        $this->assertSame(1, $rectificadas[0]['rectificaVersion']);
        $this->assertCount(2, $v2->abiertas(), 'La evidencia rectificada no se puede unir a una continuación.');
    }

    public function test_only_one_version_of_a_period_can_be_current(): void
    {
        (new HistorialHospitalarioBaseDatos)->guardar($this->cierre([$this->estancia('A')]));

        $this->expectException(UniqueConstraintViolationException::class);
        AhAntecedente::query()->create([
            'programa_slug' => 'la-maria', 'ruta' => 'PROSTATA', 'periodo' => '2026-08', 'version' => 9, 'tipo' => 'DEFINITIVO',
            'estado' => 'VIGENTE', 'clave_vigente' => 'la-maria|PROSTATA|2026-08', 'hash_contenido' => str_repeat('a', 64),
            'hash_origen' => str_repeat('b', 64), 'origen' => [], 'version_reglas' => 'x', 'huella_secreto' => str_repeat('0', 16), 'completitud' => 'CONFIRMADA_POR_OPERADOR',
        ]);
    }

    /**
     * Un choque con otro cierre del mismo período deshace el intento completo y se reintenta: queda una sola
     * versión vigente y ninguna estancia huérfana.
     */
    public function test_a_competing_closure_of_the_same_period_is_retried_without_partial_writes(): void
    {
        $historial = new HistorialHospitalarioBaseDatos;
        $v1 = $historial->guardar($this->cierre([$this->estancia('A')]));
        $this->competidor(veces: 1);

        $v2 = $historial->guardar($this->cierre([$this->estancia('A'), $this->estancia('B')], 'FAC-AGO-CORREGIDA'));

        $this->assertSame([1, 2], AhAntecedente::query()->orderBy('version')->pluck('version')->all());
        $this->assertSame(['REEMPLAZADO', 'VIGENTE'], AhAntecedente::query()->orderBy('version')->pluck('estado')->all());
        $this->assertSame($v2->id, $historial->vigente('PROSTATA', '2026-08')->id);
        $this->assertSame(0, AhEstanciaAbierta::query()->whereNotIn('antecedente_id', [$v1->id, $v2->id])->count());
        $this->assertSame(3, AhEstanciaAbierta::query()->count());
    }

    /**
     * Si el choque persiste, el cierre se rechaza completo y pide volver a ejecutarlo; la versión vigente no cambia.
     */
    public function test_a_persistent_competing_closure_is_rejected_without_partial_writes(): void
    {
        $historial = new HistorialHospitalarioBaseDatos;
        $v1 = $historial->guardar($this->cierre([$this->estancia('A')]));
        $this->competidor(veces: 3);

        try {
            $historial->guardar($this->cierre([$this->estancia('A'), $this->estancia('B')], 'FAC-AGO-CORREGIDA'));
            $this->fail('El cierre concurrente debió rechazarse.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('vuelva a ejecutar', $exception->getMessage());
        }

        $this->assertSame([$v1->id], AhAntecedente::query()->pluck('id')->all());
        $this->assertSame('VIGENTE', AhAntecedente::query()->find($v1->id)->estado);
        $this->assertSame(1, AhEstanciaAbierta::query()->count());
        $this->assertSame($v1->hashContenido(), $historial->vigente('PROSTATA', '2026-08')->hashContenido());
    }

    /**
     * Simula otro proceso que registra una versión vigente del período justo antes de cada uno de los primeros intentos.
     */
    private function competidor(int $veces): void
    {
        AhAntecedente::creating(static function () use (&$veces): void {
            if ($veces-- > 0) {
                DB::table('ah_antecedentes')->insert([
                    'programa_slug' => 'la-maria', 'ruta' => 'PROSTATA', 'periodo' => '2026-08', 'version' => 7, 'tipo' => 'DEFINITIVO',
                    'estado' => 'VIGENTE', 'clave_vigente' => 'la-maria|PROSTATA|2026-08', 'hash_contenido' => str_repeat('c', 64),
                    'hash_origen' => str_repeat('d', 64), 'origen' => '[]', 'version_reglas' => 'x', 'huella_secreto' => str_repeat('0', 16), 'completitud' => 'CONFIRMADA_POR_OPERADOR',
                ]);
            }
        });
    }

    /**
     * MariaDB y MySQL rechazan un texto más largo que su columna (SQLite no lo valida): los valores que escribe
     * el historial caben en las longitudes declaradas en sus migraciones.
     */
    public function test_stored_values_fit_the_column_lengths_declared_in_the_migrations(): void
    {
        $longitudes = [];

        foreach (['ah_antecedentes', 'ah_estancias_abiertas'] as $tabla) {
            $migracion = (string) file_get_contents((string) current(glob(database_path("migrations/*_create_{$tabla}_table.php"))));
            preg_match_all("/->(?:string|char)\\('(\\w+)', (\\d+)\\)/", $migracion, $columnas, PREG_SET_ORDER);
            $longitudes[$tabla] = array_map('intval', array_column($columnas, 2, 1));
        }

        $continuidades = [...array_values(array_filter((new ReflectionClass(Reconstructor::class))->getConstants(),
            static fn (mixed $valor, string $nombre): bool => is_string($valor) && preg_match('/^(CONTINUACION|INICIO)_/', $nombre) === 1, ARRAY_FILTER_USE_BOTH)),
            'CONTINUACION AMBIGUA EN LOS RIPS DE 2026-10', 'INICIO EN EL PERIODO'];
        $maximo = static fn (array $valores): int => max(array_map('strlen', $valores));

        $this->assertGreaterThanOrEqual(strlen(CierreHospitalarioPeriodo::VERSION_REGLAS), $longitudes['ah_antecedentes']['version_reglas']);
        $this->assertGreaterThanOrEqual($maximo([AntecedenteHospitalario::TIPO_ARRANQUE, AntecedenteHospitalario::TIPO_DEFINITIVO, AntecedenteHospitalario::TIPO_PROVISIONAL]),
            $longitudes['ah_antecedentes']['tipo']);
        $this->assertGreaterThanOrEqual(strlen('REEMPLAZADO'), $longitudes['ah_antecedentes']['estado']);
        $this->assertGreaterThanOrEqual(strlen(AntecedenteHospitalario::COMPLETITUD_OPERADOR), $longitudes['ah_antecedentes']['completitud']);
        $this->assertGreaterThanOrEqual($maximo(array_column(EstadoEstanciaHospitalaria::cases(), 'value')), $longitudes['ah_estancias_abiertas']['estado']);
        $this->assertGreaterThanOrEqual($maximo($continuidades), $longitudes['ah_estancias_abiertas']['continuidad']);
        $this->assertGreaterThanOrEqual(strlen('2026-10-01 00:00:00'), $longitudes['ah_estancias_abiertas']['ingreso_original']);
        $this->assertSame(16, strlen((new CierreHospitalarioPeriodo(new PoliticaPaqueteHospitalario, str_repeat('s', 40)))->huellaSecreto()));
        $this->assertSame(16, $longitudes['ah_antecedentes']['huella_secreto']);
    }

    public function test_database_and_memory_histories_store_the_same_content(): void
    {
        $cierre = $this->cierre([$this->estancia('A'), $this->estancia('B')]);

        $baseDatos = (new HistorialHospitalarioBaseDatos)->guardar($cierre);
        $memoria = (new HistorialHospitalarioEnMemoria)->guardar($cierre);

        $this->assertSame($memoria->hashContenido(), $baseDatos->hashContenido());
        $this->assertSame($memoria->estancias, $baseDatos->estancias);
        $this->assertSame($memoria->origen, $baseDatos->origen);
        $this->assertSame($memoria->completitud, $baseDatos->completitud);
    }

    /**
     * @param  list<array<string, mixed>>  $estancias
     */
    private function cierre(array $estancias, string $factura = 'FAC-AGO'): AntecedenteHospitalario
    {
        return new AntecedenteHospitalario('PROSTATA', '2026-08', 0, AntecedenteHospitalario::TIPO_ARRANQUE, $estancias,
            [['factura' => $factura, 'archivo' => 'agosto.json', 'sha256' => hash('sha256', $factura)]], 'reglas-prueba');
    }

    /**
     * @return array<string, mixed>
     */
    private function estancia(string $paciente, int $bloques = 1): array
    {
        return [
            'clavePaciente' => hash('sha256', $paciente), 'autorizacion' => 'AUT-'.$paciente, 'codPrestador' => '9000000102',
            'ingresoOriginal' => '2026-08-20 08:00', 'inicioTramo' => '2026-08-20 08:00', 'finCorte' => '2026-08-31 23:59', 'destinoFinal' => '08',
            'cortes08' => 1, 'bloquesReconocidos' => $bloques, 'bloquesPeriodo' => [['numero' => 1, 'inicio' => '2026-08-20 08:00', 'periodo' => '2026-08']],
            'tramosOrigen' => ['FAC-AGO#1'], 'estado' => EstadoEstanciaHospitalaria::AbiertaVerificada->value, 'continuidad' => 'INICIO EN EL PERIODO',
        ];
    }
}
