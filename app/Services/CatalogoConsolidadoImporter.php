<?php

namespace App\Services;

use App\Models\CatalogoConsolidadoImport;
use App\Models\CodigoCups;
use App\Models\CodigoInsumoNt;
use App\Models\CodigoMedicamento;
use App\Models\CodigoMedicamentoNt;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use RuntimeException;

class CatalogoConsolidadoImporter
{
    public const TYPE_CUPS = 'CUPS_CONTRATO';

    public const TYPE_MEDICAMENTOS = 'MEDICAMENTOS_CONTRATO';

    public const TYPE_INSUMOS = 'INSUMOS_CONTRATO';

    /**
     * @return array<string, string>
     */
    public static function types(): array
    {
        return [
            self::TYPE_CUPS => 'Tarifario CUPS del contrato',
            self::TYPE_MEDICAMENTOS => 'Medicamentos y homologaciones del contrato',
            self::TYPE_INSUMOS => 'Tarifario de insumos del contrato',
        ];
    }

    /**
     * @return array{import: CatalogoConsolidadoImport, processed: int, created: int, updated: int, ignored: int}
     */
    public function import(
        UploadedFile $file,
        string $type,
        string $version,
        User $user
    ): array {
        if (! array_key_exists($type, self::types())) {
            throw new InvalidArgumentException('El tipo de tarifario seleccionado no es válido.');
        }

        $path = $file->getRealPath();

        if ($path === false) {
            throw new RuntimeException('No fue posible leer el archivo del tarifario.');
        }

        $storedPath = null;

        try {
            return DB::transaction(function () use ($file, $path, $type, $version, $user, &$storedPath): array {
                $statistics = match ($type) {
                    self::TYPE_CUPS => $this->importCups($path),
                    self::TYPE_MEDICAMENTOS => $this->importMedicines($path),
                    self::TYPE_INSUMOS => $this->importSupplies($path),
                };

                if ($statistics['processed'] === 0) {
                    throw new InvalidArgumentException(
                        'No se encontraron filas válidas para el tarifario seleccionado. Revisa el tipo y los encabezados del Excel.'
                    );
                }

                $storedPath = $file->storeAs(
                    'catalogos-consolidado/'.$type,
                    Str::uuid().'.xlsx'
                );

                if (! is_string($storedPath) || $storedPath === '') {
                    throw new RuntimeException('No fue posible conservar el archivo fuente del tarifario.');
                }

                $import = CatalogoConsolidadoImport::create([
                    'tipo' => $type,
                    'version' => $version,
                    'archivo_origen' => $file->getClientOriginalName(),
                    'archivo_almacenado' => $storedPath,
                    'registros_procesados' => $statistics['processed'],
                    'registros_nuevos' => $statistics['created'],
                    'registros_actualizados' => $statistics['updated'],
                    'registros_ignorados' => $statistics['ignored'],
                    'imported_by' => $user->id,
                ]);

                return [
                    'import' => $import,
                    ...$statistics,
                ];
            });
        } catch (\Throwable $exception) {
            if ($storedPath !== null) {
                Storage::delete($storedPath);
            }

            throw $exception;
        }
    }

    /**
     * @return array{processed: int, created: int, updated: int, ignored: int}
     */
    private function importCups(string $path): array
    {
        $statistics = $this->emptyStatistics();

        $this->readWorkbook($path, function (string $sheetName, iterable $rows) use (&$statistics): void {
            $header = null;

            foreach ($rows as $row) {
                $values = $this->rowValues($row);

                if ($header === null) {
                    $candidate = $this->headerMap($values);

                    if ($this->hasColumn($candidate, ['CUPS', 'CODIGO'])
                        && $this->hasColumn($candidate, ['TARIFA 2025', 'TARIFA UNITARIO', 'TARIFA'])) {
                        $header = $candidate;
                    }

                    continue;
                }

                $code = $this->normaliseCode($this->at($values, $header, ['CUPS', 'CODIGO']));

                if ($code === '') {
                    $this->countIgnoredRow($values, $statistics);

                    continue;
                }

                $record = CodigoCups::query()->firstOrNew(['codigo' => $code]);
                $this->persist(
                    $record,
                    [
                        'hoja' => $sheetName,
                        'tipo_servicio' => $this->nullableString($this->at($values, $header, ['TIPO SERVICIO', 'SERVICIO'])),
                        'descripcion' => $this->nullableString($this->at($values, $header, ['DESCRIPCION SERVICIO', 'DESCRIPCION'])),
                        'tarifa_2025' => $this->numberValue($this->at($values, $header, ['TARIFA 2025', 'TARIFA UNITARIO', 'TARIFA'])),
                    ],
                    $statistics
                );
                $statistics['processed']++;
            }
        });

        return $statistics;
    }

    /**
     * @return array{processed: int, created: int, updated: int, ignored: int}
     */
    private function importMedicines(string $path): array
    {
        $statistics = $this->emptyStatistics();

        $this->readWorkbook($path, function (string $sheetName, iterable $rows) use (&$statistics): void {
            $header = null;
            $mode = '';

            foreach ($rows as $row) {
                $values = $this->rowValues($row);

                if ($header === null) {
                    $candidate = $this->headerMap($values);

                    if ($this->hasColumn($candidate, ['CODIGO'])
                        && $this->hasColumn($candidate, ['CUMS HOMOLOGO', 'CUM HOMOLOGO'])) {
                        $header = $candidate;
                        $mode = 'AM';
                    } elseif ($this->hasColumn($candidate, ['CUMS', 'CUM'])
                        && $this->hasColumn($candidate, ['TARIFA NT', 'TARIFA UNITARIO', 'TARIFA'])) {
                        $header = $candidate;
                        $mode = 'NT';
                    }

                    continue;
                }

                if ($mode === 'AM') {
                    $this->importMedicineMappingRow($values, $header, $statistics, $sheetName);
                } else {
                    $this->importMedicineNtRow($values, $header, $statistics, $sheetName);
                }
            }
        });

        return $statistics;
    }

    /**
     * @return array{processed: int, created: int, updated: int, ignored: int}
     */
    private function importSupplies(string $path): array
    {
        $statistics = $this->emptyStatistics();

        $this->readWorkbook($path, function (string $sheetName, iterable $rows) use (&$statistics): void {
            $header = null;

            foreach ($rows as $row) {
                $values = $this->rowValues($row);

                if ($header === null) {
                    $candidate = $this->headerMap($values);

                    if ($this->hasColumn($candidate, ['CODIGO'])
                        && $this->hasColumn($candidate, ['TARIFA UNITARIO', 'TARIFA'])) {
                        $header = $candidate;
                    }

                    continue;
                }

                $code = $this->normaliseCode($this->at($values, $header, ['CODIGO']));

                if ($code === '') {
                    $this->countIgnoredRow($values, $statistics);

                    continue;
                }

                $record = CodigoInsumoNt::query()->firstOrNew(['codigo' => $code]);
                $this->persist(
                    $record,
                    [
                        'hoja' => $sheetName,
                        'descripcion' => $this->nullableString($this->at($values, $header, ['DESCRIPCION'])),
                        'nt' => $this->nullableString($this->at($values, $header, ['NT'])),
                        'tarifa_unitario' => $this->numberValue($this->at($values, $header, ['TARIFA UNITARIO', 'TARIFA'])),
                    ],
                    $statistics
                );
                $statistics['processed']++;
            }
        });

        return $statistics;
    }

    /**
     * @param  array<int, mixed>  $values
     * @param  array<string, int>  $header
     * @param  array{processed: int, created: int, updated: int, ignored: int}  $statistics
     */
    private function importMedicineMappingRow(array $values, array $header, array &$statistics, string $sheetName): void
    {
        $code = $this->normaliseCode($this->at($values, $header, ['CODIGO']));

        if ($code === '') {
            $this->countIgnoredRow($values, $statistics);

            return;
        }

        $cums = $this->normaliseCode($this->at($values, $header, ['CUMS HOMOLOGO', 'CUM HOMOLOGO']));
        $nt = $this->normaliseNt($this->at($values, $header, ['NT', 'PERTENECE O NO A LA NT']));
        $rate = $this->numberValue($this->at($values, $header, ['TARIFA UNITARIO', 'TARIFA NT', 'TARIFA']));
        $mapping = CodigoMedicamento::query()->firstOrNew(['codigo' => $code]);
        $mappingAttributes = ['hoja' => $sheetName, 'divide_por_duplicados' => $mapping->exists ? $mapping->divide_por_duplicados : true];

        if ($cums !== '') {
            $mappingAttributes['cums_homologo'] = $cums;
        }

        if ($nt !== '') {
            $mappingAttributes['nt'] = $nt;
        }

        $this->persist($mapping, $mappingAttributes, $statistics);

        if ($cums !== '' && $rate !== null) {
            $medicine = CodigoMedicamentoNt::query()->firstOrNew(['cums' => $cums]);
            $medicineAttributes = ['hoja' => $sheetName, 'tarifa_nt' => $rate];

            if ($nt !== '') {
                $medicineAttributes['pertenece_nt'] = $nt;
            }

            $this->persist($medicine, $medicineAttributes, $statistics);
        }

        $statistics['processed']++;
    }

    /**
     * @param  array<int, mixed>  $values
     * @param  array<string, int>  $header
     * @param  array{processed: int, created: int, updated: int, ignored: int}  $statistics
     */
    private function importMedicineNtRow(array $values, array $header, array &$statistics, string $sheetName): void
    {
        $cums = $this->normaliseCode($this->at($values, $header, ['CUMS', 'CUM']));

        if ($cums === '') {
            $this->countIgnoredRow($values, $statistics);

            return;
        }

        $record = CodigoMedicamentoNt::query()->firstOrNew(['cums' => $cums]);
        $this->persist(
            $record,
            [
                'hoja' => $sheetName,
                'nombre_estandar' => $this->nullableString($this->at($values, $header, ['NOMBRE ESTADAR', 'NOMBRE ESTANDAR', 'NOMBRE ESTANDARIZADO', 'DESCRIPCION'])),
                'pertenece_nt' => $this->nullableString($this->at($values, $header, ['PERTENECE O NO A LA NT', 'NT'])),
                'tarifa_nt' => $this->numberValue($this->at($values, $header, ['TARIFA NT', 'TARIFA UNITARIO', 'TARIFA'])),
            ],
            $statistics
        );
        $statistics['processed']++;
    }

    /**
     * @param  array{processed: int, created: int, updated: int, ignored: int}  $statistics
     */
    private function persist(Model $record, array $attributes, array &$statistics): void
    {
        $wasExisting = $record->exists;
        $record->fill($attributes);

        if (! $wasExisting) {
            $record->save();
            $statistics['created']++;

            return;
        }

        if ($record->isDirty()) {
            $record->save();
            $statistics['updated']++;
        }
    }

    /**
     * @param  callable(string, iterable<int, Row>): void  $readSheet
     */
    private function readWorkbook(string $path, callable $readSheet): void
    {
        $reader = new Reader;
        $reader->open($path);

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $readSheet($sheet->getName(), $sheet->getRowIterator());
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * @return array<int, mixed>
     */
    private function rowValues(Row $row): array
    {
        $values = [];

        foreach ($row->getCells() as $cell) {
            $values[] = $cell instanceof FormulaCell
                ? ($cell->getComputedValue() ?? $cell->getValue())
                : $cell->getValue();
        }

        return $values;
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<string, int>
     */
    private function headerMap(array $values): array
    {
        $headers = [];

        foreach ($values as $index => $value) {
            $header = $this->normaliseHeader($this->stringValue($value));

            if ($header !== '' && ! isset($headers[$header])) {
                $headers[$header] = $index;
            }
        }

        return $headers;
    }

    /**
     * @param  array<string, int>  $header
     * @param  array<int, string>  $aliases
     */
    private function hasColumn(array $header, array $aliases): bool
    {
        foreach ($aliases as $alias) {
            if (isset($header[$this->normaliseHeader($alias)])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, mixed>  $values
     * @param  array<string, int>  $header
     * @param  array<int, string>  $aliases
     */
    private function at(array $values, array $header, array $aliases): mixed
    {
        foreach ($aliases as $alias) {
            $normalised = $this->normaliseHeader($alias);

            if (isset($header[$normalised])) {
                return $values[$header[$normalised]] ?? null;
            }
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $values
     * @param  array{processed: int, created: int, updated: int, ignored: int}  $statistics
     */
    private function countIgnoredRow(array $values, array &$statistics): void
    {
        foreach ($values as $value) {
            if ($this->stringValue($value) !== '') {
                $statistics['ignored']++;

                return;
            }
        }
    }

    /**
     * @return array{processed: int, created: int, updated: int, ignored: int}
     */
    private function emptyStatistics(): array
    {
        return [
            'processed' => 0,
            'created' => 0,
            'updated' => 0,
            'ignored' => 0,
        ];
    }

    private function normaliseHeader(string $value): string
    {
        return (string) Str::of($value)
            ->ascii()
            ->upper()
            ->replaceMatches('/[^A-Z0-9]+/', ' ')
            ->trim()
            ->replace(' ', '');
    }

    private function normaliseCode(mixed $value): string
    {
        $code = $this->stringValue($value);

        if (preg_match('/^\d+\.0+$/', $code) === 1) {
            $code = (string) (int) $code;
        }

        return (string) Str::of($code)
            ->ascii()
            ->upper()
            ->replace(['.', ' ', '/'], '')
            ->trim();
    }

    private function normaliseNt(mixed $value): string
    {
        $nt = $this->normaliseHeader($this->stringValue($value));

        return in_array($nt, ['SI', 'NO'], true) ? $nt : '';
    }

    private function nullableString(mixed $value): ?string
    {
        $value = $this->stringValue($value);

        return $value === '' ? null : $value;
    }

    private function numberValue(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $value = str_replace(['$', ' ', "\u{00A0}"], '', $this->stringValue($value));

        if ($value === '') {
            return null;
        }

        if (str_contains($value, ',') && str_contains($value, '.')) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } elseif (str_contains($value, ',')) {
            $value = str_replace(',', '.', $value);
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function stringValue(mixed $value): string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return '';
        }

        return trim((string) $value);
    }
}
