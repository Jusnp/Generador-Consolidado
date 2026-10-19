<?php

namespace App\Programas\LaMaria;

/**
 * Descompone la brecha respaldada (referencia − costo respaldado) por grupo
 * del resumen de la nota técnica, comparando la actividad proyectada con la
 * actividad real del RIPS. Solo se atribuye un monto cuando la causa y el
 * valor se demuestran con la nota y el RIPS; lo demás queda como NO
 * CUANTIFICADA (causa conocida, monto no demostrable) o SIN EXPLICAR.
 */
class ExplicacionBrecha
{
    public const HEADERS = [
        'RUTA', 'MES', 'GRUPO NOTA', 'PROYECTADO NOTA', 'COSTO RESPALDADO', 'CONDICIONADO EN ESCENARIO',
        'EXTERNO POTENCIAL', 'BRECHA RESPALDADA', 'UNIDADES PROYECTADAS', 'UNIDADES RIPS',
        'PRESUPUESTADO SIN ACTIVIDAD RIPS', 'MENOR ACTIVIDAD QUE LA PROYECTADA', 'MAYOR ACTIVIDAD QUE LA PROYECTADA',
        'ACTIVIDAD REAL NO RESPALDADA', 'BRECHA EXPLICADA', 'BRECHA NO CUANTIFICADA', 'DIFERENCIA SIN EXPLICAR',
        'PORCENTAJE EXPLICADO', 'CAUSA Y EVIDENCIA',
    ];

    private const ORDEN = [
        'URGENCIAS', 'CONSULTA_EXTERNA', 'TERAPIAS', 'IMAGENES_DIAGNOSTICAS', 'PROCEDIMIENTOS_DIAGNOSTICOS_Y_TERAPEUTICOS',
        'PROCEDIMIENTOS_QUIRURGICOS', 'LABORATORIO_CLINICO', 'HOSPITALIZACION', 'QUIMIOTERAPIA', 'RADIOTERAPIA',
        'MEDICAMENTOS', 'TRANSPORTE_ALBERGUE', 'FUERA_DE_NOTA',
    ];

    /** Posición de cada importe en la fila de un grupo (sin RUTA, MES ni GRUPO). */
    private const COLUMNAS_SUMADAS = [
        'proyectado' => 0, 'respaldado' => 1, 'condicionado' => 2, 'externo' => 3, 'brecha' => 4,
        'sinActividad' => 7, 'menor' => 8, 'mayor' => 9, 'noRespaldada' => 10, 'explicada' => 11,
        'noCuantificada' => 12, 'sinExplicar' => 13,
    ];

    /** @var array<string, array{explicada: float, noCuantificada: float, sinExplicar: float}> */
    private array $totalesPorMes = [];

    public function __construct(private ResumenEconomico $resumen) {}

    /**
     * @param  array<string, array<string, float|int>>  $totalesMensuales  ResumenEconomico::totalesMensuales().
     * @return list<array<int, mixed>>
     */
    public function filas(array $totalesMensuales): array
    {
        $ruta = $this->resumen->ruta();
        $proyectadoGrupo = [];
        $conceptos = [];
        $tarifaPaquete = PoliticaPaqueteHospitalario::VALOR_PAQUETE;

        foreach ($this->resumen->notaTecnica() as $concepto) {
            $codigo = (string) ($concepto['codigo'] ?? '');
            $metadatos = (array) ($concepto['metadatos'] ?? []);

            if (($concepto['categoria'] ?? '') === 'RESUMEN_TECNICO' && $codigo !== 'RESUMEN:VALOR_TOTAL_MES') {
                $proyectadoGrupo[substr($codigo, strlen('RESUMEN:'))] = (float) $concepto['tarifaReferencia'];

                continue;
            }

            if ($codigo === 'HX:COSTO_PONDERADO_DE_HOSPITALIZACION' && (float) ($metadatos['valores_referencia'][0] ?? 0) > 0) {
                $tarifaPaquete = (float) $metadatos['valores_referencia'][0];

                continue;
            }

            $grupo = ResumenEconomico::grupoDeConcepto($concepto);

            if ($grupo === null) {
                continue;
            }

            $cantidad = match ($concepto['categoria']) {
                'SERVICIO_TECNICO' => (float) ($metadatos['cantidad_mensual'] ?? 0),
                'TRANSPORTE_ALBERGUE' => (float) ($metadatos['cantidad_proyectada'] ?? 0) / 12,
                default => (float) ($metadatos['cantidad_referencia'] ?? 0),
            };
            $grupoCodigo = $concepto['categoria'] === 'SERVICIO_TECNICO' ? ($this->resumen->grupoDeCodigo($codigo) ?? $grupo) : $grupo;
            $conceptos[$grupoCodigo][$codigo] ??= ['cantidad' => 0.0, 'tarifa' => (float) ($concepto['tarifaReferencia'] ?? 0), 'tarifaMedicamento' => 0.0];
            $conceptos[$grupoCodigo][$codigo]['cantidad'] += $cantidad;
            $conceptos[$grupoCodigo][$codigo]['tarifaMedicamento'] = (float) ($metadatos['tarifa_medicamento'] ?? 0);
        }

        $filas = [];
        $this->totalesPorMes = [];
        $grupos = $this->resumen->grupos();

        foreach ($totalesMensuales as $mes => $total) {
            $clave = $mes === 'SIN FECHA' ? '' : (string) $mes;
            $nombres = array_values(array_unique([...array_keys($proyectadoGrupo), ...array_keys($grupos[$clave] ?? [])]));
            usort($nombres, static fn (string $a, string $b): int => [array_search($a, self::ORDEN, true) === false ? 99 : array_search($a, self::ORDEN, true), $a]
                <=> [array_search($b, self::ORDEN, true) === false ? 99 : array_search($b, self::ORDEN, true), $b]);
            $suma = ['proyectado' => 0.0, 'respaldado' => 0.0, 'condicionado' => 0.0, 'externo' => 0.0, 'brecha' => 0.0,
                'sinActividad' => 0.0, 'menor' => 0.0, 'mayor' => 0.0, 'noRespaldada' => 0.0, 'explicada' => 0.0, 'noCuantificada' => 0.0, 'sinExplicar' => 0.0];

            foreach ($nombres as $grupo) {
                $fila = $this->grupo($clave, $grupo, $proyectadoGrupo[$grupo] ?? 0.0, $conceptos[$grupo] ?? [], $grupos[$clave][$grupo] ?? [], $total, $tarifaPaquete);
                $filas[] = [$ruta, $mes, $grupo, ...$fila];

                foreach (self::COLUMNAS_SUMADAS as $campo => $indice) {
                    $suma[$campo] += $fila[$indice];
                }
            }

            $referencia = $this->resumen->referencia($ruta);
            $redondeo = round($referencia - $suma['proyectado'], 2);
            $brecha = round($referencia - $suma['respaldado'], 2);
            $sinExplicar = round($brecha - $suma['explicada'] - $suma['noCuantificada'], 2);
            $this->totalesPorMes[(string) $mes] = ['explicada' => $suma['explicada'], 'noCuantificada' => $suma['noCuantificada'], 'sinExplicar' => $sinExplicar];
            $filas[] = [$ruta, $mes, 'TOTAL', $referencia, round($suma['respaldado'], 2), round($suma['condicionado'], 2), round($suma['externo'], 2),
                $brecha, '', '', round($suma['sinActividad'], 2), round($suma['menor'], 2), round($suma['mayor'], 2), round($suma['noRespaldada'], 2),
                round($suma['explicada'], 2), round($suma['noCuantificada'], 2), $sinExplicar,
                $brecha > 0 ? round($suma['explicada'] / $brecha, 6) : '',
                'BRECHA = EXPLICADA + NO CUANTIFICADA + SIN EXPLICAR. Incluye $'.number_format($redondeo, 2, ',', '.')
                    .' de redondeo entre el total de la nota y la suma de sus grupos. La brecha presupuestal no es ingreso perdido ni facturación pendiente.'];
        }

        return $filas;
    }

    /**
     * @return array<string, array{explicada: float, noCuantificada: float, sinExplicar: float}>
     */
    public function totalesPorMes(): array
    {
        return $this->totalesPorMes;
    }

    /**
     * @param  array<string, array{cantidad: float, tarifa: float, tarifaMedicamento: float}>  $conceptos
     * @param  array<string, int>  $costos
     * @param  array<string, float|int>  $total
     * @return list<mixed>
     */
    private function grupo(string $mes, string $grupo, float $proyectado, array $conceptos, array $costos, array $total, float $tarifaPaquete): array
    {
        $respaldadoServicios = ($costos['respaldado'] ?? 0) / 100.0;
        $respaldadoMedicamentos = ($costos['respaldadoMedicamento'] ?? 0) / 100.0;
        $condicionadoServicios = ($costos['condicionado'] ?? 0) / 100.0;
        $condicionadoMedicamentos = ($costos['condicionadoMedicamento'] ?? 0) / 100.0;
        $externo = (($costos['externo'] ?? 0) + ($costos['externoMedicamento'] ?? 0)) / 100.0;
        $externoMedicamentos = ($costos['externoMedicamento'] ?? 0) / 100.0;
        $respaldado = $respaldadoServicios + $respaldadoMedicamentos;

        if ($grupo === 'HOSPITALIZACION') {
            $respaldado += (float) $total['paquetes'];
        }

        $condicionado = $condicionadoServicios + $condicionadoMedicamentos;
        $brecha = $proyectado - $respaldado;
        $sinActividad = $menor = $mayor = $noRespaldada = $noCuantificada = 0.0;
        $unidadesProyectadas = $unidadesRips = 0.0;
        $causa = '';

        if ($grupo === 'HOSPITALIZACION') {
            $paquetes = $tarifaPaquete > 0 ? (float) $total['paquetes'] / $tarifaPaquete : 0.0;
            $unidadesProyectadas = $tarifaPaquete > 0 ? $proyectado / $tarifaPaquete : 0.0;
            $unidadesRips = round($paquetes, 2);
            [$sinActividad, $menor, $mayor] = $this->volumen($unidadesProyectadas, $paquetes, $tarifaPaquete);
            $noRespaldada = $condicionado + $externo;
            $causa = sprintf('Paquetes proyectados %s frente a %s paquetes reales de $%s (bloques de 25 días).',
                number_format($unidadesProyectadas, 2, ',', '.'), number_format($paquetes, 2, ',', '.'), number_format($tarifaPaquete, 2, ',', '.'));
        } elseif (in_array($grupo, ['RADIOTERAPIA', 'TRANSPORTE_ALBERGUE'], true)) {
            $actividadGrupo = $this->resumen->actividad($mes, 'grupo:'.$grupo);
            $unidadesRips = $actividadGrupo;

            foreach ($conceptos as $codigo => $concepto) {
                $unidadesProyectadas += $concepto['cantidad'];
                $valor = $concepto['cantidad'] * $concepto['tarifa'];
                $conActividad = $grupo === 'RADIOTERAPIA' ? $this->resumen->actividad($mes, (string) $codigo) > 0 : $actividadGrupo > 0;
                $conActividad ? $noCuantificada += $valor : $sinActividad += $valor;
            }

            $noRespaldada = $condicionado + $externo;
            $causa = $grupo === 'RADIOTERAPIA'
                ? 'La nota proyecta tratamientos; el RIPS reporta líneas por sesión o actividad. La unidad contractual está pendiente: lo proyectado con actividad RIPS no se puede valorar.'
                : 'Transporte y albergue sin unidad contractual confirmada (viaje o día). Las líneas RIPS se cuentan sin valorar.';
        } elseif ($conceptos !== []) {
            $valorReal = 0.0;
            $medicamentoProyectado = 0.0;

            foreach ($conceptos as $codigo => $concepto) {
                $real = $this->resumen->actividad($mes, (string) $codigo);
                $unidadesProyectadas += $concepto['cantidad'];
                $unidadesRips += $real;
                // El volumen se valora solo con la tarifa del código (aplicación en
                // quimioterapia): el promedio de medicamento por ciclo no es una unidad del RIPS.
                [$sin, $men, $may] = $this->volumen($concepto['cantidad'], $real, $concepto['tarifa']);
                $sinActividad += $sin;
                $menor += $men;
                $mayor += $may;
                $valorReal += $real * $concepto['tarifa'];
                $medicamentoProyectado += $concepto['cantidad'] * $concepto['tarifaMedicamento'];
            }

            // Actividad real valorada a la tarifa de la nota que no quedó respaldada
            // (condicionada, absorbida en paquete o sin cantidad válida), más los
            // medicamentos con costo condicionado o externo demostrado.
            $noRespaldada = ($valorReal - $respaldadoServicios) + $condicionadoMedicamentos + $externoMedicamentos;
            $causa = $grupo === 'QUIMIOTERAPIA'
                ? sprintf('Aplicaciones proyectadas %s frente a %s en RIPS, valoradas a la tarifa de aplicación. Medicamentos: la nota proyecta $%s (ciclos × promedio por ciclo); el RIPS respalda $%s y condiciona $%s. El resto no se atribuye: el promedio por ciclo no es comparable línea a línea con los medicamentos reales (mezcla de esquemas, ciclos o precio).',
                    number_format($unidadesProyectadas, 2, ',', '.'), number_format($unidadesRips, 2, ',', '.'),
                    number_format($medicamentoProyectado, 2, ',', '.'), number_format($respaldadoMedicamentos, 2, ',', '.'),
                    number_format($condicionadoMedicamentos + $externoMedicamentos, 2, ',', '.'))
                : sprintf('Actividad proyectada %s frente a %s en RIPS para %d códigos de la nota, valorada a la tarifa de la nota.',
                    number_format($unidadesProyectadas, 2, ',', '.'), number_format($unidadesRips, 2, ',', '.'), count($conceptos));
        } else {
            $noRespaldada = $condicionado + $externo;
            $unidadesRips = (float) ($costos['lineas'] ?? 0);
            $causa = match ($grupo) {
                'MEDICAMENTOS' => 'La nota proyecta medicamentos por familia anual, sin cantidades por código: no se mide volumen. Se explican los condicionados y externos valorados.',
                'FUERA_DE_NOTA' => 'Servicios sin concepto en la nota técnica de la ruta.',
                default => 'Grupo sin conceptos itemizados en la nota.',
            };
            $unidadesProyectadas = 0.0;
        }

        if ($grupo === 'FUERA_DE_NOTA') {
            $noRespaldada = -$respaldado;
        }

        $explicada = $sinActividad + $menor - $mayor + $noRespaldada;
        $sinExplicar = $brecha - $explicada - $noCuantificada;
        $sinTarifa = (int) ($costos['sinTarifa'] ?? 0);

        if ($sinTarifa > 0) {
            $causa .= sprintf(' %d línea(s) RIPS sin tarifa aplicable: monto NO CUANTIFICADO.', $sinTarifa);
        }

        return [
            round($proyectado, 2),
            round($respaldado, 2),
            round($condicionado, 2),
            round($externo, 2),
            round($brecha, 2),
            round($unidadesProyectadas, 4),
            round($unidadesRips, 4),
            round($sinActividad, 2),
            round($menor, 2),
            round($mayor, 2),
            round($noRespaldada, 2),
            round($explicada, 2),
            round($noCuantificada, 2),
            round($sinExplicar, 2),
            $brecha > 0 ? round($explicada / $brecha, 6) : '',
            trim($causa),
        ];
    }

    /**
     * @return array{0: float, 1: float, 2: float} Sin actividad, menor y mayor actividad valoradas.
     */
    private function volumen(float $proyectada, float $real, float $valorUnitario): array
    {
        return match (true) {
            $real <= 0 => [$proyectada * $valorUnitario, 0.0, 0.0],
            $real < $proyectada => [0.0, ($proyectada - $real) * $valorUnitario, 0.0],
            default => [0.0, 0.0, ($real - $proyectada) * $valorUnitario],
        };
    }
}
