<?php

namespace App\Console\Commands;

use App\Models\CodigoMedicamentoNt;
use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportarMedicamentosNt extends Command
{
    protected $signature = 'catalogo:importar-medicamentos-nt
                            {archivo : Ruta del archivo Excel}
                            {--hoja=Medicamentos : Nombre de la hoja del catálogo}';

    protected $description = 'Importa el catálogo de medicamentos NT desde un archivo Excel';

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

        $ultimaFila = $hoja->getHighestRow();

        if ($ultimaFila < 3) {
            $this->error('La hoja Medicamentos no contiene registros.');

            return self::FAILURE;
        }

        /*
         * La hoja tiene los encabezados en la fila 2.
         *
         * A = CUMS
         * B = Nombre estadar
         * C = PERTENECE O NO A LA NT
         * D = Tarifa NT
         */
        $filaEncabezados = 2;

        $ultimaColumna = $hoja->getHighestColumn();

        $numeroColumnas =
            \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString(
                $ultimaColumna
            );

        $encabezados = [];

        for ($i = 1; $i <= $numeroColumnas; $i++) {
            $columna =
                \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(
                    $i
                );

            $encabezados[$columna] = trim(
                (string) $hoja->getCell(
                    $columna . $filaEncabezados
                )->getValue()
            );
        }

        /*
         * Creamos el mapa de columnas.
         */
        $mapa = [];

        foreach ($encabezados as $columna => $nombre) {
            if ($nombre !== '') {
                $mapa[$nombre] = $columna;
            }
        }

        /*
         * Columnas requeridas.
         */
        $columnasRequeridas = [
            'CUMS',
            'Nombre estadar',
            'PERTENECE O NO A LA NT',
            'Tarifa NT',
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
         * Los datos empiezan en la fila 3.
         */
        for ($numeroFila = 3; $numeroFila <= $ultimaFila; $numeroFila++) {

            /*
             * CUMS.
             *
             * Este será el código utilizado posteriormente
             * para encontrar la tarifa exacta.
             */
            $cums = trim(
                (string) $hoja->getCell(
                    $mapa['CUMS'] . $numeroFila
                )->getValue()
            );

            /*
             * Si no existe CUMS, ignoramos la fila.
             */
            if ($cums === '') {
                $ignorados++;
                continue;
            }

            /*
             * Nombre estandarizado.
             */
            $nombreEstandar = trim(
                (string) $hoja->getCell(
                    $mapa['Nombre estadar'] . $numeroFila
                )->getValue()
            );

            /*
             * Pertenece a la NT.
             */
            $perteneceNt = trim(
                (string) $hoja->getCell(
                    $mapa['PERTENECE O NO A LA NT'] . $numeroFila
                )->getValue()
            );

            /*
             * Tarifa NT.
             *
             * IMPORTANTE:
             *
             * Usamos getValue() y no getFormattedValue().
             *
             * Así conservamos el valor numérico real
             * almacenado en Excel.
             */
            $tarifaValor = $hoja->getCell(
                $mapa['Tarifa NT'] . $numeroFila
            )->getValue();

            $tarifa = $this->normalizarTarifa(
                $tarifaValor
            );

            /*
             * Verificamos si el CUMS ya existe.
             */
            $registroExistente = CodigoMedicamentoNt::where(
                'cums',
                $cums
            )->exists();

            /*
             * Guardamos o actualizamos.
             */
            CodigoMedicamentoNt::updateOrCreate(
                [
                    'cums' => $cums,
                ],
                [
                    'nombre_estandar' => $nombreEstandar !== ''
                        ? $nombreEstandar
                        : null,

                    'pertenece_nt' => $perteneceNt !== ''
                        ? $perteneceNt
                        : null,

                    'tarifa_nt' => $tarifa,
                ]
            );

            if ($registroExistente) {
                $actualizados++;
            } else {
                $importados++;
            }
        }

        $this->newLine();

        $this->info('==============================================');
        $this->info('   IMPORTACIÓN MEDICAMENTOS NT FINALIZADA');
        $this->info('==============================================');

        $this->line(
            "Nuevos registros: {$importados}"
        );

        $this->line(
            "Registros actualizados: {$actualizados}"
        );

        $this->line(
            "Filas ignoradas: {$ignorados}"
        );

        $total = CodigoMedicamentoNt::count();

        $this->line(
            "Total medicamentos en la base de datos: {$total}"
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
         * Si Excel entrega directamente un número,
         * conservamos el valor.
         */
        if (is_numeric($valor)) {
            return (float) $valor;
        }

        /*
         * Si llega como texto, limpiamos.
         */
        $texto = trim((string) $valor);

        if ($texto === '') {
            return null;
        }

        /*
         * Eliminamos símbolo de moneda y espacios.
         */
        $texto = str_replace(
            ['$', ' '],
            '',
            $texto
        );

        /*
         * Ejemplo:
         *
         * 1.234,56
         *
         * se convierte en:
         *
         * 1234.56
         */
        if (
            str_contains($texto, ',') &&
            str_contains($texto, '.')
        ) {
            $texto = str_replace('.', '', $texto);
            $texto = str_replace(',', '.', $texto);
        }

        /*
         * Ejemplo:
         *
         * 1234,56
         *
         * se convierte en:
         *
         * 1234.56
         */
        elseif (str_contains($texto, ',')) {
            $texto = str_replace(',', '.', $texto);
        }

        /*
         * Validamos que sea numérico.
         */
        if (!is_numeric($texto)) {
            return null;
        }

        return (float) $texto;
    }
}