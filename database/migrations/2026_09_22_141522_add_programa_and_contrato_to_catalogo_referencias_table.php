<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalogo_referencias', function (Blueprint $table) {
            $table->string('programa_slug', 64)->default('seguimiento-oncologico')->after('id');
            $table->foreignId('contrato_id')
                ->nullable()
                ->after('programa_slug')
                ->constrained('contratos')
                ->nullOnDelete();
        });

        DB::table('catalogo_referencias')->update([
            'programa_slug' => 'seguimiento-oncologico',
        ]);

        Schema::table('catalogo_referencias', function (Blueprint $table) {
            $table->index(['programa_slug', 'tipo', 'activo']);
        });
    }

    public function down(): void
    {
        Schema::table('catalogo_referencias', function (Blueprint $table) {
            $table->dropIndex(['programa_slug', 'tipo', 'activo']);
            $table->dropConstrainedForeignId('contrato_id');
            $table->dropColumn('programa_slug');
        });
    }
};
