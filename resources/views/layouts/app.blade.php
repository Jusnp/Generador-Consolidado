<!DOCTYPE html>
<html lang="es" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        @yield('title', 'Comité de Estudios Médicos')
    </title>

    <link rel="icon" href="{{ asset('favicon.png') }}?v=2" type="image/png">
    <link rel="shortcut icon" href="{{ asset('favicon.ico') }}?v=2">

    <style>
        /* =========================================================
           VARIABLES DE TEMA
        ========================================================= */

        :root {
            --bg-main: #0f141b;
            --bg-header: #151c25;
            --bg-card: #1b2430;
            --bg-card-secondary: #202c39;

            --border: #334354;
            --border-light: #3d4d5f;

            --text-primary: #f4f7fa;
            --text-secondary: #91abc4;
            --text-muted: #71869b;

            --green: #16a34a;
            --green-hover: #15803d;

            --red: #ef4444;
            --red-hover: #dc2626;

            --blue: #3b82f6;
            --purple: #7c3aed;

            --input-bg: #202c39;
            --input-border: #405266;

            --shadow: 0 20px 50px rgba(0, 0, 0, 0.25);
        }

        html[data-theme="light"] {
            --bg-main: #f4f7f6;
            --bg-header: #ffffff;
            --bg-card: #ffffff;
            --bg-card-secondary: #f8fafc;

            --border: #d8e0e7;
            --border-light: #cbd5df;

            --text-primary: #142033;
            --text-secondary: #58718c;
            --text-muted: #7890a5;

            --green: #16a34a;
            --green-hover: #15803d;

            --red: #ef4444;
            --red-hover: #dc2626;

            --blue: #2563eb;
            --purple: #7c3aed;

            --input-bg: #ffffff;
            --input-border: #cbd5e1;

            --shadow: 0 15px 40px rgba(15, 23, 42, 0.10);
        }

        /* =========================================================
           BASE
        ========================================================= */

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

            background: var(--bg-main);
            color: var(--text-primary);

            transition:
                background-color 0.25s ease,
                color 0.25s ease;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        button,
        input,
        select {
            font: inherit;
        }

        /* =========================================================
           ENCABEZADO GLOBAL
        ========================================================= */

        .app-header {
            width: 100%;
            min-height: 116px;

            background: var(--bg-header);
            border-bottom: 1px solid var(--border);

            transition:
                background-color 0.25s ease,
                border-color 0.25s ease;
        }

        .app-header-inner {
            width: min(1260px, calc(100% - 48px));
            min-height: 116px;

            margin: 0 auto;

            display: flex;
            align-items: center;
            justify-content: space-between;

            gap: 30px;
        }

        .brand-area {
            display: flex;
            align-items: center;
            gap: 28px;
            min-width: 0;
        }

        .brand {
            font-size: 22px;
            line-height: 1.15;
            font-weight: 700;
            letter-spacing: 0.28em;
            text-transform: uppercase;

            color: var(--text-primary);

            max-width: 260px;
        }

        .brand-divider {
            width: 1px;
            height: 64px;
            background: var(--border);
        }

        .header-title {
            min-width: 0;
        }

        .header-title h1 {
            margin: 0;

            font-size: 30px;
            line-height: 1.1;
            font-weight: 750;

            color: var(--text-primary);
        }

        .header-title p {
            margin: 7px 0 0;

            font-size: 16px;
            color: var(--text-secondary);
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 14px;
            flex-shrink: 0;
        }

        .theme-button {
            width: 6px;
            height: 6px;

            border: 1px solid var(--border);
            border-radius: 50%;

            background: transparent;
            color: var(--text-primary);

            display: flex;
            align-items: center;
            justify-content: center;

            cursor: pointer;

            font-size: 23px;

            transition:
                background-color 0.2s ease,
                border-color 0.2s ease,
                transform 0.2s ease;
        }

        .theme-button:hover {
            background: var(--bg-card-secondary);
            border-color: var(--border-light);
            transform: scale(1.04);
        }

        .header-user {
            color: var(--text-secondary);
            font-size: 15px;
            white-space: nowrap;
        }

        .logout-button {
            border: 0;
            border-radius: 8px;

            background: var(--red);
            color: #ffffff;

            padding: 13px 20px;

            font-weight: 700;
            font-size: 14px;

            cursor: pointer;

            transition:
                background-color 0.2s ease,
                transform 0.2s ease;
        }

        .logout-button:hover {
            background: var(--red-hover);
            transform: translateY(-1px);
        }

        /* =========================================================
           CONTENIDO
        ========================================================= */

        .app-main {
            width: min(1260px, calc(100% - 48px));
            margin: 0 auto;

            padding: 46px 0 70px;
        }

        /* =========================================================
           BOTÓN VOLVER
        ========================================================= */

        .back-button {
            display: inline-flex;
            align-items: center;
            gap: 7px;

            padding: 11px 16px;

            border: 1px solid var(--border);
            border-radius: 8px;

            background: var(--bg-card);
            color: var(--text-secondary);

            font-size: 14px;
            font-weight: 700;

            transition:
                background-color 0.2s ease,
                border-color 0.2s ease,
                color 0.2s ease,
                transform 0.2s ease;
        }

        .back-button:hover {
            background: var(--bg-card-secondary);
            border-color: var(--border-light);
            color: var(--text-primary);
            transform: translateY(-1px);
        }

        /* =========================================================
           TARJETAS
        ========================================================= */

        .card {
            background: var(--bg-card);

            border: 1px solid var(--border);
            border-radius: 16px;

            box-shadow: var(--shadow);

            transition:
                background-color 0.25s ease,
                border-color 0.25s ease;
        }

        /* =========================================================
           FORMULARIOS
        ========================================================= */

        .form-label {
            display: block;

            margin-bottom: 8px;

            color: var(--text-primary);

            font-size: 14px;
            font-weight: 700;
        }

        .required {
            color: #ef4444;
        }

        .form-input,
        .form-select {
            width: 100%;

            min-height: 48px;

            padding: 12px 14px;

            border: 1px solid var(--input-border);
            border-radius: 8px;

            background: var(--input-bg);
            color: var(--text-primary);

            outline: none;

            transition:
                border-color 0.2s ease,
                box-shadow 0.2s ease,
                background-color 0.25s ease,
                color 0.25s ease;
        }

        .form-input::placeholder {
            color: var(--text-muted);
        }

        .form-input:focus,
        .form-select:focus {
            border-color: var(--green);

            box-shadow:
                0 0 0 3px rgba(22, 163, 74, 0.12);
        }

        .form-help {
            margin-top: 6px;

            color: var(--text-secondary);

            font-size: 12px;
        }

        /* =========================================================
           BOTONES
        ========================================================= */

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;

            min-height: 44px;

            padding: 10px 18px;

            border-radius: 8px;

            font-size: 14px;
            font-weight: 700;

            cursor: pointer;

            border: 1px solid transparent;

            transition:
                background-color 0.2s ease,
                border-color 0.2s ease,
                color 0.2s ease,
                transform 0.2s ease;
        }

        .btn:hover {
            transform: translateY(-1px);
        }

        .btn-green {
            background: var(--green);
            color: #ffffff;
        }

        .btn-green:hover {
            background: var(--green-hover);
        }

        .btn-secondary {
            background: var(--bg-card-secondary);
            border-color: var(--border);
            color: var(--text-primary);
        }

        .btn-secondary:hover {
            border-color: var(--border-light);
        }

        .btn-red {
            background: var(--red);
            color: #ffffff;
        }

        .btn-red:hover {
            background: var(--red-hover);
        }

        /* =========================================================
           MENSAJES
        ========================================================= */

        .alert {
            border-radius: 9px;
            padding: 13px 15px;
            margin-bottom: 20px;

            font-size: 14px;
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.10);
            border: 1px solid rgba(239, 68, 68, 0.35);
            color: #ef4444;
        }

        .alert-success {
            background: rgba(22, 163, 74, 0.10);
            border: 1px solid rgba(22, 163, 74, 0.30);
            color: var(--green);
        }

        /* =========================================================
           RESPONSIVE
        ========================================================= */

        @media (max-width: 900px) {
            .app-header-inner {
                min-height: auto;
                padding: 22px 0;

                align-items: flex-start;
            }

            .brand-area {
                gap: 16px;
            }

            .brand-divider {
                display: none;
            }

            .header-title h1 {
                font-size: 23px;
            }

            .header-title p {
                font-size: 13px;
            }

            .brand {
                font-size: 16px;
                max-width: 180px;
            }

            .header-user {
                display: none;
            }
        }

        @media (max-width: 650px) {
            .app-header-inner,
            .app-main {
                width: min(100% - 28px, 1260px);
            }

            .app-header-inner {
                flex-wrap: wrap;
            }

            .brand-area {
                width: 100%;
            }

            .header-actions {
                width: 100%;
                justify-content: flex-end;
            }

            .app-main {
                padding-top: 28px;
            }
        }
    </style>

    {{-- =========================================================
         APLICAR EL TEMA ANTES DE MOSTRAR LA PÁGINA
    ========================================================== --}}
    <script>
        (function () {
            const savedTheme = localStorage.getItem('json-excel-theme');

            if (savedTheme === 'light' || savedTheme === 'dark') {
                document.documentElement.setAttribute(
                    'data-theme',
                    savedTheme
                );
            }
        })();
    </script>
</head>

<body>

    {{-- =========================================================
         ENCABEZADO
    ========================================================== --}}
    <header class="app-header">
        <div class="app-header-inner">

            <div class="brand-area">

                <a href="{{ auth()->check() ? route('dashboard') : route('login') }}"
                   class="brand">
                    COMITÉ DE<br>
                    ESTUDIOS<br>
                    MÉDICOS
                </a>

                <div class="brand-divider"></div>

                <div class="header-title">
                    <h1>
                        @yield('header_title', 'Generador de Consolidado')
                    </h1>

                    <p>
                        @yield(
                            'header_subtitle',
                            'Conversión de archivos JSON a Excel'
                        )
                    </p>
                </div>

            </div>

            <div class="header-actions">

                {{-- CAMBIO DE TEMA --}}
                <button
                    type="button"
                    id="themeToggle"
                    class="theme-button"
                    title="Cambiar tema"
                    aria-label="Cambiar tema"
                >
                    ☀️
                </button>

                @auth
                    <span class="header-user">
                        {{ auth()->user()->name }}
                    </span>

                    <form
                        method="POST"
                        action="{{ route('logout') }}"
                        style="margin: 0;"
                    >
                        @csrf

                        <button
                            type="submit"
                            class="logout-button"
                        >
                            Cerrar sesión
                        </button>
                    </form>
                @endauth

            </div>

        </div>
    </header>

    {{-- =========================================================
         CONTENIDO DE CADA PÁGINA
    ========================================================== --}}
    <main class="app-main">
        @yield('content')
    </main>

    {{-- =========================================================
         CAMBIO GLOBAL DE TEMA
    ========================================================== --}}
    <script>
        document.addEventListener('DOMContentLoaded', function () {

            const html = document.documentElement;
            const themeButton = document.getElementById('themeToggle');

            if (!themeButton) {
                return;
            }

            function updateThemeButton() {
                const currentTheme =
                    html.getAttribute('data-theme') || 'dark';

                if (currentTheme === 'dark') {
                    themeButton.textContent = '☀️';
                    themeButton.title = 'Cambiar a tema claro';
                    themeButton.setAttribute(
                        'aria-label',
                        'Cambiar a tema claro'
                    );
                } else {
                    themeButton.textContent = '🌙';
                    themeButton.title = 'Cambiar a tema oscuro';
                    themeButton.setAttribute(
                        'aria-label',
                        'Cambiar a tema oscuro'
                    );
                }
            }

            updateThemeButton();

            themeButton.addEventListener('click', function () {

                const currentTheme =
                    html.getAttribute('data-theme') || 'dark';

                const newTheme =
                    currentTheme === 'dark'
                        ? 'light'
                        : 'dark';

                html.setAttribute(
                    'data-theme',
                    newTheme
                );

                localStorage.setItem('json-excel-theme', newTheme);

                updateThemeButton();
            });

            window.addEventListener('storage', function (event) {
                if (event.key !== 'json-excel-theme') {
                    return;
                }

                const newTheme = event.newValue === 'light' ? 'light' : 'dark';
                html.setAttribute('data-theme', newTheme);
                updateThemeButton();
            });

        });
    </script>

@include('navigation')
</body>
</html>
