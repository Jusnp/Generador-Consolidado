<?php

namespace App\Console\Commands;

use App\Models\CodigoCups;
use App\Services\CatalogoConsolidadoImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportarCups extends Command
{
    protected $signature = 'catalogo:importar-cups
                            {archivo : Ruta del archivo Excel}
                            {--hoja=CUPS : Nombre de la hoja que contiene el catálogo}';

    protected $description = 'Importa el catálogo CUPS desde un archivo Excel';

    public function handle(CatalogoConsolidadoImporter $importador): int
    {
        $archivo = $this->argument('archivo');
        $hojaNombre = $this->option('hoja');

        if (! file_exists($archivo)) {
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

        if (! $spreadsheet->sheetNameExists($hojaNombre)) {
            $this->error("No existe la hoja '{$hojaNombre}' en el archivo.");

            $this->info('Hojas disponibles:');

            foreach ($spreadsheet->getSheetNames() as $nombre) {
                $this->line("- {$nombre}");
            }

            return self::FAILURE;
        }

        $hoja = $spreadsheet->getSheetByName($hojaNombre);

        if ($hoja->getHighestRow() < 2) {
            $this->error('La hoja CUPS está vacía.');

            return self::FAILURE;
        }

        $this->info('Comenzando importación...');
        $this->newLine();

        /*
         * Misma regla que la carga web del tarifario: solo una hoja que clasifica
         * el tipo de servicio alimenta codigo_cups. Así la hoja de insumos del mismo
         * libro (que también trae código y tarifa) nunca reemplaza una tarifa CUPS.
         * Las fórmulas se leen por su valor calculado, igual que en la carga web.
         */
        $estadisticas = DB::transaction(fn (): array => $importador->importCupsSheet(
            $hojaNombre,
            $hoja->toArray(null, true, false, false)
        ));

        if ($estadisticas['processed'] === 0) {
            $this->error("La hoja '{$hojaNombre}' no es un tarifario CUPS: se requieren columnas de código, tipo de servicio y tarifa.");
            $this->error('No se modificó ninguna tarifa.');

            return self::FAILURE;
        }

        $this->newLine();

        $this->info('======================================');
        $this->info('     IMPORTACIÓN CUPS FINALIZADA');
        $this->info('======================================');

        $this->line("Nuevos registros: {$estadisticas['created']}");
        $this->line("Registros actualizados: {$estadisticas['updated']}");
        $this->line("Filas ignoradas: {$estadisticas['ignored']}");
        $this->line('Tarifas vigentes conservadas (celda vacía o no numérica): '.count($estadisticas['rates_kept']));
        $this->line('Códigos sin tarifa válida: '.count($estadisticas['rates_missing']));
        $this->line('Total CUPS en la base de datos: '.CodigoCups::count());

        if ($estadisticas['rates_kept'] !== []) {
            $this->warn('Se conservó la tarifa vigente y su hoja de origen para: '.implode(', ', $estadisticas['rates_kept']));
        }

        if ($estadisticas['rates_missing'] !== []) {
            $this->warn('Revise el tarifario: no se asignó ninguna tarifa a '.implode(', ', $estadisticas['rates_missing']));
        }

        return self::SUCCESS;
    }
}
