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
        Schema::create('catalogo_consolidado_imports', function (Blueprint $table) {
            $table->id();
            $table->string('tipo', 40);
            $table->string('version', 100);
            $table->string('archivo_origen');
            $table->string('archivo_almacenado');
            $table->unsignedInteger('registros_procesados')->default(0);
            $table->unsignedInteger('registros_nuevos')->default(0);
            $table->unsignedInteger('registros_actualizados')->default(0);
            $table->unsignedInteger('registros_ignorados')->default(0);
            $table->foreignId('imported_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();

            $table->index(['tipo', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('catalogo_consolidado_imports');
    }
};
