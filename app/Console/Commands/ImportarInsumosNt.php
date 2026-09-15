<?php

namespace App\Console\Commands;

use App\Models\CodigoInsumoNt;
use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportarInsumosNt extends Command
{
    protected $signature = 'catalogo:importar-insumos-nt
                            {archivo : Ruta del archivo Excel}
                            {--hoja=INSUMOS : Nombre de la hoja del catálogo}';

    protected $description = 'Importa el catálogo de insumos NT desde un archivo Excel';

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

        if ($ultimaFila < 2) {
            $this->error('La hoja INSUMOS no contiene registros.');

            return self::FAILURE;
        }

        /*
         * Los encabezados están en la fila 1:
         *
         * A = CODIGO
         * B = DESCRIPCION
         * C = NT
         * D = Tarifa UNITARIO
         */
        $filaEncabezados = 1;

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
            'CODIGO',
            'DESCRIPCION',
            'NT',
            'Tarifa UNITARIO',
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
         * Los datos comienzan en la fila 2.
         */
        for ($numeroFila = 2; $numeroFila <= $ultimaFila; $numeroFila++) {

            /*
             * Código del insumo.
             */
            $codigo = trim(
                (string) $hoja->getCell(
                    $mapa['CODIGO'] . $numeroFila
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
             * Descripción.
             */
            $descripcion = trim(
                (string) $hoja->getCell(
                    $mapa['DESCRIPCION'] . $numeroFila
                )->getValue()
            );

            /*
             * Indicador NT.
             */
            $nt = trim(
                (string) $hoja->getCell(
                    $mapa['NT'] . $numeroFila
                )->getValue()
            );

            /*
             * Tarifa unitaria.
             *
             * Se obtiene el valor real de Excel mediante getValue().
             */
            $tarifaValor = $hoja->getCell(
                $mapa['Tarifa UNITARIO'] . $numeroFila
            )->getValue();

            $tarifa = $this->normalizarTarifa(
                $tarifaValor
            );

            /*
             * Verificamos si el código ya existe.
             */
            $registroExistente = CodigoInsumoNt::where(
                'codigo',
                $codigo
            )->exists();

            /*
             * Un código repetido en el catálogo no crea
             * un registro adicional.
             *
             * Se conserva un único registro por código.
             */
            CodigoInsumoNt::updateOrCreate(
                [
                    'codigo' => $codigo,
                ],
                [
                    'descripcion' => $descripcion !== ''
                        ? $descripcion
                        : null,

                    'nt' => $nt !== ''
                        ? $nt
                        : null,

                    'tarifa_unitario' => $tarifa,
                ]
            );

            if ($registroExistente) {
                $actualizados++;
            } else {
                $importados++;
            }
        }

        $this->newLine();

        $this->info('==========================================');
        $this->info('     IMPORTACIÓN INSUMOS NT FINALIZADA');
        $this->info('==========================================');

        $this->line(
            "Nuevos registros: {$importados}"
        );

        $this->line(
            "Registros actualizados: {$actualizados}"
        );

        $this->line(
            "Filas ignoradas: {$ignorados}"
        );

        $total = CodigoInsumoNt::count();

        $this->line(
            "Total códigos de insumos en la base de datos: {$total}"
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
         * Formato:
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
         * Formato:
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