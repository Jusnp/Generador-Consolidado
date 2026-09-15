<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('codigo_medicamentos', 'divide_por_duplicados')) {
            Schema::table('codigo_medicamentos', function (Blueprint $table) {
                $table->boolean('divide_por_duplicados')->default(true);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('codigo_medicamentos', 'divide_por_duplicados')) {
            Schema::table('codigo_medicamentos', function (Blueprint $table) {
                $table->dropColumn('divide_por_duplicados');
            });
        }
    }
};
