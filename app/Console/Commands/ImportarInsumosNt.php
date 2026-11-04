<?php

namespace App\Console\Commands;

use App\Models\CodigoInsumoNt;
use App\Services\CatalogoConsolidadoImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportarInsumosNt extends Command
{
    protected $signature = 'catalogo:importar-insumos-nt
                            {archivo : Ruta del archivo Excel}
                            {--hoja=INSUMOS : Nombre de la hoja del catálogo}';

    protected $description = 'Importa el catálogo de insumos NT desde un archivo Excel';

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
            $this->error('La hoja INSUMOS no contiene registros.');

            return self::FAILURE;
        }

        $this->info('Comenzando importación...');
        $this->newLine();

        /*
         * Misma regla que la carga web del tarifario de insumos: solo escribe
         * codigo_insumos_nt (un registro por código) y nunca toca codigo_cups.
         */
        $estadisticas = DB::transaction(fn (): array => $importador->importSuppliesSheet(
            $hojaNombre,
            $hoja->toArray(null, true, false, false)
        ));

        if ($estadisticas['processed'] === 0) {
            $this->error("La hoja '{$hojaNombre}' no es un tarifario de insumos: se requieren columnas de código y tarifa.");
            $this->error('No se modificó ninguna tarifa.');

            return self::FAILURE;
        }

        $this->newLine();

        $this->info('==========================================');
        $this->info('     IMPORTACIÓN INSUMOS NT FINALIZADA');
        $this->info('==========================================');

        $this->line("Nuevos registros: {$estadisticas['created']}");
        $this->line("Registros actualizados: {$estadisticas['updated']}");
        $this->line("Filas ignoradas: {$estadisticas['ignored']}");
        $this->line('Total códigos de insumos en la base de datos: '.CodigoInsumoNt::count());

        return self::SUCCESS;
    }
}
