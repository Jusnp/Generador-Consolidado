<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    /**
     * Campos que se pueden asignar masivamente.
     */
    protected $fillable = [
        'name',
        'email',
        'authentik_subject',
        'password',
        'role',
        'active',
    ];

    /**
     * Campos que no deben mostrarse en arrays o JSON.
     */
    protected $hidden = [
        'password',
        'remember_token',
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
        ];
    }

    /**
     * Actividades realizadas por este usuario.
     */
    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }
}
