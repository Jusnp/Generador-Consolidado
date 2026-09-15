<?php

namespace App\Console\Commands;

use App\Models\CodigoMedicamento;
use App\Models\CodigoMedicamentoNt;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportarCatalogoDesdeAm extends Command
{
    protected $signature = 'catalogo:importar-desde-am
                            {archivo : Ruta de un Excel de referencia con hoja AM}
                            {--hoja=AM : Nombre de la hoja que contiene los medicamentos}
                            {--actualizar : Reemplaza diferencias con valores de una guía aprobada}';

    protected $description = 'Completa datos faltantes del catálogo a partir de las columnas de la hoja AM';

    public function handle(): int
    {
        $path = $this->argument('archivo');
        $sheetName = $this->option('hoja');

        if (! is_file($path) || ! is_readable($path)) {
            $this->error("No se puede leer el archivo: {$path}");

            return self::FAILURE;
        }

        try {
            $worksheetUri = $this->worksheetUri($path, $sheetName);
            $preview = $this->previewRows($worksheetUri);
            $headerStrings = $this->readSharedStrings($path, $this->sharedIndexes($preview));
            [$headerRow, $columns] = $this->findHeader($preview, $headerStrings);

            if ($headerRow === null) {
                $this->error('No se encontraron las columnas CODIGO, NT, CUMS HOMOLOGO y Tarifa UNITARIO.');

                return self::FAILURE;
            }

            $sharedIndexes = $this->sharedIndexesForColumns(
                $worksheetUri,
                $headerRow,
                array_values($columns)
            );
            $sharedStrings = $this->readSharedStrings($path, $sharedIndexes);

            [$addedMappings, $addedNt, $conflicts, $ignored] = $this->importRows(
                $worksheetUri,
                $headerRow,
                $columns,
                $sharedStrings,
                (bool) $this->option('actualizar')
            );
        } catch (\Throwable $exception) {
            $this->error('No fue posible importar el catálogo desde la guía.');
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Mapeos creados: {$addedMappings}");
        $this->info("Registros NT creados: {$addedNt}");
        $this->info($this->option('actualizar')
            ? "Diferencias aplicadas desde la guía: {$conflicts}"
            : "Conflictos conservados para revisión: {$conflicts}");
        $this->info("Filas sin código ignoradas: {$ignored}");

        return self::SUCCESS;
    }

    private function worksheetUri(string $path, string $sheetName): string
    {
        $archive = new \ZipArchive;

        if ($archive->open($path) !== true) {
            throw new \RuntimeException('El archivo no es un libro Excel válido.');
        }

        try {
            $workbook = $this->xml($this->zipEntry($archive, 'xl/workbook.xml'));
            $relationships = $this->xml($this->zipEntry($archive, 'xl/_rels/workbook.xml.rels'));
            $officeRelationship = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
            $relationshipId = null;

            foreach ($workbook->sheets->sheet as $sheet) {
                if ((string) $sheet['name'] === $sheetName) {
                    $relationshipId = (string) $sheet->attributes($officeRelationship)['id'];
                    break;
                }
            }

            if ($relationshipId === null || $relationshipId === '') {
                throw new \RuntimeException("No existe la hoja '{$sheetName}'.");
            }

            foreach ($relationships->Relationship as $relationship) {
                if ((string) $relationship['Id'] !== $relationshipId) {
                    continue;
                }

                $target = str_replace('\\', '/', (string) $relationship['Target']);
                $entry = str_starts_with($target, '/')
                    ? ltrim($target, '/')
                    : 'xl/'.$target;

                return $this->zipUri($path, $entry);
            }
        } finally {
            $archive->close();
        }

        throw new \RuntimeException("No se encontró la definición de la hoja '{$sheetName}'.");
    }

    private function previewRows(string $worksheetUri): array
    {
        $rows = [];

        $this->streamRows($worksheetUri, function (int $row, array $cells) use (&$rows): bool {
            $rows[$row] = $cells;

            return $row < 10;
        });

        return $rows;
    }

    private function findHeader(array $rows, array $sharedStrings): array
    {
        foreach ($rows as $rowNumber => $cells) {
            $columns = [];

            foreach ($cells as $column => $cell) {
                $columns[strtoupper(trim($this->cellValue($cell, $sharedStrings)))] = $column;
            }

            if (isset(
                $columns['CODIGO'],
                $columns['NT'],
                $columns['CUMS HOMOLOGO'],
                $columns['TARIFA UNITARIO']
            )) {
                return [$rowNumber, [
                    'CODIGO' => $columns['CODIGO'],
                    'NT' => $columns['NT'],
                    'CUMS HOMOLOGO' => $columns['CUMS HOMOLOGO'],
                    'TARIFA UNITARIO' => $columns['TARIFA UNITARIO'],
                ]];
            }
        }

        return [null, []];
    }

    private function sharedIndexesForColumns(
        string $worksheetUri,
        int $headerRow,
        array $columns
    ): array {
        $indexes = [];

        $this->streamRows($worksheetUri, function (int $row, array $cells) use (
            $headerRow,
            $columns,
            &$indexes
        ): void {
            if ($row <= $headerRow) {
                return;
            }

            foreach ($columns as $column) {
                $cell = $cells[$column] ?? null;

                if ($cell !== null && $cell['type'] === 's' && ctype_digit($cell['value'])) {
                    $indexes[(int) $cell['value']] = true;
                }
            }
        });

        return array_keys($indexes);
    }

    private function importRows(
        string $worksheetUri,
        int $headerRow,
        array $columns,
        array $sharedStrings,
        bool $actualizar
    ): array {
        $addedMappings = 0;
        $addedNt = 0;
        $conflicts = 0;
        $ignored = 0;
        $mappingsByCode = CodigoMedicamento::query()
            ->get()
            ->keyBy(fn (CodigoMedicamento $item): string => $this->codeValue($item->codigo))
            ->all();
        $ntByCums = CodigoMedicamentoNt::query()
            ->get()
            ->keyBy(fn (CodigoMedicamentoNt $item): string => $this->codeValue($item->cums))
            ->all();

        DB::transaction(function () use (
            $worksheetUri,
            $headerRow,
            $columns,
            $sharedStrings,
            $actualizar,
            &$addedMappings,
            &$addedNt,
            &$conflicts,
            &$ignored,
            &$mappingsByCode,
            &$ntByCums
        ): void {
            $this->streamRows($worksheetUri, function (int $row, array $cells) use (
                $headerRow,
                $columns,
                $sharedStrings,
                $actualizar,
                &$addedMappings,
                &$addedNt,
                &$conflicts,
                &$ignored,
                &$mappingsByCode,
                &$ntByCums
            ): void {
                if ($row <= $headerRow) {
                    return;
                }

                $codigo = $this->codeValue($this->cellValue($cells[$columns['CODIGO']] ?? [], $sharedStrings));
                $cums = $this->codeValue($this->cellValue($cells[$columns['CUMS HOMOLOGO']] ?? [], $sharedStrings));
                $nt = strtoupper(trim($this->cellValue($cells[$columns['NT']] ?? [], $sharedStrings)));
                $tarifa = $this->numberValue($this->cellValue($cells[$columns['TARIFA UNITARIO']] ?? [], $sharedStrings));

                if (! $this->isCatalogCode($codigo)) {
                    $ignored++;

                    return;
                }

                if (! $this->isCatalogCode($cums)) {
                    $cums = '';
                }

                if (! in_array($nt, ['SI', 'NO'], true)) {
                    $nt = '';
                }

                $mapping = $mappingsByCode[$codigo] ?? new CodigoMedicamento(['codigo' => $codigo]);

                if (! $mapping->exists) {
                    $addedMappings++;
                }

                if ($cums !== '') {
                    if (trim((string) $mapping->cums_homologo) === '') {
                        $mapping->cums_homologo = $cums;
                    } elseif ($mapping->cums_homologo !== $cums) {
                        $conflicts++;

                        if ($actualizar) {
                            $mapping->cums_homologo = $cums;
                        }
                    }
                }

                if ($nt !== '' && (trim((string) $mapping->nt) === '' || $actualizar)) {
                    $mapping->nt = $nt;
                }

                if ($mapping->divide_por_duplicados === null) {
                    $mapping->divide_por_duplicados = true;
                }

                if ($mapping->isDirty()) {
                    $mapping->save();
                }

                $mappingsByCode[$codigo] = $mapping;

                if ($cums === '' || $tarifa === null) {
                    return;
                }

                $catalogoNt = $ntByCums[$cums] ?? new CodigoMedicamentoNt(['cums' => $cums]);

                if (! $catalogoNt->exists) {
                    $addedNt++;
                }

                if ((trim((string) $catalogoNt->pertenece_nt) === '' || $actualizar) && $nt !== '') {
                    $catalogoNt->pertenece_nt = $nt;
                }

                if ($catalogoNt->tarifa_nt === null) {
                    $catalogoNt->tarifa_nt = $tarifa;
                } elseif (abs((float) $catalogoNt->tarifa_nt - $tarifa) > 0.000001) {
                    $conflicts++;

                    if ($actualizar) {
                        $catalogoNt->tarifa_nt = $tarifa;
                    }
                }

                if ($catalogoNt->isDirty()) {
                    $catalogoNt->save();
                }

                $ntByCums[$cums] = $catalogoNt;
            });
        });

        return [$addedMappings, $addedNt, $conflicts, $ignored];
    }

    private function sharedIndexes(array $rows): array
    {
        $indexes = [];

        foreach ($rows as $cells) {
            foreach ($cells as $cell) {
                if ($cell['type'] === 's' && ctype_digit($cell['value'])) {
                    $indexes[(int) $cell['value']] = true;
                }
            }
        }

        return array_keys($indexes);
    }

    private function readSharedStrings(string $path, array $indexes): array
    {
        if ($indexes === []) {
            return [];
        }

        $wanted = array_fill_keys($indexes, true);
        $found = [];
        $reader = new \XMLReader;

        if (! $reader->open($this->zipUri($path, 'xl/sharedStrings.xml'), null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new \RuntimeException('No fue posible leer las cadenas del libro Excel.');
        }

        $index = -1;

        try {
            while ($reader->read()) {
                if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->localName !== 'si') {
                    continue;
                }

                $index++;

                if (! isset($wanted[$index])) {
                    continue;
                }

                $depth = $reader->depth;
                $value = '';

                while ($reader->read()) {
                    if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 't') {
                        $value .= $reader->readString();
                    }

                    if ($reader->nodeType === \XMLReader::END_ELEMENT && $reader->depth === $depth && $reader->localName === 'si') {
                        break;
                    }
                }

                $found[$index] = $value;

                if (count($found) === count($wanted)) {
                    break;
                }
            }
        } finally {
            $reader->close();
        }

        return $found;
    }

    private function streamRows(string $worksheetUri, callable $callback): void
    {
        $reader = new \XMLReader;

        if (! $reader->open($worksheetUri, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new \RuntimeException('No fue posible leer la hoja AM.');
        }

        try {
            while ($reader->read()) {
                if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->localName !== 'row') {
                    continue;
                }

                $row = (int) ($reader->getAttribute('r') ?? 0);

                if ($callback($row, $this->readRow($reader)) === false) {
                    break;
                }
            }
        } finally {
            $reader->close();
        }
    }

    private function readRow(\XMLReader $reader): array
    {
        $cells = [];
        $rowDepth = $reader->depth;

        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'c') {
                $reference = (string) $reader->getAttribute('r');
                $column = preg_replace('/\\d+$/', '', $reference);

                if ($column !== '') {
                    $cells[$column] = $this->readCell($reader);
                }

                continue;
            }

            if ($reader->nodeType === \XMLReader::END_ELEMENT && $reader->depth === $rowDepth && $reader->localName === 'row') {
                break;
            }
        }

        return $cells;
    }

    private function readCell(\XMLReader $reader): array
    {
        $type = (string) ($reader->getAttribute('t') ?? 'n');
        $cellDepth = $reader->depth;
        $value = '';

        if ($reader->isEmptyElement) {
            return ['type' => $type, 'value' => $value];
        }

        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT && in_array($reader->localName, ['v', 't'], true)) {
                $value = $reader->readString();
            }

            if ($reader->nodeType === \XMLReader::END_ELEMENT && $reader->depth === $cellDepth && $reader->localName === 'c') {
                break;
            }
        }

        return ['type' => $type, 'value' => $value];
    }

    private function cellValue(array $cell, array $sharedStrings): string
    {
        if (($cell['type'] ?? null) === 's') {
            return $sharedStrings[(int) ($cell['value'] ?? -1)] ?? '';
        }

        return (string) ($cell['value'] ?? '');
    }

    private function codeValue(string $value): string
    {
        return str_replace(' ', '', trim($value));
    }

    private function isCatalogCode(string $value): bool
    {
        return $value !== ''
            && strlen($value) <= 100
            && ! str_starts_with($value, '=')
            && ! str_starts_with($value, '#');
    }

    private function numberValue(string $value): ?float
    {
        if ($value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        $value = str_replace(['$', ' '], '', trim($value));

        if (str_contains($value, ',') && str_contains($value, '.')) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } elseif (str_contains($value, ',')) {
            $value = str_replace(',', '.', $value);
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function zipUri(string $path, string $entry): string
    {
        $path = str_replace('\\', '/', (string) realpath($path));

        return 'zip://'.$path.'#'.$entry;
    }

    private function zipEntry(\ZipArchive $archive, string $entry): string
    {
        $contents = $archive->getFromName($entry);

        if ($contents === false) {
            throw new \RuntimeException("No se encontró '{$entry}' en el libro Excel.");
        }

        return $contents;
    }

    private function xml(string $contents): \SimpleXMLElement
    {
        $xml = simplexml_load_string($contents, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);

        if ($xml === false) {
            throw new \RuntimeException('El libro contiene XML inválido.');
        }

        return $xml;
    }
}
