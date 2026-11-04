<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    /**
     * Campos que se pueden asignar masivamente.
     */
    protected $fillable = [
        'name',
        'cedula',
        'email',
        'password',
        'role',
        'active',
    ];

    /**
     * Iguales a los valores por defecto de las columnas, para usuarios aún no
     * recargados (el control de usuario activo los evalúa en cada petición).
     */
    protected $attributes = [
        'role' => 'user',
        'active' => true,
        'authentik_status' => 'unlinked',
    ];

    /**
     * Campos que no deben mostrarse en arrays o JSON.
     */
    protected $hidden = [
        'password',
        'remember_token',
        'authentik_provisioning_key',
    ];

    /**
     * Conversiones de atributos.
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'active' => 'boolean',
            'authentik_pk' => 'integer',
            'authentik_status' => AuthentikStatus::class,
            'authentik_provisioned_at' => 'datetime',
            'authentik_first_login_at' => 'datetime',
        ];
    }

    /**
     * Cierra las sesiones locales de este usuario en SIRUTA y anula su cookie
     * "recordarme", sin afectar las sesiones de otros usuarios.
     */
    public function invalidateSessions(): void
    {
        if (config('session.driver') === 'database') {
            DB::connection(config('session.connection'))
                ->table(config('session.table'))
                ->where('user_id', $this->getKey())
                ->delete();
        }

        $this->setRememberToken(Str::random(60));
        $this->save();
    }

    /**
     * Cédula con solo los últimos cuatro dígitos visibles, para auditoría.
     */
    public function maskedCedula(): ?string
    {
        return $this->cedula === null ? null : self::maskCedula($this->cedula);
    }

    public static function maskCedula(string $cedula): string
    {
        return str_repeat('*', max(strlen($cedula) - 4, 0)).substr($cedula, -4);
    }

    /**
     * La cédula es el usuario en Authentik: solo se puede cambiar mientras
     * SIRUTA no tenga ni pueda tener una cuenta creada o vinculada con ella.
     */
    public function canChangeCedula(): bool
    {
        return in_array($this->authentik_status, [AuthentikStatus::Unlinked, AuthentikStatus::Conflict], true);
    }

    public function canRetryAuthentikProvisioning(): bool
    {
        return $this->cedula !== null
            && in_array($this->authentik_status, [
                AuthentikStatus::Unlinked,
                AuthentikStatus::Pending,
                AuthentikStatus::Failed,
                AuthentikStatus::Conflict,
            ], true);
    }

    public function canLinkExistingAuthentikAccount(): bool
    {
        return $this->cedula !== null
            && in_array($this->authentik_status, [AuthentikStatus::Unlinked, AuthentikStatus::Conflict], true);
    }

    /**
     * El enlace de activación solo se ofrece para cuentas creadas por SIRUTA
     * que todavía no han iniciado sesión: nunca restablece cuentas en uso.
     */
    public function canReceiveActivationLink(): bool
    {
        return $this->authentik_status === AuthentikStatus::Provisioned
            && $this->authentik_pk !== null
            && $this->authentik_first_login_at === null;
    }

    public function canUnlinkAuthentikAccount(): bool
    {
        return $this->authentik_status === AuthentikStatus::Linked;
    }

    public function canBeDeleted(): bool
    {
        return ! $this->authentik_status->mayHaveAuthentikAccount();
    }

    /**
     * Actividades realizadas por este usuario.
     */
    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }
}
