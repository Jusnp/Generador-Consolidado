<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CatalogoReferencia extends Model
{
    protected $fillable = [
        'programa_slug',
        'contrato_id',
        'tipo',
        'version',
        'archivo_origen',
        'fecha_referencia',
        'activo',
        'imported_by',
    ];

    protected $attributes = [
        'programa_slug' => 'la-maria',
    ];

    protected function casts(): array
    {
        return [
            'fecha_referencia' => 'date',
            'activo' => 'boolean',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(CatalogoReferenciaItem::class);
    }

    public function importedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function contrato(): BelongsTo
    {
        return $this->belongsTo(Contrato::class);
    }

    /**
     * @param  Builder<CatalogoReferencia>  $query
     * @return Builder<CatalogoReferencia>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('activo', true);
    }

    /**
     * @param  Builder<CatalogoReferencia>  $query
     * @return Builder<CatalogoReferencia>
     */
    public function scopeForPrograma(Builder $query, string $programaSlug): Builder
    {
        return $query->where('programa_slug', $programaSlug);
    }
}
