<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CodigoMedicamentoNt extends Model
{
    protected $table = 'codigo_medicamentos_nt';

    protected $fillable = [
        'cums',
        'nombre_estandar',
        'pertenece_nt',
        'tarifa_nt',
        'activo',
        'hoja',
    ];

    protected $casts = [
        'tarifa_nt' => 'decimal:4',
        'activo' => 'boolean',
    ];
}
