<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('codigo_medicamentos', function (Blueprint $table) {
            $table->id();

            $table->string('codigo', 100)->index();

            $table->string('llave', 100)->nullable();

            $table->string('nt', 20)->nullable();

            $table->string('cums_homologo', 100)->nullable();

            $table->decimal('tarifa_unitario', 20, 6)->nullable();

            $table->timestamps();

            $table->index(['codigo', 'llave']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('codigo_medicamentos');
    }
};