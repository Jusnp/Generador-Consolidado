<?php

namespace Tests\Feature;

use App\Models\CatalogoReferencia;
use App\Models\CatalogoReferenciaItem;
use App\Models\User;
use App\Programas\LaMaria\LaMariaPrograma;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

class SeguimientoRutaControllerTest extends TestCase
{
    use RefreshDatabase;

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

        $summary = $this->records($workbook->getSheetByName('SEGUIMIENTO MENSUAL'));
        $patient = $this->records($workbook->getSheetByName('SEGUIMIENTO PACIENTE MES'))[0];
        $detailSheet = $workbook->getSheetByName('DETALLE RIPS');
        $detail = $this->records($detailSheet)[0];
        $detailHeaders = $this->headers($detailSheet);

        $this->assertCount(1, $summary);
        $this->assertSame('2026-06', $summary[0]['MES']);
        $this->assertSame(5000.0, (float) $summary[0]['COSTO TOTAL']);
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

        $summaryCost = $workbook->getSheetByName('SEGUIMIENTO MENSUAL')->getCell('D2');
        $this->assertSame('#,##0.00', $summaryCost->getStyle()->getNumberFormat()->getFormatCode());
        $this->assertEquals(5000, $summaryCost->getValue());

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
            ['MES', 'PACIENTES', 'SERVICIOS', 'COSTO TOTAL', 'CASOS A REVISAR', 'SERVICIOS SIN TARIFA APLICADA'],
            $this->headers($workbook->getSheetByName('SEGUIMIENTO MENSUAL'))
        );
        $this->assertSame(
            ['MES', 'tipoDocumentoIdentificacion', 'numDocumentoIdentificacion', 'codSexo', 'fechaNacimiento', 'codMunicipioResidencia', 'codZonaTerritorialResidencia', 'tipoUsuario', 'codDiagnosticoPrincipal (valores del mes)', 'numFactura (facturas del mes)', 'PRIMERA ATENCION', 'ULTIMA ATENCION', 'CONSULTAS', 'PROCEDIMIENTOS', 'MEDICAMENTOS', 'OTROS SERVICIOS', 'URGENCIAS', 'HOSPITALIZACIONES', 'TOTAL SERVICIOS', 'SERVICIOS SIN TARIFA APLICADA', 'COSTO TOTAL', 'OBSERVACION'],
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

    public function test_cervix_execution_uses_cervix_route_records_and_reconciles_the_other_route(): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();

        $workbook = $this->exportWorkbook($user, 'cervix', [
            'junio.json' => $this->routeJson('FAC-061', 'C61X', '2026-06-10', 'consultas'),
            'julio.json' => $this->routeJson('FAC-071', 'C531', '2026-07-08', 'otrosServicios', 'F'),
        ]);

        $summary = $this->records($workbook->getSheetByName('SEGUIMIENTO MENSUAL'));
        $conciliation = $this->conciliation($workbook);

        $this->assertCount(1, $summary);
        $this->assertSame('2026-07', $summary[0]['MES']);
        $this->assertSame(8000.0, (float) $summary[0]['COSTO TOTAL']);
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

        $summary = $this->records($workbook->getSheetByName('SEGUIMIENTO MENSUAL'))[0];
        $patients = $this->records($workbook->getSheetByName('SEGUIMIENTO PACIENTE MES'));
        $review = $this->records($workbook->getSheetByName('CASOS A REVISAR'));

        $this->assertSame(1, (int) $summary['PACIENTES']);
        $this->assertSame(5000.0, (float) $summary['COSTO TOTAL']);
        $this->assertSame(2, (int) $summary['CASOS A REVISAR']);
        $this->assertCount(1, $patients);
        $this->assertSame('100', (string) $patients[0]['numDocumentoIdentificacion']);
        $this->assertCount(2, $review);
        $this->assertSame('200', (string) $review[0]['numDocumentoIdentificacion']);
        $this->assertStringContainsString('DOBLE RUTA C61 Y C53', $review[0]['MOTIVO']);
    }

    public function test_hospital_services_reconcile_detail_patient_and_monthly_costs(): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();
        $payload = json_decode(
            $this->routeJson('FAC-HOSP', 'C61X', '2026-10-10', 'consultas'),
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

        $workbook = $this->exportWorkbook($user, 'prostata', [
            'hospitalario.json' => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);

        $detail = collect($this->records($workbook->getSheetByName('DETALLE RIPS')))->keyBy('tipoServicio');
        $patient = $this->records($workbook->getSheetByName('SEGUIMIENTO PACIENTE MES'))[0];
        $summary = $this->records($workbook->getSheetByName('SEGUIMIENTO MENSUAL'))[0];
        $detailCost = (float) $detail->sum(static fn (array $row): float => (float) $row['COSTO']);

        $this->assertSame(5000.0, $detailCost);
        $this->assertSame($detailCost, (float) $patient['COSTO TOTAL']);
        $this->assertSame($detailCost, (float) $summary['COSTO TOTAL']);
        $this->assertSame(2, (int) $patient['TOTAL SERVICIOS']);
        $this->assertSame(1, (int) $patient['SERVICIOS SIN TARIFA APLICADA']);
        $this->assertSame('', (string) $detail['hospitalizacion']['codigo (codConsulta / codProcedimiento / codTecnologiaSalud)']);
        $this->assertSame('2026-10-31', $detail['hospitalizacion']['fechaEgreso']);
        $this->assertSame('', (string) $detail['hospitalizacion']['COSTO']);
        $this->assertNotContains('NO ENCONTRADOS NOTA TECNICA', $workbook->getSheetNames());
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

        $summary = $this->records($workbook->getSheetByName('SEGUIMIENTO MENSUAL'))[0];
        $patient = $this->records($workbook->getSheetByName('SEGUIMIENTO PACIENTE MES'))[0];
        $detail = $this->records($workbook->getSheetByName('DETALLE RIPS'))[0];
        $review = $this->records($workbook->getSheetByName('CASOS A REVISAR'))[0];

        $this->assertSame('2026-08', $summary['MES']);
        $this->assertSame(1, (int) $summary['PACIENTES']);
        $this->assertSame(5000.0, (float) $summary['COSTO TOTAL']);
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

        $summary = $this->records($workbook->getSheetByName('SEGUIMIENTO MENSUAL'))[0];
        $review = $this->records($workbook->getSheetByName('CASOS A REVISAR'))[0];

        $this->assertSame(8000.0, (float) $summary['COSTO TOTAL']);
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
        $this->assertSame([], $this->records($workbook->getSheetByName('SEGUIMIENTO MENSUAL')));
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
        $this->assertSame(5000.0, (float) $this->records($prostata->getSheetByName('SEGUIMIENTO MENSUAL'))[0]['COSTO TOTAL']);
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
            $summary = $this->records($workbook->getSheetByName('SEGUIMIENTO MENSUAL'))[0];
            $conciliation = $this->conciliation($workbook);

            $this->assertSame([], $this->records($workbook->getSheetByName('SEGUIMIENTO PACIENTE MES')));
            $this->assertSame([], $this->records($workbook->getSheetByName('DETALLE RIPS')));
            $this->assertCount(2, $review);
            $this->assertStringContainsString('DOBLE RUTA C61 Y C53', $review['890201']['MOTIVO']);
            $this->assertStringContainsString('POTENCIAL PROSTATA $5000.00 / CERVIX $0.00', $review['890201']['MOTIVO']);
            $this->assertStringContainsString('POTENCIAL PROSTATA $0.00 / CERVIX $8000.00', $review['OS-001']['MOTIVO']);
            $this->assertSame(0.0, (float) $summary['COSTO TOTAL']);
            $this->assertSame(2, (int) $summary['CASOS A REVISAR']);
            $this->assertSame(2, (int) $conciliation['NO ASIGNABLE - DOBLE RUTA']['SERVICIOS']);
            $this->assertSame(1, (int) $conciliation['NO ASIGNABLE - DOBLE RUTA']['PACIENTE-MES']);
            $this->assertSame(0.0, (float) $conciliation['NO ASIGNABLE - DOBLE RUTA']['COSTO TOTAL']);
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

        $summary = $this->records($workbook->getSheetByName('SEGUIMIENTO MENSUAL'))[0];
        $detail = $this->records($workbook->getSheetByName('DETALLE RIPS'))[0];

        $this->assertSame(0, (int) $summary['PACIENTES']);
        $this->assertSame(5000.0, (float) $summary['COSTO TOTAL']);
        $this->assertSame('', (string) $detail['numDocumentoIdentificacion']);
        $this->assertSame(5000.0, (float) $detail['COSTO']);
        $this->assertSame('SIN DOCUMENTO DEL PACIENTE', $this->records($workbook->getSheetByName('CASOS A REVISAR'))[0]['MOTIVO']);
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

        $summary = $this->records($workbook->getSheetByName('SEGUIMIENTO MENSUAL'))[0];
        $detail = $this->records($workbook->getSheetByName('DETALLE RIPS'))[0];

        $this->assertSame('SIN FECHA', $summary['MES']);
        $this->assertSame(0.0, (float) $summary['COSTO TOTAL']);
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
        $summary = $this->records($workbook->getSheetByName('SEGUIMIENTO MENSUAL'))[0];
        $review = $this->records($workbook->getSheetByName('CASOS A REVISAR'));
        $notFound = collect($this->records($workbook->getSheetByName('NO ENCONTRADOS NOTA TECNICA')))->keyBy($codigo);
        $detail = collect($this->records($workbook->getSheetByName('DETALLE RIPS')))->keyBy($codigo);

        $this->assertSame(3, (int) $summary['SERVICIOS']);
        $this->assertSame(5000.0, (float) $summary['COSTO TOTAL']);
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
        $summary = $this->records($workbook->getSheetByName('SEGUIMIENTO MENSUAL'))[0];

        $this->assertSame(['19979158-2', 'DM00114'], $notFound->keys()->all());
        $this->assertSame('TARIFA APLICADA POR DISPENSACION ADMINISTRATIVA', $notFound['19979158-2']['OBSERVACION']);
        $this->assertSame('', (string) $notFound['DM00114']['OBSERVACION']);
        $this->assertSame('medicamentos', $notFound['19979158-2']['tipoServicio']);
        $this->assertSame(2000.0, (float) $detail['medicamentos']['COSTO']);
        $this->assertSame('01', (string) $detail['urgencias']['condicionDestinoUsuarioEgreso']);
        $this->assertSame(4, (int) $summary['SERVICIOS']);
        $this->assertSame(7000.0, (float) $summary['COSTO TOTAL']);
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
        $this->assertSame(5000.0, (float) $this->records($workbook->getSheetByName('SEGUIMIENTO MENSUAL'))[0]['COSTO TOTAL']);
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
        $this->assertSame(0.0, (float) $this->records($workbook->getSheetByName('SEGUIMIENTO MENSUAL'))[0]['COSTO TOTAL']);
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
        $this->assertSame(10000.0, (float) $this->records($workbook->getSheetByName('SEGUIMIENTO MENSUAL'))[0]['COSTO TOTAL']);
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
        $this->assertSame(10000.0, (float) $this->records($workbook->getSheetByName('SEGUIMIENTO MENSUAL'))[0]['COSTO TOTAL']);
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
            $this->assertSame(20000.0, (float) $conciliation['PROSTATA']['COSTO TOTAL']);
            $this->assertSame(16000.0, (float) $conciliation['CERVIX']['COSTO TOTAL']);
        }

        $prostataDetail = $this->records($prostata->getSheetByName('DETALLE RIPS'));

        $this->assertSame(20000.0, $this->summaryTotal($prostata, 'COSTO TOTAL'));
        $this->assertSame(16000.0, $this->summaryTotal($cervix, 'COSTO TOTAL'));
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
     * @param  array<string, string>  $files
     */
    private function exportWorkbook(User $user, string $ejecucion, array $files): Spreadsheet
    {
        $uploads = [];

        foreach ($files as $name => $content) {
            $uploads[] = UploadedFile::fake()->createWithContent($name, $content);
        }

        $response = $this->actingAs($user)->post(route('la-maria.'.$ejecucion.'.export'), [
            'archivos' => $uploads,
        ]);
        $response->assertDownload();

        $exportPath = storage_path('framework/testing/'.Str::uuid().'.xlsx');
        File::ensureDirectoryExists(dirname($exportPath));
        File::put($exportPath, $response->streamedContent());

        try {
            return IOFactory::load($exportPath);
        } finally {
            File::delete($exportPath);
        }
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

    private function summaryTotal(Spreadsheet $workbook, string $column): float
    {
        return (float) collect($this->records($workbook->getSheetByName('SEGUIMIENTO MENSUAL')))
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
