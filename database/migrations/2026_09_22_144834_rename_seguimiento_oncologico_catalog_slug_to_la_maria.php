<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Renombra el slug histórico único `seguimiento-oncologico`.
     *
     * Evidencia de que las ejecuciones históricas van a próstata:
     * - En main (v1.0) no existía `programa_ejecuciones` ni slug de cérvix.
     * - `seguimiento-oncologico` era un solo programa; no había ejecuciones
     *   separadas próstata/cérvix bajo ese slug.
     * - `la-maria-cervix` aparece solo tras el hub La María; cualquier fila
     *   previa con `seguimiento-oncologico` pertenece a la ejecución única
     *   anterior, que se consolidó como próstata (`la-maria-prostata`).
     * - Catálogos/contratos van al hub `la-maria` (compartidos).
     * - El down() revierte ambos slugs de ejecución porque, tras migrar,
     *   pueden existir corridas posteriores de cérvix que también deben
     *   volver al slug histórico único.
     */
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
