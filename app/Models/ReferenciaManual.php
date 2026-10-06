<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ReferenciaManual extends Model
{
    protected $table = 'referencias_manuales';

    protected $fillable = [
        'programa_slug', 'ruta', 'tipo', 'codigo', 'nombre', 'nombre_normalizado',
        'tarifa', 'activo', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['tarifa' => 'float', 'activo' => 'boolean'];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('activo', true);
    }

    public function scopeForProgram(Builder $query, string $programaSlug): Builder
    {
        return $query->where('programa_slug', $programaSlug);
    }
}
