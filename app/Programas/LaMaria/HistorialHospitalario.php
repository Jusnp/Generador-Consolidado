<?php

namespace App\Programas\LaMaria;

/**
 * Historial versionado de cierres hospitalarios por ruta y período. Guardar es
 * idempotente: el mismo contenido y origen devuelven la versión vigente; un
 * cambio crea la versión siguiente y nunca modifica las anteriores.
 */
interface HistorialHospitalario
{
    public function vigente(string $ruta, string $periodo): ?AntecedenteHospitalario;

    public function version(string $ruta, string $periodo, int $version): ?AntecedenteHospitalario;

    /**
     * @return list<AntecedenteHospitalario>
     */
    public function versiones(string $ruta, string $periodo): array;

    public function guardar(AntecedenteHospitalario $cierre, ?int $usuarioId = null): AntecedenteHospitalario;
}
