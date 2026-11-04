<?php

namespace Tests\Unit;

use App\Programas\LaMaria\AntecedenteHospitalario;
use App\Programas\LaMaria\CierreHospitalarioPeriodo;
use App\Programas\LaMaria\EstadoAntecedenteHospitalario;
use App\Programas\LaMaria\EstadoEstanciaHospitalaria;
use App\Programas\LaMaria\HistorialHospitalarioEnMemoria;
use App\Programas\LaMaria\PoliticaPaqueteHospitalario;
use App\Programas\LaMaria\ReconstructorEpisodiosHospitalarios as Reconstructor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CierreHospitalarioPeriodoTest extends TestCase
{
    private const SECRETO = 'secreto-de-prueba-del-historial-hospitalario';

    /**
     * El tramo del día 1 se une solo con el corte 08 del mismo paciente (tipo y número de documento), la misma
     * autorización y el mismo prestador, como el reconstructor del lote. Con otra evidencia es una estancia nueva.
     *
     * @param  array<string, string>  $agosto
     * @param  array<string, string>  $septiembre
     */
    #[DataProvider('continuacionesSinEvidencia')]
    public function test_a_day_one_stay_joins_only_the_same_patient_authorization_and_provider(array $agosto, array $septiembre): void
    {
        $cierre = $this->servicio();
        $corte = fn (array $cambios = []): AntecedenteHospitalario => $this->cerrar($cierre, '2026-08', [$this->tramo('ago', '2026-08-20 08:00', '2026-08-31 23:59', '08', $cambios)]);

        $unida = $this->procesar($cierre, '2026-09', [$this->tramo('sep', '2026-09-01 00:00', '2026-09-10 08:00', '01')], $corte());
        $otra = $this->procesar($cierre, '2026-09', [$this->tramo('sep', '2026-09-01 00:00', '2026-09-10 08:00', '01', $septiembre)], $corte($agosto));

        $this->assertSame(Reconstructor::CONTINUACION_UNIDA, $unida['continuidades'][0]['motivo']);
        $this->assertSame(Reconstructor::INICIO_SIN_CORTE_PREVIO, $otra['continuidades'][0]['motivo']);
        $this->assertSame([EstadoEstanciaHospitalaria::SinContinuacionObservada->value], array_column($otra['cierre']->estancias, 'estado'));
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: array<string, string>}>
     */
    public static function continuacionesSinEvidencia(): array
    {
        $sinDocumento = static fn (string $archivo): array => ['paciente' => 'SIN_DOCUMENTO|'.$archivo.'|0', 'tipoDocumento' => '', 'documento' => ''];

        return [
            'otro tipo de documento' => [[], ['paciente' => 'TI|100', 'tipoDocumento' => 'TI']],
            'dos pacientes sin documento' => [$sinDocumento('agosto.json'), $sinDocumento('septiembre.json')],
            'otro prestador' => [[], ['prestadorServicio' => '050010000000']],
            'otra autorizacion' => [[], ['autorizacion' => 'AUT-B']],
        ];
    }

    /**
     * Dos tramos del día 1 que reclaman la misma estancia abierta no se pueden asignar: ninguno se une ni se
     * cuenta como estancia nueva; sus bloques quedan condicionados.
     */
    public function test_two_day_one_stays_claiming_one_open_stay_are_not_joined(): void
    {
        $cierre = $this->servicio();
        $antecedente = $this->cerrar($cierre, '2026-08', [$this->tramo('ago', '2026-08-20 08:00', '2026-08-31 23:59', '08')]);

        $resultado = $this->procesar($cierre, '2026-09', [
            $this->tramo('sep-1', '2026-09-01 00:00', '2026-09-10 08:00', '01'),
            $this->tramo('sep-2', '2026-09-01 00:00', '2026-09-12 08:00', '01'),
        ], $antecedente);

        $this->assertSame([Reconstructor::CONTINUACION_NO_COMPROBADA, Reconstructor::CONTINUACION_NO_COMPROBADA], array_column($resultado['continuidades'], 'motivo'));
        $this->assertSame([], $resultado['cierre']->abiertas());
    }

    /**
     * Corregir un cierre deja incompletos todos los cierres posteriores de la cadena, no solo el siguiente.
     */
    public function test_correcting_an_earlier_closure_leaves_every_later_closure_incomplete(): void
    {
        $cierre = $this->servicio();
        $historial = new HistorialHospitalarioEnMemoria;
        $junio = $historial->guardar($this->definitivo($this->cerrar($cierre, '2026-06', [$this->tramo('jun', '2026-06-20 08:00', '2026-06-30 23:59', '08')]), AntecedenteHospitalario::TIPO_ARRANQUE));
        $julio = $historial->guardar($this->definitivo($this->cerrar($cierre, '2026-07', [$this->tramo('jul', '2026-07-10 08:00', '2026-07-31 23:59', '08')], $junio)));
        $agosto = $historial->guardar($this->definitivo($this->cerrar($cierre, '2026-08', [$this->tramo('ago', '2026-08-20 08:00', '2026-08-31 23:59', '08')], $julio)));

        $this->assertSame(EstadoAntecedenteHospitalario::Comprobado, $cierre->estadoAntecedente($agosto, $historial, []));

        $historial->guardar($this->definitivo($this->cerrar($cierre, '2026-06', [$this->tramo('jun', '2026-06-21 08:00', '2026-06-30 23:59', '08')]), AntecedenteHospitalario::TIPO_ARRANQUE));

        $this->assertSame(EstadoAntecedenteHospitalario::Incompleto, $cierre->estadoAntecedente($historial->vigente('PROSTATA', '2026-07'), $historial, []));
        $this->assertSame(EstadoAntecedenteHospitalario::Incompleto, $cierre->estadoAntecedente($historial->vigente('PROSTATA', '2026-08'), $historial, []));
    }

    /**
     * Una corrección que solo cambia el origen (el mismo contenido) no invalida los cierres posteriores; una que
     * cambia el contenido sí, y volver a cerrar el posterior deja la cadena comprobada.
     */
    public function test_only_a_content_change_invalidates_later_closures_and_closing_them_again_heals_the_chain(): void
    {
        $cierre = $this->servicio();
        $historial = new HistorialHospitalarioEnMemoria;
        $julio = fn (string $ingreso, string $sha = '2026-07'): AntecedenteHospitalario => $historial->guardar($this->definitivo(
            $this->cerrar($cierre, '2026-07', [$this->tramo('jul', $ingreso, '2026-07-31 23:59', '08')], sha: $sha), AntecedenteHospitalario::TIPO_ARRANQUE));
        $agosto = fn (AntecedenteHospitalario $antecedente): AntecedenteHospitalario => $historial->guardar($this->definitivo(
            $this->cerrar($cierre, '2026-08', [$this->tramo('ago', '2026-08-20 08:00', '2026-08-31 23:59', '08', ['autorizacion' => 'AUT-B'])], $antecedente)));
        $agosto($julio('2026-07-20 08:00'));

        $this->assertSame(2, $julio('2026-07-20 08:00', 'json-corregido-sin-cambio-AH')->version);
        $this->assertSame(EstadoAntecedenteHospitalario::Comprobado, $cierre->estadoAntecedente($historial->vigente('PROSTATA', '2026-08'), $historial, []));

        $julioV3 = $julio('2026-07-21 08:00');
        $this->assertSame(EstadoAntecedenteHospitalario::Incompleto, $cierre->estadoAntecedente($historial->vigente('PROSTATA', '2026-08'), $historial, []));
        $this->assertSame(EstadoAntecedenteHospitalario::Comprobado, $cierre->estadoAntecedente($agosto($julioV3), $historial, []));
    }

    /**
     * Un cierre guardado con otro secreto no se puede leer: queda incompleto y su continuación, condicionada,
     * en vez de contarse como estancia nueva.
     */
    public function test_a_closure_saved_with_another_secret_is_incomplete_and_conditions_its_continuations(): void
    {
        $historial = new HistorialHospitalarioEnMemoria;
        $agosto = $historial->guardar($this->definitivo($this->cerrar($this->servicio(), '2026-08',
            [$this->tramo('ago', '2026-08-20 08:00', '2026-08-31 23:59', '08')]), AntecedenteHospitalario::TIPO_ARRANQUE));
        $otroSecreto = $this->servicio('otro-secreto-del-historial-hospitalario-de-prueba');

        $estado = $otroSecreto->estadoAntecedente($agosto, $historial, []);
        [$episodios, $segmentos] = $this->episodios([$this->tramo('sep', '2026-09-01 00:00', '2026-09-10 08:00', '01')]);
        $septiembre = $otroSecreto->procesar('PROSTATA', '2026-09', $episodios, $segmentos, $agosto, $estado);

        $this->assertSame(EstadoAntecedenteHospitalario::Incompleto, $estado);
        $this->assertSame(Reconstructor::INICIO_SIN_ANTECEDENTE, $septiembre['continuidades'][0]['motivo']);
    }

    /**
     * Cualquier versión guardada reproduce exactamente el cierre que se calculó con ella; reprocesar no duplica.
     */
    public function test_a_stored_version_reproduces_the_closure_computed_with_it(): void
    {
        $cierre = $this->servicio();
        $historial = new HistorialHospitalarioEnMemoria;
        $agostoV1 = $historial->guardar($this->definitivo($this->cerrar($cierre, '2026-08', [
            $this->tramo('ago', '2026-08-20 08:00', '2026-08-31 23:59', '08'),
            $this->tramo('ago-b', '2026-08-25 08:00', '2026-08-31 23:59', '08', ['paciente' => 'CC|101', 'documento' => '101']),
        ]), AntecedenteHospitalario::TIPO_ARRANQUE));
        $septiembre = [$this->tramo('sep', '2026-09-01 00:00', '2026-09-10 08:00', '01')];
        $septiembreV1 = $this->cerrar($cierre, '2026-09', $septiembre, $agostoV1);
        $agostoV2 = $historial->guardar($this->definitivo($this->cerrar($cierre, '2026-08',
            [$this->tramo('ago', '2026-08-20 08:00', '2026-08-31 23:59', '08')]), AntecedenteHospitalario::TIPO_ARRANQUE));

        $this->assertSame(2, $agostoV2->version);
        $this->assertSame($septiembreV1->hashContenido(), $this->cerrar($cierre, '2026-09', $septiembre, $historial->version('PROSTATA', '2026-08', 1))->hashContenido());
        $this->assertSame($septiembreV1->hashContenido(), $this->cerrar($cierre, '2026-09', $septiembre, $agostoV1)->hashContenido());
        $this->assertNotSame($septiembreV1->hashContenido(), $this->cerrar($cierre, '2026-09', $septiembre, $agostoV2)->hashContenido());
        $this->assertSame($agostoV2->id, $historial->guardar($this->definitivo($this->cerrar($cierre, '2026-08',
            [$this->tramo('ago', '2026-08-20 08:00', '2026-08-31 23:59', '08')]), AntecedenteHospitalario::TIPO_ARRANQUE))->id);
    }

    private function servicio(string $secreto = self::SECRETO): CierreHospitalarioPeriodo
    {
        return new CierreHospitalarioPeriodo(new PoliticaPaqueteHospitalario, $secreto);
    }

    /**
     * @param  list<array{episodio: array<string, mixed>, segmento: array<string, mixed>}>  $tramos
     * @return array{continuidades: list<array<string, mixed>>, cierre: AntecedenteHospitalario}
     */
    private function procesar(CierreHospitalarioPeriodo $cierre, string $periodo, array $tramos, ?AntecedenteHospitalario $antecedente, ?string $sha = null): array
    {
        [$episodios, $segmentos] = $this->episodios($tramos);

        return $cierre->procesar('PROSTATA', $periodo, $episodios, $segmentos, $antecedente,
            $antecedente === null ? EstadoAntecedenteHospitalario::Ausente : EstadoAntecedenteHospitalario::Comprobado,
            [['factura' => 'FAC-'.$periodo, 'archivo' => $periodo.'.json', 'sha256' => hash('sha256', $sha ?? $periodo)]]);
    }

    /**
     * @param  list<array{episodio: array<string, mixed>, segmento: array<string, mixed>}>  $tramos
     */
    private function cerrar(CierreHospitalarioPeriodo $cierre, string $periodo, array $tramos, ?AntecedenteHospitalario $antecedente = null, ?string $sha = null): AntecedenteHospitalario
    {
        return $this->procesar($cierre, $periodo, $tramos, $antecedente, $sha)['cierre'];
    }

    private function definitivo(AntecedenteHospitalario $provisional, string $tipo = AntecedenteHospitalario::TIPO_DEFINITIVO): AntecedenteHospitalario
    {
        return $provisional->comoCierre($tipo);
    }

    /**
     * @param  list<array{episodio: array<string, mixed>, segmento: array<string, mixed>}>  $tramos
     * @return array{0: list<array<string, mixed>>, 1: array<string, array<string, mixed>>}
     */
    private function episodios(array $tramos): array
    {
        return [array_column($tramos, 'episodio'), array_column(array_column($tramos, 'segmento'), null, 'clave')];
    }

    /**
     * Tramo de un solo segmento AH del documento CC 100 con la autorización AUT-A.
     *
     * @param  array<string, string>  $cambios
     * @return array{episodio: array<string, mixed>, segmento: array<string, mixed>}
     */
    private function tramo(string $clave, string $inicio, string $fin, string $destino, array $cambios = []): array
    {
        $segmento = [
            'clave' => $clave, 'paciente' => 'CC|100', 'tipoDocumento' => 'CC', 'documento' => '100', 'autorizacion' => 'AUT-A',
            'prestadorServicio' => '9000000102', 'inicio' => $inicio, 'fin' => $fin, 'destino' => $destino, 'factura' => 'FAC-'.substr($inicio, 0, 7),
            'consecutivo' => '1', ...$cambios,
        ];

        return [
            'episodio' => [
                'paciente' => $segmento['paciente'], 'segmentosOrdenados' => [['clave' => $clave, 'inicio' => $inicio, 'fin' => $fin, 'destino' => $destino]],
                'inicio' => $inicio, 'fin' => $fin, 'paquetes' => (new PoliticaPaqueteHospitalario)->paquetesPorEstancia($inicio, $fin),
            ],
            'segmento' => $segmento,
        ];
    }
}
