<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('medication_adjustments', 'regimen')) {
            return;
        }

        Schema::table('medication_adjustments', function (Blueprint $table) {
            $table->dropUnique('medication_adjustments_identity_unique');
            $table->string('regimen', 20)->default('');
            $table->unique(
                ['documento', 'regimen', 'codigo', 'fecha_servicio', 'ocurrencia'],
                'medication_adjustments_identity_unique'
            );
        });
    }

    public function down(): void
    {
        // Se conserva la columna para no perder ajustes operativos existentes.
    }
};
