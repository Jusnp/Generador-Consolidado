<?php

namespace App\Programas\LaMaria;

/**
 * Historial hospitalario en memoria: pruebas y verificación con datos reales sin escribir en la base.
 */
final class HistorialHospitalarioEnMemoria implements HistorialHospitalario
{
    /** @var array<string, list<AntecedenteHospitalario>> */
    private array $versiones = [];

    private int $secuencia = 0;

    public function vigente(string $ruta, string $periodo): ?AntecedenteHospitalario
    {
        foreach (array_reverse($this->versiones[$ruta.'|'.$periodo] ?? []) as $cierre) {
            if ($cierre->vigente) {
                return $cierre;
            }
        }

        return null;
    }

    public function version(string $ruta, string $periodo, int $version): ?AntecedenteHospitalario
    {
        return $this->versiones[$ruta.'|'.$periodo][$version - 1] ?? null;
    }

    public function versiones(string $ruta, string $periodo): array
    {
        return $this->versiones[$ruta.'|'.$periodo] ?? [];
    }

    public function guardar(AntecedenteHospitalario $cierre, ?int $usuarioId = null): AntecedenteHospitalario
    {
        $clave = $cierre->ruta.'|'.$cierre->periodo;
        $actual = $this->vigente($cierre->ruta, $cierre->periodo);

        if ($actual !== null && $actual->hashContenido() === $cierre->hashContenido() && $actual->hashOrigen() === $cierre->hashOrigen()) {
            return $actual;
        }

        foreach ($this->versiones[$clave] ?? [] as $indice => $anterior) {
            $this->versiones[$clave][$indice] = $anterior->conVersion($anterior->version, $anterior->id, false);
        }

        $nuevo = $cierre->rectificando($actual)->conVersion(count($this->versiones[$clave] ?? []) + 1, ++$this->secuencia);
        $this->versiones[$clave][] = $nuevo;

        return $nuevo;
    }
}
