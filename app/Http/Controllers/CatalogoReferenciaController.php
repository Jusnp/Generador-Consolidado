<?php

namespace App\Http\Controllers;

use App\Models\CatalogoConsolidadoImport;
use App\Models\CatalogoReferencia;
use App\Models\CatalogoReferenciaItem;
use App\Models\CodigoCups;
use App\Models\CodigoInsumoNt;
use App\Models\CodigoMedicamento;
use App\Models\CodigoMedicamentoNt;
use App\Models\Contrato;
use App\Models\ReferenciaManual;
use App\Programas\LaMaria\LaMariaPrograma;
use App\Programas\ProgramaRegistry;
use App\Services\ActivityLogger;
use App\Services\CatalogoConsolidadoImporter;
use App\Services\CatalogoReferenciaImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CatalogoReferenciaController extends Controller
{
    public function manualIndex(Request $request, ProgramaRegistry $programas): Response|JsonResponse
    {
        $programa = $request->string('programa')->toString();
        $ruta = $request->string('ruta')->toString();
        abort_unless($programa === '' || $programas->exists($programa), 404);
        if ($programa !== LaMariaPrograma::SLUG) {
            $ruta = '';
        }

        $items = ReferenciaManual::query()
            ->when($programa !== '', fn ($query) => $query->forProgram($programa))
            ->when($ruta !== '', fn ($query) => $query->where('ruta', $ruta))
            ->latest()
            ->get();

        if (! $request->expectsJson()) {
            return redirect()->route('catalogos-referencia.index');
        }

        return response()->json(['programas' => $programas->all(), 'referencias' => $items]);
    }

    public function storeManual(Request $request): JsonResponse
    {
        $data = $request->validate([
            'programa_slug' => ['required', 'string', 'max:80'],
            'ruta' => ['nullable', 'string', 'max:40'],
            'tipo' => ['required', 'string', 'max:40'],
            'codigo' => ['nullable', 'string', 'max:120'],
            'nombre' => ['required', 'string', 'max:255'],
            'tarifa' => ['required', 'numeric', 'min:0'],
        ]);
        $data['nombre_normalizado'] = Str::upper(Str::ascii(trim($data['nombre'])));
        $data['created_by'] = $request->user()->id;
        $data['updated_by'] = $request->user()->id;
        $existing = ReferenciaManual::query()
            ->where('programa_slug', $data['programa_slug'])
            ->where('ruta', $data['ruta'] ?? null)
            ->where(function ($query) use ($data) {
                if (($data['codigo'] ?? '') !== '') {
                    $query->where('codigo', $data['codigo']);
                }
                $query->orWhere('nombre_normalizado', $data['nombre_normalizado']);
            })->first();
        if ($existing) {
            return response()->json(['message' => 'El registro ya existe. Puedes actualizarlo.', 'referencia' => $existing], 409);
        }
        $item = ReferenciaManual::create($data);

        return response()->json(['message' => 'Cargado correctamente.', 'referencia' => $item], 201);
    }

    public function updateManual(Request $request, ReferenciaManual $referenciaManual): JsonResponse
    {
        $data = $request->validate([
            'programa_slug' => ['required', 'string', 'max:80'],
            'ruta' => ['nullable', 'string', 'max:40'], 'tipo' => ['required', 'string', 'max:40'],
            'codigo' => ['nullable', 'string', 'max:120'], 'nombre' => ['required', 'string', 'max:255'],
            'tarifa' => ['required', 'numeric', 'min:0'], 'activo' => ['nullable', 'boolean'],
        ]);
        $data['nombre_normalizado'] = Str::upper(Str::ascii(trim($data['nombre'])));
        $data['updated_by'] = $request->user()->id;
        $data['activo'] ??= $referenciaManual->activo;
        $duplicate = ReferenciaManual::query()
            ->where('id', '!=', $referenciaManual->id)
            ->where('programa_slug', $data['programa_slug'])
            ->where('ruta', $data['ruta'] ?? null)
            ->where('nombre_normalizado', $data['nombre_normalizado'])
            ->first();
        if ($duplicate) {
            return response()->json([
                'message' => 'Ya existe otra referencia con ese programa, ruta y nombre.',
                'referencia' => $duplicate,
            ], 409);
        }
        $referenciaManual->update($data);

        return response()->json(['message' => 'Registro actualizado.', 'referencia' => $referenciaManual->fresh()]);
    }

    public function toggleManual(Request $request, ReferenciaManual $referenciaManual): JsonResponse
    {
        $referenciaManual->update(['activo' => ! $referenciaManual->activo, 'updated_by' => $request->user()->id]);

        return response()->json(['message' => $referenciaManual->activo ? 'Registro activado.' : 'Registro inactivado.', 'referencia' => $referenciaManual]);
    }

    public function index(ProgramaRegistry $programas): View
    {
        return view('catalogos-referencia', [
            'catalogOwnership' => $programas->catalogOwnershipMap(),
            'catalogTypes' => CatalogoReferenciaImporter::types(),
            'catalogosPorTipo' => CatalogoReferencia::query()
                ->with(['importedBy', 'contrato'])
                ->withCount('items')
                ->latest('created_at')
                ->get()
                ->groupBy('tipo'),
            'contratosVigentes' => Contrato::query()
                ->forPrograma(LaMariaPrograma::SLUG)
                ->activo()
                ->vigenteEn()
                ->orderBy('nombre')
                ->get(),
            'consolidatedCatalogTypes' => CatalogoConsolidadoImporter::types(),
            'consolidatedCatalogsByType' => CatalogoConsolidadoImport::query()
                ->with('importedBy')
                ->latest('created_at')
                ->get()
                ->groupBy('tipo'),
            'consolidatedCatalogBaseRecords' => [
                CatalogoConsolidadoImporter::TYPE_CUPS => CodigoCups::query()->count(),
                CatalogoConsolidadoImporter::TYPE_MEDICAMENTOS => CodigoMedicamento::query()->count()
                    + CodigoMedicamentoNt::query()->count(),
                CatalogoConsolidadoImporter::TYPE_INSUMOS => CodigoInsumoNt::query()->count(),
            ],
            'consolidatedCatalogActiveRecords' => [
                CatalogoConsolidadoImporter::TYPE_CUPS => CodigoCups::query()->where('activo', true)->count(),
                CatalogoConsolidadoImporter::TYPE_MEDICAMENTOS => CodigoMedicamento::query()->where('activo', true)->count() + CodigoMedicamentoNt::query()->where('activo', true)->count(),
                CatalogoConsolidadoImporter::TYPE_INSUMOS => CodigoInsumoNt::query()->where('activo', true)->count(),
            ],
        ]);
    }

    public function show(Request $request, CatalogoReferencia $catalogoReferencia): View
    {
        $sheet = $request->string('sheet')->toString();
        $metadataColumns = $catalogoReferencia->items()
            ->when($sheet !== '', fn ($query) => $query->where('hoja', $sheet))
            ->pluck('metadatos')
            ->filter()
            ->flatMap(fn ($metadata) => array_keys(is_array($metadata) ? Arr::dot($metadata) : []))
            ->unique()
            ->values();

        return view('catalogo-referencia-detalle', [
            'catalogo' => $catalogoReferencia,
            'sheets' => $catalogoReferencia->items()->whereNotNull('hoja')->distinct()->orderBy('hoja')->pluck('hoja'),
            'metadataColumns' => $metadataColumns,
            'items' => $catalogoReferencia->items()
                ->when($request->filled('q'), function ($query) use ($request) {
                    $term = '%'.$request->string('q')->toString().'%';
                    $query->where(fn ($query) => $query->where('codigo', 'like', $term)->orWhere('descripcion', 'like', $term));
                })->when($request->filled('sheet'), fn ($query) => $query->where('hoja', $request->string('sheet')->toString()))->orderBy('id')->paginate(100)->withQueryString(),
        ]);
    }

    public function updateItem(Request $request, CatalogoReferencia $catalogoReferencia, int $item, ActivityLogger $logger): JsonResponse
    {
        $data = $request->validate([
            'codigo' => ['nullable', 'string', 'max:120'],
            'ruta' => ['nullable', 'string', 'max:40'],
            'categoria' => ['nullable', 'string', 'max:120'],
            'metadata' => ['nullable', 'array'],
            'metadata.*' => ['nullable', 'string', 'max:10000'],
            'descripcion' => ['nullable', 'string', 'max:10000'],
            'tarifa_referencia' => ['nullable', 'numeric', 'min:0', 'max:999999999999999.9999'],
            'activo' => ['nullable', 'boolean'],
            'revision' => ['required', 'string'],
        ]);

        return DB::transaction(function () use ($catalogoReferencia, $item, $data, $logger, $request): JsonResponse {
            $catalog = CatalogoReferencia::query()->lockForUpdate()->findOrFail($catalogoReferencia->id);
            abort_unless($catalog->activo, 409, 'Esta versión está inactiva. Abre la versión activa.');
            $record = $catalog->items()->lockForUpdate()->findOrFail($item);
            $before = $record->getAttributes();
            abort_unless(hash('sha256', json_encode($before)) === $data['revision'], 409, 'Otro usuario cambió esta fila. Recarga antes de editarla.');
            $updates = collect($data)->except('revision')->all();
            if (array_key_exists('metadata', $updates)) {
                $metadata = $record->metadatos ?? [];
                foreach ($updates['metadata'] as $key => $value) {
                    Arr::set($metadata, $key, $value);
                }
                $updates['metadatos'] = $metadata;
                unset($updates['metadata']);
            }
            $record->update($updates);
            $logger->log('nota_tecnica_editada', 'Edición directa de una fila de la nota técnica.', [
                'catalogo_id' => $catalog->id, 'item_id' => $record->id,
                'antes' => $before, 'despues' => $record->getAttributes(),
            ], $request);

            return response()->json(['message' => 'Fila guardada en la nota técnica.', 'revision' => hash('sha256', json_encode($record->fresh()->getAttributes()))]);
        });
    }

    public function storeItem(Request $request, CatalogoReferencia $catalogoReferencia, ActivityLogger $logger): JsonResponse
    {
        abort_unless($catalogoReferencia->activo, 409, 'Esta versión está inactiva. Abre la versión activa.');
        $data = $request->validate([
            'codigo' => ['required', 'string', 'max:100'], 'ruta' => ['nullable', 'string', 'max:20'],
            'categoria' => ['nullable', 'string', 'max:100'], 'descripcion' => ['nullable', 'string', 'max:10000'],
            'tarifa_referencia' => ['nullable', 'numeric', 'min:0', 'max:999999999999999.9999'],
        ]);
        $data['activo'] = true;
        $item = $catalogoReferencia->items()->create($data);
        $logger->log('nota_tecnica_item_agregado', 'Nueva fila agregada a la nota técnica.', ['catalogo_id' => $catalogoReferencia->id, 'item_id' => $item->id], $request);

        return response()->json(['message' => 'Fila agregada correctamente.', 'item' => $item], 201);
    }

    public function toggleItem(Request $request, CatalogoReferencia $catalogoReferencia, CatalogoReferenciaItem $item, ActivityLogger $logger): JsonResponse
    {
        abort_unless($item->catalogo_referencia_id === $catalogoReferencia->id, 404);
        $item->update(['activo' => ! $item->activo]);
        $logger->log('nota_tecnica_item_estado', 'Estado de una fila de la nota técnica actualizado.', ['catalogo_id' => $catalogoReferencia->id, 'item_id' => $item->id, 'activo' => $item->activo], $request);

        return response()->json(['message' => $item->activo ? 'Fila activada.' : 'Fila inactivada.', 'activo' => $item->activo]);
    }

    public function download(CatalogoReferencia $catalogoReferencia): BinaryFileResponse
    {
        abort_unless($catalogoReferencia->programa_slug === LaMariaPrograma::SLUG, 404);
        $catalogoReferencia->load(['items' => fn ($query) => $query->orderBy('id')]);
        $path = storage_path('app/catalogo-'.$catalogoReferencia->id.'.xlsx');
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['Código', 'Ruta', 'Categoría', 'Descripción', 'Tarifa']));
        foreach ($catalogoReferencia->items as $item) {
            $writer->addRow(Row::fromValues([
                $item->codigo,
                $item->ruta,
                $item->categoria,
                $item->descripcion,
                $item->tarifa_referencia,
            ]));
        }
        $writer->close();

        return response()->download($path, 'nota-tecnica-'.$catalogoReferencia->version.'.xlsx')->deleteFileAfterSend(true);
    }

    public function showConsolidated(Request $request, string $type): View
    {
        abort_unless(array_key_exists($type, CatalogoConsolidadoImporter::types()), 404);
        $sheet = $request->string('sheet')->toString();
        $filterSheet = fn ($query) => $sheet === '' ? $query : $query->where('hoja', $sheet);
        $records = match ($type) {
            CatalogoConsolidadoImporter::TYPE_CUPS => $filterSheet(CodigoCups::query())->orderBy('id')->get()->map(fn ($row) => [
                'id' => $row->id, 'source' => 'cups', 'codigo' => $row->codigo, 'nombre' => $row->descripcion, 'activo' => $row->activo,
                'detalle' => $row->tipo_servicio, 'tarifa' => $row->tarifa_2025, 'hoja' => $row->hoja,
            ]),
            CatalogoConsolidadoImporter::TYPE_INSUMOS => $filterSheet(CodigoInsumoNt::query())->orderBy('id')->get()->map(fn ($row) => [
                'id' => $row->id, 'source' => 'insumos', 'codigo' => $row->codigo, 'nombre' => $row->descripcion, 'activo' => $row->activo,
                'detalle' => $row->nt, 'tarifa' => $row->tarifa_unitario, 'hoja' => $row->hoja,
            ]),
            default => $filterSheet(CodigoMedicamento::query())->orderBy('id')->get()->map(fn ($row) => [
                'id' => $row->id, 'source' => 'medicamentos', 'codigo' => $row->codigo, 'nombre' => $row->llave, 'activo' => $row->activo,
                'detalle' => $row->cums_homologo, 'tarifa' => $row->tarifa_unitario, 'hoja' => $row->hoja,
            ])->concat($filterSheet(CodigoMedicamentoNt::query())->orderBy('id')->get()->map(fn ($row) => [
                'id' => $row->id, 'source' => 'medicamentos_nt', 'codigo' => $row->cums, 'nombre' => $row->nombre_estandar, 'activo' => $row->activo,
                'detalle' => $row->pertenece_nt, 'tarifa' => $row->tarifa_nt, 'hoja' => $row->hoja,
            ])),
        };

        $sheets = collect(['cups' => CodigoCups::class, 'insumos' => CodigoInsumoNt::class, 'medicamentos' => CodigoMedicamento::class, 'medicamentos_nt' => CodigoMedicamentoNt::class])
            ->map(fn (string $model) => $model::query()->whereNotNull('hoja')->distinct()->pluck('hoja'))
            ->flatten()->filter()->unique()->sort()->values();

        return view('catalogo-consolidado-detalle', [
            'type' => $type, 'label' => CatalogoConsolidadoImporter::types()[$type], 'records' => $records, 'sheets' => $sheets,
        ]);
    }

    public function updateConsolidated(Request $request, string $source, int $id, ActivityLogger $logger): JsonResponse
    {
        $data = $request->validate(['codigo' => ['nullable', 'string', 'max:255'], 'nombre' => ['nullable', 'string', 'max:10000'], 'detalle' => ['nullable', 'string', 'max:10000'], 'tarifa' => ['nullable', 'numeric', 'min:0', 'max:999999999999999.9999']]);
        $model = match ($source) {
            'cups' => CodigoCups::class, 'medicamentos' => CodigoMedicamento::class,
            'medicamentos_nt' => CodigoMedicamentoNt::class, 'insumos' => CodigoInsumoNt::class,
            default => null,
        };
        abort_unless($model !== null, 404);
        $record = $model::query()->findOrFail($id);
        $before = $record->getAttributes();
        $attributes = match ($source) {
            'cups' => ['codigo' => $data['codigo'] ?? $record->codigo, 'descripcion' => $data['nombre'] ?? null, 'tipo_servicio' => $data['detalle'] ?? $record->tipo_servicio, 'tarifa_2025' => $data['tarifa'] ?? null],
            'insumos' => ['codigo' => $data['codigo'] ?? $record->codigo, 'descripcion' => $data['nombre'] ?? null, 'nt' => $data['detalle'] ?? $record->nt, 'tarifa_unitario' => $data['tarifa'] ?? null],
            'medicamentos' => ['codigo' => $data['codigo'] ?? $record->codigo, 'llave' => $data['nombre'] ?? null, 'cums_homologo' => $data['detalle'] ?? $record->cums_homologo, 'tarifa_unitario' => $data['tarifa'] ?? null],
            default => ['cums' => $data['codigo'] ?? $record->cums, 'nombre_estandar' => $data['nombre'] ?? null, 'pertenece_nt' => $data['detalle'] ?? $record->pertenece_nt, 'tarifa_nt' => $data['tarifa'] ?? null],
        };
        $record->update($attributes);
        $logger->log('tarifario_autoinmunes_editado', 'Edición directa de un tarifario contractual.', [
            'source' => $source, 'id' => $id, 'antes' => $before, 'despues' => $record->fresh()->getAttributes(),
        ], $request);

        return response()->json(['message' => 'Tarifario actualizado correctamente.']);
    }

    public function toggleConsolidated(string $source, int $id, ActivityLogger $logger): JsonResponse
    {
        $model = match ($source) {
            'cups' => CodigoCups::class, 'medicamentos' => CodigoMedicamento::class,
            'medicamentos_nt' => CodigoMedicamentoNt::class, 'insumos' => CodigoInsumoNt::class,
            default => null,
        };
        abort_unless($model !== null, 404);
        $record = $model::query()->findOrFail($id);
        $record->update(['activo' => ! $record->activo]);
        $logger->log('tarifario_autoinmunes_estado', 'Estado de un registro tarifario actualizado.', ['source' => $source, 'id' => $id, 'activo' => $record->activo], request());

        return response()->json(['message' => $record->activo ? 'Registro activado.' : 'Registro inactivado.', 'activo' => $record->activo]);
    }

    public function toggleConsolidatedFile(string $type, ActivityLogger $logger): RedirectResponse
    {
        $models = match ($type) {
            CatalogoConsolidadoImporter::TYPE_CUPS => [CodigoCups::class],
            CatalogoConsolidadoImporter::TYPE_MEDICAMENTOS => [CodigoMedicamento::class, CodigoMedicamentoNt::class],
            CatalogoConsolidadoImporter::TYPE_INSUMOS => [CodigoInsumoNt::class],
            default => null,
        };
        abort_unless($models !== null, 404);
        $currentlyActive = collect($models)->contains(fn ($model) => $model::query()->where('activo', true)->exists());
        foreach ($models as $model) {
            $model::query()->update(['activo' => ! $currentlyActive]);
        }
        $logger->log('tarifario_autoinmunes_archivo_estado', 'Estado del archivo tarifario actualizado.', ['tipo' => $type, 'activo' => ! $currentlyActive]);

        return back()->with('success', $currentlyActive ? 'Archivo inactivado.' : 'Archivo activado.');
    }

    public function storeConsolidatedRow(Request $request, ActivityLogger $logger): RedirectResponse
    {
        $data = $request->validate(['source' => ['required', Rule::in(['cups', 'medicamentos', 'medicamentos_nt', 'insumos'])], 'codigo' => ['required', 'string', 'max:255'], 'nombre' => ['nullable', 'string', 'max:10000'], 'detalle' => ['nullable', 'string', 'max:10000'], 'tarifa' => ['nullable', 'numeric', 'min:0']]);
        $record = match ($data['source']) {
            'cups' => CodigoCups::create(['codigo' => $data['codigo'], 'descripcion' => $data['nombre'] ?? null, 'tipo_servicio' => $data['detalle'] ?? null, 'tarifa_2025' => $data['tarifa'] ?? null, 'activo' => true]),
            'insumos' => CodigoInsumoNt::create(['codigo' => $data['codigo'], 'descripcion' => $data['nombre'] ?? null, 'nt' => $data['detalle'] ?? null, 'tarifa_unitario' => $data['tarifa'] ?? null, 'activo' => true]),
            'medicamentos' => CodigoMedicamento::create(['codigo' => $data['codigo'], 'llave' => $data['nombre'] ?? null, 'cums_homologo' => $data['detalle'] ?? null, 'tarifa_unitario' => $data['tarifa'] ?? null, 'activo' => true]),
            default => CodigoMedicamentoNt::create(['cums' => $data['codigo'], 'nombre_estandar' => $data['nombre'] ?? null, 'pertenece_nt' => $data['detalle'] ?? null, 'tarifa_nt' => $data['tarifa'] ?? null, 'activo' => true]),
        };
        $logger->log('tarifario_autoinmunes_agregado', 'Nuevo registro agregado al tarifario.', ['source' => $data['source'], 'id' => $record->id]);

        return back()->with('success', 'Registro agregado correctamente.');
    }

    public function toggleTechnicalFile(CatalogoReferencia $catalogoReferencia, ActivityLogger $logger): RedirectResponse
    {
        $catalogoReferencia->update(['activo' => ! $catalogoReferencia->activo]);
        $logger->log('catalogo_tecnico_archivo_estado', 'Estado de la nota técnica actualizado.', ['catalogo_id' => $catalogoReferencia->id, 'activo' => $catalogoReferencia->activo]);

        return back()->with('success', $catalogoReferencia->activo ? 'Archivo activado.' : 'Archivo inactivado.');
    }

    public function store(
        Request $request,
        CatalogoReferenciaImporter $catalogoReferenciaImporter,
        ActivityLogger $activityLogger
    ): RedirectResponse|JsonResponse {
        $validated = $request->validate([
            'tipo' => ['required', Rule::in(array_keys(CatalogoReferenciaImporter::types()))],
            'version' => ['required', 'string', 'max:100'],
            'contrato_id' => [
                'nullable',
                'integer',
                Rule::exists('contratos', 'id')->where(
                    fn ($query) => $query
                        ->where('programa_slug', LaMariaPrograma::SLUG)
                        ->where('activo', true)
                ),
            ],
            'archivo' => ['required', 'file', 'mimes:xlsx', 'extensions:xlsx', 'max:512000'],
        ], [
            'tipo.required' => 'Selecciona el tipo de catálogo que vas a cargar.',
            'tipo.in' => 'El tipo de catálogo seleccionado no es válido.',
            'version.required' => 'Indica la versión o fecha de referencia del catálogo.',
            'contrato_id.exists' => 'El contrato seleccionado no es válido para este programa.',
            'archivo.required' => 'Selecciona el archivo Excel de referencia.',
            'archivo.mimes' => 'El archivo de referencia debe estar en formato Excel (.xlsx).',
            'archivo.extensions' => 'El archivo de referencia debe tener extensión .xlsx.',
            'archivo.max' => 'El archivo de referencia puede pesar hasta 500 MB.',
        ]);

        try {
            $result = $catalogoReferenciaImporter->import(
                $validated['archivo'],
                $validated['tipo'],
                $validated['version'],
                $request->user(),
                LaMariaPrograma::SLUG,
                $validated['contrato_id'] ?? null
            );
        } catch (InvalidArgumentException|RuntimeException $exception) {
            throw ValidationException::withMessages([
                'archivo' => $exception->getMessage(),
            ]);
        }

        $activityLogger->log(
            'catalogo_referencia_importado',
            'Administrador actualizó un catálogo de referencia para La María.',
            [
                'programa' => LaMariaPrograma::SLUG,
                'contrato_id' => $result['catalogo']->contrato_id,
                'tipo' => $result['catalogo']->tipo,
                'version' => $result['catalogo']->version,
                'archivo_origen' => $result['catalogo']->archivo_origen,
                'items_importados' => $result['items'],
            ]
        );

        $message = "Catálogo actualizado: {$result['items']} registros disponibles para los próximos reportes.";

        if ($request->expectsJson()) {
            return response()->json(['message' => $message]);
        }

        return redirect()
            ->route('catalogos-referencia.index')
            ->with('success', $message);
    }

    public function storeConsolidatedCatalog(
        Request $request,
        CatalogoConsolidadoImporter $catalogoConsolidadoImporter,
        ActivityLogger $activityLogger
    ): RedirectResponse|JsonResponse {
        $validated = $request->validate([
            'tipo' => ['required', Rule::in(array_keys(CatalogoConsolidadoImporter::types()))],
            'version' => ['required', 'string', 'max:100'],
            'archivo' => ['required', 'file', 'mimes:xlsx', 'extensions:xlsx', 'max:512000'],
        ], [
            'tipo.required' => 'Selecciona el tipo de tarifario que vas a cargar.',
            'tipo.in' => 'El tipo de tarifario seleccionado no es válido.',
            'version.required' => 'Indica la versión o fecha del contrato.',
            'archivo.required' => 'Selecciona el archivo Excel del tarifario.',
            'archivo.mimes' => 'El tarifario debe estar en formato Excel (.xlsx).',
            'archivo.extensions' => 'El tarifario debe tener extensión .xlsx.',
            'archivo.max' => 'El tarifario puede pesar hasta 500 MB.',
        ]);

        try {
            $result = $catalogoConsolidadoImporter->import(
                $validated['archivo'],
                $validated['tipo'],
                $validated['version'],
                $request->user()
            );
        } catch (InvalidArgumentException|RuntimeException $exception) {
            throw ValidationException::withMessages([
                'archivo' => $exception->getMessage(),
            ]);
        }

        $activityLogger->log(
            'catalogo_consolidado_importado',
            'Administrador actualizó un tarifario contractual del consolidado RIPS.',
            [
                'tipo' => $result['import']->tipo,
                'version' => $result['import']->version,
                'archivo_origen' => $result['import']->archivo_origen,
                'registros_procesados' => $result['processed'],
                'registros_nuevos' => $result['created'],
                'registros_actualizados' => $result['updated'],
                'registros_ignorados' => $result['ignored'],
            ]
        );

        $message = "Tarifario contractual actualizado: {$result['processed']} filas procesadas.";

        if ($request->expectsJson()) {
            return response()->json(['message' => $message]);
        }

        return redirect()
            ->route('catalogos-referencia.index')
            ->with('success', $message);
    }
}
