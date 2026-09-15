<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('codigo_cups', function (Blueprint $table) {
            $table->id();

            $table->string('codigo', 20)
                ->unique();

            $table->string('tipo_servicio', 100)
                ->nullable();

            $table->text('descripcion')
                ->nullable();

            $table->decimal('tarifa_2025', 15, 4)
                ->nullable();

            $table->timestamps();

            $table->index('tipo_servicio');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('codigo_cups');
    }
};