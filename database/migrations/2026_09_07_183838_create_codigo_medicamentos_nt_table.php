<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('codigo_medicamentos_nt', function (Blueprint $table) {
            $table->id();
            $table->string('cums', 100)->unique();
            $table->text('nombre_estandar')->nullable();
            $table->string('pertenece_nt', 20)->nullable()->index();
            $table->decimal('tarifa_nt', 20, 6)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('codigo_medicamentos_nt');
    }
};
