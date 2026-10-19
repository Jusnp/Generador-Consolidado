<?php

namespace App\Programas\LaMaria;

use InvalidArgumentException;

/**
 * Dos vistas del mismo costo: tipo RIPS de origen y categoría de la nota.
 * Los paquetes se atribuyen a AH una sola vez; los importes históricos quedan fuera.
 */
final class ConciliacionRipsNota
{
    public function __construct(private readonly string $claveSeudonimo)
    {
        if ($claveSeudonimo === '') {
            throw new InvalidArgumentException('Se requiere una clave para seudonimizar los casos exportados.');
        }
    }

    private const TIPOS = [
        'consultas' => 'AC', 'procedimientos' => 'AP', 'otrosServicios' => 'AT',
        'urgencias' => 'AU', 'medicamentos' => 'AM', 'hospitalizacion' => 'AH',
        'recienNacidos' => 'AN',
    ];

    /** @var array<string, array<string, array<string, int|float>>> */
    private array $porTipo = [];

    /** @var array<string, array<string, array<string, int|float>>> */
    private array $porCategoria = [];

    /** @var array<string, array<string, mixed>> */
    private array $porCodigo = [];

    /** @var list<array<int, mixed>> */
    private array $atAu = [];

    /** @var list<array<string, int|string>> */
    private array $evidenciaUrgencias = [];

    /**
     * @param  array<string, mixed>  $detalle
     * @param  array<string, mixed>  $economico
     */
    public function registrar(array $detalle, array $economico): void
    {
        $mes = (string) ($detalle['mesAtencion'] ?? '');
        $tipoOriginal = (string) ($detalle['tipoServicio'] ?? '');
        $tipo = self::TIPOS[$tipoOriginal] ?? strtoupper($tipoOriginal);
        $codigo = (string) ($detalle['codigo'] ?? '');
        $grupo = $this->categoria($detalle, $economico);
        $estado = (string) ($economico['estado'] ?? '');
        $cantidad = is_numeric($detalle['cantidadAplicada'] ?? null)
            ? (float) $detalle['cantidadAplicada']
            : (in_array($tipo, ['AC', 'AP', 'AU', 'AH'], true) ? 1.0 : 0.0);
        $respaldado = ResumenEconomico::centavos($economico['respaldado'] ?? 0);
        $condicionado = ResumenEconomico::centavos(($economico['condicionadoIncluido'] ?? 0) + ($economico['referencialAdicional'] ?? 0));
        $historico = ResumenEconomico::centavos($economico['condicionadoExterno'] ?? 0);
        $incluidoPaquete = ($detalle['decisionPaquete'] ?? '') === 'INCLUIDO_EN_PAQUETE' ? 1 : 0;
        $sinTarifa = $estado === 'COSTO NO CALCULABLE' ? 1 : 0;

        foreach ([&$this->porTipo[$mes][$tipo], &$this->porCategoria[$mes][$grupo]] as &$acumulado) {
            $acumulado ??= self::vacio();
            $acumulado['registros']++;
            $acumulado['unidades'] += $cantidad;
            $acumulado['respaldado'] += $respaldado;
            $acumulado['condicionado'] += $condicionado;
            $acumulado['historico'] += $historico;
            $acumulado['incluidos'] += $incluidoPaquete;
            $acumulado['sinTarifa'] += $sinTarifa;
        }
        unset($acumulado);

        $clave = implode('|', [$mes, $tipo, $codigo, $grupo, $estado, (string) ($detalle['tarifaReferencia'] ?? ''), (string) ($detalle['fuenteTarifa'] ?? '')]);
        $this->porCodigo[$clave] ??= [
            'mes' => $mes, 'tipo' => $tipo, 'codigo' => $codigo, 'grupo' => $grupo,
            'descripcion' => (string) (($detalle['descripcionOficial'] ?? '') ?: ($detalle['nombreServicio'] ?? '')),
            'tarifa' => (float) ($detalle['tarifaReferencia'] ?? 0),
            'fuente' => (string) ($detalle['fuenteTarifa'] ?? ''), 'estado' => $estado,
            'registros' => 0, 'unidades' => 0.0, 'respaldado' => 0,
            'condicionado' => 0, 'historico' => 0, 'incluidos' => 0,
        ];
        $codigoAcumulado = &$this->porCodigo[$clave];
        $codigoAcumulado['registros']++;
        $codigoAcumulado['unidades'] += $cantidad;
        $codigoAcumulado['respaldado'] += $respaldado;
        $codigoAcumulado['condicionado'] += $condicionado;
        $codigoAcumulado['historico'] += $historico;
        $codigoAcumulado['incluidos'] += $incluidoPaquete;
        unset($codigoAcumulado);

        if (in_array($tipo, ['AT', 'AU'], true) || ($tipo === 'AC' && $grupo === 'URGENCIAS')) {
            $this->atAu[] = [
                $mes, (string) ($detalle['factura'] ?? ''), (string) ($detalle['casoId'] ?? ''),
                (string) ($detalle['fechaAtencion'] ?? ''), $tipo, $codigo, $grupo,
                (string) (($detalle['descripcionOficial'] ?? '') ?: ($detalle['nombreServicio'] ?? '')),
                $cantidad, (float) ($detalle['tarifaReferencia'] ?? 0), $respaldado / 100,
                $condicionado / 100, $historico / 100, $estado,
                (string) ($economico['motivo'] ?? ''), (string) ($detalle['decisionPaquete'] ?? ''),
                (string) ($detalle['episodioInicio'] ?? ''), (string) ($detalle['episodioEgreso'] ?? ''),
                (string) ($detalle['fuenteTarifa'] ?? ''), (string) ($detalle['archivoOrigen'] ?? ''),
                (string) ($detalle['consecutivoRips'] ?? ''),
            ];
        }

        $this->evidenciaUrgencias[] = [
            'mes' => $mes, 'factura' => (string) ($detalle['factura'] ?? ''),
            'caso' => (string) ($detalle['casoId'] ?? ''),
            'fecha' => (string) ($detalle['fechaAtencion'] ?? ''),
            'egreso' => (string) ($detalle['servicioJson']['fechaEgreso'] ?? ''),
            'tipo' => $tipo, 'codigo' => $codigo, 'respaldado' => $respaldado,
            'condicionado' => $condicionado, 'incluido' => $incluidoPaquete,
            'sinTarifa' => $sinTarifa,
        ];
    }

    /** @param list<array<int, mixed>> $filasHospitalizacion */
    public function atribuirPaquetes(array $filasHospitalizacion): void
    {
        foreach ($filasHospitalizacion as $fila) {
            $mes = (string) ($fila[0] ?? '');

            if ($mes === '' || $mes === 'TOTAL') {
                continue;
            }

            $cantidad = (int) ($fila[1] ?? 0);
            $importe = ResumenEconomico::centavos($fila[2] ?? 0);

            foreach ([&$this->porTipo[$mes]['AH'], &$this->porCategoria[$mes]['HOSPITALIZACION']] as &$acumulado) {
                $acumulado ??= self::vacio();
                $acumulado['paquetes'] += $cantidad;
                $acumulado['respaldado'] += $importe;
            }
            unset($acumulado);

            $this->porCodigo[$mes.'|AH|PAQUETE_HOSPITALARIO_25D'] = [
                'mes' => $mes, 'tipo' => 'AH', 'codigo' => 'PAQUETE_HOSPITALARIO_25D',
                'grupo' => 'HOSPITALIZACION', 'descripcion' => 'Paquetes netos atribuidos al mes',
                'tarifa' => PoliticaPaqueteHospitalario::VALOR_PAQUETE,
                'fuente' => 'NOTA_TECNICA / LIBRO_PAQUETES', 'estado' => 'COSTO RESPALDADO',
                'registros' => 0, 'unidades' => 0.0, 'respaldado' => $importe,
                'condicionado' => 0, 'historico' => 0, 'incluidos' => 0, 'paquetes' => $cantidad,
            ];
        }
    }

    /** @return list<array<int, mixed>> */
    public function filasTipos(): array
    {
        $filas = [];

        foreach ($this->porTipo as $mes => $tipos) {
            foreach (['AC', 'AP', 'AT', 'AU', 'AM', 'AH', 'AN'] as $tipo) {
                if (! isset($tipos[$tipo])) {
                    continue;
                }

                $a = $tipos[$tipo];
                $filas[] = [$mes, $tipo, $a['registros'], round($a['unidades'], 4), $a['respaldado'] / 100,
                    $a['condicionado'] / 100, $a['historico'] / 100, $a['paquetes'], $a['incluidos'], $a['sinTarifa']];
            }
        }

        return $filas;
    }

    /**
     * @param  list<array<int, mixed>>  $explicacion  Filas de ExplicacionBrecha.
     * @return list<array<int, mixed>>
     */
    public function filasCategorias(array $explicacion): array
    {
        $presupuestos = [];
        $atribuidoAU = [];

        foreach ($this->filasEpisodiosUrgencias() as $episodio) {
            $mesEpisodio = (string) $episodio[0];
            $atribuidoAU[$mesEpisodio] = ($atribuidoAU[$mesEpisodio] ?? 0) + (float) $episodio[7];
        }

        foreach ($explicacion as $fila) {
            if (($fila[2] ?? '') !== 'TOTAL') {
                $presupuestos[(string) $fila[1]][(string) $fila[2]] = (float) ($fila[3] ?? 0);
            }
        }

        $filas = [];

        foreach (array_unique([...array_keys($presupuestos), ...array_keys($this->porCategoria)]) as $mes) {
            $categorias = array_unique([...array_keys($presupuestos[$mes] ?? []), ...array_keys($this->porCategoria[$mes] ?? [])]);

            foreach ($categorias as $categoria) {
                $a = $this->porCategoria[$mes][$categoria] ?? self::vacio();
                $presupuesto = $presupuestos[$mes][$categoria] ?? 0.0;
                $principal = $a['respaldado'] / 100;
                $filas[] = [$mes, $categoria, $a['registros'], round($a['unidades'], 4), $a['paquetes'],
                    $presupuesto, $principal, $a['condicionado'] / 100, $a['historico'] / 100,
                    round($presupuesto - $principal, 2), $a['incluidos'], $a['sinTarifa'],
                    $categoria === 'URGENCIAS' ? round($atribuidoAU[$mes] ?? 0, 2) : 0];
            }
        }

        return $filas;
    }

    /** @return list<array<int, mixed>> */
    public function filasCodigos(): array
    {
        $filas = [];

        foreach ($this->porCodigo as $a) {
            $filas[] = [$a['mes'], $a['tipo'], $a['codigo'], $a['grupo'], $a['descripcion'],
                $a['registros'], round($a['unidades'], 4), $a['paquetes'] ?? 0, $a['tarifa'],
                $a['respaldado'] / 100, $a['condicionado'] / 100, $a['historico'] / 100,
                $a['incluidos'], $a['fuente'], $a['estado']];
        }

        return $filas;
    }

    /** @return list<array<int, mixed>> */
    public function filasAtAu(): array
    {
        return array_map(function (array $fila): array {
            $fila[2] = $this->seudonimo((string) $fila[2]);

            return $fila;
        }, $this->atAu);
    }

    /**
     * Atribución informativa de costos ya reconocidos al episodio AU.
     * No cambia las categorías económicas ni suma un cobro nuevo.
     *
     * @return list<array<int, mixed>>
     */
    public function filasEpisodiosUrgencias(): array
    {
        return array_map(fn (array $enlace): array => $enlace['fila'], $this->enlacesUrgencias());
    }

    /**
     * Costo ya reconocido (A) de los componentes enlazados a episodios AU, por
     * mes y tipo RIPS del componente. Es el desglose de la columna «COSTO YA
     * RECONOCIDO NO ADITIVO»: no se suma a ningún total.
     *
     * @return array<string, array<string, float>> mes => tipo => costo
     */
    public function costoUrgenciasPorTipo(): array
    {
        $costos = [];

        foreach ($this->enlacesUrgencias() as $enlace) {
            foreach ($enlace['respaldadoPorTipo'] as $tipo => $centavos) {
                $costos[$enlace['fila'][0]][$tipo] = ($costos[$enlace['fila'][0]][$tipo] ?? 0) + $centavos;
            }
        }

        return array_map(static fn (array $tipos): array => array_map(static fn (int $centavos): float => $centavos / 100, $tipos), $costos);
    }

    /**
     * @return list<array{fila: array<int, mixed>, respaldadoPorTipo: array<string, int>}>
     */
    private function enlacesUrgencias(): array
    {
        $episodios = array_values(array_filter($this->evidenciaUrgencias, static fn (array $fila): bool => $fila['tipo'] === 'AU'));
        $enlaces = [];

        foreach ($episodios as $episodio) {
            $inicio = $episodio['fecha'];
            $fin = $episodio['egreso'];
            $tipos = [];
            $respaldadoPorTipo = [];
            $respaldado = $condicionado = $incluidos = $sinTarifa = $ambiguos = 0;

            if ($inicio !== '' && $fin !== '' && $fin >= $inicio) {
                foreach ($this->evidenciaUrgencias as $servicio) {
                    if ($servicio['tipo'] === 'AU' || $servicio['caso'] !== $episodio['caso']
                        || $servicio['factura'] !== $episodio['factura'] || $servicio['fecha'] < $inicio
                        || $servicio['fecha'] > $fin) {
                        continue;
                    }

                    $coincidencias = 0;

                    foreach ($episodios as $otro) {
                        if ($otro['caso'] === $servicio['caso'] && $otro['factura'] === $servicio['factura']
                            && $otro['fecha'] <= $servicio['fecha'] && $otro['egreso'] >= $servicio['fecha']) {
                            $coincidencias++;
                        }
                    }

                    if ($coincidencias !== 1) {
                        $ambiguos++;

                        continue;
                    }

                    $tipos[$servicio['tipo']] = ($tipos[$servicio['tipo']] ?? 0) + 1;
                    $respaldadoPorTipo[$servicio['tipo']] = ($respaldadoPorTipo[$servicio['tipo']] ?? 0) + $servicio['respaldado'];
                    $respaldado += $servicio['respaldado'];
                    $condicionado += $servicio['condicionado'];
                    $incluidos += $servicio['incluido'];
                    $sinTarifa += $servicio['sinTarifa'];
                }
            }

            $enlaces[] = ['respaldadoPorTipo' => $respaldadoPorTipo, 'fila' => [$episodio['mes'], $episodio['factura'], $this->seudonimo($episodio['caso']), $inicio, $fin,
                array_sum($tipos), implode(' | ', array_map(
                    static fn (string $tipo, int $cantidad): string => $tipo.':'.$cantidad,
                    array_keys($tipos), array_values($tipos)
                )), $respaldado / 100, $condicionado / 100, $incluidos, $sinTarifa, $ambiguos,
                $inicio === '' || $fin === '' || $fin < $inicio ? 'FECHAS_INSUFICIENTES'
                    : ($ambiguos > 0 ? 'ENLACE_AMBIGUO' : 'ATRIBUCION_INFORMATIVA_NO_ADITIVA')]];
        }

        return $enlaces;
    }

    private function seudonimo(string $caso): string
    {
        return 'LM-'.substr(hash_hmac('sha256', $caso, $this->claveSeudonimo), 0, 20);
    }

    /**
     * @param  list<array<int, mixed>>  $costos  Filas de COSTOS Y BRECHAS.
     * @return list<array<int, mixed>>
     */
    public function filasControles(array $costos): array
    {
        $filas = [];

        foreach ($costos as $costo) {
            $mes = (string) ($costo[1] ?? '');

            if ($mes === 'TOTAL PERIODO') {
                continue;
            }

            $porTipo = array_sum(array_column($this->porTipo[$mes] ?? [], 'respaldado')) / 100;
            $porCategoria = array_sum(array_column($this->porCategoria[$mes] ?? [], 'respaldado')) / 100;
            $principal = (float) ($costo[3] ?? 0);
            $registrosTipo = array_sum(array_column($this->porTipo[$mes] ?? [], 'registros'));
            $registrosCategoria = array_sum(array_column($this->porCategoria[$mes] ?? [], 'registros'));
            $filas[] = [$mes, $principal, $porTipo, $porCategoria,
                round($principal - $porTipo, 2), round($porTipo - $porCategoria, 2),
                $registrosTipo, $registrosCategoria, $registrosTipo - $registrosCategoria];
        }

        return $filas;
    }

    /** @return array<string, int|float> */
    private static function vacio(): array
    {
        return ['registros' => 0, 'unidades' => 0.0, 'paquetes' => 0, 'respaldado' => 0,
            'condicionado' => 0, 'historico' => 0, 'incluidos' => 0, 'sinTarifa' => 0];
    }

    /**
     * @param  array<string, mixed>  $detalle
     * @param  array<string, mixed>  $economico
     */
    private function categoria(array $detalle, array $economico): string
    {
        $grupo = (string) ($economico['grupoNota'] ?? 'FUERA_DE_NOTA');
        $tipo = (string) ($detalle['tipoServicio'] ?? '');
        $codigo = (string) ($detalle['codigo'] ?? '');

        if ($tipo === 'otrosServicios' && $grupo === 'FUERA_DE_NOTA') {
            return match (true) {
                str_starts_with($codigo, 'DM') => 'DISPOSITIVOS_MEDICOS',
                $codigo === '129M02' => 'HOSPITALIZACION',
                $codigo === 'NET042' => 'SOPORTE_NUTRICIONAL',
                in_array($codigo, ['T34004', '601T01'], true) => 'TRANSPORTE_ALBERGUE',
                default => 'OTROS_INSUMOS',
            };
        }

        return $grupo;
    }
}
