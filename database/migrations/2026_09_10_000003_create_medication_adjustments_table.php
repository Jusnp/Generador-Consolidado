<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medication_adjustments', function (Blueprint $table) {
            $table->id();
            $table->string('documento', 50);
            $table->string('regimen', 20);
            $table->string('codigo', 100);
            $table->date('fecha_servicio');
            $table->unsignedSmallInteger('ocurrencia')->default(1);
            $table->decimal('valor_total_override', 20, 6);
            $table->string('motivo', 500);
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->unique(
                ['documento', 'regimen', 'codigo', 'fecha_servicio', 'ocurrencia'],
                'medication_adjustments_identity_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medication_adjustments');
    }
};
