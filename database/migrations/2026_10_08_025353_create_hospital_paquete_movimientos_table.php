<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Libro de movimientos de paquetes hospitalarios de La María: +1 reconoce un
 * bloque de 600 horas y −1 lo revierte. El estado económico es la suma de los
 * movimientos por mes contable. bloque_vigente (único) impide reconocer dos
 * veces el mismo bloque y clave_idempotencia (única) impide registrar dos veces
 * el mismo movimiento. Las filas no se borran.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hospital_paquete_movimientos', function (Blueprint $table) {
            $table->id();
            $table->string('programa_slug', 40);
            $table->string('ruta', 20);
            $table->string('tipo_documento', 5);
            $table->string('num_documento', 30);
            $table->string('episodio_clave', 191)->nullable();
            $table->foreignId('segmento_ancla_id')->nullable()->constrained('hospital_segmentos')->restrictOnDelete();
            $table->json('segmentos')->nullable();
            $table->dateTime('bloque_inicio');
            $table->unsignedSmallInteger('numero_bloque')->nullable();
            $table->char('mes_contable', 7);
            $table->tinyInteger('signo');
            $table->decimal('valor', 16, 2);
            $table->decimal('tarifa_paquete', 16, 2);
            $table->string('motivo', 40);
            $table->string('estado_episodio', 40)->nullable();
            $table->foreignId('movimiento_revertido_id')->nullable()->constrained('hospital_paquete_movimientos')->restrictOnDelete();
            $table->string('bloque_vigente', 191)->nullable()->unique();
            $table->string('clave_idempotencia', 191)->unique();
            $table->string('ejecucion_origen', 100);
            $table->foreignId('programa_ejecucion_id')->nullable()->constrained('programa_ejecuciones')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['ruta', 'mes_contable']);
            $table->index(['ruta', 'tipo_documento', 'num_documento'], 'hospital_movimientos_paciente_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hospital_paquete_movimientos');
    }
};
