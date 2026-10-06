<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Detalle de actividad | Comité de Estudios Médicos</title>

    <link rel="icon" href="{{ asset('favicon.png') }}?v=2" type="image/png">
    <link rel="shortcut icon" href="{{ asset('favicon.ico') }}?v=2">

    <script>
        (function () {
            const savedTheme =
                localStorage.getItem('json-excel-theme') || 'dark';

            document.documentElement.setAttribute(
                'data-theme',
                savedTheme
            );
        })();
    </script>

    <style>
        * {
            box-sizing: border-box;
        }

        html,
        body {
            margin: 0;
            padding: 0;
            min-height: 100%;
            font-family: Arial, Helvetica, sans-serif;
        }

        body {
            background: var(--bg);
            color: var(--text);
            transition: background 0.2s ease, color 0.2s ease;
        }

        :root {
            --bg: #f3f5f7;
            --card: #ffffff;
            --card-soft: #f8fafb;
            --border: #dfe4e8;
            --text: #1f2933;
            --muted: #66727d;
            --primary: #1f4e79;
            --primary-hover: #173b5c;
            --shadow: 0 8px 25px rgba(0, 0, 0, 0.08);
        }

        html[data-theme="dark"] {
            --bg: #101417;
            --card: #171d21;
            --card-soft: #1d252a;
            --border: #303a40;
            --text: #edf2f4;
            --muted: #aab5bc;
            --primary: #4d8ac4;
            --primary-hover: #619bd0;
            --shadow: 0 8px 25px rgba(0, 0, 0, 0.28);
        }

        .page {
            min-height: 100vh;
        }

        /* =========================================================
           HEADER
        ========================================================= */

        .header {
            min-height: 82px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 32px;
            background: var(--card);
            border-bottom: 1px solid var(--border);
            box-shadow: var(--shadow);
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        /*
         * No usamos una ruta inventada para el logo.
         * El espacio queda preparado para mantener el diseño
         * institucional sin alterar el logo del Dashboard.
         */

        .brand-logo-container {
            width: 58px;
            height: 58px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .brand-logo-container img {
            max-width: 58px;
            max-height: 58px;
            object-fit: contain;
        }

        .brand-divider {
            width: 1px;
            height: 48px;
            background: var(--border);
        }

        .brand-text h1 {
            margin: 0;
            font-size: 19px;
            letter-spacing: 0.4px;
            font-weight: 700;
        }

        .brand-text p {
            margin: 5px 0 0;
            color: var(--muted);
            font-size: 13px;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .user-info {
            text-align: right;
            margin-right: 8px;
        }

        .user-name {
            font-weight: 700;
            font-size: 14px;
        }

        .user-role {
            color: var(--muted);
            font-size: 12px;
            margin-top: 3px;
        }

        .theme-button {
            width: 40px;
            height: 40px;
            border: 1px solid var(--border);
            border-radius: 9px;
            background: var(--card-soft);
            color: var(--text);
            cursor: pointer;
            font-size: 17px;
        }

        .theme-button:hover {
            border-color: var(--primary);
        }

        .logout-button {
            border: 0;
            border-radius: 8px;
            padding: 10px 15px;
            background: var(--primary);
            color: #fff;
            cursor: pointer;
            font-weight: 600;
        }

        .logout-button:hover {
            background: var(--primary-hover);
        }

        /* =========================================================
           CONTENIDO
        ========================================================= */

        .container {
            max-width: 1150px;
            margin: 0 auto;
            padding: 34px 32px 50px;
        }

        .page-title {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            margin-bottom: 25px;
        }

        .page-title h2 {
            margin: 0;
            font-size: 25px;
        }

        .page-title p {
            margin: 7px 0 0;
            color: var(--muted);
            font-size: 14px;
        }

        .back-button {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            text-decoration: none;
            background: var(--card);
            color: var(--text);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 10px 15px;
            font-size: 14px;
            font-weight: 600;
            white-space: nowrap;
        }

        .back-button:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        /* =========================================================
           CARD
        ========================================================= */

        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 12px;
            box-shadow: var(--shadow);
            overflow: hidden;
            margin-bottom: 20px;
        }

        .card-header {
            padding: 20px 24px;
            background: var(--card-soft);
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
        }

        .activity-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 17px;
            font-weight: 700;
        }

        .action-badge {
            display: inline-flex;
            align-items: center;
            padding: 6px 11px;
            border-radius: 999px;
            border: 1px solid var(--border);
            background: var(--card);
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }

        .card-body {
            padding: 24px;
        }

        /* =========================================================
           INFORMACIÓN GENERAL
        ========================================================= */

        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
        }

        .info-item {
            padding: 16px;
            background: var(--card-soft);
            border: 1px solid var(--border);
            border-radius: 9px;
        }

        .info-label {
            color: var(--muted);
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 700;
            margin-bottom: 7px;
        }

        .info-value {
            font-size: 14px;
            font-weight: 600;
            word-break: break-word;
        }

        .info-value.normal {
            font-weight: 400;
        }

        /* =========================================================
           DETALLES ESPECÍFICOS
        ========================================================= */

        .section-title {
            margin: 28px 0 15px;
            font-size: 17px;
            font-weight: 700;
        }

        .conversion-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 15px;
        }

        .conversion-item {
            padding: 17px;
            border: 1px solid var(--border);
            border-radius: 9px;
            background: var(--card-soft);
        }

        .conversion-label {
            color: var(--muted);
            font-size: 12px;
            margin-bottom: 7px;
        }

        .conversion-value {
            font-size: 15px;
            font-weight: 700;
            word-break: break-word;
        }

        .money {
            font-size: 19px;
        }

        /* =========================================================
           INFORMACIÓN DE USUARIO
        ========================================================= */

        .user-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 15px;
        }

        /* =========================================================
           METADATA
        ========================================================= */

        .metadata-box {
            margin-top: 20px;
            padding: 18px;
            background: var(--card-soft);
            border: 1px solid var(--border);
            border-radius: 9px;
            overflow-x: auto;
        }

        .metadata-box pre {
            margin: 0;
            white-space: pre-wrap;
            word-break: break-word;
            font-family: Consolas, "Courier New", monospace;
            font-size: 12px;
            line-height: 1.6;
            color: var(--text);
        }

        /* =========================================================
           RESPONSIVE
        ========================================================= */

        @media (max-width: 800px) {

            .header {
                min-height: 82px;
                padding: 15px 18px;
                gap: 15px;
            }

            .brand-text h1 {
                font-size: 16px;
            }

            .brand-text p {
                display: none;
            }

            .user-info {
                display: none;
            }

            .container {
                padding: 25px 18px 40px;
            }

            .page-title {
                align-items: flex-start;
                flex-direction: column;
            }

            .info-grid,
            .conversion-grid,
            .user-grid {
                grid-template-columns: 1fr;
            }

            .card-header {
                align-items: flex-start;
                flex-direction: column;
            }
        }
    </style>
</head>

<body>

<div class="page">

    {{-- =========================================================
         HEADER
    ========================================================== --}}

    <header class="header">

        <div class="brand">

            {{-- Logo institucional --}}
            <div class="brand-logo-container">
                @if (file_exists(public_path('images/logo.png')))
                    <img
                        src="{{ asset('images/logo.png') }}"
                        alt="Comité de Estudios Médicos"
                    >
                @endif
            </div>

            <div class="brand-divider"></div>

            <div class="brand-text">
                <h1>COMITÉ DE ESTUDIOS MÉDICOS</h1>

                <p>
                    Generador de Consolidado · Registro de actividad
                </p>
            </div>

        </div>


        <div class="header-right">

            <div class="user-info">

                <div class="user-name">
                    {{ auth()->user()->name }}
                </div>

                <div class="user-role">
                    {{ auth()->user()->role === 'admin'
                        ? 'Administrador'
                        : 'Usuario' }}
                </div>

            </div>


            <button
                type="button"
                class="theme-button"
                data-theme-toggle
                title="Cambiar tema"
                aria-label="Cambiar tema"
            >
                ☀️
            </button>


            <form
                method="POST"
                action="{{ route('logout') }}"
            >
                @csrf

                <button
                    type="submit"
                    class="logout-button"
                >
                    Cerrar sesión
                </button>

            </form>

        </div>

    </header>


    {{-- =========================================================
         CONTENIDO
    ========================================================== --}}

    <main class="container">


        <div class="page-title">

            <div>

                <h2>
                    📋 Detalle de actividad
                </h2>

                <p>
                    Información completa de la acción registrada.
                </p>

            </div>


            <a
                href="{{ route('activity-logs.index') }}"
                class="back-button"
            >
                ← Registro de actividad
            </a>

        </div>


        {{-- =====================================================
             ACTIVIDAD
        ====================================================== --}}

        <section class="card">

            <div class="card-header">

                <div class="activity-title">

                    @switch($activityLog->action)

                        @case('login')
                            🔐 Inicio de sesión
                            @break

                        @case('logout')
                            🚪 Cierre de sesión
                            @break

                        @case('conversion')
                            📊 Conversión de archivos
                            @break

                        @case('user_created')
                            👤 Usuario creado
                            @break

                        @case('user_updated')
                            ✏️ Usuario actualizado
                            @break

                        @case('user_status_changed')
                            🔄 Estado de usuario cambiado
                            @break

                        @case('user_deleted')
                            🗑️ Usuario eliminado
                            @break

                        @default
                            {{ $activityLog->action }}

                    @endswitch

                </div>


                <span class="action-badge">
                    {{ $activityLog->action }}
                </span>

            </div>


            <div class="card-body">


                {{-- =================================================
                     INFORMACIÓN GENERAL
                ================================================== --}}

                <div class="info-grid">


                    <div class="info-item">

                        <div class="info-label">
                            Usuario
                        </div>

                        <div class="info-value">

                            @if ($activityLog->user)

                                {{ $activityLog->user->name }}

                            @else

                                Usuario eliminado

                            @endif

                        </div>

                    </div>


                    <div class="info-item">

                        <div class="info-label">
                            Correo electrónico
                        </div>

                        <div class="info-value">

                            @if ($activityLog->user)

                                {{ $activityLog->user->email }}

                            @else

                                —

                            @endif

                        </div>

                    </div>


                    <div class="info-item">

                        <div class="info-label">
                            Fecha y hora
                        </div>

                        <div class="info-value normal">

                            {{ $activityLog->created_at->format('d/m/Y H:i:s') }}

                        </div>

                    </div>


                    <div class="info-item">

                        <div class="info-label">
                            Dirección IP
                        </div>

                        <div class="info-value normal">

                            {{ $activityLog->ip_address ?? '—' }}

                        </div>

                    </div>


                    <div class="info-item">

                        <div class="info-label">
                            Descripción
                        </div>

                        <div class="info-value normal">

                            {{ $activityLog->description ?? '—' }}

                        </div>

                    </div>


                    <div class="info-item">

                        <div class="info-label">
                            Navegador / dispositivo
                        </div>

                        <div class="info-value normal">

                            {{ $activityLog->user_agent ?? '—' }}

                        </div>

                    </div>

                </div>


                {{-- =================================================
                     METADATA
                ================================================== --}}

                @php

                    $metadata = $activityLog->metadata ?? [];

                    if (is_string($metadata)) {
                        $metadata = json_decode($metadata, true) ?? [];
                    }

                @endphp


                {{-- =================================================
                     DETALLE DE CONVERSIÓN
                ================================================== --}}

                @if ($activityLog->action === 'conversion')

                    @php
                        $subsidiadoFiles = $metadata['subsidiado_files']
                            ?? [$metadata['subsidiado_file'] ?? '—'];
                        $contributivoFiles = $metadata['contributivo_files']
                            ?? [$metadata['contributivo_file'] ?? '—'];
                    @endphp

                    <h3 class="section-title">
                        📊 Detalles de la conversión
                    </h3>


                    <div class="conversion-grid">


                        <div class="conversion-item">

                            <div class="conversion-label">
                                Lote Subsidiado
                            </div>

                            <div class="conversion-value">

                                {{ implode(', ', $subsidiadoFiles) }}

                            </div>

                        </div>


                        <div class="conversion-item">

                            <div class="conversion-label">
                                Lote Contributivo
                            </div>

                            <div class="conversion-value">

                                {{ implode(', ', $contributivoFiles) }}

                            </div>

                        </div>


                        <div class="conversion-item">

                            <div class="conversion-label">
                                Excel generado
                            </div>

                            <div class="conversion-value">

                                {{ $metadata['excel_file'] ?? '—' }}

                            </div>

                        </div>


                        <div class="conversion-item">

                            <div class="conversion-label">
                                Valor administrativo
                            </div>

                            <div class="conversion-value money">

                                @if (isset($metadata['valor_administrativo']))

                                    ${{ number_format(
                                        (float) $metadata['valor_administrativo'],
                                        0,
                                        ',',
                                        '.'
                                    ) }}

                                @else

                                    —

                                @endif

                            </div>

                        </div>

                    </div>

                @endif


                {{-- =================================================
                     DETALLE DE USUARIO CREADO
                ================================================== --}}

                @if ($activityLog->action === 'user_created')

                    <h3 class="section-title">
                        👤 Información del usuario creado
                    </h3>


                    <div class="user-grid">

                        <div class="conversion-item">

                            <div class="conversion-label">
                                Nombre
                            </div>

                            <div class="conversion-value">
                                {{ $metadata['created_user_name'] ?? '—' }}
                            </div>

                        </div>


                        <div class="conversion-item">

                            <div class="conversion-label">
                                Correo electrónico
                            </div>

                            <div class="conversion-value">
                                {{ $metadata['created_user_email'] ?? '—' }}
                            </div>

                        </div>


                        <div class="conversion-item">

                            <div class="conversion-label">
                                Rol
                            </div>

                            <div class="conversion-value">
                                {{ ($metadata['role'] ?? '') === 'admin'
                                    ? 'Administrador'
                                    : 'Usuario' }}
                            </div>

                        </div>


                        <div class="conversion-item">

                            <div class="conversion-label">
                                Estado
                            </div>

                            <div class="conversion-value">

                                {{ !empty($metadata['active'])
                                    ? 'Activo'
                                    : 'Inactivo' }}

                            </div>

                        </div>

                    </div>

                @endif


                {{-- =================================================
                     DETALLE DE USUARIO ACTUALIZADO
                ================================================== --}}

                @if ($activityLog->action === 'user_updated')

                    <h3 class="section-title">
                        ✏️ Cambios realizados
                    </h3>


                    @if (!empty($metadata['old_data']) || !empty($metadata['new_data']))

                        <div class="user-grid">

                            <div class="conversion-item">

                                <div class="conversion-label">
                                    Datos anteriores
                                </div>

                                <div class="conversion-value">

                                    <pre style="margin:0; white-space:pre-wrap; font-size:12px;">{{ json_encode(
                                        $metadata['old_data'] ?? [],
                                        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
                                    ) }}</pre>

                                </div>

                            </div>


                            <div class="conversion-item">

                                <div class="conversion-label">
                                    Datos nuevos
                                </div>

                                <div class="conversion-value">

                                    <pre style="margin:0; white-space:pre-wrap; font-size:12px;">{{ json_encode(
                                        $metadata['new_data'] ?? [],
                                        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
                                    ) }}</pre>

                                </div>

                            </div>

                        </div>

                    @endif

                @endif


                {{-- =================================================
                     DETALLE CAMBIO DE ESTADO
                ================================================== --}}

                @if ($activityLog->action === 'user_status_changed')

                    <h3 class="section-title">
                        🔄 Cambio de estado
                    </h3>


                    <div class="conversion-grid">

                        <div class="conversion-item">

                            <div class="conversion-label">
                                Usuario
                            </div>

                            <div class="conversion-value">
                                {{ $metadata['user_name'] ?? '—' }}
                            </div>

                        </div>


                        <div class="conversion-item">

                            <div class="conversion-label">
                                Estado anterior
                            </div>

                            <div class="conversion-value">

                                {{ !empty($metadata['old_active'])
                                    ? 'Activo'
                                    : 'Inactivo' }}

                            </div>

                        </div>


                        <div class="conversion-item">

                            <div class="conversion-label">
                                Estado nuevo
                            </div>

                            <div class="conversion-value">

                                {{ !empty($metadata['new_active'])
                                    ? 'Activo'
                                    : 'Inactivo' }}

                            </div>

                        </div>


                        <div class="conversion-item">

                            <div class="conversion-label">
                                Acción
                            </div>

                            <div class="conversion-value">
                                {{ ucfirst($metadata['status'] ?? '—') }}
                            </div>

                        </div>

                    </div>

                @endif


                {{-- =================================================
                     DETALLE USUARIO ELIMINADO
                ================================================== --}}

                @if ($activityLog->action === 'user_deleted')

                    <h3 class="section-title">
                        🗑️ Usuario eliminado
                    </h3>


                    @php
                        $deletedUser = $metadata['deleted_user'] ?? [];
                    @endphp


                    <div class="user-grid">

                        <div class="conversion-item">

                            <div class="conversion-label">
                                Nombre
                            </div>

                            <div class="conversion-value">
                                {{ $deletedUser['name'] ?? '—' }}
                            </div>

                        </div>


                        <div class="conversion-item">

                            <div class="conversion-label">
                                Correo electrónico
                            </div>

                            <div class="conversion-value">
                                {{ $deletedUser['email'] ?? '—' }}
                            </div>

                        </div>


                        <div class="conversion-item">

                            <div class="conversion-label">
                                Rol
                            </div>

                            <div class="conversion-value">

                                {{ ($deletedUser['role'] ?? '') === 'admin'
                                    ? 'Administrador'
                                    : 'Usuario' }}

                            </div>

                        </div>


                        <div class="conversion-item">

                            <div class="conversion-label">
                                Estado antes de eliminar
                            </div>

                            <div class="conversion-value">

                                {{ !empty($deletedUser['active'])
                                    ? 'Activo'
                                    : 'Inactivo' }}

                            </div>

                        </div>

                    </div>

                @endif


                {{-- =================================================
                     INFORMACIÓN TÉCNICA
                ================================================== --}}

                @if (!empty($metadata))

                    <h3 class="section-title">
                        Información técnica
                    </h3>


                    <div class="metadata-box">

                        <pre>{{ json_encode(
                            $metadata,
                            JSON_PRETTY_PRINT |
                            JSON_UNESCAPED_UNICODE |
                            JSON_UNESCAPED_SLASHES
                        ) }}</pre>

                    </div>

                @endif

            </div>

        </section>

    </main>

</div>


<script src="{{ asset('js/theme.js') }}"></script>

@include('navigation')
</body>
</html>
