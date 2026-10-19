<?php

namespace App\Services;

use App\Models\CatalogoReferencia;
use App\Models\CatalogoReferenciaItem;
use App\Models\User;
use App\Programas\LaMaria\EstadoContractual;
use App\Programas\LaMaria\LaMariaPrograma;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use InvalidArgumentException;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;
use RuntimeException;

class CatalogoReferenciaImporter
{
    private string $currentSheet = '';

    /**
     * Sistema de fechas del libro: 1904 (Mac) o 1900 (Windows).
     */
    private bool $date1904 = false;

    /**
     * Diagnóstico de lectura por hoja: filas leídas, aceptadas y rechazadas por motivo.
     *
     * @var array<string, array{leidas: int, aceptadas: int, rechazadas: array<string, int>, hoja_rechazada: string}>
     */
    private array $diagnostics = [];

    public const TYPE_ANEXOS_2706 = 'ANEXOS_2706';

    public const TYPE_MEDICAMENTOS_CUM = 'MEDICAMENTOS_CUM';

    public const TYPE_NOTA_CERVIX = 'NOTA_CERVIX';

    public const TYPE_NOTA_PROSTATA = 'NOTA_PROSTATA';

    public const TYPE_NOTA_MEDICAMENTOS = 'NOTA_MEDICAMENTOS';

    public const TYPE_DISPENSACION_CERVIX = 'DISPENSACION_CERVIX';

    public const TYPE_DISPENSACION_PROSTATA = 'DISPENSACION_PROSTATA';

    public const TYPE_COMPLEMENTO_MEDICAMENTOS_PROSTATA = 'COMPLEMENTO_MEDICAMENTOS_PROSTATA';

    public function __construct(private CatalogoReferenciaActivator $activator) {}

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
            self::TYPE_COMPLEMENTO_MEDICAMENTOS_PROSTATA => 'Complemento contractual de medicamentos de próstata',
        ];
    }

    /**
     * Importa una versión nueva del catálogo. Con $activar = false la versión
     * queda cargada pero inactiva, para revisarla antes de activarla.
     *
     * @return array{catalogo: CatalogoReferencia, items: int, diagnostico: array<string, array<string, mixed>>}
     */
    public function import(
        UploadedFile $file,
        string $type,
        string $version,
        User $user,
        string $programaSlug = LaMariaPrograma::SLUG,
        ?int $contratoId = null,
        bool $activar = true
    ): array {
        $this->diagnostics = [];

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
                self::TYPE_COMPLEMENTO_MEDICAMENTOS_PROSTATA => $this->importMedicationComplement($path, $catalogo, 'PROSTATA'),
            };

            if ($itemCount === 0) {
                throw new InvalidArgumentException('No se encontraron registros reconocibles para el tipo de catálogo seleccionado.');
            }

            // Activa solo dentro del mismo programa+tipo, verificada y auditada;
            // conserva las versiones previas inactivas.
            if ($activar) {
                $this->activator->activar($catalogo, null);
            }
        } catch (\Throwable $exception) {
            $catalogo->delete();

            throw $exception;
        }

        return [
            'catalogo' => $catalogo->fresh(),
            'items' => $itemCount,
            'diagnostico' => $this->diagnostics,
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
            $sheetItems = [];
            $rowNumber = 0;
            $diagnostic = ['leidas' => 0, 'aceptadas' => 0, 'rechazadas' => [], 'hoja_rechazada' => ''];

            foreach ($rows as $row) {
                $rowNumber++;
                $values = $this->rowValues($row);

                if ($header === null) {
                    $candidate = $this->headerMap($values);

                    if (isset($candidate['DOCUMENTOPACIENTE'], $candidate['CODIGOCUM'])) {
                        $header = $candidate;
                    }

                    continue;
                }

                if (array_filter($values, fn (mixed $value): bool => $this->stringValue($value) !== '') === []) {
                    continue;
                }

                $diagnostic['leidas']++;
                $document = $this->normaliseCode($this->at($values, $header, ['DOCUMENTO PACIENTE']));
                $code = $this->normaliseCode($this->at($values, $header, ['CODIGO CUM']));
                $dispensationDate = $this->dateValue($this->at($values, $header, ['FECHA DISPENSACION']));
                $units = $this->numberValue($this->at($values, $header, ['UNIDADES DISPENSADAS', 'CANTIDAD ENTREGADA']));
                $rawUnitRate = $this->at($values, $header, ['VALOR UNITARIO MEDICAMENTOS DISPENSADOS', 'VALOR UNITARIO']);
                $rawTotalRate = $this->at($values, $header, ['VALOR TOTAL MEDICAMENTOS DISPENSADOS', 'VALOR TOTAL']);
                $unitRate = $this->currencyValue($rawUnitRate);
                $totalRate = $this->currencyValue($rawTotalRate);

                // Una columna desplazada o equivocada no puede convertirse en documento,
                // CUM, fecha, cantidad o precio: la fila se rechaza con su motivo.
                $rejection = match (true) {
                    preg_match('/^(?=.*\d)[A-Z0-9]{3,20}$/', $document) !== 1 => 'DOCUMENTO_INVALIDO',
                    preg_match('/^\d{3,9}-\d{1,3}$/', $code) !== 1 => 'CUM_INVALIDO',
                    ! $this->isPlausibleDate($dispensationDate) => 'FECHA_INVALIDA',
                    $units === null || $units < 0 => 'UNIDADES_INVALIDAS',
                    $unitRate === null && $this->hasContent($rawUnitRate) => 'VALOR_UNITARIO_NO_INTERPRETABLE',
                    $totalRate === null && $this->hasContent($rawTotalRate) => 'VALOR_TOTAL_NO_INTERPRETABLE',
                    default => null,
                };

                if ($rejection !== null) {
                    $diagnostic['rechazadas'][$rejection] = ($diagnostic['rechazadas'][$rejection] ?? 0) + 1;

                    continue;
                }

                if ($unitRate === null && $totalRate !== null && $units > 0) {
                    $unitRate = round($totalRate / $units, 4);
                }

                $description = $this->stringValue($this->at($values, $header, [
                    'NOMBRE GENERICO',
                    'DESCRIPCION MEDICAMENTOS DISPENSADOS',
                ]));

                $sheetItems[] = $this->item(
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
                        'control_valor' => $this->valueControl($units, $unitRate, $totalRate),
                        'fila' => $rowNumber,
                        'origen_fecha' => 'FECHA DISPENSACION',
                    ]
                );
                $diagnostic['aceptadas']++;
            }

            $rejected = $diagnostic['leidas'] - $diagnostic['aceptadas'];

            // Si la mayoría de las filas no supera la validación, los encabezados no
            // son confiables (por ejemplo, desplazados) y la hoja completa se descarta.
            if ($diagnostic['leidas'] >= 10 && $rejected > $diagnostic['leidas'] / 2) {
                $diagnostic['hoja_rechazada'] = 'ENCABEZADOS_NO_CONFIABLES';
                $diagnostic['aceptadas'] = 0;
                $sheetItems = [];
            }

            if ($header !== null) {
                $this->diagnostics[$sheetName] = $diagnostic;
            }

            $count = 0;

            foreach (array_chunk($sheetItems, 500) as $chunk) {
                $count += $this->flushItems($chunk, true);
            }

            return $count;
        });
    }

    /**
     * Complemento contractual de medicamentos: una fila por CUM y precio, con su
     * vigencia, documento fuente, evidencia y estado contractual. No se deducen
     * vigencias: si el archivo no las trae quedan vacías, y un precio VERIFICADO
     * exige evidencia contractual y vigencia inicial. Nunca se identifica el
     * medicamento por un CUPS.
     */
    private function importMedicationComplement(string $path, CatalogoReferencia $catalogo, string $route): int
    {
        return $this->readWorkbook($path, function (string $sheetName, iterable $rows) use ($catalogo, $route): int {
            $header = null;
            $items = [];
            $rowNumber = 0;
            $diagnostic = ['leidas' => 0, 'aceptadas' => 0, 'rechazadas' => [], 'hoja_rechazada' => ''];

            foreach ($rows as $row) {
                $rowNumber++;
                $values = $this->rowValues($row);

                if ($header === null) {
                    $candidate = $this->headerMap($values);

                    if (isset($candidate['CUM'], $candidate['TARIFA'], $candidate['ESTADOCONTRACTUAL'])) {
                        $header = $candidate;
                    }

                    continue;
                }

                if (array_filter($values, fn (mixed $value): bool => $this->stringValue($value) !== '') === []) {
                    continue;
                }

                $diagnostic['leidas']++;
                $code = $this->normaliseCode($this->at($values, $header, ['CUM']));
                $ium = $this->normaliseCode($this->at($values, $header, ['IUM']));
                $rawRate = $this->at($values, $header, ['TARIFA']);
                $rate = $this->currencyValue($rawRate);
                $state = strtoupper($this->stringValue($this->at($values, $header, ['ESTADO CONTRACTUAL'])));
                $state = $state === '' ? EstadoContractual::Pendiente->value : $state;
                $rawFrom = $this->at($values, $header, ['VIGENCIA DESDE']);
                $rawTo = $this->at($values, $header, ['VIGENCIA HASTA']);
                $validFrom = $this->dateValue($rawFrom);
                $validTo = $this->dateValue($rawTo);
                $evidence = $this->stringValue($this->at($values, $header, ['EVIDENCIA CONTRACTUAL']));

                $rejection = match (true) {
                    preg_match('/^\d{3,9}-\d{1,3}$/', $code) !== 1 => 'CUM_INVALIDO',
                    $ium !== '' && preg_match('/^[0-9A-Z]{15}$/', $ium) !== 1 => 'IUM_INVALIDO',
                    $rate === null || $rate < 0 => 'TARIFA_INVALIDA',
                    EstadoContractual::tryFrom($state) === null => 'ESTADO_CONTRACTUAL_INVALIDO',
                    ($this->hasContent($rawFrom) && ! $this->isPlausibleDate($validFrom))
                        || ($this->hasContent($rawTo) && ! $this->isPlausibleDate($validTo))
                        || ($validFrom !== '' && $validTo !== '' && $validTo < $validFrom) => 'VIGENCIA_INVALIDA',
                    $state === EstadoContractual::Verificado->value && ($evidence === '' || $validFrom === '') => 'VERIFICADO_SIN_EVIDENCIA_O_VIGENCIA',
                    default => null,
                };

                if ($rejection !== null) {
                    $diagnostic['rechazadas'][$rejection] = ($diagnostic['rechazadas'][$rejection] ?? 0) + 1;

                    continue;
                }

                $principle = $this->stringValue($this->at($values, $header, ['PRINCIPIO ACTIVO']));
                $concentration = $this->stringValue($this->at($values, $header, ['CONCENTRACION']));
                $form = $this->stringValue($this->at($values, $header, ['FORMA FARMACEUTICA']));
                $items[] = $this->item(
                    $catalogo,
                    $code,
                    $route,
                    'MEDICAMENTO_COMPLEMENTARIO',
                    trim(implode(' ', array_filter([$principle, $concentration, $form]))),
                    $rate,
                    [
                        'ium' => $ium,
                        'principio_activo' => $principle,
                        'concentracion' => $concentration,
                        'forma_farmaceutica' => $form,
                        'vigencia_desde' => $validFrom,
                        'vigencia_hasta' => $validTo,
                        'documento_fuente' => $this->stringValue($this->at($values, $header, ['DOCUMENTO FUENTE'])),
                        'evidencia_contractual' => $evidence,
                        'estado_contractual' => $state,
                        'periodo_precio_observado' => $this->stringValue($this->at($values, $header, ['PERIODO PRECIO OBSERVADO'])),
                        'observacion' => $this->stringValue($this->at($values, $header, ['OBSERVACION'])),
                        'fila' => $rowNumber,
                    ]
                );
                $diagnostic['aceptadas']++;
            }

            if ($header !== null) {
                $this->diagnostics[$sheetName] = $diagnostic;
            }

            return $this->flushItems($items, true);
        });
    }

    /**
     * UNIDADES × VALOR UNITARIO frente a VALOR TOTAL, sin modificar ninguno.
     */
    private function valueControl(?float $units, ?float $unitRate, ?float $totalRate): string
    {
        if ($units === null || $unitRate === null || $totalRate === null) {
            return 'SIN_VALOR';
        }

        $difference = abs($units * $unitRate - $totalRate);

        return match (true) {
            $difference <= 1 => 'CUADRA',
            $difference <= max(5, 0.001 * abs($totalRate)) => 'REDONDEO',
            default => 'INCONSISTENTE',
        };
    }

    private function hasContent(mixed $value): bool
    {
        return ! in_array(str_replace(['$', ' ', "\u{00A0}"], '', $this->stringValue($value)), ['', '-'], true);
    }

    private function isPlausibleDate(string $date): bool
    {
        return preg_match('/^(\d{4})-\d{2}-\d{2}$/', $date, $matches) === 1
            && (int) $matches[1] >= 2000
            && (int) $matches[1] <= 2100;
    }

    /**
     * Fecha del registro INVIMA (formato mm/dd/aaaa) en aaaa-mm-dd.
     */
    private function registryDate(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $text = $this->stringValue($value);

        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $text, $matches) === 1) {
            return $matches[3].'-'.$matches[1].'-'.$matches[2];
        }

        return $this->dateValue($value);
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
                    'ium' => $this->normaliseCode($this->at($values, $header, ['IUM'])),
                    'expediente' => $this->normaliseCode($this->at($values, $header, ['EXPEDIENTE'])),
                    'registro_sanitario' => $this->stringValue($this->at($values, $header, ['REGISTROSANITARIO'])),
                    'estado_registro' => $this->stringValue($this->at($values, $header, ['ESTADOREGISTRO'])),
                    'cantidad_cum' => $this->numberValue($this->at($values, $header, ['CANTIDADCUM'])),
                    'unidad' => $this->stringValue($this->at($values, $header, ['UNIDAD'])),
                    'cantidad' => $this->numberValue($this->at($values, $header, ['CANTIDAD'])),
                    'unidad_medida' => $this->stringValue($this->at($values, $header, ['UNIDADMEDIDA'])),
                    'unidad_referencia' => $this->stringValue($this->at($values, $header, ['UNIDADREFERENCIA'])),
                    'muestra_medica' => $this->stringValue($this->at($values, $header, ['MUESTRAMEDICA'])),
                    'fecha_activo' => $this->registryDate($this->at($values, $header, ['FECHAACTIVO'])),
                    'fecha_inactivo' => $this->registryDate($this->at($values, $header, ['FECHAINACTIVO'])),
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
                str_contains($normalisedName, 'MEDICAMENTOSNUEVOS') => $this->importComplementaryMedicines($rows, $catalogo, $route),
                $normalisedName === 'POBLACION' => $this->importPopulation($rows, $catalogo, $route),
                default => 0,
            };
        }, preserveEmptyRows: true);
    }

    /**
     * Importa la hoja "Medicamentos nuevos" con la ruta del catálogo que se
     * está cargando (próstata o cérvix).
     */
    private function importComplementaryMedicines(iterable $rows, CatalogoReferencia $catalogo, string $route): int
    {
        $header = null;
        $items = [];
        $count = 0;

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

            $items[] = $this->item(
                $catalogo,
                $code,
                $route,
                'MEDICAMENTOS_NUEVOS',
                $this->stringValue($this->at($values, $header, ['MEDICAMENTO A AGREGAR'])),
                $this->numberValue($this->at($values, $header, ['VALOR UNITARIO'])),
                [
                    'presentacion' => $this->stringValue($this->at($values, $header, ['PRESENTACION EXACTA'])),
                    'cups' => $this->normaliseCode($this->at($values, $header, ['CUPS'])),
                    'periodo_precio' => $this->stringValue($this->at($values, $header, ['PERIODO DEL PRECIO'])),
                ]
            );
            $count += $this->flushItems($items);
        }

        return $count + $this->flushItems($items, true);
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
            $servicio = $this->stringValue($this->at($values, $header, ['SERVICIO']));

            if ($servicio !== '') {
                $metadata['servicio'] = $servicio;
            }

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

        foreach ($rows as $rowNumber => $row) {
            $values = $this->rowValues($row);
            $code = $header === null ? '' : $this->normaliseCode($this->at($values, $header, ['CUPS']));

            // Una fila con CUPS válido siempre es un servicio, aunque su descripción
            // mencione QUIMIOTERAPIA o RADIOTERAPIA.
            if (! $this->isCatalogCode($code)) {
                $title = $this->oncologySectionTitle($values);

                if ($title !== null) {
                    $section = $title;
                    $header = null;

                    continue;
                }

                $candidate = $this->headerMap($values);

                if (isset($candidate['CUPS']) && ($section !== '' || isset($candidate['TARIFA']))) {
                    $header = $candidate;
                }

                continue;
            }

            $category = $section === 'QUIMIOTERAPIA'
                ? 'ONCOLOGIA_QUIMIOTERAPIA'
                : 'ONCOLOGIA_RADIOTERAPIA';
            $schemeAliases = ['MXS CONTEMPLADOS EN EL ESQUEMA', 'MXS CONTEMPLADOS', 'ESQUEMA', 'MEDICAMENTO'];
            $quantityAliases = ['CANTIDAD MES', 'CANTIDAD', 'FRECUENCIA'];
            $scheme = $this->stringValue($this->at($values, $header, $schemeAliases));
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
                    'cantidad_referencia' => $this->numberValue($this->at($values, $header, $quantityAliases)),
                    'frecuencia_anual' => $this->numberValue($this->at($values, $header, ['FREC ANO', 'FRECUENCIA ANUAL'])),
                    'naturaleza_cantidad' => 'PROYECCION_NOTA_TECNICA',
                    'unidad_referencia' => $category === 'ONCOLOGIA_QUIMIOTERAPIA' ? 'APLICACION_CICLO' : 'TRATAMIENTO',
                    'unidad_contractual' => null,
                    'fuente_celdas' => [
                        'tarifa' => $this->sourceCell($values, $header, $category === 'ONCOLOGIA_QUIMIOTERAPIA' ? ['TARIFA APLICACION CICLO', 'TARIFA'] : ['TARIFA TRATAMIENTO', 'TARIFA'], (int) $rowNumber),
                        'tarifa_medicamento' => $this->sourceCell($values, $header, ['TARIFA MEDICAMENTO'], (int) $rowNumber),
                        'cantidad_referencia' => $this->sourceCell($values, $header, $quantityAliases, (int) $rowNumber),
                        'esquema' => $this->sourceCell($values, $header, $schemeAliases, (int) $rowNumber),
                        'frecuencia_anual' => $this->sourceCell($values, $header, ['FREC ANO', 'FRECUENCIA ANUAL'], (int) $rowNumber),
                    ],
                ]
            );
            $count += $this->flushItems($items);
        }

        return $count + $this->flushItems($items, true);
    }

    /**
     * Título de sección: una fila con una sola celda cuyo texto completo es
     * QUIMIOTERAPIA o RADIOTERAPIA.
     *
     * @param  array<int, mixed>  $values
     */
    private function oncologySectionTitle(array $values): ?string
    {
        $texts = array_values(array_filter(
            array_map(fn (mixed $value): string => $this->stringValue($value), $values),
            static fn (string $value): bool => $value !== ''
        ));

        if (count($texts) !== 1) {
            return null;
        }

        $title = $this->normaliseHeader($texts[0]);

        return in_array($title, ['QUIMIOTERAPIA', 'RADIOTERAPIA'], true) ? $title : null;
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

        $sourceCells = ['TRANSPORTE' => [], 'ALBERGUE' => []];
        $blocks = [];
        foreach ($rows as $rowNumber => $row) {
            $values = $this->rowValues($row);

            foreach ([[0, 1], [4, 5]] as [$labelIndex, $valueIndex]) {
                $label = $this->stringValue($values[$labelIndex] ?? null);
                $value = $this->numberValue($values[$valueIndex] ?? null);
                $normalisedLabel = $this->normaliseHeader($label);
                if (str_contains($normalisedLabel, 'TARIFA')) {
                    $block = match (true) {
                        str_contains($normalisedLabel, 'ALBERGUE') => 'ALBERGUE',
                        str_contains($normalisedLabel, 'TRANSPORTE') => 'TRANSPORTE',
                        default => null,
                    };
                    if ($block !== null) {
                        $blocks[$labelIndex] = $block;
                    }
                }
                $key = $blocks[$labelIndex] ?? null;

                if ($key === null || $label === '' || $value === null) {
                    continue;
                }

                $references[$key][$label] = $value;
                $field = match (true) {
                    str_contains($normalisedLabel, 'TARIFA') => 'tarifa',
                    str_contains($normalisedLabel, 'FRECUENCIA') => 'frecuencia_anual',
                    str_starts_with($normalisedLabel, 'COSTO') => 'costo_anual',
                    str_starts_with($normalisedLabel, 'TOTAL') => 'cantidad_proyectada',
                    default => null,
                };
                if ($field !== null) {
                    $sourceCells[$key][$field] = $this->sourceCell($values, [$normalisedLabel => $valueIndex], [$label], (int) $rowNumber);
                }
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
                [
                    'valores_referencia' => $values,
                    'cantidad_proyectada' => $sourceCells[$key]['cantidad_proyectada']['valor_original'] ?? null,
                    'frecuencia_anual' => $sourceCells[$key]['frecuencia_anual']['valor_original'] ?? null,
                    'naturaleza_cantidad' => 'PROYECCION_NOTA_TECNICA',
                    'unidad_contractual' => null,
                    'fuente_celdas' => $sourceCells[$key],
                ]
            );
        }

        return $this->flushItems($items, true);
    }

    /**
     * @param  callable(string, iterable<int, Row>): int  $readSheet
     */
    /**
     * Convierte un serial de Excel en fecha según el sistema del libro. En el
     * sistema 1900, Excel cuenta el 29 de febrero de 1900, que no existe.
     */
    private function excelSerialDate(float $serial): ?DateTimeImmutable
    {
        $days = (int) floor($serial);

        if ($days < 0 || $days > 2958465) {
            return null;
        }

        if ($this->date1904) {
            return (new DateTimeImmutable('1904-01-01'))->modify('+'.$days.' days');
        }

        if ($days < 1) {
            return null;
        }

        return (new DateTimeImmutable('1899-12-31'))->modify('+'.($days >= 60 ? $days - 1 : $days).' days');
    }

    private function workbookUsesDate1904(string $path): bool
    {
        $zip = new \ZipArchive;

        if ($zip->open($path) !== true) {
            return false;
        }

        $workbook = (string) $zip->getFromName('xl/workbook.xml');
        $zip->close();

        return preg_match('/<(?:\w+:)?workbookPr\b[^>]*\bdate1904="(?:1|true)"/i', $workbook) === 1;
    }

    private function readWorkbook(string $path, callable $readSheet, bool $preserveEmptyRows = false): int
    {
        $this->date1904 = $this->workbookUsesDate1904($path);
        $options = new Options;
        $options->SHOULD_PRESERVE_EMPTY_ROWS = $preserveEmptyRows;
        $reader = new Reader($options);
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
     * @param  array<int, mixed>  $values
     * @param  array<string, int>  $header
     * @param  list<string>  $aliases
     * @return array{hoja: string, celda: string, valor_original: mixed}|null
     */
    private function sourceCell(array $values, array $header, array $aliases, int $rowNumber): ?array
    {
        foreach ($aliases as $alias) {
            $index = $header[$this->normaliseHeader($alias)] ?? null;
            if ($index === null) {
                continue;
            }
            $column = '';
            $position = $index + 1;
            while ($position > 0) {
                $position--;
                $column = chr(65 + $position % 26).$column;
                $position = intdiv($position, 26);
            }

            return ['hoja' => $this->currentSheet, 'celda' => $column.$rowNumber, 'valor_original' => $values[$index] ?? null];
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

        if ($rate !== null && in_array($catalogo->tipo, [
            self::TYPE_NOTA_PROSTATA,
            self::TYPE_NOTA_CERVIX,
            self::TYPE_NOTA_MEDICAMENTOS,
        ], true)) {
            $metadata['tarifa_fuente_precision'] = $rate;
        }

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

        // Fecha guardada como número serial de Excel (celda con formato General).
        if (is_int($value) || is_float($value) || preg_match('/^\d{1,7}(\.\d+)?$/', $this->stringValue($value)) === 1) {
            return $this->excelSerialDate((float) $this->stringValue($value))?->format('Y-m-d') ?? '';
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
