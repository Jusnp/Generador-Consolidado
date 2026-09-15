<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();

            // Usuario que realizó la acción
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Tipo de actividad
            $table->string('action', 50);

            // Descripción legible de la actividad
            $table->text('description')->nullable();

            // Información adicional de la actividad
            $table->json('metadata')->nullable();

            // Dirección IP desde donde se realizó la acción
            $table->string('ip_address', 45)->nullable();

            // Navegador/dispositivo utilizado
            $table->text('user_agent')->nullable();

            $table->timestamps();

            // Facilita búsquedas por usuario y actividad
            $table->index(['user_id', 'action']);

            // Facilita consultas por fecha
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};