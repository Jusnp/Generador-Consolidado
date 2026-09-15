<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // La migración anterior ya crea todas las columnas de esta tabla.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Sin cambios: las columnas pertenecen a la migración de creación.
    }
};
