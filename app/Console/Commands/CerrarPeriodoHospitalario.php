<?php

namespace App\Console\Commands;

use App\Programas\LaMaria\AntecedenteHospitalario;
use App\Programas\LaMaria\CierreHospitalarioPeriodo;
use App\Programas\LaMaria\CondicionArchivo;
use App\Programas\LaMaria\EstadoAntecedenteHospitalario;
use App\Programas\LaMaria\HistorialHospitalario;
use App\Programas\LaMaria\PoliticaPaqueteHospitalario;
use App\Programas\LaMaria\RutaCalculo;
use App\Services\SeguimientoRutaClassifier;
use Illuminate\Console\Command;

/**
 * Único camino para guardar un cierre hospitalario. Un período solo se cierra con
 * la lista completa de sus facturas (--factura), archivos de ese mismo período
 * (atenciones del mes y ninguna posterior), sin repetidos ni conflictos, con la
 * evidencia de que está completo (--motivo), el antecedente comprobado y el mismo
 * secreto. --arranque solo vale para el período de inicio autorizado.
 */
class CerrarPeriodoHospitalario extends Command
{
    protected $signature = 'la-maria:cerrar-periodo
        {ruta : PROSTATA o CERVIX}
        {periodo : Mes (aaaa-mm) cuyos RIPS están completos}
        {--archivo=* : Ruta de cada JSON RIPS del período (todas las facturas del mes)}
        {--factura=* : Número de cada factura que debe tener el período (lista esperada; obligatoria para guardar)}
        {--arranque : Período de inicio autorizado (LA_MARIA_HISTORIAL_ARRANQUE): se cierra sin antecedente}
        {--motivo= : Evidencia de que el período está completo o motivo de la corrección (obligatorio para guardar)}
        {--aplicar : Guarda el cierre; sin esta opción solo simula}';

    protected $description = 'Cierra el período hospitalario de La María (antecedente del mes siguiente). Nunca modifica versiones anteriores.';

    public function handle(HistorialHospitalario $historial): int
    {
        $ruta = strtoupper((string) $this->argument('ruta'));
        $periodo = (string) $this->argument('periodo');
        $rutas = (array) $this->option('archivo');
        $esperadas = array_values(array_unique(array_filter(array_map('trim', (array) $this->option('factura')))));
        $motivo = trim((string) $this->option('motivo'));
        $arranque = (bool) $this->option('arranque');
        $secreto = (string) config('programas.la-maria.historial_hospitalario.secreto', '');

        if (! in_array($ruta, [RutaCalculo::Prostata->value, RutaCalculo::Cervix->value], true) || preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periodo) !== 1) {
            return $this->fallar('Indica la ruta (PROSTATA o CERVIX) y el período en formato aaaa-mm.');
        }

        if (strlen($secreto) < 32) {
            return $this->fallar('Falta LA_MARIA_HISTORIAL_SECRETO (mínimo 32 caracteres): sin él no se puede seudonimizar al paciente.');
        }

        if ($rutas === [] || array_filter($rutas, static fn (string $archivo): bool => ! is_file($archivo)) !== []) {
            return $this->fallar('Indica con --archivo cada JSON RIPS del período; todos deben existir.');
        }

        if ($this->option('aplicar') && ($esperadas === [] || $motivo === '')) {
            return $this->fallar('Para guardar indica con --factura cada factura esperada del período y con --motivo la evidencia de que está completo.');
        }

        $classifier = new SeguimientoRutaClassifier;
        $archivos = array_map(static fn (string $archivo): array => ['path' => $archivo, 'fileName' => basename($archivo)], $rutas);
        $procesables = $classifier->processableFiles($classifier->screenFiles($archivos));

        if (count($procesables) !== count($archivos) || array_filter($procesables, static fn (array $archivo): bool => ($archivo['condicion'] ?? '') !== CondicionArchivo::Unico->value) !== []) {
            return $this->fallar('Hay archivos repetidos o en conflicto: un período con cargue incompleto no se cierra.');
        }

        $perfiles = $classifier->profilesForFiles($procesables);
        ['episodios' => $episodios, 'segmentos' => $segmentos] = $classifier->episodiosPorRutaYMes($procesables, $perfiles);
        $origen = $classifier->origenArchivos($procesables);
        $cargadas = array_column($origen, 'factura');
        $periodos = $classifier->periodosDeFacturas($procesables, $perfiles);
        $ajenas = array_filter($cargadas, static fn (string $factura): bool => ($periodos[$factura] ?? '') !== $periodo);

        if ($ajenas !== []) {
            return $this->fallar("Archivos de otro período (deben traer atenciones de {$periodo} y ninguna posterior): "
                .implode(', ', array_map(static fn (string $factura): string => $factura.' ('.($periodos[$factura] ?? 'sin atenciones').')', $ajenas)).'.');
        }

        $faltantes = array_diff($esperadas, $cargadas);
        $sobrantes = array_diff($cargadas, $esperadas);

        if ($esperadas !== [] && ($faltantes !== [] || $sobrantes !== [])) {
            return $this->fallar('Los archivos no son la lista esperada de facturas del período. Faltan: '.(implode(', ', $faltantes) ?: 'ninguna')
                .'. Sobran: '.(implode(', ', $sobrantes) ?: 'ninguna').'.');
        }

        if (! isset($episodios[$ruta][$periodo])) {
            return $this->fallar("Los archivos no traen atenciones de {$ruta} en {$periodo}.");
        }

        $cierre = new CierreHospitalarioPeriodo(new PoliticaPaqueteHospitalario, $secreto);
        $anterior = CierreHospitalarioPeriodo::mesAnterior($periodo);
        $vigente = $historial->vigente($ruta, $periodo);

        if ($arranque) {
            $autorizado = (string) config('programas.la-maria.historial_hospitalario.arranque', '');

            if ($autorizado !== $periodo) {
                return $this->fallar('--arranque solo se permite en el período de inicio autorizado (LA_MARIA_HISTORIAL_ARRANQUE: '
                    .($autorizado === '' ? 'sin valor' : $autorizado).'); no sirve para saltar un antecedente faltante.');
            }

            if ($historial->vigente($ruta, $anterior) !== null) {
                return $this->fallar("Ya existe el cierre {$ruta} {$anterior}: {$periodo} se cierra con él como antecedente, sin --arranque.");
            }
        }

        $antecedente = $arranque ? null : $historial->vigente($ruta, $anterior);

        foreach (array_filter([$antecedente, $vigente]) as $guardado) {
            if ($guardado->huellaSecreto !== $cierre->huellaSecreto()) {
                return $this->fallar("El cierre {$guardado->etiqueta()} se guardó con otro secreto (LA_MARIA_HISTORIAL_SECRETO cambió): "
                    .'restaure el secreto original; no se cierra ni se reemplaza nada.');
            }
        }

        $estado = $cierre->estadoAntecedente($antecedente, $historial, $origen);

        if (! $arranque && $estado !== EstadoAntecedenteHospitalario::Comprobado) {
            return $this->fallar("El antecedente {$ruta} {$anterior} está {$estado->value}: ciérrelo (o vuelva a cerrarlo) antes de {$periodo}.");
        }

        $quitadas = $vigente === null ? [] : array_diff(array_column($vigente->origen, 'factura'), $cargadas);

        if ($quitadas !== []) {
            return $this->fallar("Una corrección no puede quitar facturas del cierre vigente {$vigente->etiqueta()}: falta ".implode(', ', $quitadas).'.');
        }

        $definitivo = $cierre->procesar($ruta, $periodo, $episodios[$ruta][$periodo], $segmentos, $antecedente, $estado, $origen)['cierre']
            ->comoCierre($arranque ? AntecedenteHospitalario::TIPO_ARRANQUE : AntecedenteHospitalario::TIPO_DEFINITIVO, $motivo);
        $estados = array_count_values(array_column($definitivo->estancias, 'estado'));
        ksort($estados);

        $this->table(['Concepto', 'Valor'], [
            ['Ruta y período', "{$ruta} {$periodo}"],
            ['Antecedente', $antecedente === null ? 'NINGUNO (arranque)' : $antecedente->etiqueta().' · '.$estado->value],
            ['Facturas', implode(', ', $cargadas).' · origen '.substr($definitivo->hashOrigen(), 0, 12)],
            ['Completitud', 'CONFIRMADA POR EL OPERADOR: --factura no se contrasta con una fuente independiente de facturas'],
            ['Estancias del cierre', implode(' · ', array_map(static fn (string $e, int $n): string => "{$e}: {$n}", array_keys($estados), $estados)) ?: 'ninguna'],
            ['Hash del cierre', $definitivo->hashContenido()],
            ['Versión vigente', $vigente === null ? 'ninguna' : $vigente->etiqueta()],
        ]);

        if (! $this->option('aplicar')) {
            $this->info('SIMULACION: no se guardó nada.'.match (true) {
                $vigente === null => '',
                $vigente->hashContenido() === $definitivo->hashContenido() && $vigente->hashOrigen() === $definitivo->hashOrigen() => ' Coincide con el cierre vigente '.$vigente->etiqueta().'.',
                default => ' DISTINTO del cierre vigente '.$vigente->etiqueta().': guardarlo crearía una versión nueva.',
            });

            return self::SUCCESS;
        }

        $guardado = $historial->guardar($definitivo);

        if ($vigente !== null && $guardado->id === $vigente->id) {
            $this->info('SIN CAMBIOS: el cierre ya está guardado como '.$guardado->etiqueta().'.');

            return self::SUCCESS;
        }

        $this->info('GUARDADO: '.$guardado->etiqueta().($vigente === null ? '' : ' reemplaza a v'.$vigente->version.' (que se conserva).'));

        for ($mes = CierreHospitalarioPeriodo::mesSiguiente($periodo); ($posterior = $historial->vigente($ruta, $mes)) !== null; $mes = CierreHospitalarioPeriodo::mesSiguiente($mes)) {
            if ($cierre->estadoAntecedente($posterior, $historial, []) !== EstadoAntecedenteHospitalario::Comprobado) {
                $this->warn("El cierre {$posterior->etiqueta()} se calculó con la cadena anterior y queda INCOMPLETO: vuelva a cerrarlo.");
            }
        }

        return self::SUCCESS;
    }

    private function fallar(string $mensaje): int
    {
        $this->error($mensaje);

        return self::FAILURE;
    }
}
