<?php

namespace App\Services;

use App\Models\CatalogoReferencia;
use App\Models\CatalogoReferenciaItem;
use App\Models\ReferenciaManual;
use App\Programas\LaMaria\LaMariaPrograma;
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
     * @var array<string, array<string, array<string, mixed>>>
     */
    private array $medicationByPrincipio = [];

    /** @var array<string, array<string, mixed>> */
    private array $manualReferences = [];

    /** @var array<string, array<string, mixed>> */
    private array $dispensationReferences = [];

    /**
     * @var array<int, array{catalogo: CatalogoReferencia, coincidencias: int}>
     */
    private array $usage = [];

    /**
     * @param  Collection<int, CatalogoReferencia>|null  $catalogos
     */
    public function __construct(?Collection $catalogos = null, ?string $rutaEjecucion = null)
    {
        if ($catalogos === null) {
            // Solo catálogos del programa La María; opcionalmente acotados a la ejecución.
            $catalogos = CatalogoReferencia::query()
                ->forPrograma(LaMariaPrograma::SLUG)
                ->active()
                ->where('tipo', '!=', CatalogoReferenciaImporter::TYPE_MEDICAMENTOS_CUM)
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

            foreach ($catalogo->items as $item) {
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
        $cum = $serviceType === 'medicamentos' ? $this->cumReference($code) : null;
        $dispensation = $serviceType === 'medicamentos'
            ? $this->dispensationReference($record, $route, $cum)
            : null;
        $medicationNt = $serviceType === 'medicamentos'
            ? $this->medicationTechnicalReference(
                $code,
                (string) ($record['nombreServicio'] ?? ''),
                $route,
                (string) ($cum['metadatos']['principio_activo'] ?? $cum['descripcion'] ?? '')
            )
            : null;
        $manual = $this->manualReference($route, $serviceType, $code);
        $oncologyScheme = $serviceType === 'medicamentos'
            ? $this->oncologyScheme($route, (string) ($record['nombreServicio'] ?? ''), $cum)
            : '';

        foreach ([$official, $technical, $cum, $medicationNt, $manual, $dispensation] as $reference) {
            if ($reference !== null) {
                $this->registerUsage($reference);
            }
        }

        $notaTecnica = $serviceType === 'medicamentos' ? $medicationNt : $technical;
        $nombreNotaTecnica = (string) ($notaTecnica['descripcion'] ?? '');

        if ($nombreNotaTecnica === '' && $serviceType === 'medicamentos') {
            $nombreNotaTecnica = (string) ($record['nombreServicio'] ?? '');

            if ($nombreNotaTecnica === '') {
                $nombreNotaTecnica = (string) (
                    $cum['metadatos']['principio_activo']
                    ?? $cum['descripcion']
                    ?? ''
                );
            }
        }

        $description = $official['descripcion'] ?? $technical['descripcion'] ?? $cum['descripcion'] ?? '';
        $catalogNames = array_values(array_unique(array_filter([
            $official['catalogoLabel'] ?? '',
            $technical['catalogoLabel'] ?? '',
            $medicationNt['catalogoLabel'] ?? '',
            $cum['catalogoLabel'] ?? '',
            $dispensation['catalogoLabel'] ?? '',
        ])));
        $rate = $notaTecnica['tarifaReferencia']
            ?? $technical['tarifaReferencia']
            ?? $manual['tarifaReferencia']
            ?? $dispensation['tarifaReferencia']
            ?? null;
        $quantity = $this->numberOrDefault($record['cantidad'] ?? null, 1.0);
        $estimatedCost = $rate === null ? 0.0 : round($rate * $quantity, 2);
        $status = $dispensation !== null && $notaTecnica === null && $technical === null && $manual === null
            ? 'MEDICAMENTO_CON_DISPENSACION'
            : $this->statusFor($official, $notaTecnica ?? $technical ?? $manual, $cum, $serviceType);
        $referenceCategory = (string) ($notaTecnica['categoria'] ?? $technical['categoria'] ?? '');
        $referenceValueType = match (true) {
            $dispensation !== null && $rate === $dispensation['tarifaReferencia'] => 'TARIFA_DISPENSACION_ADMINISTRATIVA',
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
            'estadoCruce' => $status,
            'cum' => $code !== '' && $serviceType === 'medicamentos'
                ? $code
                : ($cum === null ? '' : (string) $cum['codigo']),
            'principioActivo' => $medicationNt['metadatos']['principio_activo']
                ?? $cum['metadatos']['principio_activo']
                ?? '',
            'atc' => $cum['metadatos']['atc'] ?? '',
            'esquemaOncologico' => $oncologyScheme,
            'tipoValorReferencia' => $referenceValueType,
        ];
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

        if ($document === '' || $date === '' || $route === '' || $code === '' || $rate <= 0) {
            return;
        }

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

        if ($catalogo === null) {
            return null;
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

        if ($principio !== '' && ! isset($this->medicationByPrincipio[$scope][$principio])) {
            $this->medicationByPrincipio[$scope][$principio] = $entry;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function medicationTechnicalReference(
        string $cum,
        string $nombreServicio,
        string $route,
        string $principioAlterno = ''
    ): ?array {
        foreach ([$route, '*'] as $scope) {
            if ($cum !== '' && isset($this->medicationByCum[$scope][$cum])) {
                return $this->medicationByCum[$scope][$cum];
            }
        }

        $candidateSources = array_values(array_filter([$nombreServicio, $principioAlterno]));

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
                foreach ($this->medicationByPrincipio[$scope] ?? [] as $principio => $entry) {
                    if (mb_strlen((string) $principio) >= 5 && str_contains($haystack, (string) $principio)) {
                        return $entry;
                    }
                }
            }
        }

        return null;
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
            'tarifaReferencia' => $item->tarifa_referencia,
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
