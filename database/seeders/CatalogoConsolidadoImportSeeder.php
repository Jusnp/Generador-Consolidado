<?php

namespace Database\Seeders;

use App\Models\CatalogoConsolidadoImport;
use Illuminate\Database\Seeder;

class CatalogoConsolidadoImportSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        CatalogoConsolidadoImport::factory()->count(3)->create();
    }
}
