<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cierres hospitalarios de La María por ruta y período (antecedente del mes
 * siguiente). El contenido de una versión no se modifica: una corrección es
 * una versión nueva que reemplaza a la anterior. Solo cambian, en la misma
 * transacción, el estado y clave_vigente de la versión reemplazada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ah_antecedentes', function (Blueprint $table) {
            $table->id();
            $table->string('programa_slug', 40);
            $table->string('ruta', 20);
            $table->char('periodo', 7);
            $table->unsignedSmallInteger('version');
            // ARRANQUE (primer período, sin antecedente) o DEFINITIVO.
            $table->string('tipo', 20);
            // VIGENTE o REEMPLAZADO.
            $table->string('estado', 20);
            // programa|ruta|periodo mientras la versión está VIGENTE; null en las demás.
            $table->string('clave_vigente', 80)->nullable()->unique();
            $table->char('hash_contenido', 64);
            $table->char('hash_origen', 64);
            $table->json('origen');
            $table->string('version_reglas', 120);
            // HMAC fijo con el secreto del historial: detecta un secreto distinto sin guardarlo.
            $table->char('huella_secreto', 16);
            $table->foreignId('antecedente_id')->nullable()->constrained('ah_antecedentes')->restrictOnDelete();
            $table->char('antecedente_hash', 64)->nullable();
            $table->foreignId('reemplaza_id')->nullable()->constrained('ah_antecedentes')->restrictOnDelete();
            $table->text('motivo')->nullable();
            // Cómo se estableció que el período estaba completo: CONFIRMADA_POR_OPERADOR (lista --factura sin fuente independiente).
            $table->string('completitud', 40);
            $table->foreignId('programa_ejecucion_id')->nullable()->constrained('programa_ejecuciones')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['programa_slug', 'ruta', 'periodo', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ah_antecedentes');
    }
};
