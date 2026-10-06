<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalogo_referencia_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalogo_referencia_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('codigo', 100);
            $table->string('ruta', 20)->nullable();
            $table->string('categoria', 100)->nullable();
            $table->text('descripcion')->nullable();
            $table->decimal('tarifa_referencia', 20, 4)->nullable();
            $table->json('metadatos')->nullable();
            $table->timestamps();

            $table->index(['catalogo_referencia_id', 'codigo']);
            $table->index(['ruta', 'categoria']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalogo_referencia_items');
    }
};
