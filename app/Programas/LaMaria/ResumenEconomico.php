<?php

namespace App\Programas\LaMaria;

/**
 * Acumula, línea por línea del DETALLE RIPS, el resultado respaldado y el
 * escenario referencial ampliado de una ruta. Las mismas cifras alimentan la
 * pantalla y todas las hojas económicas del Excel, sin cálculos paralelos.
 *
 * A (respaldado): tarifa de nota técnica sin condiciones pendientes.
 * B (escenario referencial): A + condicionados que sumaría FASE_1
 * (precio administrativo de dispensación y reglas pendientes). No es
 * facturación aprobada. Los externos potenciales no suman en ninguno.
 */
class ResumenEconomico
{
    public const HEADERS = [
        'RUTA', 'MES', 'REFERENCIA NOTA', 'COSTO RESPALDADO', 'CONDICIONADO INCLUIDO', 'ESCENARIO REFERENCIAL',
        'EXTERNOS POTENCIALES', 'BRECHA RESPALDADA', 'BRECHA REFERENCIAL', 'COSTO ACTIVO',
        'DISPENSACION ADMINISTRATIVA EN ESCENARIO', 'COBERTURA RESPALDADA', 'COBERTURA REFERENCIAL',
        'SERVICIOS SIN TARIFA', 'BRECHA EXPLICADA', 'BRECHA NO CUANTIFICADA', 'DIFERENCIA SIN EXPLICAR',
        'PORCENTAJE EXPLICADO', 'FACTURACION APROBADA',
    ];

    public const MEDICATION_HEADERS = [
        'MEDICAMENTO', 'CUM', 'IUM', 'PRESENTACION', 'CANTIDAD RIPS', 'TARIFA UTILIZADA', 'FUENTE',
        'COSTO RESPALDADO', 'COSTO CONDICIONADO', 'EXTERNO POTENCIAL NO SUMADO', 'ESCENARIO REFERENCIAL',
        'LINEAS', 'MESES', 'MOTIVO DE EXCLUSION',
    ];

    public const NO_TARIFF_HEADERS = [
        'TIPO SERVICIO', 'CODIGO', 'DESCRIPCION', 'GRUPO NOTA', 'ESTADO TARIFARIO', 'MOTIVO', 'LINEAS',
        'CANTIDAD', 'MESES', 'VALOR EN ESCENARIO REFERENCIAL', 'EXTERNO POTENCIAL NO SUMADO', 'EN NOTA TECNICA',
    ];

    /** Referencias mensuales de las notas técnicas cuando la nota activa no trae su total. */
    public const REFERENCIAS = ['PROSTATA' => 3622024541.0, 'CERVIX' => 1892436504.0];

    /** Exclusiones que no son actividad real distinta (no se miden ni valoran). */
    private const EXCLUSIONES = [
        'DUPLICADO_EXACTO', 'FACTURA_REPETIDA_CON_DIFERENCIAS', 'NO_ASIGNABLE', 'DOBLE_RUTA',
        'CANTIDAD_MEDICAMENTO_NO_INFORMADA', 'CANTIDAD_INVALIDA',
    ];

    private const NO_ES_ACTIVIDAD = [
        'DUPLICADO_EXACTO', 'FACTURA_REPETIDA_CON_DIFERENCIAS', 'NO_ASIGNABLE', 'DOBLE_RUTA', 'SIN_FECHA',
    ];

    /** @var array<string, array<string, int>> */
    private array $meses = [];

    /** @var array<string, array<string, array<string, int>>> */
    private array $grupos = [];

    /** @var array<string, array<string, float>> */
    private array $actividad = [];

    /** @var array<string, array<string, mixed>> */
    private array $medicamentos = [];

    /** @var array<string, array<string, mixed>> */
    private array $sinTarifa = [];

    /** @var array<string, string> */
    private array $grupoPorCodigo = [];

    /**
     * @param  list<array<string, mixed>>  $notaTecnica  Conceptos de la nota de la ruta.
     */
    public function __construct(private string $ruta = '', private array $notaTecnica = [])
    {
        foreach ($notaTecnica as $concepto) {
            $grupo = self::grupoDeConcepto($concepto);

            if ($grupo !== null && in_array($concepto['categoria'] ?? '', ['ONCOLOGIA_QUIMIOTERAPIA', 'ONCOLOGIA_RADIOTERAPIA'], true)) {
                $this->grupoPorCodigo[(string) $concepto['codigo']] = $grupo;
            } elseif ($grupo !== null) {
                $this->grupoPorCodigo[(string) $concepto['codigo']] ??= $grupo;
            }
        }
    }

    public function ruta(): string
    {
        return $this->ruta;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function notaTecnica(): array
    {
        return $this->notaTecnica;
    }

    /**
     * @param  array<string, mixed>  $detalle
     * @return array{estado: string, respaldado: float, condicionadoIncluido: float, referencialAdicional: float, escenarioReferencial: float, condicionadoExterno: float, motivo: string, grupoNota: string}
     */
    public function registrar(array $detalle): array
    {
        $incluido = ($detalle['sumaAlResumen'] ?? 'NO') === 'SI';
        $enEscenario = ($detalle['sumaEscenarioReferencial'] ?? $detalle['sumaAlResumen'] ?? 'NO') === 'SI';
        $motivoNoSuma = (string) ($detalle['motivoNoSuma'] ?? '');
        $absorcion = in_array($detalle['decisionPaquete'] ?? '', ['INCLUIDO_EN_PAQUETE', 'INCLUIDO_EN_URGENCIA'], true);
        $condicionado = ($detalle['fuenteTarifa'] ?? '') !== 'NOTA_TECNICA'
            || in_array($detalle['decisionPaquete'] ?? '', ['COBRABLE_POR_FUERA', 'REGLA_NO_DEFINIDA'], true);
        $excluido = in_array($motivoNoSuma, self::EXCLUSIONES, true);
        $conTarifa = ($detalle['estadoTarifario'] ?? 'CON_TARIFA') === 'CON_TARIFA';
        $costo = $incluido ? self::centavos($detalle['costoEstimadoReferencia'] ?? 0) : 0;
        $adicional = ! $incluido && $enEscenario ? self::centavos($detalle['costoEscenarioReferencial'] ?? 0) : 0;
        $externo = ! $enEscenario && ! $absorcion && ! $excluido ? self::centavos($detalle['valorCondicionado'] ?? 0) : 0;
        $sinTarifa = ! $incluido && ! $absorcion && ! $excluido && ! $conTarifa;
        $estado = match (true) {
            $absorcion => 'SERVICIO ABSORBIDO',
            $incluido && $condicionado => 'COSTO CONDICIONADO',
            $incluido => 'COSTO RESPALDADO',
            $adicional > 0 => 'ESCENARIO REFERENCIAL (PRECIO ADMINISTRATIVO)',
            $externo > 0 => 'EXTERNO POTENCIAL NO SUMADO',
            $excluido => 'EXCLUIDO SIN COSTO',
            ($detalle['tipoValorReferencia'] ?? '') === 'TARIFA_TRATAMIENTO_PENDIENTE' => 'ALTERNATIVA NO ADITIVA',
            $sinTarifa => 'COSTO NO CALCULABLE',
            default => 'NO SUMA POR REGLA',
        };
        $mes = (string) ($detalle['mesAtencion'] ?? '');
        $grupo = $this->grupoDeLinea($detalle);
        $esMedicamento = ($detalle['tipoServicio'] ?? '') === 'medicamentos';
        $respaldado = $condicionado ? 0 : $costo;
        $incluidoCondicionado = $condicionado ? $costo : 0;

        $this->meses[$mes] ??= ['respaldado' => 0, 'incluido' => 0, 'adicional' => 0, 'externo' => 0, 'lineasSumadas' => 0, 'sinTarifa' => 0];
        $this->meses[$mes]['respaldado'] += $respaldado;
        $this->meses[$mes]['incluido'] += $incluidoCondicionado;
        $this->meses[$mes]['adicional'] += $adicional;
        $this->meses[$mes]['externo'] += $externo;
        $this->meses[$mes]['lineasSumadas'] += $costo;
        $this->meses[$mes]['sinTarifa'] += $sinTarifa ? 1 : 0;

        $sufijo = $esMedicamento ? 'Medicamento' : '';
        $this->grupos[$mes][$grupo] ??= [
            'respaldado' => 0, 'condicionado' => 0, 'externo' => 0,
            'respaldadoMedicamento' => 0, 'condicionadoMedicamento' => 0, 'externoMedicamento' => 0,
            'lineas' => 0, 'sinTarifa' => 0,
        ];
        $this->grupos[$mes][$grupo]['respaldado'.$sufijo] += $respaldado;
        $this->grupos[$mes][$grupo]['condicionado'.$sufijo] += $incluidoCondicionado + $adicional;
        $this->grupos[$mes][$grupo]['externo'.$sufijo] += $externo;
        $this->grupos[$mes][$grupo]['lineas']++;
        $this->grupos[$mes][$grupo]['sinTarifa'] += $sinTarifa ? 1 : 0;

        if (! $esMedicamento && ! in_array($motivoNoSuma, self::NO_ES_ACTIVIDAD, true)) {
            $codigo = (string) ($detalle['codigo'] ?? '');
            $this->actividad[$mes][$codigo] = ($this->actividad[$mes][$codigo] ?? 0.0) + (float) ($detalle['cantidadAplicada'] ?? 0);
            $this->actividad[$mes]['grupo:'.$grupo] = ($this->actividad[$mes]['grupo:'.$grupo] ?? 0.0) + (float) ($detalle['cantidadAplicada'] ?? 0);
        }

        $motivo = (string) (($detalle['motivoCondicionado'] ?? '') ?: $motivoNoSuma
            ?: ($condicionado && $incluido ? 'FUENTE_AUXILIAR_O_REGLA_HOSPITALARIA_PENDIENTE' : ''));

        if ($esMedicamento) {
            $this->registrarMedicamento($detalle, $mes, $respaldado, $incluidoCondicionado + $adicional, $externo, $incluido ? '' : $motivo);
        }

        if ($sinTarifa) {
            $this->registrarSinTarifa($detalle, $mes, $grupo, $adicional, $externo);
        }

        return [
            'estado' => $estado,
            'respaldado' => $respaldado / 100.0,
            'condicionadoIncluido' => $incluidoCondicionado / 100.0,
            'referencialAdicional' => $adicional / 100.0,
            'escenarioReferencial' => ($costo + $adicional) / 100.0,
            'condicionadoExterno' => $externo / 100.0,
            'motivo' => $motivo ?: ($incluido ? 'FUENTE_Y_REGLA_DOCUMENTADAS_PARA_ANALISIS' : ''),
            'grupoNota' => $grupo,
        ];
    }

    /**
     * Totales mensuales. Los paquetes hospitalarios son la diferencia entre el
     * costo total del mes y las líneas que suman: se agregan una sola vez.
     *
     * @param  list<array<int, mixed>>  $resumenMensual  Filas de SEGUIMIENTO MENSUAL.
     * @return array<string, array<string, float|int>>
     */
    public function totalesMensuales(array $resumenMensual): array
    {
        $totales = [];

        foreach ($resumenMensual as $resumen) {
            $mes = (string) $resumen[0];
            $datos = $this->meses[$mes === 'SIN FECHA' ? '' : $mes]
                ?? ['respaldado' => 0, 'incluido' => 0, 'adicional' => 0, 'externo' => 0, 'lineasSumadas' => 0, 'sinTarifa' => 0];
            $total = self::centavos($resumen[3]);
            $paquetes = $total - $datos['lineasSumadas'];
            $respaldado = $datos['respaldado'] + $paquetes;

            $totales[$mes] = [
                'paquetes' => $paquetes / 100.0,
                'respaldado' => $respaldado / 100.0,
                'condicionadoActivo' => $datos['incluido'] / 100.0,
                'dispensacionEscenario' => $datos['adicional'] / 100.0,
                'condicionadoIncluido' => ($datos['incluido'] + $datos['adicional']) / 100.0,
                'activo' => $total / 100.0,
                'escenario' => ($total + $datos['adicional']) / 100.0,
                'externo' => $datos['externo'] / 100.0,
                'sinTarifa' => $datos['sinTarifa'],
            ];
        }

        return $totales;
    }

    /**
     * Filas de COSTOS Y BRECHAS. La explicación proviene de ExplicacionBrecha.
     *
     * @param  list<array<int, mixed>>  $resumenMensual
     * @param  array<string, array{explicada: float, noCuantificada: float, sinExplicar: float}>  $explicacionPorMes
     * @return list<array<int, mixed>>
     */
    public function filas(array $resumenMensual, string $ruta, array $explicacionPorMes = []): array
    {
        $referencia = $this->referencia($ruta);
        $filas = [];
        $acumulado = array_fill_keys(['referencia', 'respaldado', 'condicionadoIncluido', 'escenario', 'externo', 'activo', 'dispensacionEscenario', 'sinTarifa', 'explicada', 'noCuantificada', 'sinExplicar'], 0.0);

        foreach ($this->totalesMensuales($resumenMensual) as $mes => $total) {
            $explicacion = $explicacionPorMes[$mes] ?? null;
            $filas[] = $this->fila($ruta, (string) $mes, $referencia, $total, $explicacion);

            foreach (['respaldado', 'condicionadoIncluido', 'escenario', 'externo', 'activo', 'dispensacionEscenario', 'sinTarifa'] as $campo) {
                $acumulado[$campo] += $total[$campo];
            }

            $acumulado['referencia'] += $referencia;

            foreach (['explicada', 'noCuantificada', 'sinExplicar'] as $campo) {
                $acumulado[$campo] += $explicacion[$campo] ?? 0.0;
            }
        }

        if (count($filas) > 1) {
            $filas[] = $this->fila($ruta, 'TOTAL PERIODO', $acumulado['referencia'], $acumulado, $explicacionPorMes === [] ? null : $acumulado);
        }

        return $filas;
    }

    /**
     * @return list<array<int, mixed>>
     */
    public function filasMedicamentos(): array
    {
        $filas = array_map(static fn (array $medicamento): array => [
            $medicamento['nombre'],
            $medicamento['cum'],
            $medicamento['ium'],
            $medicamento['presentacion'],
            round($medicamento['cantidad'], 4),
            $medicamento['tarifa'],
            $medicamento['fuente'],
            $medicamento['respaldado'] / 100.0,
            $medicamento['condicionado'] / 100.0,
            $medicamento['externo'] / 100.0,
            ($medicamento['respaldado'] + $medicamento['condicionado']) / 100.0,
            $medicamento['lineas'],
            implode(', ', array_keys($medicamento['meses'])),
            implode(' | ', array_keys($medicamento['motivos'])),
        ], array_values($this->medicamentos));

        usort($filas, static fn (array $a, array $b): int => [$b[10] + $b[9], $a[0]] <=> [$a[10] + $a[9], $b[0]]);

        return $filas;
    }

    /**
     * @return list<array<int, mixed>>
     */
    public function filasSinTarifa(): array
    {
        $filas = array_map(static fn (array $servicio): array => [
            $servicio['tipo'],
            $servicio['codigo'],
            $servicio['descripcion'],
            $servicio['grupo'],
            $servicio['estado'],
            implode(' | ', array_keys($servicio['motivos'])),
            $servicio['lineas'],
            round($servicio['cantidad'], 4),
            implode(', ', array_keys($servicio['meses'])),
            $servicio['escenario'] / 100.0,
            $servicio['externo'] / 100.0,
            $servicio['enNota'] ? 'SI' : 'NO',
        ], array_values($this->sinTarifa));

        usort($filas, static fn (array $a, array $b): int => [$b[6], $a[1]] <=> [$a[6], $b[1]]);

        return $filas;
    }

    /**
     * @return array<string, array<string, array<string, int>>>
     */
    public function grupos(): array
    {
        return $this->grupos;
    }

    public function actividad(string $mes, string $clave): float
    {
        return $this->actividad[$mes][$clave] ?? 0.0;
    }

    public function grupoDeCodigo(string $codigo): ?string
    {
        return $this->grupoPorCodigo[$codigo] ?? null;
    }

    public function referencia(string $ruta): float
    {
        foreach ($this->notaTecnica as $concepto) {
            if (($concepto['codigo'] ?? '') === 'RESUMEN:VALOR_TOTAL_MES' && (float) ($concepto['tarifaReferencia'] ?? 0) > 0) {
                return round((float) $concepto['tarifaReferencia']);
            }
        }

        return self::REFERENCIAS[$ruta] ?? 0.0;
    }

    /**
     * Grupo del resumen de la nota técnica (URGENCIAS, CONSULTA_EXTERNA...).
     *
     * @param  array<string, mixed>  $concepto
     */
    public static function grupoDeConcepto(array $concepto): ?string
    {
        return match ($concepto['categoria'] ?? '') {
            'SERVICIO_TECNICO' => self::normalizarGrupo((string) ($concepto['metadatos']['servicio'] ?? '')) ?: null,
            'ONCOLOGIA_QUIMIOTERAPIA' => 'QUIMIOTERAPIA',
            'ONCOLOGIA_RADIOTERAPIA' => 'RADIOTERAPIA',
            'TRANSPORTE_ALBERGUE' => 'TRANSPORTE_ALBERGUE',
            default => null,
        };
    }

    public static function normalizarGrupo(string $texto): string
    {
        $ascii = strtr(mb_strtoupper(trim($texto)), ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N']);

        return trim((string) preg_replace('/[^A-Z0-9]+/', '_', $ascii), '_');
    }

    public static function centavos(mixed $valor): int
    {
        return (int) round((float) $valor * 100);
    }

    /**
     * @param  array<string, mixed>  $detalle
     */
    private function grupoDeLinea(array $detalle): string
    {
        $tipo = (string) ($detalle['tipoServicio'] ?? '');
        $categoria = (string) ($detalle['categoriaNotaTecnica'] ?? '');
        $nombre = mb_strtoupper((string) (($detalle['nombreServicio'] ?? '') ?: ($detalle['descripcionOficial'] ?? '')));

        return match (true) {
            $tipo === 'medicamentos' => ($detalle['esquemaOncologico'] ?? '') !== '' ? 'QUIMIOTERAPIA' : 'MEDICAMENTOS',
            isset($this->grupoPorCodigo[(string) ($detalle['codigo'] ?? '')]) => $this->grupoPorCodigo[(string) $detalle['codigo']],
            $categoria === 'ONCOLOGIA_QUIMIOTERAPIA' => 'QUIMIOTERAPIA',
            $categoria === 'ONCOLOGIA_RADIOTERAPIA' => 'RADIOTERAPIA',
            $categoria === 'TRANSPORTE_ALBERGUE', preg_match('/TRANSPORT|ALBERGUE/', $nombre) === 1 => 'TRANSPORTE_ALBERGUE',
            ($detalle['servicioNotaTecnica'] ?? '') !== '' => self::normalizarGrupo((string) $detalle['servicioNotaTecnica']),
            $tipo === 'hospitalizacion' => 'HOSPITALIZACION',
            $tipo === 'urgencias' => 'URGENCIAS',
            default => 'FUERA_DE_NOTA',
        };
    }

    /**
     * @param  array<string, mixed>  $detalle
     */
    private function registrarMedicamento(array $detalle, string $mes, int $respaldado, int $condicionado, int $externo, string $motivo): void
    {
        $codigo = (string) ($detalle['codigo'] ?? '');
        $metodo = (string) ($detalle['metodoResolucion'] ?? '');
        $tarifa = (float) ($detalle['tarifaReferencia'] ?? 0) > 0
            ? (float) $detalle['tarifaReferencia']
            : ((float) ($detalle['tarifaEscenarioReferencial'] ?? 0) ?: (float) ($detalle['tarifaCondicionada'] ?? 0));
        $fuente = (float) ($detalle['tarifaReferencia'] ?? 0) > 0
            ? (string) ($detalle['fuenteTarifa'] ?? '')
            : (($detalle['fuenteEscenarioReferencial'] ?? '') !== ''
                ? $detalle['fuenteEscenarioReferencial'].' (ESCENARIO REFERENCIAL)'
                : ($tarifa > 0 ? 'CONDICIONADA: '.($detalle['motivoCondicionado'] ?? '') : 'SIN TARIFA'));
        $medicamento = [
            'nombre' => (string) (($detalle['principioActivo'] ?? '') ?: ($detalle['nombreServicio'] ?? '') ?: ($detalle['descripcionOficial'] ?? '')),
            'cum' => (string) (($detalle['cumResuelto'] ?? '') ?: ($detalle['cum'] ?? '')),
            'ium' => str_contains($metodo, 'IUM') ? $codigo : '',
            'presentacion' => (string) ($detalle['presentacionCum'] ?? '') ?: trim(implode(' ', array_filter([
                (string) ($detalle['concentracionMedicamento'] ?? ''),
                (string) ($detalle['unidadMedida'] ?? ''),
                (string) ($detalle['formaFarmaceutica'] ?? ''),
            ]))),
            'tarifa' => $tarifa,
            'fuente' => $fuente,
        ];
        $clave = implode('|', $medicamento);
        $this->medicamentos[$clave] ??= [...$medicamento, 'cantidad' => 0.0, 'respaldado' => 0, 'condicionado' => 0, 'externo' => 0, 'lineas' => 0, 'meses' => [], 'motivos' => []];
        $this->medicamentos[$clave]['cantidad'] += is_numeric($detalle['cantidad'] ?? null) ? (float) $detalle['cantidad'] : 0.0;
        $this->medicamentos[$clave]['respaldado'] += $respaldado;
        $this->medicamentos[$clave]['condicionado'] += $condicionado;
        $this->medicamentos[$clave]['externo'] += $externo;
        $this->medicamentos[$clave]['lineas']++;
        $this->medicamentos[$clave]['meses'][$mes] = true;

        if ($motivo !== '') {
            $this->medicamentos[$clave]['motivos'][$motivo] = true;
        }
    }

    /**
     * @param  array<string, mixed>  $detalle
     */
    private function registrarSinTarifa(array $detalle, string $mes, string $grupo, int $adicional, int $externo): void
    {
        $tipo = (string) ($detalle['tipoServicio'] ?? '');
        $codigo = (string) ($detalle['codigo'] ?? '');
        $estado = (string) ($detalle['estadoTarifario'] ?? '');
        $clave = $tipo.'|'.$codigo.'|'.$estado;
        $this->sinTarifa[$clave] ??= [
            'tipo' => $tipo,
            'codigo' => $codigo,
            'descripcion' => (string) (($detalle['descripcionOficial'] ?? '') ?: ($detalle['nombreServicio'] ?? '') ?: ($detalle['nombreNotaTecnica'] ?? '')),
            'grupo' => $grupo,
            'estado' => $estado,
            'motivos' => [],
            'lineas' => 0,
            'cantidad' => 0.0,
            'meses' => [],
            'escenario' => 0,
            'externo' => 0,
            'enNota' => (bool) ($detalle['enNotaTecnica'] ?? false),
        ];
        $motivo = (string) (($detalle['tipoValorReferencia'] ?? '') === 'TARIFA_TRATAMIENTO_PENDIENTE'
            ? 'UNIDAD_POR_TRATAMIENTO_PENDIENTE'
            : (($detalle['motivoCondicionado'] ?? '') ?: ($detalle['estadoCruce'] ?? '')));
        $this->sinTarifa[$clave]['motivos'][$motivo] = true;
        $this->sinTarifa[$clave]['lineas']++;
        $this->sinTarifa[$clave]['cantidad'] += is_numeric($detalle['cantidad'] ?? null) ? (float) $detalle['cantidad'] : 0.0;
        $this->sinTarifa[$clave]['meses'][$mes] = true;
        $this->sinTarifa[$clave]['escenario'] += $adicional;
        $this->sinTarifa[$clave]['externo'] += $externo;
    }

    /**
     * @param  array<string, float|int>  $total
     * @param  array<string, float>|null  $explicacion
     * @return list<mixed>
     */
    private function fila(string $ruta, string $mes, float $referencia, array $total, ?array $explicacion): array
    {
        $brechaRespaldada = round($referencia - $total['respaldado'], 2);

        return [
            $ruta,
            $mes,
            $referencia,
            round($total['respaldado'], 2),
            round($total['condicionadoIncluido'], 2),
            round($total['escenario'], 2),
            round($total['externo'], 2),
            $brechaRespaldada,
            round($referencia - $total['escenario'], 2),
            round($total['activo'], 2),
            round($total['dispensacionEscenario'], 2),
            $referencia > 0 ? round($total['respaldado'] / $referencia, 6) : 0.0,
            $referencia > 0 ? round($total['escenario'] / $referencia, 6) : 0.0,
            (int) $total['sinTarifa'],
            $explicacion === null ? 'NO CALCULADA' : round($explicacion['explicada'], 2),
            $explicacion === null ? 'NO CALCULADA' : round($explicacion['noCuantificada'], 2),
            $explicacion === null ? 'NO CALCULADA' : round($explicacion['sinExplicar'], 2),
            $explicacion === null || $brechaRespaldada <= 0 ? '' : round($explicacion['explicada'] / $brechaRespaldada, 6),
            'NO; ANALISIS DE DESARROLLO',
        ];
    }
}
