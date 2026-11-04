<?php

declare(strict_types=1);

namespace App\Programas\LaMaria;

use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/** Presentación alternativa: nunca modifica ni suma el central técnico. */
final class ReporteConciliacionAdministrativa
{
    private int $secuenciaSegmento = 0;

    /**
     * La huella se calcula del JSON completo, incluidos campos anidados; una clave ambigua no recibe firma.
     *
     * @param  list<array<string, mixed>>  $rips
     * @param  list<array{path: string, fileName: string}>  $archivos
     * @return list<array<string, mixed>>
     */
    public function incorporarFirmasCompletas(array $rips, array $archivos, HistorialAdministrativo $historial): array
    {
        $originales = [];
        foreach ($archivos as $archivo) {
            $usuarios = Items::fromFile($archivo['path'], ['pointer' => '/usuarios', 'decoder' => new ExtJsonDecoder(true)]);
            foreach ($usuarios as $usuario) {
                if (! is_array($usuario)) {
                    continue;
                }
                $paciente = $historial->clavePaciente((string) ($usuario['numDocumentoIdentificacion'] ?? ''));
                $tipoDocumento = strtoupper(trim((string) ($usuario['tipoDocumentoIdentificacion'] ?? '')));
                $procedimientos = $usuario['servicios']['procedimientos'] ?? [];
                if (! is_array($procedimientos)) {
                    continue;
                }
                foreach ($procedimientos as $servicio) {
                    if (! is_array($servicio)) {
                        continue;
                    }
                    $clave = $this->claveServicioOriginal($archivo['fileName'], $tipoDocumento, $paciente,
                        (string) ($servicio['fechaInicioAtencion'] ?? $servicio['fechaAtencion'] ?? ''),
                        (string) ($servicio['codProcedimiento'] ?? ''), (string) ($servicio['consecutivo'] ?? ''));
                    unset($servicio['consecutivo']);
                    $originales[$clave][] = hash('sha256', json_encode($this->ordenarCampos($servicio), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                }
            }
        }
        $cuentas = [];
        foreach ($rips as $r) {
            if (($r['tipo'] ?? '') === 'AP') {
                $clave = $this->claveServicioOriginal($r['archivo'] ?? '', $r['tipo_documento'] ?? '', $r['paciente'] ?? '',
                    $r['fecha_hora'] ?? '', $r['codigo'] ?? '', $r['consecutivo'] ?? '');
                $cuentas[$clave] = ($cuentas[$clave] ?? 0) + 1;
            }
        }
        foreach ($rips as &$r) {
            if (($r['tipo'] ?? '') !== 'AP') {
                continue;
            }
            $clave = $this->claveServicioOriginal($r['archivo'] ?? '', $r['tipo_documento'] ?? '', $r['paciente'] ?? '',
                $r['fecha_hora'] ?? '', $r['codigo'] ?? '', $r['consecutivo'] ?? '');
            $r['firma_repeticion'] = ($cuentas[$clave] ?? 0) === 1 && count($originales[$clave] ?? []) === 1
                ? hash('sha256', json_encode([$r['factura'] ?? '', $r['cantidad_aplicada_firma'] ?? null, $originales[$clave][0]], JSON_THROW_ON_ERROR)) : null;
        }
        unset($r);

        return $rips;
    }

    private function claveServicioOriginal(string $archivo, string $tipoDocumento, string $paciente, string $fecha, string $codigo, string $consecutivo): string
    {
        return json_encode([$archivo, strtoupper(trim($tipoDocumento)), $paciente, trim($fecha), trim($codigo), trim($consecutivo)], JSON_THROW_ON_ERROR);
    }

    /** @param array<mixed> $campos
     * @return array<mixed>
     */
    private function ordenarCampos(array $campos): array
    {
        if (! array_is_list($campos)) {
            ksort($campos);
        }
        foreach ($campos as &$valor) {
            if (is_array($valor)) {
                $valor = $this->ordenarCampos($valor);
            }
        }
        unset($valor);

        return $campos;
    }

    /** @param list<array<string, mixed>> $rips
     * @param  list<array<int, mixed>>  $coberturas
     * @return list<array<string, mixed>>
     */
    public function incorporarCobertura(array $rips, array $coberturas, HistorialAdministrativo $historial, string $ruta): array
    {
        $porClave = [];
        foreach ($coberturas as $c) {
            $clave = implode('|', [(string) $c[1], $historial->clavePaciente((string) $c[3]), (string) $c[4], (string) $c[5]]);
            $porClave[$clave][] = $c;
        }
        $cuentas = [];
        foreach ($rips as $r) {
            if ($r['ruta'] === $ruta && $r['tipo'] === 'AM') {
                $clave = implode('|', [$r['factura'], $r['paciente'], $r['fecha_hora'], $r['codigo']]);
                $cuentas[$clave] = ($cuentas[$clave] ?? 0) + 1;
            }
        }
        foreach ($rips as &$r) {
            if ($r['ruta'] !== $ruta || $r['tipo'] !== 'AM') {
                continue;
            }
            $clave = implode('|', [$r['factura'], $r['paciente'], $r['fecha_hora'], $r['codigo']]);
            if (($cuentas[$clave] ?? 0) === 1 && count($porClave[$clave] ?? []) === 1) {
                $c = $porClave[$clave][0];
                $r['cobertura_medicamento'] = ['cum' => $c[6], 'clasificacion_motor' => $c[16], 'precio_historico' => $c[17], 'fuente' => $c[19]];
            }
        }
        unset($r);

        return $rips;
    }

    /** @param list<array<string, mixed>> $rips
     * @return array<string, array<string, string>>
     */
    public function tarifasTransporte(array $rips, HistorialAdministrativo $historial): array
    {
        $tarifas = [];
        foreach ($rips as $r) {
            $ruta = $r['ruta'];
            $mes = $r['mes'];
            if ($r['codigo'] !== 'T34004' || isset($tarifas[$ruta][$mes])) {
                continue;
            }
            $precio = $historial->precio($ruta, $mes, 'AT', 'T34004');
            if ($precio !== null && $precio['minimo'] === $precio['maximo']) {
                $tarifas[$ruta][$mes] = (string) $precio['mediana'];
            }
        }

        return $tarifas;
    }

    /** @param array<string, mixed> $detalle
     * @return array<string, mixed>
     */
    public function normalizar(string $ruta, array $detalle, ?HistorialAdministrativo $historial): array
    {
        $tipos = ['consultas' => 'AC', 'procedimientos' => 'AP', 'medicamentos' => 'AM', 'hospitalizacion' => 'AH', 'urgencias' => 'AU', 'otrosServicios' => 'AT'];
        $tipo = $tipos[$detalle['tipoServicio'] ?? ''] ?? strtoupper((string) ($detalle['tipoServicio'] ?? ''));
        $cantidad = is_numeric($detalle['cantidadAplicada'] ?? null) ? $detalle['cantidadAplicada'] : (in_array($tipo, ['AC', 'AP', 'AH', 'AU'], true) ? 1 : ($detalle['cantidad'] ?? null));

        $paciente = $historial?->clavePaciente((string) ($detalle['numDocumentoIdentificacion'] ?? '')) ?? '';
        $tipoDocumento = strtoupper(trim((string) ($detalle['tipoDocumentoIdentificacion'] ?? '')));
        $pacienteHospitalario = $tipoDocumento !== '' && $paciente !== '' ? $tipoDocumento.'|'.$paciente : '';
        $fecha = (string) ($detalle['fechaAtencion'] ?? '');
        $servicio = $detalle['servicioJson'] ?? [];
        $segmento = $tipo === 'AH' ? [
            'clave' => hash('sha256', $ruta.'|'.(string) ($detalle['archivoOrigen'] ?? '').'|'.(string) ($detalle['factura'] ?? '').'|'.(++$this->secuenciaSegmento)), 'paciente' => $pacienteHospitalario,
            'inicio' => $fecha, 'fin' => (string) ($servicio['fechaEgreso'] ?? ''),
            'destino' => (string) ($servicio['condicionDestinoUsuarioEgreso'] ?? ''),
            'autorizacion' => ($servicio['numAutorizacion'] ?? '') === '' ? '' : hash('sha256', (string) $servicio['numAutorizacion']),
            'prestadorServicio' => (string) ($servicio['codPrestador'] ?? ''), 'ruta' => $ruta,
            'excluido' => $paciente === '' || empty($detalle['tipoDocumentoIdentificacion']),
        ] : null;

        return ['ruta' => $ruta, 'mes' => (string) ($detalle['mesAtencion'] ?? ''), 'tipo' => $tipo,
            'codigo' => (string) ($detalle['codigo'] ?? ''), 'paciente' => $paciente, 'paciente_hospitalario' => $pacienteHospitalario,
            'fecha' => substr($fecha, 0, 10), 'fecha_hora' => $fecha, 'factura' => (string) ($detalle['factura'] ?? ''), 'hospital_segmento' => $segmento,
            'archivo' => (string) ($detalle['archivoOrigen'] ?? ''), 'tipo_documento' => $tipoDocumento, 'consecutivo' => (string) ($detalle['consecutivoRips'] ?? ''),
            'cantidad' => $cantidad, 'paquete' => (string) ($detalle['decisionPaquete'] ?? ''),
            'tipo_valor_referencia' => (string) ($detalle['tipoValorReferencia'] ?? ''),
            'firma_repeticion' => null, 'cantidad_aplicada_firma' => $detalle['cantidadAplicada'] ?? null,
            'episodio_posible' => ! empty($detalle['segmentoHospitalario']),
            'episodio_inicio_motor' => (string) ($detalle['episodioInicio'] ?? ''), 'episodio_fin_motor' => (string) ($detalle['episodioEgreso'] ?? ''),
            'detalle_tecnico' => ['cantidad' => $cantidad, 'tarifa' => $detalle['tarifaReferencia'] ?? null, 'fuente' => $detalle['fuenteTarifa'] ?? '']];
    }

    /**
     * Aplica RN-05 solo con firma íntegra y unidad histórica disponible. Las líneas se conservan.
     *
     * @param  list<array<string, mixed>>  $lineas
     * @param  list<array<string, mixed>>  $administrativos
     * @return array<int, array{tope: int, meses: list<string>, filas_mes: int, firmas_distintas: int}>
     */
    private function repeticionesNoAditivas(array $lineas, array $administrativos, HistorialAdministrativo $historial, string $ruta, string $mes): array
    {
        $filasPorServicio = [];
        foreach ($administrativos as $fila) {
            if (($fila['tipo'] ?? '') === 'AP' && ($fila['paciente'] ?? '') !== '' && ($fila['fecha'] ?? '') !== ''
                && (float) ($fila['valor_sin_redondeo'] ?? (($fila['centavos'] ?? 0) / 100)) > 0) {
                $clave = json_encode([$fila['codigo'], $fila['paciente'], $fila['fecha']], JSON_THROW_ON_ERROR);
                $filasPorServicio[$clave] = ($filasPorServicio[$clave] ?? 0) + 1;
            }
        }
        $grupos = [];
        $unidades = [];
        foreach ($lineas as $linea) {
            $codigo = (string) ($linea['codigo'] ?? '');
            $paciente = (string) ($linea['paciente'] ?? '');
            $fecha = (string) ($linea['fecha'] ?? '');
            $firma = (string) ($linea['firma_repeticion'] ?? '');
            if (($linea['tipo'] ?? '') !== 'AP' || $codigo === '' || $paciente === '' || $fecha === '' || $firma === ''
                || ($linea['paquete'] ?? '') === 'INCLUIDO_EN_PAQUETE'
                || ($linea['tipo_valor_referencia'] ?? '') === 'TARIFA_TRATAMIENTO_PENDIENTE') {
                continue;
            }
            if (! array_key_exists($codigo, $unidades)) {
                $unidades[$codigo] = $historial->unidadPorPaciente($ruta, $mes, 'AP', $codigo);
            }
            if ($unidades[$codigo] === null) {
                continue;
            }
            $clave = json_encode([$codigo, $paciente, $fecha], JSON_THROW_ON_ERROR);
            $grupos[$clave] ??= ['tope' => max($unidades[$codigo]['porFecha'], $filasPorServicio[$clave] ?? 0),
                'meses' => $unidades[$codigo]['meses'], 'filas_mes' => $filasPorServicio[$clave] ?? 0, 'firmas' => [], 'repetidas' => []];
            if (isset($grupos[$clave]['firmas'][$firma])) {
                $grupos[$clave]['repetidas'][] = $linea['indice_rips'];
            } else {
                $grupos[$clave]['firmas'][$firma] = true;
            }
        }
        $noAditivas = [];
        foreach ($grupos as $grupo) {
            $permitidas = max(0, $grupo['tope'] - count($grupo['firmas']));
            foreach ($grupo['repetidas'] as $posicion => $indice) {
                if ($posicion >= $permitidas) {
                    $noAditivas[$indice] = ['tope' => $grupo['tope'], 'meses' => $grupo['meses'], 'filas_mes' => $grupo['filas_mes'], 'firmas_distintas' => count($grupo['firmas'])];
                }
            }
        }

        return $noAditivas;
    }

    /** @param list<array<string, mixed>> $administrativos
     * @param  list<array<string, mixed>>  $rips
     * @param  list<array<string, mixed>>  $fuentes
     */
    public function escribir(Writer $writer, array $administrativos, array $rips, array $fuentes, array $segmentosContexto = [], array $tarifasTransporte = [], ?HistorialAdministrativo $historial = null): void
    {
        $tablas = [
            'CONCILIACION ADMIN' => [['RUTA', 'MES', 'TIPO', 'REFERENCIA FILAS', 'RIPS PARCIAL', 'CONDICIONADO INCLUIDO', 'PENDIENTES', 'DIFERENCIA BRUTA', 'ALCANCE', 'NO ADITIVO', 'PENDIENTE CUANTIFICADO']],
            'DETALLE POR CODIGO' => [['RUTA', 'MES', 'TIPO', 'CODIGO', 'FILAS ADMIN', 'REGISTROS RIPS', 'TARIFAS ADMIN OBSERVADAS', 'TARIFAS NOTA', 'ADMINISTRATIVO', 'RIPS PARCIAL', 'DIFERENCIA BRUTA', 'PENDIENTES', 'EFECTO CANTIDAD', 'EFECTO TARIFA', 'NO COMPARABLE', 'CRITERIO', 'NO ADITIVO', 'RIPS ANTES RN05', 'REDUCCION RN05']],
            'QUIMIOTERAPIA' => [['RUTA', 'MES', 'PACIENTE SEUDONIMO', 'FECHA', 'FACTURA', 'CODIGO', 'APLICACION', 'INTEGRAL', 'ESCENARIO', 'CERTEZA', 'FUENTE', 'MEDICAMENTO REFERENCIA']],
            'PENDIENTES ADMIN' => [['RUTA', 'MES', 'TIPO', 'CODIGO', 'INDICE RIPS', 'MOTIVO', 'IMPORTE REFERENCIAL', 'FUENTE', 'EVIDENCIA', 'ESTADO ECONOMICO']],
            'FUENTES Y CONTROL' => [['ARCHIVO', 'HOJA', 'RUTA', 'MESES', 'FILAS', 'SHA256', 'CONTROL']],
        ];
        $grupos = [];
        foreach ($rips as $linea) {
            $grupos[$linea['ruta'].'|'.$linea['mes']][] = $linea;
        }
        $segmentos = $segmentosContexto;
        foreach ($rips as $linea) {
            if (is_array($linea['hospital_segmento'] ?? null)) {
                $segmentos[$linea['ruta']][] = $linea['hospital_segmento'];
            }
        }
        $hospital = [];
        foreach ($segmentos as $ruta => $lista) {
            $episodios = (new ReconstructorEpisodiosHospitalarios)->reconstruir($lista);
            $unidades = (new EscenarioHospitalarioAdministrativo)->calcular($episodios, (int) round(PoliticaPaqueteHospitalario::VALOR_PAQUETE * 100));
            $porId = array_column($episodios, null, 'id');
            foreach ($unidades['unidades'] as $unidad) {
                $periodo = substr($unidad['fecha'], 0, 7);
                $hospital[$ruta][$periodo]['unidades'][] = $unidad;
                $hospital[$ruta][$periodo]['episodios'][$unidad['episodio']] = $porId[$unidad['episodio']];
            }
        }
        $motor = new ConciliadorEscenarioAdministrativo;
        $valorador = new ValoracionUnidadesAdministrativas;
        foreach ($grupos as $clave => $lineas) {
            [$ruta, $mes] = explode('|', $clave, 2);
            $admin = array_values(array_filter($administrativos, static fn (array $a): bool => $a['ruta'] === $ruta && $a['mes'] === $mes));
            if ($admin === []) {
                $tablas['CONCILIACION ADMIN'][] = [$ruta, $mes, 'TODAS', null, null, null, null, null, 'NO DISPONIBLE: INFORME INDIVIDUAL DEL PERIODO'];

                continue;
            }
            $conciliacion = $motor->conciliar($admin, $lineas);
            $escenario = $motor->escenarioProvisional($conciliacion, $lineas);
            $detalle = $escenario['detalle_rips'];
            $repeticiones = $historial === null ? [] : $this->repeticionesNoAditivas($detalle, $admin, $historial, $ruta, $mes);
            $acAp = array_values(array_filter($detalle, static fn (array $r): bool => in_array($r['tipo'], ['AC', 'AP'], true)));
            $calculadas = $valorador->calcularConsultasProcedimientos($acAp)['detalle'];
            $otras = array_values(array_filter($detalle, static fn (array $r): bool => in_array($r['tipo'], ['AM', 'AT', 'AU'], true)));
            $otras = $valorador->calcular($otras, $tarifasTransporte[$ruta][$mes] ?? '0')['detalle'];
            $porIndice = [];
            foreach ($calculadas as $linea) {
                $porIndice[$linea['indice_rips']] = $linea;
            }
            foreach ($otras as $linea) {
                $porIndice[$linea['indice_rips']] = $linea;
            }
            $quimio = array_values(array_filter($calculadas, static fn (array $r): bool => in_array($r['codigo'], ['992504', '992505', '992509', '992511'], true)
                && ($r['importe_calculo_centavos'] ?? null) !== null && ($r['cantidad_calculo'] ?? '') === '1'));
            $integrales = $motor->escenarioQuimioterapia($quimio)['detalle'];
            $porIntegral = [];
            $adminPorFuente = array_column($admin, null, 'fuente');
            foreach ($integrales as $linea) {
                $porIntegral[$linea['indice_rips']] = $linea;
                $tablas['QUIMIOTERAPIA'][] = [$ruta, $mes, $linea['paciente'], $linea['fecha'], $linea['factura'] ?? '', $linea['codigo'],
                    $linea['aplicacion_centavos'] / 100, $linea['integral_centavos'] === null ? null : $linea['integral_centavos'] / 100,
                    $linea['escenario_centavos'] / 100, $linea['certeza_integral'], $linea['fuente'], $adminPorFuente[$linea['fuente']]['medicamento'] ?? ''];
            }
            $componentes = [];
            $ajustesRn05 = [];
            foreach ($detalle as $linea) {
                $indice = $linea['indice_rips'];
                $tipo = $linea['tipo'];
                if (! in_array($tipo, ['AC', 'AP', 'AM', 'AH', 'AU', 'AT'], true)) {
                    continue;
                }
                $calculada = $porIndice[$indice] ?? [];
                $integral = $porIntegral[$indice] ?? null;
                $importe = $integral['escenario_centavos'] ?? ($calculada['importe_calculo_centavos'] ?? $calculada['importe_centavos'] ?? null);
                $radioterapiaPorRegistro = $importe === null && $tipo === 'AP'
                    && in_array($linea['codigo'], ['922444', '922605'], true)
                    && ($linea['estado'] ?? '') === 'REFERENCIA ADMINISTRATIVA EN FECHA'
                    && is_int($linea['centavos'] ?? null) && $linea['centavos'] > 0;
                if ($radioterapiaPorRegistro) {
                    $importe = $linea['centavos'];
                }
                $repetida = isset($repeticiones[$indice]);
                $incluido = $repetida || ($linea['paquete'] ?? '') === 'INCLUIDO_EN_PAQUETE' || str_starts_with($calculada['estado_calculo'] ?? '', 'NO ADITIVO');
                $solapamiento = ! empty($linea['episodio_posible']);
                $fechaHora = (string) ($linea['fecha_hora'] ?? $linea['fecha']);
                $episodiosCoincidentes = [];
                foreach ($hospital[$ruta][$mes]['episodios'] ?? [] as $episodio) {
                    $pacienteHospitalario = $linea['paciente_hospitalario'] ?? $linea['paciente'];
                    if ($fechaHora !== '' && $pacienteHospitalario !== '' && $pacienteHospitalario === ($episodio['paciente'] ?? '')
                        && substr((string) $episodio['inicio'], 0, strlen($fechaHora)) <= $fechaHora
                        && substr((string) $episodio['fin'], 0, strlen($fechaHora)) >= $fechaHora) {
                        $solapamiento = true;
                        $episodiosCoincidentes[] = $episodio;
                    }
                }
                if (in_array($linea['codigo'], ['992504', '992505', '992509', '992511'], true)) {
                    $solapamiento = false;
                }
                $episodioCoincidente = count($episodiosCoincidentes) === 1 ? $episodiosCoincidentes[0] : null;
                $solapamientoResuelto = $importe !== null && $solapamiento && ($linea['paquete'] ?? '') === 'COBRABLE_POR_FUERA'
                    && $episodioCoincidente !== null && ($episodioCoincidente['tipo'] ?? '') === 'HOSPITALIZACION'
                    && ($episodioCoincidente['estado'] ?? '') === 'CERRADO'
                    && ($linea['episodio_inicio_motor'] ?? '') !== '' && ($linea['episodio_fin_motor'] ?? '') !== ''
                    && $linea['episodio_inicio_motor'] === ($episodioCoincidente['inicio'] ?? '')
                    && $linea['episodio_fin_motor'] === ($episodioCoincidente['fin'] ?? '');
                $solapamientoRelevante = $solapamiento && ! $solapamientoResuelto && ! $incluido && $importe !== null;
                $estado = $incluido ? 'NO_ADITIVO' : ($importe === null || $solapamientoRelevante ? 'PENDIENTE' : 'CONDICIONADO');
                if ($repetida && (! $solapamiento || $solapamientoResuelto) && $importe !== null) {
                    $claveCodigo = $tipo.'|'.$linea['codigo'];
                    $ajustesRn05[$claveCodigo] = ($ajustesRn05[$claveCodigo] ?? 0) + $importe;
                }
                $motivo = $repetida ? 'RN-05 REPETICION IDENTICA NO ADITIVA; UNIDAD HISTORICA'.($solapamiento && $importe !== null ? ' | POSIBLE SOLAPAMIENTO AH' : '') : ($solapamientoResuelto ? 'POSIBLE SOLAPAMIENTO AH RESUELTO: COBRABLE POR FUERA' : ($solapamientoRelevante ? 'POSIBLE SOLAPAMIENTO AH' : ($radioterapiaPorRegistro
                    ? 'RN-08 REFERENCIA POR REGISTRO; UNIDAD CONTRACTUAL PENDIENTE'
                    : ($calculada['estado_calculo'] ?? 'SIN REGLA INDIVIDUAL IMPLEMENTADA'))));
                $componentes[] = ['id' => (string) $indice, 'tipo' => $tipo, 'codigo' => $linea['codigo'], 'centavos' => $importe, 'estado' => $estado];
                if ($estado === 'PENDIENTE' || $estado === 'NO_ADITIVO' || $radioterapiaPorRegistro || $solapamientoResuelto) {
                    $evidencia = $repetida ? sprintf('Tope %d por paciente y fecha; ventana %s; %d filas administrativas del mes; %d firmas distintas. Misma factura, cantidad y campos salvo consecutivo.',
                        $repeticiones[$indice]['tope'], implode(', ', $repeticiones[$indice]['meses']), $repeticiones[$indice]['filas_mes'], $repeticiones[$indice]['firmas_distintas']) : '';
                    if ($solapamientoResuelto) {
                        $evidencia .= ' Decision COBRABLE_POR_FUERA del motor y episodio hospitalario cerrado coincidente; escenario condicionado a la acreditacion contractual.';
                    }
                    $tablas['PENDIENTES ADMIN'][] = [$ruta, $mes, $tipo, $linea['codigo'], $indice, $motivo, $importe === null ? null : $importe / 100, $linea['fuente'] ?? '', trim($evidencia), $estado];
                }
            }
            foreach ($hospital[$ruta][$mes]['unidades'] ?? [] as $unidad) {
                $componentes[] = ['id' => 'AH|'.$unidad['episodio'].'|'.$unidad['fecha'].'|'.$unidad['tipo'], 'tipo' => 'AH',
                    'codigo' => 'PAQUETE 25 DIAS', 'centavos' => $unidad['centavos'], 'estado' => 'CONDICIONADO'];
            }
            $referencias = [];
            foreach ($admin as $fila) {
                $referencias[$fila['tipo']] = ($referencias[$fila['tipo']] ?? 0) + $fila['centavos'];
            }
            $resultado = $motor->consolidarComponentes($componentes, $referencias);
            $brutos = array_column($admin, 'valor_sin_redondeo');
            $diferenciaRedondeo = count($brutos) === count($admin)
                ? ((int) round(array_sum($brutos) * 100) - array_sum(array_column($admin, 'centavos'))) / 100 : null;
            $tablas['FUENTES Y CONTROL'][] = ['', '', $ruta, $mes, count($lineas), '',
                'RIPS='.count($lineas).'; COMPONENTES='.count($componentes).'; FILAS ADMIN='.count($admin)
                .'; AJUSTE TOTAL SIN REDONDEAR MENOS SUMA DE FILAS='.($diferenciaRedondeo === null ? 'NO DISPONIBLE' : (string) $diferenciaRedondeo)];
            foreach ($resultado['categorias'] as $tipo => $c) {
                $tablas['CONCILIACION ADMIN'][] = [$ruta, $mes, $tipo, $c['referencia'] === null ? null : $c['referencia'] / 100,
                    $c['calculado_mostrable'] === null ? null : $c['calculado_mostrable'] / 100, $c['condicionado_incluido'] / 100,
                    $c['pendientes'], $c['diferencia_bruta'] === null ? null : $c['diferencia_bruta'] / 100, 'PARCIAL; NO SUMAR AL CENTRAL',
                    $c['no_aditivo'] / 100, $c['pendiente_cuantificado'] / 100];
            }
            $codigos = [];
            foreach ($admin as $fila) {
                $key = $fila['tipo'].'|'.$fila['codigo'];
                $codigos[$key] ??= ['tipo' => $fila['tipo'], 'codigo' => $fila['codigo'], 'admin' => 0, 'filas' => 0, 'rips' => 0, 'precios' => [], 'tarifas' => [], 'unidadesSimples' => true, 'resultado' => null];
                $codigos[$key]['admin'] += $fila['centavos'];
                $codigos[$key]['filas']++;
                $codigos[$key]['precios'][(string) $fila['centavos']] = true;
            }
            foreach ($lineas as $linea) {
                $key = $linea['tipo'].'|'.$linea['codigo'];
                $codigos[$key] ??= ['tipo' => $linea['tipo'], 'codigo' => $linea['codigo'], 'admin' => 0, 'filas' => 0, 'rips' => 0, 'precios' => [], 'tarifas' => [], 'unidadesSimples' => true, 'resultado' => null];
                $codigos[$key]['rips']++;
                $tarifa = $linea['detalle_tecnico']['tarifa'] ?? null;
                $cantidad = $linea['detalle_tecnico']['cantidad'] ?? $linea['cantidad'] ?? null;
                if (! is_numeric($cantidad) || (float) $cantidad !== 1.0) {
                    $codigos[$key]['unidadesSimples'] = false;
                }
                if (is_numeric($tarifa) && (float) $tarifa > 0) {
                    $codigos[$key]['tarifas'][(string) $tarifa] = true;
                }
            }
            foreach ($resultado['por_codigo'] as $c) {
                $key = $c['tipo'].'|'.$c['codigo'];
                $codigos[$key] ??= ['tipo' => $c['tipo'], 'codigo' => $c['codigo'], 'admin' => 0, 'filas' => 0, 'rips' => 0, 'precios' => [], 'tarifas' => [], 'unidadesSimples' => true, 'resultado' => null];
                $codigos[$key]['resultado'] = $c;
            }
            foreach ($codigos as $c) {
                $calculado = $c['resultado']['lineas_valoradas'] ?? 0;
                $valor = $calculado === 0 ? null : $c['resultado']['calculado'];
                $hayReferenciaTipo = array_key_exists($c['tipo'], $referencias);
                $brecha = ! $hayReferenciaTipo || ($c['filas'] === 0 && $valor === null) ? null : $c['admin'] - ($valor ?? 0);
                $pendientes = $c['resultado']['pendientes'] ?? 0;
                $noAditivas = $c['resultado']['lineas_no_aditivas'] ?? 0;
                $esQuimio = in_array($c['codigo'], ['992504', '992505', '992509', '992511'], true);
                $comparable = $brecha !== null && $valor !== null && $c['filas'] > 0 && in_array($c['tipo'], ['AC', 'AP'], true)
                    && count($c['precios']) === 1 && count($c['tarifas']) === 1 && $c['unidadesSimples'] && $pendientes === 0 && $noAditivas === 0 && ! $esQuimio;
                $efectoCantidad = null;
                $efectoTarifa = null;
                if ($comparable) {
                    $precioAdministrativo = (int) array_key_first($c['precios']);
                    $efectoCantidad = ($c['filas'] - $calculado) * $precioAdministrativo;
                    $efectoTarifa = $calculado * $precioAdministrativo - $valor;
                }
                $criterio = match (true) {
                    $brecha === null => 'NO DETERMINABLE',
                    $comparable => 'COMPARABLE: CANTIDAD Y TARIFA',
                    $c['tipo'] === 'AH' => 'PAQUETE Y CRITERIO AH',
                    $esQuimio => 'ESQUEMA INTEGRAL CONDICIONADO',
                    in_array($c['codigo'], ['405406', '405407'], true) => 'CODIGO DISTINTO; SIN HOMOLOGACION',
                    $c['filas'] === 0 => 'SIN FILA ADMINISTRATIVA DEL CODIGO',
                    $valor === null => 'SIN VALOR RIPS COMPARABLE',
                    $noAditivas > 0 => 'INCLUSIONES O REPETICIONES NO ADITIVAS',
                    default => 'PARCIAL: CANTIDAD, TARIFA O CORRESPONDENCIA PENDIENTE',
                };
                $reduccionRn05 = $ajustesRn05[$c['tipo'].'|'.$c['codigo']] ?? 0;
                $tablas['DETALLE POR CODIGO'][] = [$ruta, $mes, $c['tipo'], $c['codigo'], $c['filas'], $c['rips'],
                    implode(' | ', array_map(static fn (int|string $v): string => (string) ((int) $v / 100), array_keys($c['precios']))),
                    implode(' | ', array_keys($c['tarifas'])), $c['filas'] === 0 ? null : $c['admin'] / 100,
                    $valor === null ? null : $valor / 100, $brecha === null ? null : $brecha / 100, $pendientes,
                    $efectoCantidad === null ? null : $efectoCantidad / 100, $efectoTarifa === null ? null : $efectoTarifa / 100,
                    $brecha === null || $comparable ? null : $brecha / 100, $criterio, ($c['resultado']['valor_no_aditivo'] ?? 0) / 100,
                    $valor === null ? null : ($valor + $reduccionRn05) / 100, $reduccionRn05 / 100];
            }
            foreach ($conciliacion['detalle'] as $fila) {
                if (! in_array($fila['nivel_correspondencia'], ['COINCIDENCIA EXACTA', 'CANDIDATA UNICA POR PERIODO'], true)) {
                    $tablas['PENDIENTES ADMIN'][] = [$ruta, $mes, $fila['tipo'], $fila['codigo'], '', $fila['nivel_correspondencia'], $fila['centavos'] / 100, $fila['fuente']];
                }
            }
        }
        foreach ($fuentes as $fuente) {
            $tablas['FUENTES Y CONTROL'][] = [$fuente['archivo'] ?? '', $fuente['hoja'] ?? '', $fuente['ruta'] ?? '', $fuente['meses'] ?? '',
                $fuente['filas'] ?? 0, $fuente['sha256'] ?? '', $fuente['control'] ?? 'REFERENCIA POR FILA REDONDEADA; NO MODIFICA CENTRAL'];
        }
        if ($fuentes === []) {
            $tablas['FUENTES Y CONTROL'][] = ['', '', '', '', null, '', 'NO DISPONIBLE: NO SE CARGÓ INFORME INDIVIDUAL'];
        }
        foreach ($tablas as $nombre => $filas) {
            $hoja = $writer->addNewSheetAndMakeItCurrent();
            $hoja->setName($nombre);
            $vista = new SheetView;
            $vista->setFreezeRow(1);
            $hoja->setSheetView($vista);
            foreach ($filas[0] as $columna => $titulo) {
                $hoja->setColumnWidth(max(18, min(42, mb_strlen($titulo) + 6)), $columna + 1);
            }
            $dinero = new Style;
            $dinero->setFormat('#,##0.00');
            $columnasDinero = match ($nombre) {
                'CONCILIACION ADMIN' => [3, 4, 5, 7, 9, 10],
                'DETALLE POR CODIGO' => [8, 9, 10, 12, 13, 14, 16, 17, 18],
                'QUIMIOTERAPIA' => [6, 7, 8],
                'PENDIENTES ADMIN' => [6],
                default => [],
            };
            $estilos = array_fill_keys($columnasDinero, $dinero);
            foreach ($filas as $indice => $fila) {
                $writer->addRow($indice === 0 ? Row::fromValues($fila, (new Style)->setFontBold()) : Row::fromValuesWithStyles($fila, null, $estilos));
            }
        }
    }
}
