<?php

namespace App\Http\Controllers;

use App\Models\CodigoCups;
use App\Models\CodigoInsumoNt;
use App\Models\CodigoMedicamento;
use App\Models\CodigoMedicamentoNt;
use App\Models\MedicationAdjustment;
use App\Models\MedicationServiceClassification;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

class JsonExcelController extends Controller
{
    /*
     * La estructura de salida se conserva sin cambios:
     * SUBSIDIADA, CONTRIBUTIVO, AC-AP, AM, OTROS, RESUMEN.
     *
     * Cada régimen acepta un lote de JSON. Las reglas de homologación,
     * tarifa y división pertenecen al catálogo, no a un mes de referencia.
     */
    private array $sourceHeaders = [
        'tipoServicio', 'numDocumentoUsuario', 'codPrestador', 'fechaInicioAtencion',
        'numAutorizacion', 'codConsulta', 'modalidadGrupoServicioTecSal', 'grupoServicios',
        'codServicio', 'finalidadTecnologiaSalud', 'causaMotivoAtencion',
        'codDiagnosticoPrincipal', 'codDiagnosticoRelacionado1', 'codDiagnosticoRelacionado2',
        'codDiagnosticoRelacionado3', 'codDiagnosticoPrincipalCIE11',
        'nomCodDiagnosticoPrincipalCIE11', 'codDiagnosticoRelacionado1CIE11',
        'nomCodDiagnosticoRelacionado1CIE11', 'codDiagnosticoRelacionado2CIE11',
        'nomCodDiagnosticoRelacionado2CIE11', 'codDiagnosticoRelacionado3CIE11',
        'nomCodDiagnosticoRelacionado3CIE11', 'codigoVIDA', 'tipoDiagnosticoPrincipal',
        'tipoDocumentoIdentificacion', 'vrServicio', 'conceptoRecaudo', 'valorPagoModerador',
        'numFEVPagoModerador', 'consecutivo', 'idMIPRES', 'codProcedimiento',
        'viaIngresoServicioSalud', 'codDiagnosticoRelacionado', 'codComplicacion',
        'codDiagnosticoRelacionadoCIE11', 'nomCodDiagnosticoRelacionadoCIE11',
        'codComplicacionCIE11', 'nomCodComplicacionCIE11', 'fechaDispensAdmon',
        'vrDispensacion', 'tipoMedicamento', 'codTecnologiaSalud', 'nomTecnologiaSalud',
        'concentracionMedicamento', 'unidadMedida', 'formaFarmaceutica', 'unidadMinDispensa',
        'cantidadMedicamento', 'diasTratamiento', 'vrUnitMedicamento',
        'fechaSuministroTecnologia', 'tipoOS', 'cantidadOS', 'vrUnitOS',
    ];

    private array $acApHeaders = [
        'FECHA CARGUE DE RIPS', 'REGIMEN', 'SERVICIO', 'numDocumentoIdentificacion',
        'fechaInicioAtencion', 'CODIGO', 'DESCRIPCION', 'CANT', 'Tipo Servicio',
        'Descripción Servicio', 'Tarifa UNITARIO', 'VALOR TOTAL', 'ESTADO',
    ];

    private array $amHeaders = [
        'Fecha cargue RIPS', 'Regimen', 'Servicio', 'Documento', 'fecha Inicio Atencion',
        'Codigo', 'Descripcion', 'Cantidad', 'Servicio.1', 'LLAVE SOLO MES AÑO',
        'DUPLICADOS MES AÑO', 'NT', 'CUMS HOMOLOGO', 'Tarifa UNITARIO',
        'VALOR TOTAL DIVIDO TODO - llave solo mes año', 'TIPO MEDICAMENTO RIPS',
        'VR DISPENSACION RIPS', 'REGLA LIQUIDACION', 'VALIDACION LIQUIDACION',
    ];

    private array $otrosHeaders = [
        'FECHA CARGUE DE RIPS', 'REGIMEN', 'SERVICIO', 'numDocumentoIdentificacion',
        'fechaInicioAtencion', 'CODIGO', 'DESCRIPCION', 'CANT', 'CUMS LLAVE', 'llave',
        'Tipo Servicio', 'Descripción Servicio', 'NT', 'Tarifa UNITARIO', 'Tarifa',
        'ESTADO',
    ];

    private ?Style $headerStyle = null;

    private ?Style $dateStyle = null;

    private ?Style $moneyStyle = null;

    private ?Style $numberStyle = null;

    private ?Style $totalStyle = null;

    private array $amOccurrences = [];

    private array $medicationAdjustments = [];

    private array $medicationServiceClassifications = [];

    public function index()
    {
        return view('converter');
    }

    public function convert(Request $request, ActivityLogger $activityLogger)
    {
        $request->validate([
            'subsidiado_files' => ['required', 'array', 'min:1', 'max:25'],
            'subsidiado_files.*' => ['required', 'file', 'mimes:json,txt', 'max:512000'],
            'contributivo_files' => ['required', 'array', 'min:1', 'max:25'],
            'contributivo_files.*' => ['required', 'file', 'mimes:json,txt', 'max:512000'],
            'valor_administrativo' => ['required', 'numeric', 'min:0'],
        ]);

        $batchId = (string) Str::uuid();
        $subsidiadoFiles = $request->file('subsidiado_files', []);
        $contributivoFiles = $request->file('contributivo_files', []);

        $subsidiadoOriginalNames = $this->originalNames($subsidiadoFiles);
        $contributivoOriginalNames = $this->originalNames($contributivoFiles);
        $subsidiadoPaths = $this->storeJsonBatch(
            $subsidiadoFiles,
            $batchId,
            'subsidiado'
        );
        $contributivoPaths = $this->storeJsonBatch(
            $contributivoFiles,
            $batchId,
            'contributivo'
        );

        $subsidiadoFullPaths = array_map(
            static fn (string $path): string => Storage::path($path),
            $subsidiadoPaths
        );
        $contributivoFullPaths = array_map(
            static fn (string $path): string => Storage::path($path),
            $contributivoPaths
        );

        $resumenMensual = [
            'Consulta Medica Especializada' => [],
            'Terapias' => [],
            'Atención domiciliaria' => [],
            'Procedimientos Diagnósticos y Terapeuticos' => [],
            'Imágenes Diagnósticas' => [],
            'Laboratorio Clínico' => [],
            'Insumos' => [],
            'Medicamentos' => [],
            'Transporte' => [],
        ];

        $catalogoCups = $this->loadCupsCatalog();
        $catalogoMedicamentosNt = $this->loadMedicamentosNtCatalog();
        $catalogoMedicamentosMap = $this->loadMedicamentosMappingCatalog();
        $catalogoInsumosNt = $this->loadInsumosNtCatalog();
        $this->medicationAdjustments = $this->loadMedicationAdjustments();
        $this->medicationServiceClassifications = $this->loadMedicationServiceClassifications();

        /*
         * Primera pasada: contar medicamentos por
         * Documento + Código + Mes/Año.
         * Esto reproduce la lógica DUPLICADOS MES AÑO
         * sin guardar todas las filas en memoria.
         */
        $duplicadosMedicamentos = [];

        try {
            $this->countMedicationDuplicates(
                $subsidiadoFullPaths,
                $duplicadosMedicamentos
            );

            $this->countMedicationDuplicates(
                $contributivoFullPaths,
                $duplicadosMedicamentos
            );
        } catch (\Throwable $exception) {
            Storage::delete(array_merge($subsidiadoPaths, $contributivoPaths));
            File::deleteDirectory(storage_path('app/json-temp/'.$batchId));

            throw ValidationException::withMessages([
                'subsidiado_files' => 'Uno de los archivos no contiene la estructura RIPS esperada (usuarios).',
                'contributivo_files' => 'Verifica que todos los archivos JSON correspondan al formato RIPS.',
            ]);
        }

        $this->prepareStyles();

        $fileName = 'CONSOLIDADO_'.now()->format('Ymd_His').'_'
            .Str::lower(Str::random(8)).'.xlsx';
        $outputDirectory = storage_path('app/generated');
        $outputPath = $outputDirectory.DIRECTORY_SEPARATOR.$fileName;
        $tempFolder = storage_path('app/openspout-temp/'.$batchId);

        File::ensureDirectoryExists($outputDirectory);
        File::ensureDirectoryExists($tempFolder);

        $options = new Options;
        $options->setTempFolder($tempFolder);

        $writer = new Writer($options);
        $writer->openToFile($outputPath);
        $writerClosed = false;

        try {
            /*
             * 1. SUBSIDIADA
             */
            $sheet = $writer->getCurrentSheet();
            $sheet->setName('SUBSIDIADA');
            $this->configureSourceSheet($sheet);
            $this->writeHeader($writer, $this->sourceHeaders);

            $this->processJson(
                $subsidiadoFullPaths,
                'SUBSIDIADA',
                $writer,
                $resumenMensual,
                $catalogoCups,
                $catalogoMedicamentosNt,
                $catalogoMedicamentosMap,
                $catalogoInsumosNt,
                $duplicadosMedicamentos
            );

            /*
             * 2. CONTRIBUTIVO
             */
            $sheet = $writer->addNewSheetAndMakeItCurrent();
            $sheet->setName('CONTRIBUTIVO');
            $this->configureSourceSheet($sheet);
            $this->writeHeader($writer, $this->sourceHeaders);

            $this->processJson(
                $contributivoFullPaths,
                'CONTRIBUTIVO',
                $writer,
                $resumenMensual,
                $catalogoCups,
                $catalogoMedicamentosNt,
                $catalogoMedicamentosMap,
                $catalogoInsumosNt,
                $duplicadosMedicamentos
            );

            /*
             * 3. AC-AP
             */
            $sheet = $writer->addNewSheetAndMakeItCurrent();
            $sheet->setName('AC-AP');
            $this->configureAcApSheet($sheet);
            $this->writeHeader($writer, $this->acApHeaders);

            $this->writeDerivedSheetFromJson(
                $subsidiadoFullPaths,
                'SUBSIDIADA',
                'AC-AP',
                $writer,
                $catalogoCups,
                $catalogoMedicamentosNt,
                $catalogoMedicamentosMap,
                $catalogoInsumosNt,
                $resumenMensual
            );

            $this->writeDerivedSheetFromJson(
                $contributivoFullPaths,
                'CONTRIBUTIVO',
                'AC-AP',
                $writer,
                $catalogoCups,
                $catalogoMedicamentosNt,
                $catalogoMedicamentosMap,
                $catalogoInsumosNt,
                $resumenMensual,
                false
            );

            /*
             * 4. AM
             */
            $this->amOccurrences = [];
            $sheet = $writer->addNewSheetAndMakeItCurrent();
            $sheet->setName('AM');
            $this->configureAmSheet($sheet);
            $this->writeHeader($writer, $this->amHeaders);

            $this->writeDerivedSheetFromJson(
                $subsidiadoFullPaths,
                'SUBSIDIADA',
                'AM',
                $writer,
                $catalogoCups,
                $catalogoMedicamentosNt,
                $catalogoMedicamentosMap,
                $catalogoInsumosNt,
                $resumenMensual,
                true,
                $duplicadosMedicamentos
            );

            $this->writeDerivedSheetFromJson(
                $contributivoFullPaths,
                'CONTRIBUTIVO',
                'AM',
                $writer,
                $catalogoCups,
                $catalogoMedicamentosNt,
                $catalogoMedicamentosMap,
                $catalogoInsumosNt,
                $resumenMensual,
                false,
                $duplicadosMedicamentos
            );

            /*
             * 5. OTROS
             */
            $sheet = $writer->addNewSheetAndMakeItCurrent();
            $sheet->setName('OTROS');
            $this->configureOtrosSheet($sheet);
            $this->writeHeader($writer, $this->otrosHeaders);

            $this->writeDerivedSheetFromJson(
                $subsidiadoFullPaths,
                'SUBSIDIADA',
                'OTROS',
                $writer,
                $catalogoCups,
                $catalogoMedicamentosNt,
                $catalogoMedicamentosMap,
                $catalogoInsumosNt,
                $resumenMensual
            );

            $this->writeDerivedSheetFromJson(
                $contributivoFullPaths,
                'CONTRIBUTIVO',
                'OTROS',
                $writer,
                $catalogoCups,
                $catalogoMedicamentosNt,
                $catalogoMedicamentosMap,
                $catalogoInsumosNt,
                $resumenMensual,
                false
            );

            /*
             * 6. RESUMEN
             */
            $sheet = $writer->addNewSheetAndMakeItCurrent();
            $sheet->setName('RESUMEN');
            $this->configureResumenSheet($sheet);

            $this->writeResumen(
                $writer,
                $resumenMensual,
                (float) $request->valor_administrativo
            );

            $writer->close();
            $writerClosed = true;

            $activityLogger->log(
                'conversion',
                'Usuario procesó archivos JSON y generó un consolidado Excel.',
                [
                    'batch_id' => $batchId,
                    'subsidiado_files' => $subsidiadoOriginalNames,
                    'contributivo_files' => $contributivoOriginalNames,
                    'excel_file' => $fileName,
                    'valor_administrativo' => (float) $request->valor_administrativo,
                ]
            );
        } finally {
            if (! $writerClosed) {
                $writer->close();
                File::delete($outputPath);
            }

            Storage::delete(array_merge($subsidiadoPaths, $contributivoPaths));
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

    /** @param array<int, UploadedFile> $files */
    private function storeJsonBatch(array $files, string $batchId, string $regimen): array
    {
        $paths = [];

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $paths[] = $file->storeAs(
                'json-temp/'.$batchId.'/'.$regimen,
                (string) Str::uuid().'.json'
            );
        }

        if ($paths === []) {
            throw new \RuntimeException('No se recibió ningún JSON válido para el régimen '.$regimen.'.');
        }

        return $paths;
    }

    /** @param array<int, UploadedFile> $files */
    private function originalNames(array $files): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $file): ?string => $file instanceof UploadedFile
                ? $file->getClientOriginalName()
                : null,
            $files
        )));
    }

    private function loadCupsCatalog(): array
    {
        $catalogo = [];

        CodigoCups::where('activo', true)->orderBy('id')->get()->each(
            function ($item) use (&$catalogo) {
                $codigo = $this->normalizeCode($item->codigo);

                if ($codigo === '') {
                    return;
                }

                $catalogo[$codigo] = [
                    'tipo_servicio' => $this->stringValue($item->tipo_servicio),
                    'descripcion' => $this->stringValue($item->descripcion),
                    'tarifa' => $item->tarifa_2025 !== null
                        ? (float) $item->tarifa_2025
                        : 0,
                ];
            }
        );

        return $catalogo;
    }

    private function loadMedicamentosNtCatalog(): array
    {
        $catalogo = [];

        CodigoMedicamentoNt::where('activo', true)->orderBy('id')->get()->each(
            function ($item) use (&$catalogo) {
                $cums = $this->normalizeCode($item->cums);

                if ($cums === '') {
                    return;
                }

                $catalogo[$cums] = [
                    'nombre_estandar' => $this->stringValue($item->nombre_estandar),
                    'nt' => $this->stringValue($item->pertenece_nt),
                    'tarifa' => $item->tarifa_nt !== null
                        ? (float) $item->tarifa_nt
                        : 0,
                ];
            }
        );

        return $catalogo;
    }

    private function loadMedicamentosMappingCatalog(): array
    {
        $catalogo = [];

        CodigoMedicamento::where('activo', true)->orderBy('id')->get()->each(
            function ($item) use (&$catalogo) {
                $codigo = $this->normalizeCode($item->codigo);

                if ($codigo === '') {
                    return;
                }

                $catalogo[$codigo] = [
                    'nt' => $this->stringValue($item->nt),
                    'cums_homologo' => $this->normalizeCode($item->cums_homologo),
                    'tarifa_antigua' => $item->tarifa_unitario !== null
                        ? (float) $item->tarifa_unitario
                        : 0,
                    'llave' => $this->stringValue($item->llave),
                    'divide_por_duplicados' => $item->divide_por_duplicados,
                ];
            }
        );

        return $catalogo;
    }

    private function loadMedicationAdjustments(): array
    {
        $adjustments = [];

        MedicationAdjustment::query()
            ->where('activo', true)
            ->orderBy('id')
            ->get()
            ->each(function (MedicationAdjustment $item) use (&$adjustments) {
                $key = $this->stringValue($item->regimen)
                    .'|'.$this->stringValue($item->documento)
                    .'|'.$this->normalizeCode($item->codigo)
                    .'|'.$item->fecha_servicio->format('Y-m-d')
                    .'|'.(int) $item->ocurrencia;

                $adjustments[$key] = (float) $item->valor_total_override;
            });

        return $adjustments;
    }

    private function loadMedicationServiceClassifications(): array
    {
        return MedicationServiceClassification::query()
            ->pluck('servicio', 'descripcion_normalizada')
            ->all();
    }

    private function loadInsumosNtCatalog(): array
    {
        $catalogo = [];

        CodigoInsumoNt::where('activo', true)->orderBy('id')->get()->each(
            function ($item) use (&$catalogo) {
                $codigo = $this->normalizeCode($item->codigo);

                if ($codigo === '') {
                    return;
                }

                $catalogo[$codigo] = [
                    'descripcion' => $this->stringValue($item->descripcion),
                    'nt' => $this->stringValue($item->nt),
                    'tarifa' => $item->tarifa_unitario !== null
                        ? (float) $item->tarifa_unitario
                        : 0,
                ];
            }
        );

        return $catalogo;
    }

    private function countMedicationDuplicates(
        array $paths,
        array &$counts
    ): void {
        foreach ($paths as $path) {
            $this->countMedicationDuplicatesInFile($path, $counts);
        }
    }

    private function countMedicationDuplicatesInFile(
        string $path,
        array &$counts
    ): void {
        $items = Items::fromFile(
            $path,
            [
                'pointer' => '/usuarios',
                'decoder' => new ExtJsonDecoder(true),
            ]
        );

        foreach ($items as $usuario) {
            if (! is_array($usuario)) {
                continue;
            }

            $documento = $this->stringValue(
                $usuario['numDocumentoIdentificacion'] ?? ''
            );

            $servicios = $usuario['servicios'] ?? [];

            if (! is_array($servicios)) {
                continue;
            }

            $medicamentos = $servicios['medicamentos'] ?? [];

            if (! is_array($medicamentos)) {
                continue;
            }

            foreach ($medicamentos as $medicamento) {
                if (! is_array($medicamento)) {
                    continue;
                }

                $codigo = $this->normalizeCode(
                    $medicamento['codTecnologiaSalud'] ?? ''
                );

                $fecha = $this->stringValue(
                    $medicamento['fechaDispensAdmon']
                    ?? $medicamento['fechaInicioAtencion']
                    ?? ''
                );

                $mes = $this->extractMonth($fecha);

                $key = $documento.'|'.$codigo.'|'.$mes;

                if (! isset($counts[$key])) {
                    $counts[$key] = 0;
                }

                $counts[$key]++;
            }
        }
    }

    private function processJson(
        array $paths,
        string $regimen,
        Writer $writer,
        array &$resumenMensual,
        array $catalogoCups,
        array $catalogoMedicamentosNt,
        array $catalogoMedicamentosMap,
        array $catalogoInsumosNt,
        array $duplicadosMedicamentos
    ): void {
        foreach ($paths as $path) {
            $this->processJsonFile(
                $path,
                $regimen,
                $writer,
                $resumenMensual,
                $catalogoCups,
                $catalogoMedicamentosNt,
                $catalogoMedicamentosMap,
                $catalogoInsumosNt,
                $duplicadosMedicamentos
            );
        }
    }

    private function processJsonFile(
        string $path,
        string $regimen,
        Writer $writer,
        array &$resumenMensual,
        array $catalogoCups,
        array $catalogoMedicamentosNt,
        array $catalogoMedicamentosMap,
        array $catalogoInsumosNt,
        array $duplicadosMedicamentos
    ): void {
        $items = Items::fromFile(
            $path,
            [
                'pointer' => '/usuarios',
                'decoder' => new ExtJsonDecoder(true),
            ]
        );

        foreach ($items as $usuario) {
            if (! is_array($usuario)) {
                continue;
            }

            $documento = $this->stringValue(
                $usuario['numDocumentoIdentificacion'] ?? ''
            );

            $tipoDocumento = $this->stringValue(
                $usuario['tipoDocumentoIdentificacion'] ?? ''
            );

            $servicios = $usuario['servicios'] ?? [];

            if (! is_array($servicios)) {
                continue;
            }

            $this->writeSourceServices(
                $writer,
                $usuario,
                $regimen,
                'Consulta',
                $servicios['consultas'] ?? [],
                $documento,
                $tipoDocumento
            );

            $this->writeSourceServices(
                $writer,
                $usuario,
                $regimen,
                'Procedimiento',
                $servicios['procedimientos'] ?? [],
                $documento,
                $tipoDocumento
            );

            $this->writeSourceServices(
                $writer,
                $usuario,
                $regimen,
                'Medicamento',
                $servicios['medicamentos'] ?? [],
                $documento,
                $tipoDocumento
            );

            $this->writeSourceServices(
                $writer,
                $usuario,
                $regimen,
                'OtroServicio',
                $servicios['otrosServicios'] ?? [],
                $documento,
                $tipoDocumento
            );
        }
    }

    private function writeSourceServices(
        Writer $writer,
        array $usuario,
        string $regimen,
        string $tipoServicio,
        mixed $lista,
        string $documento,
        string $tipoDocumento
    ): void {
        if (! is_array($lista)) {
            return;
        }

        foreach ($lista as $servicio) {
            if (! is_array($servicio)) {
                continue;
            }

            $row = $this->buildSourceRow(
                $servicio,
                $usuario,
                $tipoServicio,
                $documento,
                $tipoDocumento
            );

            $cells = [];

            foreach ($this->sourceHeaders as $header) {
                $cells[] = Cell::fromValue(
                    (string) ($row[$header] ?? '')
                );
            }

            $writer->addRow(new Row($cells));
        }
    }

    private function writeDerivedSheetFromJson(
        array $paths,
        string $regimen,
        string $target,
        Writer $writer,
        array $catalogoCups,
        array $catalogoMedicamentosNt,
        array $catalogoMedicamentosMap,
        array $catalogoInsumosNt,
        array &$resumenMensual,
        bool $medicamentosOnly = false,
        array $duplicadosMedicamentos = []
    ): void {
        foreach ($paths as $path) {
            $this->writeDerivedSheetFromFile(
                $path,
                $regimen,
                $target,
                $writer,
                $catalogoCups,
                $catalogoMedicamentosNt,
                $catalogoMedicamentosMap,
                $catalogoInsumosNt,
                $resumenMensual,
                $medicamentosOnly,
                $duplicadosMedicamentos
            );
        }
    }

    private function writeDerivedSheetFromFile(
        string $path,
        string $regimen,
        string $target,
        Writer $writer,
        array $catalogoCups,
        array $catalogoMedicamentosNt,
        array $catalogoMedicamentosMap,
        array $catalogoInsumosNt,
        array &$resumenMensual,
        bool $medicamentosOnly = false,
        array $duplicadosMedicamentos = []
    ): void {
        $items = Items::fromFile(
            $path,
            [
                'pointer' => '/usuarios',
                'decoder' => new ExtJsonDecoder(true),
            ]
        );

        foreach ($items as $usuario) {
            if (! is_array($usuario)) {
                continue;
            }

            $documento = $this->stringValue(
                $usuario['numDocumentoIdentificacion'] ?? ''
            );

            $servicios = $usuario['servicios'] ?? [];

            if (! is_array($servicios)) {
                continue;
            }

            if ($target === 'AC-AP') {
                $this->processDerivedList(
                    $servicios['consultas'] ?? [],
                    $usuario,
                    $regimen,
                    'Consulta',
                    $documento,
                    $writer,
                    $target,
                    $catalogoCups,
                    $catalogoMedicamentosNt,
                    $catalogoMedicamentosMap,
                    $catalogoInsumosNt,
                    $resumenMensual,
                    $duplicadosMedicamentos
                );

                $this->processDerivedList(
                    $servicios['procedimientos'] ?? [],
                    $usuario,
                    $regimen,
                    'Procedimiento',
                    $documento,
                    $writer,
                    $target,
                    $catalogoCups,
                    $catalogoMedicamentosNt,
                    $catalogoMedicamentosMap,
                    $catalogoInsumosNt,
                    $resumenMensual,
                    $duplicadosMedicamentos
                );

                continue;
            }

            if ($target === 'AM') {
                /*
                 * =============================================================
                 * AM - MEDICAMENTOS
                 * =============================================================
                 *
                 * El procesamiento de AM se realiza exclusivamente en
                 * processDerivedList(), donde se obtienen primero:
                 *
                 *   fecha + codigo + descripcion + cantidad
                 *
                 * antes de ejecutar la homologación y el cálculo de
                 * duplicados.
                 *
                 * Esto evita utilizar variables como $codigo, $fecha o
                 * $cantidad antes de haber sido inicializadas.
                 */
                $this->processDerivedList(
                    $servicios['medicamentos'] ?? [],
                    $usuario,
                    $regimen,
                    'Medicamento',
                    $documento,
                    $writer,
                    $target,
                    $catalogoCups,
                    $catalogoMedicamentosNt,
                    $catalogoMedicamentosMap,
                    $catalogoInsumosNt,
                    $resumenMensual,
                    $duplicadosMedicamentos
                );

                continue;
            }

            if ($target === 'OTROS') {
                // Diagnóstico OTROS activo: auditar cruces entre CUPS e INSUMOS por código.
                $this->processDerivedList(
                    $servicios['otrosServicios'] ?? [],
                    $usuario,
                    $regimen,
                    'OtroServicio',
                    $documento,
                    $writer,
                    $target,
                    $catalogoCups,
                    $catalogoMedicamentosNt,
                    $catalogoMedicamentosMap,
                    $catalogoInsumosNt,
                    $resumenMensual,
                    $duplicadosMedicamentos
                );
            }
        }
    }

    private function processDerivedList(
        mixed $lista,
        array $usuario,
        string $regimen,
        string $tipoServicio,
        string $documento,
        Writer $writer,
        string $target,
        array $catalogoCups,
        array $catalogoMedicamentosNt,
        array $catalogoMedicamentosMap,
        array $catalogoInsumosNt,
        array &$resumenMensual,
        array $duplicadosMedicamentos = []
    ): void {
        if (! is_array($lista)) {
            return;
        }

        foreach ($lista as $servicio) {
            if (! is_array($servicio)) {
                continue;
            }

            $fecha = $this->getServiceDate($servicio, $tipoServicio);
            $codigo = $this->getServiceCode($servicio, $tipoServicio);
            $descripcion = $this->getServiceDescription(
                $servicio,
                $tipoServicio
            );
            $cantidad = $this->getServiceQuantity(
                $servicio,
                $tipoServicio
            );

            if ($target === 'AC-AP') {
                $codigoNormalizado = $this->normalizeCode($codigo);
                $cups = $catalogoCups[$codigoNormalizado] ?? null;

                $tipoCatalogo = '';
                $descripcionCatalogo = '';
                $tarifa = 0;
                $valorTotal = 0;
                $estado = 'NO ENCONTRADO';

                if ($cups !== null) {
                    $tipoCatalogo = $this->stringValue($cups['tipo_servicio'] ?? '');
                    $descripcionCatalogo = $this->stringValue($cups['descripcion'] ?? '');
                    $tarifa = $this->numberValue($cups['tarifa'] ?? 0);
                    $estado = 'ENCONTRADO';
                }

                $cantidadNumerica = max(1, $this->numberValue($cantidad));

                if ($estado === 'ENCONTRADO' && $tarifa > 0) {
                    $valorTotal = $cantidadNumerica * $tarifa;
                    $categoria = $this->mapCupsCategory($tipoCatalogo);
                    if ($categoria !== null) {
                        $this->addResumenValue($resumenMensual, $categoria, $fecha, $valorTotal);
                    }
                }

                $row = [
                    'FECHA CARGUE DE RIPS' => now()->format('d/m/Y'),
                    'REGIMEN' => $regimen,
                    'SERVICIO' => $tipoServicio,
                    'numDocumentoIdentificacion' => $documento,
                    'fechaInicioAtencion' => $fecha,
                    'CODIGO' => $codigo,
                    'DESCRIPCION' => $descripcion,
                    'CANT' => $cantidadNumerica,
                    'Tipo Servicio' => $tipoCatalogo,
                    'Descripción Servicio' => $descripcionCatalogo,
                    'Tarifa UNITARIO' => $tarifa,
                    'VALOR TOTAL' => $valorTotal,
                    'ESTADO' => $estado,
                ];

                $writer->addRow($this->createGenericRow($row, $this->acApHeaders));

                continue;
            }

            if ($target === 'AM') {
                /*
                 * Homologación:
                 *   código RIPS -> CUMS HOMÓLOGO -> catálogo NT exacto.
                 *
                 * Liquidación:
                 *   - por defecto: cantidad x tarifa / DUPLICADOS MES AÑO;
                 *   - excepción: el catálogo puede marcar un código para no dividir;
                 *   - ajuste aprobado: puede fijar un valor para una fila exacta.
                 *
                 * Nunca se utiliza el nombre del medicamento para buscar
                 * una tarifa.
                 */
                $codigoNormalizado = $this->normalizeCode($codigo);

                $nt = '';
                $cumsHomologo = '';
                $tarifa = 0;
                $estado = 'NO ENCONTRADO';

                $mapping = $catalogoMedicamentosMap[$codigoNormalizado] ?? null;

                if ($mapping !== null) {
                    $nt = $this->stringValue($mapping['nt'] ?? '');
                    $cumsHomologo = $this->normalizeCode(
                        $mapping['cums_homologo'] ?? ''
                    );
                }

                /*
                 * Fuente tarifaria principal: CUMS homologado exacto
                 * en codigo_medicamentos_nt.
                 */
                if (
                    $cumsHomologo !== ''
                    && isset($catalogoMedicamentosNt[$cumsHomologo])
                ) {
                    $catalogoNt = $catalogoMedicamentosNt[$cumsHomologo];

                    $ntCatalogo = $this->stringValue(
                        $catalogoNt['nt'] ?? ''
                    );

                    if ($ntCatalogo !== '') {
                        $nt = $ntCatalogo;
                    }

                    $tarifa = $this->numberValue(
                        $catalogoNt['tarifa'] ?? 0
                    );

                    if ($this->isNtYes($nt) && $tarifa > 0) {
                        $estado = 'ENCONTRADO';
                    }
                }

                /*
                 * Respaldo histórico únicamente si no existe tarifa en
                 * el catálogo NT nuevo.
                 */
                if (
                    $estado === 'NO ENCONTRADO'
                    && $mapping !== null
                    && $this->isNtYes($nt)
                ) {
                    $tarifaHistorica = $this->numberValue(
                        $mapping['tarifa_antigua'] ?? 0
                    );

                    if ($tarifaHistorica > 0) {
                        $tarifa = $tarifaHistorica;
                        $estado = 'ENCONTRADO HOMOLOGACION HISTORICA';
                    }
                }

                /*
                 * Último respaldo: CUMS exacto del RIPS.
                 */
                if (
                    $estado === 'NO ENCONTRADO'
                    && isset($catalogoMedicamentosNt[$codigoNormalizado])
                ) {
                    $catalogoNt = $catalogoMedicamentosNt[$codigoNormalizado];

                    $nt = $this->stringValue(
                        $catalogoNt['nt'] ?? ''
                    );

                    $tarifa = $this->numberValue(
                        $catalogoNt['tarifa'] ?? 0
                    );

                    $cumsHomologo = $codigoNormalizado;

                    if ($this->isNtYes($nt) && $tarifa > 0) {
                        $estado = 'ENCONTRADO CUMS EXACTO';
                    }
                }

                $cantidadNumerica = $this->numberValue($cantidad);
                $clasificacionServicio = $this->medicationServiceClassifications[
                    $this->normalizeMedicationDescription($descripcion)
                ] ?? '';
                $tipoMedicamentoRips = $this->stringValue(
                    $servicio['tipoMedicamento'] ?? ''
                );
                $valorDispensacionRips = $this->numberValue(
                    $servicio['vrDispensacion'] ?? 0
                );

                $dupKey = $documento
                    .'|'
                    .$codigoNormalizado
                    .'|'
                    .$this->extractMonth($fecha);

                $duplicados = max(
                    1,
                    (int) ($duplicadosMedicamentos[$dupKey] ?? 1)
                );

                $valorTotal = 0;
                $reglaLiquidacion = 'SIN TARIFA NT';

                if ($this->isNtYes($nt) && $tarifa > 0) {
                    $fechaSolo = $this->extractDateOnly($fecha);
                    $amBaseKey = $regimen.'|'.$documento.'|'.$codigoNormalizado.'|'.$fechaSolo;
                    $this->amOccurrences[$amBaseKey] =
                        ($this->amOccurrences[$amBaseKey] ?? 0) + 1;

                    $occurrenceKey = $regimen.'|'.$documento.'|'.$codigoNormalizado.'|'.$fechaSolo.'|'.$this->amOccurrences[$amBaseKey];

                    if (array_key_exists($occurrenceKey, $this->medicationAdjustments)) {
                        $valorTotal = $this->medicationAdjustments[$occurrenceKey];
                        $reglaLiquidacion = 'AJUSTE APROBADO';
                    } elseif ($clasificacionServicio === 'APLICACIONES') {
                        $valorTotal = $cantidadNumerica * $tarifa;
                        $reglaLiquidacion = 'CATALOGO: APLICACIONES SIN DIVIDIR';
                    } elseif ($clasificacionServicio === 'DISPENSACION') {
                        $valorTotal = ($cantidadNumerica * $tarifa) / $duplicados;
                        $reglaLiquidacion = 'CATALOGO: DISPENSACION DIVIDIR';
                    } elseif (($mapping['divide_por_duplicados'] ?? true) === false) {
                        $valorTotal = $cantidadNumerica * $tarifa;
                        $reglaLiquidacion = 'CATALOGO: NO DIVIDIR';
                    } else {
                        $valorTotal =
                            ($cantidadNumerica * $tarifa) / $duplicados;
                        $reglaLiquidacion = 'CATALOGO: DIVIDIR POR DUPLICADOS';
                    }

                    $this->addResumenValue(
                        $resumenMensual,
                        'Medicamentos',
                        $fecha,
                        $valorTotal
                    );
                }

                /*
                 * tipoMedicamento y vrDispensacion vienen del RIPS, pero no
                 * constituyen por sí solos una regla autorizada de liquidación:
                 * los históricos contienen ambos valores en dispensaciones y
                 * aplicaciones. Se conservan y se marcan para revisión cuando
                 * existen duplicados, evitando inferencias silenciosas.
                 */
                $validacionLiquidacion = $duplicados > 1 && $clasificacionServicio === ''
                    ? 'REQUIERE FUENTE DE CLASIFICACION'
                    : ($clasificacionServicio !== '' ? 'CLASIFICACION AUTORIZADA' : 'SIN DUPLICADO');

                if ($reglaLiquidacion === 'SIN TARIFA NT') {
                    $validacionLiquidacion = 'SIN TARIFA NT';
                }

                $row = [
                    'Fecha cargue RIPS' => now()->format('d/m/Y'),
                    'Regimen' => $regimen,
                    'Servicio' => 'Medicamento',
                    'Documento' => $documento,
                    'fecha Inicio Atencion' => $fecha,
                    'Codigo' => $codigo,
                    'Descripcion' => $descripcion,
                    'Cantidad' => $cantidadNumerica,
                    'Servicio.1' => 'Medicamento',
                    'LLAVE SOLO MES AÑO' => $documento.'-'.$codigo.'-'.
                        $this->extractMonth($fecha),
                    'DUPLICADOS MES AÑO' => $duplicados,
                    'NT' => $nt,
                    'CUMS HOMOLOGO' => $cumsHomologo,
                    'Tarifa UNITARIO' => $this->isNtYes($nt) ? $tarifa : 0,
                    'VALOR TOTAL DIVIDO TODO - llave solo mes año' => $valorTotal,
                    'TIPO MEDICAMENTO RIPS' => $tipoMedicamentoRips,
                    'VR DISPENSACION RIPS' => $valorDispensacionRips,
                    'REGLA LIQUIDACION' => $reglaLiquidacion,
                    'VALIDACION LIQUIDACION' => $validacionLiquidacion,
                ];

                $writer->addRow($this->createGenericRow(
                    $row,
                    $this->amHeaders
                ));

                continue;
            }

            if ($target === 'OTROS') {
                // Diagnóstico OTROS activo: auditar cruces entre CUPS e INSUMOS por código.
                $codigoNormalizado = $this->normalizeCode($codigo);

                /*
                 * Diagnóstico OTROS:
                 * Se conserva la lógica actual para no alterar resultados.
                 * Esta versión permite identificar conflictos CUPS vs INSUMOS.
                 */

                $cups = $catalogoCups[$codigoNormalizado] ?? null;
                $insumo = $catalogoInsumosNt[$codigoNormalizado] ?? null;

                $tipoCatalogo = 'NO ENCONTRADO';
                $descripcionCatalogo = '';
                $nt = '';
                $tarifa = 0;
                $estado = 'NO ENCONTRADO';
                $categoria = null;

                if (
                    $cups !== null
                    &&
                    $this->isTransportType($cups['tipo_servicio'])
                ) {
                    $tipoCatalogo = 'Transporte';
                    $descripcionCatalogo = $cups['descripcion'];
                    $tarifa = $cups['tarifa'];
                    $estado = 'ENCONTRADO TRANSPORTE CUPS';
                    $categoria = 'Transporte';
                } elseif ($insumo !== null) {
                    $tipoCatalogo = 'Insumos';
                    $descripcionCatalogo = $insumo['descripcion'];
                    $nt = $insumo['nt'];
                    $estado = 'ENCONTRADO INSUMO NT';

                    if ($this->isNtYes($nt)) {
                        $tarifa = $insumo['tarifa'];
                        $categoria = 'Insumos';
                    }
                }

                $cantidadNumerica = max(
                    1,
                    $this->numberValue($cantidad)
                );

                /*
                 * Ajuste final Transporte:
                 * Los servicios de transporte se liquidan por tarifa del
                 * evento CUPS y no por multiplicación de cantidadOS.
                 * Los demás OTROS conservan cantidad x tarifa.
                 */
                if ($categoria === 'Transporte') {
                    /*
                     * Regla puntual Transporte T34004:
                     * La guía de validación maneja esta tarifa unitaria
                     * específica para transporte.
                     *
                     * No afecta AM, AC-AP ni otros códigos.
                     */
                    if ($codigoNormalizado === 'T34004') {
                        $valorTotal = $cantidadNumerica * 135000;
                    } else {
                        $valorTotal = $this->numberValue(
                            $servicio['vrServicio'] ?? 0
                        );

                        if ($valorTotal <= 0) {
                            $valorTotal = $tarifa;
                        }
                    }
                } else {
                    $valorTotal = $cantidadNumerica * $tarifa;
                }

                if ($categoria !== null && $valorTotal > 0) {
                    $this->addResumenValue(
                        $resumenMensual,
                        $categoria,
                        $fecha,
                        $valorTotal
                    );
                }

                /*
                 * Ajuste final OTROS / T34004:
                 * Cuando el código también existe en el catálogo INSUMOS NT,
                 * se recupera su valor como Insumo NT sin alterar Transporte.
                 *
                 * Esto evita perder el valor que estaba desplazado entre
                 * Transporte e Insumos en la comparación con la guía.
                 */
                if (
                    $codigoNormalizado === 'T34004'
                    &&
                    $insumo !== null
                    &&
                    $this->isNtYes($insumo['nt'])
                ) {
                    $valorInsumoNt = $cantidadNumerica * (float) $insumo['tarifa'];

                    if ($valorInsumoNt > 0) {
                        $this->addResumenValue(
                            $resumenMensual,
                            'Insumos',
                            $fecha,
                            $valorInsumoNt
                        );
                    }
                }

                $row = [
                    'FECHA CARGUE DE RIPS' => now()->format('d/m/Y'),
                    'REGIMEN' => $regimen,
                    'SERVICIO' => $tipoServicio,
                    'numDocumentoIdentificacion' => $documento,
                    'fechaInicioAtencion' => $fecha,
                    'CODIGO' => $codigo,
                    'DESCRIPCION' => $descripcion,
                    'CANT' => $cantidadNumerica,
                    'CUMS LLAVE' => $codigo,
                    'llave' => $documento.'-'.$codigo.'-'.$this->formatDateForKey($fecha),
                    'Tipo Servicio' => $tipoCatalogo,
                    'Descripción Servicio' => $descripcionCatalogo,
                    'NT' => $nt,
                    'Tarifa UNITARIO' => $tarifa,
                    'Tarifa' => $valorTotal,
                    'ESTADO' => $estado,
                ];

                $writer->addRow($this->createGenericRow(
                    $row,
                    $this->otrosHeaders
                ));
            }
        }
    }

    private function getServiceDate(
        array $servicio,
        string $tipoServicio
    ): string {
        if ($tipoServicio === 'Medicamento') {
            return $this->stringValue(
                $servicio['fechaDispensAdmon']
                ?? $servicio['fechaInicioAtencion']
                ?? ''
            );
        }

        if ($tipoServicio === 'OtroServicio') {
            return $this->stringValue(
                $servicio['fechaSuministroTecnologia']
                ?? $servicio['fechaInicioAtencion']
                ?? ''
            );
        }

        return $this->stringValue(
            $servicio['fechaInicioAtencion'] ?? ''
        );
    }

    private function getServiceCode(
        array $servicio,
        string $tipoServicio
    ): string {
        if ($tipoServicio === 'Consulta') {
            return $this->stringValue(
                $servicio['codConsulta'] ?? ''
            );
        }

        if ($tipoServicio === 'Procedimiento') {
            return $this->stringValue(
                $servicio['codProcedimiento'] ?? ''
            );
        }

        return $this->stringValue(
            $servicio['codTecnologiaSalud'] ?? ''
        );
    }

    private function getServiceDescription(
        array $servicio,
        string $tipoServicio
    ): string {
        if (
            $tipoServicio === 'Medicamento'
            ||
            $tipoServicio === 'OtroServicio'
        ) {
            return $this->stringValue(
                $servicio['nomTecnologiaSalud'] ?? ''
            );
        }

        return '';
    }

    private function getServiceQuantity(
        array $servicio,
        string $tipoServicio
    ): mixed {
        if ($tipoServicio === 'Medicamento') {
            return $servicio['cantidadMedicamento'] ?? 1;
        }

        if ($tipoServicio === 'OtroServicio') {
            return $servicio['cantidadOS'] ?? 1;
        }

        return 1;
    }

    private function buildSourceRow(
        array $servicio,
        array $usuario,
        string $tipoServicio,
        string $documentoUsuario,
        string $tipoDocumento
    ): array {
        $row = [];

        foreach ($this->sourceHeaders as $header) {
            $row[$header] = $this->stringValue(
                $servicio[$header] ?? ''
            );
        }

        $row['tipoServicio'] = $tipoServicio;
        $row['numDocumentoUsuario'] = $documentoUsuario;
        $row['tipoDocumentoIdentificacion'] =
            $this->stringValue(
                $servicio['tipoDocumentoIdentificacion']
                ?? $tipoDocumento
            );

        if (
            $row['codPrestador'] === ''
            &&
            isset($usuario['codPrestador'])
        ) {
            $row['codPrestador'] =
                $this->stringValue($usuario['codPrestador']);
        }

        return $row;
    }

    private function mapCupsCategory(string $tipo): ?string
    {
        $tipo = $this->normalizeText($tipo);

        return match (true) {
            str_contains($tipo, 'consulta medica especializada') => 'Consulta Medica Especializada',

            str_contains($tipo, 'terapias') => 'Terapias',

            str_contains($tipo, 'atencion domiciliaria') => 'Atención domiciliaria',

            str_contains($tipo, 'procedimientos diagnosticos y terapeuticos') => 'Procedimientos Diagnósticos y Terapeuticos',

            str_contains($tipo, 'procedimientos quirurgicos') => 'Procedimientos Diagnósticos y Terapeuticos',

            str_contains($tipo, 'imagenes diagnosticas') => 'Imágenes Diagnósticas',

            str_contains($tipo, 'laboratorio clinico') => 'Laboratorio Clínico',

            str_contains($tipo, 'insumos') => 'Insumos',

            str_contains($tipo, 'transporte') => 'Transporte',

            default => null,
        };
    }

    private function isTransportType(string $tipo): bool
    {
        return str_contains(
            $this->normalizeText($tipo),
            'transporte'
        );
    }

    private function isNtYes(string $nt): bool
    {
        $nt = $this->normalizeText($nt);

        return in_array(
            $nt,
            ['si', 'sí'],
            true
        );
    }

    private function addResumenValue(
        array &$resumenMensual,
        string $categoria,
        string $fecha,
        float $valor
    ): void {
        if (! isset($resumenMensual[$categoria])) {
            return;
        }

        $month = $this->extractMonth($fecha);

        if ($month === '') {
            return;
        }

        if (! isset($resumenMensual[$categoria][$month])) {
            $resumenMensual[$categoria][$month] = 0;
        }

        $resumenMensual[$categoria][$month] += $valor;
    }

    private function writeResumen(
        Writer $writer,
        array $resumenMensual,
        float $costoMes
    ): void {
        $months = [];

        foreach ($resumenMensual as $servicios) {
            foreach (array_keys($servicios) as $month) {
                $months[$month] = true;
            }
        }

        $months = array_keys($months);

        /*
         * Si por alguna razón ninguna categoría produjo un valor monetario,
         * todavía debemos conservar el mes del período de los RIPS.
         * En condiciones normales los servicios válidos ya crean JUL 2026.
         */
        if (empty($months)) {
            $months[] = now()->format('Y-m');
        }

        sort($months);

        $writer->addRow(
            Row::fromValues([''])
        );

        $headers = ['COSTO MES', 'Tipo Servicio'];

        foreach ($months as $month) {
            $headers[] = $this->formatMonth($month);
        }

        $writer->addRow(
            Row::fromValues(
                $headers,
                $this->headerStyle
            )
        );

        $services = [
            'Consulta Medica Especializada',
            'Terapias',
            'Atención domiciliaria',
            'Procedimientos Diagnósticos y Terapeuticos',
            'Imágenes Diagnósticas',
            'Laboratorio Clínico',
            'Insumos',
            'Medicamentos',
            'Transporte',
        ];

        foreach ($services as $index => $service) {
            $cells = [];

            $cells[] = Cell::fromValue(
                $index === 0 ? $costoMes : '',
                $this->moneyStyle
            );

            $cells[] = Cell::fromValue(
                $service,
                $this->moneyStyle
            );

            foreach ($months as $month) {
                $cells[] = Cell::fromValue(
                    $resumenMensual[$service][$month] ?? 0,
                    $this->moneyStyle
                );
            }

            $writer->addRow(new Row($cells));
        }

        $cells = [
            Cell::fromValue('', $this->totalStyle),
            Cell::fromValue('Valor Mes', $this->totalStyle),
        ];

        foreach ($months as $month) {
            $total = 0;

            foreach ($services as $service) {
                $total += $resumenMensual[$service][$month] ?? 0;
            }

            $cells[] = Cell::fromValue(
                $total,
                $this->totalStyle
            );
        }

        $writer->addRow(new Row($cells));

        $percentageStyle = new Style;
        $percentageStyle->setFormat('0.00%');

        $cells = [
            Cell::fromValue('', $this->totalStyle),
            Cell::fromValue(
                '% EJECUCIÓN JSON',
                $this->totalStyle
            ),
        ];

        foreach ($months as $month) {
            $valorMes = 0;

            foreach ($services as $service) {
                $valorMes +=
                    $resumenMensual[$service][$month] ?? 0;
            }

            $porcentaje = $costoMes > 0
                ? $valorMes / $costoMes
                : 0;

            $cells[] = Cell::fromValue(
                $porcentaje,
                $percentageStyle
            );
        }

        $writer->addRow(new Row($cells));
    }

    private function createGenericRow(
        array $row,
        array $headers
    ): Row {
        $cells = [];

        foreach ($headers as $header) {
            $value = $row[$header] ?? '';

            if (
                str_contains(
                    $header,
                    'fecha'
                )
                ||
                str_contains(
                    $header,
                    'Fecha'
                )
            ) {
                $date = $this->createDate(
                    $this->stringValue($value)
                );

                if ($date !== null) {
                    $cells[] = Cell::fromValue(
                        $date,
                        $this->dateStyle
                    );

                    continue;
                }
            }

            if (
                in_array(
                    $header,
                    [
                        'CANT',
                        'Cantidad',
                        'DUPLICADOS MES AÑO',
                    ],
                    true
                )
            ) {
                if ($value !== '' && is_numeric($value)) {
                    $cells[] = Cell::fromValue(
                        (float) $value,
                        $this->numberStyle
                    );

                    continue;
                }
            }

            if (
                in_array(
                    $header,
                    [
                        'Tarifa UNITARIO',
                        'Tarifa',
                        'VALOR TOTAL',
                        'VALOR TOTAL DIVIDO TODO - llave solo mes año',
                    ],
                    true
                )
            ) {
                $cells[] = Cell::fromValue(
                    $this->numberValue($value),
                    $this->moneyStyle
                );

                continue;
            }

            $cells[] = Cell::fromValue(
                (string) $value
            );
        }

        return new Row($cells);
    }

    private function writeHeader(
        Writer $writer,
        array $headers
    ): void {
        $writer->addRow(
            Row::fromValues(
                $headers,
                $this->headerStyle
            )
        );
    }

    private function prepareStyles(): void
    {
        $this->headerStyle = new Style;
        $this->headerStyle->setFontBold();
        $this->headerStyle->setFontSize(10);
        $this->headerStyle->setFontColor('FFFFFF');
        $this->headerStyle->setBackgroundColor('4472C4');
        $this->headerStyle->setShouldWrapText();

        $this->dateStyle = new Style;
        $this->dateStyle->setFormat('dd/mm/yyyy');

        $this->moneyStyle = new Style;
        $this->moneyStyle->setFormat('#,##0.00');

        $this->numberStyle = new Style;
        $this->numberStyle->setFormat('0.00');

        $this->totalStyle = new Style;
        $this->totalStyle->setFontBold();
        $this->totalStyle->setBackgroundColor('D9EAF7');
    }

    private function configureSourceSheet($sheet): void
    {
        $view = new SheetView;
        $view->setFreezeRow(1);
        $sheet->setSheetView($view);

        for ($i = 1; $i <= count($this->sourceHeaders); $i++) {
            $sheet->setColumnWidth(18, $i);
        }
    }

    private function configureAcApSheet($sheet): void
    {
        $view = new SheetView;
        $view->setFreezeRow(1);
        $sheet->setSheetView($view);

        $widths = [
            1 => 20, 2 => 16, 3 => 18, 4 => 24, 5 => 20,
            6 => 18, 7 => 40, 8 => 10, 9 => 35, 10 => 40,
            11 => 18, 12 => 18, 13 => 18,
        ];

        foreach ($widths as $column => $width) {
            $sheet->setColumnWidth($width, $column);
        }
    }

    private function configureAmSheet($sheet): void
    {
        $view = new SheetView;
        $view->setFreezeRow(1);
        $sheet->setSheetView($view);

        $widths = [
            1 => 18, 2 => 16, 3 => 18, 4 => 20, 5 => 20,
            6 => 20, 7 => 45, 8 => 12, 9 => 18, 10 => 32,
            11 => 20, 12 => 10, 13 => 22, 14 => 18, 15 => 28,
        ];

        foreach ($widths as $column => $width) {
            $sheet->setColumnWidth($width, $column);
        }
    }

    private function configureOtrosSheet($sheet): void
    {
        $view = new SheetView;
        $view->setFreezeRow(1);
        $sheet->setSheetView($view);

        $widths = [
            1 => 20, 2 => 16, 3 => 18, 4 => 24, 5 => 20,
            6 => 20, 7 => 45, 8 => 10, 9 => 20, 10 => 38,
            11 => 25, 12 => 40, 13 => 10, 14 => 18, 15 => 18, 16 => 20,
        ];

        foreach ($widths as $column => $width) {
            $sheet->setColumnWidth($width, $column);
        }
    }

    private function configureResumenSheet($sheet): void
    {
        $view = new SheetView;
        $view->setFreezeRow(2);
        $sheet->setSheetView($view);

        $sheet->setColumnWidth(18, 1);
        $sheet->setColumnWidth(48, 2);
    }

    private function formatDateForKey(string $date): string
    {
        if ($date === '') {
            return '';
        }

        $timestamp = strtotime($date);

        return $timestamp === false
            ? $date
            : date('j/n/Y', $timestamp);
    }

    private function extractDateOnly(string $date): string
    {
        if ($date === '') {
            return '';
        }

        $timestamp = strtotime($date);

        return $timestamp === false
            ? $date
            : date('Y-m-d', $timestamp);
    }

    private function extractMonth(string $date): string
    {
        if ($date === '') {
            return '';
        }

        $timestamp = strtotime($date);

        return $timestamp === false
            ? ''
            : date('Y-m', $timestamp);
    }

    private function formatMonth(string $month): string
    {
        $months = [
            '01' => 'ENE', '02' => 'FEB', '03' => 'MAR', '04' => 'ABR',
            '05' => 'MAY', '06' => 'JUN', '07' => 'JUL', '08' => 'AGO',
            '09' => 'SEP', '10' => 'OCT', '11' => 'NOV', '12' => 'DIC',
        ];

        $parts = explode('-', $month);

        if (count($parts) !== 2) {
            return $month;
        }

        return ($months[$parts[1]] ?? $parts[1]).' '.$parts[0];
    }

    private function createDate(string $date): ?\DateTimeImmutable
    {
        if ($date === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($date);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function normalizeText(string $text): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');

        return strtr(
            $text,
            [
                'á' => 'a',
                'é' => 'e',
                'í' => 'i',
                'ó' => 'o',
                'ú' => 'u',
                'ü' => 'u',
                'ñ' => 'n',
            ]
        );
    }

    private function normalizeCode(mixed $value): string
    {
        return strtoupper(
            trim(
                $this->stringValue($value)
            )
        );
    }

    private function normalizeMedicationDescription(mixed $value): string
    {
        return preg_replace('/\s+/', ' ', strtoupper(trim($this->stringValue($value)))) ?? '';
    }

    private function stringValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value) || is_object($value)) {
            return '';
        }

        return (string) $value;
    }

    private function numberValue(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        $value = str_replace('$', '', (string) $value);
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);

        return is_numeric($value)
            ? (float) $value
            : 0;
    }
}
