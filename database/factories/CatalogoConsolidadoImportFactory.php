<?php

namespace Database\Factories;

use App\Models\CatalogoConsolidadoImport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CatalogoConsolidadoImport>
 */
class CatalogoConsolidadoImportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tipo' => 'CUPS_CONTRATO',
            'version' => fake()->date('Y-m-d'),
            'archivo_origen' => 'tarifario_cups.xlsx',
            'archivo_almacenado' => 'catalogos-consolidado/tarifario_cups.xlsx',
            'registros_procesados' => fake()->numberBetween(1, 500),
            'registros_nuevos' => fake()->numberBetween(0, 100),
            'registros_actualizados' => fake()->numberBetween(0, 300),
            'registros_ignorados' => 0,
            'imported_by' => User::factory(),
        ];
    }
}
