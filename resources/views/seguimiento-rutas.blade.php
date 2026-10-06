@extends('layouts.app')

@section('title', 'La María · '.($rutaCodigo === 'PROSTATA' ? 'Próstata' : 'Cérvix').' | Comité de Estudios Médicos')

@section('header_title', 'La María · '.($rutaCodigo === 'PROSTATA' ? 'Próstata' : 'Cérvix'))

@section('header_subtitle', $rutaCodigo === 'PROSTATA'
    ? 'Seguimiento mensual de la ruta de próstata (C61)'
    : 'Seguimiento mensual de la ruta de cérvix (C53)')

@section('content')
    <style>
        .route-follow-up-card {
            width: min(760px, 100%);
            margin: 28px auto 0;
            padding: 32px;
        }

        .route-follow-up-card h2 {
            margin: 0;
            font-size: 26px;
        }

        .route-follow-up-card > p {
            margin: 12px 0 28px;
            color: var(--text-secondary);
            line-height: 1.6;
        }

        .route-follow-up-form {
            display: grid;
            gap: 20px;
        }

        .route-follow-up-actions {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .route-follow-up-rules {
            margin: 0;
            padding-left: 20px;
            color: var(--text-secondary);
            line-height: 1.75;
        }

        .catalog-readiness {
            margin: 0;
            padding: 16px;
            border: 1px solid var(--border-soft);
            border-radius: 10px;
            color: var(--text-secondary);
            line-height: 1.65;
        }

        .catalog-readiness ul {
            margin: 8px 0 0;
            padding-left: 20px;
        }

        .catalog-ready {
            color: var(--green);
            font-weight: 700;
        }

        .catalog-pending {
            color: var(--red);
            font-weight: 700;
        }

        .upload-progress {
            padding: 14px;
            border: 1px solid var(--border-soft);
            border-radius: 10px;
            background: var(--surface-soft);
        }

        .upload-progress[hidden],
        .upload-progress-error[hidden] {
            display: none;
        }

        .upload-progress-label {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 9px;
            color: var(--text-secondary);
            font-size: 13px;
            font-weight: 700;
        }

        .upload-progress-track {
            height: 9px;
            overflow: hidden;
            border-radius: 999px;
            background: var(--border-soft);
        }

        .upload-progress-bar {
            width: 0;
            height: 100%;
            border-radius: inherit;
            background: var(--green);
            transition: width 0.2s ease;
        }

        .upload-progress-error {
            margin: 0;
            color: var(--red);
            font-size: 13px;
            line-height: 1.5;
        }

        @media (max-width: 650px) {
            .route-follow-up-card {
                padding: 24px;
            }

            .route-follow-up-actions .btn {
                width: 100%;
            }
        }
    </style>

    <a href="{{ route('la-maria') }}" class="back-button">
        ← Volver a La María
    </a>

    <section class="card route-follow-up-card">
        <h2>
            Generar reporte · {{ $rutaCodigo === 'PROSTATA' ? 'Próstata' : 'Cérvix' }}
        </h2>

        <p>
            Esta ejecución de <strong>La María</strong> genera únicamente el seguimiento de
            <strong>{{ $rutaCodigo === 'PROSTATA' ? 'próstata (C61)' : 'cérvix (C53)' }}</strong>.
            Para la otra ruta, <a href="{{ route('la-maria') }}">vuelve a La María</a> y elige la opción correspondiente.
        </p>

        <div class="catalog-readiness">
            <strong>Catálogos usados en este reporte:</strong>
            <ul>
                @foreach ($catalogStatus as $catalog)
                    <li>
                        {{ $catalog['tipo'] }}:
                        @if ($catalog['activo'])
                            <span class="catalog-ready">ACTIVO ({{ $catalog['version'] }})</span>
                        @else
                            <span class="catalog-pending">PENDIENTE</span>
                        @endif
                    </li>
                @endforeach
            </ul>

            @if (auth()->user()->role === 'admin')
                @if (auth()->user()->role === 'admin')
                    <a href="{{ route('catalogos-referencia.index') }}">Actualizar catálogos de La María</a>
                @endif
            @endif
        </div>

        @if ($errors->any())
            <div class="alert alert-error">
                @foreach ($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        <form
            method="POST"
            action="{{ $exportRoute }}"
            enctype="multipart/form-data"
            class="route-follow-up-form"
            data-upload-progress-form
            data-upload-progress-mode="download"
        >
            @csrf

            <div>
                <label for="archivos" class="form-label">
                    Archivos RIPS JSON <span class="required">*</span>
                </label>

                <input
                    id="archivos"
                    name="archivos[]"
                    type="file"
                    accept=".json,.txt,application/json,text/plain"
                    class="form-input"
                    multiple
                    required
                >

                <div class="form-help">
                    Puedes cargar hasta 25 archivos, con un máximo de 500 MB por archivo.
                </div>
            </div>

            <div>
                <label for="sql_archivo" class="form-label">
                    Archivo SQL para validación <span class="form-help">(opcional)</span>
                </label>
                <input
                    id="sql_archivo"
                    name="sql_archivo"
                    type="file"
                    accept=".sql,.txt,text/plain,application/sql"
                    class="form-input"
                >
                <div class="form-help">
                    Se cruza por número de documento y se agrega la hoja “VALIDACION SQL”.
                    La basura, comentarios y tablas no reconocidas no detienen el reporte.
                </div>
            </div>

            <ul class="route-follow-up-rules">
                @if ($rutaCodigo === 'PROSTATA')
                    <li>Solo incluye pacientes con diagnóstico C61 (próstata).</li>
                @else
                    <li>Solo incluye pacientes con diagnóstico C53 (cérvix).</li>
                @endif
                <li>Los casos con doble ruta (C61 y C53) aparecen como revisión.</li>
                <li>El reporte separa el seguimiento de cada usuario por mes de atención.</li>
                <li>Otros servicios usan la fecha de suministro para asignar el mes.</li>
                <li>El Excel separa valor RIPS reportado y costo estimado de referencia.</li>
            </ul>

            <div class="upload-progress" data-upload-progress role="progressbar" aria-label="Progreso de generación del seguimiento" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" hidden>
                <div class="upload-progress-label">
                    <span data-upload-progress-status>Preparando archivos…</span>
                    <span data-upload-progress-value>0%</span>
                </div>
                <div class="upload-progress-track">
                    <div class="upload-progress-bar" data-upload-progress-bar></div>
                </div>
            </div>
            <p class="upload-progress-error" data-upload-progress-error role="alert" hidden></p>

            <div class="route-follow-up-actions">
                <button type="submit" class="btn btn-green" data-upload-progress-submit>
                    Generar Excel de {{ $rutaCodigo === 'PROSTATA' ? 'próstata' : 'cérvix' }}
                </button>

                <a href="{{ route('dashboard') }}" class="btn btn-secondary">
                    Cancelar
                </a>
            </div>
        </form>
    </section>

    <script src="{{ asset('js/upload-progress.js') }}"></script>
@endsection
