<?php

namespace App\Programas\LaMaria;

use App\Exceptions\ConcurrenciaContinuidadException;

/**
 * Persistencia de la continuidad hospitalaria entre ejecuciones: segmentos
 * versionados (tabla hospital_segmentos) y libro de movimientos de paquetes
 * (tabla hospital_paquete_movimientos). La confirmación es optimista por
 * paciente: si otro proceso confirmó cambios del mismo paciente después de la
 * lectura, se rechaza y el libro vuelve a calcular.
 */
interface AlmacenContinuidadHospitalaria
{
    /**
     * Marca de versión de los datos del paciente en la ruta.
     */
    public function version(string $ruta, string $paciente): int;

    /**
     * @return list<array<string, mixed>> todas las versiones de los segmentos del paciente
     */
    public function segmentos(string $ruta, string $paciente): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function movimientos(string $ruta, ?string $paciente = null): array;

    /**
     * Meses (aaaa-mm) con segmentos vigentes cargados en la ruta.
     *
     * @return list<string>
     */
    public function mesesCargados(string $ruta): array;

    /**
     * Aplica de forma atómica segmentos nuevos, reemplazos y movimientos del
     * paciente. Garantiza un solo reconocimiento vigente por bloque.
     *
     * @param  array{segmentos: list<array<string, mixed>>, reemplazados: list<string>, movimientos: list<array<string, mixed>>}  $escrituras
     *
     * @throws ConcurrenciaContinuidadException
     */
    public function confirmar(string $ruta, string $paciente, int $versionLeida, array $escrituras): void;
}
