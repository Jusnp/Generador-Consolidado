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
    ];

    protected $casts = [
        'tarifa_nt' => 'decimal:4',
    ];
}