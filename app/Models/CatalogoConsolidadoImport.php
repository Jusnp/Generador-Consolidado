<?php

namespace App\Models;

use Database\Factories\CatalogoConsolidadoImportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogoConsolidadoImport extends Model
{
    /** @use HasFactory<CatalogoConsolidadoImportFactory> */
    use HasFactory;

    protected $fillable = [
        'tipo',
        'version',
        'archivo_origen',
        'archivo_almacenado',
        'registros_procesados',
        'registros_nuevos',
        'registros_actualizados',
        'registros_ignorados',
        'imported_by',
    ];

    protected function casts(): array
    {
        return [
            'registros_procesados' => 'integer',
            'registros_nuevos' => 'integer',
            'registros_actualizados' => 'integer',
            'registros_ignorados' => 'integer',
        ];
    }

    public function importedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }
}
