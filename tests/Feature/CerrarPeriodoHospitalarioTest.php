<?php

namespace Tests\Feature;

use App\Programas\LaMaria\AntecedenteHospitalario;
use App\Programas\LaMaria\CierreHospitalarioPeriodo;
use App\Programas\LaMaria\EstadoAntecedenteHospitalario;
use App\Programas\LaMaria\HistorialHospitalario;
use App\Programas\LaMaria\PoliticaPaqueteHospitalario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

/**
 * la-maria:cerrar-periodo: un cierre solo se guarda con la lista completa de facturas del período, archivos del
 * propio período, confirmación, antecedente comprobado y el mismo secreto; el arranque solo en el período autorizado.
 */
class CerrarPeriodoHospitalarioTest extends TestCase
{
    use RefreshDatabase;

    private const SECRETO = 'secreto-de-prueba-del-historial-hospitalario';

    private string $directorio;

    protected function setUp(): void
    {
        parent::setUp();

        config(['programas.la-maria.historial_hospitalario' => ['modo' => 'DESACTIVADO', 'secreto' => self::SECRETO, 'arranque' => '2026-07']]);
        File::ensureDirectoryExists($this->directorio = storage_path('framework/testing/cierre-'.Str::uuid()));
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($this->directorio));
    }

    public function test_a_period_is_saved_only_with_its_complete_invoice_list_and_a_reason(): void
    {
        $julio = [$this->julio(), $this->archivo('julio-2.json', 'FAC-JUL-2', [$this->usuario('105', [$this->ah('2026-07-03 08:00', '2026-07-09 08:00', '01', 'AUT-E')])])];

        $this->cerrar('2026-07', [$julio[0]], ['FAC-JUL', 'FAC-JUL-2'], arranque: true)->expectsOutputToContain('FAC-JUL-2')->assertFailed();
        $this->cerrar('2026-07', $julio, ['FAC-JUL'], arranque: true)->assertFailed();
        $this->cerrar('2026-07', $julio, [], arranque: true)->assertFailed();
        $this->cerrar('2026-07', $julio, ['FAC-JUL', 'FAC-JUL-2'], arranque: true, motivo: '')->assertFailed();
        $this->assertNull($this->historial()->vigente('PROSTATA', '2026-07'));

        $this->cerrar('2026-07', $julio, ['FAC-JUL-2', 'FAC-JUL'], arranque: true)->expectsOutputToContain('CONFIRMADA POR EL OPERADOR')->assertSuccessful();
        $this->assertEqualsCanonicalizing(['FAC-JUL', 'FAC-JUL-2'], array_column($this->historial()->vigente('PROSTATA', '2026-07')->origen, 'factura'));
        $this->assertSame(AntecedenteHospitalario::COMPLETITUD_OPERADOR, $this->historial()->vigente('PROSTATA', '2026-07')->completitud, 'Sin fuente independiente, la completitud es la que declara el operador.');
    }

    public function test_a_period_is_never_closed_with_files_of_another_period(): void
    {
        $this->cerrar('2026-07', [$this->julio(), $this->agosto()], ['FAC-JUL', 'FAC-AGO'], arranque: true)->assertFailed();
        $this->cerrar('2026-08', [$this->agosto(), $this->julio()], ['FAC-AGO', 'FAC-JUL'])->assertFailed();
        $this->assertNull($this->historial()->vigente('PROSTATA', '2026-07'));
        $this->assertNull($this->historial()->vigente('PROSTATA', '2026-08'));
    }

    public function test_the_start_without_antecedent_is_only_allowed_for_the_authorized_period(): void
    {
        config(['programas.la-maria.historial_hospitalario.arranque' => null]);
        $this->cerrar('2026-07', [$this->julio()], ['FAC-JUL'], arranque: true)->assertFailed();

        config(['programas.la-maria.historial_hospitalario.arranque' => '2026-07']);
        $this->cerrar('2026-08', [$this->agosto()], ['FAC-AGO'], arranque: true)->assertFailed();
        $this->cerrar('2026-07', [$this->julio()], ['FAC-JUL'], arranque: true)->assertSuccessful();

        config(['programas.la-maria.historial_hospitalario.arranque' => '2026-08']);
        $this->cerrar('2026-08', [$this->agosto()], ['FAC-AGO'], arranque: true)->assertFailed();
        $this->assertNull($this->historial()->vigente('PROSTATA', '2026-08'), 'El arranque no puede saltar el cierre de julio.');
    }

    /**
     * Cerrar con los mismos archivos en otro orden no crea otra versión; una corrección no puede quitar una
     * factura del cierre vigente.
     */
    public function test_the_closure_identity_depends_only_on_the_evidence_of_its_period(): void
    {
        $julio = [$this->julio(), $this->archivo('julio-2.json', 'FAC-JUL-2', [$this->usuario('105', [$this->ah('2026-07-03 08:00', '2026-07-09 08:00', '01', 'AUT-E')])])];
        $this->cerrar('2026-07', $julio, ['FAC-JUL', 'FAC-JUL-2'], arranque: true)->assertSuccessful();

        $this->cerrar('2026-07', array_reverse($julio), ['FAC-JUL-2', 'FAC-JUL'], arranque: true)->expectsOutputToContain('SIN CAMBIOS')->assertSuccessful();
        $this->cerrar('2026-07', [$julio[0]], ['FAC-JUL'], arranque: true)->assertFailed();
        $this->assertCount(1, $this->historial()->versiones('PROSTATA', '2026-07'));

        // La simulación compara con el cierre vigente sin escribir: así se comprueba la cadena sin --aplicar.
        $this->cerrar('2026-07', $julio, ['FAC-JUL', 'FAC-JUL-2'], arranque: true, aplicar: false)->expectsOutputToContain('Coincide con el cierre vigente PROSTATA 2026-07 v1')->assertSuccessful();
        $corregido = $this->archivo('julio-2.json', 'FAC-JUL-2', [$this->usuario('105', [$this->ah('2026-07-03 08:00', '2026-07-31 23:59', '08', 'AUT-E')])]);
        $this->cerrar('2026-07', [$julio[0], $corregido], ['FAC-JUL', 'FAC-JUL-2'], arranque: true, aplicar: false)->expectsOutputToContain('DISTINTO del cierre vigente')->assertSuccessful();
        $this->assertCount(1, $this->historial()->versiones('PROSTATA', '2026-07'));
    }

    public function test_a_correction_flags_every_later_closure_that_depends_on_it(): void
    {
        $this->cerrar('2026-07', [$this->julio()], ['FAC-JUL'], arranque: true)->assertSuccessful();
        $this->cerrar('2026-08', [$this->agosto()], ['FAC-AGO'])->assertSuccessful();
        $this->cerrar('2026-09', [$this->septiembre()], ['FAC-SEP'])->assertSuccessful();

        $corregido = $this->archivo('julio-corregido.json', 'FAC-JUL', [$this->usuario('100', [$this->ah('2026-07-21 08:00', '2026-07-31 23:59', '08', 'AUT-A')])]);
        $this->cerrar('2026-07', [$corregido], ['FAC-JUL'], arranque: true)
            ->expectsOutputToContain('PROSTATA 2026-08 v1')->expectsOutputToContain('PROSTATA 2026-09 v1')->assertSuccessful();

        $cierre = new CierreHospitalarioPeriodo(new PoliticaPaqueteHospitalario, self::SECRETO);
        $this->assertSame(EstadoAntecedenteHospitalario::Incompleto, $cierre->estadoAntecedente($this->historial()->vigente('PROSTATA', '2026-09'), $this->historial(), []));
        $this->cerrar('2026-09', [$this->septiembre()], ['FAC-SEP'])->assertFailed();
    }

    public function test_a_changed_secret_never_closes_or_replaces_a_period(): void
    {
        $this->cerrar('2026-07', [$this->julio()], ['FAC-JUL'], arranque: true)->assertSuccessful();
        config(['programas.la-maria.historial_hospitalario.secreto' => 'otro-secreto-del-historial-hospitalario-de-prueba']);

        $this->cerrar('2026-08', [$this->agosto()], ['FAC-AGO'])->expectsOutputToContain('otro secreto')->assertFailed();
        $this->cerrar('2026-07', [$this->julio()], ['FAC-JUL'], arranque: true)->expectsOutputToContain('otro secreto')->assertFailed();
        $this->assertCount(1, $this->historial()->versiones('PROSTATA', '2026-07'));
        $this->assertNull($this->historial()->vigente('PROSTATA', '2026-08'));
    }

    private function historial(): HistorialHospitalario
    {
        return app(HistorialHospitalario::class);
    }

    /**
     * @param  list<string>  $archivos
     * @param  list<string>  $facturas
     */
    private function cerrar(string $periodo, array $archivos, array $facturas, bool $arranque = false, string $motivo = 'Facturas completas del período', bool $aplicar = true): PendingCommand
    {
        return $this->artisan('la-maria:cerrar-periodo', array_filter([
            'ruta' => 'PROSTATA', 'periodo' => $periodo, '--archivo' => $archivos, '--factura' => $facturas, '--arranque' => $arranque,
            '--motivo' => $motivo, '--aplicar' => $aplicar,
        ], static fn (mixed $valor): bool => $valor !== false && $valor !== [] && $valor !== ''));
    }

    private function julio(): string
    {
        return $this->archivo('julio.json', 'FAC-JUL', [$this->usuario('100', [$this->ah('2026-07-20 08:00', '2026-07-31 23:59', '08', 'AUT-A')])]);
    }

    private function agosto(): string
    {
        return $this->archivo('agosto.json', 'FAC-AGO', [$this->usuario('101', [$this->ah('2026-08-25 10:00', '2026-08-31 23:59', '08', 'AUT-B')])]);
    }

    private function septiembre(): string
    {
        return $this->archivo('septiembre.json', 'FAC-SEP', [$this->usuario('101', [$this->ah('2026-09-01 00:00', '2026-09-10 08:00', '01', 'AUT-B')])]);
    }

    /**
     * @param  list<array<string, mixed>>  $usuarios
     */
    private function archivo(string $nombre, string $factura, array $usuarios): string
    {
        File::put($ruta = $this->directorio.DIRECTORY_SEPARATOR.$nombre, json_encode([
            'numDocumentoIdObligado' => '9000000101', 'numFactura' => $factura, 'tipoNota' => null, 'numNota' => null, 'usuarios' => $usuarios,
        ], JSON_THROW_ON_ERROR));

        return $ruta;
    }

    /**
     * @param  list<array<string, mixed>>  $hospitalizaciones
     * @return array<string, mixed>
     */
    private function usuario(string $documento, array $hospitalizaciones): array
    {
        return [
            'tipoDocumentoIdentificacion' => 'CC', 'numDocumentoIdentificacion' => $documento, 'codSexo' => 'M', 'fechaNacimiento' => '1960-01-01',
            'codMunicipioResidencia' => '05001', 'servicios' => ['hospitalizacion' => $hospitalizaciones],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ah(string $ingreso, string $egreso, string $destino, string $autorizacion): array
    {
        return [
            'codPrestador' => '9000000102', 'viaIngresoServicioSalud' => '01', 'fechaInicioAtencion' => $ingreso, 'fechaEgreso' => $egreso,
            'codDiagnosticoPrincipal' => 'C61X', 'condicionDestinoUsuarioEgreso' => $destino, 'numAutorizacion' => $autorizacion, 'consecutivo' => 1,
        ];
    }
}
