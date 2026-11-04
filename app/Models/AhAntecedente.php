<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Versión de un cierre hospitalario de La María (ver migración).
 */
class AhAntecedente extends Model
{
    protected $table = 'ah_antecedentes';

    protected $fillable = [
        'programa_slug', 'ruta', 'periodo', 'version', 'tipo', 'estado', 'clave_vigente', 'hash_contenido', 'hash_origen',
        'origen', 'version_reglas', 'huella_secreto', 'antecedente_id', 'antecedente_hash', 'reemplaza_id', 'motivo', 'completitud', 'programa_ejecucion_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'origen' => 'array',
        ];
    }

    /**
     * @return HasMany<AhEstanciaAbierta, $this>
     */
    public function estancias(): HasMany
    {
        return $this->hasMany(AhEstanciaAbierta::class, 'antecedente_id');
    }
}
