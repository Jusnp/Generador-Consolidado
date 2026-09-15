<?php

namespace App\Console\Commands;

use App\Models\CodigoCups;
use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportarCups extends Command
{
    protected $signature = 'catalogo:importar-cups
                            {archivo : Ruta del archivo Excel}
                            {--hoja=CUPS : Nombre de la hoja que contiene el catálogo}';

    protected $description = 'Importa el catálogo CUPS desde un archivo Excel';

    public function handle(): int
    {
        $archivo = $this->argument('archivo');
        $hojaNombre = $this->option('hoja');

        if (!file_exists($archivo)) {
            $this->error('No se encontró el archivo:');
            $this->error($archivo);

            return self::FAILURE;
        }

        $this->info('Leyendo archivo:');
        $this->info($archivo);

        try {
            $spreadsheet = IOFactory::load($archivo);
        } catch (\Throwable $e) {
            $this->error('No fue posible abrir el archivo Excel.');
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (!$spreadsheet->sheetNameExists($hojaNombre)) {
            $this->error("No existe la hoja '{$hojaNombre}' en el archivo.");

            $this->info('Hojas disponibles:');

            foreach ($spreadsheet->getSheetNames() as $nombre) {
                $this->line("- {$nombre}");
            }

            return self::FAILURE;
        }

        $hoja = $spreadsheet->getSheetByName($hojaNombre);

        /*
         * Obtenemos la última fila utilizada.
         */
        $ultimaFila = $hoja->getHighestRow();

        if ($ultimaFila < 2) {
            $this->error('La hoja CUPS está vacía.');

            return self::FAILURE;
        }

        /*
         * Leemos los encabezados directamente desde la primera fila.
         */
        $ultimaColumna = $hoja->getHighestColumn();

        $encabezados = [];

        for (
            $columna = 1;
            $columna <= \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString(
                $ultimaColumna
            );
            $columna++
        ) {
            $letraColumna =
                \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(
                    $columna
                );

            $encabezados[$letraColumna] = trim(
                (string) $hoja->getCell(
                    $letraColumna . '1'
                )->getValue()
            );
        }

        /*
         * Creamos el mapa:
         *
         * CUPS              => A
         * Tipo Servicio     => B
         * Descripción...    => C
         * Tarifa 2025       => H
         */
        $mapa = [];

        foreach ($encabezados as $columna => $nombre) {
            if ($nombre !== '') {
                $mapa[$nombre] = $columna;
            }
        }

        /*
         * Columnas obligatorias.
         */
        $columnasRequeridas = [
            'CUPS',
            'Tipo Servicio',
            'Descripción Servicio',
            'Tarifa 2025',
        ];

        foreach ($columnasRequeridas as $columna) {
            if (!isset($mapa[$columna])) {
                $this->error(
                    "No se encontró la columna requerida: {$columna}"
                );

                $this->info('Columnas encontradas:');

                foreach (array_keys($mapa) as $nombre) {
                    $this->line("- {$nombre}");
                }

                return self::FAILURE;
            }
        }

        $importados = 0;
        $actualizados = 0;
        $ignorados = 0;

        $this->info('Comenzando importación...');
        $this->newLine();

        /*
         * Recorremos las filas directamente.
         *
         * IMPORTANTE:
         * No usamos toArray() aquí.
         *
         * getValue() obtiene el valor interno real
         * de la celda Excel.
         */
        for ($numeroFila = 2; $numeroFila <= $ultimaFila; $numeroFila++) {

            /*
             * Código CUPS.
             */
            $codigo = trim(
                (string) $hoja->getCell(
                    $mapa['CUPS'] . $numeroFila
                )->getValue()
            );

            /*
             * Si no hay código, ignoramos la fila.
             */
            if ($codigo === '') {
                $ignorados++;
                continue;
            }

            /*
             * Tipo de servicio.
             */
            $tipoServicio = trim(
                (string) $hoja->getCell(
                    $mapa['Tipo Servicio'] . $numeroFila
                )->getValue()
            );

            /*
             * Descripción.
             */
            $descripcion = trim(
                (string) $hoja->getCell(
                    $mapa['Descripción Servicio'] . $numeroFila
                )->getValue()
            );

            /*
             * TARIFA.
             *
             * Aquí está la corrección principal.
             *
             * getValue() devuelve, por ejemplo:
             *
             * 12575.3001244665
             *
             * y NO:
             *
             * $ 12,575
             */
            $tarifaTexto = $hoja->getCell(
                $mapa['Tarifa 2025'] . $numeroFila
            )->getValue();

            $tarifa = $this->normalizarTarifa(
                $tarifaTexto
            );

            /*
             * Verificamos si el CUPS ya existe.
             */
            $registroExistente = CodigoCups::where(
                'codigo',
                $codigo
            )->exists();

            /*
             * Guardamos el registro.
             */
            CodigoCups::updateOrCreate(
                [
                    'codigo' => $codigo,
                ],
                [
                    'tipo_servicio' => $tipoServicio !== ''
                        ? $tipoServicio
                        : null,

                    'descripcion' => $descripcion !== ''
                        ? $descripcion
                        : null,

                    'tarifa_2025' => $tarifa,
                ]
            );

            if ($registroExistente) {
                $actualizados++;
            } else {
                $importados++;
            }
        }

        $this->newLine();

        $this->info('======================================');
        $this->info('     IMPORTACIÓN CUPS FINALIZADA');
        $this->info('======================================');

        $this->line(
            "Nuevos registros: {$importados}"
        );

        $this->line(
            "Registros actualizados: {$actualizados}"
        );

        $this->line(
            "Filas ignoradas: {$ignorados}"
        );

        $total = CodigoCups::count();

        $this->line(
            "Total CUPS en la base de datos: {$total}"
        );

        return self::SUCCESS;
    }

    /**
     * Normaliza la tarifa proveniente del Excel.
     */
    private function normalizarTarifa(mixed $valor): ?float
    {
        /*
         * Celda vacía.
         */
        if ($valor === null || $valor === '') {
            return null;
        }

        /*
         * Si PhpSpreadsheet entrega directamente
         * un número, lo conservamos.
         */
        if (is_numeric($valor)) {
            return (float) $valor;
        }

        /*
         * Si por alguna razón llega como texto,
         * hacemos una conversión segura.
         */
        $texto = trim((string) $valor);

        if ($texto === '') {
            return null;
        }

        /*
         * Eliminamos símbolos monetarios.
         */
        $texto = str_replace(
            ['$', ' '],
            '',
            $texto
        );

        /*
         * Si tiene punto y coma:
         *
         * 12.575,30
         *
         * se convierte en:
         *
         * 12575.30
         */
        if (
            str_contains($texto, ',') &&
            str_contains($texto, '.')
        ) {
            $texto = str_replace('.', '', $texto);
            $texto = str_replace(',', '.', $texto);
        }

        /*
         * Si solamente tiene coma:
         *
         * 12575,30
         *
         * se convierte en:
         *
         * 12575.30
         */
        elseif (str_contains($texto, ',')) {
            $texto = str_replace(',', '.', $texto);
        }

        /*
         * Validamos.
         */
        if (!is_numeric($texto)) {
            return null;
        }

        return (float) $texto;
    }
}