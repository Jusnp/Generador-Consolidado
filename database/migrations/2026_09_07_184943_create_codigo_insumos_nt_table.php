<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('codigo_insumos_nt', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 50)->unique();
            $table->text('descripcion')->nullable();
            $table->string('nt', 10)->nullable()->index();
            $table->decimal('tarifa_unitario', 15, 4)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('codigo_insumos_nt');
    }
};
