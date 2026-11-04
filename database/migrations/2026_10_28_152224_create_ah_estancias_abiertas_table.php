<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estancias de cada versión de un cierre hospitalario. El paciente se guarda
 * solo como clave HMAC con un secreto propio; nunca el documento en claro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ah_estancias_abiertas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('antecedente_id')->constrained('ah_antecedentes')->restrictOnDelete();
            $table->string('clave_paciente', 64);
            $table->string('autorizacion', 80);
            $table->string('cod_prestador', 30);
            // Fechas en el formato RIPS (aaaa-mm-dd hh:mm).
            $table->string('ingreso_original', 20);
            $table->string('inicio_tramo', 20);
            $table->string('fin_corte', 20);
            $table->string('destino_final', 2);
            $table->unsignedSmallInteger('cortes_08');
            $table->unsignedSmallInteger('bloques_reconocidos');
            $table->json('bloques_periodo');
            $table->json('tramos_origen');
            $table->string('estado', 40);
            $table->string('continuidad', 60);
            $table->unsignedSmallInteger('rectifica_version')->nullable();
            $table->timestamps();

            $table->index(['antecedente_id', 'clave_paciente']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ah_estancias_abiertas');
    }
};
