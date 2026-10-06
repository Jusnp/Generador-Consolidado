<?php

namespace App\Models;

use Database\Factories\ContratoFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contrato extends Model
{
    /** @use HasFactory<ContratoFactory> */
    use HasFactory;

    protected $fillable = [
        'programa_slug',
        'contratante_slug',
        'nombre',
        'codigo',
        'vigencia_inicio',
        'vigencia_fin',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'vigencia_inicio' => 'date',
            'vigencia_fin' => 'date',
            'activo' => 'boolean',
        ];
    }

    public function catalogosReferencia(): HasMany
    {
        return $this->hasMany(CatalogoReferencia::class);
    }

    public function ejecuciones(): HasMany
    {
        return $this->hasMany(ProgramaEjecucion::class);
    }

    /**
     * @param  Builder<Contrato>  $query
     * @return Builder<Contrato>
     */
    public function scopeForPrograma(Builder $query, string $programaSlug): Builder
    {
        return $query->where('programa_slug', $programaSlug);
    }

    /**
     * @param  Builder<Contrato>  $query
     * @return Builder<Contrato>
     */
    public function scopeActivo(Builder $query): Builder
    {
        return $query->where('activo', true);
    }

    /**
     * @param  Builder<Contrato>  $query
     * @return Builder<Contrato>
     */
    public function scopeVigenteEn(Builder $query, ?\DateTimeInterface $fecha = null): Builder
    {
        $fecha = $fecha ?? now();

        return $query
            ->whereDate('vigencia_inicio', '<=', $fecha)
            ->where(function (Builder $builder) use ($fecha): void {
                $builder
                    ->whereNull('vigencia_fin')
                    ->orWhereDate('vigencia_fin', '>=', $fecha);
            });
    }
}
