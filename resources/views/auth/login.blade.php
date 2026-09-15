<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Iniciar sesión | Comité de Estudios Médicos
    </title>


    {{-- =========================================================
         APLICAR TEMA ANTES DE MOSTRAR LA PÁGINA
    ========================================================== --}}

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

        /* =========================================================
           TEMA OSCURO
        ========================================================= */

        :root {

            --bg: #0d1218;

            --header: #151c25;

            --card: #19222d;

            --card-hover: #202b37;

            --border: #334252;

            --text: #f4f7fa;

            --muted: #8fa9c2;

            --green: #16a34a;

            --green-hover: #15803d;

            --red: #ef4444;

            --input: #202b37;

            --shadow:
                0 20px 45px rgba(0, 0, 0, .22);

        }


        /* =========================================================
           TEMA CLARO
        ========================================================== */

        html[data-theme="light"] {

            --bg: #f4f7f6;

            --header: #ffffff;

            --card: #ffffff;

            --card-hover: #f8faf9;

            --border: #d8e0e5;

            --text: #142033;

            --muted: #60758c;

            --green: #16a34a;

            --green-hover: #15803d;

            --red: #ef4444;

            --input: #ffffff;

            --shadow:
                0 18px 40px rgba(15, 23, 42, .09);

        }


        /* =========================================================
           GENERAL
        ========================================================== */

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

            background:
                var(--bg);

            color:
                var(--text);

            transition:
                background .25s ease,
                color .25s ease;

        }


        .page {

            min-height: 100vh;

            background:
                var(--bg);

        }


        /* =========================================================
           HEADER
        ========================================================== */

        .header {

            border-bottom:
                1px solid var(--border);

            background:
                var(--header);

            transition:
                background .25s ease,
                border-color .25s ease;

        }


        .header-inner {

            width:
                min(1260px, calc(100% - 48px));

            min-height:
                116px;

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


        /* =========================================================
           MARCA
        ========================================================== */

        .brand-area {

            display:
                flex;

            align-items:
                center;

            gap:
                28px;

        }


        .brand {

            width:
                205px;

            font-size:
                21px;

            line-height:
                1.12;

            letter-spacing:
                5px;

            font-weight:
                500;

            color:
                var(--text);

        }


        .brand-divider {

            width:
                1px;

            height:
                62px;

            background:
                var(--border);

        }


        .page-heading h1 {

            margin:
                0 0 5px;

            font-size:
                31px;

            line-height:
                1.15;

            font-weight:
                800;

            color:
                var(--text);

        }


        .page-heading p {

            margin:
                0;

            color:
                var(--muted);

            font-size:
                15px;

        }


        /* =========================================================
           HEADER ACTIONS
        ========================================================== */

        .header-actions {

            display:
                flex;

            align-items:
                center;

            gap:
                14px;

        }


        /* =========================================================
           BOTÓN DE TEMA
        ========================================================== */

        .theme-button {

            width:
                36px;

            height:
                36px;

            display:
                flex;

            align-items:
                center;

            justify-content:
                center;

            border:
                1px solid var(--border);

            border-radius:
                50%;

            background:
                transparent;

            color:
                var(--text);

            font-size:
                16px;

            cursor:
                pointer;

            transition:
                background .2s ease,
                transform .2s ease;

        }


        .theme-button:hover {

            background:
                var(--card-hover);

            transform:
                scale(1.04);

        }


        /* =========================================================
           CONTENIDO
        ========================================================== */

        .content {

            width:
                min(1260px, calc(100% - 48px));

            min-height:
                calc(100vh - 116px);

            margin:
                0 auto;

            display:
                flex;

            align-items:
                center;

            justify-content:
                center;

            padding:
                45px 0 60px;

        }


        /* =========================================================
           TARJETA LOGIN
        ========================================================== */

        .login-card {

            width:
                min(460px, 100%);

            padding:
                36px;

            border:
                1px solid var(--border);

            border-radius:
                18px;

            background:
                var(--card);

            box-shadow:
                var(--shadow);

            transition:
                background .25s ease,
                border-color .25s ease;

        }


        /* =========================================================
           ICONO
        ========================================================== */

        .login-icon {

            width:
                58px;

            height:
                58px;

            margin:
                0 auto 18px;

            display:
                flex;

            align-items:
                center;

            justify-content:
                center;

            border-radius:
                14px;

            background:
                rgba(22, 163, 74, .12);

            color:
                var(--green);

            font-size:
                28px;

        }


        /* =========================================================
           TITULO
        ========================================================== */

        .login-heading {

            text-align:
                center;

            margin-bottom:
                30px;

        }


        .login-heading h2 {

            margin:
                0;

            font-size:
                27px;

            line-height:
                1.2;

            font-weight:
                800;

            color:
                var(--text);

        }


        .login-heading p {

            margin:
                9px 0 0;

            color:
                var(--muted);

            font-size:
                14px;

        }


        /* =========================================================
           ERRORES
        ========================================================== */

        .errors {

            margin-bottom:
                22px;

            padding:
                14px 16px;

            border:
                1px solid rgba(239, 68, 68, .45);

            border-radius:
                9px;

            background:
                rgba(239, 68, 68, .10);

            color:
                #f87171;

            font-size:
                14px;

            line-height:
                1.5;

        }


        .errors div {

            margin:
                3px 0;

        }


        /* =========================================================
           CAMPOS
        ========================================================== */

        .field {

            margin-bottom:
                20px;

        }


        .field label {

            display:
                block;

            margin-bottom:
                8px;

            color:
                var(--text);

            font-size:
                14px;

            font-weight:
                700;

        }


        .required {

            color:
                var(--red);

        }


        .field input {

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
                var(--input);

            color:
                var(--text);

            font-size:
                15px;

            transition:
                border-color .2s ease,
                box-shadow .2s ease,
                background .2s ease;

        }


        .field input::placeholder {

            color:
                var(--muted);

        }


        .field input:focus {

            border-color:
                var(--green);

            box-shadow:
                0 0 0 3px
                rgba(22, 163, 74, .12);

        }


        /* =========================================================
           BOTÓN LOGIN
        ========================================================== */

        .login-button {

            width:
                100%;

            min-height:
                48px;

            border:
                0;

            border-radius:
                8px;

            background:
                var(--green);

            color:
                #ffffff;

            font-size:
                15px;

            font-weight:
                800;

            cursor:
                pointer;

            transition:
                background .2s ease,
                transform .2s ease;

        }


        .login-button:hover {

            background:
                var(--green-hover);

            transform:
                translateY(-1px);

        }


        /* =========================================================
           FOOTER
        ========================================================== */

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


        /* =========================================================
           RESPONSIVE
        ========================================================== */

        @media (max-width: 900px) {

            .header-inner {

                min-height:
                    auto;

                padding:
                    22px 0;

                align-items:
                    flex-start;

                flex-direction:
                    column;

            }


            .header-actions {

                width:
                    100%;

                justify-content:
                    flex-start;

            }

        }


        @media (max-width: 650px) {

            .header-inner,
            .content {

                width:
                    min(100% - 28px, 1260px);

            }


            .brand-area {

                gap:
                    15px;

            }


            .brand {

                width:
                    auto;

                font-size:
                    16px;

                letter-spacing:
                    3px;

            }


            .brand-divider {

                display:
                    none;

            }


            .page-heading h1 {

                font-size:
                    23px;

            }


            .page-heading p {

                font-size:
                    13px;

            }


            .login-card {

                padding:
                    27px 21px;

            }

        }

    </style>

</head>


<body>


<div class="page">


    <!-- =========================================================
         HEADER
    ========================================================== -->

    <header class="header">

        <div class="header-inner">


            <div class="brand-area">


                <div class="brand">

                    COMITÉ DE<br>
                    ESTUDIOS<br>
                    MÉDICOS

                </div>


                <div class="brand-divider"></div>


                <div class="page-heading">

                    <h1>
                        Sistema de acceso
                    </h1>

                    <p>
                        Comité de Estudios Médicos
                    </p>

                </div>


            </div>


            <div class="header-actions">


                <!-- BOTÓN DE TEMA -->

                <button
                    type="button"
                    class="theme-button"
                    data-theme-toggle
                    aria-label="Cambiar tema"
                    title="Cambiar tema"
                >
                    ☀️
                </button>


            </div>

        </div>

    </header>



    <!-- =========================================================
         CONTENIDO
    ========================================================== -->

    <main class="content">


        <div>


            <!-- =================================================
                 LOGIN
            ================================================== -->

            <section class="login-card">


                <div class="login-heading">


                    <div class="login-icon">
                        🔐
                    </div>


                    <h2>
                        Iniciar sesión
                    </h2>


                    <p>
                        Ingresa tus credenciales para continuar.
                    </p>


                </div>



                <!-- ERRORES -->

                @if ($errors->any())

                    <div class="errors">

                        @foreach ($errors->all() as $error)

                            <div>
                                {{ $error }}
                            </div>

                        @endforeach

                    </div>

                @endif



                <!-- MENSAJE DE ÉXITO -->

                @if (session('success'))

                    <div
                        style="
                            margin-bottom: 22px;
                            padding: 14px 16px;
                            border: 1px solid rgba(22, 163, 74, .35);
                            border-radius: 9px;
                            background: rgba(22, 163, 74, .10);
                            color: var(--green);
                            font-size: 14px;
                        "
                    >
                        {{ session('success') }}
                    </div>

                @endif



                <!-- FORMULARIO -->

                <form
                    method="POST"
                    action="{{ route('login.authenticate') }}"
                >

                    @csrf


                    <!-- CORREO -->

                    <div class="field">

                        <label for="email">

                            Correo electrónico

                            <span class="required">
                                *
                            </span>

                        </label>


                        <input
                            type="email"
                            id="email"
                            name="email"
                            value="{{ old('email') }}"
                            placeholder="correo@ejemplo.com"
                            autocomplete="email"
                            required
                            autofocus
                        >

                    </div>



                    <!-- CONTRASEÑA -->

                    <div class="field">

                        <label for="password">

                            Contraseña

                            <span class="required">
                                *
                            </span>

                        </label>


                        <input
                            type="password"
                            id="password"
                            name="password"
                            placeholder="Ingresa tu contraseña"
                            autocomplete="current-password"
                            required
                        >

                    </div>



                    <!-- BOTÓN -->

                    <button
                        type="submit"
                        class="login-button"
                    >
                        Iniciar sesión
                    </button>


                </form>


            </section>



            <!-- FOOTER -->

            <div class="footer">

                Sistema interno · Comité de Estudios Médicos

            </div>


        </div>


    </main>


</div>



<!-- =============================================================
     SISTEMA GLOBAL DE TEMA
============================================================= -->

<script src="{{ asset('js/theme.js') }}"></script>


</body>

</html>