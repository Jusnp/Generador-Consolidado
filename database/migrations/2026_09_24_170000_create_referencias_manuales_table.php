<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referencias_manuales', function (Blueprint $table) {
            $table->id();
            $table->string('programa_slug', 80);
            $table->string('ruta', 40)->nullable();
            $table->string('tipo', 40);
            $table->string('codigo', 120)->nullable();
            $table->string('nombre');
            $table->string('nombre_normalizado');
            $table->decimal('tarifa', 20, 4);
            $table->boolean('activo')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['programa_slug', 'ruta', 'activo']);
            $table->unique(['programa_slug', 'ruta', 'codigo'], 'ref_manual_programa_ruta_codigo_uq');
            $table->unique(['programa_slug', 'ruta', 'nombre_normalizado'], 'ref_manual_programa_ruta_nombre_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referencias_manuales');
    }
};
