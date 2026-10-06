<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('programa_ejecuciones', function (Blueprint $table) {
            $table->id();
            $table->string('programa_slug', 64);
            $table->foreignId('contrato_id')
                ->nullable()
                ->constrained('contratos')
                ->nullOnDelete();
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('periodo', 7);
            $table->uuid('batch_id');
            $table->string('archivo_salida');
            $table->json('catalogos_aplicados')->nullable();
            $table->unsignedInteger('archivos_procesados')->default(0);
            $table->timestamps();

            $table->index(['programa_slug', 'periodo']);
            $table->unique('batch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('programa_ejecuciones');
    }
};
