<?php

namespace App\Http\Controllers;

use App\Models\ProgramaEjecucion;
use App\Programas\LaMaria\LaMariaCervixPipeline;
use App\Programas\LaMaria\LaMariaPrograma;
use App\Programas\LaMaria\LaMariaProstataPipeline;
use App\Services\ActivityLogger;
use App\Services\ReferenciaRutaMatcher;
use App\Services\SeguimientoRutaClassifier;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SeguimientoRutaController extends Controller
{
    private const MONTHLY_SUMMARY_HEADERS = [
        'MES ATENCION', 'RUTA DE CÁLCULO', 'PACIENTES', 'FACTURAS', 'SERVICIOS',
        'SERVICIOS CON TARIFA', 'SERVICIOS SIN TARIFA', 'VALOR RIPS REPORTADO',
        'COSTO ESTIMADO REFERENCIA', 'CASOS A REVISAR',
    ];

    private const PATIENT_HEADERS = [
        'MES ATENCION', 'TIPO DOCUMENTO', 'DOCUMENTO', 'SEXO',
        'FECHA NACIMIENTO', 'MUNICIPIO', 'ZONA',
        'RUTA FINAL',
        'COD DIAGNOSTICOS', 'FACTURAS ASOCIADAS', 'PRIMERA ATENCION',
        'ULTIMA ATENCION', 'CONSULTAS', 'PROCEDIMIENTOS', 'MEDICAMENTOS',
        'OTROS SERVICIOS', 'URGENCIAS', 'HOSPITALIZACIONES',
        'TOTAL SERVICIOS', 'SERVICIOS CON TARIFA', 'SERVICIOS SIN TARIFA',
        'VALOR RIPS REPORTADO', 'COSTO ESTIMADO REFERENCIA',
        'TIPO USUARIO', 'PAIS ORIGEN', 'PAIS RESIDENCIA', 'INCAPACIDAD',
        'REGISTRO SIRAS', 'CONSECUTIVO USUARIO',
    ];

    private const DETAIL_HEADERS = [
        'MES ATENCION', 'FACTURA', 'COD PRESTADOR', 'TIPO DOCUMENTO', 'DOCUMENTO', 'SEXO', 'RUTA FINAL',
        'FECHA ATENCION',
        'TIPO SERVICIO', 'CODIGO', 'NOMBRE NOTA TECNICA', 'GRUPO TECNICO',
        'FINALIDAD TECNOLOGIA SALUD', 'CAUSA MOTIVO ATENCION',
        'COD DIAGNOSTICO PRINCIPAL', 'AUTORIZACION',
        'CANTIDAD', 'VALOR RIPS REPORTADO', 'TARIFA REFERENCIA',
        'COSTO ESTIMADO REFERENCIA', 'ATC',
        'ESTADO CRUCE', 'ARCHIVO ORIGEN', 'ÁMBITO',
    ];

    private const DETAIL_ENRICHMENT_HEADERS = [
        'TIPO USUARIO', 'PAIS ORIGEN', 'PAIS RESIDENCIA', 'INCAPACIDAD',
        'REGISTRO SIRAS', 'CONSECUTIVO USUARIO', 'CONSECUTIVO RIPS',
        'NOMBRE ORIGINAL RIPS', 'ID MIPRES', 'MODALIDAD GRUPO SERVICIO',
        'GRUPO SERVICIOS', 'COD SERVICIO', 'VIA INGRESO SERVICIO',
        'TIPO MEDICAMENTO', 'TIPO OTRO SERVICIO', 'CONCENTRACION MEDICAMENTO',
        'UNIDAD MEDIDA', 'FORMA FARMACEUTICA', 'UNIDAD MIN DISPENSA',
        'DIAS TRATAMIENTO', 'COD DIAGNOSTICO PRINCIPAL CIE11',
        'NOMBRE DIAGNOSTICO PRINCIPAL CIE11', 'COD DIAGNOSTICOS RELACIONADOS',
        'COD DIAGNOSTICOS RELACIONADOS CIE11',
        'NOMBRES DIAGNOSTICOS RELACIONADOS CIE11', 'TIPO DIAGNOSTICO PRINCIPAL',
        'COD COMPLICACION', 'COD COMPLICACION CIE11', 'NOMBRE COMPLICACION CIE11',
        'VALOR UNITARIO RIPS', 'VALOR DISPENSACION RIPS', 'CONCEPTO RECAUDO',
        'VALOR PAGO MODERADOR', 'NUM FEV PAGO MODERADOR', 'CODIGO VIDA',
    ];

    private const NOT_FOUND_HEADERS = [
        'MES ATENCION', 'FACTURA', 'TIPO DOCUMENTO', 'DOCUMENTO', 'SEXO', 'RUTA FINAL',
        'TIPO SERVICIO', 'CODIGO', 'NOMBRE RIPS (REFERENCIA)', 'COD DIAGNOSTICO PRINCIPAL',
        'CANTIDAD', 'VALOR RIPS REPORTADO', 'ESTADO CRUCE', 'ARCHIVO ORIGEN',
        'MOTIVO', 'CLASIFICACION REVISION',
    ];

    private const TECHNICAL_REFERENCE_HEADERS = [
        'RUTA', 'GRUPO TECNICO', 'CODIGO', 'DESCRIPCION', 'TARIFA REFERENCIA',
        'FRECUENCIA REFERENCIA', 'CANTIDAD MENSUAL REFERENCIA', 'COSTO MENSUAL REFERENCIA',
        'POBLACION REFERENCIA', 'DETALLE REFERENCIA', 'VERSION', 'ARCHIVO ORIGEN',
    ];

    private const CATALOG_HEADERS = [
        'CATALOGO', 'VERSION', 'ARCHIVO ORIGEN', 'FECHA REFERENCIA',
        'COINCIDENCIAS EN DETALLE', 'ESTADO',
    ];

    private const CONTROL_HEADERS = [
        'ARCHIVO ORIGEN', 'FACTURA', 'USUARIOS', 'SERVICIOS',
        'SERVICIOS SIN FECHA', 'MES INICIAL', 'MES FINAL', 'ESTADO CARGUE',
        'OBSERVACION',
    ];

    private const SQL_HEADERS = [
        'DOCUMENTO', 'ENCONTRADO EN SQL', 'RUTA', 'ESTADO VALIDACION', 'OBSERVACION',
    ];

    private const DUPLICATE_HEADERS = [
        'PACIENTE', 'FACTURA', 'FECHA ATENCION', 'TIPO SERVICIO', 'CODIGO',
        'CANTIDAD', 'VECES REPETIDO', 'COSTO ACUMULADO', 'ARCHIVO ORIGEN',
        'CLASIFICACION AUTOMATICA', 'REVISION SUGERIDA',
    ];

    public function index(string $ejecucion): View
    {
        $pipeline = $this->pipelineFor($ejecucion);

        return view('seguimiento-rutas', [
            'ejecucion' => $ejecucion,
            'pipeline' => $pipeline,
            'rutaCodigo' => $pipeline->rutaCodigo(),
            'catalogStatus' => ReferenciaRutaMatcher::catalogStatusForProgram(
                LaMariaPrograma::SLUG,
                $pipeline->ownedCatalogTypes()
            ),
            'exportRoute' => route('la-maria.'.$ejecucion.'.export'),
        ]);
    }

    public function export(
        string $ejecucion,
        Request $request,
        SeguimientoRutaClassifier $seguimientoRutaClassifier,
        ActivityLogger $activityLogger
    ): BinaryFileResponse {
        $pipeline = $this->pipelineFor($ejecucion);
        $referenciaRutaMatcher = new ReferenciaRutaMatcher;

        $validated = $request->validate([
            'archivos' => ['required', 'array', 'min:1', 'max:25'],
            'archivos.*' => [
                'required',
                'file',
                'mimes:json,txt',
                'extensions:json,txt',
                'max:512000',
            ],
            'sql_archivo' => ['nullable', 'file', 'mimes:sql,txt', 'extensions:sql,txt', 'max:102400'],
        ], [
            'archivos.required' => 'Selecciona al menos un archivo JSON.',
            'archivos.array' => 'Selecciona los archivos JSON para procesar.',
            'archivos.min' => 'Selecciona al menos un archivo JSON.',
            'archivos.max' => 'Puedes cargar hasta 25 archivos por vez.',
            'archivos.*.mimes' => 'Cada archivo debe ser un JSON válido.',
            'archivos.*.extensions' => 'Cada archivo debe tener extensión .json o .txt.',
            'archivos.*.max' => 'Cada archivo puede pesar hasta 500 MB.',
            'sql_archivo.file' => 'El archivo SQL no es válido.',
            'sql_archivo.max' => 'El archivo SQL puede pesar hasta 100 MB.',
        ]);

        $sqlFile = $request->file('sql_archivo');

        $files = $this->uploadedFiles($validated['archivos']);
        $batchId = (string) Str::uuid();
        $fileName = 'LA_MARIA_'.$pipeline->rutaCodigo().'_'.now()->format('Ymd_His').'_'
            .Str::lower(Str::random(8)).'.xlsx';
        $outputDirectory = storage_path('app/generated');
        $outputPath = $outputDirectory.DIRECTORY_SEPARATOR.$fileName;
        $tempFolder = storage_path('app/openspout-temp/'.$batchId);
        $reviewRowsPath = $tempFolder.DIRECTORY_SEPARATOR.'review-rows.ndjson';
        $notFoundRowsPath = $tempFolder.DIRECTORY_SEPARATOR.'not-found-rows.ndjson';

        File::ensureDirectoryExists($outputDirectory);
        File::ensureDirectoryExists($tempFolder);

        $options = new Options;
        $options->setTempFolder($tempFolder);

        $writer = new Writer($options);
        $writer->openToFile($outputPath);
        $writerClosed = false;
        $reviewRowsStream = null;
        $notFoundRowsStream = null;

        try {
            $allProfiles = $seguimientoRutaClassifier->profilesForFiles($files);
            $profiles = $seguimientoRutaClassifier->profilesForExecutionRoute(
                $allProfiles,
                $pipeline->rutaCodigo()
            );
            $profiles = $seguimientoRutaClassifier->validateRouteConsistency(
                $profiles,
                $pipeline->rutaCodigo()
            );
            $profiles = $seguimientoRutaClassifier->profilesWithReferenceCostsForFiles(
                $files,
                $profiles,
                $referenciaRutaMatcher,
                $pipeline->rutaCodigo()
            );
            $oppositeRouteProfiles = $seguimientoRutaClassifier->profilesForOppositeExecutionRoute(
                $allProfiles,
                $pipeline->rutaCodigo()
            );
            $oppositeRouteProfiles = $seguimientoRutaClassifier->validateRouteConsistency(
                $oppositeRouteProfiles,
                $pipeline->rutaCodigo()
            );
            $oppositeRouteProfiles = $seguimientoRutaClassifier->profilesWithReferenceCostsForFiles(
                $files,
                $oppositeRouteProfiles,
                $referenciaRutaMatcher,
                $pipeline->rutaCodigo()
            );
            $monthlySummaries = $this->monthlySummaries($profiles);
            $includeRegistroSiras = $this->profilesContainValue($allProfiles, 'registroSiras');
            $referenciaRutaMatcher->resetUsage();

            $summarySheet = $writer->getCurrentSheet();
            $summarySheet->setName('SEGUIMIENTO MENSUAL');
            $this->configureSheet($summarySheet, [14, 24, 14, 14, 14, 20, 20, 24, 28, 18]);
            $writer->addRow(Row::fromValues(self::MONTHLY_SUMMARY_HEADERS, $this->headerStyle()));

            foreach ($monthlySummaries as $summary) {
                $writer->addRow(Row::fromValues($summary));
            }

            $patientSheet = $writer->addNewSheetAndMakeItCurrent();
            $patientSheet->setName('SEGUIMIENTO PACIENTE MES');
            $this->configureSheet(
                $patientSheet,
                [14, 18, 20, 10, 16, 14, 12, 24, 24, 34, 22, 22, 14, 18, 18, 18, 14, 18, 18, 20, 20, 24, 28, 16, 16, 18, 16, 22, 22]
            );
            $writer->addRow(Row::fromValues(
                $this->patientHeaders($includeRegistroSiras),
                $this->headerStyle()
            ));

            foreach ($profiles as $profile) {
                if ($profile['estado'] !== 'LISTO') {
                    continue;
                }

                $writer->addRow(Row::fromValues(
                    $this->patientValues($profile, $includeRegistroSiras)
                ));
            }

            $detailSheet = $writer->addNewSheetAndMakeItCurrent();
            $detailSheet->setName('DETALLE RIPS');
            $this->configureSheet(
                $detailSheet,
                [
                    14, 18, 18, 18, 20, 10, 24, 52, 24, 22, 36, 36, 28, 28, 42, 28, 14, 24, 22, 28, 18, 28, 40, 14,
                    16, 16, 18, 16, 22, 22, 20, 44, 20, 28, 22, 20, 24, 20, 20, 24, 20, 24, 20, 18, 24, 38, 34, 34, 42, 26, 22, 24, 38, 22, 24, 22, 22, 26, 24,
                ]
            );
            $writer->addRow(Row::fromValues([
                ...self::DETAIL_HEADERS,
                ...$this->detailEnrichmentHeaders($includeRegistroSiras),
            ], $this->headerStyle()));

            $reviewRowsStream = $this->openTemporaryRowsStream($reviewRowsPath);
            $notFoundRowsStream = $this->openTemporaryRowsStream($notFoundRowsPath);
            $notFoundRowCount = 0;
            $reviewDetailRowCount = 0;
            $duplicateGroups = [];

            foreach ($seguimientoRutaClassifier->detailRowsForFiles(
                $files,
                $profiles,
                $referenciaRutaMatcher,
                $pipeline->rutaCodigo()
            ) as $detail) {
                if ($detail['estado'] !== 'LISTO') {
                    $this->writeTemporaryRow($reviewRowsStream, $detail);
                    $reviewDetailRowCount++;

                    continue;
                }

                $writer->addRow(Row::fromValues(
                    $this->detailValues($detail, $includeRegistroSiras)
                ));

                if ($this->belongsInNotFoundSheet($detail)) {
                    $this->writeTemporaryRow($notFoundRowsStream, $detail);
                    $notFoundRowCount++;
                }

                $duplicateKey = implode('|', [
                    $detail['numDocumentoIdentificacion'],
                    $detail['factura'],
                    $detail['fechaAtencion'],
                    $detail['tipoServicio'],
                    $detail['codigo'],
                    (string) $detail['cantidad'],
                ]);
                $duplicateGroups[$duplicateKey] ??= [
                    'detail' => $this->duplicateDetailValues($detail),
                    'count' => 0,
                    'cost' => 0.0,
                    'files' => [],
                ];
                $duplicateGroups[$duplicateKey]['count']++;
                $duplicateGroups[$duplicateKey]['cost'] += (float) ($detail['costoEstimadoReferencia'] ?? 0);
                $duplicateGroups[$duplicateKey]['files'][$detail['archivoOrigen']] = true;
            }

            foreach ($seguimientoRutaClassifier->detailRowsForFiles(
                $files,
                $oppositeRouteProfiles,
                $referenciaRutaMatcher,
                $pipeline->rutaCodigo()
            ) as $detail) {
                $this->writeTemporaryRow($reviewRowsStream, $detail);
                $reviewDetailRowCount++;
            }

            fclose($reviewRowsStream);
            $reviewRowsStream = null;
            fclose($notFoundRowsStream);
            $notFoundRowsStream = null;

            $duplicateGroups = array_filter(
                $duplicateGroups,
                static fn (array $group): bool => $group['count'] > 1
            );

            if ($duplicateGroups !== []) {
                $duplicateSheet = $writer->addNewSheetAndMakeItCurrent();
                $duplicateSheet->setName('REPETICIONES Y DUPLICADOS');
                $this->configureSheet($duplicateSheet, [20, 18, 22, 18, 20, 14, 16, 24, 30, 34, 52]);
                $writer->addRow(Row::fromValues(self::DUPLICATE_HEADERS, $this->headerStyle()));

                foreach ($duplicateGroups as $group) {
                    $detail = $group['detail'];
                    $classification = $this->duplicateClassification($detail, $group);
                    $writer->addRow(Row::fromValues([
                        $detail['numDocumentoIdentificacion'],
                        $detail['factura'],
                        $detail['fechaAtencion'],
                        $detail['tipoServicio'],
                        $detail['codigo'],
                        $detail['cantidad'],
                        $group['count'],
                        round($group['cost'], 2),
                        $detail['archivoOrigen'],
                        $classification,
                        'Validar si es una atención repetida legítima o un duplicado del RIPS.',
                    ]));
                }
            }

            if ($reviewDetailRowCount > 0) {
                $reviewDetailSheet = $writer->addNewSheetAndMakeItCurrent();
                $reviewDetailSheet->setName('CASOS A REVISAR');
                $this->configureSheet(
                    $reviewDetailSheet,
                    [
                        14, 18, 18, 18, 20, 10, 24, 52, 24, 22, 36, 36, 28, 28, 42, 28, 14, 24, 22, 28, 18, 28, 40, 14,
                        14, 60, 22,
                        16, 16, 18, 16, 22, 22, 20, 44, 20, 28, 22, 20, 24, 20, 20, 24, 20, 24, 20, 18, 24, 38, 34, 34, 42, 26, 22, 24, 38, 22, 24, 22, 22, 26, 24,
                    ]
                );
                $writer->addRow(Row::fromValues([
                    ...self::DETAIL_HEADERS,
                    'ESTADO', 'MOTIVO DE REVISION', 'RUTA DE CÁLCULO',
                    ...$this->detailEnrichmentHeaders($includeRegistroSiras),
                ], $this->headerStyle()));

                foreach ($this->temporaryRows($reviewRowsPath) as $detail) {
                    $writer->addRow(Row::fromValues([
                        ...$this->detailBaseValues($detail),
                        $detail['estado'],
                        $detail['motivoClasificacion'],
                        $detail['rutaCalculo'],
                        ...$this->detailEnrichmentValues($detail, $includeRegistroSiras),
                    ]));
                }
            }

            if ($notFoundRowCount > 0) {
                $notFoundSheet = $writer->addNewSheetAndMakeItCurrent();
                $notFoundSheet->setName('NO ENCONTRADOS NOTA TECNICA');
                $this->configureSheet($notFoundSheet, [14, 18, 18, 20, 10, 24, 20, 22, 36, 26, 14, 24, 26, 34, 52, 24]);
                $writer->addRow(Row::fromValues(self::NOT_FOUND_HEADERS, $this->headerStyle()));

                foreach ($this->temporaryRows($notFoundRowsPath) as $detail) {
                    $writer->addRow(Row::fromValues($this->notFoundValues($detail)));
                }
            }

            $technicalReferenceSheet = $writer->addNewSheetAndMakeItCurrent();
            $technicalReferenceSheet->setName('REFERENCIA TECNICA');
            $this->configureSheet($technicalReferenceSheet, [16, 32, 22, 48, 24, 22, 26, 26, 22, 42, 22, 34]);
            $writer->addRow(Row::fromValues(self::TECHNICAL_REFERENCE_HEADERS, $this->headerStyle()));

            foreach ($referenciaRutaMatcher->technicalReferenceRows() as $reference) {
                $writer->addRow(Row::fromValues($reference));
            }

            $catalogSheet = $writer->addNewSheetAndMakeItCurrent();
            $catalogSheet->setName('CATALOGOS APLICADOS');
            $this->configureSheet($catalogSheet, [48, 24, 38, 22, 28, 16]);
            $writer->addRow(Row::fromValues(self::CATALOG_HEADERS, $this->headerStyle()));

            foreach ($referenciaRutaMatcher->catalogUsageRows() as $catalog) {
                $writer->addRow(Row::fromValues($catalog));
            }

            $controlSheet = $writer->addNewSheetAndMakeItCurrent();
            $controlSheet->setName('CONTROL DE CARGUE');
            $this->configureSheet($controlSheet, [34, 18, 14, 14, 22, 14, 14, 18, 52]);
            $writer->addRow(Row::fromValues(self::CONTROL_HEADERS, $this->headerStyle()));

            foreach ($seguimientoRutaClassifier->controlRowsForFiles($files) as $control) {
                $writer->addRow(Row::fromValues($this->controlValues($control)));
            }

            if ($sqlFile instanceof UploadedFile) {
                $sqlSheet = $writer->addNewSheetAndMakeItCurrent();
                $sqlSheet->setName('VALIDACION SQL');
                $this->configureSheet($sqlSheet, [24, 22, 14, 24, 70]);
                $writer->addRow(Row::fromValues(self::SQL_HEADERS, $this->headerStyle()));
                foreach ($this->sqlValidationRows($sqlFile, $profiles, $pipeline->rutaCodigo()) as $row) {
                    $writer->addRow(Row::fromValues($row));
                }
            }

            $writer->close();
            $writerClosed = true;

            $activityLogger->log(
                'seguimiento_rutas',
                'Usuario generó un seguimiento de La María ('.$pipeline->rutaCodigo().').',
                [
                    'batch_id' => $batchId,
                    'files' => array_column($files, 'fileName'),
                    'excel_file' => $fileName,
                    'programa' => $pipeline->slug(),
                    'ruta' => $pipeline->rutaCodigo(),
                ]
            );

            ProgramaEjecucion::query()->create([
                'programa_slug' => $pipeline->slug(),
                'contrato_id' => null,
                'user_id' => $request->user()?->id,
                'periodo' => now()->format('Y-m'),
                'batch_id' => $batchId,
                'archivo_salida' => $fileName,
                'catalogos_aplicados' => $referenciaRutaMatcher->appliedCatalogsSnapshot(),
                'archivos_procesados' => count($files),
            ]);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'archivos' => 'Uno de los archivos no contiene la estructura RIPS esperada (usuarios).',
            ]);
        } finally {
            if (is_resource($reviewRowsStream)) {
                fclose($reviewRowsStream);
            }

            if (is_resource($notFoundRowsStream)) {
                fclose($notFoundRowsStream);
            }

            if (! $writerClosed) {
                $writer->close();
                File::delete($outputPath);
            }

            File::deleteDirectory($tempFolder);
        }

        return response()
            ->download(
                $outputPath,
                $fileName,
                [
                    'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                ]
            )
            ->deleteFileAfterSend(true);
    }

    private function pipelineFor(string $ejecucion): LaMariaProstataPipeline|LaMariaCervixPipeline
    {
        return match ($ejecucion) {
            'prostata' => app(LaMariaProstataPipeline::class),
            'cervix' => app(LaMariaCervixPipeline::class),
            default => abort(404),
        };
    }

    /**
     * @param  array<int, UploadedFile>  $uploadedFiles
     * @return array<int, array{path: string, fileName: string}>
     */
    private function uploadedFiles(array $uploadedFiles): array
    {
        $files = [];

        foreach ($uploadedFiles as $uploadedFile) {
            $path = $uploadedFile->getRealPath();

            if ($path === false) {
                throw ValidationException::withMessages([
                    'archivos' => 'No fue posible leer uno de los archivos cargados.',
                ]);
            }

            $files[] = [
                'path' => $path,
                'fileName' => $uploadedFile->getClientOriginalName(),
            ];
        }

        return $files;
    }

    private function headerStyle(): Style
    {
        $style = new Style;
        $style->setFontBold();
        $style->setFontSize(10);
        $style->setFontColor('FFFFFF');
        $style->setBackgroundColor('4472C4');
        $style->setShouldWrapText();

        return $style;
    }

    /**
     * @param  array<int, int>  $widths
     */
    private function configureSheet(object $sheet, array $widths): void
    {
        $view = new SheetView;
        $view->setFreezeRow(1);
        $sheet->setSheetView($view);

        foreach ($widths as $column => $width) {
            $sheet->setColumnWidth($width, $column + 1);
        }
    }

    /**
     * @return resource
     */
    private function openTemporaryRowsStream(string $path): mixed
    {
        $stream = fopen($path, 'wb');

        if ($stream === false) {
            throw new \RuntimeException('No fue posible preparar el almacenamiento temporal del reporte.');
        }

        return $stream;
    }

    /**
     * @param  resource  $stream
     * @param  array<string, mixed>  $row
     */
    private function writeTemporaryRow(mixed $stream, array $row): void
    {
        $written = fwrite(
            $stream,
            json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL
        );

        if ($written === false) {
            throw new \RuntimeException('No fue posible guardar una fila temporal del reporte.');
        }
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    private function temporaryRows(string $path): \Generator
    {
        $stream = fopen($path, 'rb');

        if ($stream === false) {
            throw new \RuntimeException('No fue posible leer las filas temporales del reporte.');
        }

        try {
            while (($line = fgets($stream)) !== false) {
                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

                if (is_array($row)) {
                    yield $row;
                }
            }
        } finally {
            fclose($stream);
        }
    }

    /**
     * Conserva únicamente los campos usados por la hoja de duplicados para no
     * mantener en memoria todas las columnas clínicas y administrativas.
     *
     * @param  array<string, mixed>  $detail
     * @return array<string, int|float|string>
     */
    private function duplicateDetailValues(array $detail): array
    {
        return [
            'numDocumentoIdentificacion' => $detail['numDocumentoIdentificacion'],
            'factura' => $detail['factura'],
            'fechaAtencion' => $detail['fechaAtencion'],
            'tipoServicio' => $detail['tipoServicio'],
            'codigo' => $detail['codigo'],
            'cantidad' => $detail['cantidad'],
            'archivoOrigen' => $detail['archivoOrigen'],
        ];
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array<int, int|string>
     */
    private function patientValues(array $profile, bool $includeRegistroSiras): array
    {
        $values = [
            $profile['mesAtencion'] === '' ? 'SIN FECHA' : $profile['mesAtencion'],
            $profile['tipoDocumentoIdentificacion'],
            $profile['numDocumentoIdentificacion'],
            $profile['sexo'] ?? '',
            $profile['fechaNacimiento'] ?? '',
            $profile['municipio'] ?? '',
            $profile['zona'] ?? '',
            $profile['rutaFinal'],
            $profile['diagnosticosDetectados'],
            implode(', ', $profile['facturas']),
            $profile['primeraFechaAtencion'],
            $profile['ultimaFechaAtencion'],
            $profile['serviceCounts']['consultas'],
            $profile['serviceCounts']['procedimientos'],
            $profile['serviceCounts']['medicamentos'],
            $profile['serviceCounts']['otrosServicios'],
            $profile['serviceCounts']['urgencias'],
            $profile['serviceCounts']['hospitalizacion'],
            $profile['serviceCount'],
            $profile['serviciosConTarifa'],
            $profile['serviciosSinTarifa'],
            round($profile['valorRipsReportado'], 2),
            round($profile['costoEstimadoReferencia'], 2),
            $profile['tipoUsuario'] ?? '',
            $profile['codPaisOrigen'] ?? '',
            $profile['codPaisResidencia'] ?? '',
            $profile['incapacidad'] ?? '',
        ];

        if ($includeRegistroSiras) {
            $values[] = $profile['registroSiras'] ?? '';
        }

        $values[] = $profile['consecutivoUsuario'] ?? '';

        return $values;
    }

    /**
     * @param  array<string, int|float|string|bool>  $detail
     * @return array<int, int|float|string>
     */
    private function detailValues(array $detail, bool $includeRegistroSiras): array
    {
        return [
            ...$this->detailBaseValues($detail),
            ...$this->detailEnrichmentValues($detail, $includeRegistroSiras),
        ];
    }

    /**
     * @param  array<string, int|float|string|bool>  $detail
     * @return array<int, int|float|string>
     */
    private function detailBaseValues(array $detail): array
    {
        return [
            $detail['mesAtencion'] === '' ? 'SIN FECHA' : $detail['mesAtencion'],
            $detail['factura'],
            $detail['codPrestador'] ?? '',
            $detail['tipoDocumentoIdentificacion'],
            $detail['numDocumentoIdentificacion'],
            $detail['sexo'] ?? '',
            $detail['rutaFinal'],
            $detail['fechaAtencion'],
            $detail['tipoServicio'],
            $detail['codigo'],
            $detail['nombreNotaTecnica'] ?? '',
            $detail['grupoTecnico'],
            $detail['finalidadTecnologiaSalud'] ?? '',
            $detail['causaMotivoAtencion'] ?? '',
            $detail['diagnosticoPrincipal'],
            $detail['autorizacion'],
            $detail['cantidad'],
            $detail['valorRipsReportado'],
            $detail['tarifaReferencia'],
            $detail['costoEstimadoReferencia'],
            $detail['atc'],
            $detail['estadoCruce'],
            $detail['archivoOrigen'],
            $detail['ambito'] ?? '',
        ];
    }

    /**
     * @param  array<string, int|float|string|bool>  $detail
     * @return array<int, int|float|string>
     */
    private function detailEnrichmentValues(array $detail, bool $includeRegistroSiras): array
    {
        $values = [
            $detail['tipoUsuario'] ?? '',
            $detail['codPaisOrigen'] ?? '',
            $detail['codPaisResidencia'] ?? '',
            $detail['incapacidad'] ?? '',
        ];

        if ($includeRegistroSiras) {
            $values[] = $detail['registroSiras'] ?? '';
        }

        return [
            ...$values,
            $detail['consecutivoUsuario'] ?? '',
            $detail['consecutivoRips'] ?? '',
            $detail['nombreServicio'] ?? '',
            $detail['idMipres'] ?? '',
            $detail['modalidadGrupoServicio'] ?? '',
            $detail['grupoServicios'] ?? '',
            $detail['codServicio'] ?? '',
            $detail['viaIngresoServicio'] ?? '',
            $detail['tipoMedicamento'] ?? '',
            $detail['tipoOtroServicio'] ?? '',
            $detail['concentracionMedicamento'] ?? '',
            $detail['unidadMedida'] ?? '',
            $detail['formaFarmaceutica'] ?? '',
            $detail['unidadMinDispensa'] ?? '',
            $detail['diasTratamiento'] ?? '',
            $detail['diagnosticoPrincipalCie11'] ?? '',
            $detail['nombreDiagnosticoPrincipalCie11'] ?? '',
            $detail['diagnosticosRelacionados'] ?? '',
            $detail['diagnosticosRelacionadosCie11'] ?? '',
            $detail['nombresDiagnosticosRelacionadosCie11'] ?? '',
            $detail['tipoDiagnosticoPrincipal'] ?? '',
            $detail['codComplicacion'] ?? '',
            $detail['codComplicacionCie11'] ?? '',
            $detail['nombreComplicacionCie11'] ?? '',
            $detail['valorUnitarioRips'] ?? '',
            $detail['valorDispensacionRips'] ?? '',
            $detail['conceptoRecaudo'] ?? '',
            $detail['valorPagoModerador'] ?? '',
            $detail['numFevPagoModerador'] ?? '',
            $detail['codigoVida'] ?? '',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function patientHeaders(bool $includeRegistroSiras): array
    {
        if ($includeRegistroSiras) {
            return self::PATIENT_HEADERS;
        }

        return array_values(array_filter(
            self::PATIENT_HEADERS,
            static fn (string $header): bool => $header !== 'REGISTRO SIRAS'
        ));
    }

    /**
     * @return array<int, string>
     */
    private function detailEnrichmentHeaders(bool $includeRegistroSiras): array
    {
        if ($includeRegistroSiras) {
            return self::DETAIL_ENRICHMENT_HEADERS;
        }

        return array_values(array_filter(
            self::DETAIL_ENRICHMENT_HEADERS,
            static fn (string $header): bool => $header !== 'REGISTRO SIRAS'
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>  $profiles
     */
    private function profilesContainValue(array $profiles, string $field): bool
    {
        foreach ($profiles as $profile) {
            if (trim((string) ($profile[$field] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Solo servicios que deberían aparecer en la nota técnica de la ejecución.
     * Otros servicios / urgencias / hospitalización no se listan como “no encontrados”.
     *
     * @param  array<string, int|float|string|bool>  $detail
     */
    private function belongsInNotFoundSheet(array $detail): bool
    {
        if ($detail['encontradoEnNotaTecnica'] ?? false) {
            return false;
        }

        return in_array((string) ($detail['tipoServicio'] ?? ''), [
            'medicamentos',
            'consultas',
            'procedimientos',
        ], true);
    }

    /**
     * @param  array<string, int|float|string|bool>  $detail
     * @return array<int, int|float|string>
     */
    private function notFoundValues(array $detail): array
    {
        return [
            $detail['mesAtencion'] === '' ? 'SIN FECHA' : $detail['mesAtencion'],
            $detail['factura'],
            $detail['tipoDocumentoIdentificacion'],
            $detail['numDocumentoIdentificacion'],
            $detail['sexo'] ?? '',
            $detail['rutaFinal'],
            $detail['tipoServicio'],
            $detail['codigo'],
            $detail['nombreServicio'] ?? '',
            $detail['diagnosticoPrincipal'],
            $detail['cantidad'],
            $detail['valorRipsReportado'],
            $detail['estadoCruce'],
            $detail['archivoOrigen'],
            'No está en la nota técnica de medicamentos/servicios de esta ejecución.',
            $this->classifyNotFoundMedication($detail),
        ];
    }

    /**
     * Classifies an unmatched medication for review only. It does not add or
     * remove the item from any technical note or catalog.
     *
     * @param  array<string, int|float|string|bool>  $detail
     */
    private function classifyNotFoundMedication(array $detail): string
    {
        if (($detail['tipoServicio'] ?? '') !== 'medicamentos') {
            return 'REFERENCIA_CONTRACTUAL';
        }

        $name = Str::upper(Str::ascii((string) ($detail['nombreServicio'] ?? '')));

        if ($name === '') {
            return 'SIN_CORRESPONDENCIA';
        }

        foreach (['PROPOFOL', 'REMIFENTANILO', 'FENTANILO', 'LIDOCAINA', 'MANITOL', 'MAGNESIO', 'SODIO CLORURO', 'SODIO LACTATO', 'CEFazolina', 'METAMIZOL', 'ENOXAPARINA', 'FUROSEMIDA', 'POTASIO', 'CALCIO', 'HEPARINA', 'PIPERACILINA', 'MEROPENEM', 'VANCOMICINA'] as $term) {
            if (Str::contains($name, Str::upper($term))) {
                return 'HOSPITALARIO';
            }
        }

        foreach (['ONDANSETRON', 'FOSAPREPITANT', 'DEXAMETASONA', 'METOCLOPRAMIDA', 'DIFENHIDRAMINA', 'APREPITANT'] as $term) {
            if (Str::contains($name, $term)) {
                return 'QUIMIO_APOYO';
            }
        }

        foreach (['IOPROMIDA', 'CONTRASTE', 'INSUMO', 'EQUIPO', 'JERINGA', 'AGUJA'] as $term) {
            if (Str::contains($name, $term)) {
                return 'REFERENCIA_CONTRACTUAL';
            }
        }

        return 'PENDIENTE_NOTA_TECNICA';
    }

    /**
     * @param  array<string, int|string>  $control
     * @return array<int, int|string>
     */
    private function controlValues(array $control): array
    {
        return [
            $control['archivoOrigen'],
            $control['factura'],
            $control['userCount'],
            $control['serviceCount'],
            $control['servicesWithoutDate'],
            $control['firstMonth'],
            $control['lastMonth'],
            $control['estadoCargue'],
            $control['observacion'],
        ];
    }

    /**
     * Cruza documentos de los RIPS contra un SQL sin asumir una estructura
     * concreta: el SQL puede contener comentarios, INSERTS y tablas auxiliares.
     * Se reportan los documentos encontrados para que el usuario valide la
     * diferencia entre la fuente administrativa y la realidad del RIPS.
     *
     * @param  array<int, array<string, mixed>>  $profiles
     * @return iterable<int, array<int, string>>
     */
    private function sqlValidationRows(UploadedFile $sqlFile, array $profiles, string $ruta): iterable
    {
        $contents = file_get_contents($sqlFile->getRealPath());
        $contents = is_string($contents) ? $contents : '';
        $documentsInSql = [];

        preg_match_all('/(?:documento|numDocumentoIdentificacion|identificacion|cedula)\\D{0,30}(\\d{5,20})/iu', $contents, $matches);
        foreach ($matches[1] ?? [] as $document) {
            $documentsInSql[(string) $document] = true;
        }

        foreach ($profiles as $profile) {
            $document = (string) $profile['numDocumentoIdentificacion'];
            $found = $document !== '' && isset($documentsInSql[$document]);
            yield [
                $document,
                $found ? 'SI' : 'NO',
                $ruta,
                $found ? 'VALIDADO' : 'REVISAR',
                $found
                    ? 'El documento aparece en el SQL y en los RIPS.'
                    : 'El documento está en los RIPS, pero no fue encontrado en el SQL cargado.',
            ];
        }

        if ($profiles === []) {
            yield ['', 'NO', $ruta, 'REVISAR', 'No se encontraron pacientes válidos en los RIPS.'];
        }
    }

    /**
     * @param  array<string, mixed>  $detail
     * @param  array{count: int, files: array<string, bool>}  $group
     */
    private function duplicateClassification(array $detail, array $group): string
    {
        if (count($group['files']) > 1) {
            return 'POSIBLE_DUPLICADO_ENTRE_ARCHIVOS';
        }

        if ($detail['tipoServicio'] === 'medicamentos') {
            return 'DUPLICADO_MEDICAMENTO_MISMA_FECHA';
        }

        if ($detail['tipoServicio'] === 'procedimientos') {
            return 'REPETICION_PROCEDIMIENTO_VALIDAR';
        }

        return 'DUPLICADO_EXACTO_POTENCIAL';
    }

    /**
     * @param  array<int, array<string, mixed>>  $profiles
     * @return array<int, array<int, float|int|string>>
     */
    private function monthlySummaries(array $profiles): array
    {
        $summaries = [];

        foreach ($profiles as $profile) {
            $key = ($profile['mesAtencion'] === '' ? 'SIN_FECHA' : $profile['mesAtencion'])
                .'|'.$profile['rutaCalculo'];

            if (! isset($summaries[$key])) {
                $summaries[$key] = [
                    'mesAtencion' => $profile['mesAtencion'],
                    'rutaCalculo' => $profile['rutaCalculo'],
                    'pacientes' => [],
                    'facturas' => [],
                    'servicios' => 0,
                    'serviciosConTarifa' => 0,
                    'serviciosSinTarifa' => 0,
                    'valorRipsReportado' => 0.0,
                    'costoEstimadoReferencia' => 0.0,
                    'casosRevisar' => 0,
                ];
            }

            $summary = &$summaries[$key];

            if ($profile['estado'] !== 'LISTO') {
                $summary['casosRevisar']++;
                unset($summary);

                continue;
            }

            $summary['pacientes'][$profile['profileKey']] = true;

            foreach ($profile['facturas'] as $factura) {
                $summary['facturas'][$factura] = true;
            }

            $summary['servicios'] += $profile['serviceCount'];
            $summary['serviciosConTarifa'] += $profile['serviciosConTarifa'];
            $summary['serviciosSinTarifa'] += $profile['serviciosSinTarifa'];
            $summary['valorRipsReportado'] += $profile['valorRipsReportado'];
            $summary['costoEstimadoReferencia'] += $profile['costoEstimadoReferencia'];

            unset($summary);
        }

        ksort($summaries);

        return array_map(static fn (array $summary): array => [
            $summary['mesAtencion'] === '' ? 'SIN FECHA' : $summary['mesAtencion'],
            $summary['rutaCalculo'],
            count($summary['pacientes']),
            count($summary['facturas']),
            $summary['servicios'],
            $summary['serviciosConTarifa'],
            $summary['serviciosSinTarifa'],
            round($summary['valorRipsReportado'], 2),
            round($summary['costoEstimadoReferencia'], 2),
            $summary['casosRevisar'],
        ], $summaries);
    }
}
