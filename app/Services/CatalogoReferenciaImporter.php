<?php

namespace App\Services;

use App\Models\CatalogoReferencia;
use App\Models\CatalogoReferenciaItem;
use App\Models\User;
use App\Programas\LaMaria\LaMariaPrograma;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use RuntimeException;

class CatalogoReferenciaImporter
{
    private string $currentSheet = '';

    public const TYPE_ANEXOS_2706 = 'ANEXOS_2706';

    public const TYPE_MEDICAMENTOS_CUM = 'MEDICAMENTOS_CUM';

    public const TYPE_NOTA_CERVIX = 'NOTA_CERVIX';

    public const TYPE_NOTA_PROSTATA = 'NOTA_PROSTATA';

    public const TYPE_NOTA_MEDICAMENTOS = 'NOTA_MEDICAMENTOS';

    public const TYPE_DISPENSACION_CERVIX = 'DISPENSACION_CERVIX';

    public const TYPE_DISPENSACION_PROSTATA = 'DISPENSACION_PROSTATA';

    /**
     * @return array<string, string>
     */
    public static function types(): array
    {
        return [
            self::TYPE_ANEXOS_2706 => 'Anexos técnicos Resolución 2706 de 2025',
            self::TYPE_MEDICAMENTOS_CUM => 'Código Único de Medicamentos (CUM)',
            self::TYPE_NOTA_CERVIX => 'Nota técnica de cérvix',
            self::TYPE_NOTA_PROSTATA => 'Nota técnica de próstata',
            self::TYPE_NOTA_MEDICAMENTOS => 'Nota técnica de medicamentos (La María)',
            self::TYPE_DISPENSACION_CERVIX => 'Dispensación administrativa de cérvix',
            self::TYPE_DISPENSACION_PROSTATA => 'Dispensación administrativa de próstata',
        ];
    }

    /**
     * @return array{catalogo: CatalogoReferencia, items: int}
     */
    public function import(
        UploadedFile $file,
        string $type,
        string $version,
        User $user,
        string $programaSlug = LaMariaPrograma::SLUG,
        ?int $contratoId = null
    ): array {
        if (! array_key_exists($type, self::types())) {
            throw new InvalidArgumentException('El tipo de catálogo seleccionado no es válido.');
        }

        if ($programaSlug !== LaMariaPrograma::SLUG) {
            throw new InvalidArgumentException(
                'Los catálogos técnicos de referencia solo pertenecen al programa La María.'
            );
        }

        $path = $file->getRealPath();

        if ($path === false) {
            throw new RuntimeException('No fue posible leer el archivo cargado.');
        }

        $catalogo = CatalogoReferencia::create([
            'programa_slug' => $programaSlug,
            'contrato_id' => $contratoId,
            'tipo' => $type,
            'version' => $version,
            'archivo_origen' => $file->getClientOriginalName(),
            'fecha_referencia' => $this->dateFromFileName($file->getClientOriginalName()),
            'activo' => false,
            'imported_by' => $user->id,
        ]);

        try {
            $itemCount = match ($type) {
                self::TYPE_ANEXOS_2706 => $this->importResolutionAnnexes($path, $catalogo),
                self::TYPE_MEDICAMENTOS_CUM => $this->importCum($path, $catalogo),
                self::TYPE_NOTA_CERVIX => $this->importTechnicalNote($path, $catalogo, 'CERVIX'),
                self::TYPE_NOTA_PROSTATA => $this->importTechnicalNote($path, $catalogo, 'PROSTATA'),
                self::TYPE_NOTA_MEDICAMENTOS => $this->importMedicationTechnicalNote($path, $catalogo),
                self::TYPE_DISPENSACION_CERVIX => $this->importAdministrativeDispensation($path, $catalogo, 'CERVIX'),
                self::TYPE_DISPENSACION_PROSTATA => $this->importAdministrativeDispensation($path, $catalogo, 'PROSTATA'),
            };

            if ($itemCount === 0) {
                throw new InvalidArgumentException('No se encontraron registros reconocibles para el tipo de catálogo seleccionado.');
            }

            // Activa solo dentro del mismo programa+tipo; conserva versiones previas inactivas.
            DB::transaction(function () use ($catalogo, $type, $programaSlug): void {
                CatalogoReferencia::query()
                    ->forPrograma($programaSlug)
                    ->where('tipo', $type)
                    ->whereKeyNot($catalogo->id)
                    ->update(['activo' => false]);

                $catalogo->update(['activo' => true]);
            });
        } catch (\Throwable $exception) {
            $catalogo->delete();

            throw $exception;
        }

        return [
            'catalogo' => $catalogo->fresh(),
            'items' => $itemCount,
        ];
    }

    private function importResolutionAnnexes(string $path, CatalogoReferencia $catalogo): int
    {
        return $this->readWorkbook($path, function (string $sheetName, iterable $rows) use ($catalogo): int {
            $normalisedName = $this->normaliseHeader($sheetName);
            $category = match (true) {
                str_starts_with($normalisedName, 'ANEXO2SINPUNTOS') => 'ANEXO_2',
                str_contains($normalisedName, 'INTERNACIONESYTRANSPORTE') => 'ANEXO_4',
                default => null,
            };

            if ($category === null) {
                return 0;
            }

            $header = null;
            $items = [];
            $count = 0;

            foreach ($rows as $row) {
                $values = $this->rowValues($row);

                if ($header === null) {
                    $candidate = $this->headerMap($values);

                    if (isset($candidate['CODIGO']) && isset($candidate['DESCRIPCION'])) {
                        $header = $candidate;
                    }

                    continue;
                }

                $code = $this->normaliseCode($this->at($values, $header, ['CODIGO']));
                $description = $this->stringValue($this->at($values, $header, ['DESCRIPCION']));

                if (! $this->isCatalogCode($code) || $description === '') {
                    continue;
                }

                $items[] = $this->item($catalogo, $code, null, $category, $description);
                $count += $this->flushItems($items);
            }

            return $count + $this->flushItems($items, true);
        });
    }

    private function importAdministrativeDispensation(string $path, CatalogoReferencia $catalogo, string $route): int
    {
        return $this->readWorkbook($path, function (string $sheetName, iterable $rows) use ($catalogo, $route): int {
            $header = null;
            $items = [];
            $count = 0;

            foreach ($rows as $row) {
                $values = $this->rowValues($row);

                if ($header === null) {
                    $candidate = $this->headerMap($values);

                    if (isset($candidate['DOCUMENTOPACIENTE'], $candidate['CODIGOCUM'])) {
                        $header = $candidate;
                    }

                    continue;
                }

                $document = $this->normaliseCode($this->at($values, $header, ['DOCUMENTO PACIENTE']));
                $code = $this->normaliseCode($this->at($values, $header, ['CODIGO CUM', 'CODIGO PRODUCTO']));
                $dispensationDate = $this->dateValue($this->at($values, $header, ['FECHA DISPENSACION']));

                if ($document === '' || $code === '' || $dispensationDate === '') {
                    continue;
                }

                $units = $this->numberValue($this->at($values, $header, ['UNIDADES DISPENSADAS', 'CANTIDAD ENTREGADA'])) ?? 0.0;
                $unitRate = $this->currencyValue($this->at($values, $header, [
                    'VALOR UNITARIO MEDICAMENTOS DISPENSADOS',
                    'VALOR UNITARIO',
                ]));
                $totalRate = $this->currencyValue($this->at($values, $header, [
                    'VALOR TOTAL MEDICAMENTOS DISPENSADOS',
                    'VALOR TOTAL',
                ]));

                if ($unitRate === null && $totalRate !== null && $units > 0) {
                    $unitRate = round($totalRate / $units, 4);
                }

                $description = $this->stringValue($this->at($values, $header, [
                    'NOMBRE GENERICO',
                    'DESCRIPCION MEDICAMENTOS DISPENSADOS',
                ]));

                $items[] = $this->item(
                    $catalogo,
                    $code,
                    $route,
                    'DISPENSACION_ADMINISTRATIVA',
                    $description,
                    $unitRate,
                    [
                        'documento_paciente' => $document,
                        'tipo_documento_paciente' => $this->stringValue($this->at($values, $header, ['TIPO DOCUMENTO PACIENTE'])),
                        'fecha_dispensacion' => $dispensationDate,
                        'unidades_dispensadas' => $units,
                        'valor_total_dispensado' => $totalRate,
                        'origen_fecha' => 'FECHA DISPENSACION',
                    ]
                );
                $count += $this->flushItems($items);
            }

            return $count + $this->flushItems($items, true);
        });
    }

    private function importCum(string $path, CatalogoReferencia $catalogo): int
    {
        return $this->readWorkbook($path, function (string $sheetName, iterable $rows) use ($catalogo): int {
            if ($this->normaliseHeader($sheetName) !== 'DATA') {
                return 0;
            }

            $header = null;
            $items = [];
            $count = 0;

            foreach ($rows as $row) {
                $values = $this->rowValues($row);

                if ($header === null) {
                    $candidate = $this->headerMap($values);

                    if (isset($candidate['EXPEDIENTECUM']) && isset($candidate['CONSECUTIVOCUM'])) {
                        $header = $candidate;
                    }

                    continue;
                }

                $expediente = $this->normaliseCode($this->at($values, $header, ['EXPEDIENTECUM']));
                $consecutivo = $this->normaliseCode($this->at($values, $header, ['CONSECUTIVOCUM']));

                if ($expediente === '' || $consecutivo === '') {
                    continue;
                }

                $metadata = [
                    'producto' => $this->stringValue($this->at($values, $header, ['PRODUCTO'])),
                    'descripcion_comercial' => $this->stringValue($this->at($values, $header, ['DESCRIPCIONCOMERCIAL'])),
                    'atc' => $this->stringValue($this->at($values, $header, ['ATC'])),
                    'descripcion_atc' => $this->stringValue($this->at($values, $header, ['DESCRIPCIONATC'])),
                    'via_administracion' => $this->stringValue($this->at($values, $header, ['VIAADMINISTRACION'])),
                    'concentracion' => $this->stringValue($this->at($values, $header, ['CONCENTRACION'])),
                    'principio_activo' => $this->stringValue($this->at($values, $header, ['PRINCIPIOACTIVO'])),
                    'forma_farmaceutica' => $this->stringValue($this->at($values, $header, ['FORMAFARMACEUTICA'])),
                    'estado_cum' => $this->stringValue($this->at($values, $header, ['ESTADOCUM'])),
                ];
                $description = $metadata['principio_activo'] !== ''
                    ? $metadata['principio_activo']
                    : ($metadata['descripcion_comercial'] !== '' ? $metadata['descripcion_comercial'] : $metadata['producto']);

                $items[] = $this->item(
                    $catalogo,
                    $expediente.'-'.$consecutivo,
                    null,
                    'CUM',
                    $description,
                    null,
                    $metadata
                );
                $count += $this->flushItems($items);
            }

            return $count + $this->flushItems($items, true);
        });
    }

    private function importTechnicalNote(string $path, CatalogoReferencia $catalogo, string $route): int
    {
        return $this->readWorkbook($path, function (string $sheetName, iterable $rows) use ($catalogo, $route): int {
            $normalisedName = $this->normaliseHeader($sheetName);

            return match (true) {
                str_contains($normalisedName, 'SERVICIOSYTECNOLOGIAS') => $this->importTechnicalServices($rows, $catalogo, $route),
                str_contains($normalisedName, 'QUIMIO') || str_contains($normalisedName, 'RADIO') => $this->importOncology($rows, $catalogo, $route),
                str_contains($normalisedName, 'MEDICAMENTOSPALIATIVOS') => $this->importPalliativeDrugs($rows, $catalogo, $route),
                $normalisedName === 'RESUMEN' => $this->importTechnicalSummary($rows, $catalogo, $route),
                $normalisedName === 'HX' => $this->importHospitalizationReference($rows, $catalogo, $route),
                str_contains($normalisedName, 'TRANSPORTE') => $this->importTransportAndLodgingReference($rows, $catalogo, $route),
                str_contains($normalisedName, 'MEDICAMENTOSNUEVOS') => $this->importNewProstateMedicines($rows, $catalogo),
                $normalisedName === 'POBLACION' => $this->importPopulation($rows, $catalogo, $route),
                default => 0,
            };
        });
    }

    private function importNewProstateMedicines(iterable $rows, CatalogoReferencia $catalogo): int
    {
        $header = null;
        $items = [];
        foreach ($rows as $row) {
            $values = $this->rowValues($row);
            if ($header === null) {
                $candidate = $this->headerMap($values);
                if (isset($candidate['CODIGOCUM']) && isset($candidate['MEDICAMENTOAAGREGAR'])) {
                    $header = $candidate;
                }

                continue;
            }
            $code = $this->normaliseCode($this->at($values, $header, ['CODIGO CUM']));
            if ($code === '') {
                continue;
            }
            $items[] = [
                'catalogo_referencia_id' => $catalogo->id,
                'hoja' => $this->currentSheet,
                'codigo' => $code,
                'ruta' => 'PROSTATA',
                'categoria' => 'MEDICAMENTOS_NUEVOS',
                'descripcion' => $this->stringValue($this->at($values, $header, ['MEDICAMENTO A AGREGAR'])),
                'tarifa_referencia' => $this->numberValue($this->at($values, $header, ['VALOR UNITARIO'])),
                'activo' => true,
                'metadatos' => json_encode(['presentacion' => $this->stringValue($this->at($values, $header, ['PRESENTACION EXACTA'])), 'cups' => $this->stringValue($this->at($values, $header, ['CUPS'])), 'periodo_precio' => $this->stringValue($this->at($values, $header, ['PERIODO DEL PRECIO']))], JSON_UNESCAPED_UNICODE),
            ];
        }

        return $this->flushItems($items, true);
    }

    /**
     * Importa el listado de medicamentos NT por CUM / principio activo
     * (hojas CA PROSTATA, CA CERVIX y Medicamentos paliativos).
     */
    private function importMedicationTechnicalNote(string $path, CatalogoReferencia $catalogo): int
    {
        return $this->readWorkbook($path, function (string $sheetName, iterable $rows) use ($catalogo): int {
            $normalisedName = $this->normaliseHeader($sheetName);

            $route = match (true) {
                str_contains($normalisedName, 'PROSTATA') => 'PROSTATA',
                str_contains($normalisedName, 'CERVIX') => 'CERVIX',
                str_contains($normalisedName, 'PALIATIVO') => null,
                default => false,
            };

            if ($route === false) {
                return 0;
            }

            return $this->importMedicationTechnicalRows(
                $rows,
                $catalogo,
                $route,
                $route === null ? 'MEDICAMENTO_NT_PALIATIVO' : 'MEDICAMENTO_NT'
            );
        });
    }

    private function importMedicationTechnicalRows(
        iterable $rows,
        CatalogoReferencia $catalogo,
        ?string $route,
        string $category
    ): int {
        $header = null;
        $items = [];
        $count = 0;
        $seen = [];

        foreach ($rows as $row) {
            $values = $this->rowValues($row);

            if ($header === null) {
                $candidate = $this->headerMap($values);

                if (isset($candidate['CUM']) && (
                    isset($candidate['DESCRIPCIONESTANDARIZADA'])
                    || isset($candidate['PRINCIPIOACTIVO'])
                )) {
                    $header = $candidate;
                }

                continue;
            }

            $cum = $this->normaliseCode($this->at($values, $header, ['CUM']));

            if ($cum === '' || isset($seen[$cum])) {
                continue;
            }

            $description = $this->stringValue($this->at($values, $header, [
                'DESCRIPCION ESTANDARIZADA',
                'DESCRIPCIONESTANDARIZADA',
            ]));
            $principio = $this->stringValue($this->at($values, $header, [
                'PRINCIPIO ACTIVO',
                'PRINCIPIOACTIVO',
            ]));

            if ($description === '' && $principio === '') {
                continue;
            }

            $seen[$cum] = true;
            $items[] = $this->item(
                $catalogo,
                $cum,
                $route,
                $category,
                $description !== '' ? $description : $principio,
                $this->moneyValue($this->at($values, $header, [
                    'TARIFA',
                    'TARIFA MIN REG',
                    'TARIFAMINREG',
                ])),
                [
                    'principio_activo' => $principio,
                    'descripcion_estandarizada' => $description,
                    'descripcion_invima' => $this->stringValue($this->at($values, $header, [
                        'DESCRIPCION INVIMA',
                        'DESCRIPCIONINVIMA',
                    ])),
                    'subgrupo_farmacologico' => $this->stringValue($this->at($values, $header, [
                        'SUBGRUPO FARMACOLOGICO',
                        'SUBGRUPOFARMACOLOGICO',
                    ])),
                ]
            );
            $count += $this->flushItems($items);
        }

        return $count + $this->flushItems($items, true);
    }

    private function moneyValue(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $raw = str_replace(['$', ' ', "\u{00A0}"], '', $this->stringValue($value));

        if ($raw === '') {
            return null;
        }

        // 612,563 o 1.234.567 → miles sin decimales
        if (preg_match('/^\d{1,3}([.,]\d{3})+$/', $raw) === 1) {
            return (float) str_replace([',', '.'], '', $raw);
        }

        return $this->numberValue($raw);
    }

    private function importTechnicalServices(iterable $rows, CatalogoReferencia $catalogo, string $route): int
    {
        $header = null;
        $items = [];
        $count = 0;

        foreach ($rows as $row) {
            $values = $this->rowValues($row);

            if ($header === null) {
                $candidate = $this->headerMap($values);

                if (isset($candidate['CUPS']) && isset($candidate['TARIFA'])) {
                    $header = $candidate;
                }

                continue;
            }

            $code = $this->normaliseCode($this->at($values, $header, ['CUPS']));

            if (! $this->isCatalogCode($code)) {
                continue;
            }

            $description = $this->stringValue($this->at($values, $header, ['NOMBREDEL CUPS', 'NOMBRE ESTANDAR', 'SERVICIO']));
            $metadata = [
                'agrupador' => $this->stringValue($this->at($values, $header, ['AGRUPADOR', 'RECLASIFICACION', 'SERVICIO'])),
                'frecuencia_mensual' => $this->numberValue($this->at($values, $header, ['FRECUENCIA MES CERVIX', 'FRECUENCIA MES PROSTATA'])),
                'cantidad_mensual' => $this->numberValue($this->at($values, $header, ['CANTIDAD MES CERVIX', 'CANTIDAD MES PROSTATA'])),
            ];

            $items[] = $this->item(
                $catalogo,
                $code,
                $route,
                'SERVICIO_TECNICO',
                $description,
                $this->numberValue($this->at($values, $header, ['TARIFA'])),
                $metadata
            );
            $count += $this->flushItems($items);
        }

        return $count + $this->flushItems($items, true);
    }

    private function importOncology(iterable $rows, CatalogoReferencia $catalogo, string $route): int
    {
        $section = '';
        $header = null;
        $items = [];
        $count = 0;

        foreach ($rows as $row) {
            $values = $this->rowValues($row);
            $rowText = $this->normaliseHeader(implode(' ', array_map(fn (mixed $value): string => $this->stringValue($value), $values)));

            if (str_contains($rowText, 'QUIMIOTERAPIA')) {
                $section = 'QUIMIOTERAPIA';
                $header = null;

                continue;
            }

            if (str_contains($rowText, 'RADIOTERAPIA')) {
                $section = 'RADIOTERAPIA';
                $header = null;

                continue;
            }

            $candidate = $this->headerMap($values);

            if (isset($candidate['CUPS']) && ($section !== '' || isset($candidate['TARIFA']))) {
                $header = $candidate;

                continue;
            }

            if ($header === null) {
                continue;
            }

            $code = $this->normaliseCode($this->at($values, $header, ['CUPS']));

            if (! $this->isCatalogCode($code)) {
                continue;
            }

            $category = $section === 'QUIMIOTERAPIA'
                ? 'ONCOLOGIA_QUIMIOTERAPIA'
                : 'ONCOLOGIA_RADIOTERAPIA';
            $scheme = $this->stringValue($this->at($values, $header, ['MXS CONTEMPLADOS', 'ESQUEMA', 'MEDICAMENTO']));
            $applicationRate = $this->numberValue($this->at($values, $header, ['TARIFA APLICACION CICLO', 'TARIFA']));
            $treatmentRate = $this->numberValue($this->at($values, $header, ['TARIFA TRATAMIENTO', 'TARIFA']));
            $tariff = $category === 'ONCOLOGIA_QUIMIOTERAPIA' ? $applicationRate : $treatmentRate;

            $items[] = $this->item(
                $catalogo,
                $code,
                $route,
                $category,
                $this->stringValue($this->at($values, $header, ['NOMBRE DEL CUPS', 'NOMBRE ESTANDAR', 'VALORES', 'SERVICIO'])),
                $tariff,
                [
                    'esquema' => $scheme,
                    'tarifa_medicamento' => $this->numberValue($this->at($values, $header, ['TARIFA MEDICAMENTO'])),
                    'cantidad_referencia' => $this->numberValue($this->at($values, $header, ['CANTIDAD', 'FRECUENCIA'])),
                ]
            );
            $count += $this->flushItems($items);
        }

        return $count + $this->flushItems($items, true);
    }

    private function importPalliativeDrugs(iterable $rows, CatalogoReferencia $catalogo, string $route): int
    {
        $header = null;
        $items = [];
        $count = 0;

        foreach ($rows as $row) {
            $values = $this->rowValues($row);

            if ($header === null) {
                $candidate = $this->headerMap($values);

                if (isset($candidate['MEDICAMENTO']) || isset($candidate['GRUPO']) || isset($candidate['SUBGRUPOFARMACOLOGICO'])) {
                    $header = $candidate;
                }

                continue;
            }

            $description = $this->stringValue($this->at($values, $header, [
                'MEDICAMENTO',
                'GRUPO',
                'SUBGRUPO FARMACOLOGICO',
                'DESCRIPCION',
            ]));

            if ($description === '') {
                continue;
            }

            $items[] = $this->item(
                $catalogo,
                'PALIATIVO:'.Str::upper(Str::slug($description, '_')),
                $route,
                'PALIATIVOS',
                $description,
                null,
                [
                    'cantidad_anual' => $this->numberValue($this->at($values, $header, [
                        'CANTIDAD ANUAL',
                        'CANTIDAD ANO CERVIX',
                        'CANTIDAD ANO PROSTATA',
                    ])),
                    'costo_anual' => $this->numberValue($this->at($values, $header, [
                        'COSTO ANUAL',
                        'COSTO CERVIX ANO',
                        'COSTO PROSTATA ANO',
                    ])),
                    'costo_mensual' => $this->numberValue($this->at($values, $header, ['COSTO MES', 'COSTO MENSUAL'])),
                ]
            );
            $count += $this->flushItems($items);
        }

        return $count + $this->flushItems($items, true);
    }

    private function importTechnicalSummary(iterable $rows, CatalogoReferencia $catalogo, string $route): int
    {
        $items = [];
        $count = 0;

        foreach ($rows as $row) {
            $values = $this->rowValues($row);
            $description = $this->stringValue($values[1] ?? null);
            $cost = $this->numberValue($values[2] ?? null);

            if ($description === '' || $cost === null) {
                continue;
            }

            $items[] = $this->item(
                $catalogo,
                'RESUMEN:'.Str::upper(Str::slug($description, '_')),
                $route,
                'RESUMEN_TECNICO',
                $description,
                $cost,
                ['medida' => 'COSTO_MENSUAL_REFERENCIA']
            );
            $count += $this->flushItems($items);
        }

        return $count + $this->flushItems($items, true);
    }

    private function importPopulation(iterable $rows, CatalogoReferencia $catalogo, string $route): int
    {
        $items = [];
        $count = 0;

        foreach ($rows as $row) {
            $values = $this->rowValues($row);
            $population = $this->stringValue($values[0] ?? null);
            $quantity = $this->numberValue($values[1] ?? null);

            if (! in_array(Str::upper($population), ['CERVIX', 'PROSTATA'], true) || $quantity === null) {
                continue;
            }

            $items[] = $this->item(
                $catalogo,
                'POBLACION:'.Str::upper($population),
                $route,
                'POBLACION_OBJETIVO',
                Str::upper($population),
                null,
                ['poblacion_referencia' => $quantity]
            );
            $count += $this->flushItems($items);
        }

        return $count + $this->flushItems($items, true);
    }

    private function importHospitalizationReference(iterable $rows, CatalogoReferencia $catalogo, string $route): int
    {
        $items = [];
        $count = 0;

        foreach ($rows as $row) {
            $values = $this->rowValues($row);
            $textValues = array_values(array_filter(
                array_map(fn (mixed $value): string => $this->stringValue($value), $values),
                static fn (string $value): bool => $value !== ''
            ));
            $rowText = $this->normaliseHeader(implode(' ', $textValues));

            if (! str_contains($rowText, 'HOSPITAL') && ! str_contains($rowText, 'COSTO')) {
                continue;
            }

            $description = $textValues[0] ?? '';
            $numbers = array_values(array_filter(
                array_map(fn (mixed $value): ?float => $this->numberValue($value), $values),
                static fn (?float $value): bool => $value !== null
            ));

            if ($description === '' || $numbers === []) {
                continue;
            }

            $items[] = $this->item(
                $catalogo,
                'HX:'.Str::upper(Str::slug($description, '_')),
                $route,
                'HX_HOSPITALIZACION',
                $description,
                null,
                ['valores_referencia' => $numbers]
            );
            $count += $this->flushItems($items);
        }

        return $count + $this->flushItems($items, true);
    }

    private function importTransportAndLodgingReference(iterable $rows, CatalogoReferencia $catalogo, string $route): int
    {
        $references = [
            'TRANSPORTE' => [],
            'ALBERGUE' => [],
        ];

        foreach ($rows as $row) {
            $values = $this->rowValues($row);

            foreach ([[0, 1], [4, 5]] as [$labelIndex, $valueIndex]) {
                $label = $this->stringValue($values[$labelIndex] ?? null);
                $value = $this->numberValue($values[$valueIndex] ?? null);
                $key = str_contains($this->normaliseHeader($label), 'ALBERGUE') ? 'ALBERGUE' : 'TRANSPORTE';

                if ($label === '' || $value === null) {
                    continue;
                }

                $references[$key][$label] = $value;
            }
        }

        $items = [];

        foreach ($references as $key => $values) {
            if ($values === []) {
                continue;
            }

            $tariff = collect($values)
                ->first(static fn (float $value, string $label): bool => str_contains(
                    (string) Str::of($label)->ascii()->upper(),
                    'TARIFA'
                ));

            $items[] = $this->item(
                $catalogo,
                'TRANSPORTE:'.$key,
                $route,
                'TRANSPORTE_ALBERGUE',
                Str::ucfirst(Str::lower($key)),
                $tariff,
                ['valores_referencia' => $values]
            );
        }

        return $this->flushItems($items, true);
    }

    /**
     * @param  callable(string, iterable<int, Row>): int  $readSheet
     */
    private function readWorkbook(string $path, callable $readSheet): int
    {
        $reader = new Reader;
        $reader->open($path);
        $count = 0;

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $this->currentSheet = $sheet->getName();
                $count += $readSheet($sheet->getName(), $sheet->getRowIterator());
            }
        } finally {
            $reader->close();
        }

        return $count;
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
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function item(
        CatalogoReferencia $catalogo,
        string $code,
        ?string $route,
        string $category,
        string $description,
        ?float $rate = null,
        array $metadata = []
    ): array {
        $timestamp = now();

        return [
            'catalogo_referencia_id' => $catalogo->id,
            'codigo' => $code,
            'hoja' => $this->currentSheet,
            'ruta' => $route,
            'categoria' => $category,
            'descripcion' => $description === '' ? null : $description,
            'tarifa_referencia' => $rate,
            'activo' => true,
            'metadatos' => $metadata === [] ? null : json_encode($metadata, JSON_THROW_ON_ERROR),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function flushItems(array &$items, bool $force = false): int
    {
        if ($items === [] || (! $force && count($items) < 500)) {
            return 0;
        }

        CatalogoReferenciaItem::query()->insert($items);
        $count = count($items);
        $items = [];

        return $count;
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
        $string = $this->stringValue($value);

        if (preg_match('/^\d+\.0+$/', $string) === 1) {
            $string = (string) (int) $string;
        }

        return (string) Str::of($string)
            ->ascii()
            ->upper()
            ->replace(['.', ' ', '/'], '')
            ->trim();
    }

    private function isCatalogCode(string $code): bool
    {
        return preg_match('/^(?=.*\d)[A-Z0-9-]+$/', $code) === 1;
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

    private function currencyValue(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $value = preg_replace('/[^0-9,.-]/', '', $this->stringValue($value)) ?? '';

        if ($value === '') {
            return null;
        }

        if (str_contains($value, ',') && str_contains($value, '.')) {
            if (strrpos($value, ',') > strrpos($value, '.')) {
                $value = str_replace('.', '', $value);
                $value = str_replace(',', '.', $value);
            } else {
                $value = str_replace(',', '', $value);
            }
        } elseif (str_contains($value, ',')) {
            $value = preg_match('/,\d{3}(,\d{3})*$/', $value) === 1
                ? str_replace(',', '', $value)
                : str_replace(',', '.', $value);
        } elseif (preg_match('/\.\d{3}(\.\d{3})*$/', $value) === 1) {
            $value = str_replace('.', '', $value);
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function dateValue(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $value = $this->stringValue($value);

        if ($value === '') {
            return '';
        }

        try {
            return (new DateTimeImmutable($value))->format('Y-m-d');
        } catch (\Throwable) {
            return '';
        }
    }

    private function stringValue(mixed $value): string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return '';
        }

        return trim((string) $value);
    }

    private function dateFromFileName(string $fileName): ?DateTimeImmutable
    {
        if (preg_match('/(20\d{2})(\d{2})(\d{2})/', $fileName, $matches) !== 1) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('Ymd', implode('', array_slice($matches, 1)));

        return $date === false ? null : $date;
    }
}
