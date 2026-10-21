<?php

namespace App\Programas\LaMaria;

class ProyeccionEconomica
{
    /**
     * Proyecta primero las unidades de un servicio. Dos meses permiten validar
     * agosto; tres, septiembre; cuatro, octubre y noviembre.
     *
     * @param  list<float|int>  $unidades
     * @return array{metodo: string, unidades_central: float, unidades_conservador: float, unidades_alto: float, conservador: float, central: float, alto: float, tendencia: float, variabilidad: float, historial_escaso: bool, inactividad_confirmada: bool}
     */
    public function proyectarServicio(array $unidades, float $tarifa, int $horizonte, string $metodo, bool $reducirInactivos = true): array
    {
        if (! in_array(count($unidades), [2, 3, 4], true) || $tarifa < 0 || ! in_array($horizonte, [1, 2], true) || ! in_array($metodo, ['ponderado', 'mediana'], true)) {
            throw new \InvalidArgumentException('Serie, tarifa, horizonte o método inválido.');
        }

        foreach ($unidades as $cantidad) {
            if (! is_numeric($cantidad) || $cantidad < 0) {
                throw new \InvalidArgumentException('Las unidades históricas deben ser no negativas.');
            }
        }

        $serie = array_map(static fn (float|int $valor): float => (float) $valor, array_values($unidades));
        $length = count($serie);
        $inactividadConfirmada = $reducirInactivos && $length >= 3 && $serie[$length - 1] === 0.0 && $serie[$length - 2] === 0.0;
        $escaso = count(array_filter($serie, static fn (float $valor): bool => $valor > 0)) < 2;
        $weights = $length === 2 ? [0.5, 0.5] : ($length === 3 ? [0.2, 0.3, 0.5] : [0.1, 0.2, 0.3, 0.4]);
        $weighted = array_sum(array_map(static fn (float $weight, float $value): float => $weight * $value, $weights, $serie));
        $sorted = $serie;
        sort($sorted, SORT_NUMERIC);
        $median = $length === 3 ? $sorted[1] : ($sorted[$length === 2 ? 0 : 1] + $sorted[$length === 2 ? 1 : 2]) / 2;
        $first = ($serie[0] + $serie[1]) / 2;
        $recent = $length === 2 ? $first : ($length === 3 ? $serie[2] : ($serie[2] + $serie[3]) / 2);
        $trend = $escaso || $length === 2 ? 0.0 : max(-0.1, min(0.1, ($recent - $first) / max(1.0, $first)));
        $mean = array_sum($serie) / $length;
        $variance = array_sum(array_map(static fn (float $value): float => ($value - $mean) ** 2, $serie)) / $length;
        $volatility = max(0.1, min(0.3, sqrt($variance) / max(1.0, $mean)));
        $base = $inactividadConfirmada ? 0.0 : ($escaso ? $mean : ($metodo === 'ponderado' ? $weighted : $median));
        $centralUnits = max(0.0, $base * ((1 + $trend) ** $horizonte));
        $lowUnits = max(0.0, $centralUnits * (1 - $volatility));
        $highUnits = $centralUnits * (1 + $volatility);

        return [
            'metodo' => $metodo,
            'unidades_central' => round($centralUnits, 4),
            'unidades_conservador' => round($lowUnits, 4),
            'unidades_alto' => round($highUnits, 4),
            'conservador' => round($lowUnits * $tarifa, 2),
            'central' => round($centralUnits * $tarifa, 2),
            'alto' => round($highUnits * $tarifa, 2),
            'tendencia' => round($trend, 6),
            'variabilidad' => round($volatility, 6),
            'historial_escaso' => $escaso,
            'inactividad_confirmada' => $inactividadConfirmada,
        ];
    }

    /**
     * Proyecta unidades valoradas a una tarifa ya verificada, sin escribir
     * resultados reales ni convertir promedios de ciclo en tarifas.
     *
     * @param  array{0: float|int, 1: float|int, 2: float|int, 3: float|int}  $unidades  Junio a septiembre.
     * @return array{unidades_central: float, conservador: float, central: float, alto: float, tendencia: float, variabilidad: float}
     */
    public function proyectar(array $unidades, float $tarifa, int $horizonte): array
    {
        if (count($unidades) !== 4 || $tarifa < 0 || ! in_array($horizonte, [1, 2], true)) {
            throw new \InvalidArgumentException('Se requieren cuatro meses, tarifa no negativa y horizonte de uno o dos meses.');
        }

        foreach ($unidades as $cantidad) {
            if (! is_numeric($cantidad) || $cantidad < 0) {
                throw new \InvalidArgumentException('Las unidades históricas deben ser no negativas.');
            }
        }

        $serie = array_map(static fn (float|int $valor): float => (float) $valor, array_values($unidades));
        $base = $serie[0] * 0.1 + $serie[1] * 0.2 + $serie[2] * 0.3 + $serie[3] * 0.4;
        $primeraMitad = ($serie[0] + $serie[1]) / 2;
        $segundaMitad = ($serie[2] + $serie[3]) / 2;
        $tendencia = max(-0.1, min(0.1, ($segundaMitad - $primeraMitad) / max(1.0, $primeraMitad)));
        $media = array_sum($serie) / 4;
        $varianza = array_sum(array_map(static fn (float $valor): float => ($valor - $media) ** 2, $serie)) / 4;
        $variabilidad = max(0.1, min(0.3, sqrt($varianza) / max(1.0, $media)));
        $unidadesCentrales = max(0, $base * ((1 + $tendencia) ** $horizonte));
        $costoCentral = round($unidadesCentrales * $tarifa, 2);

        return [
            'unidades_central' => round($unidadesCentrales, 4),
            'conservador' => round($costoCentral * (1 - $variabilidad), 2),
            'central' => $costoCentral,
            'alto' => round($costoCentral * (1 + $variabilidad), 2),
            'tendencia' => round($tendencia, 6),
            'variabilidad' => round($variabilidad, 6),
        ];
    }
}
