<?php

namespace App\Console\Commands;

use App\Programas\LaMaria\LibroPaquetesEjecucion;
use App\Programas\LaMaria\RutaCalculo;
use App\Services\ActivityLogger;
use Illuminate\Console\Command;

class EstablecerInicioLibroPaquetes extends Command
{
    protected $signature = 'continuidad:establecer-inicio
        {ruta : PROSTATA o CERVIX}
        {mes : Primer mes (aaaa-mm) cuyos paquetes se reconocen en el libro persistente}
        {--motivo= : Decisión y evidencia que respaldan el punto de inicio}
        {--aplicar : Registra el punto de inicio; sin esta opción solo informa}';

    protected $description = 'Registra el punto de inicio controlado del libro de paquetes de La María para una ruta (no crea saldos ni modifica ejecuciones históricas)';

    public function handle(ActivityLogger $logger, LibroPaquetesEjecucion $libro): int
    {
        $ruta = strtoupper((string) $this->argument('ruta'));
        $mes = (string) $this->argument('mes');
        $motivo = trim((string) $this->option('motivo'));

        if (! in_array($ruta, [RutaCalculo::Prostata->value, RutaCalculo::Cervix->value], true) || preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes) !== 1) {
            $this->error('Indica la ruta (PROSTATA o CERVIX) y el mes en formato aaaa-mm.');

            return self::FAILURE;
        }

        if ($motivo === '') {
            $this->error('Indica con --motivo la decisión y la evidencia del punto de inicio.');

            return self::FAILURE;
        }

        $anterior = $libro->iniciosPorRuta()[$ruta] ?? 'ninguno';
        $this->table(['Concepto', 'Valor'], [
            ['Ruta', $ruta],
            ['Inicio actual', $anterior],
            ['Nuevo inicio', $mes],
            ['Meses anteriores', LibroPaquetesEjecucion::PERIODO_HISTORICO.' (sin cobros; simulación y revisión) salvo incorporación conciliada'],
            ['Paquetes reconocidos por el software histórico', 'NO VERIFICADOS'],
            ['Paquetes reconocidos en facturación externa', 'NO VERIFICADOS'],
            ['Saldo inicial que se crea', 'NINGUNO'],
        ]);

        if (! $this->option('aplicar')) {
            $this->info('SIMULACION: no se registró nada.');

            return self::SUCCESS;
        }

        $log = $logger->log(LibroPaquetesEjecucion::ACCION_INICIO, 'Punto de inicio del libro de paquetes de La María.', [
            'ruta' => $ruta,
            'mes_inicio' => $mes,
            'inicio_anterior' => $anterior,
            'motivo' => $motivo,
            'saldo_inicial' => 'NINGUNO',
            'paquetes_software_historico' => 'NO_VERIFICADO',
            'paquetes_facturacion_externa' => 'NO_VERIFICADO',
        ]);
        $this->info('APLICADO: inicio '.$mes.' para '.$ruta.' (registro '.$log->id.').');

        return self::SUCCESS;
    }
}
