<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $homologations = [
            '19900859-1' => '20109427-88', '19906237-5' => '19906237-5',
            '19914377-3' => '19914377-3', '19927154-1' => '19927154-6',
            '19927446-17' => '19927446-6', '19943211-1' => '19930610-2',
            '1995521-21' => '19934768-18', '19965499-11' => '20123645-10',
            '19980029-9' => '19930964-6', '19985993-26' => '19960116-15',
            '19987925-1' => '19965794-2', '19993092-1' => '19959808-1',
            '20023909-1' => '19966222-1', '20029235-9' => '20029235-2',
            '20030167-4' => '20030167-4', '20039017-3' => '19953202-7',
            '20056052-30' => '20056052-22', '20069677-1' => '20069677-1',
            '20084373-9' => '20084373-2', '20084687-6' => '19950452-73',
            '20084690-6' => '19950452-55', '20142721-1' => '19996055-7',
            '20155046-2' => '20001633-2', '20241640-20' => '19908960-11',
            '214251-8' => '214251-8', '36123-4' => '19955429-3',
            '47547-8' => '38369-32', '20033957-11' => '20015005-11',
            '19977789-3' => '19987949-22', '20120320-6' => '20043426-2',
            '19985888-17' => '32602-2', '19944003-4' => '19944003-6',
            '19970681-1' => '16806-1', '20115061-3' => '20125451-6',
        ];

        foreach ($homologations as $codigo => $cumsHomologo) {
            $existing = DB::table('codigo_medicamentos')
                ->where('codigo', $codigo)
                ->orderBy('id')
                ->first();

            if ($existing === null) {
                DB::table('codigo_medicamentos')->insert([
                    'codigo' => $codigo,
                    'cums_homologo' => $cumsHomologo,
                    'divide_por_duplicados' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                continue;
            }

            if (trim((string) $existing->cums_homologo) === '') {
                DB::table('codigo_medicamentos')
                    ->where('id', $existing->id)
                    ->update([
                        'cums_homologo' => $cumsHomologo,
                        'updated_at' => now(),
                    ]);
            }
        }

        $tarifa = DB::table('codigo_medicamentos')
            ->where('codigo', '20120320-6')
            ->orderBy('id')
            ->first();

        if ($tarifa !== null && (float) ($tarifa->tarifa_unitario ?? 0) <= 0) {
            DB::table('codigo_medicamentos')
                ->where('id', $tarifa->id)
                ->update([
                    'tarifa_unitario' => 9808.16,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Los registros de catálogo son datos operativos y no se eliminan al revertir código.
    }
};
