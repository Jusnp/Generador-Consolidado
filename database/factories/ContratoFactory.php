<?php

namespace Database\Factories;

use App\Models\Contrato;
use App\Programas\LaMaria\LaMariaPrograma;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contrato>
 */
class ContratoFactory extends Factory
{
    protected $model = Contrato::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'programa_slug' => LaMariaPrograma::SLUG,
            'contratante_slug' => null,
            'nombre' => 'Contrato '.fake()->unique()->numerify('####'),
            'codigo' => fake()->optional()->bothify('CTR-####'),
            'vigencia_inicio' => now()->startOfYear()->toDateString(),
            'vigencia_fin' => now()->endOfYear()->toDateString(),
            'activo' => true,
        ];
    }
}
