<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalogo_referencia_items', function (Blueprint $table): void {
            $table->boolean('activo')->default(true)->after('tarifa_referencia');
            $table->index(['catalogo_referencia_id', 'activo']);
        });
    }

    public function down(): void
    {
        Schema::table('catalogo_referencia_items', function (Blueprint $table): void {
            $table->dropIndex(['catalogo_referencia_id', 'activo']);
            $table->dropColumn('activo');
        });
    }
};
