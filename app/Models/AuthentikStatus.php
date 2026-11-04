<?php

namespace App\Models;

/**
 * Estado de la identidad de un usuario de SIRUTA en Authentik. Solo las
 * identidades aprovisionadas por SIRUTA o vinculadas por un administrador
 * pueden iniciar sesión.
 */
enum AuthentikStatus: string
{
    /** Sin identidad en Authentik. */
    case Unlinked = 'unlinked';

    /** Registrado en SIRUTA; el aprovisionamiento en Authentik aún no termina. */
    case Pending = 'pending';

    /** Cuenta creada por SIRUTA en Authentik y vinculada. */
    case Provisioned = 'provisioned';

    /** Cuenta preexistente vinculada por un administrador, sin modificarla. */
    case Linked = 'linked';

    /** La cédula ya existe en Authentik en una cuenta que SIRUTA no creó. */
    case Conflict = 'conflict';

    /** El último intento de aprovisionamiento falló; se puede reintentar. */
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Unlinked => 'Sin vincular',
            self::Pending => 'Pendiente',
            self::Provisioned => 'Aprovisionado',
            self::Linked => 'Vinculado',
            self::Conflict => 'Conflicto',
            self::Failed => 'Error de sincronización',
        };
    }

    public function allowsLogin(): bool
    {
        return in_array($this, [self::Provisioned, self::Linked], true);
    }

    /**
     * Mientras el aprovisionamiento no termina, Authentik puede tener una cuenta
     * creada con la cédula actual: cambiarla o borrar el registro la dejaría huérfana.
     */
    public function mayHaveAuthentikAccount(): bool
    {
        return in_array($this, [self::Pending, self::Failed, self::Provisioned], true);
    }
}
