@extends('layouts.app')
@section('title', 'Editor de nota técnica')
@section('header_title', 'Editor de nota técnica')
@section('header_subtitle', $catalogo->archivo_origen)
@section('content')
<style>
.sheet-panel{margin:20px 0;padding:20px;background:var(--bg-card);border:1px solid var(--border);border-radius:12px;min-width:0}
.sheet-tools,.sheet-footer{display:flex;gap:14px;align-items:center;flex-wrap:wrap;margin:14px 0}
.sheet-scroll{height:62vh;overflow:auto;border:1px solid var(--border)}
.sheet-grid{border-collapse:separate;border-spacing:0;font-size:13px;min-width:1850px;width:100%}
.sheet-grid th,.sheet-grid td{border-right:1px solid var(--border);border-bottom:1px solid var(--border);padding:0;vertical-align:top}
.sheet-grid th{position:sticky;top:0;z-index:2;background:var(--bg-card);padding:9px}
.sheet-grid .row-number{position:sticky;left:0;min-width:45px;background:var(--bg-card);padding:8px}
.sheet-grid input{width:100%;min-width:170px;padding:9px;border:0;background:transparent;color:var(--text-primary);font:inherit;box-sizing:border-box}
.sheet-grid input:focus{outline:2px solid var(--green);outline-offset:-2px}
.sheet-grid .description{min-width:380px}.sheet-grid .editable{min-width:180px}.sheet-grid .readonly{padding:9px;min-width:160px}.sheet-grid td:nth-last-child(2){min-width:150px;padding:10px}.sheet-grid td:last-child{min-width:160px;padding:10px}
.sheet-grid tr.dirty{background:color-mix(in srgb,var(--green) 12%,transparent)}
.sheet-grid button,.sheet-tools button{padding:8px 14px;border:1px solid var(--green);border-radius:7px;background:var(--green);color:white;cursor:pointer;font-weight:700}
.sheet-grid button:disabled{opacity:.5}.sheet-grid .save-cell{min-width:150px;padding:5px}
.sheet-grid details{min-width:250px;padding:9px}.sheet-grid pre{white-space:pre-wrap;font-size:12px}
#sheet-status{min-height:26px}
.sheet-grid .inactive{opacity:.55}.status-pill{display:inline-flex;align-items:center;gap:6px;padding:5px 9px;border-radius:999px;font-weight:800;font-size:12px}.status-pill::before{content:'';width:8px;height:8px;border-radius:50%;background:currentColor}.status-pill.active{color:var(--green);background:color-mix(in srgb,var(--green) 12%,transparent)}.status-pill.inactive{color:var(--red);background:color-mix(in srgb,var(--red) 12%,transparent)}
.sheet-grid .toggle-item{display:block;margin-top:8px;background:transparent;color:var(--red);border-color:var(--red)}
.sheet-grid .toggle-item[data-active="0"]{color:var(--green);border-color:var(--green)}
</style>
@php($esLaMaria = $catalogo->programa_slug === 'la-maria')
<a href="{{ route('catalogos-referencia.index') }}" class="back-button">← Volver a notas técnicas y catálogos</a>
<section class="sheet-panel">
<h2>{{ $catalogo->tipo }} · {{ $catalogo->version }}</h2>
<p>Edita la nota técnica directamente en esta cuadrícula. Los cambios se aplican a los próximos informes y quedan registrados en auditoría.</p>
<p>Datos importados del Excel. Las fórmulas y el diseño del archivo original no se reproducen aquí. Los demás campos se conservan.</p>
@unless($catalogo->activo)<p>Versión inactiva: solo consulta.</p>@endunless
@if($catalogo->activo)<form id="new-item" class="sheet-tools"><input name="codigo" required placeholder="Código"><input name="descripcion" placeholder="Descripción"><input name="tarifa_referencia" type="number" min="0" step="0.0001" placeholder="Tarifa">@if($esLaMaria)<input name="ruta" placeholder="Ruta (CÉRVIX o PRÓSTATA)">@endif<input name="categoria" placeholder="Categoría"><button>Agregar fila</button></form>@endif
<form class="sheet-tools" method="GET"><input name="q" value="{{ request('q') }}" aria-label="Buscar código o descripción" placeholder="Buscar código o descripción"><button>Buscar</button><a href="{{ route('catalogos-referencia.show', $catalogo) }}">Ver todos</a></form>
<form class="sheet-tools" method="GET"><label for="sheet">Hoja del Excel</label><select id="sheet" name="sheet" onchange="this.form.submit()"><option value="">Todas las hojas</option>@foreach($sheets as $sheet)<option value="{{ $sheet }}" @selected(request('sheet') === $sheet)>{{ $sheet }}</option>@endforeach</select><input type="hidden" name="q" value="{{ request('q') }}">@if($sheets->isEmpty())<small>Esta versión fue cargada antes de guardar sus hojas. Vuelve a cargar el archivo para separarlas.</small>@endif</form>
<div id="sheet-status" role="status" aria-live="polite"></div>
<div class="sheet-scroll" tabindex="0" aria-label="Cuadrícula desplazable">
@php($columnLetter = $esLaMaria ? 7 : 6)
<table class="sheet-grid"><thead><tr><th>#</th><th>A · Código</th>@if($esLaMaria)<th>B · Ruta</th>@endif<th>{{ $esLaMaria ? 'C' : 'B' }} · Categoría</th><th>{{ $esLaMaria ? 'D' : 'C' }} · Descripción</th><th>{{ $esLaMaria ? 'E' : 'D' }} · Tarifa / valor de referencia</th>@foreach($metadataColumns as $metadataColumn)<th>{{ chr(64 + $columnLetter++) }} · {{ str($metadataColumn)->replace('_', ' ')->title() }}</th>@endforeach<th>Estado</th><th>Guardar</th></tr></thead><tbody>
@foreach($items as $item)
<tr class="{{ $item->activo ? '' : 'inactive' }}" data-url="{{ route('catalogos-referencia.items.update', [$catalogo, $item->id]) }}" data-toggle-url="{{ route('catalogos-referencia.items.toggle', [$catalogo, $item->id]) }}" data-revision="{{ hash('sha256', json_encode($item->getAttributes())) }}">
<td class="row-number">{{ $items->firstItem() + $loop->index }}</td><td><input class="editable" data-field="codigo" value="{{ $item->codigo }}" aria-label="Código {{ $item->codigo }}" @disabled(!$catalogo->activo)></td>@if($esLaMaria)<td><input class="editable" data-field="ruta" value="{{ $item->ruta }}" aria-label="Ruta {{ $item->codigo }}" @disabled(!$catalogo->activo)></td>@endif<td><input class="editable" data-field="categoria" value="{{ $item->categoria }}" aria-label="Categoría {{ $item->codigo }}" @disabled(!$catalogo->activo)></td>
<td><input class="description" data-field="descripcion" aria-label="Descripción {{ $item->codigo }}" value="{{ $item->descripcion }}" @disabled(!$catalogo->activo)></td>
<td><input data-field="tarifa_referencia" aria-label="Tarifa {{ $item->codigo }}" type="number" step="0.0001" min="0" value="{{ $item->tarifa_referencia }}" @disabled(!$catalogo->activo)></td>
@foreach($metadataColumns as $metadataColumn)@php($metadataValue = is_array($item->metadatos) ? data_get($item->metadatos, $metadataColumn, '') : '')<td><input class="editable" data-field="metadata.{{ $metadataColumn }}" value="{{ is_scalar($metadataValue) || $metadataValue === null ? $metadataValue : json_encode($metadataValue, JSON_UNESCAPED_UNICODE) }}" @disabled(!$catalogo->activo)></td>@endforeach
<td><span class="status-pill {{ $item->activo ? 'active' : 'inactive' }}">{{ $item->activo ? 'ACTIVO' : 'INACTIVO' }}</span><button type="button" class="toggle-item" data-active="{{ $item->activo ? 1 : 0 }}">{{ $item->activo ? 'Inactivar' : 'Activar' }}</button></td>
<td class="save-cell"><button type="button" disabled>Guardar fila</button></td></tr>
@endforeach
@if($items->isEmpty())<tr><td colspan="{{ $esLaMaria ? 9 : 8 }}">No hay registros para esta búsqueda.</td></tr>@endif
</tbody></table></div>
<div class="sheet-footer">
@if($items->previousPageUrl())<a href="{{ $items->previousPageUrl() }}">← Anterior</a>@endif
<span>Filas {{ $items->firstItem() ?? 0 }}–{{ $items->lastItem() ?? 0 }} de {{ $items->total() }} · Página {{ $items->currentPage() }} de {{ $items->lastPage() }}</span>
@if($items->nextPageUrl())<a href="{{ $items->nextPageUrl() }}">Siguiente →</a>@endif
</div></section>
<script>
const statusBox = document.getElementById('sheet-status');
document.querySelectorAll('tr[data-url]').forEach(row => {
    const button = row.querySelector('button');
    row.querySelectorAll('input').forEach(input => input.addEventListener('input', () => { row.classList.add('dirty'); button.disabled = false; }));
    button.addEventListener('click', async () => {
        const inputs = [...row.querySelectorAll('input')];
        if (!inputs.every(input => input.reportValidity())) return;
        const body = {revision: row.dataset.revision};
        inputs.forEach(input => { if (input.dataset.field.startsWith('metadata.')) { body.metadata ??= {}; body.metadata[input.dataset.field.slice(9)] = input.value; } else { body[input.dataset.field] = input.value === '' ? null : input.value; } });
        button.disabled = true; inputs.forEach(input => input.disabled = true);
        statusBox.textContent = 'Guardando fila…';
        try {
            const response = await fetch(row.dataset.url, {method:'PATCH',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':@json(csrf_token())},body:JSON.stringify(body)});
            const result = await response.json();
            if (!response.ok) throw new Error(result.errors ? Object.values(result.errors).flat().join(' ') : result.message);
            row.dataset.revision = result.revision; row.classList.remove('dirty'); statusBox.textContent = result.message;
        } catch(error) { statusBox.textContent = error.message || 'No se pudo guardar.'; }
        finally { inputs.forEach(input => input.disabled = false); button.disabled = !row.classList.contains('dirty'); }
    });
});
document.querySelectorAll('.toggle-item').forEach(button => button.addEventListener('click', async () => {
    const row = button.closest('tr');
    const response = await fetch(row.dataset.toggleUrl, {method:'PATCH', headers:{'Accept':'application/json','X-CSRF-TOKEN':@json(csrf_token())}});
    const result = await response.json();
    if (response.ok) { statusBox.textContent = result.message; window.location.reload(); }
    else { statusBox.textContent = result.message || 'No se pudo actualizar el estado.'; }
}));
document.getElementById('new-item')?.addEventListener('submit', async event => {
    event.preventDefault();
    const form = event.currentTarget;
    const response = await fetch('{{ route('catalogos-referencia.items.store', $catalogo) }}', {method:'POST', headers:{'Accept':'application/json','X-CSRF-TOKEN':@json(csrf_token())}, body:new FormData(form)});
    const result = await response.json();
    statusBox.textContent = result.message || 'No se pudo agregar la fila.';
    if (response.ok) window.location.reload();
});
window.addEventListener('beforeunload', event => { if(document.querySelector('tr.dirty')) { event.preventDefault(); event.returnValue = ''; } });
</script>
@endsection
