<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Nuevo usuario | JSON → Excel</title>

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
            --blue: #3b82f6;

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
            --blue: #3b82f6;

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

        }


        /* =====================================================
           HEADER
        ====================================================== */

        .site-header {

            width: 100%;

            background:
                var(--header);

            border-bottom:
                1px solid var(--border);

        }


        .header-inner {

            width:
                min(1360px, calc(100% - 48px));

            min-height:
                126px;

            margin:
                0 auto;

            display:
                flex;

            align-items:
                center;

            justify-content:
                space-between;

            gap:
                30px;

        }


        .header-brand {

            display:
                flex;

            align-items:
                center;

            gap:
                28px;

        }


        .institution {

            width:
                210px;

            flex:
                0 0 210px;

        }


        .institution-title {

            margin:
                0;

            font-size:
                23px;

            line-height:
                1.05;

            letter-spacing:
                7px;

            font-weight:
                500;

            color:
                var(--text);

        }


        .header-divider {

            width:
                1px;

            height:
                66px;

            background:
                var(--border);

        }


        .section-title h1 {

            margin:
                0;

            font-size:
                34px;

            line-height:
                1.1;

            font-weight:
                800;

            color:
                var(--text);

        }


        .section-title p {

            margin:
                7px 0 0;

            font-size:
                17px;

            color:
                var(--muted);

        }


        .header-actions {

            display:
                flex;

            align-items:
                center;

            gap:
                16px;

        }


        .theme-button {

            width:
                42px !important;

            height:
                42px !important;

            min-width:
                42px !important;

            min-height:
                42px !important;

            padding:
                0 !important;

            display:
                flex !important;

            align-items:
                center !important;

            justify-content:
                center !important;

            border:
                1px solid var(--border);

            border-radius:
                50%;

            background:
                transparent;

            color:
                var(--text);

            font-size:
                17px !important;

            cursor:
                pointer;

        }


        .user-name {

            color:
                var(--muted);

            font-size:
                15px;

            font-weight:
                600;

        }


        .logout-button {

            min-height:
                48px;

            padding:
                0 20px;

            border:
                0;

            border-radius:
                9px;

            background:
                #ef4444;

            color:
                #fff;

            font-family:
                inherit;

            font-size:
                14px;

            font-weight:
                800;

            cursor:
                pointer;

        }


        /* =====================================================
           CONTENIDO
        ====================================================== */

        .page {

            min-height:
                calc(100vh - 127px);

            background:
                var(--bg);

        }


        .container {

            width:
                min(1360px, calc(100% - 48px));

            margin:
                0 auto;

            padding:
                38px 0 60px;

        }


        .top-navigation {

            display:
                flex;

            justify-content:
                flex-end;

            margin-bottom:
                20px;

        }


        .back-button {

            min-height:
                44px;

            padding:
                0 18px;

            display:
                inline-flex;

            align-items:
                center;

            justify-content:
                center;

            border:
                1px solid var(--border);

            border-radius:
                9px;

            background:
                var(--card);

            color:
                var(--text);

            text-decoration:
                none;

            font-size:
                14px;

            font-weight:
                700;

        }


        /* =====================================================
           TARJETA
        ====================================================== */

        .card {

            width:
                100%;

            background:
                var(--card);

            border:
                1px solid var(--border);

            border-radius:
                16px;

            overflow:
                hidden;

            box-shadow:
                0 18px 40px rgba(0, 0, 0, .10);

        }


        .card-header {

            padding:
                25px 26px 23px;

            border-bottom:
                1px solid var(--border);

        }


        .card-header h2 {

            margin:
                0;

            font-size:
                24px;

            font-weight:
                800;

        }


        .card-header p {

            margin:
                7px 0 0;

            color:
                var(--muted);

            font-size:
                14px;

        }


        .card-body {

            padding:
                28px;

        }


        /* =====================================================
           ERRORES
        ====================================================== */

        .errors {

            margin-bottom:
                22px;

            padding:
                14px 16px;

            border:
                1px solid rgba(239,68,68,.35);

            border-radius:
                10px;

            background:
                rgba(239,68,68,.08);

            color:
                #f87171;

            font-size:
                14px;

        }


        .errors-title {

            margin-bottom:
                7px;

            font-weight:
                800;

        }


        .errors ul {

            margin:
                0;

            padding-left:
                20px;

        }


        /* =====================================================
           FORMULARIO
        ====================================================== */

        .form-grid {

            display:
                grid;

            grid-template-columns:
                1fr 1fr;

            gap:
                22px;

        }


        .full {

            grid-column:
                1 / -1;

        }


        .field label {

            display:
                block;

            margin-bottom:
                8px;

            font-size:
                14px;

            font-weight:
                700;

        }


        .required {

            color:
                var(--red);

        }


        .field input,
        .field select {

            width:
                100%;

            height:
                48px;

            padding:
                0 14px;

            border:
                1px solid var(--border);

            border-radius:
                9px;

            outline:
                none;

            background:
                var(--card-2);

            color:
                var(--text);

            font-family:
                inherit;

            font-size:
                15px;

        }


        .field input:focus,
        .field select:focus {

            border-color:
                var(--green);

            box-shadow:
                0 0 0 3px rgba(22,163,74,.12);

        }


        .help {

            margin-top:
                7px;

            color:
                var(--muted);

            font-size:
                12px;

        }


        .error {

            margin-top:
                7px;

            color:
                #f87171;

            font-size:
                12px;

        }


        /* =====================================================
           CONTRASEÑA
        ====================================================== */

        .password-section {

            grid-column:
                1 / -1;

            padding:
                20px;

            border:
                1px solid var(--border);

            border-radius:
                12px;

            background:
                var(--card-2);

        }


        .password-section h3 {

            margin:
                0;

            font-size:
                16px;

            font-weight:
                800;

        }


        .password-section > p {

            margin:
                5px 0 18px;

            color:
                var(--muted);

            font-size:
                13px;

        }


        .password-grid {

            display:
                grid;

            grid-template-columns:
                1fr 1fr;

            gap:
                22px;

        }


        /* =====================================================
           ESTADO
        ====================================================== */

        .status-section {

            grid-column:
                1 / -1;

        }


        .status-options {

            display:
                grid;

            grid-template-columns:
                1fr 1fr;

            gap:
                12px;

        }


        .status-option input {

            position:
                absolute;

            opacity:
                0;

        }


        .status-label {

            min-height:
                52px;

            padding:
                0 16px;

            display:
                flex;

            align-items:
                center;

            gap:
                10px;

            border:
                1px solid var(--border);

            border-radius:
                10px;

            background:
                var(--card-2);

            cursor:
                pointer;

            font-size:
                14px;

            font-weight:
                700;

        }


        .status-option input:checked + .status-label {

            border-color:
                var(--green);

            background:
                rgba(22,163,74,.08);

        }


        .dot {

            width:
                9px;

            height:
                9px;

            border-radius:
                50%;

        }


        .green {
            background: var(--green);
        }


        .red {
            background: var(--red);
        }


        /* =====================================================
           BOTONES
        ====================================================== */

        .form-actions {

            margin-top:
                28px;

            padding-top:
                22px;

            border-top:
                1px solid var(--border);

            display:
                flex;

            justify-content:
                flex-end;

            gap:
                10px;

        }


        .button {

            min-height:
                44px;

            padding:
                0 18px;

            display:
                inline-flex;

            align-items:
                center;

            justify-content:
                center;

            border-radius:
                9px;

            font-family:
                inherit;

            font-size:
                14px;

            font-weight:
                800;

            text-decoration:
                none;

            cursor:
                pointer;

        }


        .cancel {

            border:
                1px solid var(--border);

            background:
                transparent;

            color:
                var(--muted);

        }


        .primary {

            border:
                1px solid var(--green);

            background:
                var(--green);

            color:
                #fff;

        }


        .primary:hover {

            background:
                var(--green-hover);

        }


        .footer {

            margin-top:
                24px;

            text-align:
                center;

            color:
                var(--muted);

            font-size:
                12px;

        }


        @media (max-width: 800px) {

            .header-inner {

                min-height:
                    auto;

                padding:
                    20px 0;

            }

            .header-brand {

                gap:
                    15px;

            }

            .institution {

                width:
                    150px;

                flex-basis:
                    150px;

            }

            .institution-title {

                font-size:
                    17px;

                letter-spacing:
                    4px;

            }

            .section-title h1 {

                font-size:
                    23px;

            }

            .section-title p {

                font-size:
                    13px;

            }

            .user-name {

                display:
                    none;

            }

            .form-grid,
            .password-grid,
            .status-options {

                grid-template-columns:
                    1fr;

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
                    Gestión de cuentas y permisos del sistema
                </p>

            </div>


        </div>


        <div class="header-actions">


            <button
                type="button"
                class="theme-button"
                data-theme-toggle
                aria-label="Cambiar tema"
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
                href="{{ route('users.index') }}"
                class="back-button"
            >
                ← Volver a usuarios
            </a>

        </div>


        <section class="card">


            <div class="card-header">

                <h2>
                    Crear nuevo usuario
                </h2>

                <p>
                    Registra una nueva cuenta y define sus permisos de acceso.
                </p>

            </div>


            <div class="card-body">


                @if ($errors->any())

                    <div class="errors">

                        <div class="errors-title">
                            Revisa los siguientes datos:
                        </div>

                        <ul>

                            @foreach ($errors->all() as $error)

                                <li>
                                    {{ $error }}
                                </li>

                            @endforeach

                        </ul>

                    </div>

                @endif


                <form
                    method="POST"
                    action="{{ route('users.store') }}"
                >

                    @csrf


                    <div class="form-grid">


                        <div class="field full">

                            <label for="name">

                                Nombre completo
                                <span class="required">*</span>

                            </label>

                            <input
                                type="text"
                                id="name"
                                name="name"
                                value="{{ old('name') }}"
                                placeholder="Ej. Juan Pérez"
                                autocomplete="name"
                                required
                            >

                            @error('name')

                                <div class="error">
                                    {{ $message }}
                                </div>

                            @enderror

                        </div>



                        <div class="field">

                            <label for="email">

                                Correo electrónico
                                <span class="required">*</span>

                            </label>

                            <input
                                type="email"
                                id="email"
                                name="email"
                                value="{{ old('email') }}"
                                placeholder="usuario@correo.com"
                                autocomplete="email"
                                required
                            >

                            @error('email')

                                <div class="error">
                                    {{ $message }}
                                </div>

                            @enderror

                        </div>



                        <div class="field">

                            <label for="role">

                                Rol
                                <span class="required">*</span>

                            </label>

                            <select
                                id="role"
                                name="role"
                                required
                            >

                                <option value="">
                                    Selecciona un rol
                                </option>

                                <option
                                    value="user"
                                    {{ old('role') === 'user' ? 'selected' : '' }}
                                >
                                    Usuario
                                </option>

                                <option
                                    value="admin"
                                    {{ old('role') === 'admin' ? 'selected' : '' }}
                                >
                                    Administrador
                                </option>

                            </select>

                            <div class="help">
                                El administrador tiene acceso a la gestión de usuarios.
                            </div>

                            @error('role')

                                <div class="error">
                                    {{ $message }}
                                </div>

                            @enderror

                        </div>



                        <div class="password-section">


                            <h3>
                                Contraseña
                            </h3>


                            <p>
                                Define la contraseña inicial de esta cuenta.
                            </p>


                            <div class="password-grid">


                                <div class="field">

                                    <label for="password">

                                        Contraseña
                                        <span class="required">*</span>

                                    </label>

                                    <input
                                        type="password"
                                        id="password"
                                        name="password"
                                        placeholder="Mínimo 8 caracteres"
                                        autocomplete="new-password"
                                        required
                                    >

                                    <div class="help">
                                        Debe contener al menos 8 caracteres.
                                    </div>

                                    @error('password')

                                        <div class="error">
                                            {{ $message }}
                                        </div>

                                    @enderror

                                </div>



                                <div class="field">

                                    <label for="password_confirmation">

                                        Confirmar contraseña
                                        <span class="required">*</span>

                                    </label>

                                    <input
                                        type="password"
                                        id="password_confirmation"
                                        name="password_confirmation"
                                        placeholder="Repite la contraseña"
                                        autocomplete="new-password"
                                        required
                                    >

                                </div>


                            </div>


                        </div>



                        <div class="status-section">


                            <div class="field">

                                <label>

                                    Estado
                                    <span class="required">*</span>

                                </label>


                                <div class="status-options">


                                    <div class="status-option">

                                        <input
                                            type="radio"
                                            id="active_yes"
                                            name="active"
                                            value="1"
                                            {{ old('active', '1') == '1' ? 'checked' : '' }}
                                        >

                                        <label
                                            for="active_yes"
                                            class="status-label"
                                        >

                                            <span class="dot green"></span>

                                            Usuario activo

                                        </label>

                                    </div>



                                    <div class="status-option">

                                        <input
                                            type="radio"
                                            id="active_no"
                                            name="active"
                                            value="0"
                                            {{ old('active') === '0' ? 'checked' : '' }}
                                        >

                                        <label
                                            for="active_no"
                                            class="status-label"
                                        >

                                            <span class="dot red"></span>

                                            Usuario inactivo

                                        </label>

                                    </div>


                                </div>


                                @error('active')

                                    <div class="error">
                                        {{ $message }}
                                    </div>

                                @enderror


                            </div>


                        </div>


                    </div>



                    <div class="form-actions">


                        <a
                            href="{{ route('users.index') }}"
                            class="button cancel"
                        >
                            Cancelar
                        </a>


                        <button
                            type="submit"
                            class="button primary"
                        >
                            ✓ Crear usuario
                        </button>


                    </div>


                </form>


            </div>


        </section>


        <div class="footer">
            Sistema interno · Comité de Estudios Médicos
        </div>


    </main>


</div>



<script src="{{ asset('js/theme.js') }}"></script>


</body>

</html>