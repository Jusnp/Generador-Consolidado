<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('catalogo_referencias')
            ->where('programa_slug', 'seguimiento-oncologico')
            ->update(['programa_slug' => 'la-maria']);

        DB::table('contratos')
            ->where('programa_slug', 'seguimiento-oncologico')
            ->update(['programa_slug' => 'la-maria']);

        DB::table('programa_ejecuciones')
            ->where('programa_slug', 'seguimiento-oncologico')
            ->update(['programa_slug' => 'la-maria-prostata']);
    }

    public function down(): void
    {
        DB::table('catalogo_referencias')
            ->where('programa_slug', 'la-maria')
            ->update(['programa_slug' => 'seguimiento-oncologico']);

        DB::table('contratos')
            ->where('programa_slug', 'la-maria')
            ->update(['programa_slug' => 'seguimiento-oncologico']);

        DB::table('programa_ejecuciones')
            ->whereIn('programa_slug', ['la-maria-prostata', 'la-maria-cervix'])
            ->update(['programa_slug' => 'seguimiento-oncologico']);
    }
};
