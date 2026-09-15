<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('medication_service_classifications')) {
            Schema::create('medication_service_classifications', function (Blueprint $table) {
                $table->id();
                $table->string('descripcion_normalizada');
                $table->string('servicio');
                $table->timestamps();
                $table->unique('descripcion_normalizada', 'medsvc_desc_unique');
            });

            return;
        }

        Schema::table('medication_service_classifications', function (Blueprint $table) {
            $table->unique('descripcion_normalizada', 'medsvc_desc_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medication_service_classifications');
    }
};
