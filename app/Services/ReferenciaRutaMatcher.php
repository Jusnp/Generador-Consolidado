<?php

namespace App\Services;

use App\Models\CatalogoReferencia;
use App\Models\CatalogoReferenciaItem;
use App\Models\ReferenciaManual;
use App\Programas\LaMaria\EstadoContractual;
use App\Programas\LaMaria\EstadoTarifario;
use App\Programas\LaMaria\FormaCruce;
use App\Programas\LaMaria\FuenteTarifa;
use App\Programas\LaMaria\HistoricoTarifarioConsolidado;
use App\Programas\LaMaria\LaMariaPrograma;
use App\Programas\LaMaria\PoliticaDispensacion;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ReferenciaRutaMatcher
{
    /**
     * @var array<string, array<string, array<string, mixed>>>
     */
    private array $officialCodes = [];

    /**
     * @var array<string, array<string, array<string, mixed>>>
     */
    private array $technicalCodes = [];

    /**
     * @var array<string, array<int, array<string, mixed>>>
     */
    private array $oncologyReferences = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $technicalReferences = [];

    /**
     * @var array<string, array<string, mixed>|null>
     */
    private array $cumCache = [];

    /**
     * @var array<string, CatalogoReferencia>
     */
    private array $cumCatalogs = [];

    /**
     * @var array<string, array<string, array<string, mixed>>>
     */
    private array $medicationByCum = [];

    /**
     * Todas las presentaciones de la nota técnica por principio activo, para
     * no aplicar en silencio la primera tarifa cuando hay varias.
     *
     * @var array<string, array<string, array<int, array<string, mixed>>>>
     */
    private array $medicationByPrincipio = [];

    /** @var array<string, array<string, mixed>> */
    private array $manualReferences = [];

    /** @var array<string, array<string, mixed>> */
    private array $dispensationReferences = [];

    /**
     * Dispensaciones por ruta, documento y mes (con o sin precio): evidencia
     * para identificar el medicamento entregado al paciente en el período.
     *
     * @var array<string, list<array<string, mixed>>>
     */
    private array $dispensationEvidence = [];

    /**
     * Precios unitarios administrativos de dispensación por ruta y CUM, con su
     * mes. Solo sirven de referencia histórica; no son tarifa contractual.
     *
     * @var array<string, array<string, list<array{mes: string, tarifa: float}>>>
     */
    private array $dispensationPriceHistory = [];

    /**
     * Registros del CUM por IUM (identificador único de medicamento).
     *
     * @var array<string, list<array<string, mixed>>>
     */
    private array $iumIndex = [];

    /**
     * Registros del CUM por los primeros caracteres del IUM. Solo se usan para
     * comprobar que una familia del registro tiene una única presentación.
     *
     * @var array<string, list<array<string, mixed>>>
     */
    private array $iumFamilyIndex = [];

    /**
     * Complemento contractual de medicamentos por ruta y CUM (todas sus filas:
     * precios, vigencias y estados contractuales).
     *
     * @var array<string, array<string, list<array<string, mixed>>>>
     */
    private array $complementByCum = [];

    /**
     * Caracteres del IUM que agrupan una familia del registro (principio,
     * concentración y forma). La regla nunca confía solo en el prefijo: exige
     * que todos los registros de la familia compartan una sola presentación.
     */
    private const IUM_LONGITUD_FAMILIA = 10;

    private PoliticaDispensacion $politicaDispensacion;

    /**
     * @var array<int, array{catalogo: CatalogoReferencia, coincidencias: int}>
     */
    private array $usage = [];

    /**
     * @param  Collection<int, CatalogoReferencia>|null  $catalogos
     */
    public function __construct(?Collection $catalogos = null, ?string $rutaEjecucion = null, ?PoliticaDispensacion $politicaDispensacion = null)
    {
        $this->politicaDispensacion = $politicaDispensacion ?? PoliticaDispensacion::configurada();

        if ($catalogos === null) {
            // Solo catálogos del programa La María; opcionalmente acotados a la ejecución.
            $catalogos = CatalogoReferencia::query()
                ->forPrograma(LaMariaPrograma::SLUG)
                ->active()
                ->where('tipo', '!=', CatalogoReferenciaImporter::TYPE_MEDICAMENTOS_CUM)
                ->where('tipo', '!=', HistoricoTarifarioConsolidado::TIPO)
                ->when(
                    $rutaEjecucion === LaMariaPrograma::RUTA_PROSTATA,
                    fn ($query) => $query->where('tipo', '!=', CatalogoReferenciaImporter::TYPE_NOTA_CERVIX)
                )
                ->when(
                    $rutaEjecucion === LaMariaPrograma::RUTA_CERVIX,
                    fn ($query) => $query->where('tipo', '!=', CatalogoReferenciaImporter::TYPE_NOTA_PROSTATA)
                )
                ->with(['items' => fn ($query) => $query->where('activo', true)])
                ->get()
                ->concat(
                    CatalogoReferencia::query()
                        ->forPrograma(LaMariaPrograma::SLUG)
                        ->active()
                        ->where('tipo', CatalogoReferenciaImporter::TYPE_MEDICAMENTOS_CUM)
                        ->get()
                );
        }

        foreach ($catalogos as $catalogo) {
            if ($catalogo->tipo === CatalogoReferenciaImporter::TYPE_MEDICAMENTOS_CUM) {
                $this->cumCatalogs[$catalogo->tipo] = $catalogo;
            }

            $cumSinPrecarga = $catalogo->tipo === CatalogoReferenciaImporter::TYPE_MEDICAMENTOS_CUM
                && ! $catalogo->relationLoaded('items');
            $items = $cumSinPrecarga
                ? $catalogo->items()->where('activo', true)->cursor()
                : $catalogo->items;

            foreach ($items as $item) {
                if ($cumSinPrecarga && ($item->metadatos['ium'] ?? '') === '') {
                    continue;
                }
                $entry = $this->entry($catalogo, $item);

                if ($catalogo->tipo === CatalogoReferenciaImporter::TYPE_ANEXOS_2706) {
                    $this->officialCodes[$item->categoria ?? ''][$item->codigo] = $entry;
                }

                if (in_array($catalogo->tipo, [
                    CatalogoReferenciaImporter::TYPE_NOTA_CERVIX,
                    CatalogoReferenciaImporter::TYPE_NOTA_PROSTATA,
                ], true)) {
                    $this->technicalReferences[] = $entry;

                    if ($item->ruta !== null && $item->codigo !== '') {
                        $existing = $this->technicalCodes[$item->ruta][$item->codigo] ?? null;

                        if ($existing === null || $this->technicalPriority($entry) > $this->technicalPriority($existing)) {
                            $this->technicalCodes[$item->ruta][$item->codigo] = $entry;
                        }
                    }

                    if (in_array($item->categoria, ['ONCOLOGIA_QUIMIOTERAPIA', 'ONCOLOGIA_RADIOTERAPIA'], true)) {
                        $this->oncologyReferences[$item->ruta ?? ''][] = $entry;
                    }
                }

                if ($catalogo->tipo === CatalogoReferenciaImporter::TYPE_NOTA_MEDICAMENTOS) {
                    $this->technicalReferences[] = $entry;
                    $this->indexMedicationTechnicalEntry($entry);
                }

                if ($catalogo->tipo === CatalogoReferenciaImporter::TYPE_MEDICAMENTOS_CUM) {
                    $this->cumCache[$item->codigo] = $entry;
                    $ium = $this->normaliseCode((string) ($entry['metadatos']['ium'] ?? ''));

                    if ($ium !== '') {
                        $this->iumIndex[$ium][] = $entry;
                        $this->iumFamilyIndex[substr($ium, 0, self::IUM_LONGITUD_FAMILIA)][] = $entry;
                    }
                }

                if ($catalogo->tipo === CatalogoReferenciaImporter::TYPE_COMPLEMENTO_MEDICAMENTOS_PROSTATA && $item->activo !== false) {
                    $this->complementByCum[$item->ruta ?? LaMariaPrograma::RUTA_PROSTATA][$this->normaliseCode((string) $item->codigo)][] = $entry;
                }

                if (in_array($catalogo->tipo, [
                    CatalogoReferenciaImporter::TYPE_DISPENSACION_CERVIX,
                    CatalogoReferenciaImporter::TYPE_DISPENSACION_PROSTATA,
                ], true)) {
                    $this->indexDispensationReference($entry);
                }
            }
        }

        if (app()->bound('db')) {
            ReferenciaManual::query()
                ->active()
                ->forProgram(LaMariaPrograma::SLUG)
                ->get()
                ->each(function (ReferenciaManual $item): void {
                    $key = ($item->ruta ?? '*').'|'.strtolower($item->tipo).'|'.$this->normaliseCode((string) $item->codigo);
                    $this->manualReferences[$key] = [
                        'codigo' => $item->codigo,
                        'descripcion' => $item->nombre,
                        'tarifaReferencia' => (float) $item->tarifa,
                        'categoria' => strtoupper($item->tipo),
                        'catalogoLabel' => 'Referencia manual',
                        'ruta' => $item->ruta,
                        'fuente' => 'MANUAL',
                    ];
                });
        }
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, float|int|string|bool>
     */
    public function match(array $record, string $route): array
    {
        $code = $this->normaliseCode((string) ($record['codigo'] ?? ''));
        $serviceType = (string) ($record['tipoServicio'] ?? '');
        $official = $this->officialReference($serviceType, $code);
        $technical = $this->technicalCodes[$route][$code] ?? null;
        $isMedication = $serviceType === 'medicamentos';
        $resolution = $isMedication ? $this->resolveMedication($record, $route) : null;
        $cum = $resolution['cum'] ?? null;
        // El precio de dispensación solo aplica cuando el propio RIPS trae el CUM
        // dispensado; una presentación deducida (IUM o dispensación) no lo hereda.
        $dispensation = $isMedication
            ? $this->dispensationReference(
                $record,
                $route,
                ($resolution['metodo'] ?? '') === FormaCruce::CumExacto->value ? $cum : null
            )
            : null;
        $resolutionBlocksTariff = in_array($resolution['estado'] ?? '', ['MUESTRA_MEDICA', 'AMBIGUO', 'CONFLICTO'], true);
        // Presentación deducida (IUM o dispensación): el precio administrativo del
        // mismo paciente, mes y CUM solo se informa como externo potencial; nunca suma.
        $deducedDispensation = $isMedication && $dispensation === null && $cum !== null && ! $resolutionBlocksTariff
            && ($resolution['metodo'] ?? '') !== FormaCruce::CumExacto->value
                ? $this->dispensationReference($record, $route, $cum)
                : null;
        $medicationLookup = match (true) {
            ! $isMedication => null,
            $resolutionBlocksTariff => [
                'entry' => null,
                'forma' => FormaCruce::PrincipioAmbiguo->value,
                'candidatos' => $resolution['candidatos'],
            ],
            default => $this->medicationTechnicalReferenceForCodes(
                $resolution['codigos'] === [] ? [$code] : $resolution['codigos'],
                (string) ($record['nombreServicio'] ?? ''),
                $route,
                (string) ($cum['metadatos']['principio_activo'] ?? $cum['descripcion'] ?? '')
            ),
        };

        if ($medicationLookup !== null && ($medicationLookup['forma'] ?? '') === FormaCruce::CumExacto->value
            && in_array($resolution['metodo'] ?? '', [FormaCruce::IumExacto->value, FormaCruce::DispensacionPacientePeriodo->value, FormaCruce::IumFamiliaDispensacion->value], true)) {
            $medicationLookup['forma'] = $resolution['metodo'];
        }

        $medicationNt = $medicationLookup['entry'] ?? null;
        $isAmbiguous = ($medicationLookup['forma'] ?? null) === FormaCruce::PrincipioAmbiguo->value;
        $manual = $this->manualReference($route, $serviceType, $code);
        $complement = $isMedication && ! $resolutionBlocksTariff
            ? $this->complementReference($resolution['codigos'] === [] ? [$code] : $resolution['codigos'], $route, substr((string) ($record['fechaAtencion'] ?? ''), 0, 10))
            : ['entry' => null, 'pendiente' => null, 'motivo' => ''];
        $oncologyScheme = $isMedication
            ? $this->oncologyScheme($route, (string) ($record['nombreServicio'] ?? ''), $cum)
            : '';

        foreach ([$official, $technical, $cum, $medicationNt, $complement['entry'], $manual, $dispensation] as $reference) {
            if ($reference !== null) {
                $this->registerUsage($reference);
            }
        }

        $notaTecnica = $isMedication ? $medicationNt : $technical;
        $nombreNotaTecnica = (string) ($notaTecnica['descripcion'] ?? '');
        // La tarifa de radioterapia es por tratamiento: no se aplica a las líneas
        // del RIPS hasta que el negocio confirme qué representa cada línea.
        $radioterapiaPendiente = ! $isMedication && ($technical['categoria'] ?? null) === 'ONCOLOGIA_RADIOTERAPIA';

        $description = $official['descripcion'] ?? $technical['descripcion'] ?? $cum['descripcion'] ?? '';
        $catalogNames = array_values(array_unique(array_filter([
            $official['catalogoLabel'] ?? '',
            $technical['catalogoLabel'] ?? '',
            $medicationNt['catalogoLabel'] ?? '',
            $complement['entry']['catalogoLabel'] ?? '',
            $cum['catalogoLabel'] ?? '',
            $dispensation['catalogoLabel'] ?? '',
        ])));
        $isComplement = ($technical['categoria'] ?? null) === 'MEDICAMENTOS_NUEVOS';
        [$rate, $rateSource, $matchForm] = match (true) {
            $radioterapiaPendiente => [null, FuenteTarifa::Ninguna, FormaCruce::CodigoExacto->value],
            ($notaTecnica['tarifaReferencia'] ?? null) !== null => [
                $notaTecnica['tarifaReferencia'],
                FuenteTarifa::NotaTecnica,
                $isMedication ? $medicationLookup['forma'] : FormaCruce::CodigoExacto->value,
            ],
            ($technical['tarifaReferencia'] ?? null) !== null => [
                $technical['tarifaReferencia'],
                $isComplement ? FuenteTarifa::Complemento : FuenteTarifa::NotaTecnica,
                $isMedication ? FormaCruce::CumExacto->value : FormaCruce::CodigoExacto->value,
            ],
            // Complemento contractual: solo un precio VERIFICADO y vigente en la fecha de atención.
            ($complement['entry']['tarifaReferencia'] ?? null) !== null => [
                $complement['entry']['tarifaReferencia'],
                FuenteTarifa::Complemento,
                (string) ($resolution['metodo'] ?? FormaCruce::CumExacto->value),
            ],
            ($manual['tarifaReferencia'] ?? null) !== null => [
                $manual['tarifaReferencia'],
                FuenteTarifa::ReferenciaManual,
                FormaCruce::ManualCodigo->value,
            ],
            $this->politicaDispensacion === PoliticaDispensacion::Fase1 && ($dispensation['tarifaReferencia'] ?? null) !== null => [
                $dispensation['tarifaReferencia'],
                FuenteTarifa::Dispensacion,
                FormaCruce::DocumentoCumMes->value,
            ],
            default => [
                null,
                FuenteTarifa::Ninguna,
                match (true) {
                    $isAmbiguous => FormaCruce::PrincipioAmbiguo->value,
                    $medicationLookup !== null => $medicationLookup['forma'],
                    $technical !== null => FormaCruce::CodigoExacto->value,
                    default => FormaCruce::SinCruce->value,
                },
            ],
        };
        $tariffState = match (true) {
            $rate !== null && $rate > 0 => EstadoTarifario::ConTarifa,
            $isAmbiguous => EstadoTarifario::RevisarPresentacion,
            default => EstadoTarifario::SinTarifa,
        };
        $reportedQuantity = $record['cantidad'] ?? null;
        $quantityState = match (true) {
            $reportedQuantity === null || $reportedQuantity === '' => 'NO_INFORMADA',
            ! is_numeric($reportedQuantity) || ! is_finite((float) $reportedQuantity) || (float) $reportedQuantity < 0 => 'INVALIDA',
            (float) $reportedQuantity === 0.0 => 'CERO',
            default => 'POSITIVA_VALIDA',
        };
        $quantity = $this->numberOrDefault($reportedQuantity, $isMedication ? 0.0 : 1.0);

        if ($quantityState === 'INVALIDA') {
            $quantity = 0.0;
        }

        $estimatedCost = $tariffState === EstadoTarifario::ConTarifa ? round($rate * $quantity, 2) : 0.0;
        // Escenario referencial ampliado (FASE_1): el mismo cruce, pero el precio
        // administrativo de dispensación suma cuando no hay tarifa contractual.
        $referentialRate = $tariffState !== EstadoTarifario::ConTarifa && $rate === null && ! $radioterapiaPendiente
            && (float) ($dispensation['tarifaReferencia'] ?? 0) > 0
                ? (float) $dispensation['tarifaReferencia']
                : null;
        // Valor que no suma porque su fuente no es contractual o no está verificada.
        [$conditionedRate, $conditionedReason] = match (true) {
            $tariffState === EstadoTarifario::ConTarifa => [null, ''],
            $complement['pendiente'] !== null => [$complement['pendiente']['tarifa'], $complement['pendiente']['motivo']],
            $complement['motivo'] !== '' => [null, $complement['motivo']],
            $this->politicaDispensacion === PoliticaDispensacion::Condicionada && ($dispensation['tarifaReferencia'] ?? null) !== null => [(float) $dispensation['tarifaReferencia'], 'DISPENSACION_ADMINISTRATIVA_CONDICIONADA'],
            (float) ($deducedDispensation['tarifaReferencia'] ?? 0) > 0 => [(float) $deducedDispensation['tarifaReferencia'], 'PRESENTACION_DEDUCIDA_PRECIO_ADMINISTRATIVO_MISMO_PACIENTE'],
            default => [null, ''],
        };
        $status = $dispensation !== null && $notaTecnica === null && $technical === null && $manual === null
            ? 'MEDICAMENTO_CON_DISPENSACION'
            : $this->statusFor($official, $notaTecnica ?? $technical ?? $manual, $cum, $serviceType);
        $referenceCategory = (string) ($notaTecnica['categoria'] ?? $technical['categoria'] ?? '');
        $referenceValueType = match (true) {
            $radioterapiaPendiente => 'TARIFA_TRATAMIENTO_PENDIENTE',
            $rateSource === FuenteTarifa::Dispensacion => 'TARIFA_DISPENSACION_ADMINISTRATIVA',
            $serviceType === 'medicamentos' => 'TARIFA_UNITARIA_MEDICAMENTO',
            in_array($referenceCategory, ['ONCOLOGIA_QUIMIOTERAPIA', 'ONCOLOGIA_RADIOTERAPIA'], true) => 'TARIFA_APLICACION_CICLO',
            $referenceCategory === 'TRANSPORTE_ALBERGUE' => 'TARIFA_TRANSPORTE_ALBERGUE',
            $rate !== null => 'TARIFA_UNITARIA_SERVICIO',
            default => 'SIN_TARIFA',
        };

        return [
            'descripcionOficial' => (string) $description,
            'nombreNotaTecnica' => $nombreNotaTecnica,
            'encontradoEnNotaTecnica' => $notaTecnica !== null || $manual !== null,
            'catalogoCruce' => implode(' | ', array_values(array_unique(array_filter([
                ...$catalogNames,
                $manual['catalogoLabel'] ?? '',
            ])))),
            'grupoTecnico' => $notaTecnica === null
                ? ($technical === null ? ($manual === null ? '' : 'Referencia manual') : $this->technicalDisplayGroup($technical))
                : $this->technicalDisplayGroup($notaTecnica),
            'valorRipsReportado' => $this->numberOrDefault($record['valorRips'] ?? null, 0.0),
            'tarifaReferencia' => $rate ?? 0.0,
            'costoEstimadoReferencia' => $estimatedCost,
            'estadoCantidad' => $quantityState,
            'cantidadAplicada' => $isMedication && $quantityState === 'NO_INFORMADA' ? null : $quantity,
            'estadoCruce' => $status,
            'estadoTarifario' => $tariffState->value,
            'fuenteTarifa' => $rateSource->value,
            'formaCruce' => $matchForm,
            'enNotaTecnica' => $isMedication
                ? $medicationLookup !== null
                : $technical !== null && ! $isComplement,
            'candidatosPresentacion' => $isAmbiguous
                ? $this->presentationCandidates($medicationLookup['candidatos'])
                : '',
            'cum' => $cum !== null ? (string) $cum['codigo'] : ($isMedication ? $code : ''),
            'principioActivo' => $medicationNt['metadatos']['principio_activo']
                ?? $medicationLookup['candidatos'][0]['metadatos']['principio_activo']
                ?? $cum['metadatos']['principio_activo']
                ?? '',
            'atc' => $cum['metadatos']['atc'] ?? '',
            'resolucionMedicamento' => $resolution['estado'] ?? '',
            'metodoResolucion' => $resolution['metodo'] ?? '',
            'cumResuelto' => (string) ($cum['codigo'] ?? ''),
            'evidenciaResolucion' => $resolution['evidencia'] ?? '',
            'muestraMedica' => ($resolution['estado'] ?? '') === 'MUESTRA_MEDICA',
            'servicioNotaTecnica' => (string) ($technical['metadatos']['servicio'] ?? ''),
            'agrupadorNotaTecnica' => (string) ($technical['metadatos']['agrupador'] ?? ''),
            'esquemaOncologico' => $oncologyScheme,
            'tipoValorReferencia' => $referenceValueType,
            'tarifaCondicionada' => $conditionedRate ?? 0.0,
            'valorCondicionado' => $conditionedRate === null ? 0.0 : round($conditionedRate * $quantity, 2),
            'motivoCondicionado' => $conditionedReason,
            'estadoContractualComplemento' => (string) ($complement['entry']['metadatos']['estado_contractual'] ?? ($complement['pendiente'] === null ? '' : EstadoContractual::Pendiente->value)),
            'politicaDispensacion' => $this->politicaDispensacion->value,
            'tarifaEscenarioReferencial' => $tariffState === EstadoTarifario::ConTarifa ? (float) $rate : ($referentialRate ?? 0.0),
            'costoEscenarioReferencial' => $tariffState === EstadoTarifario::ConTarifa
                ? $estimatedCost
                : ($referentialRate === null ? 0.0 : round($referentialRate * $quantity, 2)),
            'fuenteEscenarioReferencial' => $tariffState === EstadoTarifario::ConTarifa
                ? $rateSource->value
                : ($referentialRate === null ? '' : FuenteTarifa::Dispensacion->value),
            'presentacionCum' => $cum === null ? '' : implode(' / ', array_values(array_filter([
                (string) ($cum['metadatos']['producto'] ?? ''),
                trim(($cum['metadatos']['cantidad'] ?? '').' '.($cum['metadatos']['unidad_medida'] ?? '')),
                (string) ($cum['metadatos']['forma_farmaceutica'] ?? ''),
                (string) ($cum['metadatos']['unidad_referencia'] ?? ''),
            ]))),
            'categoriaNotaTecnica' => $radioterapiaPendiente ? 'ONCOLOGIA_RADIOTERAPIA' : $referenceCategory,
            'codigoNotaTecnica' => (string) (($isMedication ? $medicationNt : $technical)['codigo'] ?? ''),
        ];
    }

    /**
     * Precios unitarios administrativos de dispensación del CUM en la ruta
     * (cualquier paciente), para la valorización administrativa referencial.
     *
     * @return list<array{mes: string, tarifa: float}>
     */
    public function historialPreciosDispensacion(string $route, string $cum): array
    {
        return $this->dispensationPriceHistory[$route][$this->normaliseCode($cum)] ?? [];
    }

    /**
     * Conceptos de la nota técnica de la ruta (servicios, oncología, resumen,
     * hospitalización y transporte) con su proyección mensual.
     *
     * @return list<array<string, mixed>>
     */
    public function entradasNotaTecnica(string $route): array
    {
        return array_values(array_filter(
            $this->technicalReferences,
            static fn (array $reference): bool => ($reference['ruta'] ?? null) === $route
        ));
    }

    /**
     * Tarifa de la nota técnica de la ruta para un código, sin registrar uso
     * del catálogo (por ejemplo, la consulta de urgencias de una estancia de
     * menos de 24 horas).
     */
    public function technicalTariff(string $route, string $code): ?float
    {
        $rate = $this->technicalCodes[$route][$this->normaliseCode($code)]['tarifaReferencia'] ?? null;

        return $rate !== null && (float) $rate > 0 ? (float) $rate : null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $candidates
     */
    private function presentationCandidates(array $candidates): string
    {
        return implode(' | ', array_values(array_unique(array_map(
            static fn (array $entry): string => sprintf(
                '%s (%s)',
                $entry['descripcion'],
                $entry['tarifaReferencia'] === null ? 'sin tarifa' : number_format((float) $entry['tarifaReferencia'], 2, '.', '')
            ),
            $candidates
        ))));
    }

    private function manualReference(string $route, string $serviceType, string $code): ?array
    {
        return $this->manualReferences[$route.'|'.strtolower($serviceType).'|'.$code]
            ?? $this->manualReferences['*|'.strtolower($serviceType).'|'.$code]
            ?? null;
    }

    /**
     * @param  array<string, mixed>  $reference
     */
    private function indexDispensationReference(array $reference): void
    {
        $metadata = $reference['metadatos'] ?? [];
        $document = $this->normaliseCode((string) ($metadata['documento_paciente'] ?? ''));
        $date = substr((string) ($metadata['fecha_dispensacion'] ?? ''), 0, 7);
        $route = (string) ($reference['ruta'] ?? '');
        $code = $this->normaliseCode((string) ($reference['codigo'] ?? ''));
        $rate = (float) ($reference['tarifaReferencia'] ?? 0);

        if ($document !== '' && $date !== '' && $route !== '' && $code !== '') {
            $this->dispensationEvidence[implode('|', [$route, $document, $date])][] = $reference;
        }

        if ($document === '' || $date === '' || $route === '' || $code === '' || $rate <= 0) {
            return;
        }

        $this->dispensationPriceHistory[$route][$code][] = ['mes' => $date, 'tarifa' => $rate];
        $key = implode('|', [$route, $document, $code, $date]);
        $existing = $this->dispensationReferences[$key] ?? null;

        if ($existing === null || (string) ($metadata['fecha_dispensacion'] ?? '') > (string) ($existing['metadatos']['fecha_dispensacion'] ?? '')) {
            $this->dispensationReferences[$key] = $reference;
        }
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>|null  $cum
     * @return array<string, mixed>|null
     */
    private function dispensationReference(array $record, string $route, ?array $cum): ?array
    {
        $document = $this->normaliseCode((string) ($record['numDocumentoIdentificacion'] ?? ''));
        $month = substr((string) ($record['fechaAtencion'] ?? ''), 0, 7);

        if ($document === '' || $month === '') {
            return null;
        }

        $codes = array_unique(array_filter([
            $this->normaliseCode((string) ($record['codigo'] ?? '')),
            $this->normaliseCode((string) ($cum['codigo'] ?? '')),
        ]));

        foreach ($codes as $code) {
            $reference = $this->dispensationReferences[implode('|', [$route, $document, $code, $month])] ?? null;

            if ($reference !== null) {
                return $reference;
            }
        }

        return null;
    }

    public function resetUsage(): void
    {
        $this->usage = [];
    }

    /**
     * @return array<int, array<int, float|int|string>>
     */
    public function technicalReferenceRows(): array
    {
        return collect($this->technicalReferences)
            ->filter(static fn (array $reference): bool => in_array($reference['categoria'], [
                'SERVICIO_TECNICO',
                'ONCOLOGIA_QUIMIOTERAPIA',
                'ONCOLOGIA_RADIOTERAPIA',
                'PALIATIVOS',
                'MEDICAMENTO_NT',
                'MEDICAMENTO_NT_PALIATIVO',
                'RESUMEN_TECNICO',
                'HX_HOSPITALIZACION',
                'TRANSPORTE_ALBERGUE',
                'POBLACION_OBJETIVO',
            ], true))
            ->sortBy([
                ['ruta', 'asc'],
                ['categoria', 'asc'],
                ['codigo', 'asc'],
            ])
            ->map(function (array $reference): array {
                return [
                    $reference['ruta'] ?? '',
                    $this->technicalDisplayGroup($reference),
                    $reference['codigo'],
                    $reference['descripcion'],
                    $reference['tarifaReferencia'] ?? 0.0,
                    $reference['metadatos']['frecuencia_mensual'] ?? $reference['metadatos']['cantidad_referencia'] ?? '',
                    $reference['metadatos']['cantidad_mensual'] ?? '',
                    $reference['metadatos']['costo_mensual'] ?? '',
                    $reference['metadatos']['poblacion_referencia'] ?? '',
                    $this->referenceDetail($reference),
                    $reference['version'],
                    $reference['archivoOrigen'],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<int, int|string>>
     */
    public function catalogUsageRows(): array
    {
        return collect($this->usage)
            ->sortBy(static fn (array $usage): string => $usage['catalogo']->tipo)
            ->map(function (array $usage): array {
                $catalogo = $usage['catalogo'];

                return [
                    $this->catalogLabel($catalogo->tipo),
                    $catalogo->version,
                    $catalogo->archivo_origen,
                    $catalogo->fecha_referencia?->format('Y-m-d') ?? '',
                    $usage['coincidencias'],
                    'ACTIVO',
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Instantánea de catálogos activos cargados para trazabilidad de la ejecución.
     *
     * @return list<array{id: int, programa_slug: string, tipo: string, version: string, archivo_origen: string, contrato_id: int|null}>
     */
    public function appliedCatalogsSnapshot(): array
    {
        $catalogos = collect($this->usage)
            ->map(static fn (array $usage): CatalogoReferencia => $usage['catalogo'])
            ->concat($this->cumCatalogs)
            ->unique(static fn (CatalogoReferencia $catalogo): int => $catalogo->id)
            ->sortBy(static fn (CatalogoReferencia $catalogo): string => $catalogo->tipo)
            ->values();

        if ($catalogos->isEmpty()) {
            $catalogos = CatalogoReferencia::query()
                ->forPrograma(LaMariaPrograma::SLUG)
                ->active()
                ->orderBy('tipo')
                ->get();
        }

        return $catalogos
            ->map(static fn (CatalogoReferencia $catalogo): array => [
                'id' => $catalogo->id,
                'programa_slug' => $catalogo->programa_slug,
                'tipo' => $catalogo->tipo,
                'version' => $catalogo->version,
                'archivo_origen' => $catalogo->archivo_origen,
                'contrato_id' => $catalogo->contrato_id,
            ])
            ->all();
    }

    /**
     * @param  list<string>|null  $types
     * @return array<int, array{tipo: string, activo: bool, version: string}>
     */
    public function catalogStatus(?array $types = null): array
    {
        $available = [];

        foreach (array_merge($this->technicalReferences, array_filter($this->cumCache)) as $reference) {
            $available[$reference['catalogoTipo']] = $reference['version'];
        }

        foreach ($this->officialCodes as $references) {
            foreach ($references as $reference) {
                $available[$reference['catalogoTipo']] = $reference['version'];
            }
        }

        foreach ($this->cumCatalogs as $type => $catalogo) {
            $available[$type] = $catalogo->version;
        }

        foreach ($this->medicationByCum as $scoped) {
            foreach ($scoped as $reference) {
                $available[$reference['catalogoTipo']] = $reference['version'];
            }
        }

        $typeLabels = CatalogoReferenciaImporter::types();

        if ($types !== null) {
            $typeLabels = array_intersect_key($typeLabels, array_flip($types));
        }

        return collect($typeLabels)
            ->map(fn (string $label, string $type): array => [
                'tipo' => $label,
                'activo' => isset($available[$type]),
                'version' => $available[$type] ?? '',
            ])
            ->values()
            ->all();
    }

    /**
     * Obtiene el estado de los archivos de catálogo sin cargar sus filas.
     *
     * @param  list<string>  $types
     * @return array<int, array{tipo: string, activo: bool, version: string}>
     */
    public static function catalogStatusForProgram(string $programaSlug, array $types): array
    {
        $available = CatalogoReferencia::query()
            ->forPrograma($programaSlug)
            ->active()
            ->whereIn('tipo', $types)
            ->get(['tipo', 'version'])
            ->mapWithKeys(static fn (CatalogoReferencia $catalogo): array => [
                $catalogo->tipo => $catalogo->version,
            ]);

        return collect(CatalogoReferenciaImporter::types())
            ->only($types)
            ->map(static fn (string $label, string $type): array => [
                'tipo' => $label,
                'activo' => $available->has($type),
                'version' => $available->get($type, ''),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function officialReference(string $serviceType, string $code): ?array
    {
        $categoryOrder = match ($serviceType) {
            'consultas', 'procedimientos' => ['ANEXO_2'],
            'otrosServicios', 'urgencias', 'hospitalizacion' => ['ANEXO_4', 'ANEXO_2'],
            default => ['ANEXO_2', 'ANEXO_4'],
        };

        foreach ($categoryOrder as $category) {
            if (isset($this->officialCodes[$category][$code])) {
                return $this->officialCodes[$category][$code];
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function cumReference(string $code): ?array
    {
        if ($code === '') {
            return null;
        }

        if (array_key_exists($code, $this->cumCache)) {
            return $this->cumCache[$code];
        }

        $catalogo = $this->cumCatalogs[CatalogoReferenciaImporter::TYPE_MEDICAMENTOS_CUM] ?? null;

        if ($catalogo === null || $catalogo->relationLoaded('items')) {
            return $this->cumCache[$code] = null;
        }

        $item = CatalogoReferenciaItem::query()
            ->whereBelongsTo($catalogo, 'catalogoReferencia')
            ->where('activo', true)
            ->where('codigo', $code)
            ->first();

        if ($item === null) {
            $this->cumCache[$code] = null;

            return null;
        }

        return $this->cumCache[$code] = $this->entry($catalogo, $item);
    }

    /**
     * Identifica el medicamento del RIPS con relaciones exactas y verificables:
     * 1) CUM exacto; 2) IUM exacto con una sola presentación comercial;
     * 3) dispensación del mismo paciente y mes con un único CUM del mismo
     * principio activo (y, si se conoce, misma concentración y forma). Nunca
     * usa prefijos de IUM ni elige el primer registro: si quedan varias
     * presentaciones devuelve AMBIGUO, y una muestra médica sin evidencia de la
     * presentación comercial devuelve MUESTRA_MEDICA.
     *
     * @param  array<string, mixed>  $record
     * @return array{estado: string, metodo: string, cum: array<string, mixed>|null, codigos: list<string>, evidencia: string, candidatos: list<array<string, mixed>>}
     */
    private function resolveMedication(array $record, string $route): array
    {
        $code = $this->normaliseCode((string) ($record['codigo'] ?? ''));
        $result = static fn (string $estado, string $metodo, ?array $cum = null, array $codigos = [], string $evidencia = '', array $candidatos = []): array => [
            'estado' => $estado,
            'metodo' => $metodo,
            'cum' => $cum,
            'codigos' => $codigos,
            'evidencia' => $evidencia,
            'candidatos' => $candidatos,
        ];

        if ($code === '') {
            return $result('SIN_CODIGO', FormaCruce::SinCruce->value);
        }

        $cum = $this->cumReference($code);

        if ($cum !== null) {
            return $result('RESUELTO', FormaCruce::CumExacto->value, $cum, [$code], 'CUM '.$code);
        }

        $byIum = $this->cumReferencesByIum($code);
        $reference = null;

        if ($byIum !== []) {
            $commercial = array_values(array_filter($byIum, fn (array $entry): bool => ! $this->isMedicalSample($entry)));
            // Un CUM de varios principios activos tiene una fila por principio: la
            // presentación se compara por CUM, uniendo sus filas.
            $ingredientsByCode = [];

            foreach ($commercial as $entry) {
                $ingredientsByCode[(string) $entry['codigo']][] = $this->presentationKey($entry);
            }

            $presentations = array_unique(array_map(static function (array $keys): string {
                $keys = array_unique($keys);
                sort($keys);

                return implode('+', $keys);
            }, $ingredientsByCode));

            if (count($presentations) === 1) {
                $codes = array_keys($ingredientsByCode);
                $conflict = $this->dispensationConflict($record, $route, $commercial[0]);

                // El IUM y la dispensación del mismo paciente y mes muestran presentaciones
                // distintas: no se elige ninguna ni se reconoce costo hasta revisarlo.
                if ($conflict !== null) {
                    return $result(
                        'CONFLICTO',
                        FormaCruce::IumExacto->value,
                        null,
                        [],
                        'IUM '.$code.' corresponde al CUM '.implode(', ', $codes).', pero la dispensación del período registra '.$conflict,
                        [$commercial[0]]
                    );
                }

                return $result('RESUELTO', FormaCruce::IumExacto->value, $commercial[0], array_map('strval', $codes), 'IUM '.$code.' → CUM '.implode(', ', $codes));
            }

            if (count($presentations) > 1) {
                return $result('AMBIGUO', FormaCruce::IumExacto->value, null, [], 'IUM '.$code.' con varias presentaciones', $commercial);
            }

            // Solo hay muestras médicas: se busca la presentación comercial en la dispensación.
            $reference = $byIum[0];
        }

        $principios = $this->principioCandidates((string) ($record['nombreServicio'] ?? ''));

        if ($reference !== null) {
            $principios[] = $this->normalisePrincipio((string) ($reference['metadatos']['principio_activo'] ?? ''));
        }

        $dispensed = [];

        foreach ($this->dispensationEvidenceFor($record, $route) as $evidence) {
            $dispensedCum = $this->cumReference($this->normaliseCode((string) $evidence['codigo']));
            $principio = $this->normalisePrincipio((string) ($dispensedCum['metadatos']['principio_activo'] ?? ''));

            if ($dispensedCum === null || ! $this->samePrincipio($principio, $principios) || $this->isMedicalSample($dispensedCum)) {
                continue;
            }

            if ($reference !== null && $this->strengthKey($dispensedCum) !== $this->strengthKey($reference)) {
                continue;
            }

            $dispensed[(string) $dispensedCum['codigo']] = [$dispensedCum, $evidence];
        }

        if (count($dispensed) === 1) {
            [$dispensedCum, $evidence] = array_values($dispensed)[0];

            return $result(
                'RESUELTO',
                FormaCruce::DispensacionPacientePeriodo->value,
                $dispensedCum,
                [(string) $dispensedCum['codigo']],
                sprintf(
                    'Código RIPS %s%s; dispensación del %s del CUM %s (fila %s)',
                    $code,
                    $reference !== null ? ' (IUM de muestra médica '.$reference['codigo'].')' : '',
                    $evidence['metadatos']['fecha_dispensacion'] ?? '',
                    $dispensedCum['codigo'],
                    $evidence['metadatos']['fila'] ?? 's/d'
                )
            );
        }

        if (count($dispensed) > 1) {
            $family = $byIum === [] ? $this->resolveByIumFamily($code, $record, $route) : null;

            if ($family !== null && $family['cum'] !== null) {
                return $result('RESUELTO', FormaCruce::IumFamiliaDispensacion->value, $family['cum'], [(string) $family['cum']['codigo']], $family['evidencia']);
            }

            return $result(
                'AMBIGUO',
                FormaCruce::DispensacionPacientePeriodo->value,
                null,
                [],
                'Varios CUM dispensados en el período'.($family === null ? '' : '. AMBIGUO — NO SUMA AUTOMÁTICAMENTE: '.$family['evidencia']),
                array_column($dispensed, 0)
            );
        }

        if ($reference !== null) {
            return $result('MUESTRA_MEDICA', FormaCruce::IumExacto->value, null, [], 'IUM '.$code.' de muestra médica sin dispensación comercial verificable', [$reference]);
        }

        return $result('SIN_RESOLUCION', FormaCruce::SinCruce->value);
    }

    /**
     * Resolución controlada de un IUM ausente del registro cuando al paciente se
     * le dispensaron varias presentaciones del principio en el mes. Se acepta solo
     * si se cumplen todas las condiciones: (1) el IUM no existe en el registro;
     * (2) los registros comerciales de su familia (primeros caracteres del IUM)
     * comparten un único principio, concentración y forma farmacéutica; (3) ese
     * principio es el del nombre del servicio RIPS; (4) en la dispensación del
     * mismo paciente y mes hay un único CUM comercial con esa presentación; (5)
     * ese CUM pertenece a la familia; (6) la cantidad del RIPS es igual a las
     * unidades dispensadas de ese CUM. Nunca usa importes. Devuelve null si el
     * código no tiene forma de IUM; si falla una condición, cum es null y la
     * evidencia indica cuál.
     *
     * @param  array<string, mixed>  $record
     * @return array{cum: array<string, mixed>|null, evidencia: string}|null
     */
    private function resolveByIumFamily(string $code, array $record, string $route): ?array
    {
        if (preg_match('/^\d[A-Z]\d{13}$/', $code) !== 1 || isset($this->iumIndex[$code])) {
            return null;
        }

        $prefix = substr($code, 0, self::IUM_LONGITUD_FAMILIA);
        $fail = static fn (string $motivo): array => ['cum' => null, 'evidencia' => $motivo.' (IUM '.$code.', familia '.$prefix.')'];
        $family = array_values(array_filter($this->iumFamilyIndex[$prefix] ?? [], fn (array $entry): bool => ! $this->isMedicalSample($entry)));

        if ($family === []) {
            return $fail('FAMILIA_IUM_SIN_REGISTROS_COMERCIALES');
        }

        $principios = array_unique(array_map(fn (array $entry): string => $this->normalisePrincipio((string) ($entry['metadatos']['principio_activo'] ?? '')), $family));
        $strengths = array_unique(array_map(fn (array $entry): string => $this->strengthKey($entry), $family));
        $forms = array_unique(array_map(fn (array $entry): string => $this->normalisePrincipio((string) ($entry['metadatos']['forma_farmaceutica'] ?? '')), $family));

        if (count($principios) !== 1 || count($strengths) !== 1 || count($forms) !== 1) {
            return $fail('FAMILIA_IUM_CON_VARIAS_PRESENTACIONES');
        }

        $principio = $principios[array_key_first($principios)];
        $strength = $strengths[array_key_first($strengths)];
        $form = $forms[array_key_first($forms)];

        if (! $this->samePrincipio($principio, $this->principioCandidates((string) ($record['nombreServicio'] ?? '')))) {
            return $fail('PRINCIPIO_DEL_RIPS_DISTINTO_AL_DE_LA_FAMILIA');
        }

        $candidates = [];
        $others = [];

        foreach ($this->dispensationEvidenceFor($record, $route) as $evidence) {
            $dispensedCum = $this->cumReference($this->normaliseCode((string) $evidence['codigo']));

            if ($dispensedCum === null || $this->isMedicalSample($dispensedCum)
                || ! $this->samePrincipio($this->normalisePrincipio((string) ($dispensedCum['metadatos']['principio_activo'] ?? '')), [$principio])) {
                continue;
            }

            $key = (string) $dispensedCum['codigo'];

            if ($this->strengthKey($dispensedCum) === $strength
                && $this->normalisePrincipio((string) ($dispensedCum['metadatos']['forma_farmaceutica'] ?? '')) === $form) {
                $candidates[$key]['cum'] = $dispensedCum;
                $candidates[$key]['unidades'] = ($candidates[$key]['unidades'] ?? 0.0) + (float) ($evidence['metadatos']['unidades_dispensadas'] ?? 0);
                $candidates[$key]['filas'][] = $evidence['metadatos']['fila'] ?? 's/d';
            } else {
                $others[$key] = $key;
            }
        }

        if ($candidates === []) {
            return $fail('SIN_DISPENSACION_DE_LA_PRESENTACION_DE_LA_FAMILIA');
        }

        if (count($candidates) > 1) {
            return $fail('VARIOS_CUM_DISPENSADOS_CON_LA_PRESENTACION: '.implode(', ', array_keys($candidates)));
        }

        $candidate = $candidates[array_key_first($candidates)];

        if (! str_starts_with($this->normaliseCode((string) ($candidate['cum']['metadatos']['ium'] ?? '')), $prefix)) {
            return $fail('CUM_DISPENSADO_FUERA_DE_LA_FAMILIA: '.$candidate['cum']['codigo']);
        }

        $quantity = $this->numberOrDefault($record['cantidad'] ?? null, 0.0);

        if (abs($quantity - $candidate['unidades']) > 0.0001) {
            return $fail(sprintf('CANTIDAD_NO_COINCIDE: RIPS %s, dispensado %s del CUM %s', $quantity + 0, $candidate['unidades'] + 0, $candidate['cum']['codigo']));
        }

        return [
            'cum' => $candidate['cum'],
            'evidencia' => sprintf(
                'IUM %s no existe en el registro; familia %s con %d registros comerciales de una sola presentación (%s %s %s); dispensación del CUM %s (filas %s) con %s unidades = cantidad RIPS%s',
                $code,
                $prefix,
                count($family),
                $principio,
                str_replace('|', ' ', $strength),
                $form,
                $candidate['cum']['codigo'],
                implode(', ', $candidate['filas']),
                $quantity + 0,
                $others === [] ? '' : '; otras presentaciones del principio en el período, descartadas por concentración o forma: CUM '.implode(', ', $others)
            ),
        ];
    }

    /**
     * Precio del complemento contractual para los CUM identificados. Solo un
     * precio VERIFICADO cuya vigencia cubre la fecha de atención fija tarifa;
     * un precio PENDIENTE se informa como condicionado y nunca suma.
     *
     * @param  list<string>  $codes
     * @return array{entry: array<string, mixed>|null, pendiente: array{tarifa: float, motivo: string}|null, motivo: string}
     */
    private function complementReference(array $codes, string $route, string $date): array
    {
        $rows = [];

        foreach ($codes as $code) {
            foreach ($this->complementByCum[$route][$this->normaliseCode($code)] ?? [] as $entry) {
                $rows[] = $entry;
            }
        }

        $none = ['entry' => null, 'pendiente' => null, 'motivo' => ''];

        if ($rows === []) {
            return $none;
        }

        $covers = static function (array $entry) use ($date): ?bool {
            $from = (string) ($entry['metadatos']['vigencia_desde'] ?? '');
            $to = (string) ($entry['metadatos']['vigencia_hasta'] ?? '');

            if ($from === '' && $to === '') {
                return null;
            }

            return $date !== '' && ($from === '' || $from <= $date) && ($to === '' || $date <= $to);
        };
        $state = static fn (array $entry): string => (string) ($entry['metadatos']['estado_contractual'] ?? '');
        $rates = static fn (array $entries): array => array_values(array_unique(array_map(
            static fn (array $entry): string => number_format((float) $entry['tarifaReferencia'], 4, '.', ''),
            $entries
        )));
        $verified = array_values(array_filter($rows, static fn (array $entry): bool => $state($entry) === EstadoContractual::Verificado->value
            && $entry['tarifaReferencia'] !== null && $covers($entry) === true));

        if (count($rates($verified)) === 1) {
            return [...$none, 'entry' => $verified[0]];
        }

        if ($verified !== []) {
            return [...$none, 'motivo' => 'COMPLEMENTO_VERIFICADO_CON_VARIOS_PRECIOS_VIGENTES'];
        }

        $pending = array_values(array_filter($rows, static fn (array $entry): bool => $state($entry) === EstadoContractual::Pendiente->value
            && $entry['tarifaReferencia'] !== null && $covers($entry) !== false));
        $pendingRates = $rates($pending);

        return match (true) {
            count($pendingRates) === 1 => [...$none, 'pendiente' => ['tarifa' => (float) $pending[0]['tarifaReferencia'], 'motivo' => 'COMPLEMENTO_PENDIENTE_VERIFICACION']],
            count($pendingRates) > 1 => [...$none, 'motivo' => 'COMPLEMENTO_PENDIENTE_CON_VARIOS_PRECIOS: '.implode(' / ', array_map(static fn (string $rate): string => (string) ((float) $rate), $pendingRates))],
            collect($rows)->contains(static fn (array $entry): bool => $state($entry) === EstadoContractual::Verificado->value) => [...$none, 'motivo' => 'COMPLEMENTO_VERIFICADO_FUERA_DE_VIGENCIA'],
            default => $none,
        };
    }

    /**
     * Presentaciones comerciales del mismo principio dispensadas al paciente en el
     * mes que no coinciden con la del IUM; null si no hay dispensación de ese
     * principio o si alguna coincide.
     *
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $iumEntry
     */
    private function dispensationConflict(array $record, string $route, array $iumEntry): ?string
    {
        $principios = [$this->normalisePrincipio((string) ($iumEntry['metadatos']['principio_activo'] ?? ''))];
        $expected = $this->strengthKey($iumEntry);
        $different = [];

        foreach ($this->dispensationEvidenceFor($record, $route) as $evidence) {
            $dispensedCum = $this->cumReference($this->normaliseCode((string) $evidence['codigo']));

            if ($dispensedCum === null || $this->isMedicalSample($dispensedCum)
                || ! $this->samePrincipio($this->normalisePrincipio((string) ($dispensedCum['metadatos']['principio_activo'] ?? '')), $principios)) {
                continue;
            }

            if ($this->strengthKey($dispensedCum) === $expected) {
                return null;
            }

            $different[(string) $dispensedCum['codigo']] = sprintf(
                'CUM %s (fila %s)',
                $dispensedCum['codigo'],
                $evidence['metadatos']['fila'] ?? 's/d'
            );
        }

        return $different === [] ? null : implode(', ', $different);
    }

    /**
     * @param  list<string>  $codes
     * @return array{entry: array<string, mixed>|null, forma: string, candidatos: array<int, array<string, mixed>>}|null
     */
    private function medicationTechnicalReferenceForCodes(array $codes, string $nombreServicio, string $route, string $principioAlterno): ?array
    {
        if (count($codes) <= 1) {
            return $this->medicationTechnicalReference($codes[0] ?? '', $nombreServicio, $route, $principioAlterno);
        }

        $entries = [];

        foreach ($codes as $candidate) {
            foreach ([$route, '*'] as $scope) {
                if (isset($this->medicationByCum[$scope][$candidate])) {
                    $entries[] = $this->medicationByCum[$scope][$candidate];

                    break;
                }
            }
        }

        $rates = array_unique(array_map(static fn (array $entry): string => number_format((float) $entry['tarifaReferencia'], 4, '.', ''), $entries));

        return match (true) {
            $entries === [] => $this->medicationTechnicalReference('', $nombreServicio, $route, $principioAlterno),
            count($rates) === 1 => ['entry' => $entries[0], 'forma' => FormaCruce::CumExacto->value, 'candidatos' => []],
            default => ['entry' => null, 'forma' => FormaCruce::PrincipioAmbiguo->value, 'candidatos' => $entries],
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cumReferencesByIum(string $code): array
    {
        return $this->iumIndex[$code] ?? [];
    }

    /**
     * @param  array<string, mixed>  $record
     * @return list<array<string, mixed>>
     */
    private function dispensationEvidenceFor(array $record, string $route): array
    {
        $document = $this->normaliseCode((string) ($record['numDocumentoIdentificacion'] ?? ''));
        $month = substr((string) ($record['fechaAtencion'] ?? ''), 0, 7);

        return $this->dispensationEvidence[implode('|', [$route, $document, $month])] ?? [];
    }

    /**
     * El principio del registro CUM empieza por uno de los principios del RIPS
     * (el registro puede traer la sal o la equivalencia a continuación).
     *
     * @param  list<string>  $candidatos
     */
    private function samePrincipio(string $principio, array $candidatos): bool
    {
        foreach ($candidatos as $candidato) {
            if (mb_strlen($candidato) >= 5 && $principio !== '' && str_starts_with($principio, $candidato)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function isMedicalSample(array $entry): bool
    {
        return in_array(strtoupper(trim((string) ($entry['metadatos']['muestra_medica'] ?? ''))), ['SI', 'SÍ', 'S'], true);
    }

    /**
     * Presentación del registro CUM: principio, concentración, forma y, si se
     * pide, cantidad por envase.
     *
     * @param  array<string, mixed>  $entry
     */
    private function presentationKey(array $entry, bool $withPackage = true): string
    {
        $metadata = $entry['metadatos'] ?? [];

        return implode('|', array_filter([
            $this->normalisePrincipio((string) ($metadata['principio_activo'] ?? '')),
            number_format((float) ($metadata['cantidad'] ?? 0), 4, '.', ''),
            $this->normalisePrincipio((string) ($metadata['unidad_medida'] ?? '')),
            $this->normalisePrincipio((string) ($metadata['forma_farmaceutica'] ?? '')),
            $withPackage ? number_format((float) ($metadata['cantidad_cum'] ?? 0), 4, '.', '') : '',
        ], static fn (string $part): bool => $part !== ''));
    }

    /**
     * Concentración del registro CUM. Sirve para comparar presentaciones de un
     * principio ya verificado: el registro describe el mismo principio con textos
     * distintos según el titular (p. ej. "PEGFILGRASTIM (FACTOR ESTIMULANTE…)").
     *
     * @param  array<string, mixed>  $entry
     */
    private function strengthKey(array $entry): string
    {
        $metadata = $entry['metadatos'] ?? [];

        return number_format((float) ($metadata['cantidad'] ?? 0), 4, '.', '').'|'
            .$this->normalisePrincipio((string) ($metadata['unidad_medida'] ?? ''));
    }

    /**
     * @param  array<string, mixed>|null  $cum
     */
    private function oncologyScheme(string $route, string $serviceName, ?array $cum): string
    {
        $haystack = $this->normaliseText(implode(' ', array_filter([
            $serviceName,
            $cum['descripcion'] ?? '',
            $cum['metadatos']['principio_activo'] ?? '',
            $cum['metadatos']['producto'] ?? '',
        ])));

        if ($haystack === '') {
            return '';
        }

        foreach ($this->oncologyReferences[$route] ?? [] as $reference) {
            $scheme = (string) ($reference['metadatos']['esquema'] ?? '');

            if ($scheme !== '' && str_contains($this->normaliseText($scheme), $haystack)) {
                return $scheme;
            }

            foreach (preg_split('/[^A-Z0-9]+/', $this->normaliseText($scheme)) ?: [] as $token) {
                if (mb_strlen($token) >= 5 && str_contains($haystack, $token)) {
                    return $scheme;
                }
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>|null  $official
     * @param  array<string, mixed>|null  $technical
     * @param  array<string, mixed>|null  $cum
     */
    private function statusFor(?array $official, ?array $technical, ?array $cum, string $serviceType): string
    {
        if ($technical !== null && $official !== null) {
            return $technical['tarifaReferencia'] === null
                ? 'CRUCE_COMPLETO_SIN_TARIFA'
                : 'CRUCE_COMPLETO';
        }

        if ($technical !== null) {
            return $technical['tarifaReferencia'] === null
                ? 'CRUCE_TECNICO_SIN_TARIFA'
                : 'CRUCE_TECNICO';
        }

        if ($serviceType === 'medicamentos' && $cum !== null) {
            return 'MEDICAMENTO_CUM_IDENTIFICADO';
        }

        if ($official !== null) {
            return 'CODIGO_OFICIAL_SIN_TARIFA';
        }

        return $this->technicalReferences === [] && $this->officialCodes === [] && $this->cumCatalogs === []
            && $this->medicationByCum === []
            ? 'SIN_CATALOGO_ACTIVO'
            : 'CODIGO_SIN_CATALOGO';
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function indexMedicationTechnicalEntry(array $entry): void
    {
        $scope = $entry['ruta'] ?? '*';
        $cum = $this->normaliseCode((string) $entry['codigo']);

        if ($cum !== '') {
            $this->medicationByCum[$scope][$cum] = $entry;
        }

        $principio = $this->normalisePrincipio((string) ($entry['metadatos']['principio_activo'] ?? ''));

        if ($principio === '') {
            $principio = $this->normalisePrincipio((string) $entry['descripcion']);
        }

        if ($principio !== '') {
            $this->medicationByPrincipio[$scope][$principio][] = $entry;
        }
    }

    /**
     * Busca el medicamento en la nota técnica: primero por CUM exacto y luego
     * por principio activo. Por principio solo se acepta una tarifa cuando todas
     * las presentaciones candidatas comparten el mismo valor.
     *
     * @return array{entry: array<string, mixed>|null, forma: string, candidatos: array<int, array<string, mixed>>}|null
     */
    private function medicationTechnicalReference(
        string $cum,
        string $nombreServicio,
        string $route,
        string $principioAlterno = ''
    ): ?array {
        foreach ([$route, '*'] as $scope) {
            if ($cum !== '' && isset($this->medicationByCum[$scope][$cum])) {
                return [
                    'entry' => $this->medicationByCum[$scope][$cum],
                    'forma' => FormaCruce::CumExacto->value,
                    'candidatos' => [],
                ];
            }
        }

        $candidates = $this->medicationCandidatesByPrincipio(
            array_values(array_filter([$nombreServicio, $principioAlterno])),
            $route
        );

        if ($candidates === []) {
            return null;
        }

        $rates = array_unique(array_map(
            static fn (array $entry): string => number_format((float) $entry['tarifaReferencia'], 4, '.', ''),
            array_filter($candidates, static fn (array $entry): bool => $entry['tarifaReferencia'] !== null)
        ));

        if (count($rates) > 1) {
            return [
                'entry' => null,
                'forma' => FormaCruce::PrincipioAmbiguo->value,
                'candidatos' => $candidates,
            ];
        }

        return [
            'entry' => $candidates[0],
            'forma' => FormaCruce::PrincipioTarifaUnica->value,
            'candidatos' => $candidates,
        ];
    }

    /**
     * @param  array<int, string>  $candidateSources
     * @return array<int, array<string, mixed>>
     */
    private function medicationCandidatesByPrincipio(array $candidateSources, string $route): array
    {
        foreach ($candidateSources as $source) {
            foreach ($this->principioCandidates($source) as $principio) {
                foreach ([$route, '*'] as $scope) {
                    if (isset($this->medicationByPrincipio[$scope][$principio])) {
                        return $this->medicationByPrincipio[$scope][$principio];
                    }
                }
            }
        }

        foreach ($candidateSources as $source) {
            $haystack = $this->normalisePrincipio($source);

            if ($haystack === '') {
                continue;
            }

            foreach ([$route, '*'] as $scope) {
                foreach ($this->medicationByPrincipio[$scope] ?? [] as $principio => $entries) {
                    if (mb_strlen((string) $principio) >= 5 && str_contains($haystack, (string) $principio)) {
                        return $entries;
                    }
                }
            }
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function principioCandidates(string $nombreServicio): array
    {
        $text = $this->normaliseText($nombreServicio);

        if ($text === '') {
            return [];
        }

        $aliases = [
            'PARACETAMOL' => 'ACETAMINOFEN',
            'ACETAMINOFEN' => 'PARACETAMOL',
        ];

        $candidates = [];
        $full = $this->normalisePrincipio($nombreServicio);

        if ($full !== '') {
            $candidates[] = $full;

            if (isset($aliases[$full])) {
                $candidates[] = $aliases[$full];
            }
        }

        foreach (preg_split('/\s+/', $text) ?: [] as $token) {
            if (mb_strlen($token) < 4) {
                continue;
            }

            $candidates[] = $token;

            if (isset($aliases[$token])) {
                $candidates[] = $aliases[$token];
            }
        }

        return array_values(array_unique($candidates));
    }

    private function normalisePrincipio(string $value): string
    {
        return (string) Str::of($value)
            ->ascii()
            ->upper()
            ->replaceMatches('/[^A-Z0-9]+/', '')
            ->trim();
    }

    /**
     * @param  array<string, mixed>  $reference
     */
    private function registerUsage(array $reference): void
    {
        $catalogo = $reference['catalogo'];

        if (! isset($this->usage[$catalogo->id])) {
            $this->usage[$catalogo->id] = [
                'catalogo' => $catalogo,
                'coincidencias' => 0,
            ];
        }

        $this->usage[$catalogo->id]['coincidencias']++;
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(CatalogoReferencia $catalogo, CatalogoReferenciaItem $item): array
    {
        $rate = $item->tarifa_referencia;
        $sourceRate = $item->metadatos['tarifa_fuente_precision'] ?? null;

        if (in_array($catalogo->tipo, [
            CatalogoReferenciaImporter::TYPE_NOTA_PROSTATA,
            CatalogoReferenciaImporter::TYPE_NOTA_CERVIX,
            CatalogoReferenciaImporter::TYPE_NOTA_MEDICAMENTOS,
        ], true) && is_numeric($sourceRate) && (float) $sourceRate >= 0) {
            $rate = (float) $sourceRate;
        }

        return [
            'catalogo' => $catalogo,
            'catalogoTipo' => $catalogo->tipo,
            'catalogoLabel' => $this->catalogLabel($catalogo->tipo),
            'version' => $catalogo->version,
            'archivoOrigen' => $catalogo->archivo_origen,
            'codigo' => $item->codigo,
            'ruta' => $item->ruta,
            'categoria' => $item->categoria,
            'descripcion' => $item->descripcion ?? '',
            'tarifaReferencia' => $rate,
            'metadatos' => $item->metadatos ?? [],
        ];
    }

    /**
     * @param  array<string, mixed>  $reference
     */
    private function technicalPriority(array $reference): int
    {
        return match ($reference['categoria']) {
            'ONCOLOGIA_QUIMIOTERAPIA', 'ONCOLOGIA_RADIOTERAPIA' => 2,
            default => 1,
        };
    }

    private function technicalLabel(string $category): string
    {
        return match ($category) {
            'SERVICIO_TECNICO' => 'Servicio y tecnología',
            'ONCOLOGIA_QUIMIOTERAPIA' => 'Oncología: quimioterapia',
            'ONCOLOGIA_RADIOTERAPIA' => 'Oncología: radioterapia',
            'PALIATIVOS' => 'Medicamentos paliativos (referencia)',
            'MEDICAMENTO_NT' => 'Medicamento nota técnica',
            'MEDICAMENTO_NT_PALIATIVO' => 'Medicamento paliativo nota técnica',
            'RESUMEN_TECNICO' => 'Resumen técnico mensual',
            'HX_HOSPITALIZACION' => 'Referencia histórica de hospitalización',
            'TRANSPORTE_ALBERGUE' => 'Transporte y albergue (referencia)',
            'POBLACION_OBJETIVO' => 'Población objetivo',
            default => $category,
        };
    }

    /**
     * @param  array<string, mixed>  $reference
     */
    private function technicalDisplayGroup(array $reference): string
    {
        $group = (string) ($reference['metadatos']['agrupador'] ?? '');

        return $group !== '' ? $group : $this->technicalLabel((string) $reference['categoria']);
    }

    /**
     * @param  array<string, mixed>  $reference
     */
    private function referenceDetail(array $reference): string
    {
        $metadata = $reference['metadatos'];

        if (($metadata['esquema'] ?? '') !== '') {
            return 'Esquema: '.$metadata['esquema'];
        }

        if (($metadata['principio_activo'] ?? '') !== '') {
            return 'Principio activo: '.$metadata['principio_activo'];
        }

        if (($metadata['valores_referencia'] ?? []) !== []) {
            return 'Valores fuente: '.implode(', ', $metadata['valores_referencia']);
        }

        if (($metadata['costo_anual'] ?? null) !== null) {
            return 'Costo anual referencia: '.$metadata['costo_anual'];
        }

        return '';
    }

    private function catalogLabel(string $type): string
    {
        return CatalogoReferenciaImporter::types()[$type] ?? $type;
    }

    private function normaliseCode(string $code): string
    {
        return (string) Str::of($code)
            ->ascii()
            ->upper()
            ->replace(['.', ' ', '/'], '')
            ->trim();
    }

    private function normaliseText(string $value): string
    {
        return (string) Str::of($value)
            ->ascii()
            ->upper()
            ->replaceMatches('/[^A-Z0-9]+/', ' ')
            ->trim();
    }

    private function numberOrDefault(mixed $value, float $default): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        return is_string($value) && is_numeric($value) ? (float) $value : $default;
    }
}
