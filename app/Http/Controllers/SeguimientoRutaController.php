<?php

namespace App\Http\Controllers;

use App\Models\ProgramaEjecucion;
use App\Programas\LaMaria\CondicionArchivo;
use App\Programas\LaMaria\EstadoClinico;
use App\Programas\LaMaria\EstadoDuplicidad;
use App\Programas\LaMaria\EstadoTarifario;
use App\Programas\LaMaria\FuenteTarifa;
use App\Programas\LaMaria\LaMariaCervixPipeline;
use App\Programas\LaMaria\LaMariaPrograma;
use App\Programas\LaMaria\LaMariaProstataPipeline;
use App\Programas\LaMaria\RutaCalculo;
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
    private const FECHA_HEADER = 'fecha (fechaInicioAtencion / fechaDispensAdmon / fechaSuministroTecnologia)';

    private const CODIGO_HEADER = 'codigo (codConsulta / codProcedimiento / codTecnologiaSalud)';

    private const CANTIDAD_HEADER = 'cantidad (cantidadMedicamento / cantidadOS)';

    private const VR_UNIT_HEADER = 'vrUnit (vrUnitMedicamento / vrUnitOS)';

    private const FECHA_FIELDS = ['fechaInicioAtencion', 'fechaDispensAdmon', 'fechaSuministroTecnologia'];

    private const CODIGO_FIELDS = ['codConsulta', 'codProcedimiento', 'codTecnologiaSalud'];

    private const CANTIDAD_FIELDS = ['cantidadMedicamento', 'cantidadOS'];

    private const VR_UNIT_FIELDS = ['vrUnitMedicamento', 'vrUnitOS'];

    /**
     * Campos del RIPS que solo se exportan cuando llegan diligenciados en el
     * lote cargado (como registroSIRAS).
     *
     * @var array<int, string>
     */
    private const CONDITIONAL_FIELDS = [
        'registroSIRAS', 'idMIPRES', 'numFEVPagoModerador',
        'codDiagnosticoPrincipalCIE11', 'nomCodDiagnosticoPrincipalCIE11',
        'codDiagnosticoPrincipalECIE11', 'nomCodDiagnosticoPrincipalECIE11',
        'codDiagnosticoRelacionadoCIE11', 'nomCodDiagnosticoRelacionadoCIE11',
        'codDiagnosticoRelacionado1CIE11', 'nomCodDiagnosticoRelacionado1CIE11',
        'codDiagnosticoRelacionado2CIE11', 'nomCodDiagnosticoRelacionado2CIE11',
        'codDiagnosticoRelacionado3CIE11', 'nomCodDiagnosticoRelacionado3CIE11',
        'codDiagnosticoRelacionadoE1CIE11', 'nomCodDiagnosticoRelacionadoE1CIE11',
        'codDiagnosticoRelacionadoE2CIE11', 'nomCodDiagnosticoRelacionadoE2CIE11',
        'codDiagnosticoRelacionadoE3CIE11', 'nomCodDiagnosticoRelacionadoE3CIE11',
        'codComplicacionCIE11', 'nomCodComplicacionCIE11',
        'codDiagnosticoCausaMuerteCIE11', 'nomCodDiagnosticoCausaMuerteCIE11',
    ];

    private const MONTHLY_SUMMARY_HEADERS = [
        'MES', 'PACIENTES', 'SERVICIOS', 'COSTO TOTAL', 'CASOS A REVISAR',
        'SERVICIOS SIN TARIFA APLICADA',
    ];

    private const PATIENT_HEADERS = [
        'MES', 'tipoDocumentoIdentificacion', 'numDocumentoIdentificacion', 'codSexo',
        'fechaNacimiento', 'codMunicipioResidencia', 'codZonaTerritorialResidencia', 'tipoUsuario',
        'codDiagnosticoPrincipal (valores del mes)', 'numFactura (facturas del mes)',
        'PRIMERA ATENCION', 'ULTIMA ATENCION', 'CONSULTAS', 'PROCEDIMIENTOS', 'MEDICAMENTOS',
        'OTROS SERVICIOS', 'URGENCIAS', 'HOSPITALIZACIONES', 'TOTAL SERVICIOS',
        'SERVICIOS SIN TARIFA APLICADA', 'COSTO TOTAL', 'OBSERVACION',
    ];

    private const REVIEW_HEADERS = [
        'numFactura', 'tipoDocumentoIdentificacion', 'numDocumentoIdentificacion', 'codSexo',
        'tipoServicio', self::FECHA_HEADER, self::CODIGO_HEADER, 'nomTecnologiaSalud',
        'codDiagnosticoPrincipal', self::CANTIDAD_HEADER, 'consecutivo (servicio)', 'MOTIVO',
    ];

    private const NOT_FOUND_HEADERS = [
        'numFactura', 'tipoDocumentoIdentificacion', 'numDocumentoIdentificacion', 'tipoServicio',
        self::FECHA_HEADER, self::CODIGO_HEADER, 'nomTecnologiaSalud', 'codDiagnosticoPrincipal',
        self::CANTIDAD_HEADER, 'consecutivo (servicio)', 'OBSERVACION',
    ];

    private const DUPLICATE_HEADERS = [
        'numFactura (facturas del grupo)', 'tipoDocumentoIdentificacion', 'numDocumentoIdentificacion',
        self::FECHA_HEADER, 'tipoServicio', self::CODIGO_HEADER, self::CANTIDAD_HEADER,
        'consecutivo (servicio, consecutivos del grupo)', 'VECES', 'OBSERVACION',
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
        'ARCHIVO', 'numFactura', 'tipoNota', 'numNota', 'USUARIOS', 'SERVICIOS',
        'SERVICIOS SIN FECHA', 'MES INICIAL', 'MES FINAL', 'ESTADO CARGUE', 'OBSERVACION',
    ];

    private const CONCILIATION_HEADERS = [
        'CONCILIACION', 'SERVICIOS', 'PACIENTE-MES', 'COSTO TOTAL', 'OBSERVACION',
    ];

    private const SQL_HEADERS = [
        'DOCUMENTO', 'ENCONTRADO EN SQL', 'RUTA', 'ESTADO VALIDACION', 'OBSERVACION',
    ];

    /**
     * Tipos de servicio que se comparan contra la nota técnica. Urgencias y
     * hospitalización no traen código de servicio en el RIPS.
     *
     * @var array<int, string>
     */
    private const NOT_FOUND_SERVICE_TYPES = ['consultas', 'procedimientos', 'medicamentos', 'otrosServicios'];

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
        $ruta = (string) $pipeline->rutaCodigo();
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
        $fileName = 'LA_MARIA_'.$ruta.'_'.now()->format('Ymd_His').'_'
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
            $screenedFiles = $seguimientoRutaClassifier->screenFiles($files);
            $processableFiles = $seguimientoRutaClassifier->processableFiles($screenedFiles);
            $allProfiles = $seguimientoRutaClassifier->profilesForFiles($processableFiles);
            $duplicateIndex = $seguimientoRutaClassifier->duplicateIndexForFiles($processableFiles);
            $evaluation = $seguimientoRutaClassifier->evaluateCostsForFiles(
                $processableFiles,
                $allProfiles,
                $referenciaRutaMatcher,
                $duplicateIndex
            );
            $allProfiles = $evaluation['profiles'];
            $profiles = $seguimientoRutaClassifier->profilesForExecutionRoute($allProfiles, $ruta);
            $sharedProfiles = $seguimientoRutaClassifier->profilesNotAssignable($allProfiles);
            $controlRows = iterator_to_array(
                $seguimientoRutaClassifier->controlRowsForFiles($screenedFiles),
                false
            );
            $detailColumns = $this->visibleColumns($this->detailColumns(), $evaluation['camposDiligenciados']);
            $referenciaRutaMatcher->resetUsage();

            $summarySheet = $writer->getCurrentSheet();
            $summarySheet->setName('SEGUIMIENTO MENSUAL');
            $this->configureSheet($summarySheet, $this->columnWidths(self::MONTHLY_SUMMARY_HEADERS));
            $writer->addRow(Row::fromValues(self::MONTHLY_SUMMARY_HEADERS, $this->headerStyle()));

            foreach ($this->monthlySummaries($profiles, $sharedProfiles) as $summary) {
                $writer->addRow(Row::fromValuesWithStyles($summary, null, $this->moneyColumnStyles(self::MONTHLY_SUMMARY_HEADERS)));
            }

            $patientSheet = $writer->addNewSheetAndMakeItCurrent();
            $patientSheet->setName('SEGUIMIENTO PACIENTE MES');
            $this->configureSheet($patientSheet, $this->columnWidths(self::PATIENT_HEADERS));
            $writer->addRow(Row::fromValues(self::PATIENT_HEADERS, $this->headerStyle()));
            $patientMoneyStyles = $this->moneyColumnStyles(self::PATIENT_HEADERS);

            foreach ($profiles as $profile) {
                $writer->addRow(Row::fromValuesWithStyles($this->patientValues($profile), null, $patientMoneyStyles));
            }

            $detailHeaders = array_column($detailColumns, 0);
            $detailSheet = $writer->addNewSheetAndMakeItCurrent();
            $detailSheet->setName('DETALLE RIPS');
            $this->configureSheet($detailSheet, $this->columnWidths($detailHeaders));
            $writer->addRow(Row::fromValues($detailHeaders, $this->headerStyle()));
            $detailMoneyStyles = $this->moneyColumnStyles($detailHeaders);

            $reviewRowsStream = $this->openTemporaryRowsStream($reviewRowsPath);
            $notFoundRowsStream = $this->openTemporaryRowsStream($notFoundRowsPath);
            $reviewRowCount = 0;
            $notFoundRowCount = 0;
            $duplicateGroups = [];

            foreach ($seguimientoRutaClassifier->detailRowsForFiles(
                $processableFiles,
                $profiles,
                $referenciaRutaMatcher,
                $ruta,
                $duplicateIndex
            ) as $detail) {
                $writer->addRow(Row::fromValuesWithStyles($this->columnValues($detailColumns, $detail), null, $detailMoneyStyles));

                if ($detail['requiereRevision']) {
                    $this->writeTemporaryRow($reviewRowsStream, $this->reviewValues($detail));
                    $reviewRowCount++;
                }

                if ($this->belongsInNotFoundSheet($detail)) {
                    $this->writeTemporaryRow($notFoundRowsStream, $this->notFoundValues($detail));
                    $notFoundRowCount++;
                }

                $this->addToDuplicateGroups($duplicateGroups, $detail);
            }

            foreach ($seguimientoRutaClassifier->detailRowsForFiles(
                $processableFiles,
                $sharedProfiles,
                $referenciaRutaMatcher,
                $ruta,
                $duplicateIndex
            ) as $detail) {
                $this->writeTemporaryRow($reviewRowsStream, $this->reviewValues($detail));
                $reviewRowCount++;
            }

            fclose($reviewRowsStream);
            $reviewRowsStream = null;
            fclose($notFoundRowsStream);
            $notFoundRowsStream = null;

            $duplicateGroups = array_filter(
                $duplicateGroups,
                static fn (array $group): bool => $group['estados'] !== []
            );

            if ($reviewRowCount > 0) {
                $reviewSheet = $writer->addNewSheetAndMakeItCurrent();
                $reviewSheet->setName('CASOS A REVISAR');
                $this->configureSheet($reviewSheet, $this->columnWidths(self::REVIEW_HEADERS));
                $writer->addRow(Row::fromValues(self::REVIEW_HEADERS, $this->headerStyle()));

                foreach ($this->temporaryRows($reviewRowsPath) as $values) {
                    $writer->addRow(Row::fromValues($values));
                }
            }

            if ($notFoundRowCount > 0) {
                $notFoundSheet = $writer->addNewSheetAndMakeItCurrent();
                $notFoundSheet->setName('NO ENCONTRADOS NOTA TECNICA');
                $this->configureSheet($notFoundSheet, $this->columnWidths(self::NOT_FOUND_HEADERS));
                $writer->addRow(Row::fromValues(self::NOT_FOUND_HEADERS, $this->headerStyle()));

                foreach ($this->temporaryRows($notFoundRowsPath) as $values) {
                    $writer->addRow(Row::fromValues($values));
                }
            }

            if ($duplicateGroups !== []) {
                $duplicateSheet = $writer->addNewSheetAndMakeItCurrent();
                $duplicateSheet->setName('REPETICIONES Y DUPLICADOS');
                $this->configureSheet($duplicateSheet, $this->columnWidths(self::DUPLICATE_HEADERS));
                $writer->addRow(Row::fromValues(self::DUPLICATE_HEADERS, $this->headerStyle()));

                foreach ($duplicateGroups as $group) {
                    $writer->addRow(Row::fromValues($this->duplicateValues($group)));
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
            $this->configureSheet($controlSheet, [34, 18, 12, 12, 12, 12, 18, 14, 14, 16, 90]);
            $writer->addRow(Row::fromValues(self::CONTROL_HEADERS, $this->headerStyle()));

            foreach ($this->controlValues($controlRows, $evaluation['archivos']) as $control) {
                $writer->addRow(Row::fromValues($control));
            }

            $writer->addRow(Row::fromValues(['']));
            $writer->addRow(Row::fromValues(self::CONCILIATION_HEADERS, $this->headerStyle()));

            foreach ($this->conciliationRows($controlRows, $allProfiles, $ruta) as $conciliation) {
                $writer->addRow(Row::fromValuesWithStyles($conciliation, null, $this->moneyColumnStyles(self::CONCILIATION_HEADERS)));
            }

            if ($sqlFile instanceof UploadedFile) {
                $sqlSheet = $writer->addNewSheetAndMakeItCurrent();
                $sqlSheet->setName('VALIDACION SQL');
                $this->configureSheet($sqlSheet, [24, 22, 14, 24, 70]);
                $writer->addRow(Row::fromValues(self::SQL_HEADERS, $this->headerStyle()));
                foreach ($this->sqlValidationRows($sqlFile, $profiles, $ruta) as $row) {
                    $writer->addRow(Row::fromValues($row));
                }
            }

            $writer->close();
            $writerClosed = true;

            $activityLogger->log(
                'seguimiento_rutas',
                'Usuario generó un seguimiento de La María ('.$ruta.').',
                [
                    'batch_id' => $batchId,
                    'files' => array_column($files, 'fileName'),
                    'excel_file' => $fileName,
                    'programa' => $pipeline->slug(),
                    'ruta' => $ruta,
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
        } catch (\Throwable $exception) {
            report($exception);

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
     * Formato de miles para las columnas monetarias. Solo cambia la
     * presentación de la celda; el valor almacenado se conserva sin redondear.
     *
     * @param  array<int, string>  $headers
     * @return array<int, Style>
     */
    private function moneyColumnStyles(array $headers): array
    {
        $style = (new Style)->setFormat('#,##0.00');

        return array_map(
            static fn (): Style => $style,
            array_filter(
                $headers,
                static fn (string $header): bool => in_array($header, ['COSTO TOTAL', 'TARIFA', 'COSTO'], true)
            )
        );
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
     * @param  array<int, string>  $headers
     * @return array<int, int>
     */
    private function columnWidths(array $headers): array
    {
        return array_map(
            static fn (string $header): int => max(12, min(40, mb_strlen($header) + 4)),
            $headers
        );
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
     * @param  array<int|string, mixed>  $row
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
     * @return \Generator<int, array<int|string, mixed>>
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
     * Columnas de DETALLE RIPS: campos del RIPS con su nombre en el JSON, más
     * NOMBRE NOTA TECNICA, TARIFA y COSTO como únicos resultados del cálculo.
     *
     * @return array<int, array{0: string, 1: string, 2: string|array<int, string>}>
     */
    private function detailColumns(): array
    {
        $user = static fn (string $field, ?string $header = null): array => [$header ?? $field, 'usuario', $field];
        $service = static fn (string $field, ?string $header = null): array => [$header ?? $field, 'servicio', $field];

        return [
            ['numFactura', 'factura', ''],
            $user('tipoDocumentoIdentificacion'),
            $user('numDocumentoIdentificacion'),
            $user('tipoUsuario'),
            $user('fechaNacimiento'),
            $user('codSexo'),
            $user('codPaisResidencia'),
            $user('codMunicipioResidencia'),
            $user('codZonaTerritorialResidencia'),
            $user('incapacidad'),
            $user('consecutivo', 'consecutivo (usuario)'),
            $user('codPaisOrigen'),
            $user('registroSIRAS'),
            ['tipoServicio', 'tipoServicio', ''],
            $service('codPrestador'),
            [self::FECHA_HEADER, 'servicio', self::FECHA_FIELDS],
            $service('fechaEgreso'),
            $service('numAutorizacion'),
            $service('idMIPRES'),
            [self::CODIGO_HEADER, 'servicio', self::CODIGO_FIELDS],
            $service('nomTecnologiaSalud'),
            $service('modalidadGrupoServicioTecSal'),
            $service('grupoServicios'),
            $service('codServicio'),
            $service('finalidadTecnologiaSalud'),
            $service('causaMotivoAtencion'),
            $service('viaIngresoServicioSalud'),
            $service('codDiagnosticoPrincipal'),
            $service('codDiagnosticoPrincipalE'),
            $service('codDiagnosticoRelacionado'),
            $service('codDiagnosticoRelacionado1'),
            $service('codDiagnosticoRelacionado2'),
            $service('codDiagnosticoRelacionado3'),
            $service('codDiagnosticoRelacionadoE1'),
            $service('codDiagnosticoRelacionadoE2'),
            $service('codDiagnosticoRelacionadoE3'),
            $service('tipoDiagnosticoPrincipal'),
            $service('codComplicacion'),
            $service('condicionDestinoUsuarioEgreso'),
            $service('codDiagnosticoCausaMuerte'),
            ...array_map(
                static fn (string $field): array => [$field, 'servicio', $field],
                array_values(array_filter(
                    self::CONDITIONAL_FIELDS,
                    static fn (string $field): bool => str_contains($field, 'CIE11')
                ))
            ),
            $service('tipoMedicamento'),
            $service('concentracionMedicamento'),
            $service('unidadMedida'),
            $service('formaFarmaceutica'),
            $service('unidadMinDispensa'),
            [self::CANTIDAD_HEADER, 'servicio', self::CANTIDAD_FIELDS],
            $service('diasTratamiento'),
            $service('tipoOS'),
            [self::VR_UNIT_HEADER, 'servicio', self::VR_UNIT_FIELDS],
            $service('vrDispensacion'),
            $service('vrServicio'),
            $service('conceptoRecaudo'),
            $service('valorPagoModerador'),
            $service('numFEVPagoModerador'),
            $service('codigoVIDA'),
            $service('consecutivo', 'consecutivo (servicio)'),
            ['NOMBRE NOTA TECNICA', 'nombreNotaTecnica', ''],
            ['TARIFA', 'tarifa', ''],
            ['COSTO', 'costo', ''],
        ];
    }

    /**
     * Oculta los campos condicionales que llegan vacíos en todo el lote.
     *
     * @param  array<int, array{0: string, 1: string, 2: string|array<int, string>}>  $columns
     * @param  array<string, true>  $filledFields
     * @return array<int, array{0: string, 1: string, 2: string|array<int, string>}>
     */
    private function visibleColumns(array $columns, array $filledFields): array
    {
        return array_values(array_filter(
            $columns,
            static fn (array $column): bool => ! is_string($column[2])
                || ! in_array($column[2], self::CONDITIONAL_FIELDS, true)
                || isset($filledFields[$column[2]])
        ));
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2: string|array<int, string>}>  $columns
     * @param  array<string, mixed>  $detail
     * @return array<int, mixed>
     */
    private function columnValues(array $columns, array $detail): array
    {
        return array_map(fn (array $column): mixed => match ($column[1]) {
            'factura' => $detail['factura'],
            'tipoServicio' => $detail['tipoServicio'],
            'usuario' => $this->jsonValue($detail['usuarioJson'], (array) $column[2]),
            'servicio' => $this->jsonValue($detail['servicioJson'], (array) $column[2]),
            'nombreNotaTecnica' => $detail['nombreNotaTecnica'] ?? '',
            'tarifa' => $detail['estadoTarifario'] === EstadoTarifario::ConTarifa->value
                ? $detail['tarifaReferencia']
                : '',
            'costo' => $detail['sumaAlResumen'] === 'SI' ? $detail['costoEstimadoReferencia'] : '',
            default => '',
        }, $columns);
    }

    /**
     * Valor tal como viene en el JSON: el primer campo existente de la lista.
     *
     * @param  array<string, mixed>  $json
     * @param  array<int, string>  $fields
     */
    private function jsonValue(array $json, array $fields): mixed
    {
        foreach ($fields as $field) {
            $value = $json[$field] ?? null;

            if ($value !== null && $value !== '') {
                return is_bool($value) ? ($value ? 'true' : 'false') : $value;
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<int, mixed>
     */
    private function reviewValues(array $detail): array
    {
        return [
            $detail['factura'],
            $this->jsonValue($detail['usuarioJson'], ['tipoDocumentoIdentificacion']),
            $this->jsonValue($detail['usuarioJson'], ['numDocumentoIdentificacion']),
            $this->jsonValue($detail['usuarioJson'], ['codSexo']),
            $detail['tipoServicio'],
            $this->jsonValue($detail['servicioJson'], self::FECHA_FIELDS),
            $this->jsonValue($detail['servicioJson'], self::CODIGO_FIELDS),
            $this->jsonValue($detail['servicioJson'], ['nomTecnologiaSalud']),
            $this->jsonValue($detail['servicioJson'], ['codDiagnosticoPrincipal']),
            $this->jsonValue($detail['servicioJson'], self::CANTIDAD_FIELDS),
            $this->jsonValue($detail['servicioJson'], ['consecutivo']),
            $this->reviewReason($detail),
        ];
    }

    /**
     * Explica en una sola frase por qué la línea es una excepción.
     *
     * @param  array<string, mixed>  $detail
     */
    private function reviewReason(array $detail): string
    {
        $estado = (string) $detail['estadoClinico'];
        $reasons = [];

        if ($detail['ruta'] === RutaCalculo::NoAsignable->value) {
            $reasons[] = sprintf(
                '%s (POTENCIAL PROSTATA $%s / CERVIX $%s)',
                str_contains($estado, EstadoClinico::DobleRuta->value)
                    ? 'DOBLE RUTA C61 Y C53 EN EL MISMO MES - NO SUMA EN NINGUNA EJECUCION'
                    : 'SEXO NO RECONOCIDO Y SIN DIAGNOSTICO DE RUTA - NO ASIGNABLE, NO SUMA',
                number_format((float) $detail['costoPotencialProstata'], 2, '.', ''),
                number_format((float) $detail['costoPotencialCervix'], 2, '.', '')
            );
        } elseif (str_contains($estado, EstadoClinico::SinDiagnosticoRuta->value)) {
            $reasons[] = 'SIN DIAGNOSTICO DE RUTA - ASIGNADO A '.$detail['ruta'].' POR SEXO';
        }

        if (str_contains($estado, EstadoClinico::InconsistenciaSexoRuta->value)) {
            $reasons[] = 'INCONSISTENCIA SEXO/RUTA';
        }

        if (str_contains($estado, EstadoClinico::SinDocumento->value)) {
            $reasons[] = 'SIN DOCUMENTO DEL PACIENTE';
        }

        if (str_contains($estado, EstadoClinico::SinFecha->value)) {
            $reasons[] = 'SIN FECHA DE ATENCION - NO SUMA';
        }

        if ($detail['estadoTarifario'] === EstadoTarifario::RevisarPresentacion->value) {
            $reasons[] = 'PRESENTACION DEL MEDICAMENTO NO DETERMINADA - NO SUMA (CANDIDATAS: '
                .$detail['candidatosPresentacion'].')';
        }

        if ($detail['estadoDuplicidad'] === EstadoDuplicidad::DuplicadoExacto->value) {
            $reasons[] = 'COPIA EXACTA DE OTRO REGISTRO - NO SUMA';
        }

        if ($detail['estadoDuplicidad'] === EstadoDuplicidad::PosibleDobleFacturacion->value) {
            $reasons[] = 'POSIBLE DOBLE FACTURACION';
        }

        if ($detail['condicionArchivo'] === CondicionArchivo::FacturaRepetidaConDiferencias->value) {
            $reasons[] = 'FACTURA REPETIDA CON CONTENIDO DIFERENTE (ARCHIVO: '.$detail['archivoOrigen'].') - NO SUMA';
        }

        return implode(' | ', $reasons);
    }

    /**
     * Responde si la línea estaba o no en la nota técnica, sin importar si
     * obtuvo tarifa por otra fuente.
     *
     * @param  array<string, mixed>  $detail
     */
    private function belongsInNotFoundSheet(array $detail): bool
    {
        return ! $detail['enNotaTecnica']
            && in_array($detail['tipoServicio'], self::NOT_FOUND_SERVICE_TYPES, true);
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<int, mixed>
     */
    private function notFoundValues(array $detail): array
    {
        $observation = $detail['estadoTarifario'] !== EstadoTarifario::ConTarifa->value
            ? ''
            : match ($detail['fuenteTarifa']) {
                FuenteTarifa::Dispensacion->value => 'TARIFA APLICADA POR DISPENSACION ADMINISTRATIVA',
                FuenteTarifa::Complemento->value => 'TARIFA APLICADA POR MEDICAMENTO COMPLEMENTARIO',
                FuenteTarifa::ReferenciaManual->value => 'TARIFA APLICADA POR REFERENCIA MANUAL',
                default => '',
            };

        return [
            $detail['factura'],
            $this->jsonValue($detail['usuarioJson'], ['tipoDocumentoIdentificacion']),
            $this->jsonValue($detail['usuarioJson'], ['numDocumentoIdentificacion']),
            $detail['tipoServicio'],
            $this->jsonValue($detail['servicioJson'], self::FECHA_FIELDS),
            $this->jsonValue($detail['servicioJson'], self::CODIGO_FIELDS),
            $this->jsonValue($detail['servicioJson'], ['nomTecnologiaSalud']),
            $this->jsonValue($detail['servicioJson'], ['codDiagnosticoPrincipal']),
            $this->jsonValue($detail['servicioJson'], self::CANTIDAD_FIELDS),
            $this->jsonValue($detail['servicioJson'], ['consecutivo']),
            $observation,
        ];
    }

    /**
     * Agrupa las líneas por llave clínica para estudiar repeticiones y
     * duplicados. Solo se muestran los grupos con alguna coincidencia.
     *
     * @param  array<string, array<string, mixed>>  $groups
     * @param  array<string, mixed>  $detail
     */
    private function addToDuplicateGroups(array &$groups, array $detail): void
    {
        $key = $detail['clinicalKey'] !== ''
            ? $detail['clinicalKey']
            : implode('|', ['SIN_LLAVE', $detail['casoId'], $detail['fechaAtencion'], $detail['tipoServicio'], $detail['codigo']]);

        $groups[$key] ??= [
            'tipoDocumentoIdentificacion' => $this->jsonValue($detail['usuarioJson'], ['tipoDocumentoIdentificacion']),
            'numDocumentoIdentificacion' => $this->jsonValue($detail['usuarioJson'], ['numDocumentoIdentificacion']),
            'fecha' => $this->jsonValue($detail['servicioJson'], self::FECHA_FIELDS),
            'tipoServicio' => $detail['tipoServicio'],
            'codigo' => $this->jsonValue($detail['servicioJson'], self::CODIGO_FIELDS),
            'cantidad' => $this->jsonValue($detail['servicioJson'], self::CANTIDAD_FIELDS),
            'facturas' => [],
            'consecutivos' => [],
            'estados' => [],
            'veces' => 0,
        ];

        $group = &$groups[$key];
        $group['veces']++;
        $group['facturas'][$detail['factura']] = true;
        $group['consecutivos'][] = (string) $this->jsonValue($detail['servicioJson'], ['consecutivo']);

        if ($detail['estadoDuplicidad'] !== EstadoDuplicidad::Ninguno->value) {
            $group['estados'][$detail['estadoDuplicidad']] = true;
        }

        unset($group);
    }

    /**
     * @param  array<string, mixed>  $group
     * @return array<int, mixed>
     */
    private function duplicateValues(array $group): array
    {
        $facturas = array_keys($group['facturas']);
        sort($facturas);
        $observations = array_values(array_filter([
            isset($group['estados'][EstadoDuplicidad::DuplicadoExacto->value]) ? 'COPIA EXACTA - LA SEGUNDA NO SUMA' : null,
            isset($group['estados'][EstadoDuplicidad::PosibleDobleFacturacion->value]) ? 'POSIBLE DOBLE FACTURACION - SUMA' : null,
            isset($group['estados'][EstadoDuplicidad::RepeticionMismaFactura->value]) ? 'REPETICION EN LA MISMA FACTURA - SUMA' : null,
        ]));

        return [
            implode(', ', $facturas),
            $group['tipoDocumentoIdentificacion'],
            $group['numDocumentoIdentificacion'],
            $group['fecha'],
            $group['tipoServicio'],
            $group['codigo'],
            $group['cantidad'],
            implode(', ', $group['consecutivos']),
            $group['veces'],
            implode(' | ', $observations),
        ];
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array<int, mixed>
     */
    private function patientValues(array $profile): array
    {
        $json = $profile['usuarioJson'] ?? [];

        return [
            $profile['mesAtencion'] === '' ? 'SIN FECHA' : $profile['mesAtencion'],
            $this->jsonValue($json, ['tipoDocumentoIdentificacion']),
            $this->jsonValue($json, ['numDocumentoIdentificacion']),
            $this->jsonValue($json, ['codSexo']),
            $this->jsonValue($json, ['fechaNacimiento']),
            $this->jsonValue($json, ['codMunicipioResidencia']),
            $this->jsonValue($json, ['codZonaTerritorialResidencia']),
            $this->jsonValue($json, ['tipoUsuario']),
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
            $profile['serviciosEstadoSinTarifa'] + $profile['serviciosRevisarPresentacion'],
            round($profile['costoValido'] + $profile['costoValidoConAlerta'], 2),
            $this->patientObservation($profile),
        ];
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function patientObservation(array $profile): string
    {
        $labels = [
            EstadoClinico::SinDiagnosticoRuta->value => 'SIN DIAGNOSTICO DE RUTA - ASIGNADO POR SEXO',
            EstadoClinico::InconsistenciaSexoRuta->value => 'INCONSISTENCIA SEXO/RUTA',
            EstadoClinico::SinDocumento->value => 'SIN DOCUMENTO',
            EstadoClinico::SinFecha->value => 'SIN FECHA DE ATENCION',
        ];

        return implode(' | ', array_values(array_filter(array_map(
            static fn (string $estado): ?string => $labels[$estado] ?? null,
            $profile['estadosClinicos']
        ))));
    }

    /**
     * @param  array<int, array<string, mixed>>  $controlRows
     * @param  array<string, array{costoPotencial: float}>  $fileCosts
     * @return array<int, array<int, mixed>>
     */
    private function controlValues(array $controlRows, array $fileCosts): array
    {
        $differences = $this->repeatedInvoiceDifferences($controlRows, $fileCosts);

        return array_map(function (array $control) use ($differences, $fileCosts): array {
            $observations = [];

            if ($control['condicion'] === CondicionArchivo::ArchivoDuplicadoExcluido->value) {
                $observations[] = 'ARCHIVO DUPLICADO EXCLUIDO: contenido idéntico a '.$control['relacionados'].'; no se procesa para evitar doble conteo.';
            } elseif ($control['condicion'] === CondicionArchivo::FacturaRepetidaConDiferencias->value) {
                $observations[] = sprintf(
                    'FACTURA REPETIDA CON CONTENIDO DIFERENTE: también llegó en %s. Diferencias: %s Costo potencial de este archivo: $%s. Sus servicios no suman hasta definir el archivo válido.',
                    $control['relacionados'],
                    $differences[$control['archivoOrigen']] ?? '',
                    number_format($fileCosts[$control['archivoOrigen']]['costoPotencial'] ?? 0.0, 2, '.', '')
                );
            }

            $remaining = trim(str_replace([
                'Contenido idéntico a otro archivo cargado; no se procesa para evitar doble conteo.',
                'La misma factura llegó con contenido diferente; sus servicios no suman hasta definir el archivo válido.',
            ], '', (string) $control['observacion']));

            if ($remaining !== '') {
                $observations[] = $remaining;
            }

            return [
                $control['archivoOrigen'],
                $control['factura'],
                $control['tipoNota'],
                $control['numNota'],
                $control['userCount'],
                $control['serviceCount'],
                $control['servicesWithoutDate'],
                $control['firstMonth'],
                $control['lastMonth'],
                $control['estadoCargue'],
                implode(' ', $observations),
            ];
        }, $controlRows);
    }

    /**
     * Compara los archivos que comparten número de factura con contenido
     * diferente, sin elegir cuál es el correcto.
     *
     * @param  array<int, array<string, mixed>>  $controlRows
     * @param  array<string, array{costoPotencial: float}>  $fileCosts
     * @return array<string, string>
     */
    private function repeatedInvoiceDifferences(array $controlRows, array $fileCosts): array
    {
        $groups = [];

        foreach ($controlRows as $control) {
            if ($control['condicion'] === CondicionArchivo::FacturaRepetidaConDiferencias->value) {
                $groups[$control['factura']][] = $control;
            }
        }

        $differences = [];

        foreach ($groups as $controls) {
            $dimensions = [
                'Usuarios' => array_column($controls, 'userCount'),
                'Servicios' => array_column($controls, 'serviceCount'),
                'Meses' => array_map(
                    static fn (array $control): string => trim($control['firstMonth'].' a '.$control['lastMonth']),
                    $controls
                ),
                'Tipo nota' => array_column($controls, 'tipoNota'),
                'Num nota' => array_column($controls, 'numNota'),
                'Costo potencial' => array_map(
                    static fn (array $control): string => number_format(
                        $fileCosts[$control['archivoOrigen']]['costoPotencial'] ?? 0.0,
                        2,
                        '.',
                        ''
                    ),
                    $controls
                ),
            ];
            $text = [];

            foreach ($dimensions as $label => $values) {
                if (count(array_unique(array_map('strval', $values))) > 1) {
                    $text[] = $label.': '.implode(' vs ', $values);
                }
            }

            $summary = $text === []
                ? 'mismos totales; el contenido difiere en el detalle de los servicios.'
                : implode('; ', $text).'.';

            foreach ($controls as $control) {
                $differences[$control['archivoOrigen']] = $summary;
            }
        }

        return $differences;
    }

    /**
     * Conciliación: todo servicio procesado queda en Próstata, Cérvix o NO ASIGNABLE.
     *
     * @param  array<int, array<string, mixed>>  $controlRows
     * @param  array<int, array<string, mixed>>  $allProfiles
     * @return array<int, array<int, mixed>>
     */
    private function conciliationRows(array $controlRows, array $allProfiles, string $ruta): array
    {
        $loaded = array_sum(array_column($controlRows, 'serviceCount'));
        $excluded = array_sum(array_map(
            static fn (array $control): int => $control['condicion'] === CondicionArchivo::ArchivoDuplicadoExcluido->value
                ? (int) $control['serviceCount']
                : 0,
            $controlRows
        ));
        $processed = $loaded - $excluded;
        $groups = [
            RutaCalculo::Prostata->value => $this->emptyConciliationGroup(),
            RutaCalculo::Cervix->value => $this->emptyConciliationGroup(),
            'NO ASIGNABLE - DOBLE RUTA' => $this->emptyConciliationGroup(),
            'NO ASIGNABLE - SEXO NO RECONOCIDO' => $this->emptyConciliationGroup(),
        ];

        foreach ($allProfiles as $profile) {
            $group = match (true) {
                $profile['ruta'] !== RutaCalculo::NoAsignable->value => $profile['ruta'],
                in_array(EstadoClinico::DobleRuta->value, $profile['estadosClinicos'], true) => 'NO ASIGNABLE - DOBLE RUTA',
                default => 'NO ASIGNABLE - SEXO NO RECONOCIDO',
            };
            $groups[$group]['servicios'] += $profile['serviceCount'];
            $groups[$group]['casos']++;
            $groups[$group]['costoTotal'] += $profile['costoValido'] + $profile['costoValidoConAlerta'];
            $groups[$group]['potencialProstata'] += $profile['costoPotencialProstata'];
            $groups[$group]['potencialCervix'] += $profile['costoPotencialCervix'];
        }

        $classified = array_sum(array_column($groups, 'servicios'));
        $rows = [
            ['SERVICIOS EN ARCHIVOS CARGADOS', $loaded, '', '', count($controlRows).' archivo(s) cargado(s).'],
            ['SERVICIOS EN ARCHIVOS DUPLICADOS EXCLUIDOS', $excluded, '', '', 'Copias idénticas; no se procesan para evitar doble conteo.'],
            ['TOTAL JSON PROCESADO', $processed, count($allProfiles), '', 'Debe ser igual a PROSTATA + CERVIX + NO ASIGNABLE.'],
        ];

        foreach ($groups as $label => $group) {
            $isNotAssignable = str_starts_with($label, 'NO ASIGNABLE');
            $rows[] = [
                $label,
                $group['servicios'],
                $group['casos'],
                round($group['costoTotal'], 2),
                match (true) {
                    $isNotAssignable => sprintf(
                        'Casos presentes en ambos Excel; no suman en ninguna ejecución. Escenarios alternativos, no ejecución: Próstata %s / Cérvix %s.',
                        number_format($group['potencialProstata'], 2, '.', ''),
                        number_format($group['potencialCervix'], 2, '.', '')
                    ),
                    $label === $ruta => 'Ruta de esta ejecución.',
                    default => 'Se procesa y suma en la ejecución de '.$label.'.',
                },
            ];
        }

        $difference = $processed - $classified;
        $rows[] = [
            'DIFERENCIA',
            $difference,
            '',
            '',
            $difference === 0
                ? 'Conciliación completa: ningún servicio se perdió ni se duplicó.'
                : 'REVISAR: hay servicios procesados que no quedaron clasificados.',
        ];

        return $rows;
    }

    /**
     * @return array{servicios: int, casos: int, costoTotal: float, potencialProstata: float, potencialCervix: float}
     */
    private function emptyConciliationGroup(): array
    {
        return [
            'servicios' => 0,
            'casos' => 0,
            'costoTotal' => 0.0,
            'potencialProstata' => 0.0,
            'potencialCervix' => 0.0,
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
     * Resumen mensual de la ejecución: COSTO TOTAL solo incluye lo que suma.
     * CASOS A REVISAR cuenta las líneas de esa hoja, incluidas las compartidas
     * no asignables.
     *
     * @param  array<int, array<string, mixed>>  $profiles
     * @param  array<int, array<string, mixed>>  $sharedProfiles
     * @return array<int, array<int, float|int|string>>
     */
    private function monthlySummaries(array $profiles, array $sharedProfiles): array
    {
        $summaries = [];

        foreach ($profiles as $profile) {
            $summary = &$this->monthlySummary($summaries, $profile['mesAtencion']);

            if (! $profile['hasMissingIdentity']) {
                $summary['pacientes'][$profile['tipoDocumentoIdentificacion'].'|'.$profile['numDocumentoIdentificacion']] = true;
            }

            $summary['servicios'] += $profile['serviceCount'];
            $summary['costoTotal'] += $profile['costoValido'] + $profile['costoValidoConAlerta'];
            $summary['casosRevisar'] += $profile['serviciosEnRevision'];
            $summary['sinTarifaAplicada'] += $profile['serviciosEstadoSinTarifa'] + $profile['serviciosRevisarPresentacion'];

            unset($summary);
        }

        foreach ($sharedProfiles as $profile) {
            $summary = &$this->monthlySummary($summaries, $profile['mesAtencion']);
            $summary['casosRevisar'] += $profile['serviceCount'];
            unset($summary);
        }

        ksort($summaries);

        return array_values(array_map(static fn (array $summary): array => [
            $summary['mesAtencion'] === '' ? 'SIN FECHA' : $summary['mesAtencion'],
            count($summary['pacientes']),
            $summary['servicios'],
            round($summary['costoTotal'], 2),
            $summary['casosRevisar'],
            $summary['sinTarifaAplicada'],
        ], $summaries));
    }

    /**
     * @param  array<string, array<string, mixed>>  $summaries
     * @return array<string, mixed>
     */
    private function &monthlySummary(array &$summaries, string $mesAtencion): array
    {
        $key = $mesAtencion === '' ? 'SIN_FECHA' : $mesAtencion;

        $summaries[$key] ??= [
            'mesAtencion' => $mesAtencion,
            'pacientes' => [],
            'servicios' => 0,
            'costoTotal' => 0.0,
            'casosRevisar' => 0,
            'sinTarifaAplicada' => 0,
        ];

        return $summaries[$key];
    }
}
