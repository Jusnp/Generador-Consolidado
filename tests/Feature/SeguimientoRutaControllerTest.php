<?php

namespace Tests\Feature;

use App\Http\Controllers\SeguimientoRutaController;
use App\Models\CatalogoReferencia;
use App\Models\CatalogoReferenciaItem;
use App\Models\User;
use App\Programas\LaMaria\ExplicacionBrecha;
use App\Programas\LaMaria\HistoricoTarifarioConsolidado;
use App\Programas\LaMaria\LaMariaPrograma;
use App\Programas\LaMaria\PoliticaPaqueteHospitalario;
use App\Programas\LaMaria\ResumenEconomico;
use App\Programas\LaMaria\ValorizacionAdministrativa;
use App\Services\SeguimientoRutaClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row as OpenSpoutRow;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SeguimientoRutaControllerTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array<string, mixed>> */
    private array $economicSnapshots = [];

    private const HOJAS_ADMINISTRATIVAS = ['TRATAMIENTOS INTEGRALES', 'MEDICAMENTOS ASOCIADOS', 'COBERTURA AM RN14', 'ESTIMACIÓN CONDICIONADA', 'REGLAS APLICADAS', 'DETALLE POR CÓDIGO', 'DIFERENCIAS HISTÓRICAS', 'PENDIENTES DE VALORIZACIÓN'];

    /** Hojas que ya no se generan: lo necesario pasó al resumen de SEGUIMIENTO MENSUAL. */
    private const HOJAS_RETIRADAS = ['COSTOS Y BRECHAS', 'EXPLICACION BRECHA', 'MEDICAMENTOS', 'SERVICIOS SIN TARIFA', 'HOSPITALIZACION', 'RESUMEN POR TIPO RIPS', 'SERVICIOS POR CODIGO', 'CATEGORIAS NOTA', 'AT Y AU', 'EPISODIOS AU', 'CONTROL DOS VISTAS', 'COSTO A ACTUAL', 'ESTIMACIÓN ADMINISTRATIVA'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['programas.la-maria.politica_dispensacion' => 'FASE_1']);
    }

    public function test_unauthenticated_user_is_redirected_to_login(): void
    {
        $this->get(route('la-maria.prostata'))
            ->assertRedirectToRoute('login');
    }

    public function test_menu_lists_la_maria_as_single_programa(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertSeeText('La María')
            ->assertDontSee('La María · Próstata')
            ->assertDontSee('La María · Cérvix')
            ->assertSee(route('programas.show', 'la-maria'));
    }

    public function test_prostata_page_lists_only_owned_catalogs_and_marks_missing_medication_note(): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();

        $this->actingAs($user)
            ->get(route('la-maria.prostata'))
            ->assertOk()
            ->assertSee('Nota técnica de próstata')
            ->assertSee('Nota técnica de medicamentos (La María)')
            ->assertDontSee('Nota técnica de cérvix')
            ->assertSee('PENDIENTE');
    }

    public function test_prostata_page_reads_catalog_status_without_loading_catalog_items(): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();

        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $this->actingAs($user)
                ->get(route('la-maria.prostata'))
                ->assertOk();

            $itemQueries = collect(DB::getQueryLog())
                ->filter(static fn (array $query): bool => str_contains(
                    strtolower($query['query']),
                    'catalogo_referencia_items'
                ));

            $this->assertCount(0, $itemQueries);
        } finally {
            DB::disableQueryLog();
        }
    }

    public function test_prostata_page_identifies_the_active_autonomous_tariff_catalogue(): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();
        CatalogoReferencia::create([
            'programa_slug' => LaMariaPrograma::SLUG,
            'tipo' => HistoricoTarifarioConsolidado::TIPO,
            'version' => 'demo-ui-2026',
            'archivo_origen' => 'historico-demo.json',
            'activo' => true,
        ]);

        $this->actingAs($user)
            ->get(route('la-maria.prostata'))
            ->assertOk()
            ->assertSeeText('Histórico tarifario autónomo:')
            ->assertSeeText('ACTIVO (demo-ui-2026)')
            ->assertSeeText('se utiliza automáticamente');
    }

    public function test_authenticated_user_can_generate_a_prostata_workbook(): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();

        $workbook = $this->exportWorkbook($user, 'prostata', [
            'junio.json' => $this->routeJson('FAC-061', 'C61X', '2026-06-10', 'consultas'),
            'julio.json' => $this->routeJson('FAC-071', 'C531', '2026-07-08', 'otrosServicios'),
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $user->id,
            'action' => 'seguimiento_rutas',
        ]);
        $this->assertNotContains('CASOS A REVISAR', $workbook->getSheetNames());
        $this->assertNotContains('TARIFAS PENDIENTES', $workbook->getSheetNames());

        $summary = $this->monthlyRowsWithInternalEconomics($workbook);
        $patient = $this->records($workbook->getSheetByName('SEGUIMIENTO PACIENTE MES'))[0];
        $detailSheet = $workbook->getSheetByName('DETALLE RIPS');
        $detail = $this->records($detailSheet)[0];
        $detailHeaders = $this->headers($detailSheet);

        $this->assertCount(1, $summary);
        $this->assertSame('2026-06', $summary[0]['MES']);
        $this->assertSame(['MES', 'PACIENTES', 'SERVICIOS', 'VALOR TOTAL MES'], $this->headers($workbook->getSheetByName('SEGUIMIENTO MENSUAL')));
        $this->assertSame(1, (int) $summary[0]['PACIENTES']);
        $this->assertSame(1, (int) $summary[0]['SERVICIOS']);
        $this->assertSame('M', $patient['codSexo']);
        $this->assertSame('1980-05-12', $patient['fechaNacimiento']);
        $this->assertSame('05001', (string) $patient['codMunicipioResidencia']);
        $this->assertSame('01', (string) $patient['codZonaTerritorialResidencia']);
        $this->assertSame('01', (string) $patient['tipoUsuario']);
        $this->assertSame('', $patient['OBSERVACION']);
        $this->assertSame('FAC-061', $detail['numFactura']);
        $this->assertSame('consultas', $detail['tipoServicio']);
        $this->assertSame('890201', (string) $detail['codigo (codConsulta / codProcedimiento / codTecnologiaSalud)']);
        $this->assertSame('2026-06-10', $detail['fecha (fechaInicioAtencion / fechaDispensAdmon / fechaSuministroTecnologia)']);
        $this->assertSame('050010608601', (string) $detail['codPrestador']);
        $this->assertSame('C61X', $detail['codDiagnosticoPrincipal']);
        $this->assertSame('Z001', $detail['codDiagnosticoRelacionado1']);
        $this->assertSame('7', (string) $detail['consecutivo (usuario)']);
        $this->assertSame('21', (string) $detail['consecutivo (servicio)']);
        $this->assertSame('SIRAS-100', $detail['registroSIRAS']);
        $this->assertSame('MIPRES-100', $detail['idMIPRES']);
        $this->assertSame('FEV-100', $detail['numFEVPagoModerador']);
        $this->assertSame('2C82', $detail['codDiagnosticoPrincipalCIE11']);
        $this->assertSame('QA00', $detail['codDiagnosticoRelacionado1CIE11']);
        $this->assertNotContains('codDiagnosticoRelacionado2CIE11', $detailHeaders);
        $this->assertSame('Consulta de referencia próstata', $detail['NOMBRE NOTA TECNICA']);
        $this->assertSame(5000.0, (float) $detail['TARIFA']);
        $this->assertSame(5000.0, (float) $detail['COSTO']);
        $this->assertSame(['NOMBRE NOTA TECNICA', 'TARIFA', 'COSTO'], array_slice($detailHeaders, -3));

        foreach (['TARIFA', 'COSTO'] as $moneyHeader) {
            $cell = $detailSheet->getCell(Coordinate::stringFromColumnIndex(array_search($moneyHeader, $detailHeaders, true) + 1).'2');
            $this->assertSame('#,##0.00', $cell->getStyle()->getNumberFormat()->getFormatCode());
            $this->assertEquals(5000, $cell->getValue());
        }

        $this->assertSame(5000.0, (float) $workbook->getSheetByName('SEGUIMIENTO MENSUAL')->getCell('D2')->getValue());

        foreach (['ID CASO', 'ORIGEN RUTA', 'ESTADO CLINICO', 'ESTADO TARIFARIO', 'FUENTE TARIFA', 'FORMA CRUCE', 'EN NOTA TECNICA', 'ESTADO DUPLICIDAD', 'CONDICION ARCHIVO', 'SUMA AL RESUMEN', 'MOTIVO NO SUMA', 'CLASE COSTO', 'ATC', 'GRUPO TECNICO', 'RUTA', 'MES ATENCION', 'ÁMBITO', 'ESTADO CRUCE', 'ARCHIVO ORIGEN'] as $internal) {
            $this->assertNotContains($internal, $detailHeaders);
        }
    }

    public function test_workbook_omits_empty_siras_and_keeps_geographic_codes_as_reported(): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();
        $payload = json_decode(
            $this->routeJson('FAC-080', 'C61X', '2026-08-10', 'consultas'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $payload['usuarios'][0]['registroSIRAS'] = null;
        $payload['usuarios'][0]['codPaisOrigen'] = '862';
        $payload['usuarios'][0]['codPaisResidencia'] = '050';

        $workbook = $this->exportWorkbook($user, 'prostata', [
            'geografia.json' => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);

        $detailSheet = $workbook->getSheetByName('DETALLE RIPS');
        $detail = $this->records($detailSheet)[0];

        $this->assertNotContains('registroSIRAS', $this->headers($detailSheet));
        $this->assertSame('862', (string) $detail['codPaisOrigen']);
        $this->assertSame('050', (string) $detail['codPaisResidencia']);
        $this->assertSame('05001', (string) $detail['codMunicipioResidencia']);
    }

    public function test_sheets_use_only_the_approved_headers(): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();
        $repeated = $this->consulta('890201', 'Z000', '2026-07-10 08:00');

        $workbook = $this->exportWorkbook($user, 'prostata', [
            'encabezados.json' => $this->rips('FAC-HDR', [
                $this->usuario('120', 'M', [
                    'consultas' => [$repeated, $this->consulta('890201', 'Z000', '2026-07-10 08:00', 2)],
                    'otrosServicios' => [$this->otroServicio('DM00114', 'Z000', '2026-07-10 09:00', 3)],
                ]),
            ]),
        ]);

        $this->assertSame(
            ['SEGUIMIENTO MENSUAL', 'SEGUIMIENTO PACIENTE MES', 'DETALLE RIPS', 'CASOS A REVISAR', 'NO ENCONTRADOS NOTA TECNICA', 'REPETICIONES Y DUPLICADOS', 'REFERENCIA TECNICA', 'CATALOGOS APLICADOS', 'CONTROL DE CARGUE'],
            $workbook->getSheetNames()
        );
        $this->assertSame(
            ['MES', 'PACIENTES', 'SERVICIOS', 'VALOR TOTAL MES'],
            $this->headers($workbook->getSheetByName('SEGUIMIENTO MENSUAL'))
        );
        $this->assertSame(
            ['MES', 'tipoDocumentoIdentificacion', 'numDocumentoIdentificacion', 'codSexo', 'fechaNacimiento', 'codMunicipioResidencia', 'codZonaTerritorialResidencia', 'tipoUsuario', 'codDiagnosticoPrincipal (valores del mes)', 'numFactura (facturas del mes)', 'PRIMERA ATENCION', 'ULTIMA ATENCION', 'CONSULTAS', 'PROCEDIMIENTOS', 'MEDICAMENTOS (LINEAS)', 'OTROS SERVICIOS', 'URGENCIAS', 'HOSPITALIZACIONES', 'TOTAL SERVICIOS', 'SERVICIOS SIN TARIFA APLICADA', 'COSTO ACTIVO (A + CONDICIONADO)', 'OBSERVACION'],
            $this->headers($workbook->getSheetByName('SEGUIMIENTO PACIENTE MES'))
        );
        $this->assertSame(
            ['numFactura', 'tipoDocumentoIdentificacion', 'numDocumentoIdentificacion', 'codSexo', 'tipoServicio', 'fecha (fechaInicioAtencion / fechaDispensAdmon / fechaSuministroTecnologia)', 'codigo (codConsulta / codProcedimiento / codTecnologiaSalud)', 'nomTecnologiaSalud', 'codDiagnosticoPrincipal', 'cantidad (cantidadMedicamento / cantidadOS)', 'consecutivo (servicio)', 'MOTIVO'],
            $this->headers($workbook->getSheetByName('CASOS A REVISAR'))
        );
        $this->assertSame(
            ['numFactura', 'tipoDocumentoIdentificacion', 'numDocumentoIdentificacion', 'tipoServicio', 'fecha (fechaInicioAtencion / fechaDispensAdmon / fechaSuministroTecnologia)', 'codigo (codConsulta / codProcedimiento / codTecnologiaSalud)', 'nomTecnologiaSalud', 'codDiagnosticoPrincipal', 'cantidad (cantidadMedicamento / cantidadOS)', 'consecutivo (servicio)', 'OBSERVACION'],
            $this->headers($workbook->getSheetByName('NO ENCONTRADOS NOTA TECNICA'))
        );
        $this->assertSame(
            ['numFactura (facturas del grupo)', 'tipoDocumentoIdentificacion', 'numDocumentoIdentificacion', 'fecha (fechaInicioAtencion / fechaDispensAdmon / fechaSuministroTecnologia)', 'tipoServicio', 'codigo (codConsulta / codProcedimiento / codTecnologiaSalud)', 'cantidad (cantidadMedicamento / cantidadOS)', 'consecutivo (servicio, consecutivos del grupo)', 'VECES', 'OBSERVACION'],
            $this->headers($workbook->getSheetByName('REPETICIONES Y DUPLICADOS'))
        );
        $this->assertSame(
            ['ARCHIVO', 'numFactura', 'tipoNota', 'numNota', 'USUARIOS', 'SERVICIOS', 'SERVICIOS SIN FECHA', 'MES INICIAL', 'MES FINAL', 'ESTADO CARGUE', 'OBSERVACION'],
            $this->headers($workbook->getSheetByName('CONTROL DE CARGUE'))
        );
    }

    public function test_monthly_sheet_has_monthly_totals_and_control_is_last_for_multiple_months(): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();

        $workbook = $this->exportWorkbook($user, 'prostata', [
            'junio.json' => $this->routeJson('FAC-JUN', 'C61X', '2026-06-10', 'consultas'),
            'julio.json' => $this->routeJson('FAC-JUL', 'C61X', '2026-07-10', 'consultas'),
        ]);

        $sheet = $workbook->getSheetByName('SEGUIMIENTO MENSUAL');
        $this->assertSame('CONTROL DE CARGUE', $workbook->getSheetNames()[array_key_last($workbook->getSheetNames())]);
        $this->assertSame(['MES', 'PACIENTES', 'SERVICIOS', 'VALOR TOTAL MES'], $this->headers($sheet));
        $this->assertSame([
            ['MES' => '2026-06', 'PACIENTES' => 1, 'SERVICIOS' => 1, 'VALOR TOTAL MES' => 5000],
            ['MES' => '2026-07', 'PACIENTES' => 1, 'SERVICIOS' => 1, 'VALOR TOTAL MES' => 5000],
        ], $this->records($sheet));
        $this->assertSame('FF4472C4', $sheet->getCell('A1')->getStyle()->getFill()->getStartColor()->getARGB());
    }

    public function test_cervix_execution_uses_cervix_route_records_and_reconciles_the_other_route(): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();

        $workbook = $this->exportWorkbook($user, 'cervix', [
            'junio.json' => $this->routeJson('FAC-061', 'C61X', '2026-06-10', 'consultas'),
            'julio.json' => $this->routeJson('FAC-071', 'C531', '2026-07-08', 'otrosServicios', 'F'),
        ]);

        $summary = $this->monthlyRowsWithInternalEconomics($workbook);
        $conciliation = $this->conciliation($workbook);

        $this->assertCount(1, $summary);
        $this->assertSame('2026-07', $summary[0]['MES']);
        $this->assertSame(8000.0, (float) $summary[0]['COSTO ACTIVO (A + CONDICIONADO)']);
        $this->assertNotContains('CASOS A REVISAR', $workbook->getSheetNames());
        $this->assertSame(1, (int) $conciliation['PROSTATA']['SERVICIOS']);
        $this->assertSame(1, (int) $conciliation['CERVIX']['SERVICIOS']);
        $this->assertSame(0, (int) $conciliation['DIFERENCIA']['SERVICIOS']);
    }

    public function test_monthly_summary_keeps_double_route_cases_out_of_the_total_cost(): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();
        $reviewPayload = json_decode(
            $this->routeJson('FAC-REV', 'C61X', '2026-07-12', 'consultas'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $reviewPayload['usuarios'][0]['numDocumentoIdentificacion'] = '200';
        $cervixService = $reviewPayload['usuarios'][0]['servicios']['consultas'][0];
        $cervixService['codDiagnosticoPrincipal'] = 'C531';
        $cervixService['codConsulta'] = '890202';
        $cervixService['consecutivo'] = 22;
        $reviewPayload['usuarios'][0]['servicios']['consultas'][] = $cervixService;

        $workbook = $this->exportWorkbook($user, 'prostata', [
            'listo.json' => $this->routeJson('FAC-LISTO', 'C61X', '2026-07-10', 'consultas'),
            'revisar.json' => json_encode($reviewPayload, JSON_THROW_ON_ERROR),
        ]);

        $summary = $this->monthlyRowsWithInternalEconomics($workbook)[0];
        $patients = $this->records($workbook->getSheetByName('SEGUIMIENTO PACIENTE MES'));
        $review = $this->records($workbook->getSheetByName('CASOS A REVISAR'));

        $this->assertSame(1, (int) $summary['PACIENTES']);
        $this->assertSame(5000.0, (float) $summary['COSTO ACTIVO (A + CONDICIONADO)']);
        $this->assertSame(2, (int) $summary['CASOS A REVISAR']);
        $this->assertCount(1, $patients);
        $this->assertSame('100', (string) $patients[0]['numDocumentoIdentificacion']);
        $this->assertCount(2, $review);
        $this->assertSame('200', (string) $review[0]['numDocumentoIdentificacion']);
        $this->assertStringContainsString('DOBLE RUTA C61 Y C53', $review[0]['MOTIVO']);
    }

    public function test_request_without_json_files_returns_a_validation_error(): void
    {
        $user = User::factory()->create();

        $this->from(route('la-maria.prostata'))
            ->actingAs($user)
            ->post(route('la-maria.prostata.export'))
            ->assertRedirectToRoute('la-maria.prostata')
            ->assertSessionHasErrors([
                'archivos' => 'Selecciona al menos un archivo JSON.',
            ]);
    }

    public function test_male_without_route_diagnosis_is_processed_in_prostata_by_sex_and_sums(): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();

        $workbook = $this->exportWorkbook($user, 'prostata', [
            'sin-diagnostico-ruta.json' => $this->routeJson('FAC-100', 'Z000', '2026-08-10', 'consultas', 'M'),
        ]);

        $summary = $this->monthlyRowsWithInternalEconomics($workbook)[0];
        $patient = $this->records($workbook->getSheetByName('SEGUIMIENTO PACIENTE MES'))[0];
        $detail = $this->records($workbook->getSheetByName('DETALLE RIPS'))[0];
        $review = $this->records($workbook->getSheetByName('CASOS A REVISAR'))[0];

        $this->assertSame('2026-08', $summary['MES']);
        $this->assertSame(1, (int) $summary['PACIENTES']);
        $this->assertSame(5000.0, (float) $summary['COSTO ACTIVO (A + CONDICIONADO)']);
        $this->assertSame(1, (int) $summary['CASOS A REVISAR']);
        $this->assertSame('SIN DIAGNOSTICO DE RUTA - ASIGNADO POR SEXO', $patient['OBSERVACION']);
        $this->assertSame(5000.0, (float) $detail['COSTO']);
        $this->assertSame('SIN DIAGNOSTICO DE RUTA - ASIGNADO A PROSTATA POR SEXO', $review['MOTIVO']);
    }

    public function test_female_without_route_diagnosis_is_processed_in_cervix_by_sex_and_sums(): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();

        $workbook = $this->exportWorkbook($user, 'cervix', [
            'sin-diagnostico-ruta.json' => $this->routeJson('FAC-101', 'N879', '2026-08-10', 'otrosServicios', 'F'),
        ]);

        $summary = $this->monthlyRowsWithInternalEconomics($workbook)[0];
        $review = $this->records($workbook->getSheetByName('CASOS A REVISAR'))[0];

        $this->assertSame(8000.0, (float) $summary['COSTO ACTIVO (A + CONDICIONADO)']);
        $this->assertSame('SIN DIAGNOSTICO DE RUTA - ASIGNADO A CERVIX POR SEXO', $review['MOTIVO']);
    }

    public function test_female_with_cervix_diagnosis_is_only_reconciled_in_prostata(): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();

        $workbook = $this->exportWorkbook($user, 'prostata', [
            'mujer-cervix.json' => $this->routeJson('FAC-200', 'C531', '2026-09-10', 'otrosServicios', 'F'),
        ]);

        $this->assertSame([], $this->records($workbook->getSheetByName('DETALLE RIPS')));
        $this->assertSame([], $this->monthlyRowsWithInternalEconomics($workbook));
        $this->assertNotContains('CASOS A REVISAR', $workbook->getSheetNames());
        $conciliation = $this->conciliation($workbook);
        $this->assertSame(1, (int) $conciliation['CERVIX']['SERVICIOS']);
        $this->assertSame(0, (int) $conciliation['DIFERENCIA']['SERVICIOS']);
    }

    public function test_sex_route_inconsistency_follows_the_diagnosis_and_sums(): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();
        $files = [
            'inconsistencias.json' => $this->rips('FAC-INC', [
                $this->usuario('300', 'F', ['consultas' => [$this->consulta('890201', 'C61X', '2026-07-10 08:00')]]),
                $this->usuario('301', 'M', ['otrosServicios' => [$this->otroServicio('OS-001', 'C531', '2026-07-11 08:00')]]),
            ]),
        ];

        $prostata = $this->exportWorkbook($user, 'prostata', $files);
        $cervix = $this->exportWorkbook($user, 'cervix', $files);

        $prostataDetail = $this->records($prostata->getSheetByName('DETALLE RIPS'));
        $cervixDetail = $this->records($cervix->getSheetByName('DETALLE RIPS'));

        $this->assertCount(1, $prostataDetail);
        $this->assertSame('300', (string) $prostataDetail[0]['numDocumentoIdentificacion']);
        $this->assertSame('F', $prostataDetail[0]['codSexo']);
        $this->assertSame(5000.0, (float) $prostataDetail[0]['TARIFA']);
        $this->assertSame(5000.0, (float) $prostataDetail[0]['COSTO']);
        $this->assertSame('INCONSISTENCIA SEXO/RUTA', $this->records($prostata->getSheetByName('CASOS A REVISAR'))[0]['MOTIVO']);
        $this->assertSame('INCONSISTENCIA SEXO/RUTA', $this->records($prostata->getSheetByName('SEGUIMIENTO PACIENTE MES'))[0]['OBSERVACION']);
        $this->assertSame(5000.0, (float) $this->monthlyRowsWithInternalEconomics($prostata)[0]['COSTO ACTIVO (A + CONDICIONADO)']);
        $this->assertCount(1, $cervixDetail);
        $this->assertSame('301', (string) $cervixDetail[0]['numDocumentoIdentificacion']);
        $this->assertSame(8000.0, (float) $cervixDetail[0]['COSTO']);
    }

    public function test_double_route_case_is_shared_in_both_workbooks_and_never_sums(): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();
        $files = [
            'doble.json' => $this->rips('FAC-DOB', [
                $this->usuario('400', 'M', [
                    'consultas' => [$this->consulta('890201', 'C61X', '2026-07-10 08:00')],
                    'otrosServicios' => [$this->otroServicio('OS-001', 'C531', '2026-07-11 08:00', 2)],
                ]),
            ]),
        ];

        $reviews = [];

        foreach (['prostata', 'cervix'] as $ejecucion) {
            $workbook = $this->exportWorkbook($user, $ejecucion, $files);
            $review = collect($this->records($workbook->getSheetByName('CASOS A REVISAR')))
                ->keyBy('codigo (codConsulta / codProcedimiento / codTecnologiaSalud)');
            $summary = $this->monthlyRowsWithInternalEconomics($workbook)[0];
            $conciliation = $this->conciliation($workbook);

            $this->assertSame([], $this->records($workbook->getSheetByName('SEGUIMIENTO PACIENTE MES')));
            $this->assertSame([], $this->records($workbook->getSheetByName('DETALLE RIPS')));
            $this->assertCount(2, $review);
            $this->assertStringContainsString('DOBLE RUTA C61 Y C53', $review['890201']['MOTIVO']);
            $this->assertStringContainsString('POTENCIAL PROSTATA $5000.00 / CERVIX $0.00', $review['890201']['MOTIVO']);
            $this->assertStringContainsString('POTENCIAL PROSTATA $0.00 / CERVIX $8000.00', $review['OS-001']['MOTIVO']);
            $this->assertSame(0.0, (float) $summary['COSTO ACTIVO (A + CONDICIONADO)']);
            $this->assertSame(2, (int) $summary['CASOS A REVISAR']);
            $this->assertSame(2, (int) $conciliation['NO ASIGNABLE - DOBLE RUTA']['SERVICIOS']);
            $this->assertSame(1, (int) $conciliation['NO ASIGNABLE - DOBLE RUTA']['PACIENTE-MES']);
            $this->assertSame(0.0, (float) $conciliation['NO ASIGNABLE - DOBLE RUTA']['COSTO ACTIVO (A + CONDICIONADO)']);
            $this->assertSame(0, (int) $conciliation['DIFERENCIA']['SERVICIOS']);
            $reviews[] = $review->all();
        }

        $this->assertSame($reviews[0], $reviews[1]);
    }

    public function test_unrecognized_sex_is_not_assignable_without_diagnosis_and_follows_the_diagnosis_with_it(): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();
        $files = [
            'sexo.json' => $this->rips('FAC-SEX', [
                $this->usuario('500', 'I', ['consultas' => [$this->consulta('890201', 'Z000', '2026-07-10 08:00')]]),
                $this->usuario('501', 'I', ['consultas' => [$this->consulta('890201', 'C61X', '2026-07-10 09:00')]]),
            ]),
        ];

        $prostata = $this->exportWorkbook($user, 'prostata', $files);
        $cervix = $this->exportWorkbook($user, 'cervix', $files);

        foreach ([$prostata, $cervix] as $workbook) {
            $review = $this->records($workbook->getSheetByName('CASOS A REVISAR'));

            $this->assertCount(1, $review);
            $this->assertSame('500', (string) $review[0]['numDocumentoIdentificacion']);
            $this->assertStringContainsString('SEXO NO RECONOCIDO', $review[0]['MOTIVO']);
            $this->assertSame(1, (int) $this->conciliation($workbook)['NO ASIGNABLE - SEXO NO RECONOCIDO']['SERVICIOS']);
        }

        $detail = $this->records($prostata->getSheetByName('DETALLE RIPS'));
        $this->assertCount(1, $detail);
        $this->assertSame('501', (string) $detail[0]['numDocumentoIdentificacion']);
        $this->assertSame(5000.0, (float) $detail[0]['COSTO']);
    }

    public function test_record_without_document_sums_and_is_not_counted_as_a_patient(): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();
        $usuario = $this->usuario('', 'M', ['consultas' => [$this->consulta('890201', 'C61X', '2026-07-10 08:00')]]);
        $usuario['tipoDocumentoIdentificacion'] = '';

        $workbook = $this->exportWorkbook($user, 'prostata', [
            'sin-documento.json' => $this->rips('FAC-SD', [$usuario]),
        ]);

        $summary = $this->monthlyRowsWithInternalEconomics($workbook)[0];
        $detail = $this->records($workbook->getSheetByName('DETALLE RIPS'))[0];

        $this->assertSame(0, (int) $summary['PACIENTES']);
        $this->assertSame(5000.0, (float) $summary['COSTO ACTIVO (A + CONDICIONADO)']);
        $this->assertSame('', (string) $detail['numDocumentoIdentificacion']);
        $this->assertSame(5000.0, (float) $detail['COSTO']);
        $this->assertSame('SIN DOCUMENTO DEL PACIENTE', $this->records($workbook->getSheetByName('CASOS A REVISAR'))[0]['MOTIVO']);
        $this->assertSame(1, $this->patientConciliation($workbook)['PACIENTES EN JSON']);
        $this->assertSame(0, $this->patientConciliation($workbook)['DIFERENCIA PACIENTES']);
    }

    public function test_record_without_attention_date_keeps_its_tariff_but_does_not_sum(): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();

        $workbook = $this->exportWorkbook($user, 'prostata', [
            'sin-fecha.json' => $this->rips('FAC-SF', [
                $this->usuario('600', 'M', ['consultas' => [$this->consulta('890201', 'C61X', null)]]),
            ]),
        ]);

        $summary = $this->monthlyRowsWithInternalEconomics($workbook)[0];
        $detail = $this->records($workbook->getSheetByName('DETALLE RIPS'))[0];

        $this->assertSame('SIN FECHA', $summary['MES']);
        $this->assertSame(0.0, (float) $summary['COSTO ACTIVO (A + CONDICIONADO)']);
        $this->assertSame(5000.0, (float) $detail['TARIFA']);
        $this->assertSame('', (string) $detail['COSTO']);
        $this->assertStringContainsString('SIN FECHA DE ATENCION - NO SUMA', $this->records($workbook->getSheetByName('CASOS A REVISAR'))[0]['MOTIVO']);
    }

    public function test_ambiguous_presentation_goes_to_review_and_unpriced_medication_to_not_found(): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();
        $this->createMedicationCatalog();

        $workbook = $this->exportWorkbook($user, 'prostata', [
            'medicamentos.json' => $this->rips('FAC-MED', [
                $this->usuario('700', 'M', [
                    'consultas' => [$this->consulta('890201', 'C61X', '2026-07-10 08:00')],
                    'medicamentos' => [
                        $this->medicamento('1A1027731000100', 'APALUTAMIDA', 120, 2),
                        $this->medicamento('19979158-2', 'SODIO CLORURO', 2, 3),
                    ],
                ]),
            ]),
        ]);

        $codigo = 'codigo (codConsulta / codProcedimiento / codTecnologiaSalud)';
        $summary = $this->monthlyRowsWithInternalEconomics($workbook)[0];
        $review = $this->records($workbook->getSheetByName('CASOS A REVISAR'));
        $notFound = collect($this->records($workbook->getSheetByName('NO ENCONTRADOS NOTA TECNICA')))->keyBy($codigo);
        $detail = collect($this->records($workbook->getSheetByName('DETALLE RIPS')))->keyBy($codigo);

        $this->assertSame(3, (int) $summary['SERVICIOS']);
        $this->assertSame(5000.0, (float) $summary['COSTO ACTIVO (A + CONDICIONADO)']);
        $this->assertSame(2, (int) $summary['SERVICIOS SIN TARIFA APLICADA']);
        $this->assertSame(1, (int) $summary['CASOS A REVISAR']);
        $this->assertCount(1, $review);
        $this->assertSame('1A1027731000100', $review[0][$codigo]);
        $this->assertStringContainsString('PRESENTACION DEL MEDICAMENTO NO DETERMINADA - NO SUMA', $review[0]['MOTIVO']);
        $this->assertStringContainsString('APALUTAMIDA 60 MG', $review[0]['MOTIVO']);
        $this->assertSame(['19979158-2'], $notFound->keys()->all());
        $this->assertSame('', (string) $notFound['19979158-2']['OBSERVACION']);
        $this->assertSame('', (string) $detail['1A1027731000100']['NOMBRE NOTA TECNICA']);
        $this->assertSame('', (string) $detail['1A1027731000100']['TARIFA']);
        $this->assertSame('', (string) $detail['1A1027731000100']['COSTO']);
        $this->assertSame(120, (int) $detail['1A1027731000100']['cantidad (cantidadMedicamento / cantidadOS)']);
    }

    public function test_not_found_sheet_lists_lines_outside_the_note_even_when_another_source_prices_them(): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();
        $this->createDispensationCatalog('710', '19979158-2', '2026-07-05', 1000);

        $workbook = $this->exportWorkbook($user, 'prostata', [
            'no-encontrados.json' => $this->rips('FAC-NE', [
                $this->usuario('710', 'M', [
                    'consultas' => [$this->consulta('890201', 'C61X', '2026-07-10 08:00')],
                    'medicamentos' => [$this->medicamento('19979158-2', 'SODIO CLORURO', 2, 2)],
                    'otrosServicios' => [$this->otroServicio('DM00114', 'C61X', '2026-07-10 09:00', 3)],
                    'urgencias' => [[
                        'codPrestador' => '050010608601',
                        'fechaInicioAtencion' => '2026-07-09 20:00',
                        'codDiagnosticoPrincipal' => 'C61X',
                        'condicionDestinoUsuarioEgreso' => '01',
                        'fechaEgreso' => '2026-07-10 02:00',
                        'consecutivo' => 4,
                    ]],
                ]),
            ]),
        ]);

        $codigo = 'codigo (codConsulta / codProcedimiento / codTecnologiaSalud)';
        $notFound = collect($this->records($workbook->getSheetByName('NO ENCONTRADOS NOTA TECNICA')))->keyBy($codigo);
        $detail = collect($this->records($workbook->getSheetByName('DETALLE RIPS')))->keyBy('tipoServicio');
        $summary = $this->monthlyRowsWithInternalEconomics($workbook)[0];

        $this->assertSame(['19979158-2', 'DM00114'], $notFound->keys()->all());
        $this->assertSame('TARIFA APLICADA POR DISPENSACION ADMINISTRATIVA', $notFound['19979158-2']['OBSERVACION']);
        $this->assertSame('', (string) $notFound['DM00114']['OBSERVACION']);
        $this->assertSame('medicamentos', $notFound['19979158-2']['tipoServicio']);
        $this->assertSame(2000.0, (float) $detail['medicamentos']['COSTO']);
        $this->assertSame('01', (string) $detail['urgencias']['condicionDestinoUsuarioEgreso']);
        $this->assertSame(4, (int) $summary['SERVICIOS']);
        $this->assertSame(7000.0, (float) $summary['COSTO ACTIVO (A + CONDICIONADO)']);
        $this->assertSame(2, (int) $summary['SERVICIOS SIN TARIFA APLICADA']);
        $this->assertNotContains('CASOS A REVISAR', $workbook->getSheetNames());
    }

    public function test_identical_file_loaded_twice_is_excluded_without_double_counting(): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();
        $content = $this->rips('FAC-DUP', [
            $this->usuario('800', 'M', ['consultas' => [$this->consulta('890201', 'C61X', '2026-07-10 08:00')]]),
        ]);

        $workbook = $this->exportWorkbook($user, 'prostata', [
            'original.json' => $content,
            'copia.json' => $content,
        ]);

        $controlSheet = $workbook->getSheetByName('CONTROL DE CARGUE');
        $control = collect($this->records($controlSheet))->keyBy('ARCHIVO');
        $conciliation = $this->conciliation($workbook);

        $this->assertNotContains('HASH SHA256', $this->headers($controlSheet));
        $this->assertSame('PROCESADO', $control['original.json']['ESTADO CARGUE']);
        $this->assertSame('EXCLUIDO', $control['copia.json']['ESTADO CARGUE']);
        $this->assertStringContainsString('ARCHIVO DUPLICADO EXCLUIDO', $control['copia.json']['OBSERVACION']);
        $this->assertStringContainsString('original.json', $control['copia.json']['OBSERVACION']);
        $this->assertCount(1, $this->records($workbook->getSheetByName('DETALLE RIPS')));
        $this->assertSame(5000.0, (float) $this->monthlyRowsWithInternalEconomics($workbook)[0]['COSTO ACTIVO (A + CONDICIONADO)']);
        $this->assertSame(2, (int) $conciliation['SERVICIOS EN ARCHIVOS CARGADOS']['SERVICIOS']);
        $this->assertSame(1, (int) $conciliation['SERVICIOS EN ARCHIVOS DUPLICADOS EXCLUIDOS']['SERVICIOS']);
        $this->assertSame(1, (int) $conciliation['TOTAL JSON PROCESADO']['SERVICIOS']);
        $this->assertSame(0, (int) $conciliation['DIFERENCIA']['SERVICIOS']);
    }

    public function test_repeated_invoice_with_different_content_is_kept_for_review_without_summing(): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();
        $first = $this->rips('FAC-REP', [
            $this->usuario('900', 'M', ['consultas' => [$this->consulta('890201', 'C61X', '2026-07-10 08:00')]]),
        ]);
        $second = $this->rips('FAC-REP', [
            $this->usuario('900', 'M', ['consultas' => [
                $this->consulta('890201', 'C61X', '2026-07-10 08:00'),
                $this->consulta('890201', 'C61X', '2026-07-20 08:00', 2),
            ]]),
        ]);

        $workbook = $this->exportWorkbook($user, 'prostata', [
            'factura-v1.json' => $first,
            'factura-v2.json' => $second,
        ]);

        $control = collect($this->records($workbook->getSheetByName('CONTROL DE CARGUE')))->keyBy('ARCHIVO');
        $detail = $this->records($workbook->getSheetByName('DETALLE RIPS'));
        $review = $this->records($workbook->getSheetByName('CASOS A REVISAR'));

        foreach (['factura-v1.json' => ['factura-v2.json', '5000.00'], 'factura-v2.json' => ['factura-v1.json', '10000.00']] as $file => [$related, $cost]) {
            $this->assertSame('REVISAR', $control[$file]['ESTADO CARGUE']);
            $this->assertSame('FAC-REP', $control[$file]['numFactura']);
            $this->assertStringContainsString('FACTURA REPETIDA CON CONTENIDO DIFERENTE', $control[$file]['OBSERVACION']);
            $this->assertStringContainsString($related, $control[$file]['OBSERVACION']);
            $this->assertStringContainsString('Servicios: 1 vs 2', $control[$file]['OBSERVACION']);
            $this->assertStringContainsString('Costo potencial de este archivo: $'.$cost, $control[$file]['OBSERVACION']);
        }

        $this->assertCount(3, $detail);

        foreach ($detail as $row) {
            $this->assertSame(5000.0, (float) $row['TARIFA']);
            $this->assertSame('', (string) $row['COSTO']);
        }

        $this->assertCount(3, $review);
        $this->assertStringContainsString('FACTURA REPETIDA CON CONTENIDO DIFERENTE (ARCHIVO: factura-v1.json) - NO SUMA', $review[0]['MOTIVO']);
        $this->assertSame(0.0, (float) $this->monthlyRowsWithInternalEconomics($workbook)[0]['COSTO ACTIVO (A + CONDICIONADO)']);
    }

    public function test_exact_record_copy_does_not_sum_and_legitimate_repetition_stays_out_of_review(): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();
        $service = $this->consulta('890201', 'C61X', '2026-07-10 08:00');

        $workbook = $this->exportWorkbook($user, 'prostata', [
            'repetidos.json' => $this->rips('FAC-RPT', [
                $this->usuario('950', 'M', ['consultas' => [
                    $service,
                    $service,
                    $this->consulta('890201', 'C61X', '2026-07-10 08:00', 2),
                ]]),
            ]),
        ]);

        $detail = $this->records($workbook->getSheetByName('DETALLE RIPS'));
        $review = $this->records($workbook->getSheetByName('CASOS A REVISAR'));
        $duplicates = $this->records($workbook->getSheetByName('REPETICIONES Y DUPLICADOS'));

        $this->assertSame(5000.0, (float) $detail[0]['COSTO']);
        $this->assertSame('', (string) $detail[1]['COSTO']);
        $this->assertSame(5000.0, (float) $detail[2]['COSTO']);
        $this->assertSame(10000.0, (float) $this->monthlyRowsWithInternalEconomics($workbook)[0]['COSTO ACTIVO (A + CONDICIONADO)']);
        $this->assertCount(1, $review);
        $this->assertSame('COPIA EXACTA DE OTRO REGISTRO - NO SUMA', $review[0]['MOTIVO']);
        $this->assertCount(1, $duplicates);
        $this->assertSame(3, (int) $duplicates[0]['VECES']);
        $this->assertSame('1, 1, 2', $duplicates[0]['consecutivo (servicio, consecutivos del grupo)']);
        $this->assertSame('COPIA EXACTA - LA SEGUNDA NO SUMA | REPETICION EN LA MISMA FACTURA - SUMA', $duplicates[0]['OBSERVACION']);
    }

    public function test_same_attention_in_two_invoices_is_possible_double_billing_and_still_sums(): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();
        $usuario = $this->usuario('960', 'M', ['consultas' => [$this->consulta('890201', 'C61X', '2026-07-10 08:00')]]);

        $workbook = $this->exportWorkbook($user, 'prostata', [
            'factura-1.json' => $this->rips('FAC-01', [$usuario]),
            'factura-2.json' => $this->rips('FAC-02', [$usuario]),
        ]);

        $detail = $this->records($workbook->getSheetByName('DETALLE RIPS'));
        $review = $this->records($workbook->getSheetByName('CASOS A REVISAR'));
        $duplicates = $this->records($workbook->getSheetByName('REPETICIONES Y DUPLICADOS'));

        $this->assertCount(2, $detail);
        $this->assertSame(5000.0, (float) $detail[1]['COSTO']);
        $this->assertSame(10000.0, (float) $this->monthlyRowsWithInternalEconomics($workbook)[0]['COSTO ACTIVO (A + CONDICIONADO)']);
        $this->assertCount(2, $review);
        $this->assertSame('POSIBLE DOBLE FACTURACION', $review[0]['MOTIVO']);
        $this->assertSame('POSIBLE DOBLE FACTURACION - SUMA', $duplicates[0]['OBSERVACION']);
        $this->assertSame('FAC-01, FAC-02', $duplicates[0]['numFactura (facturas del grupo)']);
    }

    public function test_conciliation_never_loses_or_double_counts_services_between_executions(): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();
        $sinDocumento = $this->usuario('', 'M', ['consultas' => [$this->consulta('890201', 'C61X', '2026-07-12 08:00')]]);
        $sinDocumento['tipoDocumentoIdentificacion'] = '';
        $principal = $this->rips('FAC-A', [
            $this->usuario('100', 'M', ['consultas' => [$this->consulta('890201', 'C61X', '2026-07-10 08:00')]]),
            $this->usuario('101', 'F', ['otrosServicios' => [$this->otroServicio('OS-001', 'C531', '2026-07-10 09:00')]]),
            $this->usuario('102', 'M', ['consultas' => [$this->consulta('890201', 'Z000', '2026-07-10 10:00')]]),
            $this->usuario('103', 'F', ['otrosServicios' => [$this->otroServicio('OS-001', 'N879', '2026-07-10 11:00')]]),
            $this->usuario('104', 'M', [
                'consultas' => [$this->consulta('890201', 'C61X', '2026-07-10 12:00')],
                'otrosServicios' => [$this->otroServicio('OS-001', 'C531', '2026-07-10 13:00', 2)],
            ]),
            $this->usuario('105', 'I', ['consultas' => [$this->consulta('890201', 'Z000', '2026-07-10 14:00')]]),
            $sinDocumento,
            $this->usuario('107', 'M', ['consultas' => [$this->consulta('890201', 'C61X', null)]]),
        ]);
        $files = [
            'principal.json' => $principal,
            'principal-copia.json' => $principal,
            'repetida-v1.json' => $this->rips('FAC-R', [
                $this->usuario('108', 'M', ['consultas' => [$this->consulta('890201', 'C61X', '2026-07-15 08:00')]]),
            ]),
            'repetida-v2.json' => $this->rips('FAC-R', [
                $this->usuario('109', 'M', ['consultas' => [$this->consulta('890201', 'C61X', '2026-07-16 08:00')]]),
            ]),
            'otra-factura.json' => $this->rips('FAC-B', [
                $this->usuario('100', 'M', ['consultas' => [$this->consulta('890201', 'C61X', '2026-07-10 08:00')]]),
            ]),
        ];

        $prostata = $this->exportWorkbook($user, 'prostata', $files);
        $cervix = $this->exportWorkbook($user, 'cervix', $files);

        foreach ([$prostata, $cervix] as $workbook) {
            $conciliation = $this->conciliation($workbook);

            $this->assertSame(21, (int) $conciliation['SERVICIOS EN ARCHIVOS CARGADOS']['SERVICIOS']);
            $this->assertSame(9, (int) $conciliation['SERVICIOS EN ARCHIVOS DUPLICADOS EXCLUIDOS']['SERVICIOS']);
            $this->assertSame(12, (int) $conciliation['TOTAL JSON PROCESADO']['SERVICIOS']);
            $this->assertSame(7, (int) $conciliation['PROSTATA']['SERVICIOS']);
            $this->assertSame(2, (int) $conciliation['CERVIX']['SERVICIOS']);
            $this->assertSame(2, (int) $conciliation['NO ASIGNABLE - DOBLE RUTA']['SERVICIOS']);
            $this->assertSame(1, (int) $conciliation['NO ASIGNABLE - SEXO NO RECONOCIDO']['SERVICIOS']);
            $this->assertSame(0, (int) $conciliation['DIFERENCIA']['SERVICIOS']);
            $this->assertSame(20000.0, (float) $conciliation['PROSTATA']['COSTO ACTIVO (A + CONDICIONADO)']);
            $this->assertSame(16000.0, (float) $conciliation['CERVIX']['COSTO ACTIVO (A + CONDICIONADO)']);
        }

        $prostataDetail = $this->records($prostata->getSheetByName('DETALLE RIPS'));

        $this->assertSame(20000.0, $this->summaryTotal($prostata, 'COSTO ACTIVO (A + CONDICIONADO)'));
        $this->assertSame(16000.0, $this->summaryTotal($cervix, 'COSTO ACTIVO (A + CONDICIONADO)'));
        $this->assertSame(20000.0, $this->detailCost($prostata));
        $this->assertSame(16000.0, $this->detailCost($cervix));
        $this->assertCount(7, $prostataDetail);
        $this->assertCount(3, array_filter(
            $prostataDetail,
            static fn (array $row): bool => (float) $row['TARIFA'] > 0 && (string) $row['COSTO'] === ''
        ));
        $this->assertCount(2, $this->records($cervix->getSheetByName('DETALLE RIPS')));
    }

    /**
     * @return array<string, array{int}>
     */
    public static function dispensedQuantities(): array
    {
        return [
            'una unidad' => [1],
            'seis unidades' => [6],
            'catorce unidades' => [14],
            'sesenta unidades' => [60],
            'setenta y cinco unidades' => [75],
            'ciento ochenta unidades' => [180],
        ];
    }

    #[DataProvider('dispensedQuantities')]
    public function test_medication_cost_is_the_tariff_times_the_dispensed_quantity(int $cantidad): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();
        $this->createDispensationCatalog('720', '19935303-4', '2026-07-05', 4400);
        $medicamento = $this->medicamento('19935303-4', 'PARACETAMOL 500 MG TABLETA', $cantidad, 2);
        $medicamento['unidadMinDispensa'] = 10;
        $medicamento['unidadMedida'] = 247;

        $workbook = $this->exportWorkbook($user, 'prostata', [
            'cantidades.json' => $this->rips('FAC-CANT', [$this->usuario('720', 'M', [
                'consultas' => [$this->consulta('890201', 'C61X', '2026-07-10 08:00')],
                'medicamentos' => [$medicamento],
            ])]),
        ]);

        $detail = collect($this->records($workbook->getSheetByName('DETALLE RIPS')))->keyBy('tipoServicio');
        $patient = $this->records($workbook->getSheetByName('SEGUIMIENTO PACIENTE MES'))[0];

        $this->assertSame(4400.0, (float) $detail['medicamentos']['TARIFA']);
        $this->assertSame($cantidad, (int) $detail['medicamentos']['cantidad (cantidadMedicamento / cantidadOS)']);
        $this->assertSame(4400.0 * $cantidad, (float) $detail['medicamentos']['COSTO']);
        $this->assertSame(1, (int) $patient['MEDICAMENTOS (LINEAS)']);
        $this->assertSame(5000.0 + 4400.0 * $cantidad, (float) $patient['COSTO ACTIVO (A + CONDICIONADO)']);
    }

    public function test_medication_without_quantity_is_reported_for_review_without_cost(): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();
        $this->createDispensationCatalog('720', '19935303-4', '2026-07-05', 4400);
        $medication = $this->medicamento('19935303-4', 'PARACETAMOL 500 MG TABLETA', 1, 2);
        unset($medication['cantidadMedicamento']);
        $workbook = $this->exportWorkbook($user, 'prostata', [
            'cantidad-ausente.json' => $this->rips('FAC-QTY-MISSING', [$this->usuario('720', 'M', [
                'medicamentos' => [$medication],
            ])]),
        ]);
        $detail = $this->records($workbook->getSheetByName('DETALLE RIPS'))[0];

        $this->assertSame('', $detail['cantidad (cantidadMedicamento / cantidadOS)']);
        $this->assertSame('', $detail['COSTO']);
        $review = $this->records($workbook->getSheetByName('CASOS A REVISAR'))[0];
        $this->assertStringContainsString('CANTIDAD MEDICAMENTO NO INFORMADA', $review['MOTIVO']);
    }

    public static function originalServiceQuantities(): array
    {
        return [
            'consulta sin cantidad' => ['consultas', [], ''],
            'procedimiento con cantidad' => ['procedimientos', ['cantidad' => 3], 3],
            'procedimiento sin cantidad' => ['procedimientos', [], ''],
            'cantidad cero' => ['procedimientos', ['cantidad' => 0], 0],
            'cantidad decimal dispensada' => ['procedimientos', ['cantDispensada' => 1.5], 1.5],
            'cantidad suministrada' => ['procedimientos', ['cantSuministrada' => 2], 2],
            'cantidad vacia' => ['procedimientos', ['cantidad' => ''], ''],
        ];
    }

    #[DataProvider('originalServiceQuantities')]
    public function test_export_preserves_original_service_quantity_without_inventing_missing_values(
        string $serviceType,
        array $quantityFields,
        int|float|string $expectedQuantity
    ): void {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();
        $rips = json_decode($this->routeJson('FAC-QTY', 'C61X', '2026-07-10', $serviceType), true, 512, JSON_THROW_ON_ERROR);
        $rips['usuarios'][0]['servicios'][$serviceType][0] = array_merge(
            $rips['usuarios'][0]['servicios'][$serviceType][0],
            $quantityFields
        );
        $workbook = $this->exportWorkbook($user, 'prostata', [
            'cantidad-original.json' => json_encode($rips, JSON_THROW_ON_ERROR),
        ]);
        $record = $this->records($workbook->getSheetByName('DETALLE RIPS'))[0];

        $this->assertSame($expectedQuantity, $record['cantidad (cantidadMedicamento / cantidadOS)']);
    }

    public function test_medication_column_counts_lines_while_cost_uses_each_quantity(): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();
        $this->createDispensationCatalog('730', 'CEFAZOLINA-1', '2026-07-05', 1000);
        $this->createDispensationCatalog('730', 'OMEPRAZOL-1', '2026-07-05', 200);
        $this->createDispensationCatalog('730', 'PARACETAMOL-1', '2026-07-05', 4400);

        $workbook = $this->exportWorkbook($user, 'prostata', [
            'tres-medicamentos.json' => $this->rips('FAC-3MED', [$this->usuario('730', 'M', [
                'medicamentos' => [
                    $this->medicamento('CEFAZOLINA-1', 'CEFAZOLINA 1 G', 6, 1),
                    $this->medicamento('OMEPRAZOL-1', 'OMEPRAZOL 40 MG', 14, 2),
                    $this->medicamento('PARACETAMOL-1', 'PARACETAMOL 500 MG', 180, 3),
                ],
            ])]),
        ]);

        $patient = $this->records($workbook->getSheetByName('SEGUIMIENTO PACIENTE MES'))[0];

        $this->assertSame(3, (int) $patient['MEDICAMENTOS (LINEAS)']);
        $this->assertSame(6000.0 + 2800.0 + 792000.0, (float) $patient['COSTO ACTIVO (A + CONDICIONADO)']);
        $this->assertSame(800800.0, $this->detailCost($workbook));
    }

    public function test_every_json_patient_is_represented_in_the_patient_conciliation(): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();

        $workbook = $this->exportWorkbook($user, 'prostata', [
            'pacientes.json' => $this->rips('FAC-PAC', [
                $this->usuario('800', 'M', ['consultas' => [$this->consulta('890201', 'C61X', '2026-07-10 08:00')]]),
                $this->usuario('801', 'F', ['otrosServicios' => [$this->otroServicio('OS-001', 'C539', '2026-07-11 08:00')]]),
            ]),
        ]);

        $patients = $this->patientConciliation($workbook);

        $this->assertSame(2, $patients['PACIENTES EN JSON']);
        $this->assertSame(1, $patients['PACIENTES EN PROSTATA']);
        $this->assertSame(1, $patients['PACIENTES EN CERVIX']);
        $this->assertSame(2, $patients['PACIENTES REPRESENTADOS']);
        $this->assertSame(0, $patients['PACIENTES NO REPRESENTADOS']);
        $this->assertSame(0, $patients['DIFERENCIA PACIENTES']);
        $this->assertNotContains('CASOS A REVISAR', $workbook->getSheetNames());
    }

    public function test_json_patient_without_services_is_reported_and_never_lost(): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();

        $workbook = $this->exportWorkbook($user, 'prostata', [
            'sin-servicios.json' => $this->rips('FAC-SS', [
                $this->usuario('810', 'M', ['consultas' => [$this->consulta('890201', 'C61X', '2026-07-10 08:00')]]),
                $this->usuario('811', 'M', []),
            ]),
        ]);

        $patients = $this->patientConciliation($workbook);
        $review = $this->records($workbook->getSheetByName('CASOS A REVISAR'));

        $this->assertSame(2, $patients['PACIENTES EN JSON']);
        $this->assertSame(1, $patients['PACIENTES REPRESENTADOS']);
        $this->assertSame(1, $patients['PACIENTES NO REPRESENTADOS']);
        $this->assertSame(1, $patients['DIFERENCIA PACIENTES']);
        $this->assertCount(1, $review);
        $this->assertSame('811', (string) $review[0]['numDocumentoIdentificacion']);
        $this->assertSame('FAC-SS', $review[0]['numFactura']);
        $this->assertStringContainsString('USUARIO DEL RIPS SIN SERVICIOS PROCESABLES', $review[0]['MOTIVO']);
        $this->assertSame(5000.0, $this->summaryTotal($workbook, 'COSTO ACTIVO (A + CONDICIONADO)'));
    }

    public function test_professional_document_in_a_service_is_not_counted_as_a_patient(): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();
        $consulta = $this->consulta('890201', 'C61X', '2026-07-10 08:00');
        $consulta['tipoDocumentoIdentificacion'] = 'CC';
        $consulta['numDocumentoIdentificacion'] = '1017273218';

        $workbook = $this->exportWorkbook($user, 'prostata', [
            'profesional.json' => $this->rips('FAC-PRO', [
                $this->usuario('820', 'M', ['consultas' => [$consulta]]),
            ]),
        ]);

        $patients = $this->patientConciliation($workbook);
        $patientRows = $this->records($workbook->getSheetByName('SEGUIMIENTO PACIENTE MES'));

        $this->assertSame(1, $patients['PACIENTES EN JSON']);
        $this->assertSame(0, $patients['DIFERENCIA PACIENTES']);
        $this->assertSame(['820'], array_map(static fn (array $row): string => (string) $row['numDocumentoIdentificacion'], $patientRows));
    }

    public function test_sql_validation_reports_both_directions_without_changing_costs(): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();
        $files = [
            'sql.json' => $this->rips('FAC-SQL', [
                $this->usuario('83001', 'M', ['consultas' => [$this->consulta('890201', 'C61X', '2026-07-10 08:00')]]),
                $this->usuario('83002', 'M', ['consultas' => [$this->consulta('890201', 'C61X', '2026-07-11 08:00')]]),
                $this->usuario('83003', 'F', ['otrosServicios' => [$this->otroServicio('OS-001', 'C539', '2026-07-12 08:00')]]),
            ]),
        ];
        $sql = "-- administrativo\n"
            ."INSERT INTO pacientes (documento, nombre) VALUES ('83001', 'A');\n"
            ."INSERT INTO pacientes (documento, nombre) VALUES ('83003', 'B');\n"
            ."INSERT INTO pacientes (documento, nombre) VALUES ('99999', 'C');\n";

        $withSql = $this->exportWorkbook($user, 'prostata', $files, $sql);
        $withoutSql = $this->exportWorkbook($user, 'prostata', $files);

        $validation = collect($this->records($withSql->getSheetByName('VALIDACION SQL')))
            ->mapWithKeys(static fn (array $row): array => [(string) $row['DOCUMENTO'] => $row['RESULTADO']])
            ->all();

        $this->assertSame([
            '83001' => 'EN RIPS Y EN ADMINISTRATIVO',
            '83002' => 'SOLO EN RIPS',
            '99999' => 'SOLO EN ADMINISTRATIVO',
        ], $validation);
        $this->assertSame(['DOCUMENTO', 'RESULTADO', 'OBSERVACION'], $this->headers($withSql->getSheetByName('VALIDACION SQL')));
        $this->assertSame(10000.0, $this->summaryTotal($withSql, 'COSTO ACTIVO (A + CONDICIONADO)'));
        $this->assertSame($this->summaryTotal($withoutSql, 'COSTO ACTIVO (A + CONDICIONADO)'), $this->summaryTotal($withSql, 'COSTO ACTIVO (A + CONDICIONADO)'));
    }

    public function test_service_outside_the_hospitalization_range_is_not_a_package_case(): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();

        $workbook = $this->exportWorkbook($user, 'prostata', [
            'hospitalario.json' => json_encode($this->hospitalPayload('2026-11-05'), JSON_THROW_ON_ERROR),
        ]);

        $this->assertSame(5000.0, $this->detailCost($workbook));
        $this->assertSame(5000.0 + 2 * PoliticaPaqueteHospitalario::VALOR_PAQUETE, $this->summaryTotal($workbook, 'COSTO ACTIVO (A + CONDICIONADO)'));
        $this->assertNotContains('CASOS A REVISAR', $workbook->getSheetNames());
    }

    public function test_hospitalization_generates_the_package_and_absorbs_included_services(): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();

        $workbook = $this->exportWorkbook($user, 'prostata', [
            'hospitalario.json' => json_encode($this->hospitalPayload('2026-10-10'), JSON_THROW_ON_ERROR),
        ]);

        $detail = collect($this->records($workbook->getSheetByName('DETALLE RIPS')))->keyBy('tipoServicio');
        $patient = $this->records($workbook->getSheetByName('SEGUIMIENTO PACIENTE MES'))[0];
        $packages = 2 * PoliticaPaqueteHospitalario::VALOR_PAQUETE;

        $this->assertSame(0.0, $this->detailCost($workbook));
        $this->assertSame(5000.0, (float) $detail['consultas']['TARIFA']);
        $this->assertSame('', (string) $detail['consultas']['COSTO']);
        $this->assertSame('', (string) $detail['hospitalizacion']['COSTO']);
        $this->assertSame($packages, (float) $patient['COSTO ACTIVO (A + CONDICIONADO)']);
        $this->assertSame($packages, $this->summaryTotal($workbook, 'COSTO ACTIVO (A + CONDICIONADO)'));
        $this->assertSame(0, (int) $patient['SERVICIOS SIN TARIFA APLICADA']);
        $this->assertStringContainsString('INCLUYE PAQUETE HOSPITALARIO BLOQUE 1 DE 2', $patient['OBSERVACION']);
        $this->assertStringContainsString('INCLUYE PAQUETE HOSPITALARIO BLOQUE 2 DE 2', $patient['OBSERVACION']);
        $this->assertNotContains('CASOS A REVISAR', $workbook->getSheetNames());

        $control = $this->hospitalControl($workbook)['TOTAL'];
        $this->assertSame(1, $this->hospitalEpisodes($workbook)['EPISODIOS LOGICOS']);
        $this->assertSame(2, (int) $control['PAQUETES']);
        $this->assertSame($packages, (float) $control['COSTO PAQUETES']);
        $this->assertSame(1, (int) $control['SERVICIOS ABSORBIDOS']);
        $this->assertSame(5000.0, (float) $control['COSTO INDIVIDUAL EXCLUIDO']);
    }

    /**
     * @return array<string, array{string, string, int}>
     */
    public static function hospitalStays(): array
    {
        return [
            'H2 24 horas' => ['2026-10-01 08:00', '2026-10-02 08:00', 1],
            'H3 10 dias' => ['2026-10-01 08:00', '2026-10-11 08:00', 1],
            'H4 25 dias' => ['2026-10-01 08:00', '2026-10-26 08:00', 1],
            'H5 26 dias' => ['2026-10-01 08:00', '2026-10-27 08:00', 2],
            'H20 51 dias' => ['2026-10-01 08:00', '2026-11-21 08:00', 3],
        ];
    }

    #[DataProvider('hospitalStays')]
    public function test_long_hospitalizations_charge_one_package_per_block_of_25_days(string $ingreso, string $egreso, int $paquetes): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();

        $workbook = $this->exportWorkbook($user, 'prostata', [
            'estancia.json' => $this->hospitalRips([], $ingreso, $egreso),
        ]);

        $this->assertSame(round($paquetes * PoliticaPaqueteHospitalario::VALOR_PAQUETE, 2), $this->summaryTotal($workbook, 'COSTO ACTIVO (A + CONDICIONADO)'));
        $this->assertSame($paquetes, (int) $this->hospitalControl($workbook)['TOTAL']['PAQUETES']);
    }

    public function test_h1_emergency_under_24_hours_without_urgency_consult_is_pending_and_does_not_sum(): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();

        $workbook = $this->exportWorkbook($user, 'prostata', [
            'corta.json' => $this->hospitalRips(
                ['consultas' => [$this->procedimiento('890201', '2026-10-01 10:00', 2, 'consultas')]],
                '2026-10-01 08:00',
                '2026-10-02 07:59'
            ),
        ]);

        $review = $this->records($workbook->getSheetByName('CASOS A REVISAR'));
        $control = $this->hospitalControl($workbook)['TOTAL'];
        $episodes = $this->hospitalEpisodes($workbook);
        $detail = collect($this->records($workbook->getSheetByName('DETALLE RIPS')))->keyBy('tipoServicio');

        $this->assertSame(0.0, $this->summaryTotal($workbook, 'COSTO ACTIVO (A + CONDICIONADO)'));
        $this->assertSame(5000.0, (float) $detail['consultas']['TARIFA']);
        $this->assertSame('', (string) $detail['consultas']['COSTO']);
        $this->assertSame(0, (int) $control['PAQUETES']);
        $this->assertSame(5000.0, (float) $control['COSTO REFERENCIA URGENCIA PENDIENTE']);
        $this->assertSame(1, $episodes['URGENCIAS < 24 H PENDIENTES DE TARIFA']);
        $this->assertSame(0, $episodes['URGENCIAS < 24 H CON TARIFA']);
        $this->assertCount(1, $review);
        $this->assertSame('hospitalizacion', $review[0]['tipoServicio']);
        $this->assertStringContainsString('URGENCIA <24H - TARIFA PENDIENTE DE DEFINICION (SIN CONSULTA DE URGENCIAS', $review[0]['MOTIVO']);
    }

    public function test_surgery_in_short_stay_is_listed_for_review_without_increasing_the_total(): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();
        $this->createTechnicalItem('602001', 'PROCEDIMIENTO QUIRURGICO', 120000);
        $surgery = $this->procedimiento('602001', '2026-10-01 10:00', 2);
        $surgery['grupoServicios'] = '04';

        $workbook = $this->exportWorkbook($user, 'prostata', [
            'cirugia-corta.json' => $this->hospitalRips(
                ['procedimientos' => [$surgery]],
                '2026-10-01 08:00',
                '2026-10-02 07:59'
            ),
        ]);

        $review = collect($this->records($workbook->getSheetByName('CASOS A REVISAR')))
            ->keyBy('codigo (codConsulta / codProcedimiento / codTecnologiaSalud)');

        $this->assertSame(0.0, $this->summaryTotal($workbook, 'COSTO ACTIVO (A + CONDICIONADO)'));
        $this->assertTrue($review->has('602001'));
        $this->assertStringContainsString('INDEPENDENCIA ECONOMICA SIN ACREDITAR', $review['602001']['MOTIVO']);
        $this->assertStringContainsString('NO SUMA AL ADMINISTRATIVO', $review['602001']['MOTIVO']);
    }

    public function test_emergency_under_24_hours_is_charged_with_its_urgency_consult_tariff(): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();
        $this->createTechnicalItem('890701', 'CONSULTA DE URGENCIAS POR MEDICINA GENERAL', 40195.6077);

        $workbook = $this->exportWorkbook($user, 'prostata', [
            'urgencia.json' => $this->hospitalRips(
                ['consultas' => [
                    $this->procedimiento('890701', '2026-10-01 08:30', 2, 'consultas'),
                    $this->procedimiento('890201', '2026-10-01 10:00', 3, 'consultas'),
                ]],
                '2026-10-01 08:00',
                '2026-10-01 20:00'
            ),
        ]);

        $costs = collect($this->records($workbook->getSheetByName('DETALLE RIPS')))
            ->mapWithKeys(static fn (array $row): array => [(string) $row['codigo (codConsulta / codProcedimiento / codTecnologiaSalud)'] => (string) $row['COSTO']])
            ->all();

        $this->assertSame(40195.61, $this->summaryTotal($workbook, 'COSTO ACTIVO (A + CONDICIONADO)'));
        $this->assertSame('', $costs['890201']);
        $this->assertSame(1, $this->hospitalEpisodes($workbook)['URGENCIAS < 24 H CON TARIFA']);
        $this->assertSame(1, (int) $this->hospitalControl($workbook)['TOTAL']['URGENCIAS COBRADAS']);
        $this->assertNotContains('CASOS A REVISAR', $workbook->getSheetNames());
    }

    public function test_stay_split_by_billing_cut_08_in_two_invoices_is_one_logical_stay(): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();

        $workbook = $this->exportWorkbook($user, 'prostata', [
            'corte.json' => $this->hospitalRips(
                ['consultas' => [$this->procedimiento('890201', '2026-06-10 10:00', 2, 'consultas')]],
                '2026-06-05 08:00',
                '2026-06-15 23:59',
                '08',
                'FAC-JUN-1'
            ),
            'continuacion.json' => $this->hospitalRips([], '2026-06-16 00:00', '2026-06-25 08:00', '01', 'FAC-JUN-2'),
        ]);

        $episodes = $this->hospitalEpisodes($workbook);

        $this->assertSame(PoliticaPaqueteHospitalario::VALOR_PAQUETE, $this->summaryTotal($workbook, 'COSTO ACTIVO (A + CONDICIONADO)'));
        $this->assertSame(2, $episodes['REGISTROS DE HOSPITALIZACION DEL RIPS']);
        $this->assertSame(1, $episodes['SEGMENTOS CON CORTE DE FACTURACION 08']);
        $this->assertSame(1, $episodes['EPISODIOS LOGICOS']);
        $this->assertSame(1, $episodes['CONTINUIDADES RESUELTAS']);
        $this->assertSame(0, $episodes['CONTINUIDADES PENDIENTES']);
        $this->assertSame(1, $episodes['PAQUETES GENERADOS']);
        $this->assertNotContains('CASOS A REVISAR', $workbook->getSheetNames());
    }

    public function test_packages_of_a_long_stay_are_imputed_to_the_month_where_each_block_starts(): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();

        $workbook = $this->exportWorkbook($user, 'prostata', [
            'estancia.json' => $this->hospitalRips([], '2026-06-20 08:00', '2026-07-20 08:00', '01', 'FAC-JUN'),
        ]);

        $months = collect($this->monthlyRowsWithInternalEconomics($workbook))
            ->mapWithKeys(static fn (array $row): array => [(string) $row['MES'] => (float) $row['COSTO ACTIVO (A + CONDICIONADO)']])
            ->all();
        $patients = collect($this->records($workbook->getSheetByName('SEGUIMIENTO PACIENTE MES')))->keyBy('MES');

        $this->assertSame(['2026-06' => PoliticaPaqueteHospitalario::VALOR_PAQUETE, '2026-07' => PoliticaPaqueteHospitalario::VALOR_PAQUETE], $months);
        $this->assertStringContainsString('BLOQUE 2 DE 2', $patients['2026-07']['OBSERVACION']);
        $this->assertStringContainsString('INICIO DEL BLOQUE 2026-07-15 08:00', $patients['2026-07']['OBSERVACION']);
    }

    public function test_block_without_rips_records_in_its_month_creates_a_package_only_patient_month(): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();

        $workbook = $this->exportWorkbook($user, 'prostata', [
            'estancia.json' => $this->hospitalRips([], '2026-10-01 08:00', '2026-11-21 08:00'),
        ]);

        $months = collect($this->monthlyRowsWithInternalEconomics($workbook))
            ->mapWithKeys(static fn (array $row): array => [(string) $row['MES'] => (float) $row['COSTO ACTIVO (A + CONDICIONADO)']])
            ->all();
        $november = collect($this->records($workbook->getSheetByName('SEGUIMIENTO PACIENTE MES')))->keyBy('MES')['2026-11'];

        $this->assertSame(['2026-10' => 2 * PoliticaPaqueteHospitalario::VALOR_PAQUETE, '2026-11' => PoliticaPaqueteHospitalario::VALOR_PAQUETE], $months);
        $this->assertSame(0, (int) $november['TOTAL SERVICIOS']);
        $this->assertStringContainsString('SOLO CARGO DE PAQUETE HOSPITALARIO', $november['OBSERVACION']);
    }

    public function test_short_billing_cut_08_without_continuation_is_pending_and_not_an_emergency(): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();

        $workbook = $this->exportWorkbook($user, 'prostata', [
            'corte.json' => $this->hospitalRips(
                ['consultas' => [$this->procedimiento('890201', '2026-08-31 10:00', 2, 'consultas')]],
                '2026-08-31 01:18',
                '2026-08-31 18:50',
                '08'
            ),
        ]);

        $review = $this->records($workbook->getSheetByName('CASOS A REVISAR'));
        $episodes = $this->hospitalEpisodes($workbook);

        $this->assertSame(0.0, $this->summaryTotal($workbook, 'COSTO ACTIVO (A + CONDICIONADO)'));
        $this->assertSame(1, $episodes['CONTINUIDADES PENDIENTES']);
        $this->assertSame(0, $episodes['URGENCIAS < 24 H PENDIENTES DE TARIFA']);
        $this->assertSame(0, $episodes['PAQUETES GENERADOS']);
        $this->assertCount(1, $review);
        $this->assertStringContainsString('CONTINUIDAD HOSPITALARIA NO RESUELTA', $review[0]['MOTIVO']);
    }

    public function test_h7_h8_common_medication_is_absorbed_inside_and_charged_outside_the_stay(): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();
        $this->createDispensationCatalog('100', '19935303-4', '2026-10-05', 4400);
        $inside = $this->medicamento('19935303-4', 'PARACETAMOL', 180, 2);
        $inside['fechaDispensAdmon'] = '2026-10-05 09:00';
        $outside = $this->medicamento('19935303-4', 'PARACETAMOL', 180, 3);
        $outside['fechaDispensAdmon'] = '2026-10-20 09:00';

        $workbook = $this->exportWorkbook($user, 'prostata', [
            'medicamentos.json' => $this->hospitalRips(['medicamentos' => [$inside, $outside]]),
        ]);

        $costs = collect($this->records($workbook->getSheetByName('DETALLE RIPS')))
            ->where('tipoServicio', 'medicamentos')
            ->map(static fn (array $row): string => (string) $row['COSTO'])
            ->values()
            ->all();

        $this->assertSame(['', '792000'], $costs);
        $this->assertSame(792000.0 + PoliticaPaqueteHospitalario::VALOR_PAQUETE, $this->summaryTotal($workbook, 'COSTO ACTIVO (A + CONDICIONADO)'));
        $this->assertSame(792000.0, (float) $this->hospitalControl($workbook)['TOTAL']['COSTO INDIVIDUAL EXCLUIDO']);
    }

    public function test_h9_to_h15_included_and_billable_services_during_the_stay(): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();
        $this->createHospitalServiceCatalog();

        $workbook = $this->exportWorkbook($user, 'prostata', [
            'servicios.json' => $this->hospitalRips([
                'procedimientos' => [
                    $this->procedimiento('902210', '2026-10-02 08:00', 2),
                    $this->procedimiento('550202', '2026-10-02 09:00', 3),
                    $this->procedimiento('992505', '2026-10-03 08:00', 4),
                    $this->procedimiento('883440', '2026-10-04 08:00', 5),
                    $this->procedimiento('920901', '2026-10-05 08:00', 6),
                    $this->procedimiento('922444', '2026-10-06 08:00', 7),
                ],
                'otrosServicios' => [$this->otroServicio('DM00114', 'C61X', '2026-10-02 10:00', 8)],
            ]),
        ]);

        $costs = collect($this->records($workbook->getSheetByName('DETALLE RIPS')))
            ->mapWithKeys(static fn (array $row): array => [(string) $row['codigo (codConsulta / codProcedimiento / codTecnologiaSalud)'] => (string) $row['COSTO']])
            ->all();
        $billable = 100000.0 + 444690.0 + 500000.0 + 300000.0 + 8852976.0;
        $control = $this->hospitalControl($workbook)['TOTAL'];

        $this->assertSame('', $costs['902210']);
        $this->assertSame('', $costs['DM00114']);
        $this->assertSame(['100000', '444690', '500000', '300000', '8852976'], [
            $costs['550202'], $costs['992505'], $costs['883440'], $costs['920901'], $costs['922444'],
        ]);
        $this->assertSame(round($billable + PoliticaPaqueteHospitalario::VALOR_PAQUETE, 2), $this->summaryTotal($workbook, 'COSTO ACTIVO (A + CONDICIONADO)'));
        $this->assertSame(2, (int) $control['SERVICIOS ABSORBIDOS']);
        $this->assertSame(1700.0, (float) $control['COSTO INDIVIDUAL EXCLUIDO']);
        $this->assertSame(5, (int) $control['SERVICIOS COBRADOS POR FUERA']);
        $this->assertSame($billable, (float) $control['COSTO COBRADO POR FUERA']);
        $this->assertNotContains('CASOS A REVISAR', $workbook->getSheetNames());
    }

    public function test_h16_to_h18_undefined_hospital_rules_keep_their_cost_and_are_reported(): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();
        $this->createHospitalServiceCatalog();
        $this->createDispensationCatalog('100', '20032905-1', '2026-10-05', 2000000);
        $this->createCumCatalog('20032905-1', 'L02AE02', 'LEUPRORELINA');
        $leuprorelina = $this->medicamento('20032905-1', 'LEUPRORELINA', 1, 4);
        $leuprorelina['fechaDispensAdmon'] = '2026-10-05 09:00';

        $workbook = $this->exportWorkbook($user, 'prostata', [
            'pendientes.json' => $this->hospitalRips([
                'procedimientos' => [
                    $this->procedimiento('921401', '2026-10-02 08:00', 2),
                    $this->procedimiento('911107', '2026-10-03 08:00', 3),
                ],
                'medicamentos' => [$leuprorelina],
            ]),
        ]);

        $review = collect($this->records($workbook->getSheetByName('CASOS A REVISAR')))
            ->keyBy('codigo (codConsulta / codProcedimiento / codTecnologiaSalud)');
        $pending = 2000000.0 + 566200.0 + 2000000.0;

        $this->assertSame(round($pending + PoliticaPaqueteHospitalario::VALOR_PAQUETE, 2), $this->summaryTotal($workbook, 'COSTO ACTIVO (A + CONDICIONADO)'));
        $this->assertSame(['921401', '911107', '20032905-1'], $review->keys()->map(static fn (mixed $codigo): string => (string) $codigo)->all());
        $this->assertStringContainsString('REGLA DE PAQUETE NO DEFINIDA (PET-TC)', $review['921401']['MOTIVO']);
        $this->assertStringContainsString('REGLA DE PAQUETE NO DEFINIDA (TRANSFUSION)', $review['911107']['MOTIVO']);
        $this->assertStringContainsString('ONCOLOGICO/HORMONAL ATC L02AE02', $review['20032905-1']['MOTIVO']);
        $this->assertSame(3, (int) $this->hospitalControl($workbook)['TOTAL']['SERVICIOS REGLA PENDIENTE']);
    }

    public function test_h19_package_and_an_included_line_never_charge_twice(): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();
        $payload = json_encode($this->hospitalPayload('2026-10-10'), JSON_THROW_ON_ERROR);

        $workbook = $this->exportWorkbook($user, 'prostata', ['incluido.json' => $payload]);
        $detail = collect($this->records($workbook->getSheetByName('DETALLE RIPS')))->keyBy('tipoServicio');
        $control = $this->hospitalControl($workbook)['TOTAL'];
        $total = $this->summaryTotal($workbook, 'COSTO ACTIVO (A + CONDICIONADO)');

        $this->assertSame((float) $control['COSTO PAQUETES'] + $this->detailCost($workbook), $total);
        $this->assertSame('', (string) $detail['consultas']['COSTO']);
        $this->assertNotSame($total, (float) $control['COSTO PAQUETES'] + (float) $control['COSTO INDIVIDUAL EXCLUIDO']);

        $this->app->instance(SeguimientoRutaClassifier::class, new SeguimientoRutaClassifier(
            politicaPaquete: new PoliticaPaqueteHospitalario([], ['consultas' => ['890201']])
        ));
        $billable = $this->exportWorkbook($user, 'prostata', ['cobrable.json' => $payload]);

        $this->assertSame(5000.0 + 2 * PoliticaPaqueteHospitalario::VALOR_PAQUETE, $this->summaryTotal($billable, 'COSTO ACTIVO (A + CONDICIONADO)'));
        $this->assertSame(0, (int) $this->hospitalControl($billable)['TOTAL']['SERVICIOS ABSORBIDOS']);
    }

    /**
     * @param  array<string, string>  $files
     */
    public function test_both_dispensation_policies_give_the_same_backed_result_and_referential_scenario(): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();
        $this->createDispensationCatalog('710', '19979158-2', '2026-07-05', 1000);
        $files = ['escenarios.json' => $this->rips('FAC-ESC', [
            $this->usuario('710', 'M', [
                'consultas' => [$this->consulta('890201', 'C61X', '2026-07-10 08:00')],
                'medicamentos' => [$this->medicamento('19979158-2', 'SODIO CLORURO', 2, 2)],
            ]),
        ])];
        $results = [];

        foreach (['FASE_1', 'CONDICIONADA'] as $policy) {
            config(['programas.la-maria.politica_dispensacion' => $policy]);
            $workbook = $this->exportWorkbook($user, 'prostata', $files);
            $this->assertNull($workbook->getSheetByName('COSTOS Y BRECHAS'));
            $results[$policy] = $this->sessionRows('prostata', 'costos', ResumenEconomico::HEADERS)[0];
            $medications[$policy] = $this->sessionRows('prostata', 'medicamentos', ['RUTA', ...ResumenEconomico::MEDICATION_HEADERS])[0];
            $this->assertSame(5000.0, (float) $results[$policy]['COSTO RESPALDADO']);
            $this->assertSame(7000.0, (float) $results[$policy]['ESCENARIO REFERENCIAL']);
        }

        foreach ($results as $row) {
            $this->assertSame(5000.0, (float) $row['COSTO RESPALDADO']);
            $this->assertSame(2000.0, (float) $row['CONDICIONADO INCLUIDO']);
            $this->assertSame(7000.0, (float) $row['ESCENARIO REFERENCIAL']);
            $this->assertSame(0.0, (float) $row['EXTERNOS POTENCIALES']);
            $this->assertSame(3622024541.0 - 5000, (float) $row['BRECHA RESPALDADA']);
            $this->assertSame(3622024541.0 - 7000, (float) $row['BRECHA REFERENCIAL']);
            $this->assertSame('NO; ANALISIS DE DESARROLLO', $row['FACTURACION APROBADA']);
        }

        $this->assertSame(7000.0, (float) $results['FASE_1']['COSTO ACTIVO']);
        $this->assertSame(5000.0, (float) $results['CONDICIONADA']['COSTO ACTIVO']);
        $this->assertSame(2000.0, (float) $results['CONDICIONADA']['DISPENSACION ADMINISTRATIVA EN ESCENARIO']);
        $this->assertSame('DISPENSACION (ESCENARIO REFERENCIAL)', $medications['CONDICIONADA']['FUENTE']);
        $this->assertSame(0.0, (float) $medications['CONDICIONADA']['COSTO RESPALDADO']);
        $this->assertSame(2000.0, (float) $medications['CONDICIONADA']['COSTO CONDICIONADO']);
        $this->assertSame('19979158-2', $medications['CONDICIONADA']['CUM']);
        $this->assertSame(2.0, (float) $medications['CONDICIONADA']['CANTIDAD RIPS']);
    }

    public function test_selected_files_that_do_not_arrive_stop_the_report_and_are_named(): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();

        $this->from(route('la-maria.prostata'))->actingAs($user)->post(route('la-maria.prostata.export'), [
            'archivos' => [UploadedFile::fake()->createWithContent('FEHM632724.json', $this->routeJson('FAC-1', 'C61X', '2026-08-10 08:00', 'consultas'))],
            'archivos_declarados' => json_encode([
                ['nombre' => 'FEHM632724.json', 'tamano' => strlen($this->routeJson('FAC-1', 'C61X', '2026-08-10 08:00', 'consultas'))],
                ['nombre' => 'FEHM632903.json', 'tamano' => 4191714],
                ['nombre' => 'FEHM633817.json', 'tamano' => 7105045],
            ]),
        ])->assertRedirectToRoute('la-maria.prostata')
            ->assertSessionHasErrors(['archivos' => 'Se seleccionaron 3 archivos pero el servidor recibió 1. No llegaron: FEHM632903.json, FEHM633817.json. No se generó el reporte para evitar un consolidado incompleto; vuelve a cargarlos.']);

        $this->assertDatabaseCount('programa_ejecuciones', 0);
    }

    public function test_invalid_files_are_rejected_by_name_before_processing(): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();

        $this->from(route('la-maria.prostata'))->actingAs($user)->post(route('la-maria.prostata.export'), [
            'archivos' => [
                UploadedFile::fake()->createWithContent('valido.json', $this->routeJson('FAC-1', 'C61X', '2026-08-10 08:00', 'consultas')),
                UploadedFile::fake()->createWithContent('truncado.json', '{"numFactura":"FAC-2","usuarios":[{"tipoDocumentoIdentificacion":"CC"'),
                UploadedFile::fake()->createWithContent('sin-usuarios.json', '{"numFactura":"FAC-3"}'),
            ],
        ])->assertRedirectToRoute('la-maria.prostata')
            ->assertSessionHasErrors(['archivos' => 'Archivos rechazados: truncado.json: No es un JSON válido o está incompleto. sin-usuarios.json: No contiene la estructura RIPS esperada (usuarios). Corrígelos o retíralos y vuelve a generar el reporte.']);

        $this->assertDatabaseCount('programa_ejecuciones', 0);
    }

    public function test_upload_limit_reserves_room_for_php_so_no_file_is_dropped_silently(): void
    {
        $user = User::factory()->create();
        $limit = SeguimientoRutaController::uploadLimit();
        $uploads = array_map(
            fn (int $number): UploadedFile => UploadedFile::fake()->createWithContent('archivo-'.$number.'.json', $this->routeJson('FAC-'.$number, 'C61X', '2026-08-10 08:00', 'consultas')),
            range(1, $limit + 1)
        );

        $this->assertLessThanOrEqual((int) ini_get('max_file_uploads'), $limit + 1);
        $this->from(route('la-maria.prostata'))->actingAs($user)
            ->post(route('la-maria.prostata.export'), ['archivos' => $uploads])
            ->assertSessionHasErrors(['archivos' => 'Puedes cargar hasta '.$limit.' archivos por vez.']);
        $this->actingAs($user)->get(route('la-maria.prostata'))->assertSee('Puedes cargar hasta '.$limit.' archivos', false);
    }

    public function test_control_sheet_reports_selected_received_processed_rejected_and_omitted_files(): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();
        $content = $this->routeJson('FAC-DUP', 'C61X', '2026-07-10 08:00', 'consultas');

        $workbook = $this->exportWorkbook($user, 'prostata', ['original.json' => $content, 'copia.json' => $content], declare: true);
        $reception = $this->controlBlock($workbook, 'RECEPCION DE ARCHIVOS');

        $this->assertSame(2, (int) $reception['SELECCIONADOS EN PANTALLA']['CANTIDAD']);
        $this->assertSame(2, (int) $reception['RECIBIDOS POR EL SERVIDOR']['CANTIDAD']);
        $this->assertSame(1, (int) $reception['PROCESADOS']['CANTIDAD']);
        $this->assertSame('original.json', $reception['PROCESADOS']['ARCHIVOS']);
        $this->assertSame(1, (int) $reception['RECHAZADOS (CONTENIDO IDENTICO)']['CANTIDAD']);
        $this->assertSame('copia.json', $reception['RECHAZADOS (CONTENIDO IDENTICO)']['ARCHIVOS']);
        $this->assertSame(0, (int) $reception['OMITIDOS']['CANTIDAD']);
        $this->assertSame(1, (int) $this->monthlyRowsWithInternalEconomics($workbook)[0]['SERVICIOS']);
    }

    public function test_multi_month_costs_end_with_a_period_total_and_the_gap_explanation_balances(): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();
        $this->createExplainedNote();

        $workbook = $this->exportWorkbook($user, 'prostata', [
            'junio.json' => $this->routeJson('FAC-JUN', 'C61X', '2026-06-10 08:00', 'consultas'),
            'julio.json' => $this->routeJson('FAC-JUL', 'C61X', '2026-07-10 08:00', 'consultas'),
        ]);
        $costs = collect($this->sessionRows('prostata', 'costos', ResumenEconomico::HEADERS))->keyBy('MES');
        $explanation = collect($this->sessionRows('prostata', 'explicacion', ExplicacionBrecha::HEADERS))
            ->keyBy(static fn (array $row): string => $row['MES'].'|'.$row['GRUPO NOTA']);

        $this->assertSame(['2026-06', '2026-07', 'TOTAL PERIODO'], $costs->keys()->all());
        $this->assertSame(20000.0, (float) $costs['2026-06']['REFERENCIA NOTA']);
        $this->assertSame(40000.0, (float) $costs['TOTAL PERIODO']['REFERENCIA NOTA']);
        $this->assertSame(10000.0, (float) $costs['TOTAL PERIODO']['COSTO RESPALDADO']);

        $consultations = $explanation['2026-06|CONSULTA_EXTERNA'];
        $this->assertSame(3.0, (float) $consultations['UNIDADES PROYECTADAS']);
        $this->assertSame(1.0, (float) $consultations['UNIDADES RIPS']);
        $this->assertSame(10000.0, (float) $consultations['MENOR ACTIVIDAD QUE LA PROYECTADA']);
        $this->assertSame(5000.0, (float) $explanation['2026-06|LABORATORIO_CLINICO']['PRESUPUESTADO SIN ACTIVIDAD RIPS']);

        foreach (['2026-06', '2026-07'] as $month) {
            $total = $explanation[$month.'|TOTAL'];
            $this->assertSame(15000.0, (float) $total['BRECHA RESPALDADA']);
            $this->assertSame(15000.0, (float) $total['BRECHA EXPLICADA']);
            $this->assertSame(0.0, (float) $total['DIFERENCIA SIN EXPLICAR']);
            $this->assertSame(15000.0, (float) $costs[$month]['BRECHA EXPLICADA']);
        }

        $comparison = $this->internalComparisonRows($workbook)['PROSTATA'];
        $this->assertSame(['2026-06', '2026-07'], array_keys($comparison));
        foreach ($comparison as $month) {
            $this->assertSame(5000.0, (float) $month['TOTAL']['COSTO A']);
        }
        $this->assertCount(4, $this->headers($workbook->getSheetByName('SEGUIMIENTO MENSUAL')));
        $this->assertCount(4, $this->records($workbook->getSheetByName('SEGUIMIENTO MENSUAL'))[0]);
    }

    public function test_consolidated_export_combines_both_routes_with_the_figures_of_each_route(): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();
        $files = [
            'prostata.json' => $this->routeJson('FAC-P', 'C61X', '2026-07-10 08:00', 'consultas'),
            'cervix.json' => $this->rips('FAC-C', [$this->usuario('900', 'F', ['otrosServicios' => [$this->otroServicio('OS-001', 'C53X', '2026-07-12 08:00')]])]),
        ];

        $consolidated = $this->exportWorkbook($user, 'consolidado', $files, declare: true);
        $costs = collect($this->sessionRows('consolidado', 'costos', ResumenEconomico::HEADERS))->keyBy('RUTA');
        $this->assertSame(['SEGUIMIENTO MENSUAL', 'CATALOGOS APLICADOS', 'CONTROL DE CARGUE'], $consolidated->getSheetNames());
        $this->assertSame(['PROSTATA', 'CERVIX'], $costs->keys()->all());
        $this->assertSame(['PROSTATA', 'CERVIX'], array_keys($this->internalComparisonRows($consolidated)));

        foreach (['prostata' => 'PROSTATA', 'cervix' => 'CERVIX'] as $execution => $route) {
            $single = $this->exportWorkbook($user, $execution, $files);
            $this->assertSame($this->sessionRows($execution, 'costos', ResumenEconomico::HEADERS)[0], $costs[$route]);
            $this->assertEquals($this->internalComparisonRows($single)[$route], $this->internalComparisonRows($consolidated)[$route]);
        }

        $this->assertSame(5000.0, (float) $costs['PROSTATA']['COSTO RESPALDADO']);
        $this->assertSame(8000.0, (float) $costs['CERVIX']['COSTO RESPALDADO']);
        $this->assertSame(2, (int) $this->controlBlock($consolidated, 'RECEPCION DE ARCHIVOS')['PROCESADOS']['CANTIDAD']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'seguimiento_rutas']);
    }

    public function test_original_screens_keep_independent_exports_and_economic_data_in_excel(): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();

        $this->actingAs($user)->get(route('la-maria'))
            ->assertOk()
            ->assertSee(route('la-maria.prostata'))
            ->assertSee(route('la-maria.cervix'))
            ->assertDontSeeText('Consolidado económico de Próstata y Cérvix')
            ->assertDontSee('data-upload-progress-form', false);

        $files = [];
        for ($index = 1; $index <= 12; $index++) {
            $files["rips-{$index}.json"] = $this->rips("FAC-{$index}", [
                $this->usuario((string) (1000 + $index), 'M', ['consultas' => [$this->consulta('890201', 'C61X', '2026-07-10 08:00')]]),
                $this->usuario((string) (2000 + $index), 'F', ['otrosServicios' => [$this->otroServicio('OS-001', 'C53X', '2026-07-12 08:00')]]),
            ]);
        }

        foreach (['prostata' => 'PROSTATA', 'cervix' => 'CERVIX'] as $execution => $route) {
            $workbook = $this->exportWorkbook($user, $execution, $files, declare: true);
            $costs = $this->sessionRows($execution, 'costos', ResumenEconomico::HEADERS);
            $this->assertSame([$route], array_values(array_unique(array_column($costs, 'RUTA'))));
            $this->assertNull($workbook->getSheetByName('EXPLICACION BRECHA'));
            $this->assertArrayHasKey('2026-07', $this->internalComparisonRows($workbook)[$route]);
            $this->assertNotNull($workbook->getSheetByName('SEGUIMIENTO MENSUAL'));
            $this->assertSame(12, (int) $this->controlBlock($workbook, 'RECEPCION DE ARCHIVOS')['PROCESADOS']['CANTIDAD']);

            $this->get(route('la-maria.'.$execution))
                ->assertOk()
                ->assertSee('width: min(760px, 100%)', false)
                ->assertSee(route('la-maria.'.$execution.'.export'))
                ->assertSee('name="archivos[]"', false)
                ->assertSee('name="archivos_declarados"', false)
                ->assertSeeText('Catálogos usados en este reporte:')
                ->assertDontSeeText('Costos y brechas')
                ->assertDontSeeText('Explicación de la brecha respaldada')
                ->assertDontSeeText('Medicamentos de mayor impacto')
                ->assertDontSee('data-upload-progress-reload', false);
        }
    }

    private function createExplainedNote(): void
    {
        $note = CatalogoReferencia::query()->where('tipo', 'NOTA_PROSTATA')->firstOrFail();
        CatalogoReferenciaItem::query()->where('catalogo_referencia_id', $note->id)->where('codigo', '890201')
            ->update(['metadatos' => ['servicio' => 'Consulta externa', 'cantidad_mensual' => 3]]);

        foreach ([
            ['RESUMEN:CONSULTA_EXTERNA', 'RESUMEN_TECNICO', 15000, []],
            ['RESUMEN:LABORATORIO_CLINICO', 'RESUMEN_TECNICO', 5000, []],
            ['RESUMEN:VALOR_TOTAL_MES', 'RESUMEN_TECNICO', 20000, []],
            ['903841', 'SERVICIO_TECNICO', 2500, ['servicio' => 'Laboratorio Clínico', 'cantidad_mensual' => 2]],
        ] as [$code, $category, $rate, $metadata]) {
            CatalogoReferenciaItem::create([
                'catalogo_referencia_id' => $note->id,
                'codigo' => $code,
                'ruta' => 'PROSTATA',
                'categoria' => $category,
                'descripcion' => $code,
                'tarifa_referencia' => $rate,
                'metadatos' => $metadata,
            ]);
        }
    }

    public function test_administrative_report_adds_a_separate_referential_estimate_without_changing_cost_a_or_exposing_documents(): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();
        $informe = $this->administrativeReport([
            ['890201', 'AC', 5000], ['890201', 'AC', 5000], ['DM00114', 'AT', 1200], ['DM00114', 'AT', 1200],
        ]);
        $files = ['septiembre.json' => $this->rips('FAC-SEP', [
            $this->usuario('9988776655', 'M', [
                'consultas' => [$this->consulta('890201', 'C61X', '2026-09-10 08:00')],
                'otrosServicios' => [$this->otroServicio('DM00114', 'C61X', '2026-09-10 09:00', 2)],
            ]),
        ])];

        try {
            $workbook = $this->exportWorkbook($user, 'prostata', $files, informes: ['INFORME ADMIN PROSTATA PARA JUAN.xlsx' => $informe]);
        } finally {
            File::delete($informe);
        }

        $costs = $this->sessionRows('prostata', 'costos', ResumenEconomico::HEADERS)[0];
        $estimate = collect(session('la-maria.resultado.prostata')['estimacion'])->keyBy(2);
        $comparison = $this->internalComparisonRows($workbook)['PROSTATA']['2026-09'];

        $this->assertSame(5000.0, (float) $costs['COSTO RESPALDADO']);
        $this->assertSame(5000.0, (float) $comparison['TOTAL']['COSTO A']);
        $this->assertSame(5000.0, (float) $comparison['AC']['ESTIMACION ADMINISTRATIVA CENTRAL']);
        $this->assertSame(10000.0, (float) $estimate['AC'][10], 'Mediana del total mensual: dos registros de $5.000 por mes.');
        $this->assertSame(0.0, (float) $comparison['AT']['ESTIMACION ADMINISTRATIVA CENTRAL']);
        $this->assertSame(5000.0, (float) $comparison['TOTAL']['ESTIMACION ADMINISTRATIVA CENTRAL']);
        $this->assertSame('ESTIMACION ADMINISTRATIVA REFERENCIAL; NO ES FACTURACION', $estimate['TOTAL'][14]);
        $this->assertNull($workbook->getSheetByName('DETALLE POR CÓDIGO'));

        foreach (['SEGUIMIENTO MENSUAL'] as $sheet) {
            foreach ($workbook->getSheetByName($sheet)->toArray(null, true, false, false) as $row) {
                foreach ($row as $cell) {
                    $this->assertStringNotContainsString('9988776655', (string) $cell, 'Documento RIPS en '.$sheet);
                    $this->assertStringNotContainsString('70975586', (string) $cell, 'Documento administrativo en '.$sheet);
                }
            }
        }

        $this->get(route('la-maria.prostata'))->assertSeeText('Informe administrativo histórico');
    }

    public function test_administrative_report_must_be_an_xlsx_file(): void
    {
        $user = User::factory()->create();

        $this->from(route('la-maria.prostata'))->actingAs($user)->post(route('la-maria.prostata.export'), [
            'archivos' => [UploadedFile::fake()->createWithContent('a.json', $this->routeJson('FAC-1', 'C61X', '2026-09-10 08:00', 'consultas'))],
            'informes_administrativos' => [UploadedFile::fake()->createWithContent('informe.csv', 'MES;VALOR')],
        ])->assertSessionHasErrors(['informes_administrativos.0' => 'Cada informe administrativo debe ser un archivo .xlsx.']);
    }

    public function test_first_sheet_shows_monthly_total_and_non_additive_au(): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();
        $informe = $this->administrativeReport([
            ['890201', 'AC', 5000], ['DM00114', 'AT', 1200], ['URG', 'AU', 300],
        ]);
        $files = ['septiembre.json' => $this->rips('FAC-SEP', [
            $this->usuario('9988776655', 'M', [
                'consultas' => [$this->consulta('890201', 'C61X', '2026-09-10 08:00')],
                'otrosServicios' => [$this->otroServicio('DM00114', 'C61X', '2026-09-12 09:00', 2)],
                'urgencias' => [[
                    'codPrestador' => '050010608601', 'fechaInicioAtencion' => '2026-09-10 07:00', 'causaMotivoAtencion' => '38',
                    'codDiagnosticoPrincipal' => 'C61X', 'codDiagnosticoPrincipalE' => 'C61X', 'condicionDestinoUsuarioEgreso' => '01',
                    'fechaEgreso' => '2026-09-10 18:00', 'consecutivo' => 3,
                ]],
            ]),
        ])];

        try {
            $workbook = $this->exportWorkbook($user, 'prostata', $files, informes: ['INFORME ADMIN PROSTATA PARA JUAN.xlsx' => $informe]);
        } finally {
            File::delete($informe);
        }

        $sheet = $workbook->getSheetByName('SEGUIMIENTO MENSUAL');
        $comparison = $this->internalComparisonRows($workbook)['PROSTATA']['2026-09'];

        $this->assertSame([], array_values(array_intersect(self::HOJAS_RETIRADAS, $workbook->getSheetNames())));
        $this->assertSame('CONTROL DE CARGUE', $workbook->getSheetNames()[array_key_last($workbook->getSheetNames())]);
        $this->assertSame(['MES', 'PACIENTES', 'SERVICIOS', 'VALOR TOTAL MES'], $this->headers($sheet));
        $this->assertSame([['MES' => '2026-09', 'PACIENTES' => 1, 'SERVICIOS' => 3, 'VALOR TOTAL MES' => 5000]], $this->records($sheet));
        $this->assertSame('FF4472C4', $sheet->getCell('A1')->getStyle()->getFill()->getStartColor()->getARGB());
        $this->assertSame(5000.0, (float) $comparison['TOTAL']['COSTO A']);
        $this->assertSame(5000.0, (float) $comparison['TOTAL']['ESTIMACION ADMINISTRATIVA CENTRAL']);
        $this->assertSame(0.0, (float) $comparison['AU']['ESTIMACION ADMINISTRATIVA CENTRAL']);
        $this->assertSame(5000.0, (float) $comparison['AU']['IMPORTES INFORMATIVOS NO ADITIVOS']);
        $this->assertSame('AU — Urgencias (sin cobro propio; no aditivo)', (string) $sheet->getCell('A9')->getValue());
        $this->assertSame(1, (int) $sheet->getCell('B9')->getValue());
        $this->assertSame(0.0, (float) $sheet->getCell('D9')->getValue());
        $this->assertSame(5000.0, (float) $sheet->getCell('D12')->getValue());
        $this->assertSame(4000000000.0, ValorizacionAdministrativa::REFERENCIAS_GERENCIALES['PROSTATA']);
    }

    public function test_configured_administrative_report_of_the_route_is_read_without_uploading_it(): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();
        $configured = $this->administrativeReport([['DM00114', 'AT', 1200]]);
        $uploaded = $this->administrativeReport([['DM00114', 'AT', 1500]]);
        config([
            'programas.la-maria.informes_administrativos.requerido' => true,
            'programas.la-maria.informes_administrativos.PROSTATA' => $configured,
        ]);
        $files = ['septiembre.json' => $this->rips('FAC-SEP', [
            $this->usuario('9988776655', 'M', ['otrosServicios' => [$this->otroServicio('DM00114', 'C61X', '2026-09-12 09:00')]]),
        ])];

        try {
            $automatic = $this->internalComparisonRows($this->exportWorkbook($user, 'prostata', $files))['PROSTATA']['2026-09'];
            $replaced = $this->internalComparisonRows($this->exportWorkbook($user, 'prostata', $files, informes: ['INFORME NUEVO.xlsx' => $uploaded]))['PROSTATA']['2026-09'];
        } finally {
            File::delete([$configured, $uploaded]);
        }

        $this->assertSame(0.0, (float) $automatic['AT']['ESTIMACION ADMINISTRATIVA CENTRAL']);
        $this->assertSame(0.0, (float) $replaced['AT']['ESTIMACION ADMINISTRATIVA CENTRAL']);
        $this->assertSame(1200.0, (float) $automatic['AT']['COMPONENTE CONDICIONADO']);
        $this->assertSame(1500.0, (float) $replaced['AT']['COMPONENTE CONDICIONADO'], 'El informe cargado reemplaza al configurado de su ruta, sin convertir el insumo en cobro independiente.');
    }

    public function test_monthly_summary_declares_whether_the_total_is_autonomous_or_conciliated_with_the_month_report(): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();
        $sinMes = $this->administrativeReport([['DM00114', 'AT', 1200]]);
        $conMes = $this->administrativeReport([['DM00114', 'AT', 1200]], fechas: ['2026-06-15', '2026-07-15', '2026-08-15', '2026-09-12']);
        config(['programas.la-maria.informes_administrativos.requerido' => true]);
        $files = ['septiembre.json' => $this->rips('FAC-SEP', [
            $this->usuario('9988776655', 'M', ['otrosServicios' => [$this->otroServicio('DM00114', 'C61X', '2026-09-12 09:00')]]),
        ])];
        $fuente = static function (Spreadsheet $workbook): array {
            $filas = $workbook->getSheetByName('SEGUIMIENTO MENSUAL')->toArray(null, true, false);
            $total = array_search('TOTAL DEL MES', array_column($filas, 0), true);

            return array_map(static fn (array $fila): array => [$fila[0], $fila[1]], array_slice($filas, $total + 2, 3));
        };

        try {
            $autonomo = $fuente($this->exportWorkbook($user, 'prostata', $files, informes: ['INFORME HASTA AGOSTO.xlsx' => $sinMes]));
            $conciliado = $fuente($this->exportWorkbook($user, 'prostata', $files, informes: ['INFORME CON SEPTIEMBRE.xlsx' => $conMes]));
        } finally {
            File::delete([$sinMes, $conMes]);
        }

        // Sin informe del mes: el total es el cálculo autónomo y así lo declara.
        $this->assertSame('FUENTE DEL TOTAL', $autonomo[0][0]);
        $this->assertStringStartsWith('CÁLCULO AUTÓNOMO', (string) $autonomo[0][1]);
        $this->assertNotContains('VALOR AUTÓNOMO (SIN INFORME DEL MES)', array_column($autonomo, 0));
        // Con informe del mes: la fuente cambia de forma explícita y se muestran el valor autónomo y el ajuste.
        $this->assertStringStartsWith('CÁLCULO CON INFORME DEL MES — CONCILIACIÓN PARCIAL: informe administrativo de 2026-09', (string) $conciliado[0][1]);
        $this->assertSame(['VALOR AUTÓNOMO (SIN INFORME DEL MES)', 'AJUSTE RESPALDADO POR LA CONCILIACIÓN DEL MES'], [$conciliado[1][0], $conciliado[2][0]]);
        $this->assertSame(0.0, (float) $conciliado[2][1]);
    }

    public function test_missing_administrative_source_stops_the_report_with_a_clear_error(): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();
        $json = ['archivos' => [UploadedFile::fake()->createWithContent('a.json', $this->routeJson('FAC-1', 'C61X', '2026-09-10 08:00', 'consultas'))]];
        config(['programas.la-maria.informes_administrativos.requerido' => true]);

        $this->from(route('la-maria.prostata'))->actingAs($user)->post(route('la-maria.prostata.export'), $json)
            ->assertRedirect(route('la-maria.prostata'))
            ->assertSessionHasErrors(['informes_administrativos' => 'Falta el informe administrativo histórico de PRÓSTATA: cárguelo en el formulario o configure LA_MARIA_INFORME_ADMIN_PROSTATA. Sin esa fuente la estimación administrativa quedaría incompleta, por eso no se generó el reporte.']);

        config(['programas.la-maria.informes_administrativos.CERVIX' => storage_path('framework/testing/no-existe.xlsx')]);
        $this->from(route('la-maria'))->actingAs($user)->post(route('la-maria.consolidado.export'), $json)
            ->assertSessionHasErrors(['informes_administrativos' => 'No se encontró el informe administrativo configurado para CÉRVIX (no-existe.xlsx). Revise LA_MARIA_INFORME_ADMIN_CERVIX o cargue el informe en el formulario. No se generó un resultado incompleto.']);

        $this->assertSame(0, DB::table('programa_ejecuciones')->count());
    }

    public function test_required_economic_mode_rejects_export_without_active_clinical_catalogues(): void
    {
        $user = User::factory()->create();
        config(['programas.la-maria.informes_administrativos.requerido' => true]);
        $json = ['archivos' => [UploadedFile::fake()->createWithContent(
            'a.json', $this->routeJson('FAC-1', 'C61X', '2026-09-10 08:00', 'consultas')
        )]];

        $this->actingAs($user)->post(route('la-maria.prostata.export'), $json)
            ->assertSessionHasErrors(['archivos' => 'No hay catálogos clínicos activos de La María. Active las referencias verificadas antes de generar el consolidado; no se produjo un valor económico incompleto.']);

        $this->assertSame(0, DB::table('programa_ejecuciones')->count());
    }

    /**
     * RN-14, RN-06 (T34004) y la procedencia del precio AM se aplican en las exportaciones
     * habituales de Próstata y Cérvix en cualquier mes (aquí octubre), sin excepciones de fecha.
     */
    public function test_regular_route_exports_apply_the_generalized_rules_in_any_month(): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();
        $this->createCumCatalog('19979158-2', 'B05XA03', 'SODIO CLORURO');
        CatalogoReferenciaItem::create([
            'catalogo_referencia_id' => CatalogoReferencia::query()->where('tipo', 'MEDICAMENTOS_CUM')->firstOrFail()->id,
            'codigo' => '20085509-2', 'categoria' => 'CUM', 'descripcion' => 'APREPITANT',
            'metadatos' => ['atc' => 'A04AD12', 'principio_activo' => 'APREPITANT'],
        ]);
        $this->createDispensationCatalog('9988776655', '19979158-2', '2026-10-05', 1000);
        CatalogoReferenciaItem::create([
            'catalogo_referencia_id' => CatalogoReferencia::query()->where('tipo', 'DISPENSACION_PROSTATA')->firstOrFail()->id,
            'codigo' => '20085509-2', 'ruta' => 'PROSTATA', 'categoria' => 'DISPENSACION_ADMINISTRATIVA', 'descripcion' => 'Medicamento dispensado',
            'tarifa_referencia' => 250, 'metadatos' => ['documento_paciente' => '5544332211', 'fecha_dispensacion' => '2026-08-05'],
        ]);
        $cervix = CatalogoReferencia::create(['programa_slug' => LaMariaPrograma::SLUG, 'tipo' => 'DISPENSACION_CERVIX', 'version' => '2026-01',
            'archivo_origen' => 'dispensacion-cervix.xlsx', 'activo' => true]);
        CatalogoReferenciaItem::create([
            'catalogo_referencia_id' => $cervix->id, 'codigo' => '19979158-2', 'ruta' => 'CERVIX', 'categoria' => 'DISPENSACION_ADMINISTRATIVA',
            'descripcion' => 'Medicamento dispensado', 'tarifa_referencia' => 1000,
            'metadatos' => ['documento_paciente' => '1122334455', 'fecha_dispensacion' => '2026-10-06'],
        ]);
        $traslados = [['T34004', 'AT', 300000], ['T34004', 'AT', 300000], ['T34004', 'AT', 300000]];
        // RN-14 en Cérvix: 992511 con clase pembrolizumab (distintiva) y clase carboplatino/paclitaxel.
        $integrales = [...array_fill(0, 3, ['992511', 'AP', 32000000, 'PEMBROLIZUMAB + CARBOPLATINO']), ...array_fill(0, 7, ['992511', 'AP', 2000000, 'CARBOPLATINO + PACLITAXEL'])];
        $informes = ['INFORME PROSTATA.xlsx' => $this->administrativeReport($traslados), 'INFORME CERVIX.xlsx' => $this->administrativeReport([...$traslados, ...$integrales], 'C53X')];
        CatalogoReferenciaItem::create(['catalogo_referencia_id' => CatalogoReferencia::query()->where('tipo', 'NOTA_CERVIX')->firstOrFail()->id,
            'codigo' => '992511', 'ruta' => 'CERVIX', 'categoria' => 'ONCOLOGIA_QUIMIOTERAPIA', 'descripcion' => 'MONOTERAPIA ANTINEOPLASICA DE ALTA TOXICIDAD',
            'tarifa_referencia' => 444690, 'metadatos' => ['tarifa_medicamento' => 1431182.14, 'esquema' => 'Pembrolizumab, Carboplatino']]);
        foreach ([['CUM-PEMB', 'PEMBROLIZUMAB'], ['CUM-CARB', 'CARBOPLATINO']] as [$cum, $principio]) {
            CatalogoReferenciaItem::create(['catalogo_referencia_id' => CatalogoReferencia::query()->where('tipo', 'MEDICAMENTOS_CUM')->firstOrFail()->id,
                'codigo' => $cum, 'categoria' => 'CUM', 'descripcion' => $principio, 'metadatos' => ['atc' => 'L01', 'principio_activo' => $principio]]);
        }
        CatalogoReferenciaItem::create(['catalogo_referencia_id' => $cervix->id, 'codigo' => 'CUM-CARB', 'ruta' => 'CERVIX', 'categoria' => 'DISPENSACION_ADMINISTRATIVA',
            'descripcion' => 'Medicamento dispensado', 'tarifa_referencia' => 100, 'metadatos' => ['documento_paciente' => '7766554433', 'fecha_dispensacion' => '2026-09-05']]);
        $medicamento = fn (string $cum, string $nombre, int $consecutivo, string $fecha, string $diagnostico): array => [
            ...$this->medicamento($cum, $nombre, 2, $consecutivo), 'fechaDispensAdmon' => $fecha, 'codDiagnosticoPrincipal' => $diagnostico,
        ];
        $traslado = fn (string $diagnostico, string $fecha): array => [...$this->otroServicio('T34004', $diagnostico, $fecha, 3), 'cantidadOS' => 3];
        $files = ['octubre.json' => $this->rips('FAC-OCT', [
            $this->usuario('9988776655', 'M', [
                'consultas' => [$this->consulta('890201', 'C61X', '2026-10-10 08:00')],
                'otrosServicios' => [$traslado('C61X', '2026-10-12 09:00')],
                'medicamentos' => [$medicamento('19979158-2', 'SODIO CLORURO', 4, '2026-10-10 09:00', 'C61X'),
                    $medicamento('20085509-2', 'APREPITANT', 5, '2026-10-11 09:00', 'C61X')],
            ]),
            $this->usuario('1122334455', 'F', [
                'procedimientos' => [[...$this->procedimiento('992511', '2026-10-13 08:00', 7), 'codDiagnosticoPrincipal' => 'C53X']],
                'otrosServicios' => [$traslado('C53X', '2026-10-13 09:00')],
                'medicamentos' => [$medicamento('19979158-2', 'SODIO CLORURO', 6, '2026-10-14 09:00', 'C53X'),
                    [...$medicamento('CUM-PEMB', 'PEMBROLIZUMAB', 8, '2026-10-13 10:00', 'C53X'), 'cantidadMedicamento' => 2],
                    [...$medicamento('CUM-CARB', 'CARBOPLATINO', 9, '2026-10-13 10:00', 'C53X'), 'cantidadMedicamento' => 3]],
            ]),
        ])];

        try {
            $workbooks = [
                'prostata' => $this->exportWorkbook($user, 'prostata', $files, informes: $informes),
                'cervix' => $this->exportWorkbook($user, 'cervix', $files, informes: $informes),
            ];
            $costoAProstata = (float) $this->sessionRows('prostata', 'costos', ResumenEconomico::HEADERS)[0]['COSTO RESPALDADO'];
        } finally {
            File::delete(array_values($informes));
        }

        foreach (['prostata' => ['PROSTATA', 500.0, 2000.0], 'cervix' => ['CERVIX', 0.0, 2300.0]] as $ruta => [$codigo, $centralAm, $condicionadoAm]) {
            $workbook = $workbooks[$ruta];
            $comparison = $this->internalComparisonRows($workbook)[$codigo]['2026-10'];

            $this->assertSame('CONTROL DE CARGUE', $workbook->getSheetNames()[array_key_last($workbook->getSheetNames())]);
            $this->assertNull($workbook->getSheetByName('COBERTURA AM RN14'));
            $this->assertSame(900000.0, (float) $comparison['AT']['ESTIMACION ADMINISTRATIVA CENTRAL']);
            $this->assertSame($centralAm, (float) $comparison['AM']['ESTIMACION ADMINISTRATIVA CENTRAL']);
            $this->assertSame($condicionadoAm, (float) $comparison['AM']['COMPONENTE CONDICIONADO']);
            $this->assertSame(0.0, (float) $comparison['AM']['COSTO A']);
        }

        $this->assertSame(5000.0, $costoAProstata);
        $this->assertSame(32000000.0, (float) $this->internalComparisonRows($workbooks['cervix'])['CERVIX']['2026-10']['AP']['ESTIMACION ADMINISTRATIVA CENTRAL']);
    }

    /**
     * Celda de valor (columna D) de la fila de SEGUIMIENTO MENSUAL cuyo texto en A es $label,
     * o la celda A si la fila es un título.
     */
    private function firstSheetCell(Spreadsheet $workbook, string $label): ?Cell
    {
        $sheet = $workbook->getSheetByName('SEGUIMIENTO MENSUAL');

        foreach ($sheet->getRowIterator() as $row) {
            $index = $row->getRowIndex();

            if ((string) $sheet->getCell('A'.$index)->getValue() === $label) {
                return $sheet->getCell(($sheet->getCell('D'.$index)->getValue() === null ? 'A' : 'D').$index);
            }
        }

        return null;
    }

    /**
     * Bloques del resumen económico de SEGUIMIENTO MENSUAL por título («RUTA · MES»).
     *
     * @return array<string, array{servicios: array<string, array<string, mixed>>, indicadores: array<string, array{valor: mixed, descripcion: string}>, urgencias: array<string, array{valor: mixed, descripcion: string}>}>
     */
    private function serviceSummary(Spreadsheet $workbook): array
    {
        $prefix = 'RESUMEN ECONÓMICO POR SERVICIO · ';
        $summary = [];
        $title = $block = null;
        $headers = [];

        foreach ($workbook->getSheetByName('SEGUIMIENTO MENSUAL')->toArray(null, true, false, false) as $row) {
            $first = (string) ($row[0] ?? '');

            if (array_filter($row, static fn (mixed $value): bool => $value !== null && $value !== '') === []) {
                $block = null;

                continue;
            }

            if (str_starts_with($first, $prefix)) {
                $title = substr($first, strlen($prefix));

                continue;
            }

            $next = ['TIPO RIPS' => 'servicios', 'INDICADOR' => 'indicadores', 'URGENCIAS (AU)' => 'urgencias'][$first] ?? null;

            // La vista gerencial también empieza con 'TIPO RIPS', pero sin la columna REGISTROS.
            if ($next === 'servicios' && ($row[2] ?? '') !== 'REGISTROS') {
                $block = null;

                continue;
            }

            if ($title !== null && $next !== null) {
                $headers = array_map(static fn (mixed $value): string => (string) $value, $row);
                $headers = array_slice($headers, 0, (int) array_key_last(array_filter($headers, static fn (string $header): bool => $header !== '')) + 1);
                $block = $next;

                continue;
            }

            if ($title === null || $block === null) {
                continue;
            }

            $summary[$title][$block][$first] = $block === 'servicios'
                ? array_combine($headers, array_slice(array_map(static fn (mixed $value): mixed => $value ?? '', $row), 0, count($headers)))
                : ['valor' => $row[3], 'descripcion' => (string) $row[4]];
        }

        return $summary;
    }

    /**
     * Filas de cálculo que la ejecución deja en sesión para la pantalla.
     *
     * @param  list<string>  $headers
     * @return list<array<string, mixed>>
     */
    private function sessionRows(string $clave, string $campo, array $headers): array
    {
        return array_map(static fn (array $row): array => array_combine($headers, $row), session('la-maria.resultado.'.$clave)[$campo] ?? []);
    }

    /**
     * Informe administrativo mínimo con la hoja «inf admin», incluida una columna de documento.
     *
     * @param  list<array{0: string, 1: string, 2: int|float, 3?: string}>  $servicios  Código, tipo, valor y medicamento del esquema.
     * @param  list<string>  $fechas  Fechas de prestación de las filas (por defecto, junio a agosto).
     */
    private function administrativeReport(array $servicios, string $diagnostico = 'C61X', array $fechas = ['2026-06-15', '2026-07-15', '2026-08-15']): string
    {
        $path = storage_path('framework/testing/inf-admin-'.Str::uuid().'.xlsx');
        File::ensureDirectoryExists(dirname($path));
        $writer = new XlsxWriter;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('inf admin ');
        $writer->addRow(OpenSpoutRow::fromValues(['MES', 'DOCUMENTO', 'CUPS', 'DESCRIPCION DEL SERVICIO', 'DIACODIGO',
            'FECHA_PRESTACION_SERVICIO', 'GRUPO DEL SERVICIO', 'CLASIFICACIÓN RIPS ', 'MEDICAMENTO ', 'VALOR']));

        foreach ($fechas as $fecha) {
            foreach ($servicios as $servicio) {
                [$codigo, $tipo, $valor] = $servicio;
                $writer->addRow(OpenSpoutRow::fromValues(['', '70975586', $codigo, 'Servicio '.$codigo, $diagnostico, $fecha, 'Grupo', $tipo, $servicio[3] ?? '', $valor]));
            }
        }

        $writer->close();

        return $path;
    }

    private function exportWorkbook(User $user, string $ejecucion, array $files, ?string $sql = null, bool $declare = false, array $informes = []): Spreadsheet
    {
        $uploads = [];

        foreach ($files as $name => $content) {
            $uploads[] = UploadedFile::fake()->createWithContent($name, $content);
        }

        $payload = ['archivos' => $uploads];

        if ($declare) {
            $payload['archivos_declarados'] = json_encode(array_map(
                static fn (UploadedFile $upload): array => ['nombre' => $upload->getClientOriginalName(), 'tamano' => $upload->getSize()],
                $uploads
            ));
        }

        if ($sql !== null) {
            $payload['sql_archivo'] = UploadedFile::fake()->createWithContent('administrativo.sql', $sql);
        }

        foreach ($informes as $name => $path) {
            $payload['informes_administrativos'][] = new UploadedFile($path, $name, null, null, true);
        }

        $response = $this->actingAs($user)->post(route('la-maria.'.$ejecucion.'.export'), $payload);
        $response->assertDownload();

        $exportPath = storage_path('framework/testing/'.Str::uuid().'.xlsx');
        File::ensureDirectoryExists(dirname($exportPath));
        File::put($exportPath, $response->streamedContent());

        try {
            $workbook = IOFactory::load($exportPath);
            $this->economicSnapshots[spl_object_id($workbook)] = session('la-maria.resultado.'.$ejecucion, []);

            return $workbook;
        } finally {
            File::delete($exportPath);
        }
    }

    /**
     * Combina la tabla visible de actividad con los totales económicos de la sesión
     * para las pruebas económicas heredadas; el Excel conserva solo tres columnas.
     *
     * @return list<array<string, mixed>>
     */
    private function monthlyRowsWithInternalEconomics(Spreadsheet $workbook): array
    {
        $result = $this->economicSnapshots[spl_object_id($workbook)] ?? [];
        $costs = collect($result['costos'] ?? [])->keyBy(1);
        $review = $workbook->getSheetByName('CASOS A REVISAR');
        $reviews = $review === null ? [] : $this->records($review);

        return array_map(function (array $row) use ($costs, $reviews): array {
            $month = (string) $row['MES'];
            $cost = $costs[$month] ?? [];
            $reviewCount = count(array_filter($reviews, static function (array $reviewRow) use ($month): bool {
                $date = (string) ($reviewRow['fecha (fechaInicioAtencion / fechaDispensAdmon / fechaSuministroTecnologia)'] ?? '');

                return $month === 'SIN FECHA' ? $date === '' : str_starts_with($date, $month);
            }));

            return [
                ...$row,
                'COSTO ACTIVO (A + CONDICIONADO)' => (float) ($cost[9] ?? 0),
                'CASOS A REVISAR' => $reviewCount,
                'SERVICIOS SIN TARIFA APLICADA' => (int) ($cost[13] ?? 0),
            ];
        }, $this->records($workbook->getSheetByName('SEGUIMIENTO MENSUAL')));
    }

    /** @return array<string, array<string, array<string, mixed>>> */
    private function internalComparisonRows(Spreadsheet $workbook): array
    {
        $rows = $this->economicSnapshots[spl_object_id($workbook)]['estimacion'] ?? [];
        $headers = ['RUTA', ...ValorizacionAdministrativa::COMPARACION_HEADERS];
        $result = [];

        foreach ($rows as $row) {
            $record = array_combine($headers, $row);
            $result[$record['RUTA']][$record['MES']][$record['TIPO RIPS']] = $record;
        }

        return $result;
    }

    /**
     * Lee una hoja como registros indexados por su encabezado hasta la primera fila vacía.
     *
     * @return list<array<string, mixed>>
     */
    private function records(?Worksheet $sheet): array
    {
        $this->assertNotNull($sheet);
        $rows = $sheet->toArray(null, true, false, false);
        $header = array_map(static fn (mixed $value): string => (string) $value, array_shift($rows) ?? []);
        $records = [];

        foreach ($rows as $row) {
            if (array_filter($row, static fn (mixed $value): bool => $value !== null && $value !== '') === []) {
                break;
            }

            $records[] = array_combine($header, array_map(
                static fn (mixed $value): mixed => $value ?? '',
                $row
            ));
        }

        return $records;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function conciliation(Spreadsheet $workbook): array
    {
        $sheet = $workbook->getSheetByName('CONTROL DE CARGUE');
        $this->assertNotNull($sheet);
        $rows = $sheet->toArray(null, true, false, false);
        $start = null;

        foreach ($rows as $index => $row) {
            if (($row[0] ?? null) === 'CONCILIACION') {
                $start = $index;

                break;
            }
        }

        $this->assertNotNull($start, 'CONTROL DE CARGUE no contiene el bloque de conciliación.');
        $header = array_map(static fn (mixed $value): string => (string) $value, $rows[$start]);
        $concepts = [];

        for ($index = $start + 1; $index < count($rows); $index++) {
            $concept = (string) ($rows[$index][0] ?? '');

            if ($concept !== '') {
                $concepts[$concept] = array_combine($header, $rows[$index]);
            }
        }

        return $concepts;
    }

    /**
     * @return array<string, int>
     */
    private function patientConciliation(Spreadsheet $workbook): array
    {
        return collect($this->conciliation($workbook))
            ->filter(static fn (array $row, string $concept): bool => str_contains($concept, 'PACIENTES') && $concept !== 'CONCILIACION PACIENTES')
            ->map(static fn (array $row): int => (int) $row['SERVICIOS'])
            ->all();
    }

    /**
     * Paciente hospitalizado del 1 al 31 de octubre con una consulta tarifada en la fecha indicada.
     *
     * @return array<string, mixed>
     */
    private function hospitalPayload(string $fechaConsulta): array
    {
        $payload = json_decode(
            $this->routeJson('FAC-HOSP', 'C61X', $fechaConsulta, 'consultas'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $payload['usuarios'][0]['servicios']['hospitalizacion'] = [[
            'codPrestador' => '050010608601',
            'viaIngresoServicioSalud' => '01',
            'fechaInicioAtencion' => '2026-10-01',
            'fechaEgreso' => '2026-10-31',
            'codDiagnosticoPrincipal' => 'C61X',
            'consecutivo' => 22,
        ]];

        return $payload;
    }

    /**
     * Filas del bloque CONTROL HOSPITALARIO de CONTROL DE CARGUE, por mes y TOTAL.
     *
     * @return array<string, array<string, mixed>>
     */
    private function hospitalControl(Spreadsheet $workbook): array
    {
        return $this->controlBlock($workbook, 'CONTROL HOSPITALARIO (MES)');
    }

    /**
     * Bloque EPISODIOS HOSPITALARIOS de CONTROL DE CARGUE: concepto → cantidad.
     *
     * @return array<string, int>
     */
    private function hospitalEpisodes(Spreadsheet $workbook): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['CANTIDAD'],
            $this->controlBlock($workbook, 'EPISODIOS HOSPITALARIOS')
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function controlBlock(Spreadsheet $workbook, string $title): array
    {
        $sheet = $workbook->getSheetByName('CONTROL DE CARGUE');
        $this->assertNotNull($sheet);
        $rows = $sheet->toArray(null, true, false, false);
        $start = null;

        foreach ($rows as $index => $row) {
            if (($row[0] ?? null) === $title) {
                $start = $index;

                break;
            }
        }

        $this->assertNotNull($start, 'CONTROL DE CARGUE no contiene el bloque '.$title.'.');
        $header = array_map(static fn (mixed $value): string => (string) $value, $rows[$start]);
        $control = [];

        for ($index = $start + 1; $index < count($rows) && (string) ($rows[$index][0] ?? '') !== ''; $index++) {
            $control[(string) $rows[$index][0]] = array_combine($header, $rows[$index]);
        }

        return $control;
    }

    /**
     * RIPS de un paciente de próstata hospitalizado con los servicios indicados.
     *
     * @param  array<string, array<int, array<string, mixed>>>  $servicios
     */
    private function hospitalRips(
        array $servicios,
        string $ingreso = '2026-10-01 08:00',
        string $egreso = '2026-10-11 08:00',
        string $destino = '01',
        string $factura = 'FAC-HX'
    ): string {
        $servicios['hospitalizacion'] = [[
            'codPrestador' => '050010608601',
            'viaIngresoServicioSalud' => '01',
            'fechaInicioAtencion' => $ingreso,
            'fechaEgreso' => $egreso,
            'codDiagnosticoPrincipal' => 'C61X',
            'condicionDestinoUsuarioEgreso' => $destino, 'numAutorizacion' => 'AUT-1',
            'consecutivo' => 1,
        ]];

        return $this->rips($factura, [$this->usuario('100', 'M', $servicios)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function procedimiento(string $codigo, string $fecha, int $consecutivo, string $tipo = 'procedimientos'): array
    {
        return [
            'codPrestador' => '050010608601',
            'fechaInicioAtencion' => $fecha,
            $tipo === 'consultas' ? 'codConsulta' : 'codProcedimiento' => $codigo,
            'codDiagnosticoPrincipal' => 'C61X',
            'vrServicio' => 0,
            'consecutivo' => $consecutivo,
        ];
    }

    private function createTechnicalItem(string $codigo, string $descripcion, float $tarifa): void
    {
        CatalogoReferenciaItem::create([
            'catalogo_referencia_id' => CatalogoReferencia::query()->where('tipo', 'NOTA_PROSTATA')->firstOrFail()->id,
            'codigo' => $codigo,
            'ruta' => 'PROSTATA',
            'categoria' => 'SERVICIO_TECNICO',
            'descripcion' => $descripcion,
            'tarifa_referencia' => $tarifa,
        ]);
    }

    private function createHospitalServiceCatalog(): void
    {
        $prostata = CatalogoReferencia::query()->where('tipo', 'NOTA_PROSTATA')->firstOrFail();

        foreach ([
            ['902210', 'HEMOGRAMA IV', 1000],
            ['550202', 'NEFROSTOMIA VIA PERCUTANEA', 100000],
            ['992505', 'POLITERAPIA ANTINEOPLASICA DE ALTA TOXICIDAD', 444690],
            ['883440', 'RESONANCIA MAGNETICA DE PELVIS', 500000],
            ['920901', 'GAMAGRAFIA OSEA', 300000],
            ['922444', 'TELETERAPIA CON ACELERADOR LINEAL', 8852976],
            ['921401', 'TOMOGRAFIA POR EMISION DE POSITRONES [PET-TC]', 2000000],
            ['911107', 'PROCESAMIENTO DE LA UNIDAD DE CONCENTRADO DE GLOBULOS ROJOS', 566200],
            ['DM00114', 'DISPOSITIVO MEDICO', 700],
        ] as [$codigo, $descripcion, $tarifa]) {
            CatalogoReferenciaItem::create([
                'catalogo_referencia_id' => $prostata->id,
                'codigo' => $codigo,
                'ruta' => 'PROSTATA',
                'categoria' => 'SERVICIO_TECNICO',
                'descripcion' => $descripcion,
                'tarifa_referencia' => $tarifa,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $presentacion
     */
    private function createCumCatalog(string $cum, string $atc, string $principioActivo, array $presentacion = []): void
    {
        $catalogo = CatalogoReferencia::create([
            'programa_slug' => LaMariaPrograma::SLUG,
            'tipo' => 'MEDICAMENTOS_CUM',
            'version' => '2026-01',
            'archivo_origen' => 'cum.xlsx',
            'activo' => true,
        ]);
        CatalogoReferenciaItem::create([
            'catalogo_referencia_id' => $catalogo->id,
            'codigo' => $cum,
            'categoria' => 'CUM',
            'descripcion' => $principioActivo,
            'metadatos' => ['atc' => $atc, 'principio_activo' => $principioActivo, ...$presentacion],
        ]);
    }

    public function test_ium_resolved_medication_during_a_stay_stays_absorbed_by_the_package(): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();
        $this->createMedicationCatalog();
        CatalogoReferenciaItem::create([
            'catalogo_referencia_id' => CatalogoReferencia::query()->where('tipo', 'NOTA_MEDICAMENTOS')->firstOrFail()->id,
            'codigo' => '19935303-4',
            'ruta' => 'PROSTATA',
            'categoria' => 'MEDICAMENTO_NT_PALIATIVO',
            'descripcion' => 'PARACETAMOL 500 MG TABLETA',
            'tarifa_referencia' => 87967,
            'metadatos' => ['principio_activo' => 'PARACETAMOL'],
        ]);
        $this->createCumCatalog('19935303-4', 'N02BE01', 'PARACETAMOL', [
            'ium' => '1A1027731000100', 'cantidad' => 500, 'unidad_medida' => 'mg', 'forma_farmaceutica' => 'TABLETA', 'cantidad_cum' => 100, 'muestra_medica' => 'No',
        ]);
        $inside = $this->medicamento('1A1027731000100', 'PARACETAMOL', 120, 2);
        $inside['fechaDispensAdmon'] = '2026-10-05 09:00';
        $outside = $this->medicamento('1A1027731000100', 'PARACETAMOL', 120, 3);
        $outside['fechaDispensAdmon'] = '2026-10-20 09:00';

        $workbook = $this->exportWorkbook($user, 'prostata', [
            'ium.json' => $this->hospitalRips(['medicamentos' => [$inside, $outside]]),
        ]);

        $costs = collect($this->records($workbook->getSheetByName('DETALLE RIPS')))
            ->where('tipoServicio', 'medicamentos')
            ->map(static fn (array $row): string => (string) $row['COSTO'])
            ->values()
            ->all();

        $this->assertSame(['', (string) (87967 * 120)], $costs);
        $this->assertSame(round(87967.0 * 120 + PoliticaPaqueteHospitalario::VALOR_PAQUETE, 2), $this->summaryTotal($workbook, 'COSTO ACTIVO (A + CONDICIONADO)'));
    }

    public function test_the_same_rips_give_the_same_result_processed_together_separately_or_twice(): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();
        $june = $this->rips('FAC-JUN', [$this->usuario('900', 'M', ['consultas' => [$this->consulta('890201', 'C61X', '2026-06-10 08:00')]])]);
        $august = $this->rips('FAC-AGO', [
            $this->usuario('900', 'M', ['consultas' => [$this->consulta('890201', 'C61X', '2026-08-10 08:00')]]),
            $this->usuario('901', 'M', ['consultas' => [$this->consulta('890201', 'C61X', '2026-08-11 08:00', 2)]]),
        ]);
        $months = fn (Spreadsheet $workbook): array => collect($this->monthlyRowsWithInternalEconomics($workbook))
            ->mapWithKeys(static fn (array $row): array => [(string) $row['MES'] => [(float) $row['COSTO ACTIVO (A + CONDICIONADO)'], (int) $row['SERVICIOS'], (int) $row['PACIENTES']]])
            ->all();

        $together = $months($this->exportWorkbook($user, 'prostata', ['junio.json' => $june, 'agosto.json' => $august]));
        $again = $months($this->exportWorkbook($user, 'prostata', ['junio.json' => $june, 'agosto.json' => $august]));
        $separate = [
            ...$months($this->exportWorkbook($user, 'prostata', ['junio.json' => $june])),
            ...$months($this->exportWorkbook($user, 'prostata', ['agosto.json' => $august])),
        ];

        $this->assertSame(['2026-06' => [5000.0, 1, 1], '2026-08' => [10000.0, 2, 2]], $together);
        $this->assertSame($together, $again);
        $this->assertSame($together, $separate);
    }

    /**
     * Decisión aprobada (14/10/2026): en POR_LOTE cada mes es un lote económico
     * independiente. Un corte 08 que continúa en el mes siguiente queda como
     * continuidad pendiente y cada mes conserva su paquete, igual que si los
     * meses se procesaran por separado. El libro PERSISTENTE no cambia.
     */
    public function test_billing_cut_stay_across_months_keeps_each_month_as_an_independent_economic_batch(): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();
        $june = $this->hospitalRips([], '2026-06-20 08:00', '2026-06-30 23:59', '08', 'FAC-JUN');
        $july = $this->hospitalRips([], '2026-07-01 00:00', '2026-07-10 08:00', '01', 'FAC-JUL');
        $months = fn (Spreadsheet $workbook): array => collect($this->monthlyRowsWithInternalEconomics($workbook))
            ->mapWithKeys(static fn (array $row): array => [(string) $row['MES'] => (float) $row['COSTO ACTIVO (A + CONDICIONADO)']])
            ->all();

        $together = $this->exportWorkbook($user, 'prostata', ['junio.json' => $june, 'julio.json' => $july]);
        $first = $this->exportWorkbook($user, 'prostata', ['junio.json' => $june]);
        $second = $this->exportWorkbook($user, 'prostata', ['julio.json' => $july]);

        $this->assertSame(2, $this->hospitalEpisodes($together)['PAQUETES GENERADOS']);
        $this->assertSame(1, $this->hospitalEpisodes($together)['CONTINUIDADES PENDIENTES']);
        $this->assertSame(1, $this->hospitalEpisodes($first)['CONTINUIDADES PENDIENTES']);
        $this->assertSame(2, $this->hospitalEpisodes($first)['PAQUETES GENERADOS'] + $this->hospitalEpisodes($second)['PAQUETES GENERADOS']);
        $this->assertSame(['2026-06' => PoliticaPaqueteHospitalario::VALOR_PAQUETE, '2026-07' => PoliticaPaqueteHospitalario::VALOR_PAQUETE], $months($together));
        $this->assertSame($months($together), [...$months($first), ...$months($second)]);
    }

    private function summaryTotal(Spreadsheet $workbook, string $column): float
    {
        return (float) collect($this->monthlyRowsWithInternalEconomics($workbook))
            ->sum(static fn (array $row): float => (float) $row[$column]);
    }

    private function detailCost(Spreadsheet $workbook): float
    {
        return (float) collect($this->records($workbook->getSheetByName('DETALLE RIPS')))
            ->sum(static fn (array $row): float => (float) $row['COSTO']);
    }

    /**
     * @return list<string>
     */
    private function headers(?Worksheet $sheet): array
    {
        $this->assertNotNull($sheet);

        return array_values(array_filter(
            array_map(
                static fn (mixed $value): string => (string) $value,
                $sheet->rangeToArray('A1:'.$sheet->getHighestColumn().'1', null, false, false)[0]
            ),
            static fn (string $header): bool => $header !== ''
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>  $usuarios
     */
    private function rips(string $factura, array $usuarios): string
    {
        return json_encode([
            'numDocumentoIdObligado' => '890905177',
            'numFactura' => $factura,
            'tipoNota' => null,
            'numNota' => null,
            'usuarios' => $usuarios,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $servicios
     * @return array<string, mixed>
     */
    private function usuario(string $documento, string $sexo, array $servicios): array
    {
        return [
            'tipoDocumentoIdentificacion' => 'CC',
            'numDocumentoIdentificacion' => $documento,
            'codSexo' => $sexo,
            'fechaNacimiento' => '1960-01-01',
            'codMunicipioResidencia' => '05001',
            'servicios' => $servicios,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function consulta(string $codigo, string $diagnostico, ?string $fecha, int $consecutivo = 1): array
    {
        return array_filter([
            'codPrestador' => '050010608601',
            'fechaInicioAtencion' => $fecha,
            'codConsulta' => $codigo,
            'codDiagnosticoPrincipal' => $diagnostico,
            'vrServicio' => 0,
            'consecutivo' => $consecutivo,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    private function otroServicio(string $codigo, string $diagnostico, string $fecha, int $consecutivo = 1): array
    {
        return [
            'codPrestador' => '050010608601',
            'fechaSuministroTecnologia' => $fecha,
            'codTecnologiaSalud' => $codigo,
            'nomTecnologiaSalud' => 'Otro servicio',
            'codDiagnosticoPrincipal' => $diagnostico,
            'cantidadOS' => 1,
            'vrServicio' => 0,
            'consecutivo' => $consecutivo,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function medicamento(string $codigo, string $nombre, int $cantidad, int $consecutivo): array
    {
        return [
            'codPrestador' => '050010608601',
            'fechaDispensAdmon' => '2026-07-10 09:00',
            'codDiagnosticoPrincipal' => 'C61X',
            'codTecnologiaSalud' => $codigo,
            'nomTecnologiaSalud' => $nombre,
            'cantidadMedicamento' => $cantidad,
            'vrServicio' => 0,
            'consecutivo' => $consecutivo,
        ];
    }

    private function routeJson(
        string $factura,
        string $diagnostico,
        string $fechaAtencion,
        string $tipoServicio,
        string $sexo = 'M'
    ): string {
        $servicio = match ($tipoServicio) {
            'consultas' => [
                'codPrestador' => '050010608601',
                'codConsulta' => '890201',
                'finalidadTecnologiaSalud' => '11',
                'causaMotivoAtencion' => '38',
                'codDiagnosticoPrincipal' => $diagnostico,
                'fechaInicioAtencion' => $fechaAtencion,
            ],
            'procedimientos' => [
                'codPrestador' => '050010608601',
                'codProcedimiento' => '881201',
                'finalidadTecnologiaSalud' => '11',
                'causaMotivoAtencion' => '38',
                'codDiagnosticoPrincipal' => $diagnostico,
                'fechaInicioAtencion' => $fechaAtencion,
            ],
            'otrosServicios' => [
                'codPrestador' => '050010608601',
                'codTecnologiaSalud' => 'OS-001',
                'finalidadTecnologiaSalud' => '11',
                'causaMotivoAtencion' => '38',
                'codDiagnosticoPrincipal' => $diagnostico,
                'fechaSuministroTecnologia' => $fechaAtencion,
            ],
        };

        $servicio += [
            'nomTecnologiaSalud' => 'Servicio original del RIPS',
            'idMIPRES' => 'MIPRES-100',
            'modalidadGrupoServicioTecSal' => '01',
            'grupoServicios' => '01',
            'codServicio' => '101',
            'viaIngresoServicioSalud' => '01',
            'tipoMedicamento' => '01',
            'tipoOS' => '01',
            'concentracionMedicamento' => '10',
            'unidadMedida' => 'MG',
            'formaFarmaceutica' => 'TABLETA',
            'unidadMinDispensa' => 1,
            'diasTratamiento' => 5,
            'codDiagnosticoPrincipalCIE11' => '2C82',
            'nomCodDiagnosticoPrincipalCIE11' => 'Diagnóstico principal CIE11',
            'codDiagnosticoRelacionado1' => 'Z001',
            'codDiagnosticoRelacionado2' => 'Z002',
            'codDiagnosticoRelacionado1CIE11' => 'QA00',
            'nomCodDiagnosticoRelacionado1CIE11' => 'Diagnóstico relacionado CIE11',
            'tipoDiagnosticoPrincipal' => '01',
            'codComplicacion' => 'T001',
            'codComplicacionCIE11' => 'NE80',
            'nomCodComplicacionCIE11' => 'Complicación CIE11',
            'vrUnitMedicamento' => 2500,
            'vrDispensacion' => 5000,
            'conceptoRecaudo' => '05',
            'valorPagoModerador' => 1000,
            'numFEVPagoModerador' => 'FEV-100',
            'codigoVIDA' => 'VIDA-100',
            'consecutivo' => 21,
        ];

        return json_encode([
            'numFactura' => $factura,
            'usuarios' => [[
                'tipoDocumentoIdentificacion' => 'CC',
                'numDocumentoIdentificacion' => '100',
                'codSexo' => $sexo,
                'fechaNacimiento' => '1980-05-12',
                'codMunicipioResidencia' => '05001',
                'codZonaTerritorialResidencia' => '01',
                'tipoUsuario' => '01',
                'codPaisOrigen' => '170',
                'codPaisResidencia' => '170',
                'incapacidad' => 'NO',
                'registroSIRAS' => 'SIRAS-100',
                'consecutivo' => 7,
                'servicios' => [$tipoServicio => [$servicio]],
            ]],
        ], JSON_THROW_ON_ERROR);
    }

    private function createReferenceCatalogs(): void
    {
        $anexos = CatalogoReferencia::create([
            'programa_slug' => LaMariaPrograma::SLUG,
            'tipo' => 'ANEXOS_2706',
            'version' => '2026-01',
            'archivo_origen' => 'anexos.xlsx',
            'activo' => true,
        ]);
        CatalogoReferenciaItem::create([
            'catalogo_referencia_id' => $anexos->id,
            'codigo' => '890201',
            'categoria' => 'ANEXO_2',
            'descripcion' => 'Consulta oficial de prueba',
        ]);

        $prostata = CatalogoReferencia::create([
            'programa_slug' => LaMariaPrograma::SLUG,
            'tipo' => 'NOTA_PROSTATA',
            'version' => '2026-01',
            'archivo_origen' => 'prostata.xlsx',
            'activo' => true,
        ]);
        CatalogoReferenciaItem::create([
            'catalogo_referencia_id' => $prostata->id,
            'codigo' => '890201',
            'ruta' => 'PROSTATA',
            'categoria' => 'SERVICIO_TECNICO',
            'descripcion' => 'Consulta de referencia próstata',
            'tarifa_referencia' => 5000,
        ]);

        $cervix = CatalogoReferencia::create([
            'programa_slug' => LaMariaPrograma::SLUG,
            'tipo' => 'NOTA_CERVIX',
            'version' => '2026-01',
            'archivo_origen' => 'cervix.xlsx',
            'activo' => true,
        ]);
        CatalogoReferenciaItem::create([
            'catalogo_referencia_id' => $cervix->id,
            'codigo' => 'OS-001',
            'ruta' => 'CERVIX',
            'categoria' => 'SERVICIO_TECNICO',
            'descripcion' => 'Otro servicio de referencia cérvix',
            'tarifa_referencia' => 8000,
        ]);
    }

    private function createDispensationCatalog(string $documento, string $cum, string $fecha, float $tarifa): void
    {
        $dispensacion = CatalogoReferencia::create([
            'programa_slug' => LaMariaPrograma::SLUG,
            'tipo' => 'DISPENSACION_PROSTATA',
            'version' => '2026-01',
            'archivo_origen' => 'dispensacion.xlsx',
            'activo' => true,
        ]);
        CatalogoReferenciaItem::create([
            'catalogo_referencia_id' => $dispensacion->id,
            'codigo' => $cum,
            'ruta' => 'PROSTATA',
            'categoria' => 'DISPENSACION_ADMINISTRATIVA',
            'descripcion' => 'Medicamento dispensado',
            'tarifa_referencia' => $tarifa,
            'metadatos' => ['documento_paciente' => $documento, 'fecha_dispensacion' => $fecha],
        ]);
    }

    private function createMedicationCatalog(): void
    {
        $medicamentos = CatalogoReferencia::create([
            'programa_slug' => LaMariaPrograma::SLUG,
            'tipo' => 'NOTA_MEDICAMENTOS',
            'version' => '2026-01',
            'archivo_origen' => 'medicamentos.xlsx',
            'activo' => true,
        ]);

        foreach ([
            ['20254463-1', 'APALUTAMIDA 240 MG TABLETA RECUBIERTA', 339686],
            ['20137853-1', 'APALUTAMIDA 60 MG TABLETA RECUBIERTA', 87967],
        ] as [$cum, $descripcion, $tarifa]) {
            CatalogoReferenciaItem::create([
                'catalogo_referencia_id' => $medicamentos->id,
                'codigo' => $cum,
                'ruta' => 'PROSTATA',
                'categoria' => 'MEDICAMENTO_NT',
                'descripcion' => $descripcion,
                'tarifa_referencia' => $tarifa,
                'metadatos' => ['principio_activo' => 'APALUTAMIDA'],
            ]);
        }
    }
}
