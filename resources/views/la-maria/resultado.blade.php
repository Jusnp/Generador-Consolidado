@php
    $dinero = static fn (mixed $valor): string => is_numeric($valor) ? '$'.number_format((float) $valor, 2, ',', '.') : (string) $valor;
    $porcentaje = static fn (mixed $valor): string => is_numeric($valor) ? number_format((float) $valor * 100, 2, ',', '.').'%' : '';
    $nombreRuta = static fn (string $ruta): string => ['PROSTATA' => 'Próstata', 'CERVIX' => 'Cérvix'][$ruta] ?? $ruta;
    $totales = array_values(array_filter($resultado['explicacion'], static fn (array $fila): bool => $fila[2] === 'TOTAL'));
@endphp

<section class="la-maria-result">
    <p class="la-maria-result-warning">
        <strong>Resultado generado {{ $resultado['generado'] }} · COP.</strong>
        Análisis de desarrollo: <strong>facturación no aprobada</strong>. El resultado A (respaldado) y el B (escenario
        referencial) se calculan en la misma ejecución y no se suman entre sí. La brecha frente a la nota es presupuestal:
        no equivale a ingreso perdido.
    </p>

    <h3>Control de cargue</h3>
    <div class="la-maria-table-wrap">
        <table>
            <thead><tr><th>Recepción</th><th>Cantidad</th><th>Archivos</th><th>Observación</th></tr></thead>
            <tbody>
                @foreach ($resultado['recepcion'] as $fila)
                    <tr><td>{{ $fila[0] }}</td><td class="num">{{ $fila[1] }}</td><td>{{ $fila[2] }}</td><td>{{ $fila[3] }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <details>
        <summary>Archivos procesados ({{ count($resultado['archivos']) }})</summary>
        <div class="la-maria-table-wrap">
            <table>
                <thead><tr><th>Archivo</th><th>Factura</th><th>Mes inicial</th><th>Mes final</th><th>Usuarios</th><th>Servicios</th><th>Estado</th></tr></thead>
                <tbody>
                    @foreach ($resultado['archivos'] as $archivo)
                        <tr><td>{{ $archivo[0] }}</td><td>{{ $archivo[1] }}</td><td>{{ $archivo[2] }}</td><td>{{ $archivo[3] }}</td><td class="num">{{ number_format($archivo[4], 0, ',', '.') }}</td><td class="num">{{ number_format($archivo[5], 0, ',', '.') }}</td><td>{{ $archivo[6] }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </details>

    <h3>Costos y brechas</h3>
    <div class="la-maria-table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Ruta</th><th>Mes</th><th>Referencia nota</th><th>A · Costo respaldado</th><th>Condicionado incluido</th>
                    <th>B · Escenario referencial</th><th>Externos potenciales (no suman)</th><th>Brecha respaldada</th><th>Brecha referencial</th>
                    <th>Cobertura A</th><th>Servicios sin tarifa</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($resultado['costos'] as $fila)
                    <tr @class(['total' => $fila[1] === 'TOTAL PERIODO'])>
                        <td>{{ $nombreRuta($fila[0]) }}</td><td>{{ $fila[1] }}</td><td class="num">{{ $dinero($fila[2]) }}</td>
                        <td class="num"><strong>{{ $dinero($fila[3]) }}</strong></td><td class="num">{{ $dinero($fila[4]) }}</td>
                        <td class="num">{{ $dinero($fila[5]) }}</td><td class="num">{{ $dinero($fila[6]) }}</td>
                        <td class="num">{{ $dinero($fila[7]) }}</td><td class="num">{{ $dinero($fila[8]) }}</td>
                        <td class="num">{{ $porcentaje($fila[11]) }}</td><td class="num">{{ number_format((int) $fila[13], 0, ',', '.') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <h3>Explicación de la brecha respaldada</h3>
    <div class="la-maria-table-wrap">
        <table>
            <thead><tr><th>Ruta</th><th>Mes</th><th>Brecha respaldada</th><th>Explicada</th><th>No cuantificada</th><th>Sin explicar</th><th>% explicado</th></tr></thead>
            <tbody>
                @foreach ($totales as $fila)
                    <tr><td>{{ $nombreRuta($fila[0]) }}</td><td>{{ $fila[1] }}</td><td class="num">{{ $dinero($fila[7]) }}</td><td class="num">{{ $dinero($fila[14]) }}</td><td class="num">{{ $dinero($fila[15]) }}</td><td class="num">{{ $dinero($fila[16]) }}</td><td class="num">{{ $porcentaje($fila[17]) }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <details>
        <summary>Detalle por grupo de la nota técnica (actividad proyectada frente a RIPS)</summary>
        <div class="la-maria-table-wrap tall">
            <table>
                <thead>
                    <tr>
                        <th>Ruta</th><th>Mes</th><th>Grupo</th><th>Proyectado</th><th>Respaldado</th><th>Condicionado</th><th>Brecha</th>
                        <th>Unid. proyectadas</th><th>Unid. RIPS</th><th>Sin actividad</th><th>Menor actividad</th><th>Mayor actividad</th>
                        <th>Actividad no respaldada</th><th>Explicada</th><th>No cuantificada</th><th>Sin explicar</th><th>Causa</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($resultado['explicacion'] as $fila)
                        <tr @class(['total' => $fila[2] === 'TOTAL'])>
                            <td>{{ $nombreRuta($fila[0]) }}</td><td>{{ $fila[1] }}</td><td>{{ $fila[2] }}</td>
                            @foreach ([3, 4, 5, 7] as $columna)<td class="num">{{ $dinero($fila[$columna]) }}</td>@endforeach
                            <td class="num">{{ is_numeric($fila[8]) ? number_format($fila[8], 2, ',', '.') : '' }}</td>
                            <td class="num">{{ is_numeric($fila[9]) ? number_format($fila[9], 2, ',', '.') : '' }}</td>
                            @foreach ([10, 11, 12, 13, 14, 15, 16] as $columna)<td class="num">{{ $dinero($fila[$columna]) }}</td>@endforeach
                            <td class="cause">{{ $fila[18] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </details>

    <details>
        <summary>Medicamentos de mayor impacto (25 por ruta; el Excel trae todos)</summary>
        <div class="la-maria-table-wrap tall">
            <table>
                <thead><tr><th>Ruta</th><th>Medicamento</th><th>CUM</th><th>IUM</th><th>Presentación</th><th>Cantidad RIPS</th><th>Tarifa utilizada</th><th>Fuente</th><th>Respaldado</th><th>Condicionado</th><th>Externo no sumado</th><th>Motivo de exclusión</th></tr></thead>
                <tbody>
                    @foreach ($resultado['medicamentos'] as $fila)
                        <tr><td>{{ $nombreRuta($fila[0]) }}</td><td>{{ $fila[1] }}</td><td>{{ $fila[2] }}</td><td>{{ $fila[3] }}</td><td>{{ $fila[4] }}</td><td class="num">{{ number_format((float) $fila[5], 2, ',', '.') }}</td><td class="num">{{ $dinero($fila[6]) }}</td><td>{{ $fila[7] }}</td><td class="num">{{ $dinero($fila[8]) }}</td><td class="num">{{ $dinero($fila[9]) }}</td><td class="num">{{ $dinero($fila[10]) }}</td><td>{{ $fila[14] }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </details>

    <details>
        <summary>Servicios sin tarifa aplicable (25 por ruta, por número de líneas)</summary>
        <div class="la-maria-table-wrap tall">
            <table>
                <thead><tr><th>Ruta</th><th>Tipo</th><th>Código</th><th>Descripción</th><th>Grupo nota</th><th>Estado</th><th>Motivo</th><th>Líneas</th><th>Valor en escenario B</th><th>Externo no sumado</th><th>En nota</th></tr></thead>
                <tbody>
                    @foreach ($resultado['sinTarifa'] as $fila)
                        <tr><td>{{ $nombreRuta($fila[0]) }}</td><td>{{ $fila[1] }}</td><td>{{ $fila[2] }}</td><td>{{ $fila[3] }}</td><td>{{ $fila[4] }}</td><td>{{ $fila[5] }}</td><td>{{ $fila[6] }}</td><td class="num">{{ number_format($fila[7], 0, ',', '.') }}</td><td class="num">{{ $dinero($fila[10]) }}</td><td class="num">{{ $dinero($fila[11]) }}</td><td>{{ $fila[12] }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </details>

    <details>
        <summary>Hospitalización y prevención de doble cobro</summary>
        <div class="la-maria-table-wrap">
            <table>
                <thead><tr><th>Ruta</th><th>Mes</th><th>Paquetes</th><th>Costo paquetes</th><th>Servicios absorbidos</th><th>Costo individual excluido</th><th>Cobrados por fuera</th><th>Costo por fuera</th><th>Regla pendiente</th></tr></thead>
                <tbody>
                    @foreach ($resultado['hospitalizacion'] as $fila)
                        <tr @class(['total' => $fila[1] === 'TOTAL'])><td>{{ $nombreRuta($fila[0]) }}</td><td>{{ $fila[1] }}</td><td class="num">{{ $fila[2] }}</td><td class="num">{{ $dinero($fila[3]) }}</td><td class="num">{{ $fila[4] }}</td><td class="num">{{ $dinero($fila[5]) }}</td><td class="num">{{ $fila[6] }}</td><td class="num">{{ $dinero($fila[7]) }}</td><td class="num">{{ $fila[8] }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p>Paquete por bloque de hasta 25 días de la estancia lógica, imputado al mes en que inicia. Los servicios absorbidos no se cobran por separado.</p>
    </details>
</section>
