<?php

namespace App\Services;

use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;

class SeguimientoRutaClassifier
{
    private RipsGeographyCatalog $geographyCatalog;

    public function __construct(?RipsGeographyCatalog $geographyCatalog = null)
    {
        $this->geographyCatalog = $geographyCatalog ?? new RipsGeographyCatalog;
    }

    /**
     * @var array<int, string>
     */
    private const SERVICE_TYPES = [
        'consultas',
        'procedimientos',
        'medicamentos',
        'otrosServicios',
        'urgencias',
        'hospitalizacion',
        'recienNacidos',
    ];

    /**
     * @param  array<int, array{path: string, fileName: string}>  $files
     * @return array<int, array<string, mixed>>
     */
    public function profilesForFiles(array $files): array
    {
        $profiles = [];

        foreach ($files as $file) {
            foreach ($this->recordsForFile($file) as $record) {
                $profileKey = $record['profileKey'];

                if (! isset($profiles[$profileKey])) {
                    $profiles[$profileKey] = $this->newProfile($record);
                }

                $profile = &$profiles[$profileKey];
                $profile['serviceCount']++;

                if (isset($profile['serviceCounts'][$record['tipoServicio']])) {
                    $profile['serviceCounts'][$record['tipoServicio']]++;
                }

                if ($record['factura'] !== '') {
                    $profile['facturas'][$record['factura']] = true;
                }

                if ($record['routeEvidence'] !== '') {
                    $profile['routeEvidence'][$record['routeEvidence']] = true;
                }

                if ($record['diagnosticoPrincipal'] !== '') {
                    $profile['diagnosticosReportados'][$record['diagnosticoPrincipal']] = true;
                }

                if ($record['fechaAtencion'] !== '') {
                    $profile['primeraFechaAtencion'] = $this->earlierDate(
                        $profile['primeraFechaAtencion'],
                        $record['fechaAtencion']
                    );
                    $profile['ultimaFechaAtencion'] = $this->laterDate(
                        $profile['ultimaFechaAtencion'],
                        $record['fechaAtencion']
                    );
                }

                $profile['hasMissingIdentity'] = $profile['hasMissingIdentity']
                    || $record['hasMissingIdentity'];
                $profile['hasIdentityMismatch'] = $profile['hasIdentityMismatch']
                    || $record['hasIdentityMismatch'];
                $profile['hasMissingDate'] = $profile['hasMissingDate']
                    || $record['hasMissingDate'];

                unset($profile);
            }
        }

        foreach ($profiles as &$profile) {
            $diagnoses = array_keys($profile['routeEvidence']);
            sort($diagnoses);

            $diagnosticosReportados = array_keys($profile['diagnosticosReportados'] ?? []);
            sort($diagnosticosReportados);

            $profile['facturas'] = array_keys($profile['facturas']);
            sort($profile['facturas']);
            $profile['cantidadFacturas'] = count($profile['facturas']);
            $profile['diagnosticosDetectados'] = implode(', ', $diagnosticosReportados);
            $profile['rutaFinal'] = $this->routeForEvidence($diagnoses);
            $profile['rutaCalculo'] = $this->routeForSex($profile['sexo'] ?? '');
            $profile['estado'] = $this->profileStatus($profile);
            $profile['motivoRevision'] = $this->reviewReason($profile);
        }
        unset($profile);

        uasort(
            $profiles,
            static fn (array $left, array $right): int => [
                $left['mesAtencion'],
                $left['tipoDocumentoIdentificacion'],
                $left['numDocumentoIdentificacion'],
            ] <=> [
                $right['mesAtencion'],
                $right['tipoDocumentoIdentificacion'],
                $right['numDocumentoIdentificacion'],
            ]
        );

        return array_values($profiles);
    }

    /**
     * Filtra perfiles a una ejecución de La María por la ruta de cálculo.
     * La ruta clínica se mantiene para trazabilidad, pero el costo se consolida
     * según el sexo registrado para evitar que casos sin diagnóstico queden fuera.
     *
     * @param  array<int, array<string, mixed>>  $profiles
     * @return array<int, array<string, mixed>>
     */
    public function profilesForExecutionRoute(array $profiles, string $ruta): array
    {
        return array_values(array_filter(
            $profiles,
            static function (array $profile) use ($ruta): bool {
                return $profile['rutaCalculo'] === $ruta
                    || (($profile['sexo'] ?? '') === '' && $profile['rutaFinal'] === $ruta);
            }
        ));
    }

    /**
     * Conserva los perfiles de la ruta contraria para que el supervisor pueda
     * revisar los casos recibidos en el archivo de una ejecución distinta.
     *
     * @param  array<int, array<string, mixed>>  $profiles
     * @return array<int, array<string, mixed>>
     */
    public function profilesForOppositeExecutionRoute(array $profiles, string $ruta): array
    {
        return array_values(array_filter(
            $profiles,
            static fn (array $profile): bool => in_array(
                $profile['rutaCalculo'],
                ['PROSTATA', 'CERVIX'],
                true
            ) && $profile['rutaCalculo'] !== $ruta
        ));
    }

    /**
     * Aplica controles clínicos propios de la ejecución. Los registros no se
     * eliminan: quedan marcados para corrección y trazabilidad en el Excel.
     *
     * @param  array<int, array<string, mixed>>  $profiles
     * @return array<int, array<string, mixed>>
     */
    public function validateRouteConsistency(array $profiles, string $ruta): array
    {
        $expectedSex = $ruta === 'CERVIX' ? 'F' : 'M';

        foreach ($profiles as &$profile) {
            $profile['alertasClinicas'] ??= [];
            if (($profile['sexo'] ?? '') !== '' && $profile['sexo'] !== $expectedSex) {
                $sexoDescripcion = $profile['sexo'] === 'F' ? 'MUJER' : 'HOMBRE';
                $profile['alertasClinicas'][] = sprintf(
                    '%s recibido al generar %s; se conserva para revisión y su costo se asigna a %s.',
                    $sexoDescripcion,
                    $ruta,
                    $profile['rutaCalculo']
                );
                $profile['estado'] = 'REVISAR';
                $profile['motivoRevision'] = trim(
                    $profile['motivoRevision'].' '.end($profile['alertasClinicas'])
                );
            }
        }
        unset($profile);

        return $profiles;
    }

    /**
     * @param  array<int, array{path: string, fileName: string}>  $files
     * @param  array<int, array<string, mixed>>  $profiles
     * @return array<int, array<string, mixed>>
     */
    public function profilesWithReferenceCostsForFiles(
        array $files,
        array $profiles,
        ReferenciaRutaMatcher $referenciaRutaMatcher,
        ?string $rutaEjecucion = null
    ): array {
        $profilesByKey = [];

        foreach ($profiles as $index => $profile) {
            $profilesByKey[$profile['profileKey']] = $index;
        }

        foreach ($files as $file) {
            foreach ($this->recordsForFile($file) as $record) {
                $profileIndex = $profilesByKey[$record['profileKey']] ?? null;

                if ($profileIndex === null) {
                    continue;
                }

                $reference = $referenciaRutaMatcher->match(
                    $record,
                    $this->matchRoute($profiles[$profileIndex]['rutaCalculo'], $rutaEjecucion)
                );
                $profiles[$profileIndex]['valorRipsReportado'] += $reference['valorRipsReportado'];
                $profiles[$profileIndex]['costoEstimadoReferencia'] += $reference['costoEstimadoReferencia'];

                if ($reference['tarifaReferencia'] > 0) {
                    $profiles[$profileIndex]['serviciosConTarifa']++;
                } else {
                    $profiles[$profileIndex]['serviciosSinTarifa']++;
                }
            }
        }

        return $profiles;
    }

    /**
     * @param  array<int, array{path: string, fileName: string}>  $files
     * @param  array<int, array<string, mixed>>  $profiles
     * @return \Generator<int, array<string, int|float|string>, mixed, void>
     */
    public function detailRowsForFiles(
        array $files,
        array $profiles,
        ?ReferenciaRutaMatcher $referenciaRutaMatcher = null,
        ?string $rutaEjecucion = null
    ): \Generator {
        $profilesByKey = [];

        foreach ($profiles as $profile) {
            $profilesByKey[$profile['profileKey']] = $profile;
        }

        foreach ($files as $file) {
            foreach ($this->recordsForFile($file) as $record) {
                $profile = $profilesByKey[$record['profileKey']] ?? null;

                if ($profile === null) {
                    continue;
                }

                $reference = $referenciaRutaMatcher === null
                    ? $this->defaultReferenceMatch($record)
                    : $referenciaRutaMatcher->match(
                        $record,
                        $this->matchRoute($profile['rutaCalculo'], $rutaEjecucion)
                    );

                yield [
                    'mesAtencion' => $record['mesAtencion'],
                    'factura' => $record['factura'],
                    'codPrestador' => $record['codPrestador'] ?? '',
                    'tipoDocumentoIdentificacion' => $record['tipoDocumentoIdentificacion'],
                    'numDocumentoIdentificacion' => $record['numDocumentoIdentificacion'],
                    'sexo' => $profile['sexo'] ?? $record['sexo'] ?? '',
                    'rutaFinal' => $profile['rutaFinal'],
                    'rutaCalculo' => $profile['rutaCalculo'],
                    'estado' => $profile['estado'],
                    'motivoClasificacion' => $profile['motivoRevision'],
                    'fechaAtencion' => $record['fechaAtencion'],
                    'tipoServicio' => $record['tipoServicio'],
                    'codigo' => $record['codigo'],
                    'nombreServicio' => $record['nombreServicio'],
                    'finalidadTecnologiaSalud' => $record['finalidadTecnologiaSalud'] ?? '',
                    'causaMotivoAtencion' => $record['causaMotivoAtencion'] ?? '',
                    'diagnosticoPrincipal' => $record['diagnosticoPrincipal'],
                    'autorizacion' => $record['autorizacion'],
                    'cantidad' => $record['cantidad'],
                    'unidadMinDispensa' => $record['unidadMinDispensa'],
                    'ambito' => $record['ambito'],
                    'tipoUsuario' => $record['tipoUsuario'] ?? '',
                    'codPaisOrigen' => $record['codPaisOrigen'] ?? '',
                    'codPaisResidencia' => $record['codPaisResidencia'] ?? '',
                    'incapacidad' => $record['incapacidad'] ?? '',
                    'registroSiras' => $record['registroSiras'] ?? '',
                    'consecutivoUsuario' => $record['consecutivoUsuario'] ?? '',
                    'consecutivoRips' => $record['consecutivoRips'] ?? '',
                    'idMipres' => $record['idMipres'] ?? '',
                    'modalidadGrupoServicio' => $record['modalidadGrupoServicio'] ?? '',
                    'grupoServicios' => $record['grupoServicios'] ?? '',
                    'codServicio' => $record['codServicio'] ?? '',
                    'viaIngresoServicio' => $record['viaIngresoServicio'] ?? '',
                    'tipoMedicamento' => $record['tipoMedicamento'] ?? '',
                    'tipoOtroServicio' => $record['tipoOtroServicio'] ?? '',
                    'concentracionMedicamento' => $record['concentracionMedicamento'] ?? '',
                    'unidadMedida' => $record['unidadMedida'] ?? '',
                    'formaFarmaceutica' => $record['formaFarmaceutica'] ?? '',
                    'diasTratamiento' => $record['diasTratamiento'] ?? '',
                    'diagnosticoPrincipalCie11' => $record['diagnosticoPrincipalCie11'] ?? '',
                    'nombreDiagnosticoPrincipalCie11' => $record['nombreDiagnosticoPrincipalCie11'] ?? '',
                    'diagnosticosRelacionados' => $record['diagnosticosRelacionados'] ?? '',
                    'diagnosticosRelacionadosCie11' => $record['diagnosticosRelacionadosCie11'] ?? '',
                    'nombresDiagnosticosRelacionadosCie11' => $record['nombresDiagnosticosRelacionadosCie11'] ?? '',
                    'tipoDiagnosticoPrincipal' => $record['tipoDiagnosticoPrincipal'] ?? '',
                    'codComplicacion' => $record['codComplicacion'] ?? '',
                    'codComplicacionCie11' => $record['codComplicacionCie11'] ?? '',
                    'nombreComplicacionCie11' => $record['nombreComplicacionCie11'] ?? '',
                    'valorUnitarioRips' => $record['valorUnitarioRips'] ?? '',
                    'valorDispensacionRips' => $record['valorDispensacionRips'] ?? '',
                    'conceptoRecaudo' => $record['conceptoRecaudo'] ?? '',
                    'valorPagoModerador' => $record['valorPagoModerador'] ?? '',
                    'numFevPagoModerador' => $record['numFevPagoModerador'] ?? '',
                    'codigoVida' => $record['codigoVida'] ?? '',
                    'computaEjecucion' => 'SI',
                    'motivoExclusion' => '',
                    'archivoOrigen' => $record['archivoOrigen'],
                    ...$reference,
                ];
            }
        }
    }

    /**
     * @param  array<int, array{path: string, fileName: string}>  $files
     * @return \Generator<int, array<string, int|string>, mixed, void>
     */
    public function controlRowsForFiles(array $files): \Generator
    {
        foreach ($files as $file) {
            $factura = $this->invoiceFromJsonFile($file['path']);
            $userCount = 0;
            $serviceCount = 0;
            $servicesWithoutDate = 0;
            $missingIdentity = 0;
            $months = [];

            foreach ($this->usersForFile($file['path']) as $position => $usuario) {
                if (! is_array($usuario)) {
                    continue;
                }

                $userCount++;

                foreach ($this->recordsForUser(
                    $usuario,
                    $factura,
                    $file['fileName'],
                    (string) $position
                ) as $record) {
                    $serviceCount++;

                    if ($record['hasMissingIdentity']) {
                        $missingIdentity++;
                    }

                    if ($record['hasMissingDate']) {
                        $servicesWithoutDate++;
                    } else {
                        $months[$record['mesAtencion']] = true;
                    }
                }
            }

            $observations = [];

            if ($factura === '') {
                $observations[] = 'Falta numFactura.';
            }

            if ($userCount === 0) {
                $observations[] = 'No se encontraron usuarios.';
            }

            if ($serviceCount === 0) {
                $observations[] = 'No se encontraron servicios.';
            }

            if ($missingIdentity > 0) {
                $observations[] = 'Hay servicios sin identificación completa.';
            }

            if ($servicesWithoutDate > 0) {
                $observations[] = 'Hay servicios sin fecha para asignar el mes.';
            }

            $months = array_keys($months);
            sort($months);

            yield [
                'archivoOrigen' => $file['fileName'],
                'factura' => $factura,
                'userCount' => $userCount,
                'serviceCount' => $serviceCount,
                'servicesWithoutDate' => $servicesWithoutDate,
                'firstMonth' => $months[0] ?? '',
                'lastMonth' => $months === [] ? '' : $months[array_key_last($months)],
                'estadoCargue' => $observations === [] ? 'PROCESADO' : 'REVISAR',
                'observacion' => implode(' ', $observations),
            ];
        }
    }

    /**
     * @param  array{path: string, fileName: string}  $file
     * @return \Generator<int, array<string, int|float|string|bool>, mixed, void>
     */
    private function recordsForFile(array $file): \Generator
    {
        $factura = $this->invoiceFromJsonFile($file['path']);

        foreach ($this->usersForFile($file['path']) as $position => $usuario) {
            if (! is_array($usuario)) {
                continue;
            }

            foreach ($this->recordsForUser(
                $usuario,
                $factura,
                $file['fileName'],
                (string) $position
            ) as $record) {
                yield $record;
            }
        }
    }

    /**
     * @return iterable<int|string, mixed>
     */
    private function usersForFile(string $path): iterable
    {
        return Items::fromFile(
            $path,
            [
                'pointer' => '/usuarios',
                'decoder' => new ExtJsonDecoder(true),
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $usuario
     * @return \Generator<int, array<string, int|float|string|bool>, mixed, void>
     */
    private function recordsForUser(
        array $usuario,
        string $factura,
        string $archivoOrigen,
        string $position
    ): \Generator {
        $servicios = $usuario['servicios'] ?? [];

        if (! is_array($servicios)) {
            return;
        }

        $tipoDocumento = $this->stringValue($usuario['tipoDocumentoIdentificacion'] ?? null);
        $documento = $this->stringValue($usuario['numDocumentoIdentificacion'] ?? null);
        $sexo = $this->normaliseSex($this->firstStringValue(
            $usuario,
            ['codSexo', 'sexo', 'codigoSexo', 'tipoSexoBiologico']
        ));
        $fechaNacimiento = $this->normaliseDate($this->firstStringValue(
            $usuario,
            ['fechaNacimiento', 'fechaDeNacimiento', 'fecha_nacimiento']
        ));
        $municipioCode = $this->firstStringValue(
            $usuario,
            [
                'codMunicipioResidencia',
                'codMunicipio',
                'municipioResidencia',
                'municipio',
                'codigoMunicipioResidencia',
            ]
        );
        $municipio = $this->geographyCatalog->municipalityName($municipioCode);
        $zona = $this->normaliseZone($this->firstStringValue(
            $usuario,
            [
                'codZonaTerritorialResidencia',
                'codZonaTerritorial',
                'zonaTerritorialResidencia',
                'zonaTerritorial',
                'zona',
            ]
        ));
        $tipoUsuario = $this->stringValue($usuario['tipoUsuario'] ?? null);
        $codPaisOrigen = $this->geographyCatalog->countryName(
            $this->stringValue($usuario['codPaisOrigen'] ?? null)
        );
        $codPaisResidencia = $this->geographyCatalog->countryName(
            $this->stringValue($usuario['codPaisResidencia'] ?? null)
        );
        $incapacidad = $this->stringValue($usuario['incapacidad'] ?? null);
        $registroSiras = $this->stringValue($usuario['registroSIRAS'] ?? null);
        $consecutivoUsuario = $this->stringValue($usuario['consecutivo'] ?? null);
        $codPrestadorUsuario = $this->stringValue($usuario['codPrestador'] ?? null);
        $hasMissingIdentity = $tipoDocumento === '' || $documento === '';
        $hospitalRanges = $this->hospitalizationRanges($servicios['hospitalizacion'] ?? []);

        foreach ($servicios as $tipoServicio => $listaServicios) {
            if (! is_array($listaServicios)) {
                continue;
            }

            foreach ($listaServicios as $servicio) {
                if (! is_array($servicio)) {
                    continue;
                }

                $tipoServicio = $this->stringValue($tipoServicio);
                $fechaAtencion = $this->serviceDate($tipoServicio, $servicio);
                $mesAtencion = $this->reportingMonth($fechaAtencion);
                $diagnostico = $this->principalDiagnosis($servicio);
                $codPrestador = $this->stringValue($servicio['codPrestador'] ?? null);

                if ($codPrestador === '') {
                    $codPrestador = $codPrestadorUsuario;
                }

                yield [
                    'profileKey' => $this->profileKey(
                        $tipoDocumento,
                        $documento,
                        $factura,
                        $position,
                        $mesAtencion
                    ),
                    'factura' => $factura,
                    'archivoOrigen' => $archivoOrigen,
                    'tipoDocumentoIdentificacion' => $tipoDocumento,
                    'numDocumentoIdentificacion' => $documento,
                    'sexo' => $sexo,
                    'fechaNacimiento' => $fechaNacimiento,
                    'municipio' => $municipio,
                    'zona' => $zona,
                    'tipoUsuario' => $tipoUsuario,
                    'codPaisOrigen' => $codPaisOrigen,
                    'codPaisResidencia' => $codPaisResidencia,
                    'incapacidad' => $incapacidad,
                    'registroSiras' => $registroSiras,
                    'consecutivoUsuario' => $consecutivoUsuario,
                    'codPrestador' => $codPrestador,
                    'hasMissingIdentity' => $hasMissingIdentity,
                    'hasIdentityMismatch' => $this->serviceIdentityMismatch(
                        $servicio,
                        $tipoDocumento,
                        $documento
                    ),
                    'hasMissingDate' => $mesAtencion === '',
                    'mesAtencion' => $mesAtencion,
                    'tipoServicio' => $tipoServicio,
                    'codigo' => $this->serviceCode($tipoServicio, $servicio),
                    'nombreServicio' => $this->firstStringValue(
                        $servicio,
                        ['nomTecnologiaSalud', 'nomMedicamento', 'nombreTecnologiaSalud', 'nombreServicio']
                    ),
                    'finalidadTecnologiaSalud' => $this->stringValue(
                        $servicio['finalidadTecnologiaSalud'] ?? null
                    ),
                    'causaMotivoAtencion' => $this->stringValue(
                        $servicio['causaMotivoAtencion'] ?? null
                    ),
                    'diagnosticoPrincipal' => $diagnostico,
                    'routeEvidence' => $this->routeEvidence($diagnostico),
                    'fechaAtencion' => $fechaAtencion,
                    'autorizacion' => $this->firstStringValue(
                        $servicio,
                        ['numAutorizacion', 'numPrescripcion', 'numMIPRES']
                    ),
                    'cantidad' => $tipoServicio === 'medicamentos'
                        ? $this->medicationQuantity($servicio)
                        : $this->firstNumericValue(
                            $servicio,
                            ['cantidad', 'cantDispensada', 'cantSuministrada', 'cantidadOS']
                        ),
                    'ambito' => $this->derivedAmbit(
                        $tipoServicio,
                        $servicio,
                        $fechaAtencion,
                        $hospitalRanges
                    ),
                    'unidadMinDispensa' => $this->firstNumericValue($servicio, ['unidadMinDispensa']),
                    'consecutivoRips' => $this->stringValue($servicio['consecutivo'] ?? null),
                    'idMipres' => $this->stringValue($servicio['idMIPRES'] ?? null),
                    'modalidadGrupoServicio' => $this->stringValue(
                        $servicio['modalidadGrupoServicioTecSal'] ?? null
                    ),
                    'grupoServicios' => $this->stringValue($servicio['grupoServicios'] ?? null),
                    'codServicio' => $this->stringValue($servicio['codServicio'] ?? null),
                    'viaIngresoServicio' => $this->stringValue(
                        $servicio['viaIngresoServicioSalud'] ?? null
                    ),
                    'tipoMedicamento' => $this->stringValue($servicio['tipoMedicamento'] ?? null),
                    'tipoOtroServicio' => $this->stringValue($servicio['tipoOS'] ?? null),
                    'concentracionMedicamento' => $this->stringValue(
                        $servicio['concentracionMedicamento'] ?? null
                    ),
                    'unidadMedida' => $this->stringValue($servicio['unidadMedida'] ?? null),
                    'formaFarmaceutica' => $this->stringValue($servicio['formaFarmaceutica'] ?? null),
                    'diasTratamiento' => $this->firstNumericValue($servicio, ['diasTratamiento']),
                    'diagnosticoPrincipalCie11' => $this->stringValue(
                        $servicio['codDiagnosticoPrincipalCIE11'] ?? null
                    ),
                    'nombreDiagnosticoPrincipalCie11' => $this->stringValue(
                        $servicio['nomCodDiagnosticoPrincipalCIE11'] ?? null
                    ),
                    'diagnosticosRelacionados' => $this->joinedValues(
                        $servicio,
                        [
                            'codDiagnosticoRelacionado',
                            'codDiagnosticoRelacionado1',
                            'codDiagnosticoRelacionado2',
                            'codDiagnosticoRelacionado3',
                        ]
                    ),
                    'diagnosticosRelacionadosCie11' => $this->joinedValues(
                        $servicio,
                        [
                            'codDiagnosticoRelacionadoCIE11',
                            'codDiagnosticoRelacionado1CIE11',
                            'codDiagnosticoRelacionado2CIE11',
                            'codDiagnosticoRelacionado3CIE11',
                        ]
                    ),
                    'nombresDiagnosticosRelacionadosCie11' => $this->joinedValues(
                        $servicio,
                        [
                            'nomCodDiagnosticoRelacionadoCIE11',
                            'nomCodDiagnosticoRelacionado1CIE11',
                            'nomCodDiagnosticoRelacionado2CIE11',
                            'nomCodDiagnosticoRelacionado3CIE11',
                        ]
                    ),
                    'tipoDiagnosticoPrincipal' => $this->stringValue(
                        $servicio['tipoDiagnosticoPrincipal'] ?? null
                    ),
                    'codComplicacion' => $this->stringValue($servicio['codComplicacion'] ?? null),
                    'codComplicacionCie11' => $this->stringValue(
                        $servicio['codComplicacionCIE11'] ?? null
                    ),
                    'nombreComplicacionCie11' => $this->stringValue(
                        $servicio['nomCodComplicacionCIE11'] ?? null
                    ),
                    'valorUnitarioRips' => $this->firstNumericValue(
                        $servicio,
                        ['vrUnitMedicamento', 'vrUnitOS', 'vrUnitServicio']
                    ),
                    'valorDispensacionRips' => $this->firstNumericValue(
                        $servicio,
                        ['vrDispensacion']
                    ),
                    'conceptoRecaudo' => $this->stringValue($servicio['conceptoRecaudo'] ?? null),
                    'valorPagoModerador' => $this->firstNumericValue(
                        $servicio,
                        ['valorPagoModerador']
                    ),
                    'numFevPagoModerador' => $this->stringValue(
                        $servicio['numFEVPagoModerador'] ?? null
                    ),
                    'codigoVida' => $this->stringValue($servicio['codigoVIDA'] ?? null),
                    'valorRips' => $this->reportedServiceValue($tipoServicio, $servicio),
                ];
            }
        }
    }

    /**
     * @param  array<string, int|float|string|bool>  $record
     * @return array<string, mixed>
     */
    private function newProfile(array $record): array
    {
        return [
            'profileKey' => $record['profileKey'],
            'mesAtencion' => $record['mesAtencion'],
            'tipoDocumentoIdentificacion' => $record['tipoDocumentoIdentificacion'],
            'numDocumentoIdentificacion' => $record['numDocumentoIdentificacion'],
            'sexo' => $record['sexo'],
            'fechaNacimiento' => $record['fechaNacimiento'] ?? '',
            'municipio' => $record['municipio'] ?? '',
            'zona' => $record['zona'] ?? '',
            'tipoUsuario' => $record['tipoUsuario'] ?? '',
            'codPaisOrigen' => $record['codPaisOrigen'] ?? '',
            'codPaisResidencia' => $record['codPaisResidencia'] ?? '',
            'incapacidad' => $record['incapacidad'] ?? '',
            'registroSiras' => $record['registroSiras'] ?? '',
            'consecutivoUsuario' => $record['consecutivoUsuario'] ?? '',
            'facturas' => [],
            'routeEvidence' => [],
            'diagnosticosReportados' => [],
            'serviceCount' => 0,
            'serviciosConTarifa' => 0,
            'serviciosSinTarifa' => 0,
            'valorRipsReportado' => 0.0,
            'costoEstimadoReferencia' => 0.0,
            'serviceCounts' => array_fill_keys(self::SERVICE_TYPES, 0),
            'primeraFechaAtencion' => '',
            'ultimaFechaAtencion' => '',
            'hasMissingIdentity' => $record['hasMissingIdentity'],
            'hasIdentityMismatch' => $record['hasIdentityMismatch'] ?? false,
            'hasMissingDate' => $record['hasMissingDate'],
            'alertasClinicas' => [],
        ];
    }

    private function profileKey(
        string $tipoDocumento,
        string $documento,
        string $factura,
        string $position,
        string $mesAtencion
    ): string {
        $monthKey = $mesAtencion === '' ? 'SIN_FECHA' : $mesAtencion;

        if ($tipoDocumento === '' || $documento === '') {
            return 'SIN_IDENTIFICACION|'.$factura.'|'.$position.'|'.$monthKey;
        }

        return 'USUARIO|'.strtoupper($tipoDocumento).'|'.$documento.'|'.$monthKey;
    }

    /**
     * @param  array<int, string>  $diagnoses
     */
    private function routeForEvidence(array $diagnoses): string
    {
        $hasProstate = in_array('C61', $diagnoses, true);
        $hasCervix = in_array('C53', $diagnoses, true);

        if ($hasProstate && $hasCervix) {
            return 'DOBLE_RUTA_REVISAR';
        }

        if ($hasProstate) {
            return 'PROSTATA';
        }

        if ($hasCervix) {
            return 'CERVIX';
        }

        return 'SIN_RUTA_DEFINIDA';
    }

    private function routeForSex(string $sexo): string
    {
        return match (strtoupper(trim($sexo))) {
            'M' => 'PROSTATA',
            'F' => 'CERVIX',
            default => 'SIN_RUTA_DEFINIDA',
        };
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function profileStatus(array $profile): string
    {
        if (in_array($profile['rutaFinal'], ['DOBLE_RUTA_REVISAR', 'SIN_RUTA_DEFINIDA'], true)) {
            return 'REVISAR';
        }

        if ($profile['hasMissingIdentity'] || $profile['hasMissingDate']) {
            return 'REVISAR';
        }

        return 'LISTO';
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function reviewReason(array $profile): string
    {
        $reasons = match ($profile['rutaFinal']) {
            'PROSTATA' => ['Diagnóstico C61 detectado.'],
            'CERVIX' => ['Diagnóstico C53 detectado.'],
            'DOBLE_RUTA_REVISAR' => ['Se detectaron diagnósticos C61 y C53 en el mismo mes.'],
            default => ['No se detectó diagnóstico C61 ni C53 en el mes.'],
        };

        if ($profile['hasMissingIdentity']) {
            $reasons[] = 'Falta tipo o número de documento.';
        }

        if ($profile['hasMissingDate']) {
            $reasons[] = 'No se pudo asignar el registro a un mes de atención.';
        }

        return implode(' ', $reasons);
    }

    /**
     * @param  array<string, int|float|string|bool>  $record
     */
    private function matchRoute(string $rutaFinal, ?string $rutaEjecucion): string
    {
        if (in_array($rutaFinal, ['PROSTATA', 'CERVIX'], true)) {
            return $rutaFinal;
        }

        return $rutaEjecucion ?? $rutaFinal;
    }

    /**
     * @param  array<string, mixed>  $servicio
     */
    private function principalDiagnosis(array $servicio): string
    {
        return strtoupper($this->firstStringValue(
            $servicio,
            ['codDiagnosticoPrincipal', 'codDiagnosticoPrincipalCIE11']
        ));
    }

    /**
     * @param  array<string, mixed>  $servicio
     */
    private function serviceCode(string $tipoServicio, array $servicio): string
    {
        $fields = match ($tipoServicio) {
            'consultas' => ['codConsulta'],
            'procedimientos' => ['codProcedimiento'],
            'medicamentos' => ['codTecnologiaSalud', 'codMedicamento'],
            'otrosServicios' => ['codTecnologiaSalud'],
            'urgencias', 'hospitalizacion' => ['codServicio'],
            default => ['codTecnologiaSalud', 'codServicio'],
        };

        return $this->firstStringValue($servicio, $fields);
    }

    /**
     * @param  array<string, mixed>  $servicio
     */
    private function serviceDate(string $tipoServicio, array $servicio): string
    {
        $fields = match ($tipoServicio) {
            'otrosServicios' => ['fechaSuministroTecnologia', 'fechaInicioAtencion'],
            'medicamentos' => ['fechaDispensAdmon', 'fechaInicioAtencion'],
            'urgencias', 'hospitalizacion' => ['fechaInicioAtencion', 'fechaAtencion', 'fechaEgreso'],
            default => ['fechaInicioAtencion', 'fechaAtencion'],
        };

        return $this->firstStringValue($servicio, $fields);
    }

    private function routeEvidence(string $diagnostico): string
    {
        if (str_starts_with($diagnostico, 'C61')) {
            return 'C61';
        }

        if (str_starts_with($diagnostico, 'C53')) {
            return 'C53';
        }

        return '';
    }

    private function reportingMonth(string $fechaAtencion): string
    {
        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])-\d{2}/', $fechaAtencion, $matches) !== 1) {
            return '';
        }

        return $matches[1].'-'.$matches[2];
    }

    private function invoiceFromJsonFile(string $path): string
    {
        try {
            $facturas = Items::fromFile(
                $path,
                [
                    'pointer' => '/numFactura',
                    'decoder' => new ExtJsonDecoder(true),
                ]
            );

            foreach ($facturas as $factura) {
                return $this->stringValue($factura);
            }
        } catch (\Throwable) {
            return '';
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $fields
     */
    private function firstStringValue(array $data, array $fields): string
    {
        foreach ($fields as $field) {
            $value = $this->stringValue($data[$field] ?? null);

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $fields
     */
    private function firstNumericValue(array $data, array $fields): int|float|string
    {
        foreach ($fields as $field) {
            $value = $data[$field] ?? null;

            if (is_int($value) || is_float($value)) {
                return $value;
            }

            if (is_string($value) && is_numeric($value)) {
                return str_contains($value, '.') ? (float) $value : (int) $value;
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $fields
     */
    private function joinedValues(array $data, array $fields): string
    {
        $values = [];

        foreach ($fields as $field) {
            $value = $this->stringValue($data[$field] ?? null);

            if ($value !== '') {
                $values[$value] = true;
            }
        }

        return implode(', ', array_keys($values));
    }

    /**
     * @param  array<string, mixed>  $servicio
     */
    private function reportedServiceValue(string $tipoServicio, array $servicio): float
    {
        $fields = match ($tipoServicio) {
            'medicamentos' => ['vrServicio', 'valorServicio', 'vrUnitMedicamento'],
            'otrosServicios' => ['vrServicio', 'valorServicio', 'vrUnitOS'],
            default => ['vrServicio', 'valorServicio', 'vrUnitServicio'],
        };

        foreach ($fields as $field) {
            $value = $servicio[$field] ?? null;

            if (is_int($value) || is_float($value)) {
                return (float) $value;
            }

            if (is_string($value) && is_numeric($value)) {
                return (float) $value;
            }
        }

        return 0.0;
    }

    /**
     * Los RIPS AM informan cantidad de presentaciones y unidades mínimas por
     * presentación. La contratación se liquida por unidad, no por línea.
     */
    private function medicationQuantity(array $servicio): int|float|string
    {
        return $this->firstNumericValue($servicio, ['cantidadMedicamento', 'cantidad']);
    }

    /**
     * @param  array<string, int|float|string|bool>  $record
     * @return array<string, float|int|string>
     */
    private function defaultReferenceMatch(array $record): array
    {
        return [
            'descripcionOficial' => '',
            'nombreNotaTecnica' => '',
            'encontradoEnNotaTecnica' => false,
            'catalogoCruce' => '',
            'grupoTecnico' => '',
            'valorRipsReportado' => (float) $record['valorRips'],
            'tarifaReferencia' => 0.0,
            'costoEstimadoReferencia' => 0.0,
            'estadoCruce' => 'SIN_CATALOGO_ACTIVO',
            'cum' => '',
            'principioActivo' => '',
            'atc' => '',
            'esquemaOncologico' => '',
            'tipoValorReferencia' => 'SIN_TARIFA',
        ];
    }

    private function earlierDate(string $currentDate, string $candidateDate): string
    {
        if ($currentDate === '' || strcmp($candidateDate, $currentDate) < 0) {
            return $candidateDate;
        }

        return $currentDate;
    }

    private function laterDate(string $currentDate, string $candidateDate): string
    {
        if ($currentDate === '' || strcmp($candidateDate, $currentDate) > 0) {
            return $candidateDate;
        }

        return $currentDate;
    }

    private function stringValue(mixed $value): string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return trim((string) $value);
    }

    private function normaliseSex(string $sexo): string
    {
        $sexo = strtoupper(trim($sexo));

        return match ($sexo) {
            '1', '01', 'M', 'MASCULINO', 'HOMBRE' => 'M',
            '2', '02', 'F', 'FEMENINO', 'MUJER' => 'F',
            default => $sexo,
        };
    }

    private function normaliseDate(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $matches) === 1) {
            return $matches[1].'-'.$matches[2].'-'.$matches[3];
        }

        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $value, $matches) === 1) {
            return $matches[3].'-'.$matches[2].'-'.$matches[1];
        }

        return $value;
    }

    private function normaliseZone(string $zona): string
    {
        $zona = strtoupper(trim($zona));

        return match ($zona) {
            '01', '1', 'U', 'URBANA', 'URBANO' => 'URBANA',
            '02', '2', 'R', 'RURAL' => 'RURAL',
            default => $zona,
        };
    }

    private function serviceIdentityMismatch(array $servicio, string $tipoDocumento, string $documento): bool
    {
        $serviceType = $this->stringValue($servicio['tipoDocumentoIdentificacion'] ?? null);
        $serviceDocument = $this->stringValue($servicio['numDocumentoIdentificacion'] ?? null);

        return ($serviceType !== '' && $serviceType !== $tipoDocumento)
            || ($serviceDocument !== '' && $serviceDocument !== $documento);
    }

    private function normaliseAmbit(string $ambito): string
    {
        return match (strtoupper(trim($ambito))) {
            'A', 'AMBULATORIA', 'AMBULATORIO' => 'A',
            'H', 'HOSPITALARIA', 'HOSPITALARIO' => 'H',
            'U', 'URGENCIAS', 'URGENCIA' => 'U',
            default => strtoupper(trim($ambito)),
        };
    }

    /**
     * @param  array<int, mixed>  $hospitalizations
     * @return array<int, array{inicio: string, fin: string}>
     */
    private function hospitalizationRanges(array $hospitalizations): array
    {
        $ranges = [];

        foreach ($hospitalizations as $hospitalization) {
            if (! is_array($hospitalization)) {
                continue;
            }

            $inicio = $this->firstStringValue($hospitalization, ['fechaInicioAtencion', 'fechaIngreso']);
            $fin = $this->firstStringValue($hospitalization, ['fechaEgreso', 'fechaFinAtencion']);

            if ($inicio !== '' && $fin !== '') {
                $ranges[] = ['inicio' => $inicio, 'fin' => $fin];
            }
        }

        return $ranges;
    }

    /**
     * @param  array<int, array{inicio: string, fin: string}>  $hospitalRanges
     */
    private function derivedAmbit(
        string $tipoServicio,
        array $servicio,
        string $fechaAtencion,
        array $hospitalRanges
    ): string {
        $explicit = $this->normaliseAmbit(
            $this->firstStringValue($servicio, ['ambito', 'codAmbito', 'tipoAmbito'])
        );

        if ($explicit !== '') {
            return $explicit;
        }

        if ($tipoServicio === 'hospitalizacion') {
            return 'H';
        }

        foreach ($hospitalRanges as $range) {
            if ($fechaAtencion >= $range['inicio'] && $fechaAtencion <= $range['fin']) {
                return 'H';
            }
        }

        return '';
    }
}
