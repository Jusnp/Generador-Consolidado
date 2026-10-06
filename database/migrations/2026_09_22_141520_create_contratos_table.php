<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contratos', function (Blueprint $table) {
            $table->id();
            $table->string('programa_slug', 64);
            $table->string('contratante_slug', 64)->nullable();
            $table->string('nombre');
            $table->string('codigo', 100)->nullable();
            $table->date('vigencia_inicio');
            $table->date('vigencia_fin')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->index(['programa_slug', 'activo']);
            $table->index(['contratante_slug', 'activo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contratos');
    }
};
