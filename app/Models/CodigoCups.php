<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CodigoCups extends Model
{
    protected $table = 'codigo_cups';

    protected $fillable = [
        'codigo',
        'tipo_servicio',
        'descripcion',
        'tarifa_2025',
        'activo',
        'hoja',
    ];

    protected $casts = [
        'tarifa_2025' => 'decimal:4',
        'activo' => 'boolean',
    ];
}
