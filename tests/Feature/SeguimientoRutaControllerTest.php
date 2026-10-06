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
use PhpOffice\PhpSpreadsheet\IOFactory;
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
        $juneFile = UploadedFile::fake()->createWithContent(
            'junio.json',
            $this->routeJson('FAC-061', 'C61X', '2026-06-10', 'consultas')
        );
        $julyFile = UploadedFile::fake()->createWithContent(
            'julio.json',
            $this->routeJson('FAC-071', 'C531', '2026-07-08', 'otrosServicios')
        );

        $response = $this->actingAs($user)->post(
            route('la-maria.prostata.export'),
            ['archivos' => [$juneFile, $julyFile]]
        );

        $response->assertDownload();
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $user->id,
            'action' => 'seguimiento_rutas',
        ]);

        $exportPath = storage_path('framework/testing/'.Str::uuid().'.xlsx');
        File::ensureDirectoryExists(dirname($exportPath));
        File::put($exportPath, $response->streamedContent());

        try {
            $workbook = IOFactory::load($exportPath);
            $this->assertNotContains('CASOS A REVISAR', $workbook->getSheetNames());
            $summary = $workbook->getSheetByName('SEGUIMIENTO MENSUAL');
            $patient = $workbook->getSheetByName('SEGUIMIENTO PACIENTE MES');
            $detail = $workbook->getSheetByName('DETALLE RIPS');
            $this->assertNotNull($summary);
            $this->assertNotNull($patient);
            $this->assertNotNull($detail);
            $this->assertSame('SEXO', $patient->getCell('D1')->getFormattedValue());
            $this->assertSame('M', $patient->getCell('D2')->getFormattedValue());
            $this->assertSame('FECHA NACIMIENTO', $patient->getCell('E1')->getFormattedValue());
            $this->assertSame('1980-05-12', $patient->getCell('E2')->getFormattedValue());
            $this->assertSame('MUNICIPIO', $patient->getCell('F1')->getFormattedValue());
            $this->assertSame('MEDELLÍN, ANTIOQUIA', $patient->getCell('F2')->getFormattedValue());
            $this->assertSame('ZONA', $patient->getCell('G1')->getFormattedValue());
            $this->assertSame('URBANA', $patient->getCell('G2')->getFormattedValue());
            $this->assertSame('TOTAL SERVICIOS', $patient->getCell('S1')->getFormattedValue());
            $this->assertSame('NOMBRE NOTA TECNICA', $detail->getCell('K1')->getFormattedValue());
            $this->assertSame('Consulta de referencia próstata', $detail->getCell('K2')->getFormattedValue());
            $this->assertSame('COD PRESTADOR', $detail->getCell('C1')->getFormattedValue());
            $this->assertSame('050010608601', $detail->getCell('C2')->getFormattedValue());
            $this->assertSame('FINALIDAD TECNOLOGIA SALUD', $detail->getCell('M1')->getFormattedValue());
            $this->assertSame('11', $detail->getCell('M2')->getFormattedValue());
            $this->assertSame('CAUSA MOTIVO ATENCION', $detail->getCell('N1')->getFormattedValue());
            $this->assertSame('38', $detail->getCell('N2')->getFormattedValue());
            $this->assertSame('COD DIAGNOSTICO PRINCIPAL', $detail->getCell('O1')->getFormattedValue());
            $this->assertSame('C61X', $detail->getCell('O2')->getFormattedValue());
            $this->assertSame('ATC', $detail->getCell('U1')->getFormattedValue());
            $this->assertSame('TIPO USUARIO', $patient->getCell('X1')->getFormattedValue());
            $this->assertSame('01', $patient->getCell('X2')->getFormattedValue());
            $this->assertSame('PAIS ORIGEN', $patient->getCell('Y1')->getFormattedValue());
            $this->assertSame('COLOMBIA', $patient->getCell('Y2')->getFormattedValue());
            $this->assertSame('PAIS RESIDENCIA', $patient->getCell('Z1')->getFormattedValue());
            $this->assertSame('COLOMBIA', $patient->getCell('Z2')->getFormattedValue());
            $this->assertSame('INCAPACIDAD', $patient->getCell('AA1')->getFormattedValue());
            $this->assertSame('NO', $patient->getCell('AA2')->getFormattedValue());
            $this->assertSame('REGISTRO SIRAS', $patient->getCell('AB1')->getFormattedValue());
            $this->assertSame('SIRAS-100', $patient->getCell('AB2')->getFormattedValue());
            $this->assertSame('CONSECUTIVO USUARIO', $patient->getCell('AC1')->getFormattedValue());
            $this->assertSame('7', $patient->getCell('AC2')->getFormattedValue());
            $this->assertSame('TIPO USUARIO', $detail->getCell('Y1')->getFormattedValue());
            $this->assertSame('CONSECUTIVO RIPS', $detail->getCell('AE1')->getFormattedValue());
            $this->assertSame('21', $detail->getCell('AE2')->getFormattedValue());
            $this->assertSame('NOMBRE ORIGINAL RIPS', $detail->getCell('AF1')->getFormattedValue());
            $this->assertSame('Servicio original del RIPS', $detail->getCell('AF2')->getFormattedValue());
            $this->assertSame('ID MIPRES', $detail->getCell('AG1')->getFormattedValue());
            $this->assertSame('MIPRES-100', $detail->getCell('AG2')->getFormattedValue());
            $this->assertSame('COD DIAGNOSTICOS RELACIONADOS', $detail->getCell('AU1')->getFormattedValue());
            $this->assertSame('Z001, Z002', $detail->getCell('AU2')->getFormattedValue());
            $this->assertSame('COD DIAGNOSTICO PRINCIPAL CIE11', $detail->getCell('AS1')->getFormattedValue());
            $this->assertSame('2C82', $detail->getCell('AS2')->getFormattedValue());
            $this->assertSame('NOMBRE DIAGNOSTICO PRINCIPAL CIE11', $detail->getCell('AT1')->getFormattedValue());
            $this->assertSame('Diagnóstico principal CIE11', $detail->getCell('AT2')->getFormattedValue());
            $this->assertSame('COD DIAGNOSTICOS RELACIONADOS CIE11', $detail->getCell('AV1')->getFormattedValue());
            $this->assertSame('QA00', $detail->getCell('AV2')->getFormattedValue());
            $this->assertSame('COD COMPLICACION', $detail->getCell('AY1')->getFormattedValue());
            $this->assertSame('T001', $detail->getCell('AY2')->getFormattedValue());
            $this->assertSame('NUM FEV PAGO MODERADOR', $detail->getCell('BF1')->getFormattedValue());
            $this->assertSame('FEV-100', $detail->getCell('BF2')->getFormattedValue());
            $this->assertSame('VALOR UNITARIO RIPS', $detail->getCell('BB1')->getFormattedValue());
            $this->assertSame(2500.0, (float) $detail->getCell('BB2')->getValue());
            $this->assertSame('CODIGO VIDA', $detail->getCell('BG1')->getFormattedValue());
            $this->assertSame('VIDA-100', $detail->getCell('BG2')->getFormattedValue());
            $this->assertSame('2026-06', $summary->getCell('A2')->getFormattedValue());
            $this->assertSame('PROSTATA', $summary->getCell('B2')->getFormattedValue());
            $this->assertSame('2026-07', $summary->getCell('A3')->getFormattedValue());
            $this->assertSame('PROSTATA', $summary->getCell('B3')->getFormattedValue());
        } finally {
            File::delete($exportPath);
        }
    }

    public function test_workbook_omits_empty_siras_and_translates_official_geographic_codes(): void
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

        $response = $this->actingAs($user)->post(route('la-maria.prostata.export'), [
            'archivos' => [UploadedFile::fake()->createWithContent(
                'geografia.json',
                json_encode($payload, JSON_THROW_ON_ERROR)
            )],
        ]);

        $response->assertDownload();
        $exportPath = storage_path('framework/testing/'.Str::uuid().'.xlsx');
        File::ensureDirectoryExists(dirname($exportPath));
        File::put($exportPath, $response->streamedContent());

        try {
            $workbook = IOFactory::load($exportPath);
            $patient = $workbook->getSheetByName('SEGUIMIENTO PACIENTE MES');
            $detail = $workbook->getSheetByName('DETALLE RIPS');

            $this->assertNotNull($patient);
            $this->assertNotNull($detail);
            $patientHeaders = $patient->rangeToArray('A1:AZ1')[0];
            $detailHeaders = $detail->rangeToArray('A1:BZ1')[0];
            $this->assertNotContains('REGISTRO SIRAS', $patientHeaders);
            $this->assertNotContains('REGISTRO SIRAS', $detailHeaders);
            $this->assertSame('MEDELLÍN, ANTIOQUIA', $patient->getCell('F2')->getFormattedValue());
            $this->assertSame('VENEZUELA', $patient->getCell('Y2')->getFormattedValue());
            $this->assertSame('BANGLADÉS', $patient->getCell('Z2')->getFormattedValue());
        } finally {
            File::delete($exportPath);
        }
    }

    public function test_cervix_execution_uses_female_records_for_its_monthly_summary(): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();
        $juneFile = UploadedFile::fake()->createWithContent(
            'junio.json',
            $this->routeJson('FAC-061', 'C61X', '2026-06-10', 'consultas')
        );
        $julyFile = UploadedFile::fake()->createWithContent(
            'julio.json',
            $this->routeJson('FAC-071', 'C531', '2026-07-08', 'otrosServicios', 'F')
        );

        $response = $this->actingAs($user)->post(
            route('la-maria.cervix.export'),
            ['archivos' => [$juneFile, $julyFile]]
        );

        $response->assertDownload();

        $exportPath = storage_path('framework/testing/'.Str::uuid().'.xlsx');
        File::ensureDirectoryExists(dirname($exportPath));
        File::put($exportPath, $response->streamedContent());

        try {
            $workbook = IOFactory::load($exportPath);
            $summary = $workbook->getSheetByName('SEGUIMIENTO MENSUAL');
            $review = $workbook->getSheetByName('CASOS A REVISAR');
            $this->assertNotNull($summary);
            $this->assertNotNull($review);
            $this->assertSame('2026-07', $summary->getCell('A2')->getFormattedValue());
            $this->assertSame('CERVIX', $summary->getCell('B2')->getFormattedValue());
            $this->assertSame('', $summary->getCell('A3')->getFormattedValue());
            $this->assertSame('M', $review->getCell('F2')->getFormattedValue());
            $this->assertSame(5000.0, (float) $review->getCell('T2')->getValue());
            $this->assertSame('REVISAR', $review->getCell('Y2')->getFormattedValue());
            $this->assertStringContainsString('se conserva para revisión', $review->getCell('Z2')->getFormattedValue());
            $this->assertSame('PROSTATA', $review->getCell('AA2')->getFormattedValue());
        } finally {
            File::delete($exportPath);
        }
    }

    public function test_monthly_summary_separates_ready_patients_from_review_cases(): void
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
        $reviewPayload['usuarios'][0]['servicios']['consultas'][] = $cervixService;

        $response = $this->actingAs($user)->post(route('la-maria.prostata.export'), [
            'archivos' => [
                UploadedFile::fake()->createWithContent(
                    'listo.json',
                    $this->routeJson('FAC-LISTO', 'C61X', '2026-07-10', 'consultas')
                ),
                UploadedFile::fake()->createWithContent(
                    'revisar.json',
                    json_encode($reviewPayload, JSON_THROW_ON_ERROR)
                ),
            ],
        ]);

        $response->assertDownload();
        $exportPath = storage_path('framework/testing/'.Str::uuid().'.xlsx');
        File::ensureDirectoryExists(dirname($exportPath));
        File::put($exportPath, $response->streamedContent());

        try {
            $workbook = IOFactory::load($exportPath);
            $summary = $workbook->getSheetByName('SEGUIMIENTO MENSUAL');
            $patient = $workbook->getSheetByName('SEGUIMIENTO PACIENTE MES');
            $review = $workbook->getSheetByName('CASOS A REVISAR');

            $this->assertNotNull($summary);
            $this->assertNotNull($patient);
            $this->assertNotNull($review);
            $this->assertSame(1, (int) $summary->getCell('C2')->getValue());
            $this->assertSame(1, (int) $summary->getCell('J2')->getValue());
            $this->assertSame(2, $summary->getCell('C2')->getValue() + $summary->getCell('J2')->getValue());
            $this->assertSame(2, $patient->getHighestDataRow());
            $this->assertSame('100', $patient->getCell('C2')->getFormattedValue());
            $this->assertGreaterThan(1, $review->getHighestDataRow());
            $this->assertSame('200', $review->getCell('E2')->getFormattedValue());
        } finally {
            File::delete($exportPath);
        }
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
            'codServicio' => 'HOSP-001',
            'consecutivo' => 22,
        ]];

        $response = $this->actingAs($user)->post(route('la-maria.prostata.export'), [
            'archivos' => [UploadedFile::fake()->createWithContent(
                'hospitalario.json',
                json_encode($payload, JSON_THROW_ON_ERROR)
            )],
        ]);

        $response->assertDownload();
        $exportPath = storage_path('framework/testing/'.Str::uuid().'.xlsx');
        File::ensureDirectoryExists(dirname($exportPath));
        File::put($exportPath, $response->streamedContent());

        try {
            $workbook = IOFactory::load($exportPath);
            $summary = $workbook->getSheetByName('SEGUIMIENTO MENSUAL');
            $patient = $workbook->getSheetByName('SEGUIMIENTO PACIENTE MES');
            $detail = $workbook->getSheetByName('DETALLE RIPS');

            $this->assertNotNull($summary);
            $this->assertNotNull($patient);
            $this->assertNotNull($detail);
            $detailCost = 0.0;

            for ($row = 2; $row <= $detail->getHighestDataRow(); $row++) {
                $detailCost += (float) $detail->getCell('T'.$row)->getValue();
            }

            $patientCost = (float) $patient->getCell('W2')->getValue();
            $summaryCost = (float) $summary->getCell('I2')->getValue();

            $this->assertSame(5000.0, $detailCost);
            $this->assertSame($detailCost, $patientCost);
            $this->assertSame($patientCost, $summaryCost);
            $this->assertSame(2, (int) $patient->getCell('S2')->getValue());
            $this->assertSame('', $detail->getCell('AG3')->getFormattedValue());
            $this->assertSame('', $detail->getCell('AS3')->getFormattedValue());
            $this->assertSame('', $detail->getCell('BF3')->getFormattedValue());
        } finally {
            File::delete($exportPath);
        }
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

    public function test_prostata_summary_excludes_records_without_route_diagnosis_from_ready_totals(): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();

        $response = $this->actingAs($user)->post(route('la-maria.prostata.export'), [
            'archivos' => [
                UploadedFile::fake()->createWithContent(
                    'sin-diagnostico-ruta.json',
                    $this->routeJson('FAC-100', 'Z000', '2026-08-10', 'consultas', 'M')
                ),
            ],
        ]);

        $response->assertDownload();

        $exportPath = storage_path('framework/testing/'.Str::uuid().'.xlsx');
        File::ensureDirectoryExists(dirname($exportPath));
        File::put($exportPath, $response->streamedContent());

        try {
            $workbook = IOFactory::load($exportPath);
            $summary = $workbook->getSheetByName('SEGUIMIENTO MENSUAL');
            $review = $workbook->getSheetByName('CASOS A REVISAR');

            $this->assertNotNull($summary);
            $this->assertNotNull($review);
            $this->assertSame('2026-08', $summary->getCell('A2')->getFormattedValue());
            $this->assertSame('PROSTATA', $summary->getCell('B2')->getFormattedValue());
            $this->assertSame(0, (int) $summary->getCell('C2')->getValue());
            $this->assertSame(0.0, (float) $summary->getCell('I2')->getValue());
            $this->assertSame(1, (int) $summary->getCell('J2')->getValue());
            $this->assertGreaterThan(1, $review->getHighestDataRow());
        } finally {
            File::delete($exportPath);
        }
    }

    public function test_prostata_execution_preserves_female_records_for_review(): void
    {
        $user = User::factory()->create();
        $this->createReferenceCatalogs();

        $response = $this->actingAs($user)->post(route('la-maria.prostata.export'), [
            'archivos' => [
                UploadedFile::fake()->createWithContent(
                    'mujer-en-prostata.json',
                    $this->routeJson('FAC-200', 'C531', '2026-09-10', 'otrosServicios', 'F')
                ),
            ],
        ]);

        $response->assertDownload();

        $exportPath = storage_path('framework/testing/'.Str::uuid().'.xlsx');
        File::ensureDirectoryExists(dirname($exportPath));
        File::put($exportPath, $response->streamedContent());

        try {
            $workbook = IOFactory::load($exportPath);
            $summary = $workbook->getSheetByName('SEGUIMIENTO MENSUAL');
            $review = $workbook->getSheetByName('CASOS A REVISAR');

            $this->assertNotNull($summary);
            $this->assertNotNull($review);
            $this->assertSame('', $summary->getCell('A2')->getFormattedValue());
            $this->assertSame('F', $review->getCell('F2')->getFormattedValue());
            $this->assertSame(8000.0, (float) $review->getCell('T2')->getValue());
            $this->assertSame('REVISAR', $review->getCell('Y2')->getFormattedValue());
            $this->assertStringContainsString('costo se asigna a CERVIX', $review->getCell('Z2')->getFormattedValue());
            $this->assertSame('CERVIX', $review->getCell('AA2')->getFormattedValue());
        } finally {
            File::delete($exportPath);
        }
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
}
