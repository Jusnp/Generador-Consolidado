<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProgramaEjecucion extends Model
{
    protected $table = 'programa_ejecuciones';

    protected $fillable = [
        'programa_slug',
        'contrato_id',
        'user_id',
        'periodo',
        'batch_id',
        'archivo_salida',
        'catalogos_aplicados',
        'archivos_procesados',
    ];

    protected function casts(): array
    {
        return [
            'catalogos_aplicados' => 'array',
            'archivos_procesados' => 'integer',
        ];
    }

    public function contrato(): BelongsTo
    {
        return $this->belongsTo(Contrato::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
