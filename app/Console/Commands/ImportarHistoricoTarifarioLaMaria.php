<?php

namespace App\Console\Commands;

use App\Programas\LaMaria\HistoricoTarifarioConsolidado;
use Illuminate\Console\Command;
use JsonException;
use Throwable;

class ImportarHistoricoTarifarioLaMaria extends Command
{
    protected $signature = 'la-maria:importar-historico-tarifario
        {archivo : JSON consolidado privado, sin datos de pacientes}
        {version : Identificador de la nueva versión}
        {--verificar : Validar el archivo sin crear una versión}';

    protected $description = 'Prepara una versión inactiva de precios administrativos históricos en el catálogo existente';

    public function handle(): int
    {
        $path = (string) $this->argument('archivo');
        if (! is_file($path) || ! is_readable($path)) {
            $this->error('No se puede leer el consolidado indicado.');

            return self::FAILURE;
        }

        try {
            $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($payload) || ($payload['version'] ?? null) !== 1
                || ! is_array($payload['fuentes'] ?? null) || $payload['fuentes'] === []
                || ! is_array($payload['items'] ?? null) || $payload['items'] === []) {
                throw new \InvalidArgumentException('El consolidado no tiene la estructura esperada.');
            }

            foreach ($payload['items'] as $item) {
                if (! is_array($item) || ! in_array($item['ruta'] ?? null, ['PROSTATA', 'CERVIX'], true)
                    || ! is_string($item['tipo'] ?? null) || ! is_string($item['codigo'] ?? null)
                    || $item['codigo'] === '' || ! is_array($item['valores'] ?? null)
                    || ! is_array($item['composiciones'] ?? null) || ! is_array($item['unidades'] ?? null)) {
                    throw new \InvalidArgumentException('El consolidado contiene una referencia incompleta o de otra ruta.');
                }
            }

            $this->comprobarSinIdentificadores($payload);
            if ($this->option('verificar')) {
                $this->info(sprintf('Consolidado válido: %d referencias; no se modificó la base.', count($payload['items'])));

                return self::SUCCESS;
            }

            $catalogue = HistoricoTarifarioConsolidado::guardarInactivo($payload, (string) $this->argument('version'));
            $this->info(sprintf('Versión %d creada inactiva con %d referencias. Revise su procedencia antes de activarla.',
                $catalogue->id, count($payload['items'])));

            return self::SUCCESS;
        } catch (JsonException|\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('No se pudo preparar la versión; no se activó ningún catálogo.');

            return self::FAILURE;
        }
    }

    /** @param array<string, mixed> $payload */
    private function comprobarSinIdentificadores(array $payload): void
    {
        $forbidden = ['paciente', 'documento', 'numdocumentoidentificacion', 'numerodocumento',
            'factura', 'numfactura', 'fechaatencion', 'fechaprestacion', 'nombrepaciente', 'apellido'];
        $pending = [$payload];

        while ($pending !== []) {
            $current = array_pop($pending);
            foreach ($current as $key => $value) {
                if (is_string($key) && in_array(mb_strtolower($key), $forbidden, true)) {
                    throw new \InvalidArgumentException('El consolidado contiene una clave de identificación personal.');
                }
                if (is_array($value)) {
                    $pending[] = $value;
                }
            }
        }
    }
}
