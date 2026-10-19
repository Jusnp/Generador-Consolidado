<?php

namespace App\Services;

use App\Programas\LaMaria\AlmacenContinuidadHospitalaria;
use App\Programas\LaMaria\ClaseCosto;
use App\Programas\LaMaria\CondicionArchivo;
use App\Programas\LaMaria\DecisionPaqueteHospitalario;
use App\Programas\LaMaria\EstadoClinico;
use App\Programas\LaMaria\EstadoDuplicidad;
use App\Programas\LaMaria\EstadoTarifario;
use App\Programas\LaMaria\FormaCruce;
use App\Programas\LaMaria\FuenteTarifa;
use App\Programas\LaMaria\LibroPaquetesHospitalarios;
use App\Programas\LaMaria\OrigenRuta;
use App\Programas\LaMaria\PoliticaPaqueteHospitalario;
use App\Programas\LaMaria\ReconstructorEpisodiosHospitalarios;
use App\Programas\LaMaria\RutaCalculo;
use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;

class SeguimientoRutaClassifier
{
    private RipsGeographyCatalog $geographyCatalog;

    private PoliticaPaqueteHospitalario $politicaPaquete;

    /**
     * Estancias hospitalarias lógicas del lote en evaluación.
     *
     * @var array{key: ?string, episodios: list<array<string, mixed>>, porSegmento: array<string, int>, segmentos: array<string, array<string, mixed>>}
     */
    private array $hospitalEpisodes = ['key' => null, 'episodios' => [], 'porSegmento' => [], 'segmentos' => []];

    /** @var array<string, int> Número de paquetes netos por segmento del lote evaluado. */
    private array $paquetesSumadosPorSegmento = [];

    /**
     * Libro de paquetes persistente (modo PERSISTENTE): segmentos ya conocidos,
     * inicio del libro por ruta y si la carga es una corrección autorizada.
     *
     * @var array{almacen: AlmacenContinuidadHospitalaria, inicioPorRuta: array<string, string>, esCorreccion: bool}|null
     */
    private ?array $libroPersistente = null;

    /**
     * Modo PERSISTENTE: la reconstrucción de episodios usa también los segmentos
     * vigentes ya registrados de los pacientes del lote; los bloques de otras
     * ejecuciones no se vuelven a imputar y los de meses anteriores al inicio del
     * libro se informan como simulación, sin sumar.
     *
     * @param  array<string, string>  $inicioPorRuta
     */
    public function usarLibroPersistente(AlmacenContinuidadHospitalaria $almacen, array $inicioPorRuta, bool $esCorreccion = false): void
    {
        $this->libroPersistente = ['almacen' => $almacen, 'inicioPorRuta' => $inicioPorRuta, 'esCorreccion' => $esCorreccion];
        $this->hospitalEpisodes['key'] = null;
    }

    public function __construct(
        ?RipsGeographyCatalog $geographyCatalog = null,
        ?PoliticaPaqueteHospitalario $politicaPaquete = null
    ) {
        $this->geographyCatalog = $geographyCatalog ?? new RipsGeographyCatalog;
        $this->politicaPaquete = $politicaPaquete ?? new PoliticaPaqueteHospitalario;
    }

    /**
     * @var array<int, string>
     */
    /**
     * Motivo de no suma de un paquete de un mes anterior al inicio del libro persistente.
     */
    public const PERIODO_HISTORICO_NO_VERIFICADO = 'PERIODO_HISTORICO_NO_VERIFICADO';

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
     * Diagnósticos que se conservan como evidencia clínica secundaria cuando
     * aparecen como diagnóstico relacionado, de egreso o complicación.
     *
     * @var array<int, string>
     */
    private const SECONDARY_EVIDENCE_PREFIXES = ['C61', 'C53', 'D06', 'D07', 'C54', 'C55'];

    /**
     * Diagnósticos principales que no definen ruta pero se conservan como evidencia.
     *
     * @var array<int, string>
     */
    private const PRINCIPAL_SECONDARY_PREFIXES = ['D06', 'D07', 'C54', 'C55'];

    /**
     * @var array<int, string>
     */
    private const RELATED_DIAGNOSIS_FIELDS = [
        'codDiagnosticoRelacionado',
        'codDiagnosticoRelacionado1',
        'codDiagnosticoRelacionado2',
        'codDiagnosticoRelacionado3',
        'codDiagnosticoPrincipalE',
        'codDiagnosticoRelacionadoE1',
        'codDiagnosticoRelacionadoE2',
        'codDiagnosticoRelacionadoE3',
        'codComplicacion',
    ];

    /**
     * Identifica archivos idénticos (mismo hash) y facturas repetidas con
     * contenido diferente antes de procesar. No descarta información: la
     * condición queda en cada archivo para el control de cargue.
     *
     * @param  array<int, array{path: string, fileName: string}>  $files
     * @return array<int, array<string, string>>
     */
    public function screenFiles(array $files): array
    {
        $screened = [];
        $firstFileByHash = [];

        foreach ($files as $file) {
            $hash = (string) hash_file('sha256', $file['path']);
            $entry = [
                ...$file,
                'hash' => $hash,
                'factura' => $this->scalarFromJsonFile($file['path'], '/numFactura'),
                'tipoNota' => $this->scalarFromJsonFile($file['path'], '/tipoNota'),
                'numNota' => $this->scalarFromJsonFile($file['path'], '/numNota'),
                'condicion' => CondicionArchivo::Unico->value,
                'relacionados' => '',
            ];

            if (isset($firstFileByHash[$hash])) {
                $entry['condicion'] = CondicionArchivo::ArchivoDuplicadoExcluido->value;
                $entry['relacionados'] = $firstFileByHash[$hash];
            } else {
                $firstFileByHash[$hash] = $file['fileName'];
            }

            $screened[] = $entry;
        }

        $filesByInvoice = [];

        foreach ($screened as $index => $entry) {
            if ($entry['condicion'] === CondicionArchivo::Unico->value && $entry['factura'] !== '') {
                $filesByInvoice[$entry['factura']][] = $index;
            }
        }

        foreach ($filesByInvoice as $indexes) {
            if (count($indexes) < 2) {
                continue;
            }

            foreach ($indexes as $index) {
                $screened[$index]['condicion'] = CondicionArchivo::FacturaRepetidaConDiferencias->value;
                $screened[$index]['relacionados'] = implode(', ', array_map(
                    static fn (int $other): string => $screened[$other]['fileName'],
                    array_values(array_filter($indexes, static fn (int $other): bool => $other !== $index))
                ));
            }
        }

        return $screened;
    }

    /**
     * Archivos que no son JSON válido o no traen la lista RIPS de usuarios.
     * Se informan por nombre antes de procesar: nunca se omiten en silencio.
     *
     * @param  array<int, array{path: string, fileName: string}>  $files
     * @return array<string, string> Nombre del archivo => motivo del rechazo.
     */
    public function invalidStructureFiles(array $files): array
    {
        $invalid = [];

        foreach ($files as $file) {
            $content = @file_get_contents($file['path']);

            if ($content === false || ! json_validate($content)) {
                $invalid[$file['fileName']] = 'No es un JSON válido o está incompleto.';

                continue;
            }

            unset($content);

            try {
                $hasUsers = false;

                foreach ($this->usersForFile($file['path']) as $usuario) {
                    $hasUsers = is_array($usuario);

                    break;
                }
            } catch (\Throwable) {
                $hasUsers = false;
            }

            if (! $hasUsers) {
                $invalid[$file['fileName']] = 'No contiene la estructura RIPS esperada (usuarios).';
            }
        }

        return $invalid;
    }

    /**
     * @param  array<int, array<string, string>>  $files
     * @return array<int, array<string, string>>
     */
    public function processableFiles(array $files): array
    {
        return array_values(array_filter(
            $files,
            static fn (array $file): bool => ($file['condicion'] ?? CondicionArchivo::Unico->value)
                !== CondicionArchivo::ArchivoDuplicadoExcluido->value
        ));
    }

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

                foreach ($record['evidenciaSecundaria'] as $evidence) {
                    $profile['evidenciaSecundaria'][$evidence] = true;
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
            $diagnosticosReportados = array_keys($profile['diagnosticosReportados'] ?? []);
            sort($diagnosticosReportados);

            $evidenciaSecundaria = array_keys($profile['evidenciaSecundaria']);
            sort($evidenciaSecundaria);

            $profile['facturas'] = array_keys($profile['facturas']);
            sort($profile['facturas']);
            $profile['cantidadFacturas'] = count($profile['facturas']);
            $profile['diagnosticosDetectados'] = implode(', ', $diagnosticosReportados);
            $profile['evidenciaSecundaria'] = implode(', ', $evidenciaSecundaria);
            $profile = [...$profile, ...$this->resolveRoute($profile)];
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
     * Perfiles que se procesan y suman dentro de una ejecución de La María.
     *
     * @param  array<int, array<string, mixed>>  $profiles
     * @return array<int, array<string, mixed>>
     */
    public function profilesForExecutionRoute(array $profiles, string $ruta): array
    {
        return array_values(array_filter(
            $profiles,
            static fn (array $profile): bool => $profile['ruta'] === $ruta
        ));
    }

    /**
     * Perfiles sin ruta asignable (doble ruta o sexo no reconocido). Se muestran
     * en ambas ejecuciones como informativos y no suman en ninguna.
     *
     * @param  array<int, array<string, mixed>>  $profiles
     * @return array<int, array<string, mixed>>
     */
    public function profilesNotAssignable(array $profiles): array
    {
        return $this->profilesForExecutionRoute($profiles, RutaCalculo::NoAsignable->value);
    }

    /**
     * Concilia los pacientes del JSON contra los pacientes representados en el
     * resultado. La llave es tipo + número de documento; un usuario sin
     * documento se concilia con una llave técnica interna (factura + posición)
     * que nunca se exporta como documento.
     *
     * @param  array<int, array<string, mixed>>  $files
     * @param  array<int, array<string, mixed>>  $profiles
     * @return array{pacientesJson: int, representados: int, porRuta: array<string, int>, noRepresentados: list<array<string, string>>}
     */
    public function patientConciliationForFiles(array $files, array $profiles): array
    {
        $jsonPatients = [];

        foreach ($files as $file) {
            $factura = $file['factura'] ?? $this->scalarFromJsonFile($file['path'], '/numFactura');

            foreach ($this->usersForFile($file['path']) as $position => $usuario) {
                if (! is_array($usuario)) {
                    continue;
                }

                $tipo = $this->stringValue($usuario['tipoDocumentoIdentificacion'] ?? null);
                $documento = $this->stringValue($usuario['numDocumentoIdentificacion'] ?? null);
                $key = $this->patientKey($tipo, $documento, $factura, (string) $position);
                $jsonPatients[$key] ??= [
                    'archivo' => $file['fileName'],
                    'factura' => $factura,
                    'tipoDocumentoIdentificacion' => $tipo,
                    'numDocumentoIdentificacion' => $documento,
                    'codSexo' => $this->stringValue($usuario['codSexo'] ?? null),
                ];
            }
        }

        $represented = [];
        $byRoute = array_fill_keys(array_map(
            static fn (RutaCalculo $ruta): string => $ruta->value,
            RutaCalculo::cases()
        ), []);

        foreach ($profiles as $profile) {
            $parts = explode('|', $profile['profileKey']);
            $key = $parts[0] === 'SIN_IDENTIFICACION'
                ? $this->patientKey('', '', $parts[1], $parts[2])
                : $this->patientKey($parts[1], $parts[2], '', '');
            $represented[$key] = true;
            $byRoute[$profile['ruta']][$key] = true;
        }

        return [
            'pacientesJson' => count($jsonPatients),
            'representados' => count(array_intersect_key($jsonPatients, $represented)),
            'porRuta' => array_map('count', $byRoute),
            'noRepresentados' => array_values(array_diff_key($jsonPatients, $represented)),
        ];
    }

    private function patientKey(string $tipo, string $documento, string $factura, string $position): string
    {
        if ($tipo === '' || $documento === '') {
            return 'SIN_DOCUMENTO|'.$factura.'|'.$position;
        }

        return strtoupper($tipo).'|'.$documento;
    }

    /**
     * Índice de coincidencias clínicas entre servicios: llave clínica →
     * cantidad de servicios por factura. Las copias exactas no se cuentan.
     *
     * @param  array<int, array{path: string, fileName: string}>  $files
     * @return array<string, array<string, int>>
     */
    public function duplicateIndexForFiles(array $files): array
    {
        $index = [];

        foreach ($this->evaluableRecordsForFiles($files) as $record) {
            if ($record['esDuplicadoExacto']) {
                continue;
            }

            $key = $this->clinicalKey($record);

            if ($key === null) {
                continue;
            }

            $index[$key][$record['factura']] = ($index[$key][$record['factura']] ?? 0) + 1;
        }

        return $index;
    }

    /**
     * Calcula costos por perfil separando lo que suma al resumen de lo potencial.
     *
     * @param  array<int, array{path: string, fileName: string}>  $files
     * @param  array<int, array<string, mixed>>  $profiles
     * @param  array<string, array<string, int>>  $duplicateIndex
     * @return array{profiles: array<int, array<string, mixed>>, archivos: array<string, array{costoPotencial: float}>, camposDiligenciados: array<string, true>}
     */
    public function evaluateCostsForFiles(
        array $files,
        array $profiles,
        ReferenciaRutaMatcher $referenciaRutaMatcher,
        array $duplicateIndex = []
    ): array {
        $this->paquetesSumadosPorSegmento = [];
        $profilesByKey = [];
        $archivos = [];
        $filledFields = [];
        $this->hospitalEpisodesForFiles($files, $profiles);

        foreach ($profiles as $index => $profile) {
            $profilesByKey[$profile['profileKey']] = $index;
        }

        foreach ($files as $file) {
            $archivos[$file['fileName']] = ['costoPotencial' => 0.0];
        }

        foreach ($this->evaluableRecordsForFiles($files) as $record) {
            $profileIndex = $profilesByKey[$record['profileKey']] ?? null;

            if ($profileIndex === null) {
                continue;
            }

            foreach ([...$record['usuarioJson'], ...$record['servicioJson']] as $field => $value) {
                if ($value !== null && $value !== '') {
                    $filledFields[$field] = true;
                }
            }

            $profile = &$profiles[$profileIndex];
            $evaluation = $this->evaluateRecord(
                $record,
                $profile,
                $referenciaRutaMatcher,
                $duplicateIndex
            );
            $cost = (float) $evaluation['costoEstimadoReferencia'];

            $profile['valorRipsReportado'] += $evaluation['valorRipsReportado'];
            $profile['costoEstimadoReferencia'] += $cost;
            $profile['costoPotencialProstata'] += (float) $evaluation['costoPotencialProstata'];
            $profile['costoPotencialCervix'] += (float) $evaluation['costoPotencialCervix'];

            $tienePaquete = $evaluation['episodioCubierto'];

            if ($evaluation['tarifaReferencia'] > 0 || $tienePaquete) {
                $profile['serviciosConTarifa']++;
            } else {
                $profile['serviciosSinTarifa']++;
            }

            if ($evaluation['estadoTarifario'] === EstadoTarifario::SinTarifa->value && ! $tienePaquete) {
                $profile['serviciosEstadoSinTarifa']++;
            }

            $this->accumulateHospitalControl($profile, $evaluation);

            if ($evaluation['estadoTarifario'] === EstadoTarifario::RevisarPresentacion->value) {
                $profile['serviciosRevisarPresentacion']++;
            }

            match ($evaluation['claseCosto']) {
                ClaseCosto::Valido->value => $profile['costoValido'] += $cost,
                ClaseCosto::ValidoConAlerta->value => $profile['costoValidoConAlerta'] += $cost,
                ClaseCosto::PotencialNoSuma->value => $profile['costoPotencialNoSuma'] += $cost,
                default => null,
            };

            if ($evaluation['sumaAlResumen'] === 'SI') {
                $profile['serviciosQueSuman']++;
            }

            if ($evaluation['requiereRevision']) {
                $profile['serviciosEnRevision']++;
            }

            if ($profile['ruta'] !== RutaCalculo::NoAsignable->value) {
                $archivos[$record['archivoOrigen']]['costoPotencial'] = ($archivos[$record['archivoOrigen']]['costoPotencial'] ?? 0.0) + $cost;
            }

            unset($profile);
        }

        $episodios = $this->imputeHospitalPackages($profiles, $profilesByKey, $archivos, $referenciaRutaMatcher);

        return [
            'profiles' => $profiles,
            'archivos' => $archivos,
            'camposDiligenciados' => $filledFields,
            'episodios' => $episodios,
        ];
    }

    /**
     * Segmentos de hospitalización del lote para el libro de paquetes, con la
     * ruta del paciente-mes en que inician y la identidad de procedencia
     * (prestador, factura, paciente y consecutivo). Se omiten los segmentos de
     * facturas repetidas con diferencias (no son una corrección autorizada) y los
     * de pacientes sin ruta de La María.
     *
     * @param  array<int, array<string, mixed>>  $files
     * @param  array<int, array<string, mixed>>  $profiles
     * @return list<array{ruta: string, prestador: string, factura: string, tipoDocumento: string, documento: string, consecutivo: string, inicio: string, fin: string, destino: string, archivo: string}>
     */
    public function hospitalSegmentsForLedger(array $files, array $profiles): array
    {
        $profilesByKey = array_column($profiles, null, 'profileKey');
        $prestadores = [];

        foreach ($files as $file) {
            $prestadores[$file['fileName']] = $this->scalarFromJsonFile($file['path'], '/numDocumentoIdObligado');
        }

        $segmentos = [];

        foreach ($this->hospitalEpisodesForFiles($files, $profiles)['segmentos'] as $clave => $segmento) {
            $ruta = (string) ($profilesByKey[$this->segmentProfileKey($segmento, $this->reportingMonth((string) $segmento['inicio']))]['ruta'] ?? '');

            if (($segmento['externo'] ?? false) || $segmento['excluido'] || ! in_array($ruta, [RutaCalculo::Prostata->value, RutaCalculo::Cervix->value], true)) {
                continue;
            }

            // Una versión del RIPS que difiere de la registrada se envía tal cual al
            // libro, que la deja PENDIENTE_REVISION salvo corrección autorizada.
            $segmento = [...$segmento, ...($segmento['contenidoRips'] ?? [])];
            [, $posicion, $indice] = array_pad(explode('|', (string) $clave), 3, '');
            $segmentos[] = [
                'ruta' => $ruta,
                'prestador' => (string) ($prestadores[$segmento['archivo']] ?? ''),
                'factura' => (string) $segmento['factura'],
                'tipoDocumento' => (string) $segmento['tipoDocumento'],
                'documento' => (string) $segmento['documento'],
                'consecutivo' => $segmento['consecutivo'] !== '' ? (string) $segmento['consecutivo'] : 'U'.$posicion.'-H'.$indice,
                'inicio' => (string) $segmento['inicio'],
                'fin' => (string) $segmento['fin'],
                'destino' => (string) $segmento['destino'],
                'autorizacion' => (string) ($segmento['autorizacion'] ?? ''),
                'prestadorServicio' => (string) ($segmento['prestadorServicio'] ?? ''),
                'archivo' => (string) $segmento['archivo'],
            ];
        }

        return $segmentos;
    }

    /**
     * Reconstruye las estancias hospitalarias lógicas del lote: une los cortes
     * de facturación 08 entre facturas del mismo paciente antes de calcular la
     * duración y los paquetes.
     *
     * @param  array<int, array<string, mixed>>  $files
     * @return array{key: ?string, episodios: list<array<string, mixed>>, porSegmento: array<string, int>, segmentos: array<string, array<string, mixed>>}
     */
    public function hospitalEpisodesForFiles(array $files, array $profiles = []): array
    {
        $key = md5((string) json_encode(array_map(
            static fn (array $file): string => $file['path'].'|'.($file['condicion'] ?? ''),
            $files
        )).'|'.count($profiles));

        if ($this->hospitalEpisodes['key'] === $key) {
            return $this->hospitalEpisodes;
        }

        $segmentos = [];

        foreach ($files as $file) {
            $factura = $file['factura'] ?? $this->scalarFromJsonFile($file['path'], '/numFactura');

            foreach ($this->usersForFile($file['path']) as $position => $usuario) {
                $hospitalizaciones = is_array($usuario) ? ($usuario['servicios']['hospitalizacion'] ?? null) : null;

                if (! is_array($hospitalizaciones)) {
                    continue;
                }

                $tipo = $this->stringValue($usuario['tipoDocumentoIdentificacion'] ?? null);
                $documento = $this->stringValue($usuario['numDocumentoIdentificacion'] ?? null);
                $consultasUrgencia = [];

                foreach ($usuario['servicios']['consultas'] ?? [] as $consulta) {
                    $codigo = is_array($consulta) ? $this->serviceCode('consultas', $consulta) : '';

                    if (in_array($codigo, ReconstructorEpisodiosHospitalarios::CONSULTAS_URGENCIA, true)) {
                        $consultasUrgencia[] = [
                            'codigo' => $codigo,
                            'consecutivo' => $this->stringValue($consulta['consecutivo'] ?? null),
                            'fecha' => $this->serviceDate('consultas', $consulta),
                        ];
                    }
                }

                foreach ($hospitalizaciones as $indice => $hospitalizacion) {
                    if (! is_array($hospitalizacion)) {
                        continue;
                    }

                    $segmento = $this->hospitalizationSegment($hospitalizacion, $file['fileName'].'|'.$position.'|'.$indice);
                    $segmentos[] = [
                        ...$segmento,
                        'paciente' => $tipo === '' || $documento === ''
                            ? 'SIN_DOCUMENTO|'.$file['fileName'].'|'.$position
                            : strtoupper($tipo).'|'.$documento,
                        'tipoDocumento' => $tipo,
                        'documento' => $documento,
                        'factura' => $factura,
                        'posicion' => (string) $position,
                        'archivo' => $file['fileName'],
                        'excluido' => ($file['condicion'] ?? '') === CondicionArchivo::FacturaRepetidaConDiferencias->value,
                        'consultasUrgencia' => array_values(array_filter(
                            $consultasUrgencia,
                            static fn (array $consulta): bool => $segmento['inicio'] !== '' && $segmento['fin'] !== ''
                                && $consulta['fecha'] >= $segmento['inicio'] && $consulta['fecha'] <= $segmento['fin']
                        )),
                    ];
                }
            }
        }

        if ($profiles !== []) {
            $profilesByKey = array_column($profiles, null, 'profileKey');

            foreach ($segmentos as &$segmento) {
                $segmento['ruta'] = (string) ($profilesByKey[$this->segmentProfileKey($segmento, $this->reportingMonth((string) $segmento['inicio']))]['ruta'] ?? '');
            }

            unset($segmento);
        }

        if ($this->libroPersistente !== null) {
            $segmentos = $this->withStoredSegments($segmentos, $files);
        }

        $reconstructor = new ReconstructorEpisodiosHospitalarios($this->politicaPaquete);

        // POR_LOTE valora cada mes de atención de forma independiente. Los
        // segmentos y cortes 08 originales conservan su identidad para la
        // reconstrucción clínica transversal y el libro PERSISTENTE.
        if ($this->libroPersistente === null) {
            $segmentosPorMes = [];

            foreach ($segmentos as $segmento) {
                $segmentosPorMes[$this->reportingMonth((string) $segmento['inicio'])][] = $segmento;
            }

            $episodios = [];

            foreach ($segmentosPorMes as $segmentosDelMes) {
                array_push($episodios, ...$reconstructor->reconstruir($segmentosDelMes));
            }
        } else {
            $episodios = $reconstructor->reconstruir($segmentos);
        }

        $porSegmento = [];

        foreach ($episodios as $indice => $episodio) {
            $episodios[$indice]['paquetesSumados'] = max(0, ...array_map(
                fn (string $clave): int => $this->paquetesSumadosPorSegmento[$clave] ?? 0,
                $episodio['segmentos']
            ));
            foreach ($episodio['segmentos'] as $clave) {
                $porSegmento[$clave] = $indice;
            }
        }

        return $this->hospitalEpisodes = [
            'key' => $key,
            'episodios' => $episodios,
            'porSegmento' => $porSegmento,
            'segmentos' => array_column($segmentos, null, 'clave'),
        ];
    }

    /**
     * Imputa cada paquete al paciente-mes en que comienza su bloque de 600
     * horas. Si el paciente no tiene registros RIPS en ese mes, se crea el
     * paciente-mes solo con el cargo del paquete: el paquete es un resultado
     * del procesamiento y nunca se agrega como línea del RIPS.
     *
     * @param  array<int, array<string, mixed>>  $profiles
     * @param  array<string, int>  $profilesByKey
     * @param  array<string, array{costoPotencial: float}>  $archivos
     * @return list<array<string, mixed>>
     */
    private function imputeHospitalPackages(
        array &$profiles,
        array &$profilesByKey,
        array &$archivos,
        ReferenciaRutaMatcher $referenciaRutaMatcher
    ): array {
        $resumen = [];

        foreach ($this->hospitalEpisodes['episodios'] as $indiceEpisodio => $episodio) {
            // El ancla es el primer segmento del episodio que pertenece a este lote.
            $primero = null;

            foreach ($episodio['segmentosOrdenados'] as $ordenado) {
                if (! ($this->hospitalEpisodes['segmentos'][$ordenado['clave']]['externo'] ?? false)) {
                    $primero = $this->hospitalEpisodes['segmentos'][$ordenado['clave']];

                    break;
                }
            }

            if ($primero === null) {
                continue;
            }

            $anchorIndex = $profilesByKey[$this->segmentProfileKey($primero, $this->reportingMonth($primero['inicio']))] ?? null;
            $ruta = $anchorIndex === null ? '' : (string) $profiles[$anchorIndex]['ruta'];
            $paquetesSumados = 0;

            foreach ($episodio['bloques'] as $bloque) {
                $segmento = $this->hospitalEpisodes['segmentos'][$bloque['segmento']];

                // Bloque de un segmento de otra ejecución: ya se informó en su período.
                if ($segmento['externo'] ?? false) {
                    continue;
                }

                $key = $this->segmentProfileKey($segmento, $bloque['mes']);

                if (! isset($profilesByKey[$key]) && $anchorIndex !== null) {
                    $profiles[] = $this->packageOnlyProfile($profiles[$anchorIndex], $key, $bloque['mes'], (string) $segmento['factura']);
                    $profilesByKey[$key] = array_key_last($profiles);
                }

                $index = $profilesByKey[$key] ?? null;

                if ($index === null) {
                    continue;
                }

                $profile = &$profiles[$index];
                $inicioLibro = $this->libroPersistente['inicioPorRuta'][(string) $profile['ruta']] ?? null;
                $motivoNoSuma = match (true) {
                    $episodio['excluido'] => CondicionArchivo::FacturaRepetidaConDiferencias->value,
                    $profile['ruta'] === RutaCalculo::NoAsignable->value => RutaCalculo::NoAsignable->value,
                    $inicioLibro !== null && $bloque['mes'] < $inicioLibro => self::PERIODO_HISTORICO_NO_VERIFICADO,
                    default => '',
                };

                if ($motivoNoSuma === self::PERIODO_HISTORICO_NO_VERIFICADO) {
                    // Simulación del modelo nuevo: se informa, no se cobra.
                    $profile['controlHospitalario']['paquetesSimulados']++;
                    $profile['controlHospitalario']['costoPaquetesSimulados'] += PoliticaPaqueteHospitalario::VALOR_PAQUETE;
                } elseif ($motivoNoSuma === '') {
                    if ($profile['estadosClinicos'] === [EstadoClinico::Correcto->value]) {
                        $profile['costoValido'] += PoliticaPaqueteHospitalario::VALOR_PAQUETE;
                    } else {
                        $profile['costoValidoConAlerta'] += PoliticaPaqueteHospitalario::VALOR_PAQUETE;
                    }

                    $profile['controlHospitalario']['paquetes']++;
                    $profile['controlHospitalario']['costoPaquetes'] += PoliticaPaqueteHospitalario::VALOR_PAQUETE;
                    $archivos[$segmento['archivo']]['costoPotencial'] = ($archivos[$segmento['archivo']]['costoPotencial'] ?? 0.0)
                        + PoliticaPaqueteHospitalario::VALOR_PAQUETE;
                    $paquetesSumados++;
                } elseif ($motivoNoSuma === RutaCalculo::NoAsignable->value) {
                    $profile['costoPotencialProstata'] += PoliticaPaqueteHospitalario::VALOR_PAQUETE;
                    $profile['costoPotencialCervix'] += PoliticaPaqueteHospitalario::VALOR_PAQUETE;
                }

                $profile['episodiosHospitalarios'][] = [
                    'paciente' => $episodio['paciente'],
                    'factura' => $segmento['factura'],
                    'ingreso' => $episodio['inicio'],
                    'egreso' => $episodio['fin'],
                    'continuidadPendiente' => $episodio['estado'] === ReconstructorEpisodiosHospitalarios::ESTADO_CONTINUIDAD_PENDIENTE,
                    'bloque' => $bloque['numero'],
                    'bloques' => $episodio['paquetes'],
                    'inicioBloque' => $bloque['inicio'],
                    'mesImputado' => $bloque['mes'],
                    'costo' => PoliticaPaqueteHospitalario::VALOR_PAQUETE,
                    'suma' => $motivoNoSuma === '',
                    'motivoNoSuma' => $motivoNoSuma,
                ];
                unset($profile);
            }

            $this->hospitalEpisodes['episodios'][$indiceEpisodio]['paquetesSumados'] = $paquetesSumados;

            foreach ($episodio['segmentos'] as $claveSegmento) {
                $this->paquetesSumadosPorSegmento[$claveSegmento] = $paquetesSumados;
            }

            $resumen[] = [
                'ruta' => $ruta,
                'estado' => $episodio['estado'],
                'tipo' => $episodio['tipo'],
                'registros' => count($episodio['segmentos']),
                'cortesFacturacion' => $episodio['cortesFacturacion'],
                'continuidadesResueltas' => $episodio['continuidadesResueltas'],
                'paquetes' => $episodio['paquetes'],
                'paquetesSumados' => $paquetesSumados,
                'retenidoPorContinuidadNoConfirmada' => (bool) ($episodio['retenidoPorContinuidadNoConfirmada'] ?? false),
                'urgenciaConTarifa' => $ruta !== '' && $this->urgencyTariff($episodio, $ruta, $referenciaRutaMatcher) !== null,
            ];
        }

        return $resumen;
    }

    /**
     * @param  array<string, mixed>  $segmento
     */
    private function segmentProfileKey(array $segmento, string $mes): string
    {
        return $this->profileKey(
            (string) $segmento['tipoDocumento'],
            (string) $segmento['documento'],
            (string) $segmento['factura'],
            (string) $segmento['posicion'],
            $mes
        );
    }

    /**
     * Paciente-mes sin registros RIPS que solo recibe el cargo de un bloque
     * hospitalario; conserva la identificación y la ruta del ingreso.
     *
     * @param  array<string, mixed>  $anchor
     * @return array<string, mixed>
     */
    private function packageOnlyProfile(array $anchor, string $profileKey, string $mes, string $factura): array
    {
        return [
            ...$anchor,
            'profileKey' => $profileKey,
            'mesAtencion' => $mes,
            'casoId' => $this->caseId($profileKey),
            'facturas' => [$factura],
            'serviceCount' => 0,
            'serviceCounts' => array_fill_keys(self::SERVICE_TYPES, 0),
            'serviciosConTarifa' => 0,
            'serviciosSinTarifa' => 0,
            'serviciosQueSuman' => 0,
            'serviciosEstadoSinTarifa' => 0,
            'serviciosRevisarPresentacion' => 0,
            'serviciosEnRevision' => 0,
            'valorRipsReportado' => 0.0,
            'costoEstimadoReferencia' => 0.0,
            'costoValido' => 0.0,
            'costoValidoConAlerta' => 0.0,
            'costoPotencialNoSuma' => 0.0,
            'costoPotencialProstata' => 0.0,
            'costoPotencialCervix' => 0.0,
            'controlHospitalario' => self::emptyHospitalControl(),
            'episodiosHospitalarios' => [],
            'primeraFechaAtencion' => '',
            'ultimaFechaAtencion' => '',
            'soloPaqueteHospitalario' => true,
        ];
    }

    /**
     * Tarifa de urgencia de una estancia de menos de 24 horas: la de su única
     * consulta de urgencias (890701 o 890702) en la nota técnica de la ruta.
     *
     * @param  array<string, mixed>  $episodio
     */
    private function urgencyTariff(array $episodio, string $ruta, ?ReferenciaRutaMatcher $referenciaRutaMatcher): ?float
    {
        if ($episodio['tipo'] !== ReconstructorEpisodiosHospitalarios::TIPO_URGENCIA
            || $episodio['consultaUrgencia'] === null
            || $referenciaRutaMatcher === null) {
            return null;
        }

        return $referenciaRutaMatcher->technicalTariff($ruta, (string) $episodio['consultaUrgencia']['codigo']);
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>|null
     */
    private function episodeForRecord(array $record): ?array
    {
        $index = $this->hospitalEpisodes['porSegmento'][(string) ($record['segmentoHospitalario'] ?? '')] ?? null;

        return $index === null ? null : $this->hospitalEpisodes['episodios'][$index];
    }

    /**
     * Acumula por paciente-mes el tratamiento hospitalario de los servicios.
     *
     * @param  array<string, mixed>  $profile
     * @param  array<string, mixed>  $evaluation
     */
    private function accumulateHospitalControl(array &$profile, array $evaluation): void
    {
        $control = &$profile['controlHospitalario'];
        $individualCost = (float) $evaluation['costoEstimadoReferencia'];
        $summedCost = $evaluation['sumaAlResumen'] === 'SI' ? $individualCost : 0.0;

        switch ($evaluation['decisionPaquete']) {
            case DecisionPaqueteHospitalario::IncluidoEnPaquete->value:
                $control['serviciosAbsorbidos']++;
                $control['costoIndividualExcluido'] += $individualCost;
                break;
            case DecisionPaqueteHospitalario::CobrablePorFuera->value:
                $control['serviciosCobradosPorFuera']++;
                $control['costoCobradoPorFuera'] += $summedCost;
                break;
            case DecisionPaqueteHospitalario::ReglaNoDefinida->value:
                $control['serviciosReglaPendiente']++;
                $control['costoReglaPendiente'] += $summedCost;
                break;
            case DecisionPaqueteHospitalario::CobradoComoUrgencia->value:
                $control['urgenciasCobradas']++;
                $control['costoUrgencias'] += $summedCost;
                break;
            case DecisionPaqueteHospitalario::IncluidoEnUrgencia->value:
                $control['serviciosIncluidosUrgencia']++;
                $control['costoIncluidoUrgencia'] += $individualCost;
                break;
            case DecisionPaqueteHospitalario::UrgenciaTarifaPendiente->value:
                $control['serviciosUrgenciaPendiente']++;
                $control['costoReferenciaUrgenciaPendiente'] += $individualCost;
                break;
        }

        $fuentes = [
            PoliticaPaqueteHospitalario::FUENTE_SERVICIO => 'clasificadosPorServicio',
            PoliticaPaqueteHospitalario::FUENTE_AGRUPADOR => 'clasificadosPorAgrupador',
            PoliticaPaqueteHospitalario::FUENTE_FALLBACK => 'clasificadosPorFallback',
            PoliticaPaqueteHospitalario::FUENTE_CODIGO => 'clasificadosPorCodigo',
            PoliticaPaqueteHospitalario::FUENTE_TIPO => 'clasificadosPorTipo',
            PoliticaPaqueteHospitalario::FUENTE_SIN_CATEGORIA => 'clasificadosSinCategoria',
        ];

        if (isset($fuentes[$evaluation['fuenteClasificacion']])) {
            $control[$fuentes[$evaluation['fuenteClasificacion']]]++;
        }
    }

    /**
     * @param  array<int, array{path: string, fileName: string}>  $files
     * @param  array<int, array<string, mixed>>  $profiles
     * @param  array<string, array<string, int>>  $duplicateIndex
     * @return \Generator<int, array<string, int|float|string|bool>, mixed, void>
     */
    public function detailRowsForFiles(
        array $files,
        array $profiles,
        ?ReferenciaRutaMatcher $referenciaRutaMatcher = null,
        ?string $rutaEjecucion = null,
        array $duplicateIndex = []
    ): \Generator {
        $profilesByKey = [];
        $this->hospitalEpisodesForFiles($files);

        foreach ($profiles as $profile) {
            $profilesByKey[$profile['profileKey']] = $profile;
        }

        foreach ($this->evaluableRecordsForFiles($files) as $record) {
            $profile = $profilesByKey[$record['profileKey']] ?? null;

            if ($profile === null) {
                continue;
            }

            $evaluation = $this->evaluateRecord(
                $record,
                $profile,
                $referenciaRutaMatcher,
                $duplicateIndex,
                $rutaEjecucion
            );

            yield [
                'mesAtencion' => $record['mesAtencion'],
                'factura' => $record['factura'],
                'codPrestador' => $record['codPrestador'] ?? '',
                'tipoDocumentoIdentificacion' => $record['tipoDocumentoIdentificacion'],
                'numDocumentoIdentificacion' => $record['numDocumentoIdentificacion'],
                'sexo' => $profile['sexo'] ?? $record['sexo'] ?? '',
                'ruta' => $profile['ruta'],
                'origenRuta' => $profile['origenRuta'],
                'estadoClinico' => $profile['estadoClinico'],
                'evidenciaSecundaria' => $profile['evidenciaSecundaria'],
                'casoId' => $profile['casoId'],
                'motivoClasificacion' => $profile['motivoClasificacion'],
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
                'computaEjecucion' => $evaluation['sumaAlResumen'],
                'motivoExclusion' => $evaluation['motivoNoSuma'],
                'archivoOrigen' => $record['archivoOrigen'],
                'clinicalKey' => $this->clinicalKey($record) ?? '',
                'usuarioJson' => $record['usuarioJson'],
                'servicioJson' => $record['servicioJson'],
                ...$evaluation,
            ];
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $files
     * @return \Generator<int, array<string, int|string>, mixed, void>
     */
    public function controlRowsForFiles(array $files): \Generator
    {
        foreach ($files as $file) {
            $factura = $file['factura'] ?? $this->scalarFromJsonFile($file['path'], '/numFactura');
            $condicion = $file['condicion'] ?? CondicionArchivo::Unico->value;
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
                    (string) $position,
                    $condicion
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

            if ($condicion === CondicionArchivo::ArchivoDuplicadoExcluido->value) {
                $observations[] = 'Contenido idéntico a otro archivo cargado; no se procesa para evitar doble conteo.';
            }

            if ($condicion === CondicionArchivo::FacturaRepetidaConDiferencias->value) {
                $observations[] = 'La misma factura llegó con contenido diferente; sus servicios no suman hasta definir el archivo válido.';
            }

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
                'hash' => $file['hash'] ?? (string) hash_file('sha256', $file['path']),
                'tipoNota' => $file['tipoNota'] ?? '',
                'numNota' => $file['numNota'] ?? '',
                'userCount' => $userCount,
                'serviceCount' => $serviceCount,
                'servicesWithoutDate' => $servicesWithoutDate,
                'firstMonth' => $months[0] ?? '',
                'lastMonth' => $months === [] ? '' : $months[array_key_last($months)],
                'condicion' => $condicion,
                'relacionados' => $file['relacionados'] ?? '',
                'estadoCargue' => match (true) {
                    $condicion === CondicionArchivo::ArchivoDuplicadoExcluido->value => 'EXCLUIDO',
                    $observations === [] => 'PROCESADO',
                    default => 'REVISAR',
                },
                'observacion' => implode(' ', $observations),
            ];
        }
    }

    /**
     * Decide si un servicio suma al resumen. Solo el costo con tarifa confiable,
     * ruta asignada, mes de atención y sin copia exacta ni factura en conflicto
     * suma; las alertas clínicas no excluyen el costo.
     *
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $profile
     * @param  array<string, array<string, int>>  $duplicateIndex
     * @return array<string, mixed>
     */
    private function evaluateRecord(
        array $record,
        array $profile,
        ?ReferenciaRutaMatcher $referenciaRutaMatcher,
        array $duplicateIndex,
        ?string $rutaEjecucion = null
    ): array {
        $isNotAssignable = $profile['ruta'] === RutaCalculo::NoAsignable->value;
        $potentialProstata = '';
        $potentialCervix = '';

        if ($referenciaRutaMatcher === null) {
            $reference = $this->defaultReferenceMatch($record);
        } elseif ($isNotAssignable) {
            $prostata = $referenciaRutaMatcher->match($record, RutaCalculo::Prostata->value);
            $cervix = $referenciaRutaMatcher->match($record, RutaCalculo::Cervix->value);
            $reference = $rutaEjecucion === RutaCalculo::Cervix->value ? $cervix : $prostata;
            $potentialProstata = (float) $prostata['costoEstimadoReferencia'];
            $potentialCervix = (float) $cervix['costoEstimadoReferencia'];
        } else {
            $reference = $referenciaRutaMatcher->match($record, $profile['ruta']);
        }

        $duplicidad = $this->duplicityState($record, $duplicateIndex);
        $episodio = $this->episodeForRecord($record);
        $isHospitalization = $record['tipoServicio'] === 'hospitalizacion';
        $tarifaUrgencia = $episodio === null || $isNotAssignable
            ? null
            : $this->urgencyTariff($episodio, (string) $profile['ruta'], $referenciaRutaMatcher);
        $tratamiento = ['decision' => null, 'categoria' => '', 'fuente' => ''];

        if ($episodio !== null && ! $isHospitalization && ! $isNotAssignable
            && $episodio['estado'] !== ReconstructorEpisodiosHospitalarios::ESTADO_SIN_FECHAS) {
            $tratamiento = $episodio['tipo'] === ReconstructorEpisodiosHospitalarios::TIPO_URGENCIA
                ? $this->urgencyTreatment($record, $episodio, $tarifaUrgencia)
                : $this->politicaPaquete->clasificar(
                    (string) $record['tipoServicio'],
                    (string) $record['codigo'],
                    (string) ($reference['descripcionOficial'] ?: $reference['nombreNotaTecnica']),
                    (string) $reference['atc'],
                    (string) $reference['servicioNotaTecnica'],
                    (string) $reference['agrupadorNotaTecnica']
                );
        }

        $decisionPaquete = $tratamiento['decision'];
        $reglaHospitalariaPendiente = $decisionPaquete === DecisionPaqueteHospitalario::ReglaNoDefinida
            && $reference['estadoTarifario'] === EstadoTarifario::ConTarifa->value;
        $estadoEpisodio = $isHospitalization && $episodio !== null
            ? $this->hospitalRecordState($record, $episodio, $tarifaUrgencia)
            : '';
        $quantityReason = match (true) {
            $record['tipoServicio'] === 'medicamentos' && ($record['cantidad'] === null || $record['cantidad'] === '') => 'CANTIDAD_MEDICAMENTO_NO_INFORMADA',
            ($reference['estadoCantidad'] ?? '') === 'INVALIDA' => 'CANTIDAD_INVALIDA',
            default => '',
        };
        $motivoNoSuma = match (true) {
            $record['condicionArchivo'] === CondicionArchivo::FacturaRepetidaConDiferencias->value => CondicionArchivo::FacturaRepetidaConDiferencias->value,
            $duplicidad === EstadoDuplicidad::DuplicadoExacto->value => EstadoDuplicidad::DuplicadoExacto->value,
            $isNotAssignable && in_array(EstadoClinico::DobleRuta->value, $profile['estadosClinicos'], true) => EstadoClinico::DobleRuta->value,
            $isNotAssignable => RutaCalculo::NoAsignable->value,
            in_array(EstadoClinico::SinFecha->value, $profile['estadosClinicos'], true) => EstadoClinico::SinFecha->value,
            in_array($decisionPaquete, [
                DecisionPaqueteHospitalario::IncluidoEnPaquete,
                DecisionPaqueteHospitalario::IncluidoEnUrgencia,
                DecisionPaqueteHospitalario::UrgenciaTarifaPendiente,
            ], true) => $decisionPaquete->value,
            $quantityReason !== '' => $quantityReason,
            $reference['estadoTarifario'] !== EstadoTarifario::ConTarifa->value => (string) $reference['estadoTarifario'],
            default => '',
        };
        $hasAlert = $profile['estadosClinicos'] !== [EstadoClinico::Correcto->value]
            || in_array($duplicidad, [
                EstadoDuplicidad::PosibleDobleFacturacion->value,
                EstadoDuplicidad::RepeticionMismaFactura->value,
            ], true)
            || $reglaHospitalariaPendiente;
        $hasPotentialCost = (float) $reference['costoEstimadoReferencia'] > 0
            || (float) ($potentialProstata ?: 0) > 0
            || (float) ($potentialCervix ?: 0) > 0;

        return [
            ...$reference,
            'estadoDuplicidad' => $duplicidad,
            'condicionArchivo' => $record['condicionArchivo'],
            'sumaAlResumen' => $motivoNoSuma === '' ? 'SI' : 'NO',
            'motivoNoSuma' => $motivoNoSuma,
            // Solo cambia la fuente de tarifa: exclusiones, absorciones y cantidades son las mismas.
            'sumaEscenarioReferencial' => $motivoNoSuma === ''
                || ($motivoNoSuma === (string) $reference['estadoTarifario'] && (float) ($reference['costoEscenarioReferencial'] ?? 0) > 0)
                    ? 'SI'
                    : 'NO',
            'claseCosto' => match (true) {
                $motivoNoSuma === '' && $hasAlert => ClaseCosto::ValidoConAlerta->value,
                $motivoNoSuma === '' => ClaseCosto::Valido->value,
                $hasPotentialCost => ClaseCosto::PotencialNoSuma->value,
                default => ClaseCosto::SinCosto->value,
            },
            'costoPotencialProstata' => $potentialProstata,
            'costoPotencialCervix' => $potentialCervix,
            'decisionPaquete' => $decisionPaquete?->value ?? '',
            'categoriaPaquete' => $tratamiento['categoria'],
            'fuenteClasificacion' => $tratamiento['fuente'],
            'reglaHospitalariaPendiente' => $reglaHospitalariaPendiente,
            'estadoEpisodioHospitalario' => $estadoEpisodio,
            'episodioInicio' => (string) ($episodio['inicio'] ?? ''),
            'episodioEgreso' => (string) ($episodio['fin'] ?? ''),
            'episodioPaquetesSumados' => (int) ($episodio['paquetesSumados'] ?? 0),
            'consultasUrgenciaEpisodio' => (int) ($episodio['consultasUrgencia'] ?? 0),
            'motivoContinuidadEpisodio' => (string) ($episodio['motivoContinuidad'] ?? ''),
            'episodioCubierto' => $isHospitalization && $episodio !== null
                && ($episodio['paquetes'] > 0 || $tarifaUrgencia !== null),
            'requiereRevision' => $isNotAssignable
                || $quantityReason !== ''
                || $reglaHospitalariaPendiente
                || in_array($estadoEpisodio, [
                    'HOSPITALIZACION_SIN_FECHAS_VALIDAS',
                    'URGENCIA_MENOR_24H_TARIFA_PENDIENTE',
                    ReconstructorEpisodiosHospitalarios::ESTADO_CONTINUIDAD_PENDIENTE,
                    ReconstructorEpisodiosHospitalarios::ESTADO_CONTINUIDAD_NO_CONFIRMADA,
                ], true)
                || $profile['estadosClinicos'] !== [EstadoClinico::Correcto->value]
                || in_array($duplicidad, [
                    EstadoDuplicidad::DuplicadoExacto->value,
                    EstadoDuplicidad::PosibleDobleFacturacion->value,
                ], true)
                || $record['condicionArchivo'] !== CondicionArchivo::Unico->value
                || $reference['estadoTarifario'] === EstadoTarifario::RevisarPresentacion->value,
        ];
    }

    /**
     * Estancia de menos de 24 horas: se trata como urgencia al valor pactado.
     * Solo hay tarifa si el episodio tiene una única consulta de urgencias con
     * tarifa en la ruta; esa consulta es el cobro y lo demás queda incluido.
     * Sin esa tarifa nada del episodio suma como costo contractual.
     *
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $episodio
     * @return array{decision: DecisionPaqueteHospitalario, categoria: string, fuente: string}
     */
    private function urgencyTreatment(array $record, array $episodio, ?float $tarifaUrgencia): array
    {
        $consulta = $episodio['consultaUrgencia'];
        $isUrgencyConsult = $consulta !== null
            && $record['tipoServicio'] === 'consultas'
            && $record['codigo'] === $consulta['codigo']
            && (string) $record['consecutivoRips'] === $consulta['consecutivo']
            && $record['fechaAtencion'] === $consulta['fecha']
            && $record['segmentoHospitalario'] === $consulta['segmento'];

        return match (true) {
            $tarifaUrgencia !== null && $isUrgencyConsult => [
                'decision' => DecisionPaqueteHospitalario::CobradoComoUrgencia,
                'categoria' => 'CONSULTA DE URGENCIAS '.$consulta['codigo'],
                'fuente' => 'URGENCIA',
            ],
            $tarifaUrgencia !== null => [
                'decision' => DecisionPaqueteHospitalario::IncluidoEnUrgencia,
                'categoria' => 'INCLUIDO EN LA URGENCIA',
                'fuente' => 'URGENCIA',
            ],
            default => [
                'decision' => DecisionPaqueteHospitalario::UrgenciaTarifaPendiente,
                'categoria' => 'URGENCIA SIN TARIFA DEFINIDA',
                'fuente' => 'URGENCIA',
            ],
        };
    }

    /**
     * Estado del registro de hospitalización. Las excepciones del episodio se
     * reportan una sola vez, en su último registro.
     *
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $episodio
     */
    private function hospitalRecordState(array $record, array $episodio, ?float $tarifaUrgencia): string
    {
        $isFinal = $record['segmentoHospitalario'] === $episodio['segmentoFinal'];

        return match (true) {
            $episodio['estado'] === ReconstructorEpisodiosHospitalarios::ESTADO_SIN_FECHAS => 'HOSPITALIZACION_SIN_FECHAS_VALIDAS',
            ! $isFinal => 'SEGMENTO_DE_ESTANCIA',
            $episodio['tipo'] === ReconstructorEpisodiosHospitalarios::TIPO_URGENCIA => $tarifaUrgencia !== null
                ? 'URGENCIA_MENOR_24H_CON_TARIFA'
                : 'URGENCIA_MENOR_24H_TARIFA_PENDIENTE',
            $episodio['estado'] === ReconstructorEpisodiosHospitalarios::ESTADO_CONTINUIDAD_PENDIENTE => ReconstructorEpisodiosHospitalarios::ESTADO_CONTINUIDAD_PENDIENTE,
            $episodio['estado'] === ReconstructorEpisodiosHospitalarios::ESTADO_CONTINUIDAD_NO_CONFIRMADA => ReconstructorEpisodiosHospitalarios::ESTADO_CONTINUIDAD_NO_CONFIRMADA,
            default => 'ESTANCIA_HOSPITALARIA',
        };
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, array<string, int>>  $duplicateIndex
     */
    private function duplicityState(array $record, array $duplicateIndex): string
    {
        if ($record['esDuplicadoExacto']) {
            return EstadoDuplicidad::DuplicadoExacto->value;
        }

        $key = $this->clinicalKey($record);
        $invoices = $key === null ? [] : ($duplicateIndex[$key] ?? []);

        return match (true) {
            count($invoices) > 1 => EstadoDuplicidad::PosibleDobleFacturacion->value,
            array_sum($invoices) > 1 => EstadoDuplicidad::RepeticionMismaFactura->value,
            default => EstadoDuplicidad::Ninguno->value,
        };
    }

    /**
     * Llave clínica de comparación entre servicios del mismo paciente. Sin
     * documento no se compara, porque no puede garantizarse que sea la misma persona.
     *
     * @param  array<string, mixed>  $record
     */
    private function clinicalKey(array $record): ?string
    {
        if ($record['hasMissingIdentity']) {
            return null;
        }

        return implode('|', [
            strtoupper((string) $record['tipoDocumentoIdentificacion']),
            $record['numDocumentoIdentificacion'],
            $record['fechaAtencion'],
            $record['tipoServicio'],
            $record['codigo'],
            (string) $record['cantidad'],
        ]);
    }

    /**
     * Recorre los registros marcando como copia exacta la segunda aparición de
     * un servicio idéntico (todos sus campos, incluido el consecutivo) del mismo
     * usuario dentro del mismo archivo.
     *
     * @param  array<int, array{path: string, fileName: string}>  $files
     * @return \Generator<int, array<string, mixed>, mixed, void>
     */
    private function evaluableRecordsForFiles(array $files): \Generator
    {
        foreach ($files as $file) {
            $seen = [];

            foreach ($this->recordsForFile($file) as $record) {
                $exactKey = implode('|', [
                    $record['posicionUsuario'],
                    $record['tipoServicio'],
                    $record['huellaServicio'],
                ]);
                $record['esDuplicadoExacto'] = isset($seen[$exactKey]);
                $seen[$exactKey] = true;

                yield $record;
            }
        }
    }

    /**
     * Resuelve la ruta de cálculo de un paciente-mes: primero por diagnóstico
     * principal C61/C53, luego por sexo. Nunca usa el sexo para desempatar una
     * doble ruta.
     *
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    private function resolveRoute(array $profile): array
    {
        $hasProstate = isset($profile['routeEvidence']['C61']);
        $hasCervix = isset($profile['routeEvidence']['C53']);
        $sexo = (string) ($profile['sexo'] ?? '');
        $estados = [];

        if ($hasProstate && $hasCervix) {
            $ruta = RutaCalculo::NoAsignable;
            $origen = OrigenRuta::Ninguno;
            $estados[] = EstadoClinico::DobleRuta;
            $motivos = ['Se detectaron diagnósticos C61 y C53 en el mismo mes; no se asigna ruta hasta resolverlo.'];
        } elseif ($hasProstate || $hasCervix) {
            $ruta = $hasProstate ? RutaCalculo::Prostata : RutaCalculo::Cervix;
            $origen = OrigenRuta::Diagnostico;
            $motivos = [$hasProstate ? 'Diagnóstico C61 detectado.' : 'Diagnóstico C53 detectado.'];
            $expectedSex = $hasProstate ? 'M' : 'F';

            if (in_array($sexo, ['M', 'F'], true) && $sexo !== $expectedSex) {
                $estados[] = EstadoClinico::InconsistenciaSexoRuta;
                $motivos[] = sprintf(
                    'Sexo %s incompatible con la ruta del diagnóstico; se conserva la ruta %s.',
                    $sexo,
                    $ruta->value
                );
            }
        } elseif (in_array($sexo, ['M', 'F'], true)) {
            $ruta = $sexo === 'M' ? RutaCalculo::Prostata : RutaCalculo::Cervix;
            $origen = OrigenRuta::Sexo;
            $estados[] = EstadoClinico::SinDiagnosticoRuta;
            $motivos = ['No se detectó diagnóstico C61 ni C53 en el mes; ruta asignada por sexo.'];
        } else {
            $ruta = RutaCalculo::NoAsignable;
            $origen = OrigenRuta::Ninguno;
            $estados[] = EstadoClinico::SexoNoReconocido;
            $estados[] = EstadoClinico::SinDiagnosticoRuta;
            $motivos = ['No se detectó diagnóstico C61 ni C53 y el sexo no permite asignar una ruta.'];
        }

        if ($profile['hasMissingIdentity']) {
            $estados[] = EstadoClinico::SinDocumento;
            $motivos[] = 'Falta tipo o número de documento.';
        }

        if ($profile['hasMissingDate']) {
            $estados[] = EstadoClinico::SinFecha;
            $motivos[] = 'No se pudo asignar el registro a un mes de atención.';
        }

        $estadosClinicos = $estados === []
            ? [EstadoClinico::Correcto->value]
            : array_map(static fn (EstadoClinico $estado): string => $estado->value, $estados);

        return [
            'ruta' => $ruta->value,
            'origenRuta' => $origen->value,
            'estadosClinicos' => $estadosClinicos,
            'estadoClinico' => implode(' + ', $estadosClinicos),
            'motivoClasificacion' => implode(' ', $motivos),
            'casoId' => $this->caseId($profile['profileKey']),
        ];
    }

    /**
     * Identificador interno del paciente-mes para enlazar servicios y episodios.
     * Puede contener el documento real y nunca debe publicarse sin seudonimizar.
     */
    private function caseId(string $profileKey): string
    {
        $parts = explode('|', $profileKey);

        if ($parts[0] === 'SIN_IDENTIFICACION') {
            return implode('-', ['SIN_DOCUMENTO', $parts[1], 'U'.((int) $parts[2] + 1), $parts[3]]);
        }

        return implode('-', array_slice($parts, 1));
    }

    /**
     * @param  array<string, mixed>  $file
     * @return \Generator<int, array<string, int|float|string|bool>, mixed, void>
     */
    private function recordsForFile(array $file): \Generator
    {
        $factura = $file['factura'] ?? $this->scalarFromJsonFile($file['path'], '/numFactura');

        foreach ($this->usersForFile($file['path']) as $position => $usuario) {
            if (! is_array($usuario)) {
                continue;
            }

            foreach ($this->recordsForUser(
                $usuario,
                $factura,
                $file['fileName'],
                (string) $position,
                $file['condicion'] ?? CondicionArchivo::Unico->value
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
        string $position,
        string $condicionArchivo = 'UNICO'
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
        $hospitalRanges = $this->hospitalizationRanges(
            is_array($servicios['hospitalizacion'] ?? null) ? $servicios['hospitalizacion'] : [],
            $archivoOrigen.'|'.$position
        );
        $usuarioJson = $this->scalarFields($usuario);

        foreach ($servicios as $tipoServicio => $listaServicios) {
            if (! is_array($listaServicios)) {
                continue;
            }

            foreach ($listaServicios as $indiceServicio => $servicio) {
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
                    'condicionArchivo' => $condicionArchivo,
                    'posicionUsuario' => $position,
                    'huellaServicio' => md5(json_encode($servicio, JSON_PARTIAL_OUTPUT_ON_ERROR) ?: ''),
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
                    'evidenciaSecundaria' => $this->secondaryEvidence($diagnostico, $servicio),
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
                    'segmentoHospitalario' => $tipoServicio === 'hospitalizacion'
                        ? $archivoOrigen.'|'.$position.'|'.$indiceServicio
                        : (string) ($this->containingHospitalization($fechaAtencion, $hospitalRanges)['clave'] ?? ''),
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
                    'usuarioJson' => $usuarioJson,
                    'servicioJson' => $this->scalarFields($servicio),
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
            'usuarioJson' => $record['usuarioJson'] ?? [],
            'serviciosEnRevision' => 0,
            'facturas' => [],
            'routeEvidence' => [],
            'diagnosticosReportados' => [],
            'evidenciaSecundaria' => [],
            'serviceCount' => 0,
            'serviciosConTarifa' => 0,
            'serviciosSinTarifa' => 0,
            'serviciosQueSuman' => 0,
            'serviciosEstadoSinTarifa' => 0,
            'serviciosRevisarPresentacion' => 0,
            'valorRipsReportado' => 0.0,
            'costoEstimadoReferencia' => 0.0,
            'costoValido' => 0.0,
            'costoValidoConAlerta' => 0.0,
            'costoPotencialNoSuma' => 0.0,
            'costoPotencialProstata' => 0.0,
            'costoPotencialCervix' => 0.0,
            'controlHospitalario' => self::emptyHospitalControl(),
            'episodiosHospitalarios' => [],
            'serviceCounts' => array_fill_keys(self::SERVICE_TYPES, 0),
            'primeraFechaAtencion' => '',
            'ultimaFechaAtencion' => '',
            'hasMissingIdentity' => $record['hasMissingIdentity'],
            'hasIdentityMismatch' => $record['hasIdentityMismatch'] ?? false,
            'hasMissingDate' => $record['hasMissingDate'],
        ];
    }

    /**
     * @return array<string, int|float>
     */
    public static function emptyHospitalControl(): array
    {
        return [
            'paquetes' => 0,
            'costoPaquetes' => 0.0,
            'serviciosAbsorbidos' => 0,
            'costoIndividualExcluido' => 0.0,
            'serviciosCobradosPorFuera' => 0,
            'costoCobradoPorFuera' => 0.0,
            'serviciosReglaPendiente' => 0,
            'costoReglaPendiente' => 0.0,
            'urgenciasCobradas' => 0,
            'costoUrgencias' => 0.0,
            'serviciosIncluidosUrgencia' => 0,
            'costoIncluidoUrgencia' => 0.0,
            'serviciosUrgenciaPendiente' => 0,
            'costoReferenciaUrgenciaPendiente' => 0.0,
            'clasificadosPorServicio' => 0,
            'clasificadosPorAgrupador' => 0,
            'clasificadosPorFallback' => 0,
            'clasificadosPorCodigo' => 0,
            'clasificadosPorTipo' => 0,
            'clasificadosSinCategoria' => 0,
            'paquetesSimulados' => 0,
            'costoPaquetesSimulados' => 0.0,
        ];
    }

    /**
     * Agrega al lote los segmentos vigentes ya registrados en el libro de los
     * mismos pacientes (como externos). Si el lote trae una versión distinta de
     * un segmento registrado y no es una corrección autorizada, el informe usa
     * la versión registrada y conserva la del RIPS para enviarla a revisión.
     *
     * @param  list<array<string, mixed>>  $segmentos
     * @param  array<int, array<string, mixed>>  $files
     * @return list<array<string, mixed>>
     */
    private function withStoredSegments(array $segmentos, array $files): array
    {
        $almacen = $this->libroPersistente['almacen'];
        $prestadores = [];

        foreach ($files as $file) {
            $prestadores[$file['fileName']] = $this->scalarFromJsonFile($file['path'], '/numDocumentoIdObligado');
        }

        $enLote = [];
        $pacientes = [];

        foreach ($segmentos as $indice => $segmento) {
            if (str_starts_with((string) $segmento['paciente'], 'SIN_DOCUMENTO|')) {
                continue;
            }

            $pacientes[(string) $segmento['paciente']] = $segmento;
            [, $posicion, $orden] = array_pad(explode('|', (string) $segmento['clave']), 3, '');
            $ledger = [
                'prestador' => (string) ($prestadores[$segmento['archivo']] ?? ''),
                'factura' => (string) $segmento['factura'],
                'consecutivo' => $segmento['consecutivo'] !== '' ? (string) $segmento['consecutivo'] : 'U'.$posicion.'-H'.$orden,
            ];

            foreach ([RutaCalculo::Prostata->value, RutaCalculo::Cervix->value] as $ruta) {
                $enLote[LibroPaquetesHospitalarios::identidad($ruta, (string) $segmento['paciente'], $ledger)] = $indice;
            }
        }

        foreach ($pacientes as $paciente => $muestra) {
            foreach ([RutaCalculo::Prostata->value, RutaCalculo::Cervix->value] as $ruta) {
                foreach ($almacen->segmentos($ruta, $paciente) as $guardado) {
                    if ($guardado['estado'] !== 'VIGENTE') {
                        continue;
                    }

                    $contenido = [
                        'inicio' => $guardado['inicio'],
                        'fin' => $guardado['fin'],
                        'destino' => $guardado['destino'],
                        'autorizacion' => (string) ($guardado['autorizacion'] ?? ''),
                        'prestadorServicio' => (string) ($guardado['prestador_servicio'] ?? ''),
                    ];
                    $indice = $enLote[$guardado['identidad']] ?? null;

                    if ($indice !== null) {
                        $loteActual = $segmentos[$indice];

                        if (LibroPaquetesHospitalarios::huella($loteActual) !== $guardado['hash_contenido'] && ! $this->libroPersistente['esCorreccion']) {
                            $segmentos[$indice] = [
                                ...$loteActual,
                                ...$contenido,
                                'contenidoRips' => array_intersect_key($loteActual, $contenido),
                                'pendienteRevision' => true,
                            ];
                        }

                        continue;
                    }

                    $segmentos[] = [
                        ...$contenido,
                        'clave' => 'EXT|'.$guardado['identidad'].'#'.$guardado['version'],
                        'consecutivo' => (string) $guardado['consecutivo'],
                        'paciente' => $paciente,
                        'tipoDocumento' => (string) $muestra['tipoDocumento'],
                        'documento' => (string) $muestra['documento'],
                        'factura' => (string) $guardado['factura'],
                        'posicion' => 'EXT',
                        'archivo' => (string) ($guardado['archivo'] ?? ''),
                        'excluido' => false,
                        'consultasUrgencia' => [],
                        'ruta' => $ruta,
                        'externo' => true,
                    ];
                }
            }
        }

        return $segmentos;
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
     * Campos escalares tal como vienen en el JSON, para exportarlos sin
     * transformarlos ni traducirlos.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, int|float|string|bool|null>
     */
    private function scalarFields(array $data): array
    {
        return array_filter(
            $data,
            static fn (mixed $value): bool => ! is_array($value) && ! is_object($value)
        );
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
     * @return array<int, string>
     */
    private function secondaryEvidence(string $diagnosticoPrincipal, array $servicio): array
    {
        $evidence = [];

        if ($this->startsWithAny($diagnosticoPrincipal, self::PRINCIPAL_SECONDARY_PREFIXES)) {
            $evidence[] = $diagnosticoPrincipal;
        }

        foreach (self::RELATED_DIAGNOSIS_FIELDS as $field) {
            $code = strtoupper($this->stringValue($servicio[$field] ?? null));

            if ($this->startsWithAny($code, self::SECONDARY_EVIDENCE_PREFIXES)) {
                $evidence[] = $code;
            }
        }

        return array_values(array_unique($evidence));
    }

    /**
     * @param  array<int, string>  $prefixes
     */
    private function startsWithAny(string $value, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($value, $prefix)) {
                return true;
            }
        }

        return false;
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

    private function scalarFromJsonFile(string $path, string $pointer): string
    {
        try {
            $values = Items::fromFile(
                $path,
                [
                    'pointer' => $pointer,
                    'decoder' => new ExtJsonDecoder(true),
                ]
            );

            foreach ($values as $value) {
                return $this->stringValue($value);
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
     * @return array<string, float|int|string|bool>
     */
    private function defaultReferenceMatch(array $record): array
    {
        return [
            'descripcionOficial' => '',
            'nombreNotaTecnica' => '',
            'encontradoEnNotaTecnica' => false,
            'enNotaTecnica' => false,
            'catalogoCruce' => '',
            'grupoTecnico' => '',
            'valorRipsReportado' => (float) $record['valorRips'],
            'tarifaReferencia' => 0.0,
            'costoEstimadoReferencia' => 0.0,
            'estadoCruce' => 'SIN_CATALOGO_ACTIVO',
            'estadoTarifario' => EstadoTarifario::SinTarifa->value,
            'fuenteTarifa' => FuenteTarifa::Ninguna->value,
            'formaCruce' => FormaCruce::SinCruce->value,
            'candidatosPresentacion' => '',
            'cum' => '',
            'principioActivo' => '',
            'atc' => '',
            'servicioNotaTecnica' => '',
            'agrupadorNotaTecnica' => '',
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
     * @param  array<int|string, mixed>  $hospitalizations
     * @return array<int, array{clave: string, inicio: string, fin: string, destino: string, consecutivo: string}>
     */
    private function hospitalizationRanges(array $hospitalizations, string $segmentPrefix): array
    {
        $ranges = [];

        foreach ($hospitalizations as $index => $hospitalization) {
            if (! is_array($hospitalization)) {
                continue;
            }

            $segment = $this->hospitalizationSegment($hospitalization, $segmentPrefix.'|'.$index);

            if ($segment['inicio'] !== '' && $segment['fin'] !== '') {
                $ranges[] = $segment;
            }
        }

        return $ranges;
    }

    /**
     * Registro de hospitalización del RIPS (segmento de una estancia lógica).
     *
     * @param  array<string, mixed>  $hospitalization
     * @return array{clave: string, inicio: string, fin: string, destino: string, consecutivo: string}
     */
    private function hospitalizationSegment(array $hospitalization, string $clave): array
    {
        return [
            'clave' => $clave,
            'inicio' => $this->firstStringValue($hospitalization, ['fechaInicioAtencion', 'fechaIngreso']),
            'fin' => $this->firstStringValue($hospitalization, ['fechaEgreso', 'fechaFinAtencion']),
            'destino' => $this->stringValue($hospitalization['condicionDestinoUsuarioEgreso'] ?? null),
            'consecutivo' => $this->stringValue($hospitalization['consecutivo'] ?? null),
            'autorizacion' => $this->stringValue($hospitalization['numAutorizacion'] ?? null),
            'prestadorServicio' => $this->stringValue($hospitalization['codPrestador'] ?? null),
        ];
    }

    /**
     * Hospitalización del mismo usuario y archivo cuyo rango contiene la fecha.
     *
     * @param  array<int, array{clave: string, inicio: string, fin: string, destino: string, consecutivo: string}>  $hospitalRanges
     * @return array{clave: string, inicio: string, fin: string, destino: string, consecutivo: string}|null
     */
    private function containingHospitalization(string $fechaAtencion, array $hospitalRanges): ?array
    {
        if ($fechaAtencion === '') {
            return null;
        }

        foreach ($hospitalRanges as $range) {
            if ($fechaAtencion >= $range['inicio'] && $fechaAtencion <= $range['fin']) {
                return $range;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array{clave: string, inicio: string, fin: string, destino: string, consecutivo: string}>  $hospitalRanges
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

        return $this->containingHospitalization($fechaAtencion, $hospitalRanges) === null ? '' : 'H';
    }
}
