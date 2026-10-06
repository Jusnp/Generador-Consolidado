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
        Schema::table('catalogo_referencia_items', fn (Blueprint $table) => $table->string('hoja', 255)->nullable()->after('codigo')->index());
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('catalogo_referencia_items', fn (Blueprint $table) => $table->dropColumn('hoja'));
    }
};
