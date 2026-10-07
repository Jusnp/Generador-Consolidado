<?php

namespace Tests\Unit;

use App\Models\CatalogoReferencia;
use App\Models\CatalogoReferenciaItem;
use App\Services\ReferenciaRutaMatcher;
use App\Services\SeguimientoRutaClassifier;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class SeguimientoRutaClassifierTest extends TestCase
{
    public function test_creates_one_profile_per_user_and_attention_month(): void
    {
        $juneFile = $this->createJsonFile([
            'numFactura' => 'FAC-061',
            'usuarios' => [[
                'tipoDocumentoIdentificacion' => 'CC',
                'numDocumentoIdentificacion' => '100',
                'servicios' => [
                    'consultas' => [[
                        'codConsulta' => '890201',
                        'codDiagnosticoPrincipal' => 'C61X',
                        'fechaInicioAtencion' => '2026-06-10 08:00',
                    ]],
                    'otrosServicios' => [[
                        'codTecnologiaSalud' => 'OS-001',
                        'fechaSuministroTecnologia' => '2026-06-15',
                    ]],
                ],
            ]],
        ]);
        $julyFile = $this->createJsonFile([
            'numFactura' => 'FAC-071',
            'usuarios' => [[
                'tipoDocumentoIdentificacion' => 'CC',
                'numDocumentoIdentificacion' => '100',
                'servicios' => [
                    'medicamentos' => [[
                        'codTecnologiaSalud' => 'MED-001',
                        'codDiagnosticoPrincipal' => 'C531',
                        'fechaDispensAdmon' => '2026-07-08',
                        'cantidadMedicamento' => 2,
                    ]],
                ],
            ]],
        ]);
        $files = [
            ['path' => $juneFile, 'fileName' => 'junio.json'],
            ['path' => $julyFile, 'fileName' => 'julio.json'],
        ];

        try {
            $classifier = new SeguimientoRutaClassifier;
            $profiles = $classifier->profilesForFiles($files);
            $details = iterator_to_array($classifier->detailRowsForFiles($files, $profiles));
            $control = iterator_to_array($classifier->controlRowsForFiles($files));

            $this->assertCount(2, $profiles);
            $this->assertSame('2026-06', $profiles[0]['mesAtencion']);
            $this->assertSame('PROSTATA', $profiles[0]['ruta']);
            $this->assertSame('DIAGNOSTICO', $profiles[0]['origenRuta']);
            $this->assertSame(1, $profiles[0]['serviceCounts']['consultas']);
            $this->assertSame(1, $profiles[0]['serviceCounts']['otrosServicios']);
            $this->assertSame('2026-07', $profiles[1]['mesAtencion']);
            $this->assertSame('CERVIX', $profiles[1]['ruta']);
            $this->assertSame(1, $profiles[1]['serviceCounts']['medicamentos']);
            $this->assertSame(2, $details[2]['cantidad']);
            $this->assertSame('2026-06', $details[1]['mesAtencion']);
            $this->assertSame('2026-06-15', $details[1]['fechaAtencion']);
            $this->assertSame('PROCESADO', $control[0]['estadoCargue']);
            $this->assertSame(0, $control[0]['servicesWithoutDate']);
        } finally {
            unlink($juneFile);
            unlink($julyFile);
        }
    }

    public function test_double_route_month_is_not_assignable_and_does_not_use_sex(): void
    {
        $file = $this->createJsonFile([
            'numFactura' => 'FAC-001',
            'usuarios' => [[
                'tipoDocumentoIdentificacion' => 'CC',
                'numDocumentoIdentificacion' => '100',
                'codSexo' => 'M',
                'servicios' => [
                    'procedimientos' => [
                        [
                            'codProcedimiento' => '881201',
                            'codDiagnosticoPrincipal' => 'C61.1',
                            'fechaInicioAtencion' => '2026-07-01',
                        ],
                        [
                            'codProcedimiento' => '881202',
                            'codDiagnosticoPrincipal' => 'C53.8',
                            'fechaInicioAtencion' => '2026-07-02',
                        ],
                    ],
                ],
            ]],
        ]);

        try {
            $profiles = (new SeguimientoRutaClassifier)->profilesForFiles([
                ['path' => $file, 'fileName' => 'rutas.json'],
            ]);

            $this->assertCount(1, $profiles);
            $this->assertSame('NO_ASIGNABLE', $profiles[0]['ruta']);
            $this->assertSame('NINGUNO', $profiles[0]['origenRuta']);
            $this->assertSame(['DOBLE_RUTA'], $profiles[0]['estadosClinicos']);
            $this->assertSame('C53.8, C61.1', $profiles[0]['diagnosticosDetectados']);
        } finally {
            unlink($file);
        }
    }

    public function test_marks_records_without_an_attention_date(): void
    {
        $file = $this->createJsonFile([
            'numFactura' => 'FAC-001',
            'usuarios' => [[
                'tipoDocumentoIdentificacion' => 'CC',
                'numDocumentoIdentificacion' => '100',
                'codSexo' => 'M',
                'servicios' => [
                    'otrosServicios' => [[
                        'codTecnologiaSalud' => 'OS-001',
                        'codDiagnosticoPrincipal' => 'C61X',
                    ]],
                ],
            ]],
        ]);
        $files = [['path' => $file, 'fileName' => 'sin-fecha.json']];

        try {
            $classifier = new SeguimientoRutaClassifier;
            $profiles = $classifier->profilesForFiles($files);
            $control = iterator_to_array($classifier->controlRowsForFiles($files));

            $this->assertSame('', $profiles[0]['mesAtencion']);
            $this->assertSame('PROSTATA', $profiles[0]['ruta']);
            $this->assertContains('SIN_FECHA', $profiles[0]['estadosClinicos']);
            $this->assertSame(1, $control[0]['servicesWithoutDate']);
            $this->assertSame('REVISAR', $control[0]['estadoCargue']);
        } finally {
            unlink($file);
        }
    }

    public function test_resolves_route_by_principal_diagnosis_before_sex(): void
    {
        $file = $this->createJsonFile([
            'numFactura' => 'FAC-RUTAS',
            'usuarios' => [
                $this->user('1', 'M', 'C61X'),
                $this->user('2', 'F', 'C531'),
                $this->user('3', 'F', 'C61X'),
                $this->user('4', 'M', 'C539'),
                $this->user('5', 'M', 'Z000'),
                $this->user('6', 'F', 'N879'),
                $this->user('7', 'I', 'Z000'),
                $this->user('8', 'I', 'C61X'),
                $this->user('9', '', 'C530'),
            ],
        ]);

        try {
            $profiles = collect((new SeguimientoRutaClassifier)->profilesForFiles([
                ['path' => $file, 'fileName' => 'rutas.json'],
            ]))->keyBy('numDocumentoIdentificacion');

            $this->assertRoute($profiles['1'], 'PROSTATA', 'DIAGNOSTICO', ['CORRECTO']);
            $this->assertRoute($profiles['2'], 'CERVIX', 'DIAGNOSTICO', ['CORRECTO']);
            $this->assertRoute($profiles['3'], 'PROSTATA', 'DIAGNOSTICO', ['INCONSISTENCIA_SEXO_RUTA']);
            $this->assertRoute($profiles['4'], 'CERVIX', 'DIAGNOSTICO', ['INCONSISTENCIA_SEXO_RUTA']);
            $this->assertRoute($profiles['5'], 'PROSTATA', 'SEXO', ['SIN_DIAGNOSTICO_RUTA']);
            $this->assertRoute($profiles['6'], 'CERVIX', 'SEXO', ['SIN_DIAGNOSTICO_RUTA']);
            $this->assertRoute(
                $profiles['7'],
                'NO_ASIGNABLE',
                'NINGUNO',
                ['SEXO_NO_RECONOCIDO', 'SIN_DIAGNOSTICO_RUTA']
            );
            $this->assertRoute($profiles['8'], 'PROSTATA', 'DIAGNOSTICO', ['CORRECTO']);
            $this->assertRoute($profiles['9'], 'CERVIX', 'DIAGNOSTICO', ['CORRECTO']);
            $this->assertSame('CC-3-2026-07', $profiles['3']['casoId']);
            $this->assertStringContainsString('asignada por sexo', $profiles['5']['motivoClasificacion']);
        } finally {
            unlink($file);
        }
    }

    public function test_secondary_clinical_evidence_is_kept_without_changing_the_route(): void
    {
        $user = $this->user('10', 'F', 'D060');
        $user['servicios']['consultas'][0]['codDiagnosticoRelacionado1'] = 'C61X';
        $file = $this->createJsonFile(['numFactura' => 'FAC-EVID', 'usuarios' => [$user]]);

        try {
            $profiles = (new SeguimientoRutaClassifier)->profilesForFiles([
                ['path' => $file, 'fileName' => 'evidencia.json'],
            ]);

            $this->assertRoute($profiles[0], 'CERVIX', 'SEXO', ['SIN_DIAGNOSTICO_RUTA']);
            $this->assertSame('C61X, D060', $profiles[0]['evidenciaSecundaria']);
        } finally {
            unlink($file);
        }
    }

    public function test_record_without_document_keeps_its_route_without_inventing_a_document(): void
    {
        $user = $this->user('', 'M', 'C61X');
        $user['tipoDocumentoIdentificacion'] = '';
        $file = $this->createJsonFile(['numFactura' => 'FAC-SD', 'usuarios' => [$user]]);

        try {
            $profiles = (new SeguimientoRutaClassifier)->profilesForFiles([
                ['path' => $file, 'fileName' => 'sin-documento.json'],
            ]);

            $this->assertRoute($profiles[0], 'PROSTATA', 'DIAGNOSTICO', ['SIN_DOCUMENTO']);
            $this->assertSame('', $profiles[0]['numDocumentoIdentificacion']);
            $this->assertSame('SIN_DOCUMENTO-FAC-SD-U1-2026-07', $profiles[0]['casoId']);
        } finally {
            unlink($file);
        }
    }

    public function test_filters_execution_and_shared_profiles_by_resolved_route(): void
    {
        $classifier = new SeguimientoRutaClassifier;
        $profiles = [
            ['ruta' => 'PROSTATA', 'profileKey' => 'a'],
            ['ruta' => 'CERVIX', 'profileKey' => 'b'],
            ['ruta' => 'NO_ASIGNABLE', 'profileKey' => 'c'],
        ];

        $this->assertSame(['a'], array_column($classifier->profilesForExecutionRoute($profiles, 'PROSTATA'), 'profileKey'));
        $this->assertSame(['b'], array_column($classifier->profilesForExecutionRoute($profiles, 'CERVIX'), 'profileKey'));
        $this->assertSame(['c'], array_column($classifier->profilesNotAssignable($profiles), 'profileKey'));
    }

    public function test_screens_identical_files_and_repeated_invoices(): void
    {
        $original = ['numFactura' => 'FAC-1', 'usuarios' => [$this->user('1', 'M', 'C61X')]];
        $changed = ['numFactura' => 'FAC-1', 'usuarios' => [$this->user('2', 'M', 'C61X')]];
        $other = ['numFactura' => 'FAC-2', 'usuarios' => [$this->user('3', 'M', 'C61X')]];
        $paths = [
            $this->createJsonFile($original),
            $this->createJsonFile($original),
            $this->createJsonFile($changed),
            $this->createJsonFile($other),
        ];

        try {
            $screened = (new SeguimientoRutaClassifier)->screenFiles([
                ['path' => $paths[0], 'fileName' => 'original.json'],
                ['path' => $paths[1], 'fileName' => 'copia.json'],
                ['path' => $paths[2], 'fileName' => 'corregida.json'],
                ['path' => $paths[3], 'fileName' => 'otra.json'],
            ]);
            $byName = collect($screened)->keyBy('fileName');

            $this->assertSame('FACTURA_REPETIDA_CON_DIFERENCIAS', $byName['original.json']['condicion']);
            $this->assertSame('ARCHIVO_DUPLICADO_EXCLUIDO', $byName['copia.json']['condicion']);
            $this->assertSame('original.json', $byName['copia.json']['relacionados']);
            $this->assertSame('FACTURA_REPETIDA_CON_DIFERENCIAS', $byName['corregida.json']['condicion']);
            $this->assertSame('original.json', $byName['corregida.json']['relacionados']);
            $this->assertSame('UNICO', $byName['otra.json']['condicion']);
            $this->assertSame($byName['original.json']['hash'], $byName['copia.json']['hash']);
            $this->assertSame(64, strlen($byName['otra.json']['hash']));
        } finally {
            array_map('unlink', $paths);
        }
    }

    public function test_detail_rows_keep_internal_route_and_cost_decisions(): void
    {
        $copia = $this->user('X', 'M', 'C61X');
        $copia['servicios']['consultas'][] = $copia['servicios']['consultas'][0];
        $doble = $this->user('D', 'M', 'C61X');
        $doble['servicios']['consultas'][] = [
            'codConsulta' => '890201',
            'codDiagnosticoPrincipal' => 'C531',
            'fechaInicioAtencion' => '2026-07-11 08:00',
            'consecutivo' => 2,
        ];
        $paths = [
            $this->createJsonFile(['numFactura' => 'FAC-A', 'usuarios' => [
                $this->user('N', 'M', 'C61X'),
                $this->user('F61', 'F', 'C61X'),
                $doble,
                $copia,
            ]]),
            $this->createJsonFile(['numFactura' => 'FAC-R', 'usuarios' => [$this->user('R1', 'M', 'C61X')]]),
            $this->createJsonFile(['numFactura' => 'FAC-R', 'usuarios' => [$this->user('R2', 'M', 'C61X')]]),
        ];
        $matcher = new ReferenciaRutaMatcher(new Collection([
            $this->catalog('NOTA_PROSTATA', [
                new CatalogoReferenciaItem([
                    'codigo' => '890201',
                    'ruta' => 'PROSTATA',
                    'categoria' => 'SERVICIO_TECNICO',
                    'descripcion' => 'Consulta',
                    'tarifa_referencia' => 5000.0,
                ]),
            ]),
        ]));

        try {
            $classifier = new SeguimientoRutaClassifier;
            $files = $classifier->processableFiles($classifier->screenFiles([
                ['path' => $paths[0], 'fileName' => 'a.json'],
                ['path' => $paths[1], 'fileName' => 'r1.json'],
                ['path' => $paths[2], 'fileName' => 'r2.json'],
            ]));
            $profiles = $classifier->profilesForFiles($files);
            $index = $classifier->duplicateIndexForFiles($files);
            $rows = collect(iterator_to_array(
                $classifier->detailRowsForFiles($files, $profiles, $matcher, 'PROSTATA', $index),
                false
            ))->groupBy('numDocumentoIdentificacion');

            $normal = $rows['N'][0];
            $this->assertSame(['SI', 'VALIDO', false], [$normal['sumaAlResumen'], $normal['claseCosto'], $normal['requiereRevision']]);

            $inconsistente = $rows['F61'][0];
            $this->assertSame('PROSTATA', $inconsistente['ruta']);
            $this->assertSame('INCONSISTENCIA_SEXO_RUTA', $inconsistente['estadoClinico']);
            $this->assertSame(['SI', 'VALIDO_CON_ALERTA', true], [$inconsistente['sumaAlResumen'], $inconsistente['claseCosto'], $inconsistente['requiereRevision']]);

            foreach ($rows['D'] as $dobleRuta) {
                $this->assertSame(['NO', 'DOBLE_RUTA', true], [$dobleRuta['sumaAlResumen'], $dobleRuta['motivoNoSuma'], $dobleRuta['requiereRevision']]);
            }

            $this->assertSame(['NINGUNO', 'SI'], [$rows['X'][0]['estadoDuplicidad'], $rows['X'][0]['sumaAlResumen']]);
            $this->assertSame(['DUPLICADO_EXACTO', 'NO'], [$rows['X'][1]['estadoDuplicidad'], $rows['X'][1]['sumaAlResumen']]);

            foreach (['R1', 'R2'] as $documento) {
                $this->assertSame('NO', $rows[$documento][0]['sumaAlResumen']);
                $this->assertSame('FACTURA_REPETIDA_CON_DIFERENCIAS', $rows[$documento][0]['motivoNoSuma']);
                $this->assertSame('POTENCIAL_NO_SUMA', $rows[$documento][0]['claseCosto']);
            }
        } finally {
            array_map('unlink', $paths);
        }
    }

    /**
     * @param  array<int, CatalogoReferenciaItem>  $items
     */
    private function catalog(string $type, array $items): CatalogoReferencia
    {
        $catalog = new CatalogoReferencia([
            'tipo' => $type,
            'version' => '2026-01',
            'archivo_origen' => strtolower($type).'.xlsx',
            'activo' => true,
        ]);
        $catalog->id = 1;
        $catalog->setRelation('items', new Collection($items));

        return $catalog;
    }

    /**
     * @param  array<string, mixed>  $profile
     * @param  array<int, string>  $estados
     */
    private function assertRoute(array $profile, string $ruta, string $origen, array $estados): void
    {
        $this->assertSame($ruta, $profile['ruta']);
        $this->assertSame($origen, $profile['origenRuta']);
        $this->assertSame($estados, $profile['estadosClinicos']);
    }

    /**
     * @return array<string, mixed>
     */
    private function user(string $documento, string $sexo, string $diagnostico): array
    {
        return [
            'tipoDocumentoIdentificacion' => 'CC',
            'numDocumentoIdentificacion' => $documento,
            'codSexo' => $sexo,
            'servicios' => [
                'consultas' => [[
                    'codConsulta' => '890201',
                    'codDiagnosticoPrincipal' => $diagnostico,
                    'fechaInicioAtencion' => '2026-07-10 08:00',
                    'consecutivo' => 1,
                ]],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function createJsonFile(array $document): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rips-rutas-');

        if ($path === false) {
            self::fail('No fue posible crear un archivo temporal RIPS.');
        }

        file_put_contents($path, json_encode($document, JSON_THROW_ON_ERROR));

        return $path;
    }
}
