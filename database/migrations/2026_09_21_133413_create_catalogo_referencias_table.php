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
        Schema::create('catalogo_referencias', function (Blueprint $table) {
            $table->id();

            $table->string('tipo', 40);
            $table->string('version', 100);
            $table->string('archivo_origen');
            $table->date('fecha_referencia')->nullable();
            $table->boolean('activo')->default(false);
            $table->foreignId('imported_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();

            $table->index(['tipo', 'activo']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('catalogo_referencias');
    }
};
