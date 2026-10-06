<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (['codigo_cups', 'codigo_medicamentos', 'codigo_medicamentos_nt', 'codigo_insumos_nt'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->boolean('activo')->default(true)->index();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['codigo_cups', 'codigo_medicamentos', 'codigo_medicamentos_nt', 'codigo_insumos_nt'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn('activo');
            });
        }
    }
};
