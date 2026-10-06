@extends('layouts.app')

@section('title', 'Catálogos y contratos | Comité de Estudios Médicos')

@section('header_title', 'Catálogos y contratos')

@section('header_subtitle', 'Catálogos segregados por programa de ejecución')

@section('content')
    <style>
        .catalog-card {
            width: min(920px, 100%);
            margin: 28px auto 0;
            padding: 32px;
        }

        .catalog-card h2,
        .catalog-card h3 {
            margin: 0;
        }

        .catalog-card > p,
        .catalog-help {
            color: var(--text-secondary);
            line-height: 1.6;
        }

        .catalog-table {
            width: 100%;
            margin-top: 28px;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .catalog-table th,
        .catalog-table td {
            padding: 13px 10px;
            border-bottom: 1px solid var(--border-soft);
            text-align: left;
            vertical-align: top;
            overflow-wrap: anywhere;
        }

        .catalog-table th:nth-child(1),
        .catalog-table td:nth-child(1) { width: 15%; }
        .catalog-table th:nth-child(2),
        .catalog-table td:nth-child(2) { width: 20%; }
        .catalog-table th:nth-child(3),
        .catalog-table td:nth-child(3) { width: 15%; }
        .catalog-table th:nth-child(4),
        .catalog-table td:nth-child(4) { width: 27%; }
        .catalog-table th:nth-child(5),
        .catalog-table td:nth-child(5) { width: 10%; }
        .catalog-table th:nth-child(6),
        .catalog-table td:nth-child(6) { width: 13%; }

        .catalog-table th {
            color: var(--text-secondary);
            font-size: 13px;
        }

        .catalog-status {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            font-weight: 700;
        }

        .catalog-status::before {
            width: 9px;
            height: 9px;
            border-radius: 50%;
            background: currentColor;
            content: '';
        }

        .catalog-status.ready {
            color: var(--green);
        }

        .catalog-status.missing {
            color: var(--red);
        }

        .catalog-view-button {
            display: flex;
            width: 130px;
            min-height: 40px;
            max-width: 100%;
            margin: 8px 0 0;
            padding: 6px 10px;
            border: 1px solid var(--border);
            border-radius: 8px;
            color: var(--text-primary);
            background: var(--surface-soft);
            font-size: 13px;
            font-weight: 700;
            line-height: 1.2;
            white-space: normal;
            text-align: center;
            text-decoration: none;
            box-sizing: border-box;
            align-items: center;
            justify-content: center;
        }

        .catalog-view-button:hover {
            border-color: var(--green);
            color: var(--green);
        }

        .catalog-toggle-button { cursor: pointer; font-family: inherit; }
        .catalog-toggle-button.danger { border-color: var(--red); color: var(--red); }
        .catalog-toggle-button.success { border-color: var(--green); color: var(--green); }
        .catalog-card form { margin: 0; }

        .catalog-upload-form {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
            margin-top: 28px !important;
            padding: 20px;
            border: 1px solid var(--border);
            border-radius: 12px;
            background: var(--bg-card-secondary);
        }

        .catalog-upload-form .form-group:last-of-type,
        .catalog-upload-form .upload-progress,
        .catalog-upload-form .upload-progress-error,
        .catalog-upload-form .form-actions {
            grid-column: 1 / -1;
        }

        .upload-progress[hidden],
        .upload-progress-error[hidden] { display: none; }

        .upload-progress-label {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 7px;
            color: var(--text-secondary);
            font-size: 13px;
        }

        .upload-progress-track {
            height: 8px;
            overflow: hidden;
            border-radius: 999px;
            background: var(--border);
        }

        .upload-progress-bar {
            width: 0;
            height: 100%;
            background: var(--green);
            transition: width .2s ease;
        }

        .upload-progress-error { color: var(--red); }

        .catalog-section-title {
            margin-top: 48px !important;
        }

        @media (max-width: 650px) {
            .catalog-card {
                padding: 24px;
            }

            .catalog-table {
                display: block;
                overflow-x: auto;
                table-layout: auto;
                white-space: normal;
            }

            .catalog-table th,
            .catalog-table td {
                min-width: 125px;
            }

            .catalog-upload-form { grid-template-columns: 1fr; }
        }
    </style>

    <a href="{{ route('dashboard') }}" class="back-button">
        ← Volver al dashboard
    </a>

    <section class="card catalog-card">
        <h2>Notas técnicas y catálogos</h2>

        <p>
            Propiedad:
            <strong>{{ $catalogOwnership['ANEXOS_2706'] ?? 'La María · catálogos de referencia' }}</strong>.
            Estos archivos se usan únicamente para cruzar y estimar costos de referencia en
            La María (Próstata / Cérvix). No afectan el conversor JSON → Excel de Autoinmunes.
        </p>

        @if (session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif

        @if ($errors->any())
            <div class="alert alert-error">
                @foreach ($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        <form
            method="POST"
            action="{{ route('catalogos-referencia.store') }}"
            enctype="multipart/form-data"
            class="catalog-upload-form"
            data-upload-progress-form
        >
            @csrf
            <div class="form-group">
                <label class="form-label" for="catalog-type">Tipo de fuente</label>
                <select class="form-select" id="catalog-type" name="tipo" required>
                    <option value="">Selecciona una fuente</option>
                    @foreach ($catalogTypes as $type => $label)
                        <option value="{{ $type }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-group">
                <label class="form-label" for="catalog-version">Versión</label>
                <input class="form-input" id="catalog-version" name="version" required placeholder="Ej. 2026-10-01">
            </div>
            <div class="form-group">
                <label class="form-label" for="catalog-contract">Contrato</label>
                <select class="form-select" id="catalog-contract" name="contrato_id">
                    <option value="">Sin contrato asociado</option>
                    @foreach ($contratosVigentes as $contrato)
                        <option value="{{ $contrato->id }}">{{ $contrato->nombre }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-group">
                <label class="form-label" for="catalog-file">Archivo Excel</label>
                <input class="form-input" id="catalog-file" name="archivo" type="file" accept=".xlsx" required>
            </div>
            <div class="upload-progress" data-upload-progress role="progressbar" aria-label="Progreso de carga del catálogo" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" hidden>
                <div class="upload-progress-label"><span data-upload-progress-status>Preparando archivo…</span><span data-upload-progress-value>0%</span></div>
                <div class="upload-progress-track"><div class="upload-progress-bar" data-upload-progress-bar></div></div>
            </div>
            <p class="upload-progress-error" data-upload-progress-error role="alert" hidden></p>
            <div class="form-actions"><button class="btn btn-green" type="submit" data-upload-progress-submit>Cargar fuente</button></div>
        </form>

        <h3 style="margin-top: 36px;">Estado de las fuentes</h3>

        <table class="catalog-table">
            <thead>
                <tr>
                    <th>Programa</th>
                    <th>Fuente</th>
                    <th>Versión activa</th>
                    <th>Archivo</th>
                    <th>Registros</th>
                    <th>Estado</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($catalogosPorTipo as $type => $catalogos)
                    @foreach ($catalogos as $activeCatalog)
                    <tr>
                        <td>{{ str($activeCatalog->programa_slug)->replace('-', ' ')->title() }}</td>
                        <td>{{ $catalogTypes[$type] ?? $type }}</td>
                        <td>{{ $activeCatalog->version }}</td>
                        <td>{{ $activeCatalog->archivo_origen }}</td>
                        <td>{{ $activeCatalog->items_count }}</td>
                        <td>
                            @if ($activeCatalog)
                                <span class="catalog-status {{ $activeCatalog->activo ? 'ready' : 'missing' }}">{{ $activeCatalog->activo ? 'ACTIVO' : 'INACTIVO' }}</span>
                                <a href="{{ route('catalogos-referencia.show', $activeCatalog) }}" class="catalog-view-button">Ver contenido</a>
                                <form method="POST" action="{{ route('catalogos-referencia.toggle-file', $activeCatalog) }}">
                                    @csrf
                                    <button type="submit" class="catalog-view-button catalog-toggle-button {{ $activeCatalog->activo ? 'danger' : 'success' }}">{{ $activeCatalog->activo ? 'Inactivar archivo' : 'Activar archivo' }}</button>
                                </form>
                            @else
                                <span class="catalog-status missing">PENDIENTE</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                @empty
                    <tr><td colspan="6">No hay notas técnicas o catálogos cargados.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <section class="card catalog-card">
        <h2>Tarifarios del consolidado RIPS</h2>

        <p>
            Propiedad:
            <strong>{{ $catalogOwnership['CUPS_CONTRATO'] ?? 'Autoinmunes · tarifarios contractuales' }}</strong>.
            Actualiza los valores del contrato que usa el conversor JSON → Excel. Esta carga no afecta las
            notas técnicas ni el seguimiento mensual de rutas. La versión anterior queda registrada para auditoría.
        </p>

        <h3 class="catalog-section-title">Última versión de cada tarifario</h3>

        <p class="catalog-help">
            Las tarifas presentes en la carga inicial se muestran como vigentes. Selecciona
            <strong>Ver contenido</strong> para consultar y editar el catálogo dentro del sistema.
        </p>

        <form
            method="POST"
            action="{{ route('catalogos-referencia.consolidado.store') }}"
            enctype="multipart/form-data"
            class="catalog-upload-form"
            data-upload-progress-form
        >
            @csrf
            <div class="form-group">
                <label class="form-label" for="consolidated-type">Tipo de tarifario</label>
                <select class="form-select" id="consolidated-type" name="tipo" required>
                    <option value="">Selecciona un tarifario</option>
                    @foreach ($consolidatedCatalogTypes as $type => $label)
                        <option value="{{ $type }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-group">
                <label class="form-label" for="consolidated-version">Versión contractual</label>
                <input class="form-input" id="consolidated-version" name="version" required placeholder="Ej. Contrato 2026-10">
            </div>
            <div class="form-group">
                <label class="form-label" for="consolidated-file">Archivo Excel</label>
                <input class="form-input" id="consolidated-file" name="archivo" type="file" accept=".xlsx" required>
            </div>
            <div class="upload-progress" data-upload-progress role="progressbar" aria-label="Progreso de carga del tarifario" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" hidden>
                <div class="upload-progress-label"><span data-upload-progress-status>Preparando archivo…</span><span data-upload-progress-value>0%</span></div>
                <div class="upload-progress-track"><div class="upload-progress-bar" data-upload-progress-bar></div></div>
            </div>
            <p class="upload-progress-error" data-upload-progress-error role="alert" hidden></p>
            <div class="form-actions"><button class="btn btn-green" type="submit" data-upload-progress-submit>Cargar tarifario</button></div>
        </form>

        <table class="catalog-table consolidated-table">
            <thead>
                <tr>
                    <th>Programa</th>
                    <th>Fuente</th>
                    <th>Versión activa</th>
                    <th>Archivo</th>
                    <th>Registros</th>
                    <th>Estado</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($consolidatedCatalogTypes as $type => $label)
                    @php($lastImport = $consolidatedCatalogsByType->get($type)?->first())
                    @php($baseRecords = $consolidatedCatalogBaseRecords[$type] ?? 0)
                    @php($activeRecords = $consolidatedCatalogActiveRecords[$type] ?? 0)
                    <tr>
                        <td>Autoinmunes</td>
                        <td>{{ $label }}</td>
                        <td>{{ $lastImport?->version ?? ($baseRecords > 0 ? 'Carga inicial vigente' : '—') }}</td>
                        <td>{{ $lastImport?->archivo_origen ?? ($baseRecords > 0 ? 'Catálogo vigente del sistema' : '—') }}</td>
                        <td>{{ $lastImport?->registros_procesados ?? ($baseRecords > 0 ? number_format($baseRecords, 0, ',', '.') : '—') }}</td>
                        <td>
                            @if ($baseRecords > 0)
                                <span class="catalog-status {{ $activeRecords > 0 ? 'ready' : 'missing' }}">{{ $activeRecords > 0 ? 'ACTIVO' : 'INACTIVO' }}</span>
                            @else
                                <span class="catalog-status missing">SIN REGISTRO</span>
                            @endif
                            @if ($baseRecords > 0)
                                <a href="{{ route('catalogos-referencia.consolidado.show', $type) }}" class="catalog-view-button">Ver contenido</a>
                                <form method="POST" action="{{ route('catalogos-referencia.consolidado.toggle-file', $type) }}">
                                    @csrf
                                    <button type="submit" class="catalog-view-button catalog-toggle-button {{ $activeRecords > 0 ? 'danger' : 'success' }}">{{ $activeRecords > 0 ? 'Inactivar archivo' : 'Activar archivo' }}</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    <script src="{{ asset('js/upload-progress.js') }}"></script>

@endsection
