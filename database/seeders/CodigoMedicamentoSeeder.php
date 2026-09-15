<?php

namespace Database\Seeders;

use App\Models\CodigoMedicamento;
use Illuminate\Database\Seeder;

class CodigoMedicamentoSeeder extends Seeder
{
    public function run(): void
    {
        $path = storage_path('app/codigos_med.csv');

        if (! is_file($path)) {
            throw new \RuntimeException(
                'No se encontró el archivo codigos_med.csv en storage/app.'
            );
        }

        if (filesize($path) === 0) {
            throw new \RuntimeException(
                'codigos_med.csv está vacío.'
            );
        }

        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new \RuntimeException(
                'No se pudo abrir codigos_med.csv.'
            );
        }

        // Leer encabezados
        $header = fgetcsv($handle, 0, ',');

        if ($header === false) {
            fclose($handle);

            throw new \RuntimeException(
                'No se pudo leer el encabezado de codigos_med.csv.'
            );
        }

        // Quitar BOM UTF-8 y normalizar encabezados
        $header = array_map(function ($value) {
            $value = (string) $value;

            // Eliminar BOM UTF-8
            $value = preg_replace('/^\xEF\xBB\xBF/', '', $value);

            return strtoupper(trim($value));
        }, $header);

        // Buscar las columnas por nombre
        $indices = [];

        foreach ($header as $index => $name) {
            $indices[$name] = $index;
        }

        $requiredColumns = [
            'CODIGO',
            'LLAVE',
            'NT',
            'CUMS HOMOLOGO',
            'Tarifa UNITARIO',
        ];

        // Tarifa UNITARIO puede venir con mayúsculas/minúsculas distintas.
        // Buscamos de forma flexible.
        $tarifaIndex = null;

        foreach ($header as $index => $name) {
            $normalized = strtoupper(trim($name));

            if (
                $normalized === 'TARIFA UNITARIO' ||
                $normalized === 'TARIFA UNITARIO ' ||
                $normalized === 'TARIFA UNITARIO'
            ) {
                $tarifaIndex = $index;
                break;
            }
        }

        if (! isset($indices['CODIGO'])) {
            fclose($handle);

            throw new \RuntimeException(
                'No se encontró la columna CODIGO en codigos_med.csv.'
            );
        }

        if (! isset($indices['LLAVE'])) {
            fclose($handle);

            throw new \RuntimeException(
                'No se encontró la columna LLAVE en codigos_med.csv.'
            );
        }

        if (! isset($indices['NT'])) {
            fclose($handle);

            throw new \RuntimeException(
                'No se encontró la columna NT en codigos_med.csv.'
            );
        }

        if (! isset($indices['CUMS HOMOLOGO'])) {
            fclose($handle);

            throw new \RuntimeException(
                'No se encontró la columna CUMS HOMOLOGO en codigos_med.csv.'
            );
        }

        if ($tarifaIndex === null) {
            fclose($handle);

            throw new \RuntimeException(
                'No se encontró la columna Tarifa UNITARIO en codigos_med.csv.'
            );
        }

        $insertados = 0;
        $ignorados = 0;

        while (($row = fgetcsv($handle, 0, ',')) !== false) {

            // Saltar filas completamente vacías
            if (
                count($row) === 0 ||
                (
                    count($row) === 1 &&
                    trim((string) $row[0]) === ''
                )
            ) {
                continue;
            }

            $codigo = isset($row[$indices['CODIGO']])
                ? trim((string) $row[$indices['CODIGO']])
                : '';

            $llave = isset($row[$indices['LLAVE']])
                ? trim((string) $row[$indices['LLAVE']])
                : '';

            $nt = isset($row[$indices['NT']])
                ? trim((string) $row[$indices['NT']])
                : '';

            $cumsHomologo = isset($row[$indices['CUMS HOMOLOGO']])
                ? trim((string) $row[$indices['CUMS HOMOLOGO']])
                : '';

            $tarifaRaw = isset($row[$tarifaIndex])
                ? trim((string) $row[$tarifaIndex])
                : '';

            $dividePorDuplicados = true;

            if (isset($indices['DIVIDE POR DUPLICADOS'])) {
                $regla = strtoupper(trim((string) (
                    $row[$indices['DIVIDE POR DUPLICADOS']] ?? ''
                )));

                $dividePorDuplicados = ! in_array(
                    $regla,
                    ['NO', 'N', '0', 'FALSE'],
                    true
                );
            }

            // Si no hay código, no podemos crear el registro
            if ($codigo === '') {
                $ignorados++;

                continue;
            }

            // Normalizar NT
            $ntUpper = strtoupper($nt);

            if ($ntUpper === 'SI') {
                $nt = 'SI';
            } elseif ($ntUpper === 'NO') {
                $nt = 'NO';
            } elseif ($ntUpper === '') {
                $nt = null;
            }

            // Convertir tarifa
            $tarifa = null;

            if (
                $tarifaRaw !== '' &&
                strtoupper($tarifaRaw) !== '#N/D' &&
                strtoupper($tarifaRaw) !== '#N/A'
            ) {
                // Quitar símbolos de moneda y espacios
                $tarifaRaw = str_replace(
                    ['$ ', '$', ' '],
                    '',
                    $tarifaRaw
                );

                // El CSV generado usa valores como:
                // 39589.0
                // 66479.0
                // 1473308.0
                //
                // También soportamos valores con puntos de miles.
                if (substr_count($tarifaRaw, '.') > 1) {
                    $tarifaRaw = str_replace('.', '', $tarifaRaw);
                }

                if (is_numeric($tarifaRaw)) {
                    $tarifa = (float) $tarifaRaw;
                }
            }

            CodigoMedicamento::updateOrCreate(
                [
                    'codigo' => $codigo,
                ],
                [
                    'llave' => $llave !== '' ? $llave : null,
                    'nt' => $nt,
                    'cums_homologo' => $cumsHomologo !== ''
                        ? $cumsHomologo
                        : null,
                    'tarifa_unitario' => $tarifa,
                    'divide_por_duplicados' => $dividePorDuplicados,
                ]
            );

            $insertados++;
        }

        fclose($handle);

        $this->command?->info(
            "Registros procesados: {$insertados}"
        );

        $this->command?->info(
            "Registros ignorados: {$ignorados}"
        );
    }
}
