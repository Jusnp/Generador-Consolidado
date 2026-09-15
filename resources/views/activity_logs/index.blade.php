<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Registro de Actividad | Comité de Estudios Médicos</title>

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
            font-family: Arial, Helvetica, sans-serif;
        }

        body {
            background: var(--bg);
            color: var(--text);

            transition:
                background 0.2s ease,
                color 0.2s ease;
        }


        /* =========================================================
           TEMA CLARO
        ========================================================= */

        :root {
            --bg: #f3f5f7;
            --card: #ffffff;
            --card-soft: #f8fafb;

            --border: #dfe4e8;

            --text: #1f2933;
            --muted: #66727d;

            --primary: #1f4e79;
            --primary-hover: #173b5c;

            --shadow:
                0 8px 25px rgba(0, 0, 0, 0.08);
        }


        /* =========================================================
           TEMA OSCURO
        ========================================================= */

        html[data-theme="dark"] {
            --bg: #101417;
            --card: #171d21;
            --card-soft: #1d252a;

            --border: #303a40;

            --text: #edf2f4;
            --muted: #aab5bc;

            --primary: #4d8ac4;
            --primary-hover: #619bd0;

            --shadow:
                0 8px 25px rgba(0, 0, 0, 0.28);
        }


        /* =========================================================
           PÁGINA
        ========================================================= */

        .page {
            min-height: 100vh;
        }


        /* =========================================================
           HEADER
           Mismo estilo institucional del Dashboard
        ========================================================= */

        .header {

            min-height: 115px;

            display: flex;

            align-items: center;

            justify-content: space-between;

            padding:
                20px 30px;

            background: var(--card);

            border-bottom:
                1px solid var(--border);

            box-shadow: var(--shadow);

        }


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

            color: var(--text);

            white-space: nowrap;

        }


        .brand-divider {

            width: 1px;

            height: 58px;

            background: var(--border);

        }


        .brand-text {

            min-width: 0;

        }


        .brand-text h1 {

            margin: 0 0 5px 0;

            font-size: 32px;

            line-height: 1.1;

            font-weight: 700;

            color: var(--text);

        }


        .brand-text p {

            margin: 0;

            color: var(--muted);

            font-size: 16px;

        }


        /* =========================================================
           ACCIONES DEL HEADER
        ========================================================= */

        .header-right {

            display: flex;

            align-items: center;

            gap: 12px;

            flex-shrink: 0;

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

            border:
                1px solid var(--border);

            border-radius: 9px;

            background: var(--card-soft);

            color: var(--text);

            cursor: pointer;

            font-size: 17px;

        }


        .theme-button:hover {

            border-color:
                var(--primary);

        }


        .logout-button {

            border: 0;

            border-radius: 8px;

            padding:
                10px 15px;

            background:
                var(--primary);

            color: #ffffff;

            cursor: pointer;

            font-weight: 600;

        }


        .logout-button:hover {

            background:
                var(--primary-hover);

        }


        /* =========================================================
           CONTENIDO
        ========================================================= */

        .container {

            max-width: 1400px;

            margin: 0 auto;

            padding:
                34px 32px 50px;

        }


        .page-title {

            display: flex;

            justify-content: space-between;

            align-items: center;

            margin-bottom: 25px;

            gap: 20px;

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

            border:
                1px solid var(--border);

            border-radius: 8px;

            padding:
                10px 15px;

            font-size: 14px;

            font-weight: 600;

            white-space: nowrap;

        }


        .back-button:hover {

            border-color:
                var(--primary);

            color:
                var(--primary);

        }


        /* =========================================================
           TARJETA DE ACTIVIDADES
        ========================================================= */

        .card {

            background: var(--card);

            border:
                1px solid var(--border);

            border-radius: 12px;

            box-shadow: var(--shadow);

            overflow: hidden;

        }


        .card-header {

            padding:
                18px 22px;

            border-bottom:
                1px solid var(--border);

            background:
                var(--card-soft);

            display: flex;

            justify-content: space-between;

            align-items: center;

        }


        .card-header-title {

            font-weight: 700;

            font-size: 15px;

        }


        .card-header-count {

            color: var(--muted);

            font-size: 13px;

        }


        /* =========================================================
           TABLA
        ========================================================= */

        .table-wrapper {

            width: 100%;

            overflow-x: auto;

        }


        table {

            width: 100%;

            border-collapse: collapse;

            min-width: 850px;

        }


        th {

            text-align: left;

            padding:
                14px 18px;

            background:
                var(--card-soft);

            color:
                var(--muted);

            font-size: 12px;

            text-transform: uppercase;

            letter-spacing: 0.4px;

            border-bottom:
                1px solid var(--border);

        }


        td {

            padding:
                15px 18px;

            border-bottom:
                1px solid var(--border);

            font-size: 14px;

            vertical-align: middle;

        }


        tbody tr:hover {

            background:
                var(--card-soft);

        }


        tbody tr:last-child td {

            border-bottom: 0;

        }


        /* =========================================================
           FECHA
        ========================================================= */

        .date {

            white-space: nowrap;

            color: var(--muted);

            font-size: 13px;

        }


        /* =========================================================
           USUARIO
        ========================================================= */

        .user {

            font-weight: 600;

        }


        .user-email {

            color: var(--muted);

            font-size: 12px;

            margin-top: 3px;

        }


        /* =========================================================
           ACCIÓN
        ========================================================= */

        .action-badge {

            display: inline-flex;

            align-items: center;

            padding:
                5px 10px;

            border-radius: 999px;

            font-size: 12px;

            font-weight: 700;

            background:
                var(--card-soft);

            border:
                1px solid var(--border);

            white-space: nowrap;

        }


        /* =========================================================
           DESCRIPCIÓN
        ========================================================= */

        .description {

            color: var(--text);

        }


        /* =========================================================
           BOTÓN DETALLES
        ========================================================= */

        .details-button {

            display: inline-block;

            text-decoration: none;

            color:
                var(--primary);

            font-weight: 700;

            font-size: 13px;

            white-space: nowrap;

        }


        .details-button:hover {

            color:
                var(--primary-hover);

            text-decoration: underline;

        }


        /* =========================================================
           SIN REGISTROS
        ========================================================= */

        .empty {

            padding:
                50px 20px;

            text-align: center;

            color:
                var(--muted);

        }


        /* =========================================================
           PAGINACIÓN
        ========================================================= */

        .pagination-area {

            padding:
                18px 22px;

            border-top:
                1px solid var(--border);

        }


        .pagination-area nav {

            display: flex;

            justify-content: center;

        }


        .pagination-area svg {

            width: 18px;

            height: 18px;

        }


        .pagination-area a,
        .pagination-area span {

            display: inline-flex;

            align-items: center;

            justify-content: center;

            min-width: 36px;

            height: 36px;

            margin:
                0 3px;

            padding:
                0 10px;

            border:
                1px solid var(--border);

            border-radius: 7px;

            text-decoration: none;

            color:
                var(--text);

            font-size: 13px;

        }


        .pagination-area a:hover {

            border-color:
                var(--primary);

            color:
                var(--primary);

        }


        .pagination-area span[aria-current="page"] {

            background:
                var(--primary);

            border-color:
                var(--primary);

            color:
                #ffffff;

        }


        /* =========================================================
           RESPONSIVE
        ========================================================= */

        @media (max-width: 900px) {

            .header {

                align-items: flex-start;

                flex-direction: column;

                gap: 15px;

            }


            .header-right {

                width: 100%;

                justify-content: flex-end;

            }

        }


        @media (max-width: 800px) {

            .header {

                min-height: 82px;

                padding:
                    15px 18px;

            }


            .brand-area {

                gap: 15px;

            }


            .brand {

                font-size: 17px;

                letter-spacing: 3px;

            }


            .brand-text h1 {

                font-size: 20px;

            }


            .brand-text p {

                font-size: 13px;

            }


            .brand-divider {

                height: 45px;

            }


            .user-info {

                display: none;

            }


            .container {

                padding:
                    25px 18px 40px;

            }


            .page-title {

                align-items: flex-start;

                gap: 15px;

            }


            .page-title h2 {

                font-size: 21px;

            }

        }


        @media (max-width: 600px) {

            .brand-text {

                display: none;

            }


            .brand-divider {

                display: none;

            }


            .header-right {

                justify-content: space-between;

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

        <div class="brand-area">


            {{-- MISMO ENCABEZADO DEL DASHBOARD --}}
            <div class="brand">

                COMITÉ DE<br>
                ESTUDIOS<br>
                MÉDICOS

            </div>


            <div class="brand-divider"></div>


            <div class="brand-text">

                <h1>
                    Generador de Consolidado
                </h1>

                <p>
                    Registro de actividad
                </p>

            </div>

        </div>


        {{-- ACCIONES DEL USUARIO --}}

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


            {{-- TEMA --}}

            <button
                type="button"
                class="theme-button"
                data-theme-toggle
                title="Cambiar tema"
                aria-label="Cambiar tema"
            >
                ☀️
            </button>


            {{-- CERRAR SESIÓN --}}

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

    </header>


    {{-- =========================================================
         CONTENIDO
    ========================================================== --}}

    <main class="container">


        {{-- TÍTULO --}}

        <div class="page-title">

            <div>

                <h2>
                    📋 Registro de actividad
                </h2>

                <p>
                    Historial de acciones realizadas dentro del sistema.
                </p>

            </div>


            <a
                href="{{ route('dashboard') }}"
                class="back-button"
            >
                ← Dashboard
            </a>

        </div>


        {{-- =====================================================
             TABLA
        ====================================================== --}}

        <section class="card">


            <div class="card-header">

                <div class="card-header-title">
                    Actividades recientes
                </div>

                <div class="card-header-count">
                    {{ $activities->total() }} registros
                </div>

            </div>


            @if ($activities->count() > 0)


                <div class="table-wrapper">

                    <table>

                        <thead>

                            <tr>

                                <th>
                                    Fecha
                                </th>

                                <th>
                                    Usuario
                                </th>

                                <th>
                                    Acción
                                </th>

                                <th>
                                    Descripción
                                </th>

                                <th>
                                </th>

                            </tr>

                        </thead>


                        <tbody>


                            @foreach ($activities as $activity)


                                <tr>


                                    {{-- FECHA --}}

                                    <td class="date">

                                        {{ $activity->created_at->format('d/m/Y H:i:s') }}

                                    </td>


                                    {{-- USUARIO --}}

                                    <td>

                                        @if ($activity->user)

                                            <div class="user">

                                                {{ $activity->user->name }}

                                            </div>


                                            <div class="user-email">

                                                {{ $activity->user->email }}

                                            </div>

                                        @else

                                            <div class="user">

                                                Usuario eliminado

                                            </div>

                                        @endif

                                    </td>


                                    {{-- ACCIÓN --}}

                                    <td>

                                        <span class="action-badge">


                                            @switch($activity->action)


                                                @case('login')

                                                    🔐 Login

                                                    @break


                                                @case('logout')

                                                    🚪 Cierre de sesión

                                                    @break


                                                @case('conversion')

                                                    📊 Conversión

                                                    @break


                                                @case('user_created')

                                                    👤 Usuario creado

                                                    @break


                                                @case('user_updated')

                                                    ✏️ Usuario actualizado

                                                    @break


                                                @case('user_status_changed')

                                                    🔄 Estado cambiado

                                                    @break


                                                @case('user_deleted')

                                                    🗑️ Usuario eliminado

                                                    @break


                                                @default

                                                    {{ $activity->action }}


                                            @endswitch


                                        </span>

                                    </td>


                                    {{-- DESCRIPCIÓN --}}

                                    <td class="description">

                                        {{ $activity->description }}

                                    </td>


                                    {{-- DETALLES --}}

                                    <td>

                                        <a
                                            href="{{ route(
                                                'activity-logs.show',
                                                $activity
                                            ) }}"
                                            class="details-button"
                                        >
                                            Ver detalles →
                                        </a>

                                    </td>


                                </tr>


                            @endforeach


                        </tbody>

                    </table>

                </div>


                {{-- PAGINACIÓN --}}

                <div class="pagination-area">

                    {{ $activities->links() }}

                </div>


            @else


                <div class="empty">

                    No hay actividades registradas todavía.

                </div>


            @endif


        </section>


    </main>

</div>


{{-- TEMA GLOBAL --}}

<script src="{{ asset('js/theme.js') }}"></script>


</body>
</html>