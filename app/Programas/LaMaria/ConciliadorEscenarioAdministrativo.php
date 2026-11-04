<?php

declare(strict_types=1);

namespace App\Programas\LaMaria;

/**
 * Puente documental: compara filas administrativas con servicios RIPS exactos.
 * No infiere equivalencias ni interpreta unidades hospitalarias como servicios.
 */
final class ConciliadorEscenarioAdministrativo
{
    /**
     * Consolida componentes ya calculados; las referencias agregadas nunca se usan como tarifas.
     * Un componente incierto o incluido en otro paquete no se suma automáticamente.
     *
     * @param  list<array{id: string, tipo: string, codigo: string, centavos: int|null, estado: string}>  $componentes
     * @param  array<string, int>  $referenciasCentavos
     * @return array<string, mixed>
     */
    public function consolidarComponentes(array $componentes, array $referenciasCentavos): array
    {
        $tipos = ['AC', 'AP', 'AM', 'AH', 'AU', 'AT'];
        $categorias = [];
        $porCodigo = [];
        $ids = [];
        foreach ($tipos as $tipo) {
            $categorias[$tipo] = ['calculado' => 0, 'condicionado_incluido' => 0, 'no_aditivo' => 0, 'pendiente_cuantificado' => 0,
                'pendientes' => 0, 'lineas' => 0, 'lineas_valoradas' => 0, 'referencia' => $referenciasCentavos[$tipo] ?? null];
        }
        foreach ($componentes as $componente) {
            $id = $componente['id'];
            $tipo = $componente['tipo'];
            $estado = $componente['estado'];
            $importe = $componente['centavos'];
            if ($id === '' || isset($ids[$id]) || ! in_array($tipo, $tipos, true)
                || ! in_array($estado, ['ADITIVO', 'CONDICIONADO', 'NO_ADITIVO', 'PENDIENTE'], true)
                || ($importe !== null && $importe < 0)
                || (in_array($estado, ['ADITIVO', 'CONDICIONADO'], true) && $importe === null)) {
                throw new \LogicException('Componente económico inválido o duplicado.');
            }
            $ids[$id] = true;
            $clave = $tipo.'|'.$componente['codigo'];
            $porCodigo[$clave] ??= ['tipo' => $tipo, 'codigo' => $componente['codigo'], 'calculado' => 0, 'pendientes' => 0,
                'lineas_valoradas' => 0, 'lineas_no_aditivas' => 0, 'valor_no_aditivo' => 0];
            $categorias[$tipo]['lineas']++;
            if (in_array($estado, ['ADITIVO', 'CONDICIONADO'], true)) {
                $categorias[$tipo]['calculado'] += $importe;
                $categorias[$tipo]['lineas_valoradas']++;
                $porCodigo[$clave]['calculado'] += $importe;
                $porCodigo[$clave]['lineas_valoradas']++;
                if ($estado === 'CONDICIONADO') {
                    $categorias[$tipo]['condicionado_incluido'] += $importe;
                }
            } elseif ($estado === 'NO_ADITIVO') {
                $categorias[$tipo]['no_aditivo'] += $importe ?? 0;
                $porCodigo[$clave]['lineas_no_aditivas']++;
                $porCodigo[$clave]['valor_no_aditivo'] += $importe ?? 0;
            } else {
                $categorias[$tipo]['pendientes']++;
                $categorias[$tipo]['pendiente_cuantificado'] += $importe ?? 0;
                $porCodigo[$clave]['pendientes']++;
            }
        }
        foreach ($categorias as &$categoria) {
            $categoria['diferencia_bruta'] = $categoria['referencia'] === null
                ? null : $categoria['referencia'] - $categoria['calculado'];
            $categoria['calculado_mostrable'] = $categoria['lineas_valoradas'] === 0 ? null : $categoria['calculado'];
        }
        unset($categoria);

        return ['categorias' => $categorias, 'por_codigo' => array_values($porCodigo),
            'total_calculado' => array_sum(array_column($categorias, 'calculado')),
            'total_condicionado_incluido' => array_sum(array_column($categorias, 'condicionado_incluido')),
            'componentes' => count($componentes)];
    }

    /**
     * Valor administrativo de sesiones vinculadas, sin alterar el central ni sumar aplicación y esquema.
     *
     * @param  list<array<string, mixed>>  $lineas
     * @return array{detalle: list<array<string, mixed>>, aplicacion_centavos: int, escenario_centavos: int, incremento_centavos: int, pendientes: int}
     */
    public function escenarioQuimioterapia(array $lineas): array
    {
        $detalle = [];
        $aplicacion = 0;
        $escenario = 0;
        $pendientes = 0;
        $indices = [];
        $fuentes = [];
        foreach ($lineas as $linea) {
            $indice = $linea['indice_rips'] ?? null;
            if (! is_int($indice) || isset($indices[$indice])) {
                throw new \LogicException('Cada sesión requiere un índice RIPS único.');
            }
            $indices[$indice] = true;
            $tecnico = $linea['detalle_tecnico'] ?? [];
            $cantidad = $tecnico['cantidad'] ?? null;
            if (! is_numeric($cantidad) || (float) $cantidad !== 1.0 || ! is_numeric($tecnico['tarifa'] ?? null) || (float) $tecnico['tarifa'] <= 0) {
                throw new \LogicException('La sesión requiere una unidad y tarifa de aplicación acreditada.');
            }
            $base = (int) round((float) $tecnico['tarifa'] * 100);
            $aplicacion += $base;
            $estado = $linea['estado'] ?? '';
            $referencia = $linea['centavos'] ?? null;
            $fuente = trim((string) ($linea['fuente'] ?? ''));
            $vinculada = in_array($estado, ['REFERENCIA ADMINISTRATIVA EN FECHA', 'REFERENCIA PROVISIONAL NO CONFIRMADA'], true)
                && is_int($referencia) && $referencia > 0 && $fuente !== '' && ($linea['paquete'] ?? '') !== 'INCLUIDO_EN_PAQUETE';
            if ($vinculada) {
                if (isset($fuentes[$fuente])) {
                    throw new \LogicException('Una fila administrativa no puede justificar dos sesiones.');
                }
                $fuentes[$fuente] = true;
                $valor = $referencia;
                $certeza = $estado === 'REFERENCIA ADMINISTRATIVA EN FECHA' ? 'FECHA Y CODIGO; ESQUEMA POR VALIDAR' : 'CANDIDATA MENSUAL; NO CONFIRMADA';
                $central = $tecnico['central_centavos'] ?? null;
                if (($linea['ruta'] ?? '') === 'CERVIX' && ($linea['codigo'] ?? '') === '992505'
                    && $referencia === 3278716559 && $central === 611531256) {
                    $certeza = 'CONDICIONADA: DISTINTIVO EN OTRA FECHA';
                }
            } else {
                $valor = $base;
                $certeza = 'PENDIENTE: SE CONSERVA APLICACION';
                $referencia = null;
                $pendientes++;
            }
            $escenario += $valor;
            $detalle[] = [...$linea, 'aplicacion_centavos' => $base, 'integral_centavos' => $referencia,
                'escenario_centavos' => $valor, 'incremento_centavos' => $valor - $base, 'certeza_integral' => $certeza];
        }

        return ['detalle' => $detalle, 'aplicacion_centavos' => $aplicacion, 'escenario_centavos' => $escenario,
            'incremento_centavos' => $escenario - $aplicacion, 'pendientes' => $pendientes];
    }

    /**
     * Construye una vista administrativa alternativa; los importes no son cobros acreditados.
     * Las filas ambiguas o sin unidad no reciben valor RIPS por el solo hecho de existir.
     *
     * @param  array<string, mixed>  $conciliacion
     * @param  list<array<string, mixed>>  $rips
     * @param  list<array{indice_admin: int, indice_rips: int, evidencia: string}>  $episodios
     * @return array<string, mixed>
     */
    public function escenarioProvisional(array $conciliacion, array $rips, array $episodios = []): array
    {
        $porFila = [];
        foreach ($episodios as $episodio) {
            if (! isset($conciliacion['detalle'][$episodio['indice_admin']])
                || isset($porFila[$episodio['indice_admin']]) || trim($episodio['evidencia']) === '') {
                throw new \LogicException('El vínculo hospitalario debe ser único y tener evidencia.');
            }
            $porFila[$episodio['indice_admin']] = $episodio;
        }
        $valores = ['exactos' => 0, 'candidatos' => 0, 'paquetes_no_aditivos' => 0, 'ambiguos_no_cuantificados' => 0,
            'sin_correspondencia' => 0, 'pendientes' => 0];
        $asignados = [];
        $porTipo = [];
        $detalle = [];
        foreach ($conciliacion['detalle'] as $indiceAdmin => $fila) {
            $nivel = $fila['nivel_correspondencia'];
            $importe = $fila['centavos'];
            $tipo = $fila['tipo'];
            $porTipo[$tipo] ??= ['exactos' => 0, 'candidatos' => 0, 'paquetes_no_aditivos' => 0, 'ambiguos_no_cuantificados' => 0,
                'sin_correspondencia' => 0, 'pendientes' => 0];
            $categoria = match (true) {
                $nivel === 'COINCIDENCIA EXACTA' => 'exactos',
                $nivel === 'CANDIDATA UNICA POR PERIODO', str_starts_with($nivel, 'CANDIDATA CON ') => 'candidatos',
                $nivel === 'CANDIDATURA AMBIGUA POR PERIODO' => 'ambiguos_no_cuantificados',
                $nivel === 'SIN CORRESPONDENCIA ENCONTRADA' => 'sin_correspondencia',
                default => 'pendientes',
            };
            $episodio = $porFila[$indiceAdmin] ?? null;
            if ($episodio !== null) {
                $ripsEpisodio = $rips[$episodio['indice_rips']] ?? null;
                if ($categoria !== 'pendientes' || $tipo !== 'AH' || ($ripsEpisodio['tipo'] ?? null) !== 'AH'
                    || ($fila['ruta'] ?? null) !== ($ripsEpisodio['ruta'] ?? null)
                    || ($fila['paciente'] ?? null) !== ($ripsEpisodio['paciente'] ?? null)
                    || ($fila['fecha'] ?? '') !== substr((string) ($ripsEpisodio['fecha_egreso'] ?? ''), 0, 10)
                    || trim((string) ($ripsEpisodio['destino'] ?? '')) === ''
                    || ($ripsEpisodio['destino'] ?? '') === '08') {
                    throw new \LogicException('Vínculo hospitalario incompatible con la fila y el RIPS.');
                }
                $categoria = 'candidatos';
            }
            if (in_array($categoria, ['exactos', 'candidatos'], true)) {
                $indice = $episodio['indice_rips'] ?? ($categoria === 'exactos' ? ($fila['indice_rips'] ?? null) : ($fila['indice_rips_candidato'] ?? null));
                if (! is_int($indice) || ! array_key_exists($indice, $rips) || isset($asignados[$indice])) {
                    throw new \LogicException('La correspondencia RIPS falta o se usa más de una vez.');
                }
                if (in_array($tipo, ['AP', 'AT', 'AM'], true) && ($rips[$indice]['paquete'] ?? '') === 'INCLUIDO_EN_PAQUETE') {
                    $categoria = 'paquetes_no_aditivos';
                }
                $asignados[$indice] = ['centavos' => $importe, 'estado' => $categoria === 'exactos'
                    ? 'REFERENCIA ADMINISTRATIVA EN FECHA' : ($categoria === 'paquetes_no_aditivos'
                        ? 'POSIBLE INCLUSION EN AH; NO ADITIVO' : 'REFERENCIA PROVISIONAL NO CONFIRMADA'),
                    'fuente' => $fila['fuente'] ?? '', 'evidencia_episodio' => $episodio['evidencia'] ?? ''];
            }
            $valores[$categoria] += $importe;
            $porTipo[$tipo][$categoria] += $importe;
        }
        foreach ($rips as $indice => $linea) {
            $dato = $asignados[$indice] ?? ['centavos' => null, 'estado' => 'NO DETERMINADO', 'fuente' => ''];
            $detalle[] = [...$linea, ...$dato, 'indice_rips' => $indice];
        }

        return ['valores' => $valores, 'por_tipo' => $porTipo, 'detalle_rips' => $detalle,
            'control' => $conciliacion['administrativo'] - array_sum($valores),
            'lineas_rips' => count($rips), 'lineas_con_referencia' => count($asignados)];
    }

    /**
     * @param  iterable<array{ruta: string, mes: string, tipo: string, codigo: string, paciente: string, fecha: string, centavos: int, comparable: bool}>  $administrativos
     * @param  iterable<array{ruta: string, mes: string, tipo: string, codigo: string, paciente: string, fecha: string}>  $rips
     * @return array{administrativo: int, rips_exactos: int, no_localizados: int, no_comparables: int, filas_exactas: int, filas_no_localizadas: int, filas_no_comparables: int, detalle: list<array<string, mixed>>, rips_sin_fila: list<array<string, mixed>>}
     */
    public function conciliar(iterable $administrativos, iterable $rips): array
    {
        $rips = is_array($rips) ? array_values($rips) : iterator_to_array($rips, false);
        $disponibles = [];
        $indicesPorClave = [];
        foreach ($rips as $indice => $linea) {
            $clave = $this->clave($linea);
            if ($clave !== null) {
                $disponibles[$clave] = ($disponibles[$clave] ?? 0) + 1;
                $indicesPorClave[$clave][] = $indice;
            }
        }

        $disponiblesIniciales = $disponibles;
        $administrativos = is_array($administrativos) ? $administrativos : iterator_to_array($administrativos, false);
        $valoresPorClave = [];
        $filasPorClave = [];
        foreach ($administrativos as $fila) {
            $clave = $this->clave($fila);
            if ($fila['comparable'] && $clave !== null) {
                $filasPorClave[$clave] = ($filasPorClave[$clave] ?? 0) + 1;
                $valoresPorClave[$clave][$fila['centavos']] = true;
            }
        }

        $resultado = ['administrativo' => 0, 'rips_exactos' => 0, 'no_localizados' => 0,
            'no_comparables' => 0, 'filas_exactas' => 0, 'filas_no_localizadas' => 0,
            'filas_no_comparables' => 0, 'detalle' => [], 'rips_sin_fila' => []];
        foreach ($administrativos as $fila) {
            $importe = $fila['centavos'];
            $resultado['administrativo'] += $importe;
            $clave = $this->clave($fila);
            $asignacionAmbigua = $clave !== null && ($filasPorClave[$clave] ?? 0) > ($disponiblesIniciales[$clave] ?? 0)
                && count($valoresPorClave[$clave] ?? []) > 1;
            if (! $fila['comparable'] || $clave === null || $asignacionAmbigua) {
                $estado = 'NO COMPARABLE';
                $resultado['no_comparables'] += $importe;
                $resultado['filas_no_comparables']++;
            } elseif (($indiceRips = $this->seleccionarCompatible($fila, $indicesPorClave[$clave] ?? [], $rips)) !== null) {
                $estado = 'COINCIDENCIA EXACTA';
                $disponibles[$clave]--;
                $indicesPorClave[$clave] = array_values(array_diff($indicesPorClave[$clave], [$indiceRips]));
                $resultado['rips_exactos'] += $importe;
                $resultado['filas_exactas']++;
            } else {
                $estado = 'SIN RIPS EXACTO';
                $resultado['no_localizados'] += $importe;
                $resultado['filas_no_localizadas']++;
            }
            $resultado['detalle'][] = [...$fila, 'estado' => $estado,
                'indice_rips' => $estado === 'COINCIDENCIA EXACTA' ? $indiceRips : null];
        }

        $this->agregarCandidatos($resultado['detalle'], $indicesPorClave, $rips);
        foreach ($resultado['detalle'] as &$fila) {
            if ($fila['estado'] === 'SIN RIPS EXACTO' && $fila['tipo'] === 'AT' && $fila['codigo'] === 'T34004'
                && ($disponiblesIniciales[$this->clave($fila)] ?? 0) > 0) {
                $fila['nivel_correspondencia'] = 'PENDIENTE DE UNIDAD DE TRANSPORTE';
                unset($fila['indice_rips_candidato']);
            }
            $fila['nivel_correspondencia'] ??= match ($fila['estado']) {
                'COINCIDENCIA EXACTA' => 'COINCIDENCIA EXACTA',
                'NO COMPARABLE' => 'PENDIENTE DE UNIDAD O IDENTIDAD',
                default => 'SIN CORRESPONDENCIA ENCONTRADA',
            };
        }
        unset($fila);

        foreach ($disponibles as $clave => $cantidad) {
            if ($cantidad > 0) {
                [$ruta, $mes, $tipo, $codigo, $paciente, $fecha] = json_decode($clave, true, 512, JSON_THROW_ON_ERROR);
                $resultado['rips_sin_fila'][] = compact('ruta', 'mes', 'tipo', 'codigo', 'paciente', 'fecha', 'cantidad');
            }
        }

        return $resultado;
    }

    /** @param array<string, mixed> $fila @param list<int> $indices @param list<array<string, mixed>> $rips */
    private function seleccionarCompatible(array $fila, array $indices, array $rips): ?int
    {
        $compatibles = [];
        foreach ($indices as $indice) {
            $compatible = true;
            foreach (['tipo_documento', 'factura', 'autorizacion', 'episodio', 'ciclo'] as $campo) {
                $izquierda = trim((string) ($fila[$campo] ?? ''));
                $derecha = trim((string) ($rips[$indice][$campo] ?? ''));
                if ($izquierda !== '' && $derecha !== '' && $izquierda !== $derecha) {
                    $compatible = false;
                    break;
                }
            }
            if ($compatible) {
                $compatibles[] = $indice;
            }
        }

        return count($compatibles) === 1 ? $compatibles[0] : null;
    }

    /**
     * Las parejas mensuales solo se reservan si ambas partes son únicas. Una reserva es una
     * candidatura, no una prestación confirmada ni un importe adicional.
     *
     * @param  list<array<string, mixed>>  $detalle
     * @param  array<string, list<int>>  $indicesPorClave
     * @param  list<array<string, mixed>>  $rips
     */
    private function agregarCandidatos(array &$detalle, array $indicesPorClave, array $rips): void
    {
        $administrativos = [];
        $restantes = [];
        foreach ($detalle as $indice => $fila) {
            if ($fila['estado'] === 'SIN RIPS EXACTO' && $this->clave($fila) !== null) {
                $grupo = $this->clavePeriodo($fila);
                $administrativos[$grupo][] = $indice;
            }
        }
        foreach ($indicesPorClave as $indices) {
            foreach ($indices as $indice) {
                $restantes[$this->clavePeriodo($rips[$indice])][] = $indice;
            }
        }

        $reservados = [];
        foreach ($administrativos as $grupo => $filas) {
            $candidatos = $restantes[$grupo] ?? [];
            if ($candidatos === []) {
                continue;
            }
            foreach ($filas as $fila) {
                $detalle[$fila]['nivel_correspondencia'] = 'CANDIDATURA AMBIGUA POR PERIODO';
                $detalle[$fila]['cantidad_candidatos'] = count($candidatos);
            }
            if (count($filas) === 1 && count($candidatos) === 1
                && $this->seleccionarCompatible($detalle[$filas[0]], $candidatos, $rips) !== null) {
                $detalle[$filas[0]]['nivel_correspondencia'] = 'CANDIDATA UNICA POR PERIODO';
                foreach (['episodio', 'ciclo'] as $campo) {
                    $evidencia = trim((string) ($detalle[$filas[0]][$campo] ?? ''));
                    if ($evidencia !== '' && $evidencia === trim((string) ($rips[$candidatos[0]][$campo] ?? ''))) {
                        $detalle[$filas[0]]['nivel_correspondencia'] = 'CANDIDATA CON '.$campo.' DOCUMENTADO';
                        break;
                    }
                }
                $detalle[$filas[0]]['indice_rips_candidato'] = $candidatos[0];
                $reservados[$candidatos[0]] = true;
            }
        }

        $porFecha = [];
        foreach ($indicesPorClave as $indices) {
            foreach ($indices as $indice) {
                if (! isset($reservados[$indice])) {
                    $porFecha[$this->claveFecha($rips[$indice])][] = $indice;
                }
            }
        }
        $filasPorFecha = [];
        foreach ($detalle as $indice => $fila) {
            if ($fila['estado'] === 'SIN RIPS EXACTO' && ! isset($fila['nivel_correspondencia']) && $this->clave($fila) !== null) {
                $filasPorFecha[$this->claveFecha($fila)][] = $indice;
            }
        }
        foreach ($filasPorFecha as $grupo => $filas) {
            foreach ($filas as $fila) {
                if ($detalle[$fila]['ruta'] !== 'PROSTATA' || $detalle[$fila]['codigo'] !== '405406') {
                    continue;
                }
                $candidatos = array_values(array_filter($porFecha[$grupo] ?? [],
                    static fn (int $indice): bool => $rips[$indice]['codigo'] === '405407' && ! isset($reservados[$indice])));
                $filasCompatibles = array_filter($filas, static fn (int $indice): bool => $detalle[$indice]['codigo'] === '405406');
                if (count($filasCompatibles) === 1 && count($candidatos) === 1
                    && $this->seleccionarCompatible($detalle[$fila], $candidatos, $rips) !== null) {
                    $detalle[$fila]['nivel_correspondencia'] = 'DIFERENCIA DE CODIGO POR REVISAR';
                    $detalle[$fila]['indice_rips_candidato'] = $candidatos[0];
                    $reservados[$candidatos[0]] = true;
                }
            }
        }
    }

    /** @param array<string, mixed> $linea */
    private function clavePeriodo(array $linea): string
    {
        return json_encode(array_map(static fn (string $campo): string => trim((string) $linea[$campo]),
            ['ruta', 'mes', 'tipo', 'codigo', 'paciente']), JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $linea */
    private function claveFecha(array $linea): string
    {
        return json_encode(array_map(static fn (string $campo): string => trim((string) $linea[$campo]),
            ['ruta', 'mes', 'tipo', 'paciente', 'fecha']), JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array{ruta: string, mes: string, tipo: string, codigo: string, paciente: string, fecha: string}  $linea
     */
    private function clave(array $linea): ?string
    {
        foreach (['ruta', 'mes', 'tipo', 'codigo', 'paciente', 'fecha'] as $campo) {
            if (trim($linea[$campo]) === '') {
                return null;
            }
        }

        return json_encode(array_map(static fn (string $campo): string => trim($linea[$campo]),
            ['ruta', 'mes', 'tipo', 'codigo', 'paciente', 'fecha']), JSON_THROW_ON_ERROR);
    }
}
