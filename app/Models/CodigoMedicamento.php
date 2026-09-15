<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CodigoMedicamento extends Model
{
    protected $table = 'codigo_medicamentos';

    protected $fillable = [
        'codigo',
        'llave',
        'nt',
        'cums_homologo',
        'tarifa_unitario',
        'divide_por_duplicados',
    ];

    protected $casts = [
        'tarifa_unitario' => 'float',
        'divide_por_duplicados' => 'boolean',
    ];
}
