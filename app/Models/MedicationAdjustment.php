<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MedicationAdjustment extends Model
{
    protected $fillable = [
        'documento',
        'regimen',
        'codigo',
        'fecha_servicio',
        'ocurrencia',
        'valor_total_override',
        'motivo',
        'activo',
    ];

    protected $casts = [
        'fecha_servicio' => 'date:Y-m-d',
        'valor_total_override' => 'float',
        'activo' => 'boolean',
    ];
}
