<?php

namespace App\Console\Commands;

use App\Models\MedicationAdjustment;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class ImportarAjustesMedicamentos extends Command
{
    protected $signature = 'catalogo:importar-ajustes-medicamentos
                            {archivo : Ruta del CSV de ajustes aprobados}';

    protected $description = 'Importa anulaciones o valores autorizados para filas puntuales de medicamentos';

    public function handle(): int
    {
        $path = $this->argument('archivo');

        if (! is_file($path) || ! is_readable($path)) {
            $this->error("No se puede leer el archivo: {$path}");

            return self::FAILURE;
        }

        $handle = fopen($path, 'rb');
        $headers = fgetcsv($handle);
        $required = ['DOCUMENTO', 'REGIMEN', 'CODIGO', 'FECHA', 'OCURRENCIA', 'VALOR_TOTAL', 'MOTIVO'];

        if ($headers === false) {
            $this->error('El CSV está vacío.');
            fclose($handle);

            return self::FAILURE;
        }

        $columns = array_flip(array_map(
            static fn (string $header): string => strtoupper(trim(preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? '')),
            $headers
        ));

        foreach ($required as $column) {
            if (! array_key_exists($column, $columns)) {
                $this->error("Falta la columna requerida: {$column}");
                fclose($handle);

                return self::FAILURE;
            }
        }

        $processed = 0;
        $line = 1;

        try {
            while (($row = fgetcsv($handle)) !== false) {
                $line++;

                if ($row === [null] || $row === []) {
                    continue;
                }

                $value = static fn (string $column): string => trim((string) ($row[$columns[$column]] ?? ''));
                $documento = $value('DOCUMENTO');
                $regimen = strtoupper($value('REGIMEN'));
                $codigo = str_replace(' ', '', $value('CODIGO'));
                $fecha = $value('FECHA');
                $ocurrencia = $value('OCURRENCIA');
                $valorTotal = str_replace(',', '.', $value('VALOR_TOTAL'));
                $motivo = $value('MOTIVO');

                if (
                    $documento === '' || ! in_array($regimen, ['SUBSIDIADA', 'CONTRIBUTIVO'], true)
                    || $codigo === '' || $fecha === '' || $motivo === ''
                    || ! ctype_digit($ocurrencia) || (int) $ocurrencia < 1 || ! is_numeric($valorTotal)
                ) {
                    throw new \RuntimeException("Fila {$line}: contiene datos inválidos.");
                }

                $fechaServicio = CarbonImmutable::createFromFormat('Y-m-d', $fecha);

                if ($fechaServicio === false || $fechaServicio->format('Y-m-d') !== $fecha) {
                    throw new \RuntimeException("Fila {$line}: FECHA debe usar el formato AAAA-MM-DD.");
                }

                MedicationAdjustment::updateOrCreate(
                    [
                        'documento' => $documento,
                        'regimen' => $regimen,
                        'codigo' => $codigo,
                        'fecha_servicio' => $fechaServicio->format('Y-m-d'),
                        'ocurrencia' => (int) $ocurrencia,
                    ],
                    [
                        'valor_total_override' => (float) $valorTotal,
                        'motivo' => $motivo,
                        'activo' => true,
                    ]
                );

                $processed++;
            }
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());
            fclose($handle);

            return self::FAILURE;
        }

        fclose($handle);
        $this->info("Ajustes importados o actualizados: {$processed}");

        return self::SUCCESS;
    }
}
