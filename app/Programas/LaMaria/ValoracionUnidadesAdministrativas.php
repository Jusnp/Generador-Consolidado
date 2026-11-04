<?php

declare(strict_types=1);

namespace App\Programas\LaMaria;

use RuntimeException;

/** Valoración independiente y provisional; no modifica el costo técnico. */
final class ValoracionUnidadesAdministrativas
{
    /**
     * @param  list<array<string, mixed>>  $lineas
     * @return array{detalle: list<array<string, mixed>>, total_centavos: int, no_aditivo_centavos: int, pendientes: int}
     */
    public function calcularConsultasProcedimientos(array $lineas): array
    {
        if (! function_exists('bcmul')) {
            throw new RuntimeException('La valoración decimal requiere la extensión BCMath.');
        }

        $detalle = [];
        $total = 0;
        $noAditivo = 0;
        $pendientes = 0;
        $vistos = [];
        foreach ($lineas as $linea) {
            $indice = $linea['indice_rips'] ?? null;
            if ($indice === null || isset($vistos[$indice])) {
                throw new RuntimeException('Cada línea RIPS debe tener un índice único.');
            }
            $vistos[$indice] = true;
            $tecnico = $linea['detalle_tecnico'] ?? [];
            $cantidad = (string) ($tecnico['cantidad'] ?? '');
            $tarifa = (string) ($tecnico['tarifa'] ?? '');
            $tipo = $linea['tipo'] ?? '';
            $importe = null;
            $estado = 'NO DETERMINABLE: TARIFA O CANTIDAD';
            if (in_array($tipo, ['AC', 'AP'], true) && ($tecnico['fuente'] ?? '') === 'NOTA_TECNICA'
                && is_numeric($cantidad) && (float) $cantidad > 0 && is_numeric($tarifa) && (float) $tarifa > 0) {
                $importe = $this->centavos(bcmul($cantidad, $tarifa, 12));
                if (($linea['paquete'] ?? '') === 'INCLUIDO_EN_PAQUETE') {
                    $estado = 'NO ADITIVO: POSIBLE INCLUSION AH';
                    $noAditivo += $importe;
                } else {
                    $estado = in_array((string) ($linea['codigo'] ?? ''), ['992504', '992505', '992509', '992511'], true)
                        ? 'BASE DE APLICACION; ESQUEMA INTEGRAL PENDIENTE'
                        : 'TARIFA NOTA TECNICA; CRITERIO ADMINISTRATIVO POR VALIDAR';
                    $total += $importe;
                }
            } else {
                $pendientes++;
            }
            $detalle[] = [...$linea, 'cantidad_calculo' => $cantidad, 'tarifa_calculo' => $tarifa, 'importe_calculo_centavos' => $importe, 'estado_calculo' => $estado];
        }

        return ['detalle' => $detalle, 'total_centavos' => $total, 'no_aditivo_centavos' => $noAditivo, 'pendientes' => $pendientes];
    }

    /** @param list<array<string, mixed>> $lineas
     * @return array{detalle: list<array<string, mixed>>, total_centavos: int, suma_lineas_centavos: int, ajuste_redondeo_centavos: int}
     */
    public function calcular(array $lineas, string $tarifaTransporte): array
    {
        if (! function_exists('bcmul')) {
            throw new RuntimeException('La valoración decimal requiere la extensión BCMath.');
        }
        $detalle = [];
        $total = '0';
        $sumaLineas = 0;
        foreach ($lineas as $linea) {
            $tipo = $linea['tipo'] ?? '';
            $cobertura = $linea['cobertura_medicamento'] ?? [];
            $clasificacion = $cobertura['clasificacion_motor'] ?? '';
            $cantidad = (string) ($linea['cantidad'] ?? '');
            $tarifa = '';
            $estado = 'PENDIENTE DE SOPORTE';
            if (($linea['paquete'] ?? '') === 'INCLUIDO_EN_PAQUETE' || str_contains($clasificacion, 'INCLUIDO') || str_contains($clasificacion, 'CONDICIONADO AP') || str_contains($clasificacion, 'URGENCIA')) {
                $estado = 'NO ADITIVO: REVISAR PAQUETE';
            } elseif ($tipo === 'AM' && $clasificacion === 'INDEPENDIENTE' && ! empty($cobertura['cum']) && is_numeric($cobertura['precio_historico'] ?? null) && (float) $cobertura['precio_historico'] > 0) {
                $tarifa = (string) $cobertura['precio_historico'];
                $estado = 'PROVISIONAL: PRECIO HISTORICO Y PRESENTACION';
            } elseif ($tipo === 'AT' && ($linea['codigo'] ?? '') === 'T34004') {
                $tarifa = $tarifaTransporte;
                $estado = 'PROVISIONAL: UNIDAD Y TARIFA HISTORICA';
            }
            $importe = null;
            if ($tarifa !== '' && is_numeric($cantidad) && (float) $cantidad > 0 && is_numeric($tarifa) && (float) $tarifa > 0) {
                $importe = bcmul($cantidad, $tarifa, 12);
                $total = bcadd($total, $importe, 12);
                $sumaLineas += $this->centavos($importe);
            } elseif ($tarifa !== '') {
                $estado = 'PENDIENTE: CANTIDAD O TARIFA';
            }
            $detalle[] = [...$linea, 'estado_calculo' => $estado, 'tarifa_calculo' => $tarifa, 'importe_decimal' => $importe, 'importe_centavos' => $importe === null ? null : $this->centavos($importe)];
        }
        $centavos = $this->centavos($total);

        return ['detalle' => $detalle, 'total_centavos' => $centavos, 'suma_lineas_centavos' => $sumaLineas, 'ajuste_redondeo_centavos' => $centavos - $sumaLineas];
    }

    private function centavos(string $valor): int
    {
        return (int) bcmul(bcadd($valor, '0.005', 2), '100', 0);
    }
}
