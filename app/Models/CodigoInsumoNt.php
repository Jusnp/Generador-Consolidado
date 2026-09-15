<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CodigoInsumoNt extends Model
{
    protected $table = 'codigo_insumos_nt';

    protected $fillable = [
        'codigo',
        'descripcion',
        'nt',
        'tarifa_unitario',
    ];

    protected $casts = [
        'tarifa_unitario' => 'decimal:4',
    ];
}