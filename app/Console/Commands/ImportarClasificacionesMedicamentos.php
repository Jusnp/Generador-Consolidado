<?php

namespace App\Console\Commands;

use App\Models\MedicationServiceClassification;
use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportarClasificacionesMedicamentos extends Command
{
    protected $signature = 'catalogo:importar-clasificaciones-medicamentos {archivo : Ruta del Excel} {--hoja=Hoja1}';

    protected $description = 'Importa las clasificaciones autorizadas de dispensación y aplicaciones';

    public function handle(): int
    {
        try {
            $sheet = IOFactory::load($this->argument('archivo'))->getSheetByName($this->option('hoja'));
        } catch (\Throwable $exception) {
            $this->error('No fue posible abrir la hoja indicada.');

            return self::FAILURE;
        }

        if ($sheet === null) {
            $this->error('No existe la hoja indicada.');

            return self::FAILURE;
        }

        $imported = 0;
        for ($row = 2; $row <= $sheet->getHighestRow(); $row++) {
            $description = $this->normalize($sheet->getCell("A{$row}")->getValue());
            $service = strtoupper(trim((string) $sheet->getCell("B{$row}")->getValue()));
            if ($description === '' || ! in_array($service, ['DISPENSACION', 'APLICACIONES'], true)) {
                continue;
            }
            MedicationServiceClassification::updateOrCreate(
                ['descripcion_normalizada' => $description],
                ['servicio' => $service]
            );
            $imported++;
        }

        $this->info("Clasificaciones importadas: {$imported}");

        return self::SUCCESS;
    }

    private function normalize(mixed $value): string
    {
        return preg_replace('/\s+/', ' ', strtoupper(trim((string) $value))) ?? '';
    }
}
