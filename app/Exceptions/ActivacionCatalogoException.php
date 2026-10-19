<?php

namespace App\Exceptions;

use Exception;

/**
 * Una activación, inactivación o reversión de catálogo que no se aplicó. Si es
 * un conflicto de concurrencia, otro administrador cambió las versiones activas
 * después de que el usuario las consultó y la operación debe repetirse.
 */
class ActivacionCatalogoException extends Exception
{
    public function __construct(string $message, public readonly bool $esConflicto = false)
    {
        parent::__construct($message);
    }

    public static function conflicto(string $message): self
    {
        return new self($message, true);
    }
}
