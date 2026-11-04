<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Usuarios | JSON → Excel</title>

    <link rel="icon" href="{{ asset('favicon.png') }}?v=2" type="image/png">
    <link rel="shortcut icon" href="{{ asset('favicon.ico') }}?v=2">

    <script>
        (function () {
            const theme =
                localStorage.getItem('json-excel-theme') || 'dark';

            document.documentElement.setAttribute(
                'data-theme',
                theme
            );
        })();
    </script>

    <style>

        :root {
            --bg: #0d1218;
            --header: #151c25;
            --card: #19222d;
            --card-2: #202b37;
            --border: #334252;
            --text: #f4f7fa;
            --muted: #8fa9c2;
            --green: #16a34a;
            --green-hover: #15803d;
            --red: #ef4444;
            --orange: #f59e0b;
            --blue: #3b82f6;
            --purple: #7c3aed;
        }

        html[data-theme="light"] {
            --bg: #f4f7f6;
            --header: #ffffff;
            --card: #ffffff;
            --card-2: #f7f9fb;
            --border: #d8e0e5;
            --text: #142033;
            --muted: #60758c;
            --green: #16a34a;
            --green-hover: #15803d;
            --red: #ef4444;
            --orange: #f59e0b;
            --blue: #3b82f6;
            --purple: #7c3aed;
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            margin: 0;
            padding: 0;
            min-height: 100%;
        }

        body {
            font-family:
                Inter,
                ui-sans-serif,
                system-ui,
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                sans-serif;

            background: var(--bg);
            color: var(--text);
            transition:
                background .25s ease,
                color .25s ease;
        }

        /* =====================================================
           ENCABEZADO PRINCIPAL
        ====================================================== */

        .site-header {
            width: 100%;
            background: var(--header);
            border-bottom: 1px solid var(--border);
        }

        .header-inner {
            width: min(1360px, calc(100% - 48px));
            min-height: 126px;
            margin: 0 auto;

            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 30px;
        }

        .header-brand {
            display: flex;
            align-items: center;
            gap: 28px;
        }

        .institution {
            width: 210px;
            flex: 0 0 210px;
        }

        .institution-title {
            margin: 0;

            font-size: 23px;
            line-height: 1.05;
            letter-spacing: 7px;
            font-weight: 500;

            color: var(--text);
        }

        .header-divider {
            width: 1px;
            height: 66px;
            background: var(--border);
        }

        .section-title h1 {
            margin: 0;

            font-size: 34px;
            line-height: 1.1;
            font-weight: 800;

            color: var(--text);
        }

        .section-title p {
            margin: 7px 0 0;

            font-size: 17px;
            color: var(--muted);
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .theme-button {
            width: 42px !important;
            height: 42px !important;
            min-width: 42px !important;
            min-height: 42px !important;

            padding: 0 !important;

            display: flex !important;
            align-items: center !important;
            justify-content: center !important;

            border: 1px solid var(--border);
            border-radius: 50%;

            background: transparent;
            color: var(--text);

            font-size: 17px !important;
            line-height: 1 !important;

            cursor: pointer;

            transition:
                background .2s ease,
                transform .2s ease;
        }

        .theme-button:hover {
            background: var(--card-2);
            transform: scale(1.04);
        }

        .user-name {
            color: var(--muted);
            font-size: 15px;
            font-weight: 600;
        }

        .logout-button {
            min-height: 48px;

            padding: 0 20px;

            border: 0;
            border-radius: 9px;

            background: #ef4444;
            color: white;

            font-family: inherit;
            font-size: 14px;
            font-weight: 800;

            cursor: pointer;
        }

        /* =====================================================
           CONTENIDO
        ====================================================== */

        .page {
            min-height: calc(100vh - 127px);
            background: var(--bg);
        }

        .container {
            width: min(1360px, calc(100% - 48px));
            margin: 0 auto;
            padding: 38px 0 60px;
        }

        .top-navigation {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 20px;
        }

        .back-button {
            min-height: 44px;

            padding: 0 18px;

            display: inline-flex;
            align-items: center;
            justify-content: center;

            border: 1px solid var(--border);
            border-radius: 9px;

            background: var(--card);
            color: var(--text);

            text-decoration: none;

            font-size: 14px;
            font-weight: 700;
        }

        .back-button:hover {
            background: var(--card-2);
        }

        /* =====================================================
           TARJETA
        ====================================================== */

        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 16px;
            overflow: hidden;

            box-shadow:
                0 18px 40px rgba(0, 0, 0, .10);
        }

        .card-header {
            min-height: 90px;

            padding: 22px 24px;

            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;

            border-bottom: 1px solid var(--border);
        }

        .card-title h2 {
            margin: 0;

            font-size: 22px;
            font-weight: 800;
            color: var(--text);
        }

        .card-title p {
            margin: 5px 0 0;

            font-size: 14px;
            color: var(--muted);
        }

        .card-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .count {
            padding: 8px 13px;

            border-radius: 20px;

            background: rgba(22, 163, 74, .12);
            color: var(--green);

            font-size: 13px;
            font-weight: 800;
        }

        .new-button {
            min-height: 44px;

            padding: 0 17px;

            display: inline-flex;
            align-items: center;
            justify-content: center;

            border-radius: 9px;

            background: var(--green);
            color: white;

            text-decoration: none;

            font-size: 14px;
            font-weight: 800;
        }

        .new-button:hover {
            background: var(--green-hover);
        }

        /* =====================================================
           MENSAJES
        ====================================================== */

        .message {
            margin: 20px 24px 0;

            padding: 13px 15px;

            border-radius: 9px;

            font-size: 14px;
            font-weight: 700;
        }

        .message-success {
            background: rgba(22, 163, 74, .10);
            border: 1px solid rgba(22, 163, 74, .30);
            color: var(--green);
        }

        .message-error {
            background: rgba(239, 68, 68, .10);
            border: 1px solid rgba(239, 68, 68, .30);
            color: var(--red);
        }

        /* =====================================================
           TABLA
        ====================================================== */

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead {
            background: var(--card-2);
        }

        th {
            padding: 15px 20px;

            text-align: left;

            color: var(--muted);

            font-size: 12px;
            font-weight: 800;
            letter-spacing: .6px;
            text-transform: uppercase;

            border-bottom: 1px solid var(--border);
        }

        td {
            padding: 17px 20px;

            color: var(--text);

            font-size: 14px;

            border-bottom: 1px solid var(--border);
        }

        tbody tr:last-child td {
            border-bottom: 0;
        }

        tbody tr:hover {
            background: var(--card-2);
        }

        .name {
            font-weight: 800;
        }

        .email {
            color: var(--muted);
        }

        /* =====================================================
           ROLES
        ====================================================== */

        .role {
            display: inline-flex;
            align-items: center;
            gap: 7px;

            padding: 7px 11px;

            border-radius: 20px;

            font-size: 12px;
            font-weight: 800;
        }

        .role-admin {
            background: rgba(245, 158, 11, .14);
            color: #f59e0b;
        }

        .role-user {
            background: rgba(124, 58, 237, .10);
            color: #7c3aed;
        }

        /* =====================================================
           ESTADO
        ====================================================== */

        .status {
            display: inline-flex;
            align-items: center;
            gap: 8px;

            padding: 7px 11px;

            border-radius: 20px;

            font-size: 12px;
            font-weight: 800;
        }

        .status-active {
            background: rgba(22, 163, 74, .12);
            color: var(--green);
        }

        .status-inactive {
            background: rgba(239, 68, 68, .10);
            color: var(--red);
        }

        .dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }

        .dot-green {
            background: var(--green);
        }

        .dot-red {
            background: var(--red);
        }

        /* =====================================================
           ACCIONES
        ====================================================== */

        .actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .action-button {
            min-height: 38px;

            padding: 0 12px;

            display: inline-flex;
            align-items: center;
            justify-content: center;

            border-radius: 8px;

            font-family: inherit;
            font-size: 12px;
            font-weight: 800;

            text-decoration: none;

            cursor: pointer;
        }

        .edit-button {
            border: 1px solid rgba(59, 130, 246, .30);
            background: rgba(59, 130, 246, .08);
            color: var(--blue);
        }

        .toggle-button {
            border: 1px solid rgba(245, 158, 11, .30);
            background: rgba(245, 158, 11, .08);
            color: var(--orange);
        }

        .delete-button {
            border: 1px solid rgba(239, 68, 68, .30);
            background: rgba(239, 68, 68, .08);
            color: var(--red);
        }

        .action-button:hover {
            filter: brightness(1.08);
        }

        .authentik-button {
            border: 1px solid rgba(22, 163, 74, .30);
            background: rgba(22, 163, 74, .08);
            color: var(--green);
        }

        .link-button {
            border: 1px solid rgba(124, 58, 237, .30);
            background: rgba(124, 58, 237, .08);
            color: var(--purple);
        }

        /* =====================================================
           AUTHENTIK
        ====================================================== */

        .cedula {
            font-family: ui-monospace, SFMono-Regular, Consolas, monospace;
            font-weight: 700;
        }

        .authentik-status {
            display: inline-flex;
            align-items: center;
            gap: 7px;

            padding: 7px 11px;

            border-radius: 20px;

            font-size: 12px;
            font-weight: 800;
            white-space: nowrap;
        }

        .authentik-provisioned {
            background: rgba(22, 163, 74, .12);
            color: var(--green);
        }

        .authentik-linked {
            background: rgba(59, 130, 246, .10);
            color: var(--blue);
        }

        .authentik-pending,
        .authentik-unlinked {
            background: rgba(143, 169, 194, .14);
            color: var(--muted);
        }

        .authentik-conflict,
        .authentik-failed {
            background: rgba(239, 68, 68, .10);
            color: var(--red);
        }

        .authentik-detail {
            margin-top: 7px;
            max-width: 320px;

            color: var(--muted);

            font-size: 12px;
            line-height: 1.45;
        }

        .sync-problem {
            color: var(--red);
        }

        .activation-panel {
            margin: 20px 24px 0;
            padding: 16px 18px;

            border: 1px solid rgba(124, 58, 237, .35);
            border-radius: 10px;

            background: rgba(124, 58, 237, .07);
        }

        .activation-panel h3 {
            margin: 0 0 6px;
            font-size: 15px;
        }

        .activation-panel p {
            margin: 0 0 12px;

            color: var(--muted);

            font-size: 13px;
            line-height: 1.5;
        }

        .activation-row {
            display: flex;
            gap: 8px;
        }

        .activation-row input,
        .modal input {
            flex: 1;
            min-width: 0;
            min-height: 40px;

            padding: 0 12px;

            border: 1px solid var(--border);
            border-radius: 8px;

            background: var(--card-2);
            color: var(--text);

            font-family: ui-monospace, SFMono-Regular, Consolas, monospace;
            font-size: 13px;
        }

        .modal input {
            width: 100%;
            margin-top: 14px;
        }

        .modal-confirm {
            min-height: 42px;

            padding: 0 16px;

            border: 0;
            border-radius: 8px;

            background: var(--purple);
            color: white;

            font-family: inherit;
            font-weight: 800;

            cursor: pointer;
        }

        /* =====================================================
           MODAL
        ====================================================== */

        .modal-overlay {
            position: fixed;
            inset: 0;

            display: none;
            align-items: center;
            justify-content: center;

            padding: 20px;

            background: rgba(0, 0, 0, .62);

            z-index: 9999;
        }

        .modal-overlay.show {
            display: flex;
        }

        .modal {
            width: min(440px, 100%);

            padding: 28px;

            border: 1px solid var(--border);
            border-radius: 16px;

            background: var(--card);

            box-shadow:
                0 30px 80px rgba(0, 0, 0, .30);
        }

        .modal-icon {
            width: 48px;
            height: 48px;

            display: flex;
            align-items: center;
            justify-content: center;

            margin-bottom: 17px;

            border-radius: 12px;

            background: rgba(239, 68, 68, .10);

            font-size: 22px;
        }

        .modal h3 {
            margin: 0;

            font-size: 20px;
            font-weight: 800;
        }

        .modal p {
            margin: 9px 0 0;

            color: var(--muted);

            font-size: 14px;
            line-height: 1.5;
        }

        .modal-actions {
            margin-top: 24px;

            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .modal-cancel {
            min-height: 42px;

            padding: 0 16px;

            border: 1px solid var(--border);
            border-radius: 8px;

            background: transparent;
            color: var(--muted);

            font-family: inherit;
            font-weight: 700;

            cursor: pointer;
        }

        .modal-delete {
            min-height: 42px;

            padding: 0 16px;

            border: 0;
            border-radius: 8px;

            background: var(--red);
            color: white;

            font-family: inherit;
            font-weight: 800;

            cursor: pointer;
        }

        /* =====================================================
           RESPONSIVE
        ====================================================== */

        @media (max-width: 900px) {

            .header-inner {
                width: min(100% - 30px, 1360px);
            }

            .institution {
                width: 170px;
                flex-basis: 170px;
            }

            .institution-title {
                font-size: 18px;
                letter-spacing: 5px;
            }

            .section-title h1 {
                font-size: 26px;
            }

            .section-title p {
                font-size: 14px;
            }

        }

        @media (max-width: 700px) {

            .header-inner {
                min-height: auto;
                padding: 20px 0;
                align-items: flex-start;
            }

            .header-brand {
                gap: 15px;
            }

            .institution {
                width: 125px;
                flex-basis: 125px;
            }

            .institution-title {
                font-size: 14px;
                letter-spacing: 3px;
            }

            .header-divider {
                height: 55px;
            }

            .section-title h1 {
                font-size: 20px;
            }

            .section-title p {
                font-size: 12px;
            }

            .user-name {
                display: none;
            }

            .container {
                width: min(100% - 28px, 1360px);
                padding-top: 25px;
            }

            .card-header {
                align-items: flex-start;
                flex-direction: column;
            }

            .card-actions {
                width: 100%;
                justify-content: space-between;
            }

        }

    </style>

</head>


<body>


<header class="site-header">

    <div class="header-inner">


        <div class="header-brand">


            <div class="institution">

                <div class="institution-title">
                    COMITÉ DE<br>
                    ESTUDIOS<br>
                    MÉDICOS
                </div>

            </div>


            <div class="header-divider"></div>


            <div class="section-title">

                <h1>
                    Administración de usuarios
                </h1>

                <p>
                    Cuentas de SIRUTA, permisos y acceso con Authentik
                </p>

            </div>


        </div>


        <div class="header-actions">


            <button
                type="button"
                class="theme-button"
                data-theme-toggle
                aria-label="Cambiar tema"
                title="Cambiar tema"
            >
                ☀️
            </button>


            <span class="user-name">
                {{ auth()->user()->name }}
            </span>


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


    </div>

</header>



<div class="page">


    <main class="container">


        <div class="top-navigation">

            <a
                href="{{ route('dashboard') }}"
                class="back-button"
            >
                ← Volver al dashboard
            </a>

        </div>


        <section class="card">


            <div class="card-header">


                <div class="card-title">

                    <h2>
                        Usuarios del sistema
                    </h2>

                    <p>
                        Administra las cuentas, roles y estados de acceso.
                    </p>

                </div>


                <div class="card-actions">

                    <span class="count">
                        {{ $users->count() }} usuarios
                    </span>


                    <a
                        href="{{ route('users.create') }}"
                        class="new-button"
                    >
                        + Nuevo usuario
                    </a>

                </div>


            </div>



            @if (session('success'))

                <div class="message message-success">
                    ✓ {{ session('success') }}
                </div>

            @endif


            @if (session('error'))

                <div class="message message-error">
                    {{ session('error') }}
                </div>

            @endif


            @if (! $authentikConfigured)

                <div class="message message-error">
                    El aprovisionamiento en Authentik no está configurado: no se pueden crear ni vincular usuarios.
                </div>

            @endif


            @isset($activationLink)

                <div class="activation-panel">

                    <h3>
                        Enlace de activación para {{ $activationUser->name }}
                    </h3>

                    <p>
                        Se muestra una sola vez y vence en poco tiempo. Quien lo abra podrá definir la contraseña
                        de la cuenta {{ $activationUser->cedula }} en Authentik: entrégalo solo a esa persona, en
                        persona o por un canal seguro. Después, la persona ingresa a SIRUTA con su cédula y esa contraseña.
                    </p>

                    <div class="activation-row">

                        <input
                            type="text"
                            id="activationLink"
                            value="{{ $activationLink }}"
                            readonly
                            autocomplete="off"
                            aria-label="Enlace de activación"
                        >

                        <button
                            type="button"
                            class="action-button link-button"
                            onclick="copyActivationLink()"
                        >
                            Copiar
                        </button>

                    </div>

                </div>

            @endisset



            <div class="table-wrapper">

                <table>

                    <thead>

                        <tr>

                            <th>
                                Usuario
                            </th>

                            <th>
                                Cédula
                            </th>

                            <th>
                                Rol
                            </th>

                            <th>
                                Estado en SIRUTA
                            </th>

                            <th>
                                Authentik
                            </th>

                            <th>
                                Acciones
                            </th>

                        </tr>

                    </thead>


                    <tbody>

                        @forelse ($users as $user)

                            <tr>

                                <td>

                                    <div class="name">
                                        {{ $user->name }}
                                    </div>

                                    @if ($user->email)

                                        <div class="email">
                                            {{ $user->email }}
                                        </div>

                                    @endif

                                </td>


                                <td>

                                    @if ($user->cedula)

                                        <span class="cedula">
                                            {{ $user->cedula }}
                                        </span>

                                    @else

                                        <span class="email">
                                            Sin cédula
                                        </span>

                                    @endif

                                </td>


                                <td>

                                    @if ($user->role === 'admin')

                                        <span class="role role-admin">
                                            👑 Administrador
                                        </span>

                                    @else

                                        <span class="role role-user">
                                            👤 Usuario
                                        </span>

                                    @endif

                                </td>


                                <td>

                                    @if ($user->active)

                                        <span class="status status-active">

                                            <span class="dot dot-green"></span>

                                            Activo

                                        </span>

                                    @else

                                        <span class="status status-inactive">

                                            <span class="dot dot-red"></span>

                                            Inactivo

                                        </span>

                                    @endif

                                </td>


                                <td>

                                    <span class="authentik-status authentik-{{ $user->authentik_status->value }}">
                                        {{ $user->authentik_status->label() }}
                                    </span>

                                    @if ($user->authentik_sync_error)

                                        <div class="authentik-detail sync-problem">
                                            {{ $user->authentik_sync_error }}
                                        </div>

                                    @elseif ($user->canReceiveActivationLink())

                                        <div class="authentik-detail">
                                            Pendiente de activación: aún no ha ingresado a SIRUTA.
                                        </div>

                                    @elseif ($user->authentik_first_login_at)

                                        <div class="authentik-detail">
                                            Primer ingreso: {{ $user->authentik_first_login_at->format('Y-m-d H:i') }}
                                        </div>

                                    @elseif ($user->authentik_status === \App\Models\AuthentikStatus::Unlinked)

                                        <div class="authentik-detail">
                                            No puede ingresar hasta aprovisionar o vincular su cuenta.
                                        </div>

                                    @endif

                                </td>


                                <td>

                                    <div class="actions">


                                        <a
                                            href="{{ route('users.edit', $user) }}"
                                            class="action-button edit-button"
                                        >
                                            ✏ Editar
                                        </a>


                                        @if ($authentikConfigured && $user->canRetryAuthentikProvisioning())

                                            <form
                                                method="POST"
                                                action="{{ route('users.authentik.provision', $user) }}"
                                            >

                                                @csrf

                                                <button
                                                    type="submit"
                                                    class="action-button authentik-button"
                                                >
                                                    {{ $user->authentik_status === \App\Models\AuthentikStatus::Unlinked ? 'Aprovisionar en Authentik' : 'Reintentar aprovisionamiento' }}
                                                </button>

                                            </form>

                                        @endif


                                        @if ($authentikConfigured && $user->canReceiveActivationLink())

                                            <form
                                                method="POST"
                                                action="{{ route('users.authentik.activation-link', $user) }}"
                                            >

                                                @csrf

                                                <button
                                                    type="submit"
                                                    class="action-button link-button"
                                                >
                                                    Enlace de activación
                                                </button>

                                            </form>

                                        @endif


                                        @if ($authentikConfigured
                                            && $user->authentik_status === \App\Models\AuthentikStatus::Conflict
                                            && $user->canLinkExistingAuthentikAccount())

                                            <button
                                                type="button"
                                                class="action-button link-button"
                                                onclick="openLinkModal(
                                                    '{{ route('users.authentik.link', $user) }}',
                                                    @js($user->name)
                                                )"
                                            >
                                                Vincular cuenta existente
                                            </button>

                                        @endif


                                        @if ($user->id !== auth()->id() && $user->canUnlinkAuthentikAccount())

                                            <button
                                                type="button"
                                                class="action-button toggle-button"
                                                onclick="openUnlinkModal(
                                                    '{{ route('users.authentik.unlink', $user) }}',
                                                    @js($user->name)
                                                )"
                                            >
                                                Desvincular Authentik
                                            </button>

                                        @endif


                                        @if ($user->id !== auth()->id())

                                            <form
                                                method="POST"
                                                action="{{ route('users.toggle-active', $user) }}"
                                            >

                                                @csrf

                                                @method('PATCH')

                                                <button
                                                    type="submit"
                                                    class="action-button toggle-button"
                                                >
                                                    {{ $user->active ? 'Desactivar' : 'Activar' }}
                                                </button>

                                            </form>


                                            @if ($user->canBeDeleted())

                                                <button
                                                    type="button"
                                                    class="action-button delete-button"
                                                    onclick="openDeleteModal(
                                                        '{{ route('users.destroy', $user) }}',
                                                        @js($user->name)
                                                    )"
                                                >
                                                    🗑 Eliminar
                                                </button>

                                            @endif

                                        @endif


                                    </div>

                                </td>

                            </tr>

                        @empty

                            <tr>

                                <td
                                    colspan="6"
                                    style="text-align:center; padding:40px;"
                                >
                                    No hay usuarios registrados.
                                </td>

                            </tr>

                        @endforelse

                    </tbody>

                </table>

            </div>


        </section>


    </main>


</div>



<!-- =========================================================
     MODAL DE ELIMINACIÓN
========================================================== -->

<div
    id="deleteModal"
    class="modal-overlay"
>

    <div class="modal">


        <div class="modal-icon">
            🗑
        </div>


        <h3>
            ¿Eliminar usuario?
        </h3>


        <p id="deleteMessage">
            Esta acción eliminará permanentemente esta cuenta.
        </p>


        <div class="modal-actions">


            <button
                type="button"
                class="modal-cancel"
                onclick="closeDeleteModal()"
            >
                Cancelar
            </button>


            <form
                id="deleteForm"
                method="POST"
            >

                @csrf

                @method('DELETE')

                <button
                    type="submit"
                    class="modal-delete"
                >
                    Sí, eliminar
                </button>

            </form>


        </div>


    </div>

</div>



<!-- =========================================================
     MODAL DE VINCULACIÓN ADMINISTRATIVA
========================================================== -->

<div
    id="linkModal"
    class="modal-overlay"
>

    <div class="modal">


        <div class="modal-icon">
            🔗
        </div>


        <h3>
            ¿Vincular cuenta existente?
        </h3>


        <p id="linkMessage">
            La cédula ya existe en Authentik en una cuenta que SIRUTA no creó.
        </p>


        <form
            id="linkForm"
            method="POST"
        >

            @csrf

            <input
                type="text"
                name="cedula_confirmation"
                placeholder="Escribe la cédula para confirmar"
                inputmode="numeric"
                autocomplete="off"
                required
            >

            <div class="modal-actions">

                <button
                    type="button"
                    class="modal-cancel"
                    onclick="closeModal('linkModal')"
                >
                    Cancelar
                </button>

                <button
                    type="submit"
                    class="modal-confirm"
                >
                    Vincular sin modificarla
                </button>

            </div>

        </form>


    </div>

</div>



<!-- =========================================================
     MODAL DE DESVINCULACIÓN
========================================================== -->

<div
    id="unlinkModal"
    class="modal-overlay"
>

    <div class="modal">


        <div class="modal-icon">
            ⛓
        </div>


        <h3>
            ¿Desvincular de Authentik?
        </h3>


        <p id="unlinkMessage">
            El usuario no podrá ingresar a SIRUTA hasta volver a vincularlo.
        </p>


        <div class="modal-actions">

            <button
                type="button"
                class="modal-cancel"
                onclick="closeModal('unlinkModal')"
            >
                Cancelar
            </button>

            <form
                id="unlinkForm"
                method="POST"
            >

                @csrf

                <button
                    type="submit"
                    class="modal-delete"
                >
                    Sí, desvincular
                </button>

            </form>

        </div>


    </div>

</div>



<script src="{{ asset('js/theme.js') }}"></script>


<script>

    function openDeleteModal(action, name) {

        const modal =
            document.getElementById('deleteModal');

        const form =
            document.getElementById('deleteForm');

        const message =
            document.getElementById('deleteMessage');

        form.action = action;

        message.textContent =
            'Vas a eliminar el usuario "' +
            name +
            '". Esta acción no se puede deshacer.';

        modal.classList.add('show');

    }


    function closeDeleteModal() {

        closeModal('deleteModal');

    }


    function closeModal(id) {

        document
            .getElementById(id)
            .classList.remove('show');

    }


    function openLinkModal(action, name) {

        document.getElementById('linkForm').action = action;

        document.getElementById('linkMessage').textContent =
            'La cédula de "' +
            name +
            '" ya existe en Authentik en una cuenta que SIRUTA no creó. ' +
            'Vincúlala solo si confirmaste que pertenece a esta persona: ' +
            'SIRUTA no cambia su contraseña, datos ni estado en Authentik.';

        document.getElementById('linkModal').classList.add('show');

    }


    function openUnlinkModal(action, name) {

        document.getElementById('unlinkForm').action = action;

        document.getElementById('unlinkMessage').textContent =
            '"' +
            name +
            '" no podrá ingresar a SIRUTA hasta volver a vincularlo. ' +
            'Su cuenta de Authentik no se modifica.';

        document.getElementById('unlinkModal').classList.add('show');

    }


    function copyActivationLink() {

        const input = document.getElementById('activationLink');

        input.select();

        if (navigator.clipboard) {
            navigator.clipboard.writeText(input.value);
        }

    }


    ['deleteModal', 'linkModal', 'unlinkModal'].forEach(function (id) {

        document
            .getElementById(id)
            .addEventListener('click', function (event) {

                if (event.target === this) {
                    closeModal(id);
                }

            });

    });


    document.addEventListener('keydown', function (event) {

        if (event.key === 'Escape') {
            ['deleteModal', 'linkModal', 'unlinkModal'].forEach(closeModal);
        }

    });

</script>


@include('navigation')
</body>

</html>
