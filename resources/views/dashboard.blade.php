<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Dashboard | Comité de Estudios Médicos</title>

    <link rel="icon" href="{{ asset('favicon.png') }}?v=2" type="image/png">
    <link rel="shortcut icon" href="{{ asset('favicon.ico') }}?v=2">

    {{-- Aplicar el tema antes de mostrar la página --}}
    <script>
        (function () {
            const theme = localStorage.getItem('json-excel-theme') || 'dark';

            document.documentElement.setAttribute(
                'data-theme',
                theme
            );
        })();
    </script>

    <style>

        /* =========================================================
           VARIABLES DEL TEMA OSCURO
        ========================================================= */

        :root {

            --bg-main: #0f141b;
            --bg-header: #151c25;
            --bg-card: #1b232d;
            --bg-card-hover: #202b37;

            --border: #304052;
            --border-soft: #263443;

            --text-main: #f5f7fa;
            --text-secondary: #8fa9c5;
            --text-muted: #71869e;

            --green: #12a84f;
            --green-hover: #0e9344;

            --purple: #7c3aed;
            --purple-hover: #6d28d9;

            --blue: #2563eb;
            --blue-hover: #1d4ed8;

            --red: #ef4444;
            --red-hover: #dc2626;

            --blue-soft: #1e3a5f;
            --purple-soft: #33245b;
            --green-soft: #123c2b;
            --red-soft: #4a2025;

            --activity-soft: #173b52;

            --shadow: 0 15px 35px rgba(0, 0, 0, 0.18);

        }


        /* =========================================================
           VARIABLES DEL TEMA CLARO
        ========================================================= */

        html[data-theme="light"] {

            --bg-main: #f4f7f6;
            --bg-header: #ffffff;
            --bg-card: #ffffff;
            --bg-card-hover: #f8faf9;

            --border: #d7e0e7;
            --border-soft: #e4e9ee;

            --text-main: #142235;
            --text-secondary: #58708c;
            --text-muted: #71839a;

            --green: #12a84f;
            --green-hover: #0e9344;

            --purple: #7c3aed;
            --purple-hover: #6d28d9;

            --blue: #2563eb;
            --blue-hover: #1d4ed8;

            --red: #ef4444;
            --red-hover: #dc2626;

            --blue-soft: #eaf3ff;
            --purple-soft: #f0eaff;
            --green-soft: #e9f9ef;
            --red-soft: #fdebed;

            --activity-soft: #eaf5fb;

            --shadow: 0 15px 35px rgba(20, 34, 53, 0.08);

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

            font-family:
                Arial,
                Helvetica,
                sans-serif;

        }


        body {

            background: var(--bg-main);
            color: var(--text-main);

            transition:
                background-color 0.25s ease,
                color 0.25s ease;

        }


        /* =========================================================
           ENCABEZADO
        ========================================================= */

        .topbar {

            width: 100%;

            background: var(--bg-header);

            border-bottom:
                1px solid var(--border);

            transition:
                background-color 0.25s ease,
                border-color 0.25s ease;

        }


        .topbar-inner {

            max-width: 1280px;

            margin: 0 auto;

            min-height: 115px;

            padding:
                20px 30px;

            display: flex;

            align-items: center;

            justify-content: space-between;

            gap: 30px;

        }


        /* =========================================================
           MARCA
        ========================================================= */

        .brand-area {

            display: flex;

            align-items: center;

            gap: 28px;

            min-width: 0;

        }


        .brand {

            font-size: 21px;

            line-height: 1.15;

            letter-spacing: 5px;

            font-weight: 500;

            color: var(--text-main);

            white-space: nowrap;

        }


        .brand-divider {

            width: 1px;

            height: 58px;

            background: var(--border);

        }


        .page-heading {

            min-width: 0;

        }


        .page-heading h1 {

            margin: 0 0 5px 0;

            font-size: 32px;

            line-height: 1.1;

            font-weight: 700;

            color: var(--text-main);

        }


        .page-heading p {

            margin: 0;

            color: var(--text-secondary);

            font-size: 16px;

        }


        /* =========================================================
           ACCIONES DEL ENCABEZADO
        ========================================================= */

        .header-actions {

            display: flex;

            align-items: center;

            gap: 14px;

            flex-shrink: 0;

        }


        .theme-button {

            width: 50px;

            height: 50px;

            border-radius: 50%;

            border:
                1px solid var(--border);

            background: transparent;

            color: var(--text-main);

            font-size: 22px;

            cursor: pointer;

            display: flex;

            align-items: center;

            justify-content: center;

            transition:
                background-color 0.2s ease,
                border-color 0.2s ease,
                transform 0.2s ease;

        }


        .theme-button:hover {

            background: var(--bg-card-hover);

            transform: translateY(-1px);

        }


        .user-name {

            color: var(--text-secondary);

            font-size: 15px;

        }


        .logout-button {

            border: 0;

            background: var(--red);

            color: #ffffff;

            padding:
                13px 20px;

            border-radius: 8px;

            font-weight: 700;

            cursor: pointer;

            font-size: 14px;

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

        .container {

            max-width: 1280px;

            margin: 0 auto;

            padding:
                45px 30px 55px;

        }


        .welcome {

            margin-bottom: 35px;

        }


        .welcome h2 {

            margin: 0 0 8px 0;

            font-size: 32px;

            color: var(--text-main);

        }


        .welcome p {

            margin: 0;

            font-size: 17px;

            color: var(--text-secondary);

        }


        /* =========================================================
           TARJETAS PRINCIPALES
        ========================================================= */

        .main-grid {

            display: grid;

            grid-template-columns:
                repeat(2, minmax(0, 1fr));

            gap: 28px;

            margin-bottom: 38px;

        }


        .main-card {

            background: var(--bg-card);

            border:
                1px solid var(--border);

            border-radius: 16px;

            padding: 30px;

            min-height: 265px;

            box-shadow: var(--shadow);

            display: flex;

            flex-direction: column;

            align-items: flex-start;

            transition:
                background-color 0.25s ease,
                border-color 0.25s ease,
                transform 0.2s ease;

        }


        .main-card:hover {

            transform: translateY(-2px);

            background: var(--bg-card-hover);

        }


        .card-icon {

            width: 58px;

            height: 58px;

            border-radius: 14px;

            display: flex;

            align-items: center;

            justify-content: center;

            font-size: 28px;

            margin-bottom: 25px;

        }


        .card-icon.green {

            background: var(--green-soft);

            color: var(--green);

        }


        .card-icon.purple {

            background: var(--purple-soft);

            color: var(--purple);

        }


        .card-icon.blue {

            background: var(--activity-soft);

            color: var(--blue);

        }


        .main-card h3 {

            margin: 0 0 10px 0;

            font-size: 23px;

            color: var(--text-main);

        }


        .main-card p {

            margin: 0;

            color: var(--text-secondary);

            font-size: 16px;

            line-height: 1.55;

            flex: 1;

        }


        .card-button {

            display: inline-flex;

            align-items: center;

            justify-content: center;

            margin-top: 25px;

            padding:
                13px 22px;

            border-radius: 8px;

            color: #ffffff;

            text-decoration: none;

            font-weight: 700;

            font-size: 15px;

            transition:
                background-color 0.2s ease,
                transform 0.2s ease;

        }


        .card-button:hover {

            transform: translateY(-1px);

        }


        .card-button.green {

            background: var(--green);

        }


        .card-button.green:hover {

            background: var(--green-hover);

        }


        .card-button.purple {

            background: var(--purple);

        }


        .card-button.purple:hover {

            background: var(--purple-hover);

        }


        .card-button.blue {

            background: var(--blue);

        }


        .card-button.blue:hover {

            background: var(--blue-hover);

        }


        /* =========================================================
           RESUMEN
        ========================================================= */

        .section-title {

            margin-bottom: 20px;

        }


        .section-title h3 {

            margin: 0 0 6px 0;

            font-size: 22px;

            color: var(--text-main);

        }


        .section-title p {

            margin: 0;

            color: var(--text-secondary);

            font-size: 15px;

        }


        .stats-grid {

            display: grid;

            grid-template-columns:
                repeat(4, minmax(0, 1fr));

            gap: 20px;

        }


        .stat-card {

            background: var(--bg-card);

            border:
                1px solid var(--border);

            border-radius: 14px;

            padding: 24px;

            min-height: 120px;

            display: flex;

            align-items: center;

            justify-content: space-between;

            gap: 15px;

            transition:
                background-color 0.25s ease,
                border-color 0.25s ease;

        }


        .stat-label {

            color: var(--text-secondary);

            font-size: 14px;

            font-weight: 700;

            margin-bottom: 12px;

        }


        .stat-value {

            font-size: 30px;

            line-height: 1;

            font-weight: 700;

            color: var(--text-main);

        }


        .stat-icon {

            width: 46px;

            height: 46px;

            border-radius: 12px;

            display: flex;

            align-items: center;

            justify-content: center;

            font-size: 21px;

            flex-shrink: 0;

        }


        .stat-icon.users {

            background: var(--blue-soft);

        }


        .stat-icon.active {

            background: var(--green-soft);

        }


        .stat-icon.inactive {

            background: var(--red-soft);

        }


        .stat-icon.admin {

            background: var(--purple-soft);

        }


        /* =========================================================
           PIE
        ========================================================= */

        .footer {

            margin-top: 35px;

            padding-top: 25px;

            border-top:
                1px solid var(--border);

            color: var(--text-muted);

            font-size: 13px;

        }


        /* =========================================================
           RESPONSIVE
        ========================================================= */

        @media (max-width: 900px) {

            .topbar-inner {

                align-items: flex-start;

                flex-direction: column;

            }


            .header-actions {

                width: 100%;

                justify-content: flex-end;

            }


            .main-grid {

                grid-template-columns: 1fr;

            }


            .stats-grid {

                grid-template-columns:
                    repeat(2, minmax(0, 1fr));

            }

        }


        @media (max-width: 600px) {

            .topbar-inner {

                padding:
                    20px;

            }


            .container {

                padding:
                    30px 20px 40px;

            }


            .brand-area {

                gap: 15px;

            }


            .brand-divider {

                display: none;

            }


            .brand {

                white-space: normal;

                font-size: 17px;

                letter-spacing: 3px;

            }


            .page-heading h1 {

                font-size: 25px;

            }


            .page-heading p {

                font-size: 14px;

            }


            .header-actions {

                justify-content: space-between;

            }


            .user-name {

                margin-left: auto;

            }


            .welcome h2 {

                font-size: 27px;

            }


            .stats-grid {

                grid-template-columns: 1fr;

            }

        }

    </style>

</head>


<body>

    {{-- =========================================================
         ENCABEZADO
    ========================================================== --}}

    <header class="topbar">

        <div class="topbar-inner">

            <div class="brand-area">

                <div class="brand">
                    COMITÉ DE<br>
                    ESTUDIOS<br>
                    MÉDICOS
                </div>

                <div class="brand-divider"></div>

                <div class="page-heading">

                    <h1>
                        Inicio
                    </h1>

                    <p>
                        Selecciona un programa desde el menú
                    </p>

                </div>

            </div>


            <div class="header-actions">

                {{-- BOTÓN GLOBAL DE TEMA --}}
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

            </div>

        </div>

    </header>


    {{-- =========================================================
         CONTENIDO
    ========================================================== --}}

    <main class="container">


        {{-- BIENVENIDA --}}

        <section class="welcome">

            <h2>
                Bienvenido, {{ auth()->user()->name }}
            </h2>

            <p>
                Usa el menú de la izquierda para elegir el programa
                que vas a ejecutar. La pantalla del programa se abrirá aquí.
            </p>

        </section>


        @if(auth()->user()->role === 'admin')

            <section>

                <div class="section-title">

                    <h3>
                        Resumen del sistema
                    </h3>

                    <p>
                        Estado actual de las cuentas registradas.
                        La administración también está en el menú lateral.
                    </p>

                </div>


                <div class="stats-grid">


                    {{-- TOTAL --}}

                    <div class="stat-card">

                        <div>

                            <div class="stat-label">
                                Total usuarios
                            </div>

                            <div class="stat-value">
                                {{ \App\Models\User::count() }}
                            </div>

                        </div>

                        <div class="stat-icon users">
                            👥
                        </div>

                    </div>


                    {{-- ACTIVOS --}}

                    <div class="stat-card">

                        <div>

                            <div class="stat-label">
                                Usuarios activos
                            </div>

                            <div class="stat-value">
                                {{ \App\Models\User::where('active', true)->count() }}
                            </div>

                        </div>

                        <div class="stat-icon active">
                            🟢
                        </div>

                    </div>


                    {{-- INACTIVOS --}}

                    <div class="stat-card">

                        <div>

                            <div class="stat-label">
                                Usuarios inactivos
                            </div>

                            <div class="stat-value">
                                {{ \App\Models\User::where('active', false)->count() }}
                            </div>

                        </div>

                        <div class="stat-icon inactive">
                            🔴
                        </div>

                    </div>


                    {{-- ADMINISTRADORES --}}

                    <div class="stat-card">

                        <div>

                            <div class="stat-label">
                                Administradores
                            </div>

                            <div class="stat-value">
                                {{ \App\Models\User::where('role', 'admin')->count() }}
                            </div>

                        </div>

                        <div class="stat-icon admin">
                            👑
                        </div>

                    </div>


                </div>

            </section>

        @endif


        {{-- =====================================================
             PIE
        ====================================================== --}}

        <footer class="footer">

            Sistema interno · Comité de Estudios Médicos

        </footer>


    </main>


    {{-- =========================================================
         SISTEMA GLOBAL DE TEMA
    ========================================================== --}}

    <script src="{{ asset('js/theme.js') }}"></script>


@include('navigation')
</body>

</html>
