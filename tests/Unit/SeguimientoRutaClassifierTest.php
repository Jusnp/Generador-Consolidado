<?php

namespace Tests\Unit;

use App\Services\SeguimientoRutaClassifier;
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
            $this->assertSame('PROSTATA', $profiles[0]['rutaFinal']);
            $this->assertSame(1, $profiles[0]['serviceCounts']['consultas']);
            $this->assertSame(1, $profiles[0]['serviceCounts']['otrosServicios']);
            $this->assertSame('2026-07', $profiles[1]['mesAtencion']);
            $this->assertSame('CERVIX', $profiles[1]['rutaFinal']);
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

    public function test_marks_a_person_for_review_when_both_route_diagnoses_are_in_the_same_month(): void
    {
        $file = $this->createJsonFile([
            'numFactura' => 'FAC-001',
            'usuarios' => [[
                'tipoDocumentoIdentificacion' => 'CC',
                'numDocumentoIdentificacion' => '100',
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
            $this->assertSame('DOBLE_RUTA_REVISAR', $profiles[0]['rutaFinal']);
            $this->assertSame('REVISAR', $profiles[0]['estado']);
            $this->assertSame('C53.8, C61.1', $profiles[0]['diagnosticosDetectados']);
        } finally {
            unlink($file);
        }
    }

    public function test_marks_records_without_an_attention_date_for_review(): void
    {
        $file = $this->createJsonFile([
            'numFactura' => 'FAC-001',
            'usuarios' => [[
                'tipoDocumentoIdentificacion' => 'CC',
                'numDocumentoIdentificacion' => '100',
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
            $this->assertSame('REVISAR', $profiles[0]['estado']);
            $this->assertSame(1, $control[0]['servicesWithoutDate']);
            $this->assertSame('REVISAR', $control[0]['estadoCargue']);
        } finally {
            unlink($file);
        }
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
