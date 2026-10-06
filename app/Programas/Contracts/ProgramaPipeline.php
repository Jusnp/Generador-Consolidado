<?php

namespace App\Programas\Contracts;

/**
 * Contrato de ejecución de un programa SIRUTA.
 *
 * Cada programa debe tener su propia implementación con reglas de negocio,
 * catálogos y punto de entrada independientes. No compartir un pipeline
 * genérico entre programas.
 */
interface ProgramaPipeline
{
    /**
     * Ruta nombrada de la UI/ejecución del programa, o null si aún no está habilitado.
     */
    public function entryRouteName(): ?string;

    /**
     * Identificador estable del programa/ejecución (slug de config).
     */
    public function slug(): string;

    /**
     * Código de ruta interna cuando la ejecución filtra por una ruta clínica, o null.
     */
    public function rutaCodigo(): ?string;

    /**
     * Tipos de catálogo que pertenecen exclusivamente a este programa.
     *
     * @return list<string>
     */
    public function ownedCatalogTypes(): array;

    /**
     * Etiqueta de propiedad para pantallas de administración.
     */
    public function catalogOwnershipLabel(): string;
}
