<?php

namespace App\Http\Controllers;

use App\Exceptions\LibroPaquetesException;
use App\Models\CatalogoReferencia;
use App\Models\ProgramaEjecucion;
use App\Programas\LaMaria\ConciliacionRipsNota;
use App\Programas\LaMaria\CondicionArchivo;
use App\Programas\LaMaria\EstadoClinico;
use App\Programas\LaMaria\EstadoDuplicidad;
use App\Programas\LaMaria\EstadoTarifario;
use App\Programas\LaMaria\ExplicacionBrecha;
use App\Programas\LaMaria\FuenteTarifa;
use App\Programas\LaMaria\HistorialAdministrativo;
use App\Programas\LaMaria\LaMariaCervixPipeline;
use App\Programas\LaMaria\LaMariaPrograma;
use App\Programas\LaMaria\LaMariaProstataPipeline;
use App\Programas\LaMaria\LibroPaquetesEjecucion;
use App\Programas\LaMaria\ModoLibroPaquetes;
use App\Programas\LaMaria\PoliticaPaqueteHospitalario;
use App\Programas\LaMaria\ReconstructorEpisodiosHospitalarios;
use App\Programas\LaMaria\ResumenEconomico;
use App\Programas\LaMaria\RutaCalculo;
use App\Programas\LaMaria\ValorizacionAdministrativa;
use App\Services\ActivityLogger;
use App\Services\ReferenciaRutaMatcher;
use App\Services\SeguimientoRutaClassifier;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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

    private const CANTIDAD_FIELDS = ['cantidadMedicamento', 'cantidadOS', 'cantidad', 'cantDispensada', 'cantSuministrada'];

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

    private const MONTHLY_SUMMARY_HEADERS = ['MES', 'PACIENTES', 'SERVICIOS', 'VALOR TOTAL MES'];

    private const PATIENT_HEADERS = [
        'MES', 'tipoDocumentoIdentificacion', 'numDocumentoIdentificacion', 'codSexo',
        'fechaNacimiento', 'codMunicipioResidencia', 'codZonaTerritorialResidencia', 'tipoUsuario',
        'codDiagnosticoPrincipal (valores del mes)', 'numFactura (facturas del mes)',
        'PRIMERA ATENCION', 'ULTIMA ATENCION', 'CONSULTAS', 'PROCEDIMIENTOS', 'MEDICAMENTOS (LINEAS)',
        'OTROS SERVICIOS', 'URGENCIAS', 'HOSPITALIZACIONES', 'TOTAL SERVICIOS',
        'SERVICIOS SIN TARIFA APLICADA', 'COSTO ACTIVO (A + CONDICIONADO)', 'OBSERVACION',
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

    private const PACKAGE_LEDGER_HEADERS = ['LIBRO DE PAQUETES', 'VALOR', 'OBSERVACION'];

    private const HOSPITAL_CONTROL_HEADERS = [
        'CONTROL HOSPITALARIO (MES)', 'PAQUETES', 'COSTO PAQUETES', 'SERVICIOS ABSORBIDOS',
        'COSTO INDIVIDUAL EXCLUIDO', 'SERVICIOS COBRADOS POR FUERA', 'COSTO COBRADO POR FUERA',
        'SERVICIOS REGLA PENDIENTE', 'URGENCIAS COBRADAS', 'COSTO REFERENCIA URGENCIA PENDIENTE', 'OBSERVACION',
    ];

    private const HOSPITAL_EPISODE_HEADERS = ['EPISODIOS HOSPITALARIOS', 'CANTIDAD', 'OBSERVACION'];

    private const CONTROL_HEADERS = [
        'ARCHIVO', 'numFactura', 'tipoNota', 'numNota', 'USUARIOS', 'SERVICIOS',
        'SERVICIOS SIN FECHA', 'MES INICIAL', 'MES FINAL', 'ESTADO CARGUE', 'OBSERVACION',
    ];

    private const RECEPTION_HEADERS = ['RECEPCION DE ARCHIVOS', 'CANTIDAD', 'ARCHIVOS', 'OBSERVACION'];

    private const ROUTE_NAMES = ['PROSTATA' => 'PRÓSTATA', 'CERVIX' => 'CÉRVIX'];

    /** Informes administrativos históricos opcionales por carga (uno por programa). */
    private const ADMINISTRATIVE_REPORT_LIMIT = 2;

    private const PROCESSING_ERROR = 'No se pudo generar el reporte por un error interno al procesar los archivos; quedó registrado para revisión. Ningún archivo se omitió en silencio: no se generó un consolidado parcial.';

    private const ECONOMIC_STATE_HEADERS = [
        'MES', 'FACTURA', 'TIPO SERVICIO', 'CODIGO RIPS', 'CANTIDAD ORIGINAL', 'TARIFA REFERENCIA',
        'CUM RESUELTO', 'METODO RESOLUCION', 'ESTADO ECONOMICO', 'COSTO RESPALDADO', 'CONDICIONADO INCLUIDO',
        'DISPENSACION ADMINISTRATIVA EN ESCENARIO', 'ESCENARIO REFERENCIAL', 'EXTERNO POTENCIAL NO SUMADO',
        'MOTIVO', 'FUENTE TARIFA', 'GRUPO NOTA', 'FACTURACION APROBADA',
    ];

    private const CONCILIATION_HEADERS = [
        'CONCILIACION', 'SERVICIOS', 'PACIENTE-MES', 'COSTO ACTIVO (A + CONDICIONADO)', 'OBSERVACION',
    ];

    private const SQL_HEADERS = [
        'DOCUMENTO', 'RESULTADO', 'OBSERVACION',
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
            'limiteArchivos' => self::uploadLimit(),
            'resultado' => $this->sessionResult('la-maria.resultado.'.$ejecucion),
            'pacientesDemo' => session('la-maria.pacientes.'.$ejecucion, []),
            'categoriasEconomicas' => session('la-maria.categorias.'.$ejecucion, []),
            'fuentesCatalogos' => CatalogoReferencia::query()->forPrograma(LaMariaPrograma::SLUG)->active()
                ->whereIn('tipo', $pipeline->ownedCatalogTypes())->get(['id', 'tipo', 'version', 'archivo_origen', 'fecha_referencia']),
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

        $validated = $this->validateUpload($request);

        $sqlFile = $request->file('sql_archivo');

        $files = $this->uploadedFiles($validated['archivos']);
        $recepcion = $this->verifyReception($request, $validated['archivos'], $files, $seguimientoRutaClassifier);
        $historialAdministrativo = $this->administrativeHistory($validated, [$ruta]);
        $batchId = (string) Str::uuid();
        $modoPaquetes = LibroPaquetesEjecucion::modo();
        $esCorreccion = (bool) ($validated['correccion_autorizada'] ?? false);
        $libroEjecucion = null;

        if ($modoPaquetes === ModoLibroPaquetes::Persistente) {
            $libroEjecucion = app(LibroPaquetesEjecucion::class);

            try {
                $libroEjecucion->prepararClasificador($seguimientoRutaClassifier, $ruta, $esCorreccion);
            } catch (LibroPaquetesException $exception) {
                throw ValidationException::withMessages(['archivos' => $exception->getMessage()]);
            }
        }
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

        $resultadoLibro = null;

        try {
            // En modo PERSISTENTE el libro y la ejecución se confirman juntos.
            if ($libroEjecucion !== null) {
                DB::beginTransaction();
            }

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
            $resultadoLibro = $libroEjecucion?->registrar(
                $seguimientoRutaClassifier,
                $processableFiles,
                $allProfiles,
                $profiles,
                $ruta,
                $batchId,
                $esCorreccion
            );
            $patientConciliation = $seguimientoRutaClassifier->patientConciliationForFiles(
                $processableFiles,
                $allProfiles
            );
            $controlRows = iterator_to_array(
                $seguimientoRutaClassifier->controlRowsForFiles($screenedFiles),
                false
            );
            $detailColumns = $this->visibleColumns($this->detailColumns(), $evaluation['camposDiligenciados']);
            $referenciaRutaMatcher->resetUsage();

            $summarySheet = $writer->getCurrentSheet();
            $summarySheet->setName('SEGUIMIENTO MENSUAL');
            $this->configureSheet($summarySheet, [46, 27, 18, 26]);
            $writer->addRow(Row::fromValues(self::MONTHLY_SUMMARY_HEADERS, $this->headerStyle()));

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
            $resumenEconomico = new ResumenEconomico($ruta, $referenciaRutaMatcher->entradasNotaTecnica($ruta));
            $conciliacionRipsNota = new ConciliacionRipsNota((string) config('app.key'));
            $valorizacion = $this->administrativeValuation($ruta, $historialAdministrativo, $referenciaRutaMatcher);
            $valorizacionAutonoma = $this->administrativeValuation($ruta, $historialAdministrativo->sinInformeDelMes(), $referenciaRutaMatcher);
            $estadosEconomicos = [];
            $categoriasEconomicas = [];

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
                $estadoEconomico = $resumenEconomico->registrar($detail);
                $conciliacionRipsNota->registrar($detail, $estadoEconomico);
                $valorizacion->registrar($detail, $estadoEconomico);
                $valorizacionAutonoma->registrar($detail, $estadoEconomico);
                $estadosEconomicos[] = [$detail['mesAtencion'], $detail['factura'], $detail['tipoServicio'], $detail['codigo'],
                    $detail['cantidad'], $detail['tarifaReferencia'], $detail['cumResuelto'] ?? '', $detail['metodoResolucion'] ?? '',
                    $estadoEconomico['estado'], $estadoEconomico['respaldado'], $estadoEconomico['condicionadoIncluido'],
                    $estadoEconomico['referencialAdicional'], $estadoEconomico['escenarioReferencial'],
                    $estadoEconomico['condicionadoExterno'], $estadoEconomico['motivo'], $detail['fuenteTarifa'],
                    $estadoEconomico['grupoNota'], 'NO'];
                $categoria = $detail['tipoServicio'] === 'medicamentos' ? 'Medicamentos' : ($detail['grupoTecnico'] ?: $detail['tipoServicio']);
                $categoriaKey = $detail['mesAtencion'].'|'.$categoria;
                $categoriasEconomicas[$categoriaKey] ??= [$detail['mesAtencion'], $categoria, 0, 0, 0, 0];
                $categoriasEconomicas[$categoriaKey][2]++;
                $categoriasEconomicas[$categoriaKey][3] += (int) round($estadoEconomico['respaldado'] * 100);
                $categoriasEconomicas[$categoriaKey][4] += (int) round($estadoEconomico['condicionadoIncluido'] * 100);
                $categoriasEconomicas[$categoriaKey][5] += (int) round($estadoEconomico['condicionadoExterno'] * 100);

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

            foreach ($patientConciliation['noRepresentados'] as $usuario) {
                $this->writeTemporaryRow($reviewRowsStream, $this->unrepresentedPatientValues($usuario));
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

            if ($sqlFile instanceof UploadedFile) {
                $sqlSheet = $writer->addNewSheetAndMakeItCurrent();
                $sqlSheet->setName('VALIDACION SQL');
                $this->configureSheet($sqlSheet, [24, 30, 80]);
                $writer->addRow(Row::fromValues(self::SQL_HEADERS, $this->headerStyle()));
                foreach ($this->sqlValidationRows($sqlFile, $profiles, $allProfiles) as $row) {
                    $writer->addRow(Row::fromValues($row));
                }
            }

            $controlSheet = $writer->addNewSheetAndMakeItCurrent();
            $controlSheet->setName('CONTROL DE CARGUE');
            $this->configureSheet($controlSheet, [34, 18, 12, 12, 12, 12, 18, 14, 14, 16, 90]);
            $writer->addRow(Row::fromValues(self::CONTROL_HEADERS, $this->headerStyle()));

            foreach ($this->controlValues($controlRows, $evaluation['archivos']) as $control) {
                $writer->addRow(Row::fromValues($control));
            }

            $writer->addRow(Row::fromValues(['']));
            $writer->addRow(Row::fromValues(self::RECEPTION_HEADERS, $this->headerStyle()));

            foreach ($this->receptionRows($recepcion, $controlRows) as $receptionRow) {
                $writer->addRow(Row::fromValues($receptionRow));
            }

            $writer->addRow(Row::fromValues(['']));
            $writer->addRow(Row::fromValues(self::CONCILIATION_HEADERS, $this->headerStyle()));

            foreach ([
                ...$this->conciliationRows($controlRows, $allProfiles, $ruta),
                ...$this->patientConciliationRows($patientConciliation),
            ] as $conciliation) {
                $writer->addRow(Row::fromValuesWithStyles($conciliation, null, $this->moneyColumnStyles(self::CONCILIATION_HEADERS)));
            }

            $writer->addRow(Row::fromValues(['']));
            $writer->addRow(Row::fromValues(self::HOSPITAL_CONTROL_HEADERS, $this->headerStyle()));

            foreach ($this->hospitalControlRows($profiles) as $hospitalControl) {
                $writer->addRow(Row::fromValuesWithStyles($hospitalControl, null, $this->moneyColumnStyles(self::HOSPITAL_CONTROL_HEADERS)));
            }

            $writer->addRow(Row::fromValues(['']));
            $writer->addRow(Row::fromValues(self::HOSPITAL_EPISODE_HEADERS, $this->headerStyle()));

            foreach ($this->hospitalEpisodeRows($evaluation['episodios'], $profiles, $ruta) as $episodeRow) {
                $writer->addRow(Row::fromValues($episodeRow));
            }

            $writer->addRow(Row::fromValues(['']));
            $writer->addRow(Row::fromValues(self::PACKAGE_LEDGER_HEADERS, $this->headerStyle()));

            foreach (LibroPaquetesEjecucion::filasControl($resultadoLibro) as $ledgerRow) {
                $writer->addRow(Row::fromValues($ledgerRow));
            }

            $resultadoEconomico = $this->economicResult($resumenEconomico, $profiles, $sharedProfiles, $ruta);
            $conciliacionRipsNota->atribuirPaquetes($resultadoEconomico['hospitalizacion']);
            $valorizacion->atribuirPaquetes($resultadoEconomico['hospitalizacion']);
            $valorizacion->atribuirUrgencias($conciliacionRipsNota->filasEpisodiosUrgencias(), $conciliacionRipsNota->costoUrgenciasPorTipo());
            $valorizacionAutonoma->atribuirPaquetes($resultadoEconomico['hospitalizacion']);
            $estimacionAdministrativa = $this->administrativeComparison([$ruta => $valorizacion], [$ruta => $resultadoEconomico]);
            $writer->setCurrentSheet($summarySheet);
            $this->writeMonthlyAdministrativeSummary($writer, [$ruta => $valorizacion], [$ruta => $resultadoEconomico], $historialAdministrativo, [$ruta => $valorizacionAutonoma]);
            $hospitalizacionDemo = $resultadoEconomico['hospitalizacion'];
            foreach ($hospitalizacionDemo as $filaHospital) {
                if ($filaHospital[0] !== 'TOTAL') {
                    $categoriasEconomicas[$filaHospital[0].'|Paquetes hospitalarios'] = [$filaHospital[0], 'Paquetes hospitalarios', $filaHospital[1], (int) round($filaHospital[2] * 100), 0, 0];
                }
            }
            ksort($categoriasEconomicas);
            foreach ($categoriasEconomicas as &$filaCategoria) {
                foreach ([3, 4, 5] as $columnaCosto) {
                    $filaCategoria[$columnaCosto] /= 100.0;
                }
            }
            unset($filaCategoria);
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
                    'files_sha256' => array_combine(
                        array_column($files, 'fileName'),
                        array_map(static fn (array $file): string => (string) hash_file('sha256', $file['path']), $files)
                    ),
                    'modo_paquetes' => $modoPaquetes->value,
                    'correccion_autorizada' => $esCorreccion,
                    'libro_paquetes' => $resultadoLibro === null ? null : array_diff_key($resultadoLibro, ['resumen' => true]),
                    'recepcion' => array_diff_key($recepcion, ['archivos' => true]),
                    'costos_y_brechas' => $resultadoEconomico['costos'],
                    'valorizacion_administrativa' => [
                        'fuentes' => $historialAdministrativo->fuentes(),
                        'totales' => $valorizacion->totalesPorMes(),
                    ],
                ]
            );

            $ejecucion = ProgramaEjecucion::query()->create([
                'programa_slug' => $pipeline->slug(),
                'contrato_id' => null,
                'user_id' => $request->user()?->id,
                'periodo' => now()->format('Y-m'),
                'batch_id' => $batchId,
                'archivo_salida' => $fileName,
                'catalogos_aplicados' => $referenciaRutaMatcher->appliedCatalogsSnapshot(),
                'archivos_procesados' => count($files),
            ]);

            if ($libroEjecucion !== null) {
                foreach (['hospital_segmentos', 'hospital_paquete_movimientos'] as $tabla) {
                    DB::table($tabla)->where('ejecucion_origen', $batchId)->update(['programa_ejecucion_id' => $ejecucion->id]);
                }

                DB::commit();
            }
            $this->storeResultInSession($request, strtolower($ruta), [$resultadoEconomico], $recepcion, $controlRows, $estimacionAdministrativa);
            $request->session()->put('la-maria.categorias.'.strtolower($ruta), array_values($categoriasEconomicas));
            $pacientesDemo = array_values(array_map(static fn (array $profile): array => [$profile['mesAtencion'],
                'Paciente-mes '.substr(hash_hmac('sha256', $profile['profileKey'], (string) config('app.key')), 0, 12),
                $profile['estadoClinico'], $profile['serviceCount'], round($profile['costoValido'] + $profile['costoValidoConAlerta'], 2),
                $profile['serviceCounts']['medicamentos'], $profile['controlHospitalario']['paquetes']], $profiles));
            $request->session()->put('la-maria.pacientes.'.strtolower($ruta), $pacientesDemo);
        } catch (\Throwable $exception) {
            if ($libroEjecucion !== null && DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            report($exception);

            throw ValidationException::withMessages([
                'archivos' => $exception instanceof LibroPaquetesException
                    ? $exception->getMessage()
                    : self::PROCESSING_ERROR,
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

        return $this->excelDownload($outputPath, $fileName);
    }

    /**
     * Consolidado económico de Próstata y Cérvix con los mismos archivos y el
     * mismo cálculo de cada ruta. No incluye el detalle por servicio, que está
     * en el Excel de cada ruta.
     */
    public function exportConsolidated(
        Request $request,
        SeguimientoRutaClassifier $seguimientoRutaClassifier,
        ActivityLogger $activityLogger
    ): BinaryFileResponse {
        if (LibroPaquetesEjecucion::modo() === ModoLibroPaquetes::Persistente) {
            throw ValidationException::withMessages([
                'archivos' => 'Con el libro de paquetes PERSISTENTE genera el reporte de cada ruta: el consolidado se calcula por lote.',
            ]);
        }

        $validated = $this->validateUpload($request);
        $files = $this->uploadedFiles($validated['archivos']);
        $recepcion = $this->verifyReception($request, $validated['archivos'], $files, $seguimientoRutaClassifier);
        $historialAdministrativo = $this->administrativeHistory($validated, [LaMariaPrograma::RUTA_PROSTATA, LaMariaPrograma::RUTA_CERVIX]);
        $referenciaRutaMatcher = new ReferenciaRutaMatcher;
        $batchId = (string) Str::uuid();
        $fileName = 'LA_MARIA_CONSOLIDADO_'.now()->format('Ymd_His').'_'.Str::lower(Str::random(8)).'.xlsx';
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
            $screenedFiles = $seguimientoRutaClassifier->screenFiles($files);
            $processableFiles = $seguimientoRutaClassifier->processableFiles($screenedFiles);
            $allProfiles = $seguimientoRutaClassifier->profilesForFiles($processableFiles);
            $duplicateIndex = $seguimientoRutaClassifier->duplicateIndexForFiles($processableFiles);
            $evaluation = $seguimientoRutaClassifier->evaluateCostsForFiles($processableFiles, $allProfiles, $referenciaRutaMatcher, $duplicateIndex);
            $allProfiles = $evaluation['profiles'];
            $sharedProfiles = $seguimientoRutaClassifier->profilesNotAssignable($allProfiles);
            $controlRows = iterator_to_array($seguimientoRutaClassifier->controlRowsForFiles($screenedFiles), false);
            $resultados = [];
            $valorizaciones = [];
            $autonomas = [];

            foreach ([LaMariaPrograma::RUTA_PROSTATA, LaMariaPrograma::RUTA_CERVIX] as $ruta) {
                $profiles = $seguimientoRutaClassifier->profilesForExecutionRoute($allProfiles, $ruta);
                $resumenEconomico = new ResumenEconomico($ruta, $referenciaRutaMatcher->entradasNotaTecnica($ruta));
                $conciliacionRipsNota = new ConciliacionRipsNota((string) config('app.key'));
                $valorizaciones[$ruta] = $this->administrativeValuation($ruta, $historialAdministrativo, $referenciaRutaMatcher);
                $autonomas[$ruta] = $this->administrativeValuation($ruta, $historialAdministrativo->sinInformeDelMes(), $referenciaRutaMatcher);

                foreach ($seguimientoRutaClassifier->detailRowsForFiles($processableFiles, $profiles, $referenciaRutaMatcher, $ruta, $duplicateIndex) as $detail) {
                    $estadoEconomico = $resumenEconomico->registrar($detail);
                    $conciliacionRipsNota->registrar($detail, $estadoEconomico);
                    $valorizaciones[$ruta]->registrar($detail, $estadoEconomico);
                    $autonomas[$ruta]->registrar($detail, $estadoEconomico);
                }

                $resultados[$ruta] = $this->economicResult($resumenEconomico, $profiles, $sharedProfiles, $ruta);
                $valorizaciones[$ruta]->atribuirPaquetes($resultados[$ruta]['hospitalizacion']);
                $valorizaciones[$ruta]->atribuirUrgencias($conciliacionRipsNota->filasEpisodiosUrgencias(), $conciliacionRipsNota->costoUrgenciasPorTipo());
                $autonomas[$ruta]->atribuirPaquetes($resultados[$ruta]['hospitalizacion']);
            }

            $summaryHeaders = ['RUTA', ...self::MONTHLY_SUMMARY_HEADERS];
            $summarySheet = $writer->getCurrentSheet();
            $summarySheet->setName('SEGUIMIENTO MENSUAL');
            $this->configureSheet($summarySheet, [46, 27, 18, 18, 26]);
            $writer->addRow(Row::fromValues($summaryHeaders, $this->headerStyle()));

            $estimacionAdministrativa = $this->administrativeComparison($valorizaciones, $resultados);
            $this->writeMonthlyAdministrativeSummary($writer, $valorizaciones, $resultados, $historialAdministrativo, $autonomas, true);

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
            $writer->addRow(Row::fromValues(self::RECEPTION_HEADERS, $this->headerStyle()));

            foreach ($this->receptionRows($recepcion, $controlRows) as $receptionRow) {
                $writer->addRow(Row::fromValues($receptionRow));
            }

            $writer->close();
            $writerClosed = true;

            $activityLogger->log(
                'seguimiento_rutas',
                'Usuario generó el consolidado económico de La María (PROSTATA y CERVIX).',
                [
                    'batch_id' => $batchId,
                    'files' => array_column($files, 'fileName'),
                    'excel_file' => $fileName,
                    'programa' => LaMariaPrograma::SLUG,
                    'ruta' => 'CONSOLIDADO',
                    'files_sha256' => array_combine(
                        array_column($files, 'fileName'),
                        array_map(static fn (array $file): string => (string) hash_file('sha256', $file['path']), $files)
                    ),
                    'modo_paquetes' => ModoLibroPaquetes::PorLote->value,
                    'recepcion' => array_diff_key($recepcion, ['archivos' => true]),
                    'costos_y_brechas' => array_merge(...array_column(array_values($resultados), 'costos')),
                    'valorizacion_administrativa' => [
                        'fuentes' => $historialAdministrativo->fuentes(),
                        'totales' => array_map(static fn (ValorizacionAdministrativa $valorizacion): array => $valorizacion->totalesPorMes(), $valorizaciones),
                    ],
                ]
            );

            ProgramaEjecucion::query()->create([
                'programa_slug' => LaMariaPrograma::SLUG,
                'contrato_id' => null,
                'user_id' => $request->user()?->id,
                'periodo' => now()->format('Y-m'),
                'batch_id' => $batchId,
                'archivo_salida' => $fileName,
                'catalogos_aplicados' => $referenciaRutaMatcher->appliedCatalogsSnapshot(),
                'archivos_procesados' => count($files),
            ]);

            $this->storeResultInSession($request, 'consolidado', array_values($resultados), $recepcion, $controlRows, $estimacionAdministrativa);
        } catch (\Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages(['archivos' => self::PROCESSING_ERROR]);
        } finally {
            if (! $writerClosed) {
                $writer->close();
                File::delete($outputPath);
            }

            File::deleteDirectory($tempFolder);
        }

        return $this->excelDownload($outputPath, $fileName);
    }

    private function excelDownload(string $outputPath, string $fileName): BinaryFileResponse
    {
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
     * Último resultado económico de la sesión. Los resultados guardados con el
     * formato anterior se descartan para no mostrar columnas desalineadas.
     *
     * @return array<string, mixed>
     */
    public static function sessionResult(string $key): array
    {
        $result = session($key, []);

        return is_array($result) && isset($result['costos']) ? $result : [];
    }

    /**
     * Máximo de JSON por carga. PHP descarta sin aviso los archivos que superan
     * max_file_uploads; se reservan cupos para el SQL y los informes administrativos opcionales.
     */
    public static function uploadLimit(): int
    {
        $phpLimit = (int) ini_get('max_file_uploads');

        return max(1, min(25, $phpLimit > 0 ? $phpLimit - 1 - self::ADMINISTRATIVE_REPORT_LIMIT : 25));
    }

    /**
     * @return array<string, mixed>
     */
    private function validateUpload(Request $request): array
    {
        $limit = self::uploadLimit();

        return $request->validate([
            'archivos' => ['required', 'array', 'min:1', 'max:'.$limit],
            'archivos.*' => [
                'required',
                'file',
                'mimes:json,txt',
                'extensions:json,txt',
                'max:512000',
            ],
            'archivos_declarados' => ['nullable', 'json'],
            'sql_archivo' => ['nullable', 'file', 'mimes:sql,txt', 'extensions:sql,txt', 'max:102400'],
            'correccion_autorizada' => ['nullable', 'boolean'],
            'informes_administrativos' => ['nullable', 'array', 'max:'.self::ADMINISTRATIVE_REPORT_LIMIT],
            'informes_administrativos.*' => ['file', 'extensions:xlsx', 'max:102400'],
        ], [
            'archivos.required' => 'Selecciona al menos un archivo JSON.',
            'archivos.array' => 'Selecciona los archivos JSON para procesar.',
            'archivos.min' => 'Selecciona al menos un archivo JSON.',
            'archivos.max' => 'Puedes cargar hasta '.$limit.' archivos por vez.',
            'archivos.*.mimes' => 'Cada archivo debe ser un JSON válido.',
            'archivos.*.extensions' => 'Cada archivo debe tener extensión .json o .txt.',
            'archivos.*.max' => 'Cada archivo puede pesar hasta 500 MB.',
            'archivos_declarados.json' => 'La lista de archivos seleccionados no es válida.',
            'sql_archivo.file' => 'El archivo SQL no es válido.',
            'sql_archivo.max' => 'El archivo SQL puede pesar hasta 100 MB.',
            'informes_administrativos.max' => 'Puedes cargar hasta '.self::ADMINISTRATIVE_REPORT_LIMIT.' informes administrativos.',
            'informes_administrativos.*.extensions' => 'Cada informe administrativo debe ser un archivo .xlsx.',
            'informes_administrativos.*.max' => 'Cada informe administrativo puede pesar hasta 100 MB.',
        ]);
    }

    /**
     * Compara los archivos seleccionados en pantalla con los recibidos y valida
     * la estructura de cada uno. Si falta o falla alguno, no se genera un
     * consolidado parcial: se informa por nombre.
     *
     * @param  array<int, UploadedFile>  $uploadedFiles
     * @param  array<int, array{path: string, fileName: string}>  $files
     * @return array{declarados: int|null, recibidos: int, omitidos: list<string>, archivos: list<array{nombre: string, tamano: int}>}
     */
    private function verifyReception(Request $request, array $uploadedFiles, array $files, SeguimientoRutaClassifier $classifier): array
    {
        $received = array_map(static fn (UploadedFile $file): array => [
            'nombre' => $file->getClientOriginalName(),
            'tamano' => (int) $file->getSize(),
        ], array_values($uploadedFiles));
        $declared = $request->filled('archivos_declarados')
            ? json_decode((string) $request->input('archivos_declarados'), true)
            : null;
        $missing = [];

        if (is_array($declared)) {
            $pending = array_map(static fn (array $file): string => $file['nombre'].'|'.$file['tamano'], $received);

            foreach ($declared as $file) {
                $key = (string) ($file['nombre'] ?? '').'|'.(int) ($file['tamano'] ?? -1);
                $position = array_search($key, $pending, true);

                if ($position === false) {
                    $missing[] = (string) ($file['nombre'] ?? '');
                } else {
                    unset($pending[$position]);
                }
            }
        }

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'archivos' => sprintf(
                    'Se seleccionaron %d archivos pero el servidor recibió %d. No llegaron: %s. No se generó el reporte para evitar un consolidado incompleto; vuelve a cargarlos.',
                    count($declared),
                    count($received),
                    implode(', ', $missing)
                ),
            ]);
        }

        $invalid = $classifier->invalidStructureFiles($files);

        if ($invalid !== []) {
            throw ValidationException::withMessages([
                'archivos' => 'Archivos rechazados: '.implode(' ', array_map(
                    static fn (string $name, string $reason): string => $name.': '.$reason,
                    array_keys($invalid),
                    $invalid
                )).' Corrígelos o retíralos y vuelve a generar el reporte.',
            ]);
        }

        return [
            'declarados' => is_array($declared) ? count($declared) : null,
            'recibidos' => count($received),
            'omitidos' => $missing,
            'archivos' => $received,
        ];
    }

    /**
     * Bloque inicial de CONTROL DE CARGUE: archivos seleccionados, recibidos,
     * procesados, rechazados y omitidos.
     *
     * @param  array{declarados: int|null, recibidos: int, omitidos: list<string>, archivos: list<array{nombre: string, tamano: int}>}  $recepcion
     * @param  array<int, array<string, mixed>>  $controlRows
     * @return list<array<int, int|string>>
     */
    private function receptionRows(array $recepcion, array $controlRows): array
    {
        $byState = static fn (string $state): array => array_values(array_map(
            static fn (array $control): string => $control['archivoOrigen'],
            array_filter($controlRows, static fn (array $control): bool => $control['estadoCargue'] === $state)
        ));
        $excluded = $byState('EXCLUIDO');
        $review = $byState('REVISAR');
        $processed = array_values(array_map(
            static fn (array $control): string => $control['archivoOrigen'],
            array_filter($controlRows, static fn (array $control): bool => $control['estadoCargue'] !== 'EXCLUIDO')
        ));
        $services = array_sum(array_column($controlRows, 'serviceCount'));

        return [
            ['SELECCIONADOS EN PANTALLA', $recepcion['declarados'] ?? 'NO INFORMADO', '', $recepcion['declarados'] === null ? 'La carga no envió la lista de selección; se usa lo recibido.' : 'Lista enviada por la pantalla antes de subir.'],
            ['RECIBIDOS POR EL SERVIDOR', $recepcion['recibidos'], implode(', ', array_column($recepcion['archivos'], 'nombre')), 'Límite por carga: '.self::uploadLimit().' archivos JSON.'],
            ['PROCESADOS', count($processed), implode(', ', $processed), $services.' servicios RIPS en los archivos recibidos.'],
            ['PROCESADOS CON ALERTA', count($review), implode(', ', $review), 'Ver observación del archivo.'],
            ['RECHAZADOS (CONTENIDO IDENTICO)', count($excluded), implode(', ', $excluded), 'No se procesan para evitar doble conteo.'],
            ['OMITIDOS', count($recepcion['omitidos']), implode(', ', $recepcion['omitidos']), 'Seleccionados en pantalla y no recibidos.'],
        ];
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
                static fn (string $header): bool => $header === 'TARIFA' || str_starts_with($header, 'COSTO')
            )
        );
    }

    /**
     * Formato de pesos y porcentajes de las hojas económicas; el valor de la
     * celda se conserva sin redondear.
     *
     * @param  array<int, string>  $headers
     * @return array<int, Style>
     */
    private function economicColumnStyles(array $headers): array
    {
        $money = (new Style)->setFormat('#,##0.00');
        $percentage = (new Style)->setFormat('0.00%');
        $styles = [];

        foreach ($headers as $column => $header) {
            if (preg_match('/^(COBERTURA|PORCENTAJE)/', $header) === 1) {
                $styles[$column] = $percentage;
            } elseif (preg_match('/COSTO|TARIFA|CONDICIONAD|ESCENARIO|EXTERNO|BRECHA|REFERENCIA NOTA|REFERENCIA HISTORICA|PROYECTADO NOTA|DISPENSACION|ACTIVIDAD|PRESUPUESTADO|DIFERENCIA|VALOR|PRECIO|IMPORTES|ESTIMACION|COMPONENTE/', $header) === 1) {
                $styles[$column] = $money;
            }
        }

        return $styles;
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

        if ($detail['motivoNoSuma'] === 'CANTIDAD_MEDICAMENTO_NO_INFORMADA') {
            $reasons[] = 'CANTIDAD MEDICAMENTO NO INFORMADA - COSTO NO CALCULABLE, NO SUMA';
        } elseif ($detail['motivoNoSuma'] === 'CANTIDAD_INVALIDA') {
            $reasons[] = 'CANTIDAD INVALIDA - COSTO NO CALCULABLE, NO SUMA';
        }

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

        if ($detail['reglaHospitalariaPendiente'] ?? false) {
            $reasons[] = 'SERVICIO DURANTE HOSPITALIZACION - REGLA DE PAQUETE NO DEFINIDA ('
                .$detail['categoriaPaquete'].') - SE MANTIENE EL COBRO INDIVIDUAL';
        }

        $reasons = [...$reasons, ...match ($detail['estadoEpisodioHospitalario'] ?? '') {
            'URGENCIA_MENOR_24H_TARIFA_PENDIENTE' => [sprintf(
                'URGENCIA <24H - TARIFA PENDIENTE DE DEFINICION (%s); SUS SERVICIOS NO SUMAN COMO COSTO CONTRACTUAL',
                match ((int) $detail['consultasUrgenciaEpisodio']) {
                    0 => 'SIN CONSULTA DE URGENCIAS 890701/890702 EN EL EPISODIO',
                    1 => 'LA CONSULTA DE URGENCIAS NO TIENE TARIFA EN LA NOTA TECNICA DE LA RUTA',
                    default => 'VARIAS CONSULTAS DE URGENCIAS EN EL EPISODIO',
                }
            )],
            ReconstructorEpisodiosHospitalarios::ESTADO_CONTINUIDAD_PENDIENTE => [
                'CONTINUIDAD HOSPITALARIA NO RESUELTA - CORTE DE FACTURACION 08 SIN CONTINUACION EN LOS ARCHIVOS CARGADOS (SOLO SE COBRAN LOS PAQUETES DE LA DURACION CONOCIDA)',
            ],
            ReconstructorEpisodiosHospitalarios::ESTADO_CONTINUIDAD_NO_CONFIRMADA => [
                'CONTINUIDAD NO CONFIRMADA - HAY EVIDENCIA PARCIAL O VARIOS CANDIDATOS DE CONTINUACION DE UN CORTE 08 (MOTIVO: '.($detail['motivoContinuidadEpisodio'] ?? '').'); NO SE UNEN Y LA POSIBLE CONTINUACION NO GENERA PAQUETE HASTA REVISARLA',
            ],
            'HOSPITALIZACION_SIN_FECHAS_VALIDAS' => ['HOSPITALIZACION SIN FECHAS VALIDAS DE INGRESO Y EGRESO - PAQUETE NO CALCULADO'],
            default => [],
        }];

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

        $observations = array_values(array_filter(array_map(
            static fn (string $estado): ?string => $labels[$estado] ?? null,
            $profile['estadosClinicos']
        )));

        if ($profile['soloPaqueteHospitalario'] ?? false) {
            $observations[] = 'PACIENTE-MES SIN REGISTROS RIPS: SOLO CARGO DE PAQUETE HOSPITALARIO';
        }

        foreach ($profile['episodiosHospitalarios'] ?? [] as $episodio) {
            $observations[] = sprintf(
                '%s PAQUETE HOSPITALARIO BLOQUE %d DE %d%s POR $%s (INGRESO %s, INICIO DEL BLOQUE %s, FACTURA %s)%s',
                $episodio['suma'] ? 'INCLUYE' : 'NO SUMA',
                $episodio['bloque'],
                $episodio['bloques'],
                $episodio['continuidadPendiente'] ? ' CONOCIDOS' : '',
                number_format((float) $episodio['costo'], 2, '.', ','),
                $episodio['ingreso'],
                $episodio['inicioBloque'],
                $episodio['factura'],
                $episodio['suma'] ? '' : ' - '.$episodio['motivoNoSuma']
            );
        }

        return implode(' | ', $observations);
    }

    /**
     * Control hospitalario por mes de la ejecución: COSTO ACTIVO = costo de las
     * líneas del RIPS que suman + paquetes hospitalarios.
     *
     * @param  array<int, array<string, mixed>>  $profiles
     * @return array<int, array<int, mixed>>
     */
    private function hospitalControlRows(array $profiles): array
    {
        $months = [];
        $total = SeguimientoRutaClassifier::emptyHospitalControl();
        $executionCost = 0.0;

        foreach ($profiles as $profile) {
            $month = $profile['mesAtencion'] === '' ? 'SIN FECHA' : $profile['mesAtencion'];
            $months[$month] ??= SeguimientoRutaClassifier::emptyHospitalControl();
            $executionCost += $profile['costoValido'] + $profile['costoValidoConAlerta'];

            foreach ($profile['controlHospitalario'] as $field => $value) {
                $months[$month][$field] += $value;
                $total[$field] += $value;
            }
        }

        ksort($months);
        $row = static fn (string $label, array $control, string $observation): array => [
            $label,
            $control['paquetes'],
            round($control['costoPaquetes'], 2),
            $control['serviciosAbsorbidos'],
            round($control['costoIndividualExcluido'], 2),
            $control['serviciosCobradosPorFuera'],
            round($control['costoCobradoPorFuera'], 2),
            $control['serviciosReglaPendiente'],
            $control['urgenciasCobradas'],
            round($control['costoReferenciaUrgenciaPendiente'], 2),
            $observation,
        ];
        $rows = [];

        foreach ($months as $month => $control) {
            $rows[] = $row((string) $month, $control, $control['paquetesSimulados'] > 0 ? sprintf(
                'PERIODO HISTORICO NO VERIFICADO: %d paquete(s) del modelo nuevo por $%s no se cobran; requiere revisión frente al histórico.',
                $control['paquetesSimulados'],
                number_format($control['costoPaquetesSimulados'], 2, '.', ',')
            ) : '');
        }

        $rows[] = $row('TOTAL', $total, sprintf(
            'Paquete de $%s por cada bloque de hasta 600 horas (25 días) de la estancia lógica, imputado al mes en que inicia el bloque (hoja Hx). COSTO ACTIVO $%s = líneas RIPS $%s + paquetes $%s.',
            number_format(PoliticaPaqueteHospitalario::VALOR_PAQUETE, 2, '.', ','),
            number_format($executionCost, 2, '.', ','),
            number_format($executionCost - $total['costoPaquetes'], 2, '.', ','),
            number_format($total['costoPaquetes'], 2, '.', ',')
        ));

        return $rows;
    }

    /**
     * Resumen de las estancias hospitalarias lógicas de la ruta (después de unir
     * los cortes de facturación 08) y de la fuente de clasificación usada.
     *
     * @param  list<array<string, mixed>>  $episodes
     * @param  array<int, array<string, mixed>>  $profiles
     * @return array<int, array<int, int|string>>
     */
    private function hospitalEpisodeRows(array $episodes, array $profiles, string $ruta): array
    {
        $episodes = array_values(array_filter($episodes, static fn (array $episode): bool => $episode['ruta'] === $ruta));
        $count = static fn (callable $filter): int => count(array_filter($episodes, $filter));
        $sum = static fn (string $field): int => (int) array_sum(array_column($episodes, $field));
        $control = SeguimientoRutaClassifier::emptyHospitalControl();

        foreach ($profiles as $profile) {
            foreach ($profile['controlHospitalario'] as $field => $value) {
                $control[$field] += $value;
            }
        }

        $isUrgency = static fn (array $episode): bool => $episode['tipo'] === ReconstructorEpisodiosHospitalarios::TIPO_URGENCIA;

        return [
            ['REGISTROS DE HOSPITALIZACION DEL RIPS', $sum('registros'), 'Segmentos facturados.'],
            ['SEGMENTOS CON CORTE DE FACTURACION 08', $sum('cortesFacturacion'), 'El paciente continúa en el servicio; no es un alta.'],
            ['EPISODIOS LOGICOS', count($episodes), 'Estancias reales después de unir los cortes 08.'],
            ['CONTINUIDADES RESUELTAS', $sum('continuidadesResueltas'), 'Cortes 08 unidos con su continuación.'],
            ['CONTINUIDADES PENDIENTES', $count(static fn (array $episode): bool => $episode['estado'] === ReconstructorEpisodiosHospitalarios::ESTADO_CONTINUIDAD_PENDIENTE), 'Corte 08 sin continuación en los archivos cargados: solo se cobran los paquetes de la duración conocida.'],
            ['CONTINUIDADES NO CONFIRMADAS', $count(static fn (array $episode): bool => $episode['estado'] === ReconstructorEpisodiosHospitalarios::ESTADO_CONTINUIDAD_NO_CONFIRMADA && ! $episode['retenidoPorContinuidadNoConfirmada']), 'Corte 08 con evidencia parcial o varios candidatos: no se une; solo se cobran los paquetes de su duración conocida.'],
            ['ESTANCIAS RETENIDAS POR CONTINUIDAD NO CONFIRMADA', $count(static fn (array $episode): bool => $episode['retenidoPorContinuidadNoConfirmada']), 'Posibles continuaciones de un corte: no generan paquete propio hasta revisarlas.'],
            ['EPISODIOS CON PAQUETE', $count(static fn (array $episode): bool => $episode['paquetes'] > 0), ''],
            ['PAQUETES GENERADOS', $sum('paquetesSumados'), ''],
            ['URGENCIAS < 24 H CON TARIFA', $count(static fn (array $episode): bool => $isUrgency($episode) && $episode['urgenciaConTarifa']), 'Cobradas con la consulta de urgencias 890701/890702 del episodio.'],
            ['URGENCIAS < 24 H PENDIENTES DE TARIFA', $count(static fn (array $episode): bool => $isUrgency($episode) && ! $episode['urgenciaConTarifa']), 'Sin tarifa de urgencia definida: sus servicios no suman como costo contractual.'],
            ['EPISODIOS SIN FECHAS VALIDAS', $count(static fn (array $episode): bool => $episode['estado'] === ReconstructorEpisodiosHospitalarios::ESTADO_SIN_FECHAS), ''],
            ['CLASIFICADOS POR SERVICIO DE LA NOTA TECNICA', $control['clasificadosPorServicio'], 'Servicios durante hospitalización.'],
            ['CLASIFICADOS POR AGRUPADOR DE LA NOTA TECNICA', $control['clasificadosPorAgrupador'], ''],
            ['CLASIFICADOS POR CLASIFICACION OFICIAL TEMPORAL', $control['clasificadosPorFallback'], 'Mientras la nota activa no tenga la clasificación del código.'],
            ['CLASIFICADOS POR REGLA DE CODIGO O TIPO DE SERVICIO', $control['clasificadosPorCodigo'] + $control['clasificadosPorTipo'], ''],
            ['SIN CATEGORIA', $control['clasificadosSinCategoria'], 'Regla no definida; conservan el cobro individual.'],
        ];
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
     * Cruza documentos de los RIPS contra un SQL administrativo en ambos
     * sentidos, sin asumir una estructura concreta: el SQL puede contener
     * comentarios, INSERTS y tablas auxiliares. Es una conciliación: no cambia
     * rutas ni costos.
     *
     * @param  array<int, array<string, mixed>>  $profiles
     * @param  array<int, array<string, mixed>>  $allProfiles
     * @return iterable<int, array<int, string>>
     */
    private function sqlValidationRows(UploadedFile $sqlFile, array $profiles, array $allProfiles): iterable
    {
        $contents = file_get_contents($sqlFile->getRealPath());
        $contents = is_string($contents) ? $contents : '';
        $documentsInSql = [];

        preg_match_all('/(?:documento|numDocumentoIdentificacion|identificacion|cedula)\\D{0,30}(\\d{5,20})/iu', $contents, $matches);
        foreach ($matches[1] ?? [] as $document) {
            $documentsInSql[(string) $document] = true;
        }

        $documentsInExecution = [];

        foreach ($profiles as $profile) {
            $document = (string) $profile['numDocumentoIdentificacion'];

            if ($document !== '') {
                $documentsInExecution[$document] = true;
            }
        }

        $documentsInRips = [];

        foreach ($allProfiles as $profile) {
            $documentsInRips[(string) $profile['numDocumentoIdentificacion']] = true;
        }

        foreach (array_keys($documentsInExecution) as $document) {
            $found = isset($documentsInSql[(string) $document]);

            yield [
                (string) $document,
                $found ? 'EN RIPS Y EN ADMINISTRATIVO' : 'SOLO EN RIPS',
                $found
                    ? 'El paciente aparece en los RIPS de esta ejecución y en el SQL administrativo.'
                    : 'El paciente está en los RIPS de esta ejecución, pero no en el SQL administrativo.',
            ];
        }

        foreach (array_keys($documentsInSql) as $document) {
            if (! isset($documentsInRips[(string) $document])) {
                yield [
                    (string) $document,
                    'SOLO EN ADMINISTRATIVO',
                    'El documento aparece en el SQL administrativo, pero no en ningún RIPS cargado.',
                ];
            }
        }

        if ($documentsInExecution === [] && $documentsInSql === []) {
            yield ['', 'SIN DATOS', 'No se encontraron pacientes en los RIPS de esta ejecución ni documentos en el SQL.'];
        }
    }

    /**
     * Conciliación de pacientes: todo paciente del JSON debe quedar en
     * Próstata, Cérvix o NO ASIGNABLE.
     *
     * @param  array{pacientesJson: int, representados: int, porRuta: array<string, int>, noRepresentados: list<array<string, string>>}  $conciliation
     * @return array<int, array<int, mixed>>
     */
    private function patientConciliationRows(array $conciliation): array
    {
        $missing = count($conciliation['noRepresentados']);

        return [
            ['', '', '', '', ''],
            ['CONCILIACION PACIENTES', 'PACIENTES', '', '', 'OBSERVACION'],
            ['PACIENTES EN JSON', $conciliation['pacientesJson'], '', '', 'Pacientes únicos por tipo y número de documento en los archivos procesados.'],
            ['PACIENTES EN PROSTATA', $conciliation['porRuta'][RutaCalculo::Prostata->value] ?? 0, '', '', 'Un paciente puede estar en más de una ruta en meses distintos.'],
            ['PACIENTES EN CERVIX', $conciliation['porRuta'][RutaCalculo::Cervix->value] ?? 0, '', '', ''],
            ['PACIENTES NO ASIGNABLES', $conciliation['porRuta'][RutaCalculo::NoAsignable->value] ?? 0, '', '', 'Doble ruta o sexo no reconocido; aparecen en ambos Excel.'],
            ['PACIENTES REPRESENTADOS', $conciliation['representados'], '', '', ''],
            [
                'PACIENTES NO REPRESENTADOS',
                $missing,
                '',
                '',
                $missing === 0
                    ? 'Todos los pacientes del JSON quedaron representados en el resultado.'
                    : 'REVISAR: pacientes del JSON sin servicios procesables; se listan en CASOS A REVISAR.',
            ],
            [
                'DIFERENCIA PACIENTES',
                $conciliation['pacientesJson'] - $conciliation['representados'],
                '',
                '',
                'PACIENTES EN JSON - PACIENTES REPRESENTADOS. Debe ser 0.',
            ],
        ];
    }

    /**
     * @param  array<string, string>  $usuario
     * @return array<int, string>
     */
    private function unrepresentedPatientValues(array $usuario): array
    {
        return [
            $usuario['factura'],
            $usuario['tipoDocumentoIdentificacion'],
            $usuario['numDocumentoIdentificacion'],
            $usuario['codSexo'],
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            'USUARIO DEL RIPS SIN SERVICIOS PROCESABLES - NO QUEDO EN NINGUNA RUTA (ARCHIVO: '.$usuario['archivo'].')',
        ];
    }

    /**
     * Resumen mensual de la ejecución: COSTO ACTIVO solo incluye lo que suma.
     * CASOS A REVISAR cuenta las líneas de esa hoja, incluidas las compartidas
     * no asignables.
     *
     * @param  array<int, array<string, mixed>>  $profiles
     * @param  array<int, array<string, mixed>>  $sharedProfiles
     * @return array<int, array<int, float|int|string>>
     */
    /**
     * Resultado económico de una ruta: costos y brechas, explicación,
     * medicamentos, servicios sin tarifa y hospitalización. Pantalla, Excel por
     * ruta y consolidado usan esta misma salida.
     *
     * @param  array<int, array<string, mixed>>  $profiles
     * @param  array<int, array<string, mixed>>  $sharedProfiles
     * @return array{ruta: string, mensual: list<array<int, mixed>>, costos: list<array<int, mixed>>, explicacion: list<array<int, mixed>>, medicamentos: list<array<int, mixed>>, sinTarifa: list<array<int, mixed>>, hospitalizacion: list<array<int, mixed>>}
     */
    private function economicResult(ResumenEconomico $resumen, array $profiles, array $sharedProfiles, string $ruta): array
    {
        $mensual = $this->monthlySummaries($profiles, $sharedProfiles);
        $explicacion = new ExplicacionBrecha($resumen);
        $filasExplicacion = $explicacion->filas($resumen->totalesMensuales($mensual));

        return [
            'ruta' => $ruta,
            'mensual' => $mensual,
            'costos' => $resumen->filas($mensual, $ruta, $explicacion->totalesPorMes()),
            'explicacion' => $filasExplicacion,
            'medicamentos' => $resumen->filasMedicamentos(),
            'sinTarifa' => $resumen->filasSinTarifa(),
            'hospitalizacion' => $this->hospitalControlRows($profiles),
        ];
    }

    /**
     * Dos tablas breves en la primera hoja; AU se presenta atribuido y no se
     * agrega al total administrativo del motor. Debajo del total de cada mes
     * se declara su fuente: cálculo autónomo o conciliado con el informe del
     * mismo mes; en este caso también el valor autónomo y el ajuste.
     *
     * @param  array<string, ValorizacionAdministrativa>  $valorizaciones
     * @param  array<string, array<string, mixed>>  $resultados
     * @param  array<string, ValorizacionAdministrativa>  $autonomas  Las mismas líneas sin conciliación del mes.
     */
    private function writeMonthlyAdministrativeSummary(Writer $writer, array $valorizaciones, array $resultados, HistorialAdministrativo $historial,
        array $autonomas, bool $includeRoute = false): void
    {
        $money = (new Style)->setFormat('"$"#,##0.00');
        $integer = (new Style)->setFormat('#,##0');
        $quantity = (new Style)->setFormat('#,##0.####');
        $monthlyRows = [];
        $serviceBlocks = [];

        foreach ($resultados as $ruta => $resultado) {
            foreach ($resultado['mensual'] as $summary) {
                $mes = (string) $summary[0];
                $economic = $valorizaciones[$ruta]->resumenServicios($mes);
                $monthlyRows[] = $includeRoute
                    ? [$ruta, $mes, $summary[1], $summary[2], $economic['totales']['estimado']]
                    : [$mes, $summary[1], $summary[2], $economic['totales']['estimado']];
                $serviceBlocks[] = [$ruta, $mes, $economic, $valorizaciones[$ruta]->cantidadesEconomicas($mes),
                    $valorizaciones[$ruta]->consultaUrgencias890701($mes), $valorizaciones[$ruta]->pendientesApPorCausa($mes)];
            }
        }

        foreach ($monthlyRows as $row) {
            $writer->addRow(Row::fromValuesWithStyles($row, null, [$includeRoute ? 4 : 3 => $money]));
        }

        foreach ($serviceBlocks as [$ruta, $mes, $economic, $cantidades, $consultaUrgencias, $pendientesAp]) {
            $writer->addRow(Row::fromValues(['']));
            $writer->addRow(Row::fromValues([($includeRoute ? self::ROUTE_NAMES[$ruta].' · ' : '').$mes]));
            $writer->addRow(Row::fromValues(['TIPO RIPS / SERVICIO', 'REGISTROS RIPS', 'UNIDADES VALORIZADAS', 'VALOR ADMINISTRATIVO'], $this->headerStyle()));

            foreach ($economic['filas'] as $service) {
                if ($service[0] === 'TOTAL' || ! in_array($service[0], ['AC', 'AP', 'AT', 'AU', 'AM', 'AH'], true)) {
                    continue;
                }

                $tipo = (string) $service[0];
                $label = $tipo.' — '.$service[1].($tipo === 'AU' ? ' (sin cobro propio; no aditivo)' : '');
                $writer->addRow(Row::fromValuesWithStyles([$label, (int) $service[2], $cantidades[$tipo]['unidades'],
                    $tipo === 'AU' ? 0.0 : (float) $service[4]], null, [1 => $integer, 2 => $quantity, 3 => $money]));
            }

            $writer->addRow(Row::fromValuesWithStyles(['TOTAL DEL MES', $economic['totales']['registros'], '',
                $economic['totales']['estimado']], null, [1 => $integer, 3 => $money]));
            $writer->addRow(Row::fromValuesWithStyles(['COSTO A ACREDITADO (NO SUMA AL ADMINISTRATIVO)', '', '',
                $economic['totales']['costoA']], null, [3 => $money]));

            if (! $historial->cubreMes($ruta, $mes)) {
                $writer->addRow(Row::fromValues(['FUENTE DEL TOTAL', 'CÁLCULO AUTÓNOMO: RIPS, catálogos, reglas RN-01 a RN-16 y precios históricos anteriores a '.$mes.'; sin informe administrativo del mes.']));
            } else {
                $autonomo = $autonomas[$ruta]->resumenServicios($mes)['totales']['estimado'];
                $writer->addRow(Row::fromValues(['FUENTE DEL TOTAL', 'CÁLCULO CON INFORME DEL MES — CONCILIACIÓN PARCIAL: informe administrativo de '.$mes.' (RN-17); solo filas con correspondencia RIPS de paciente, fecha y código. Los importes sin respaldo RIPS no suman y pueden persistir diferencias con los totales externos.']));
                $writer->addRow(Row::fromValuesWithStyles(['VALOR AUTÓNOMO (SIN INFORME DEL MES)', $autonomo], null, [1 => $money]));
                $writer->addRow(Row::fromValuesWithStyles(['AJUSTE RESPALDADO POR LA CONCILIACIÓN DEL MES', round($economic['totales']['estimado'] - $autonomo, 2)], null, [1 => $money]));
            }

            $writer->addRow(Row::fromValues(['']));
            $writer->addRow(Row::fromValues(['DETALLE AU (NO ADITIVO)', 'REGISTROS / EPISODIOS', 'UNIDADES RECONOCIDAS', 'VALOR INFORMATIVO'], $this->headerStyle()));
            $writer->addRow(Row::fromValuesWithStyles(['EPISODIOS AU RIPS', $economic['urgencias']['episodios'], 0, 0.0], null, [1 => $integer, 2 => $integer, 3 => $money]));
            $writer->addRow(Row::fromValuesWithStyles(['CONSULTA 890701 RIPS (YA VALORADA EN AC)', $consultaUrgencias['rips'],
                $consultaUrgencias['reconocidas'], $consultaUrgencias['valorAc']], null, [1 => $integer, 2 => $integer, 3 => $money]));
            $writer->addRow(Row::fromValuesWithStyles(['CONSULTA 890701 EN INFORME DEL MES (REFERENCIA)',
                $consultaUrgencias['administrativas'] ?? '', $consultaUrgencias['administrativas'] ?? '',
                $consultaUrgencias['valorAdministrativo'] ?? ''], null, [1 => $integer, 2 => $integer, 3 => $money]));
            $writer->addRow(Row::fromValuesWithStyles(['TARIFA HISTÓRICA 890701 POR CONSULTA', '', '',
                $consultaUrgencias['tarifaHistorica'] ?? ''], null, [3 => $money]));
            $writer->addRow(Row::fromValuesWithStyles(['CONSULTAS 890701 ENLAZADAS A AU (SUBCONJUNTO DE AC)',
                $consultaUrgencias['enlazadas'], $consultaUrgencias['enlazadas'], $consultaUrgencias['valorEnlazado']], null,
                [1 => $integer, 2 => $integer, 3 => $money]));
            $writer->addRow(Row::fromValuesWithStyles(['OTROS SERVICIOS ATRIBUIDOS A AU (YA EN AC/AP/AM)', '', '',
                round($economic['urgencias']['atribuidoAdministrativo'] - $consultaUrgencias['valorEnlazado'], 2)], null, [3 => $money]));
            foreach ($consultaUrgencias['sinValor'] as $motivo => $registros) {
                $writer->addRow(Row::fromValues(['CONSULTA 890701 SIN VALOR INDEPENDIENTE', $registros, '', $motivo]));
            }
            $writer->addRow(Row::fromValues(['']));
            $writer->addRow(Row::fromValues(['ESTADO DE REGISTROS', 'RECONOCIDOS', 'DUPLICADOS', 'INCLUIDOS / CONDICIONADOS-PENDIENTES'], $this->headerStyle()));

            foreach (['AC', 'AP', 'AT', 'AU', 'AM', 'AH'] as $tipo) {
                $estado = $cantidades[$tipo];
                $writer->addRow(Row::fromValuesWithStyles([$tipo, $estado['reconocidos'], $estado['duplicados'],
                    $estado['incluidos'].' / '.$estado['pendientes']], null, [1 => $integer, 2 => $integer]));
            }

            $writer->addRow(Row::fromValues(['AH: REGISTROS RIPS CON EGRESO', $cantidades['AH']['reconocidos'],
                'EGRESOS VALORIZADOS (RN-16)', $cantidades['AH']['unidades']]));
            $writer->addRow(Row::fromValues(['AH: INTERNACIONES SOLO COMO SERVICIO AP',
                max(0, $cantidades['AH']['unidades'] - $cantidades['AH']['reconocidos']),
                'Forman parte de los egresos valorizados; no se suman otra vez como AP.', '']));
            $writer->addRow(Row::fromValues(['AH: REGISTROS INCLUIDOS', $cantidades['AH']['incluidos'],
                'Cortes 08 o estancias sin cobro independiente; el egreso se valora una sola vez.', '']));
            $writer->addRow(Row::fromValues(['AP: CONDICIONADO POR POSIBLE INCLUSIÓN EN PAQUETE AH', $pendientesAp['paquete'],
                'Informe del mes cobra aparte; nota Hx incluye. No suma sin aclaración contractual.', '']));
            $writer->addRow(Row::fromValues(['AP: SIN TARIFA / OTROS PENDIENTES', $pendientesAp['sinTarifa'],
                'Otros pendientes: '.$pendientesAp['otros'].'; no equivalen a prestaciones sin RIPS.', '']));
            $writer->addRow(Row::fromValues(['DEFINICIÓN DE UNIDADES', 'AC/AP: prestaciones reconocidas; AT: base compatible por código; '
                .'AM: líneas independientes, sin sumar cantidades de presentaciones distintas; AH: egresos; AU: cero unidades independientes.']));
        }
    }

    /**
     * Anchos de SEGUIMIENTO MENSUAL: sus indicadores y, debajo, el resumen
     * económico por servicio (ESTADO es la última columna).
     *
     * @param  array<int, string>  $headers
     * @return array<int, int>
     */
    private function summarySheetWidths(array $headers): array
    {
        $widths = [14, 20, 14, 22, 26, 22, 22, 110, 16, 22, 16];

        foreach ($this->columnWidths($headers) as $column => $width) {
            $widths[$column] = max($widths[$column] ?? 0, $width);
        }

        return $widths;
    }

    /**
     * Resumen económico por servicio bajo los indicadores de SEGUIMIENTO
     * MENSUAL, con los totales A, B y la estimación central de cada mes. Los
     * importes con metodologías distintas llevan su propio nombre y el total
     * solo suma conceptos aditivos.
     *
     * @param  array<string, ValorizacionAdministrativa>  $valorizaciones  Por ruta.
     * @param  array<string, array<string, mixed>>  $resultados  Resultado económico por ruta.
     */
    private function writeServiceSummary(Writer $writer, array $valorizaciones, array $resultados, HistorialAdministrativo $historial): void
    {
        $money = (new Style)->setFormat('#,##0.00');
        $integer = (new Style)->setFormat('#,##0');
        $percent = (new Style)->setFormat('0.00%');
        $title = (new Style)->setFontBold()->setFontSize(12);
        $pesos = static fn (float $valor): string => '$'.number_format($valor, 2, ',', '.');
        $blockHeader = static fn (string $label): array => [$label, '', '', 'VALOR', 'DESCRIPCIÓN', '', '', ''];

        foreach ($resultados as $ruta => $resultado) {
            $valorizacion = $valorizaciones[$ruta];
            $mesAnterior = '';
            $fuentes = implode(', ', array_column(array_filter(
                $historial->fuentes(),
                static fn (array $fuente): bool => $fuente['ruta'] === $ruta
            ), 'archivo'));

            foreach ($resultado['costos'] as $costos) {
                $mes = (string) ($costos[1] ?? '');

                if (in_array($mes, ['', 'TOTAL PERIODO', 'SIN FECHA'], true)) {
                    continue;
                }

                $resumen = $valorizacion->resumenServicios($mes);
                $urgencias = $resumen['urgencias'];
                $costoA = (float) $costos[3];
                $ventana = $resumen['meses'] === []
                    ? 'SIN HISTORIAL ADMINISTRATIVO PARA EL MES'
                    : 'meses '.implode(', ', $resumen['meses']).($fuentes === '' ? '' : ' de '.$fuentes);
                $enlazados = implode('/', array_keys($urgencias['porTipo'])) ?: 'los servicios enlazados';
                $control = round($resumen['totales']['costoA'] - $costoA, 2);
                $referenciaGerencial = ValorizacionAdministrativa::REFERENCIAS_GERENCIALES[$ruta];
                $pacientesUnicos = 0;

                foreach ($resultado['mensual'] as $filaMensual) {
                    if (($filaMensual[0] ?? '') === $mes) {
                        $pacientesUnicos = (int) ($filaMensual[1] ?? 0);

                        break;
                    }
                }

                $writer->addRow(Row::fromValues(['']));
                $writer->addRow(Row::fromValues(['RESUMEN ECONÓMICO POR SERVICIO · '.self::ROUTE_NAMES[$ruta].' · '.$mes], $title));
                $writer->addRow(Row::fromValues(ValorizacionAdministrativa::RESUMEN_SERVICIOS_HEADERS, $this->headerStyle()));

                foreach ($resumen['filas'] as $fila) {
                    $writer->addRow(Row::fromValuesWithStyles($fila, null, [2 => $integer, 3 => $money, 4 => $money, 5 => $money, 6 => $money, 8 => $integer, 9 => $money, 10 => $integer]));
                }

                $registrosPendientes = (int) ($resumen['filas'][array_key_last($resumen['filas'])][10] ?? 0);
                $indicadores = [
                    ['PACIENTES ÚNICOS', $pacientesUnicos, 'Pacientes distintos de esta ruta y mes, identificados por tipo y número de documento en los JSON procesados.', $integer],
                    ['COSTO A', $costoA, 'Costo respaldado por reglas acreditadas de SIRUTA: tarifa de la nota técnica sin condiciones pendientes más paquetes hospitalarios. No cambia con la estimación administrativa.'],
                    ['COSTO B', (float) $costos[5], 'Escenario referencial: COSTO A + condicionado incluido ('.$pesos((float) $costos[4]).'). No es facturación aprobada.'],
                    ['ESTIMACIÓN ADMINISTRATIVA CENTRAL', $resumen['totales']['estimado'], 'AC + AP + AT + AM + AH con precios administrativos históricos comparables ('.$ventana.'). Referencial: no es facturación ni tarifa contractual. AU no suma.'],
                    ['INDICADOR GERENCIAL FIJO', $referenciaGerencial, 'Referencia de comparación de la ruta; no es tarifa, piso, tope ni objetivo del motor económico.'],
                    ['VARIACIÓN CONTRA INDICADOR', round($resumen['totales']['estimado'] - $referenciaGerencial, 2), 'Estimación administrativa calculada menos indicador gerencial fijo. No modifica el resultado.'],
                    ['PORCENTAJE DEL INDICADOR', $referenciaGerencial > 0 ? $resumen['totales']['estimado'] / $referenciaGerencial : 0.0,
                        'Estimación administrativa central sobre el indicador gerencial fijo. Solo compara: no limita, completa ni recorta el cálculo.', $percent],
                    ['REGISTROS PENDIENTES', $registrosPendientes, 'Registros condicionados o sin valorización posible (columna PENDIENTE); no suman a la estimación central. Detalle e información faltante en la hoja PENDIENTES DE VALORIZACIÓN, que también lista los estimados referenciales que sí suman pero requieren soporte contractual.', $integer],
                    ['CONDICIONADOS NO SUMADOS', $resumen['totales']['condicionado'], 'Valores sin evidencia suficiente: radioterapia por registro, mezcla de esquemas, unidades no compatibles, medicamentos asociados a AP sin cobertura demostrada (RN-14) y precios de dispensación del mismo paciente, CUM y mes. No se suman al COSTO A ni a la estimación central.'],
                    ['COSTOS ATRIBUIDOS A URGENCIAS (YA INCLUIDOS)', $urgencias['atribuido'], 'Costo de '.$enlazados.' enlazado a '.$urgencias['episodios'].' episodios AU; ya está en el COSTO A y en la estimación. No se suma de nuevo.'],
                    ['AU ADMINISTRATIVO ATRIBUIDO (YA INCLUIDO)', $urgencias['atribuidoAdministrativo'], 'Reclasificación de componentes administrativos con código, unidad y episodio inequívocos. Ya está incluido en la estimación; no se suma de nuevo.'],
                ];

                if ((float) $costos[6] > 0) {
                    $indicadores[] = ['EXTERNOS POTENCIALES NO SUMADOS', (float) $costos[6], 'Valores administrativos fuera del COSTO A y del COSTO B. No se suman a ningún resultado.'];
                }

                $administrativoMismoMes = $historial->totalDelMes($ruta, $mes);

                if ($administrativoMismoMes !== null) {
                    $indicadores[] = ['ADMINISTRATIVO REGISTRADO EN EL MISMO MES', $administrativoMismoMes['valor'],
                        'Valor del informe administrativo de '.$mes.' ('.$administrativoMismoMes['registros'].' registros). Referencia observada, no tarifa contractual.'];
                    $indicadores[] = ['DIFERENCIA CENTRAL − ADMINISTRATIVO MISMO MES',
                        round($resumen['totales']['estimado'] - $administrativoMismoMes['valor'], 2),
                        'Variación de actividad, esquema, alcance y precios por código; detalle en DIFERENCIAS HISTÓRICAS.'];
                }

                $indicadores[] = ['PRINCIPALES CUPS DE LA VARIACIÓN', $mesAnterior !== '' ? 'TABLA PRINCIPALES CUPS AP' : 'HOJA DIFERENCIAS HISTÓRICAS',
                    $mesAnterior !== ''
                        ? 'Cambio AP de '.$mesAnterior.' a '.$mes.' por CUPS (registros, unidades y valor) en la tabla PRINCIPALES CUPS AP de este bloque. Contra el histórico administrativo, por código: hoja DIFERENCIAS HISTÓRICAS.'
                        : 'Por código, contra el histórico administrativo (efecto actividad y efecto composición, precio o estado): hoja DIFERENCIAS HISTÓRICAS. Para comparar meses entre sí, cárguelos en el mismo reporte.'];
                $indicadores[] = ['CONTROL: COSTO A POR SERVICIO − COSTO A', $control, abs($control) < 0.005
                    ? 'Concilia al centavo: la suma por servicio reproduce el COSTO A.'
                    : 'DIFERENCIA: la suma por servicio no reproduce el COSTO A; revisar antes de usar el resumen.'];

                $writer->addRow(Row::fromValues(['']));
                $writer->addRow(Row::fromValues($blockHeader('INDICADOR'), $this->headerStyle()));

                foreach ($indicadores as $indicador) {
                    $writer->addRow(Row::fromValuesWithStyles([$indicador[0], '', '', $indicador[1], $indicador[2]], null, [3 => $indicador[3] ?? $money]));
                }

                $desglose = implode(' · ', array_map(
                    static fn (string $tipo, float $valor): string => $tipo.' '.$pesos($valor),
                    array_keys($urgencias['porTipo']),
                    array_values($urgencias['porTipo'])
                ));

                $writer->addRow(Row::fromValues(['']));
                $writer->addRow(Row::fromValues($blockHeader('URGENCIAS (AU)'), $this->headerStyle()));

                foreach ([
                    ['EPISODIOS AU', $urgencias['episodios'], 'Atenciones de urgencias del mes; cada una se enlaza con los servicios del mismo caso y factura entre su ingreso y su egreso.', $integer],
                    ['COSTO ASOCIADO YA RECONOCIDO', $urgencias['atribuido'], ($desglose === '' ? 'Sin servicios enlazados' : $desglose).'. Incluido en el COSTO A y en la estimación; no se suma de nuevo.', $money],
                    ['ADMINISTRATIVO ASOCIADO YA INCLUIDO', $urgencias['atribuidoAdministrativo'], 'Importe de los componentes administrativos inequívocos. Otros componentes ambiguos permanecen en su tipo RIPS.', $money],
                    ['VALOR ADMINISTRATIVO HISTÓRICO COMPARABLE', $urgencias['referencia'] ?? 'SIN HISTORIAL', 'Mediana mensual de AU en el informe administrativo ('.$ventana.'). Referencia; no se suma.', $money],
                    ['COBRO INDEPENDIENTE DEMOSTRADO', $urgencias['cobroIndependiente'], $urgencias['cobroIndependiente'] > 0
                        ? 'Costo A propio de AU con tarifa acreditada; ya está en el COSTO A.'
                        : 'Sin tarifa contractual ni cobro AU independiente acreditado en el RIPS.', $money],
                    ['¿ES ADITIVO?', 'NO', 'La atribución AU es informativa: sus valores ya están en '.$enlazados.'.', null],
                ] as [$concepto, $valor, $descripcion, $style]) {
                    $writer->addRow(Row::fromValuesWithStyles([$concepto, '', '', $valor, $descripcion], null, $style === null ? [] : [3 => $style]));
                }

                $writer->addRow(Row::fromValues(['']));
                $writer->addRow(Row::fromValues(['VISTA GERENCIAL RECLASIFICADA · AU ADITIVO; MISMO TOTAL'], $title));
                $writer->addRow(Row::fromValues(['TIPO RIPS', 'SERVICIO', 'ORIGINAL ADMINISTRATIVO', 'TRASLADO A AU', 'GERENCIAL'], $this->headerStyle()));
                $controlGerencial = 0;

                foreach ($valorizacion->vistaGerencial($mes) as $filaGerencial) {
                    $controlGerencial += ResumenEconomico::centavos($filaGerencial['gerencial']);
                    $tipo = $filaGerencial['tipo'];
                    $writer->addRow(Row::fromValuesWithStyles([$tipo, ValorizacionAdministrativa::SERVICIOS[$tipo] ?? $tipo,
                        $filaGerencial['original'], $filaGerencial['trasladadoAu'], $filaGerencial['gerencial']], null,
                        [2 => $money, 3 => $money, 4 => $money]));
                }

                $writer->addRow(Row::fromValuesWithStyles(['TOTAL', '', $resumen['totales']['estimado'], 0,
                    $controlGerencial / 100.0], null, [2 => $money, 3 => $money, 4 => $money]));

                if ($mesAnterior !== '') {
                    $writer->addRow(Row::fromValues(['']));
                    $writer->addRow(Row::fromValues(['PRINCIPALES CUPS AP · '.$mesAnterior.' → '.$mes.' · VARIACIÓN DE ACTIVIDAD Y VALOR'], $title));
                    $writer->addRow(Row::fromValues(['CUPS', 'REGISTROS ANTERIOR', 'REGISTROS ACTUAL', 'UNIDADES ANTERIOR',
                        'UNIDADES ACTUAL', 'VALOR ANTERIOR', 'VALOR ACTUAL', 'DIFERENCIA'], $this->headerStyle()));

                    foreach ($valorizacion->principalesVariacionesCups($mesAnterior, $mes) as $variacion) {
                        $writer->addRow(Row::fromValuesWithStyles([$variacion['codigo'], $variacion['registrosAnterior'],
                            $variacion['registrosActual'], $variacion['unidadesAnterior'], $variacion['unidadesActual'],
                            $variacion['valorAnterior'], $variacion['valorActual'], $variacion['diferencia']], null,
                            [1 => $integer, 2 => $integer, 3 => $integer, 4 => $integer, 5 => $money, 6 => $money, 7 => $money]));
                    }
                }

                $mesAnterior = $mes;
            }
        }
    }

    /**
     * Historial administrativo de cada ruta: el informe cargado en el
     * formulario o, si no se cargó, el configurado para la ruta. Solo se leen
     * mes, tipo, código, descripción, diagnóstico y valor. Si una ruta queda
     * sin fuente, el reporte se detiene en lugar de salir incompleto.
     *
     * @param  array<string, mixed>  $validated
     * @param  list<string>  $rutas
     */
    private function administrativeHistory(array $validated, array $rutas): HistorialAdministrativo
    {
        $historial = new HistorialAdministrativo;
        $configuracion = (array) config('programas.la-maria.informes_administrativos', []);

        try {
            foreach ($validated['informes_administrativos'] ?? [] as $informe) {
                if ($informe instanceof UploadedFile && $informe->getRealPath() !== false) {
                    $historial->cargarArchivo($informe->getRealPath(), $informe->getClientOriginalName());
                }
            }

            foreach ($rutas as $ruta) {
                $configurado = (string) ($configuracion[$ruta] ?? '');

                if ($historial->tieneDatos($ruta) || $configurado === '') {
                    continue;
                }

                if (! is_file($configurado)) {
                    throw ValidationException::withMessages(['informes_administrativos' => sprintf(
                        'No se encontró el informe administrativo configurado para %s (%s). Revise LA_MARIA_INFORME_ADMIN_%s o cargue el informe en el formulario. No se generó un resultado incompleto.',
                        self::ROUTE_NAMES[$ruta] ?? $ruta, basename($configurado), $ruta
                    )]);
                }

                $historial->cargarArchivo($configurado, basename($configurado));
            }
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'informes_administrativos' => 'No fue posible leer el informe administrativo: debe ser un .xlsx con la hoja «inf admin» o una hoja de servicios con CUPS, FECHA_PRESTACION, GRUPO DEL SERVICIO y VALOR.',
            ]);
        }

        $faltantes = array_values(array_filter($rutas, static fn (string $ruta): bool => ! $historial->tieneDatos($ruta)));

        if ($faltantes !== [] && (bool) ($configuracion['requerido'] ?? true)) {
            $leidos = implode(', ', array_map(
                static fn (array $fuente): string => $fuente['archivo'].' ('.$fuente['ruta'].', '.$fuente['filas'].' filas)',
                $historial->fuentes()
            ));

            throw ValidationException::withMessages(['informes_administrativos' => sprintf(
                'Falta el informe administrativo histórico de %s: cárguelo en el formulario o configure %s. Sin esa fuente la estimación administrativa quedaría incompleta, por eso no se generó el reporte.%s',
                implode(' y ', array_map(static fn (string $ruta): string => self::ROUTE_NAMES[$ruta] ?? $ruta, $faltantes)),
                implode(' / ', array_map(static fn (string $ruta): string => 'LA_MARIA_INFORME_ADMIN_'.$ruta, $faltantes)),
                $leidos === '' ? '' : ' Informes leídos: '.$leidos.'.'
            )]);
        }

        return $historial;
    }

    private function administrativeValuation(string $ruta, HistorialAdministrativo $historial, ReferenciaRutaMatcher $matcher): ValorizacionAdministrativa
    {
        return new ValorizacionAdministrativa(
            $ruta,
            $historial,
            $matcher->entradasNotaTecnica($ruta),
            static fn (string $cum): array => $matcher->historialPreciosDispensacion($ruta, $cum),
        );
    }

    /**
     * Comparación administrativa para la sesión, sin agregar hojas al Excel.
     *
     * @param  array<string, ValorizacionAdministrativa>  $valorizaciones  Por ruta.
     * @param  array<string, array<string, mixed>>  $resultados  Resultado económico por ruta.
     * @return list<array<int, mixed>> Comparación por tipo RIPS con la ruta, para la pantalla.
     */
    private function administrativeComparison(array $valorizaciones, array $resultados): array
    {
        $costoAPorRuta = [];

        foreach ($resultados as $ruta => $resultado) {
            foreach ($resultado['costos'] as $costo) {
                if (isset($costo[1]) && $costo[1] !== 'TOTAL PERIODO') {
                    $costoAPorRuta[$ruta][(string) $costo[1]] = (float) $costo[3];
                }
            }
        }

        $prefijar = static fn (string $ruta, array $filas): array => array_map(static fn (array $fila): array => [$ruta, ...$fila], $filas);
        $comparacion = [];

        foreach ($valorizaciones as $ruta => $valorizacion) {
            $comparacion = [...$comparacion, ...$prefijar($ruta, $valorizacion->filasComparacion($costoAPorRuta[$ruta] ?? []))];
        }

        return $comparacion;
    }

    /**
     * @param  list<array<string, mixed>>  $resultados
     * @param  array{declarados: int|null, recibidos: int, omitidos: list<string>, archivos: list<array{nombre: string, tamano: int}>}  $recepcion
     * @param  array<int, array<string, mixed>>  $controlRows
     * @param  list<array<int, mixed>>  $estimacionAdministrativa
     */
    private function storeResultInSession(Request $request, string $clave, array $resultados, array $recepcion, array $controlRows, array $estimacionAdministrativa = []): void
    {
        $limit = static fn (array $rows, int $count): array => array_slice($rows, 0, $count);
        $request->session()->put('la-maria.resultado.'.$clave, [
            'generado' => now()->format('Y-m-d H:i'),
            'costos' => array_merge(...array_map(static fn (array $resultado): array => $resultado['costos'], $resultados)),
            'explicacion' => array_merge(...array_map(static fn (array $resultado): array => $resultado['explicacion'], $resultados)),
            'medicamentos' => array_merge(...array_map(static fn (array $resultado): array => array_map(
                static fn (array $fila): array => [$resultado['ruta'], ...$fila], $limit($resultado['medicamentos'], 25)), $resultados)),
            'sinTarifa' => array_merge(...array_map(static fn (array $resultado): array => array_map(
                static fn (array $fila): array => [$resultado['ruta'], ...$fila], $limit($resultado['sinTarifa'], 25)), $resultados)),
            'hospitalizacion' => array_merge(...array_map(static fn (array $resultado): array => array_map(
                static fn (array $fila): array => [$resultado['ruta'], ...$fila], $resultado['hospitalizacion']), $resultados)),
            'recepcion' => $this->receptionRows($recepcion, $controlRows),
            'archivos' => array_map(static fn (array $control): array => [
                $control['archivoOrigen'], $control['factura'], $control['firstMonth'], $control['lastMonth'],
                $control['userCount'], $control['serviceCount'], $control['estadoCargue'],
            ], $controlRows),
            'estimacion' => $estimacionAdministrativa,
        ]);
    }

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
