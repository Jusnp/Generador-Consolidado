@extends('layouts.app')

@section('title', 'Configuración de notas técnicas')
@section('header_title', 'Configuración de notas técnicas')
@section('header_subtitle', 'Medicamentos, servicios y fuentes por programa')

@section('content')
<style>
    .references-page { max-width: 1100px; margin: 0 auto; }
    .references-back { display: flex; flex-wrap: wrap; gap: 10px; margin: 28px 0 16px; }
    .references-card { padding: 32px; }
    .references-card h2, .references-card h3 { margin-top: 0; color: var(--text); }
    .references-card p { color: var(--text-secondary); line-height: 1.6; }
    .references-form { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px; margin-top: 24px; }
    .references-form label { display: grid; gap: 8px; color: var(--text); font-weight: 700; }
    .references-form button { grid-column: 1 / -1; justify-self: start; }
    .references-table { width: 100%; margin-top: 18px; border-collapse: collapse; }
    .references-table th, .references-table td { padding: 12px 10px; border-bottom: 1px solid var(--border-soft); text-align: left; color: var(--text); }
    .references-table th { color: var(--text-secondary); font-size: 13px; }
    .reference-action { padding: 8px 14px; font-size: 13px; }
    .reference-status { display: inline-flex; align-items: center; gap: 8px; padding: 6px 11px; border-radius: 999px; font-weight: 800; font-size: 12px; letter-spacing: .02em; }
    .reference-status::before { width: 8px; height: 8px; border-radius: 50%; background: currentColor; content: ''; box-shadow: 0 0 0 3px color-mix(in srgb, currentColor 14%, transparent); }
    .reference-status.active { color: var(--green); background: color-mix(in srgb, var(--green) 11%, transparent); }
    .reference-status.inactive { color: var(--red); background: color-mix(in srgb, var(--red) 11%, transparent); }
    .reference-action { border-radius: 8px; font-weight: 800; transition: transform .15s ease, background .15s ease, box-shadow .15s ease; }
    .reference-action:hover { transform: translateY(-1px); box-shadow: 0 5px 12px rgba(15, 23, 42, .12); }
    .reference-action.activate { color: var(--green); border-color: color-mix(in srgb, var(--green) 55%, var(--border)); background: color-mix(in srgb, var(--green) 9%, transparent); }
    .reference-action.activate:hover { background: color-mix(in srgb, var(--green) 16%, transparent); }
    .reference-action.deactivate { color: var(--red); border-color: color-mix(in srgb, var(--red) 55%, var(--border)); background: color-mix(in srgb, var(--red) 8%, transparent); }
    .reference-action.deactivate:hover { background: color-mix(in srgb, var(--red) 15%, transparent); }
    .reference-modal[hidden] { display: none; }
    .reference-modal { position: fixed; inset: 0; z-index: 50; display: grid; place-items: center; padding: 24px; background: rgba(15, 23, 42, .42); }
    .reference-modal-card { width: min(440px, 100%); padding: 26px; border: 1px solid var(--border); border-radius: 16px; background: var(--bg-card); box-shadow: var(--shadow); }
    .reference-modal-card h3 { margin: 0 0 8px; color: var(--text-primary); }
    .reference-modal-card p { margin: 0; color: var(--text-secondary); line-height: 1.55; }
    .reference-modal-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 22px; }
    .reference-modal-actions button { padding: 10px 16px; border-radius: 8px; font-weight: 800; }
    .reference-modal-cancel { color: var(--text-primary); background: transparent; border: 1px solid var(--border); }
    .reference-modal-confirm { color: #fff; background: var(--green); border: 1px solid var(--green); }
    @media (max-width: 760px) { .references-form { grid-template-columns: 1fr; } }
</style>
<div class="references-page">
    <div class="references-back">
        <a href="{{ route('dashboard') }}" class="back-button">← Volver al dashboard</a>
        <a href="{{ route('catalogos-referencia.index') }}" class="back-button">Ver notas técnicas y catálogos</a>
    </div>
<div class="card references-card">
    <h2>Configurar medicamento o servicio</h2>
    <p>Los registros activos se aplican únicamente al programa seleccionado.</p>
    <form id="reference-form" class="references-form">
        @csrf
        <label>Programa<select name="programa_slug" required>@foreach($programas as $programa)<option value="{{ $programa['slug'] }}" @selected(($selectedProgram ?? '') === $programa['slug'])>{{ $programa['nombre'] }}</option>@endforeach</select></label>
        @if (($selectedProgram ?? '') === 'la-maria')
            <label id="route-field">Ruta<select name="ruta"><option value="">Todas las rutas</option><option value="CERVIX" @selected(($selectedRoute ?? '') === 'CERVIX')>Cérvix</option><option value="PROSTATA" @selected(($selectedRoute ?? '') === 'PROSTATA')>Próstata</option></select></label>
        @endif
        <label>Tipo<select name="tipo" required><option value="medicamento">Medicamento</option><option value="servicio">Servicio</option><option value="insumo">Insumo</option></select></label>
        <label>Código<input name="codigo"></label>
        <label>Nombre<input name="nombre" required></label>
        <label>Precio<input name="tarifa" type="number" min="0" step="0.0001" required></label>
        <button type="submit" style="grid-column:1/-1">Guardar referencia</button>
    </form>
    <p id="reference-message" style="margin-top:16px"></p>
    <div class="reference-source-links">
        <a href="{{ route('catalogos-referencia.index') }}" class="back-button">Ver notas técnicas y catálogos</a>
        @if (auth()->user()->role === 'admin')
            <span class="reference-source-note">Desde esa pantalla el administrador puede cargar una nueva versión.</span>
        @else
            <span class="reference-source-note">La edición de referencias manuales se realiza en esta pantalla.</span>
        @endif
    </div>
    <h3 style="margin-top:32px">Registros existentes</h3>
    <table class="references-table"><thead><tr><th>Programa</th><th>Ruta</th><th>Tipo</th><th>Código</th><th>Nombre</th><th>Tarifa</th><th>Estado</th><th>Acción</th></tr></thead><tbody id="reference-list"></tbody></table>
</div>
</div>
<div id="duplicate-modal" class="reference-modal" hidden>
    <div class="reference-modal-card" role="dialog" aria-modal="true" aria-labelledby="duplicate-modal-title">
        <h3 id="duplicate-modal-title">Referencia existente</h3>
        <p id="duplicate-modal-message">Este registro ya existe.</p>
        <div class="reference-modal-actions">
            <button type="button" class="reference-modal-cancel" id="duplicate-modal-cancel">Cancelar</button>
            <button type="button" class="reference-modal-confirm" id="duplicate-modal-confirm">Actualizar registro</button>
        </div>
    </div>
</div>
<script>
const form=document.getElementById('reference-form'), msg=document.getElementById('reference-message'), list=document.getElementById('reference-list'), duplicateModal=document.getElementById('duplicate-modal'); let pendingId=null;
const routeField=document.getElementById('route-field');
function updateRouteVisibility(){const visible=form.elements.programa_slug.value==='la-maria';if(routeField){routeField.hidden=!visible;}if(!visible&&form.elements.ruta){form.elements.ruta.value='';}}
function showDuplicateModal(message){return new Promise(resolve=>{document.getElementById('duplicate-modal-message').textContent=message+' ¿Deseas actualizarlo?';duplicateModal.hidden=false;const close=value=>{duplicateModal.hidden=true;document.getElementById('duplicate-modal-cancel').onclick=null;document.getElementById('duplicate-modal-confirm').onclick=null;resolve(value);};document.getElementById('duplicate-modal-cancel').onclick=()=>close(false);document.getElementById('duplicate-modal-confirm').onclick=()=>close(true);});}
async function loadReferences(){const params=new URLSearchParams({programa:form.elements.programa_slug.value,ruta:form.elements.ruta?.value||''});const r=await fetch('{{ route('referencias-manuales.index') }}?'+params.toString()+'&t='+Date.now(),{cache:'no-store',headers:{Accept:'application/json'}});const d=await r.json();list.innerHTML=d.referencias.map(x=>`<tr><td>${x.programa_slug}</td><td>${x.ruta||''}</td><td>${x.tipo}</td><td>${x.codigo||''}</td><td>${x.nombre}</td><td>${x.tarifa}</td><td><span class="reference-status ${x.activo?'active':'inactive'}">${x.activo?'ACTIVO':'INACTIVO'}</span></td><td><button class="reference-action ${x.activo?'deactivate':'activate'}" type="button" onclick="toggleReference(${x.id})">${x.activo?'Inactivar':'Activar'}</button></td></tr>`).join('');}
form.addEventListener('submit',async e=>{e.preventDefault();const body=new FormData(form);const isUpdate=Boolean(pendingId);const url=isUpdate?`/referencias-manuales/${pendingId}`:'{{ route('referencias-manuales.store') }}';if(isUpdate){body.append('_method','PUT');}const r=await fetch(url,{method:'POST',headers:{'Accept':'application/json','X-CSRF-TOKEN':form.querySelector('[name=_token]').value},body});const d=await r.json();if(r.status===409&&d.referencia&&await showDuplicateModal(d.message)){pendingId=d.referencia.id;for(const n of ['programa_slug','ruta','tipo','codigo','nombre','tarifa'])if(form.elements[n])form.elements[n].value=d.referencia[n]??'';msg.textContent='Revisa los campos y guarda para actualizar.';return;}msg.textContent=d.message||'Error';if(r.ok){pendingId=null;form.reset();loadReferences();}});
async function toggleReference(id){const r=await fetch(`/referencias-manuales/${id}/toggle`,{method:'PATCH',headers:{'Accept':'application/json','X-CSRF-TOKEN':form.querySelector('[name=_token]').value}});msg.textContent=(await r.json()).message;loadReferences();}
loadReferences();
updateRouteVisibility();
function reloadReferenceScope(){const params=new URLSearchParams({programa:form.elements.programa_slug.value,ruta:form.elements.ruta?.value||''});window.location.href='{{ route('referencias-manuales.index') }}?'+params.toString();}
form.elements.programa_slug.addEventListener('change',()=>{updateRouteVisibility();reloadReferenceScope();});
if(form.elements.ruta){form.elements.ruta.addEventListener('change',reloadReferenceScope);}
if(form.elements.programa_slug.value!=='la-maria'&&new URLSearchParams(window.location.search).get('ruta')){reloadReferenceScope();}
</script>
@endsection
