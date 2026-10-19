<?php

namespace Tests\Unit;

use App\Exceptions\ConcurrenciaContinuidadException;
use App\Programas\LaMaria\AlmacenContinuidadHospitalariaEnMemoria;
use App\Programas\LaMaria\LibroPaquetesHospitalarios;
use App\Programas\LaMaria\PoliticaPaqueteHospitalario;
use PHPUnit\Framework\TestCase;

class LibroPaquetesHospitalariosTest extends TestCase
{
    private const PAQUETE = PoliticaPaqueteHospitalario::VALOR_PAQUETE;

    public function test_stays_split_across_invoices_are_charged_by_real_duration(): void
    {
        $veinte = $this->cargar([[
            $this->segmento('F1', '2026-06-01 08:00', '2026-06-10 08:00', '08'),
            $this->segmento('F2', '2026-06-11 00:00', '2026-06-21 08:00', '01'),
        ]]);
        $treinta = $this->cargar([[
            $this->segmento('F1', '2026-06-01 08:00', '2026-06-15 08:00', '08'),
            $this->segmento('F2', '2026-06-16 00:00', '2026-07-01 08:00', '01'),
        ]]);
        $sesenta = $this->cargar([[
            $this->segmento('F1', '2026-06-01 08:00', '2026-06-20 23:59', '08'),
            $this->segmento('F2', '2026-06-21 00:30', '2026-07-10 23:59', '08'),
            $this->segmento('F3', '2026-07-11 00:30', '2026-07-31 08:00', '01'),
        ]]);

        $this->assertSame(['2026-06' => ['paquetes' => 1, 'valor' => round(self::PAQUETE, 2)]], $veinte->netoPorMes('PROSTATA'));
        $this->assertSame(2, $this->totalPaquetes($treinta));
        $this->assertSame(3, $this->totalPaquetes($sesenta));
        $this->assertEqualsWithDelta(3 * self::PAQUETE, $this->totalValor($sesenta), 0.01);
    }

    public function test_june_and_july_together_separately_or_in_reverse_order_are_equivalent_in_cost_and_net_movements(): void
    {
        $junio = [$this->segmento('FJ', '2026-06-10 08:00', '2026-06-30 23:59', '08')];
        $julio = [$this->segmento('FL', '2026-07-01 00:30', '2026-07-03 10:00', '01')];

        $juntos = $this->cargar([[...$junio, ...$julio]]);
        $separados = $this->cargar([$junio, $julio]);
        $invertidos = $this->cargar([$julio, $junio]);

        $this->assertSame(['2026-06' => ['paquetes' => 1, 'valor' => round(self::PAQUETE, 2)]], $juntos->netoPorMes('PROSTATA'));

        foreach ([$separados, $invertidos] as $libro) {
            $this->assertSame($juntos->netoPorMes('PROSTATA'), $libro->netoPorMes('PROSTATA'));
            $this->assertSame($juntos->bloquesVigentes('PROSTATA'), $libro->bloquesVigentes('PROSTATA'));
        }
    }

    public function test_july_alone_reports_an_unverified_admission_and_june_arriving_later_adjusts_without_duplicating(): void
    {
        $almacen = new AlmacenContinuidadHospitalariaEnMemoria;
        $libro = new LibroPaquetesHospitalarios($almacen);

        $soloJulio = $libro->registrarCarga([$this->segmento('FL', '2026-07-01 00:30', '2026-07-25 10:00', '01')], 'ejecucion-julio');
        $this->assertCount(1, $soloJulio['inicios_no_verificados']);
        $this->assertSame(['2026-07' => ['paquetes' => 1, 'valor' => round(self::PAQUETE, 2)]], $libro->netoPorMes('PROSTATA'));

        $conJunio = $libro->registrarCarga([$this->segmento('FJ', '2026-06-10 08:00', '2026-06-30 23:59', '08')], 'ejecucion-junio');

        $this->assertSame([], $conJunio['inicios_no_verificados']);
        $this->assertSame(['2026-06' => ['paquetes' => 1, 'valor' => round(self::PAQUETE, 2)], '2026-07' => ['paquetes' => 1, 'valor' => round(self::PAQUETE, 2)]], $libro->netoPorMes('PROSTATA'));
        $this->assertSame(
            [['2026-07-01 00:30', 1, 'RECONOCIMIENTO'], ['2026-06-10 08:00', 1, 'RECONOCIMIENTO'], ['2026-07-05 08:00', 1, 'RECONOCIMIENTO'], ['2026-07-01 00:30', -1, 'REVERSO_POR_CONTINUIDAD']],
            array_map(static fn (array $movimiento): array => [$movimiento['bloque_inicio'], $movimiento['signo'], $movimiento['motivo']], $almacen->movimientos('PROSTATA'))
        );
        $this->assertSame(2, count($libro->bloquesVigentes('PROSTATA')));
    }

    public function test_reprocessing_the_same_file_adds_no_versions_and_no_movements(): void
    {
        $almacen = new AlmacenContinuidadHospitalariaEnMemoria;
        $libro = new LibroPaquetesHospitalarios($almacen);
        $archivo = [$this->segmento('FJ', '2026-06-10 08:00', '2026-06-30 23:59', '08'), $this->segmento('FL', '2026-07-01 00:30', '2026-07-03 10:00', '01')];

        $libro->registrarCarga($archivo, 'primera');
        $segunda = $libro->registrarCarga($archivo, 'segunda');

        $this->assertSame(2, $segunda['sin_cambio']);
        $this->assertSame(0, $segunda['movimientos']);
        $this->assertCount(2, $almacen->segmentos('PROSTATA', 'CC|100'));
        $this->assertCount(1, $almacen->movimientos('PROSTATA'));
    }

    public function test_a_corrected_invoice_changes_nothing_until_it_is_loaded_as_a_correction(): void
    {
        $junio = [$this->segmento('FJ', '2026-06-10 08:00', '2026-06-30 23:59', '08')];
        $julio = [$this->segmento('FL', '2026-07-01 00:30', '2026-07-03 10:00', '01')];
        $julioCorregido = [$this->segmento('FL', '2026-07-01 00:30', '2026-07-08 10:00', '01')];
        $almacen = new AlmacenContinuidadHospitalariaEnMemoria;
        $libro = new LibroPaquetesHospitalarios($almacen);
        $libro->registrarCarga([...$junio, ...$julio], 'original');

        $sinMarcar = $libro->registrarCarga($julioCorregido, 'recarga');
        $this->assertSame(1, $sinMarcar['pendientes_revision']);
        $this->assertSame(['2026-06' => ['paquetes' => 1, 'valor' => round(self::PAQUETE, 2)]], $libro->netoPorMes('PROSTATA'));

        $corregida = $libro->registrarCarga($julioCorregido, 'correccion', esCorreccion: true);

        $this->assertSame(1, $corregida['corregidos']);
        $this->assertSame($this->cargar([[...$junio, ...$julioCorregido]])->netoPorMes('PROSTATA'), $libro->netoPorMes('PROSTATA'));
        $this->assertSame('CORRECCION_HISTORICA', $almacen->movimientos('PROSTATA')[1]['motivo']);
        $this->assertSame(
            [['FJ', 1, 'VIGENTE'], ['FL', 1, 'REEMPLAZADO'], ['FL', 2, 'PENDIENTE_REVISION'], ['FL', 3, 'VIGENTE']],
            array_map(static fn (array $segmento): array => [$segmento['factura'], $segmento['version'], $segmento['estado']], $almacen->segmentos('PROSTATA', 'CC|100'))
        );
    }

    public function test_real_discharge_and_readmission_are_two_episodes(): void
    {
        $libro = $this->cargar([[
            $this->segmento('FJ', '2026-06-10 08:00', '2026-06-30 23:59', '01'),
            $this->segmento('FL', '2026-07-03 08:00', '2026-07-05 10:00', '01'),
        ]]);

        $this->assertSame(['2026-06' => ['paquetes' => 1, 'valor' => round(self::PAQUETE, 2)], '2026-07' => ['paquetes' => 1, 'valor' => round(self::PAQUETE, 2)]], $libro->netoPorMes('PROSTATA'));
    }

    public function test_ambiguous_continuation_is_retained_without_additional_charges(): void
    {
        $almacen = new AlmacenContinuidadHospitalariaEnMemoria;
        $libro = new LibroPaquetesHospitalarios($almacen);

        $resumen = $libro->registrarCarga([
            $this->segmento('FJ', '2026-06-10 08:00', '2026-06-30 23:59', '08'),
            $this->segmento('FL1', '2026-07-01 00:30', '2026-07-25 10:00', '01'),
            $this->segmento('FL2', '2026-07-01 06:00', '2026-07-02 12:00', '01', '7'),
        ], 'carga');

        $this->assertCount(2, $resumen['retenidos']);
        $this->assertSame(['2026-06' => ['paquetes' => 1, 'valor' => round(self::PAQUETE, 2)]], $libro->netoPorMes('PROSTATA'));
        $this->assertSame('CONTINUIDAD_NO_CONFIRMADA', $almacen->movimientos('PROSTATA')[0]['estado_episodio']);
    }

    public function test_two_simultaneous_loads_of_the_same_episode_recognize_each_block_once(): void
    {
        $almacen = new AlmacenContinuidadHospitalariaEnMemoria;
        $carga = [$this->segmento('FJ', '2026-06-10 08:00', '2026-06-30 23:59', '08'), $this->segmento('FL', '2026-07-01 00:30', '2026-07-08 10:00', '01')];
        $primera = new LibroPaquetesHospitalarios($almacen);
        $segunda = new LibroPaquetesHospitalarios($almacen);
        $almacen->antesDeConfirmar = static fn () => $segunda->registrarCarga($carga, 'usuario-b');

        $resumen = $primera->registrarCarga($carga, 'usuario-a');

        $this->assertSame(1, $resumen['reintentos']);
        $this->assertSame(0, $resumen['movimientos']);
        $this->assertCount(2, $almacen->movimientos('PROSTATA'));
        $this->assertSame(['usuario-b', 'usuario-b'], array_column($almacen->movimientos('PROSTATA'), 'ejecucion'));
        $this->assertSame(2, $this->totalPaquetes($primera));
    }

    public function test_the_store_never_accepts_a_second_recognition_of_the_same_block(): void
    {
        $almacen = new AlmacenContinuidadHospitalariaEnMemoria;
        $movimiento = ['ruta' => 'PROSTATA', 'paciente' => 'CC|100', 'bloque_inicio' => '2026-06-10 08:00', 'mes_imputado' => '2026-06', 'signo' => 1, 'valor' => self::PAQUETE];
        $almacen->confirmar('PROSTATA', 'CC|100', 0, ['segmentos' => [], 'reemplazados' => [], 'movimientos' => [$movimiento]]);

        $this->expectException(ConcurrenciaContinuidadException::class);
        $almacen->confirmar('PROSTATA', 'CC|100', 1, ['segmentos' => [], 'reemplazados' => [], 'movimientos' => [$movimiento]]);
    }

    public function test_two_legitimate_services_with_similar_data_are_both_kept(): void
    {
        $almacen = new AlmacenContinuidadHospitalariaEnMemoria;
        $resumen = (new LibroPaquetesHospitalarios($almacen))->registrarCarga([
            $this->segmento('FJ', '2026-06-10 08:00', '2026-06-12 08:00', '01', '1'),
            $this->segmento('FJ', '2026-06-10 08:00', '2026-06-12 08:00', '01', '2'),
        ], 'carga');

        $this->assertSame(2, $resumen['nuevos']);
        $this->assertCount(2, $almacen->segmentos('PROSTATA', 'CC|100'));
    }

    public function test_any_order_and_grouping_of_non_consecutive_months_and_invoices_gives_the_same_result(): void
    {
        $meses = [
            'MAY' => [$this->segmento('FM', '2026-05-20 10:00', '2026-05-31 23:00', '08')],
            'JUN' => [$this->segmento('FJ', '2026-06-01 00:10', '2026-06-30 23:00', '08'), $this->segmento('FJ2', '2026-06-05 09:00', '2026-06-05 20:00', '01', '3', '200')],
            'JUL' => [$this->segmento('FL', '2026-07-01 00:20', '2026-07-12 09:00', '01')],
            'SEP' => [$this->segmento('FS', '2026-09-03 00:20', '2026-10-12 09:00', '01', '1', '300')],
        ];
        $referencia = $this->cargar([array_merge(...array_values($meses))]);

        foreach ($this->permutaciones(array_keys($meses)) as $orden) {
            foreach ([[1, 1, 1, 1], [2, 2], [1, 3], [3, 1], [4]] as $agrupacion) {
                $lotes = [];
                $posicion = 0;

                foreach ($agrupacion as $tamano) {
                    $lotes[] = array_merge(...array_map(static fn (string $mes): array => $meses[$mes], array_slice($orden, $posicion, $tamano)));
                    $posicion += $tamano;
                }

                $libro = $this->cargar([...$lotes, $meses[$orden[0]]]);

                $this->assertSame($referencia->netoPorMes('PROSTATA'), $libro->netoPorMes('PROSTATA'), implode(',', $orden));
                $this->assertSame($referencia->bloquesVigentes('PROSTATA'), $libro->bloquesVigentes('PROSTATA'), implode(',', $orden));
            }
        }

        $this->assertSame(5, $this->totalPaquetes($referencia));
    }

    /**
     * @param  list<list<array<string, string>>>  $lotes
     */
    private function cargar(array $lotes): LibroPaquetesHospitalarios
    {
        $libro = new LibroPaquetesHospitalarios(new AlmacenContinuidadHospitalariaEnMemoria);

        foreach ($lotes as $indice => $lote) {
            $libro->registrarCarga($lote, 'ejecucion-'.$indice);
        }

        return $libro;
    }

    /**
     * @return array<string, string>
     */
    private function segmento(string $factura, string $inicio, string $fin, string $destino, string $consecutivo = '1', string $documento = '100'): array
    {
        return [
            'ruta' => 'PROSTATA', 'factura' => $factura, 'tipoDocumento' => 'CC', 'documento' => $documento, 'consecutivo' => $consecutivo,
            'inicio' => $inicio, 'fin' => $fin, 'destino' => $destino, 'archivo' => $factura.'.json',
            'autorizacion' => 'AUT-1', 'prestadorServicio' => '050010608601',
        ];
    }

    private function totalPaquetes(LibroPaquetesHospitalarios $libro): int
    {
        return array_sum(array_column($libro->netoPorMes('PROSTATA'), 'paquetes'));
    }

    private function totalValor(LibroPaquetesHospitalarios $libro): float
    {
        return array_sum(array_column($libro->netoPorMes('PROSTATA'), 'valor'));
    }

    /**
     * @param  list<string>  $items
     * @return list<list<string>>
     */
    private function permutaciones(array $items): array
    {
        if (count($items) <= 1) {
            return [$items];
        }

        $resultado = [];

        foreach ($items as $indice => $item) {
            $resto = $items;
            unset($resto[$indice]);

            foreach ($this->permutaciones(array_values($resto)) as $permutacion) {
                $resultado[] = [$item, ...$permutacion];
            }
        }

        return $resultado;
    }
}
