<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogoReferenciaItem extends Model
{
    protected $fillable = [
        'catalogo_referencia_id',
        'codigo',
        'hoja',
        'ruta',
        'categoria',
        'descripcion',
        'tarifa_referencia',
        'activo',
        'metadatos',
    ];

    protected function casts(): array
    {
        return [
            'tarifa_referencia' => 'float',
            'activo' => 'boolean',
            'metadatos' => 'array',
        ];
    }

    public function catalogoReferencia(): BelongsTo
    {
        return $this->belongsTo(CatalogoReferencia::class);
    }
}
