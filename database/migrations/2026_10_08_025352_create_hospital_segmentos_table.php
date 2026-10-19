<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Segmentos de hospitalización de La María persistidos entre ejecuciones. La
 * identidad es de procedencia (ruta, prestador, factura, paciente y consecutivo
 * del servicio), nunca un hash de contenido; cada corrección es una versión
 * nueva y nada se borra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hospital_segmentos', function (Blueprint $table) {
            $table->id();
            $table->string('programa_slug', 40);
            $table->string('ruta', 20);
            $table->string('prestador', 30);
            $table->string('num_factura', 40);
            $table->string('tipo_documento', 5);
            $table->string('num_documento', 30);
            $table->string('consecutivo_servicio', 30);
            $table->string('identidad', 191);
            $table->unsignedSmallInteger('version');
            // Igual a identidad mientras la versión está VIGENTE; null en las demás.
            $table->string('identidad_vigente', 191)->nullable()->unique();
            $table->dateTime('fecha_ingreso')->nullable();
            $table->dateTime('fecha_egreso')->nullable();
            $table->dateTime('fecha_corte')->nullable();
            $table->string('fecha_ingreso_rips', 30);
            $table->string('fecha_egreso_rips', 30);
            $table->string('condicion_destino', 2);
            // Evidencia de continuidad: número de autorización y prestador del servicio (codPrestador).
            $table->string('num_autorizacion', 40)->nullable();
            $table->string('cod_prestador_servicio', 30)->nullable();
            $table->char('hash_contenido', 64);
            $table->string('estado', 20);
            $table->foreignId('reemplaza_segmento_id')->nullable()->constrained('hospital_segmentos')->restrictOnDelete();
            $table->string('estado_continuidad', 40)->nullable();
            $table->string('archivo_origen', 255)->nullable();
            $table->string('ejecucion_origen', 100);
            $table->foreignId('programa_ejecucion_id')->nullable()->constrained('programa_ejecuciones')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['identidad', 'version']);
            $table->index(['programa_slug', 'ruta', 'tipo_documento', 'num_documento'], 'hospital_segmentos_paciente_index');
            $table->index(['num_documento', 'fecha_ingreso']);
            $table->index('estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hospital_segmentos');
    }
};
