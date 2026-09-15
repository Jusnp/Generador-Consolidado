<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Generador de Consolidado | Comité de Estudios Médicos
    </title>


    <style>

        /* =========================================================
           VARIABLES - TEMA OSCURO
        ========================================================= */

        :root {

            --bg: #0e141b;

            --header: #171e27;

            --card: #1b242f;

            --card-soft: #202b38;

            --border: #344255;

            --border-light: #45566d;

            --text: #f4f7fb;

            --muted: #8fa8c4;

            --green: #16a34a;

            --green-dark: #0d7a36;

            --green-soft: rgba(22, 163, 74, .15);

            --blue: #3b82f6;

            --blue-soft: rgba(59, 130, 246, .12);

            --red: #ef4444;

            --red-soft: rgba(239, 68, 68, .15);

            --yellow: #f59e0b;

            --yellow-soft: rgba(245, 158, 11, .15);

            --shadow:
                0 15px 40px rgba(0,0,0,.20);
        }


        /* =========================================================
           TEMA CLARO GLOBAL
        ========================================================= */

        html[data-theme="light"] {

            --bg: #f4f7f6;

            --header: #ffffff;

            --card: #ffffff;

            --card-soft: #f7faf9;

            --border: #d6dee7;

            --border-light: #c5d0dc;

            --text: #142033;

            --muted: #60758f;

            --green: #16a34a;

            --green-dark: #0d7a36;

            --green-soft:
                rgba(22, 163, 74, .09);

            --blue: #2563eb;

            --blue-soft:
                rgba(37, 99, 235, .08);

            --red: #dc2626;

            --red-soft:
                rgba(220, 38, 38, .08);

            --yellow: #d97706;

            --yellow-soft:
                rgba(217, 119, 6, .09);

            --shadow:
                0 12px 35px rgba(20,32,51,.08);
        }


        /* =========================================================
           BASE
        ========================================================= */

        * {

            box-sizing: border-box;
        }


        html {

            min-height: 100%;

            background: var(--bg);
        }


        body {

            margin: 0;

            min-height: 100vh;

            background: var(--bg);

            color: var(--text);

            font-family:
                Arial,
                Helvetica,
                sans-serif;

            transition:
                background .25s ease,
                color .25s ease;
        }


        button,
        input {

            font: inherit;
        }


        /* =========================================================
           HEADER
        ========================================================= */

        .topbar {

            width: 100%;

            background: var(--header);

            border-bottom:
                1px solid var(--border);

            transition:
                background .25s ease,
                border-color .25s ease;
        }


        .topbar-inner {

            width:
                min(1120px, calc(100% - 36px));

            min-height: 118px;

            margin: auto;

            display: flex;

            align-items: center;

            justify-content: space-between;

            gap: 25px;
        }


        .brand-area {

            display: flex;

            align-items: center;

            gap: 28px;
        }


        .brand {

            color: var(--text);

            font-size: 24px;

            line-height: 1.08;

            letter-spacing: 7px;

            text-transform: uppercase;

            font-weight: 300;

            transition:
                color .25s ease;
        }


        .brand-divider {

            width: 1px;

            height: 62px;

            background: var(--border);

            transition:
                background .25s ease;
        }


        .page-heading h1 {

            margin: 0;

            font-size: 30px;

            line-height: 1.1;

            font-weight: 800;

            color: var(--text);
        }


        .page-heading p {

            margin: 6px 0 0;

            color: var(--muted);

            font-size: 15px;
        }


        .header-actions {

            display: flex;

            align-items: center;

            gap: 13px;
        }


        /* =========================================================
           BOTÓN TEMA GLOBAL
        ========================================================= */

        .theme-button {

            width: 50px;

            height: 50px;

            border-radius: 50%;

            border:
                1px solid var(--border);

            background: transparent;

            color: var(--text);

            cursor: pointer;

            font-size: 22px;

            display: flex;

            align-items: center;

            justify-content: center;

            transition:
                border-color .2s ease,
                transform .2s ease,
                background .2s ease,
                color .2s ease;
        }


        .theme-button:hover {

            border-color:
                var(--border-light);

            transform:
                translateY(-1px);
        }


        .user-name {

            color: var(--muted);

            font-size: 14px;

            white-space: nowrap;
        }


        .logout-button {

            min-height: 43px;

            padding:
                10px 18px;

            border: 0;

            border-radius: 8px;

            background: #ef4444;

            color: white;

            font-weight: 800;

            cursor: pointer;

            transition:
                background .2s ease,
                transform .2s ease;
        }


        .logout-button:hover {

            background: #dc2626;

            transform:
                translateY(-1px);
        }


        /* =========================================================
           CONTENEDOR
        ========================================================= */

        main {

            width:
                min(1120px, calc(100% - 36px));

            margin:
                0 auto;

            padding:
                30px 0 45px;
        }


        /* =========================================================
           VOLVER
        ========================================================= */

        .back-button {

            display: inline-flex;

            align-items: center;

            min-height: 40px;

            padding:
                9px 15px;

            margin-bottom: 22px;

            border:
                1px solid var(--border);

            border-radius: 8px;

            background: var(--card);

            color: var(--muted);

            text-decoration: none;

            font-size: 13px;

            font-weight: 800;

            transition:
                color .2s ease,
                border-color .2s ease,
                background .2s ease,
                transform .2s ease;
        }


        .back-button:hover {

            color: var(--text);

            border-color:
                var(--border-light);

            transform:
                translateY(-1px);
        }


        /* =========================================================
           CARD PRINCIPAL
        ========================================================= */

        .converter-card {

            background: var(--card);

            border:
                1px solid var(--border);

            border-radius: 16px;

            overflow: hidden;

            box-shadow: var(--shadow);

            transition:
                background .25s ease,
                border-color .25s ease,
                box-shadow .25s ease;
        }


        .card-header {

            padding:
                25px 28px 22px;

            border-bottom:
                1px solid var(--border);
        }


        .card-header h2 {

            margin: 0;

            font-size: 25px;

            font-weight: 800;

            color: var(--text);
        }


        .card-header p {

            margin:
                6px 0 0;

            color: var(--muted);

            font-size: 14px;
        }


        .card-body {

            padding:
                25px 28px 22px;
        }


        /* =========================================================
           SECCIONES
        ========================================================= */

        .section-title {

            display: flex;

            align-items: center;

            gap: 12px;

            margin-bottom: 13px;
        }


        .section-number {

            width: 38px;

            height: 38px;

            border-radius: 50%;

            border:
                1px solid var(--border);

            display: flex;

            align-items: center;

            justify-content: center;

            font-size: 16px;

            font-weight: 800;

            color: var(--text);

            transition:
                color .25s ease,
                border-color .25s ease;
        }


        .section-title span {

            font-size: 16px;

            font-weight: 800;

            letter-spacing: .4px;

            color: var(--text);
        }


        /* =========================================================
           SUBIDA
        ========================================================= */

        .upload-box {

            border:
                1px dashed var(--border-light);

            border-radius: 12px;

            min-height: 190px;

            background: var(--card-soft);

            display: flex;

            flex-direction: column;

            align-items: center;

            justify-content: center;

            text-align: center;

            padding: 25px;

            cursor: pointer;

            transition:
                border-color .2s ease,
                background .2s ease,
                transform .2s ease;
        }


        .upload-box:hover {

            border-color: var(--blue);

            background:
                var(--blue-soft);
        }


        .upload-box.dragover {

            border-color: var(--green);

            background:
                var(--green-soft);

            transform:
                scale(1.005);
        }


        .upload-icon {

            width: 50px;

            height: 50px;

            border-radius: 50%;

            background:
                var(--blue-soft);

            display: flex;

            align-items: center;

            justify-content: center;

            font-size: 25px;

            margin-bottom: 10px;
        }


        .upload-title {

            font-size: 19px;

            font-weight: 800;

            margin-bottom: 5px;

            color: var(--text);
        }


        .upload-description {

            max-width: 650px;

            color: var(--muted);

            font-size: 13px;

            line-height: 1.5;

            margin-bottom: 12px;
        }


        .select-button {

            min-height: 39px;

            padding:
                8px 17px;

            border:
                1px solid var(--border);

            border-radius: 8px;

            background: var(--card);

            color: var(--text);

            cursor: pointer;

            font-size: 13px;

            font-weight: 800;

            transition:
                border-color .2s ease,
                background .2s ease,
                color .2s ease;
        }


        .select-button:hover {

            border-color:
                var(--border-light);
        }


        .select-button:disabled {

            opacity: .5;

            cursor: not-allowed;
        }


        .upload-counter {

            margin-top: 8px;

            color: var(--muted);

            font-size: 12px;
        }


        .upload-box input[type="file"] {

            width: 100%;

            margin-top: 8px;

            padding: 9px;

            border: 1px solid var(--border);

            border-radius: 8px;

            background: var(--card);

            color: var(--text);
        }


        /* =========================================================
           ARCHIVOS
        ========================================================= */

        .files-container {

            display: grid;

            grid-template-columns:
                repeat(2, minmax(0, 1fr));

            gap: 10px;

            margin-top: 10px;
        }


        .file-item {

            min-width: 0;

            display: flex;

            align-items: center;

            justify-content: space-between;

            gap: 10px;

            padding:
                12px 13px;

            border:
                1px solid var(--border);

            border-radius: 9px;

            background: var(--card-soft);

            transition:
                background .25s ease,
                border-color .25s ease;
        }


        .file-info {

            min-width: 0;
        }


        .file-name {

            overflow: hidden;

            text-overflow: ellipsis;

            white-space: nowrap;

            font-size: 13px;

            font-weight: 800;

            color: var(--text);
        }


        .file-size {

            margin-top: 3px;

            color: var(--muted);

            font-size: 11px;
        }


        .file-regime {

            flex-shrink: 0;

            padding:
                5px 9px;

            border-radius: 999px;

            font-size: 11px;

            font-weight: 800;
        }


        .file-regime.subsidiado {

            color: #60a5fa;

            background:
                rgba(37,99,235,.18);
        }


        .file-regime.contributivo {

            color: #4ade80;

            background:
                rgba(22,163,74,.16);
        }


        .file-remove {

            width: 26px;

            height: 26px;

            border: 0;

            border-radius: 6px;

            background:
                var(--red-soft);

            color:
                var(--red);

            cursor: pointer;

            font-weight: 800;

            transition:
                background .2s ease,
                transform .2s ease;
        }


        .file-remove:hover {

            transform:
                scale(1.05);
        }


        /* =========================================================
           VALOR ADMINISTRATIVO
        ========================================================= */

        .admin-section {

            margin-top: 20px;
        }


        .input-label {

            display: block;

            margin-bottom: 7px;

            font-size: 13px;

            font-weight: 800;

            color: var(--text);
        }


        .admin-input {

            width: 100%;

            height: 43px;

            border:
                1px solid var(--border);

            border-radius: 8px;

            background: var(--card-soft);

            color: var(--text);

            padding:
                0 14px;

            outline: none;

            transition:
                border-color .2s ease,
                box-shadow .2s ease,
                background .25s ease,
                color .25s ease;
        }


        .admin-input:focus {

            border-color: var(--blue);

            box-shadow:
                0 0 0 3px
                var(--blue-soft);
        }


        /* =========================================================
           ALERTAS
        ========================================================= */

        .alert {

            padding:
                11px 14px;

            border-radius: 8px;

            margin-bottom: 14px;

            font-size: 13px;

            line-height: 1.4;
        }


        .alert-error {

            color: #fecaca;

            background:
                var(--red-soft);

            border:
                1px solid rgba(239,68,68,.55);
        }


        html[data-theme="light"] .alert-error {

            color: #991b1b;
        }


        /* =========================================================
           PROGRESO
        ========================================================= */

        .progress-area {

            display: none;

            margin-top: 15px;

            padding:
                14px 16px;

            border-radius: 9px;

            border:
                1px solid var(--border);

            background: var(--card-soft);
        }


        .progress-area.active {

            display: block;
        }


        .progress-header {

            display: flex;

            align-items: center;

            justify-content: space-between;

            margin-bottom: 8px;
        }


        .progress-title {

            font-size: 13px;

            font-weight: 800;

            color: var(--text);
        }


        .progress-percent {

            font-size: 13px;

            font-weight: 800;

            color: var(--green);
        }


        .progress-track {

            width: 100%;

            height: 10px;

            overflow: hidden;

            border-radius: 999px;

            background:
                rgba(127,145,165,.18);
        }


        .progress-bar {

            width: 0%;

            height: 100%;

            border-radius: 999px;

            background: var(--green);

            transition:
                width .25s ease;
        }


        .progress-status {

            margin-top: 7px;

            color: var(--muted);

            font-size: 11px;
        }


        /* =========================================================
           BOTONES
        ========================================================= */

        .actions {

            display: flex;

            align-items: center;

            justify-content: space-between;

            gap: 15px;

            margin-top: 18px;

            padding-top: 18px;

            border-top:
                1px solid var(--border);
        }


        .button {

            display: inline-flex;

            align-items: center;

            justify-content: center;

            min-height: 40px;

            padding:
                9px 16px;

            border-radius: 8px;

            text-decoration: none;

            font-size: 13px;

            font-weight: 800;

            cursor: pointer;

            transition:
                background .2s ease,
                border-color .2s ease,
                color .2s ease,
                transform .2s ease;
        }


        .button-secondary {

            background: transparent;

            color: var(--muted);

            border:
                1px solid var(--border);
        }


        .button-secondary:hover {

            color: var(--text);

            border-color:
                var(--border-light);

            transform:
                translateY(-1px);
        }


        .button-primary {

            background: var(--green);

            border:
                1px solid var(--green);

            color: white;
        }


        .button-primary:hover {

            background:
                var(--green-dark);

            border-color:
                var(--green-dark);

            transform:
                translateY(-1px);
        }


        .button:disabled {

            opacity: .55;

            cursor: not-allowed;

            transform: none;
        }


        /* =========================================================
           INFORMACIÓN
        ========================================================= */

        .info-box {

            margin-top: 12px;

            padding:
                10px 13px;

            border-radius: 8px;

            border:
                1px solid
                rgba(22,163,74,.35);

            background:
                var(--green-soft);

            color: var(--muted);

            font-size: 11px;

            line-height: 1.45;
        }


        .info-box strong {

            color: var(--green);
        }


        /* =========================================================
           RESPONSIVE
        ========================================================= */

        @media (max-width: 850px) {

            .topbar-inner {

                width:
                    calc(100% - 28px);

                padding:
                    20px 0;

                align-items:
                    flex-start;
            }


            .brand {

                font-size: 18px;

                letter-spacing: 4px;
            }


            .brand-divider {

                display: none;
            }


            .page-heading h1 {

                font-size: 23px;
            }


            .page-heading p {

                font-size: 13px;
            }


            .user-name {

                display: none;
            }


            main {

                width:
                    calc(100% - 28px);

                padding-top: 20px;
            }


            .files-container {

                grid-template-columns: 1fr;
            }


            .actions {

                flex-direction: column;

                align-items: stretch;
            }


            .actions .button {

                width: 100%;
            }

        }

    </style>

</head>


<body>


    <!-- =========================================================
         HEADER
    ========================================================== -->

    <header class="topbar">

        <div class="topbar-inner">


            <div class="brand-area">

                <div class="brand">

                    Comité de<br>
                    Estudios<br>
                    Médicos

                </div>


                <div class="brand-divider"></div>


                <div class="page-heading">

                    <h1>
                        Generador de Consolidado
                    </h1>

                    <p>
                        Conversión de archivos JSON a Excel
                    </p>

                </div>

            </div>


            <div class="header-actions">


                <!-- =================================================
                     TEMA GLOBAL
                ================================================== -->

                <button
                    type="button"
                    class="theme-button"
                    data-theme-toggle
                    title="Cambiar tema"
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



    <!-- =========================================================
         CONTENIDO
    ========================================================== -->

    <main>


        <a
            href="{{ route('dashboard') }}"
            class="back-button"
        >
            ← Volver al dashboard
        </a>



        <div class="converter-card">


            <!-- =================================================
                 CABECERA
            ================================================== -->

            <div class="card-header">

                <h2>
                    Generar nuevo consolidado
                </h2>

                <p>
                    Carga los archivos JSON correspondientes
                    a los regímenes subsidiado y contributivo.
                </p>

            </div>



            <!-- =================================================
                 FORMULARIO
            ================================================== -->

            <form
                id="converterForm"
                method="POST"
                action="{{ route('json-excel.convert') }}"
                enctype="multipart/form-data"
            >

                @csrf


                <div class="card-body">


                    <!-- =================================================
                         ERRORES
                    ================================================== -->

                    @if ($errors->any())

                        <div class="alert alert-error">

                            @foreach ($errors->all() as $error)

                                <div>
                                    {{ $error }}
                                </div>

                            @endforeach

                        </div>

                    @endif


                    @if (session('error'))

                        <div class="alert alert-error">

                            {{ session('error') }}

                        </div>

                    @endif



                    <!-- =================================================
                         PASO 1
                    ================================================== -->

                    <div class="section">


                        <div class="section-title">

                            <div class="section-number">
                                1
                            </div>

                            <span>
                                ARCHIVOS DE ENTRADA
                            </span>

                        </div>



                        <div class="files-container">

                            <div class="upload-box">
                                <div class="upload-icon">🏥</div>
                                <label class="upload-title" for="subsidiadoFiles">
                                    Lote subsidiado
                                </label>
                                <p class="upload-description">
                                    Selecciona uno o varios JSON que pertenezcan exclusivamente a este régimen.
                                </p>
                                <input
                                    type="file"
                                    id="subsidiadoFiles"
                                    name="subsidiado_files[]"
                                    accept=".json,.txt,application/json,text/plain"
                                    multiple
                                    required
                                >
                            </div>

                            <div class="upload-box">
                                <div class="upload-icon">⚕️</div>
                                <label class="upload-title" for="contributivoFiles">
                                    Lote contributivo
                                </label>
                                <p class="upload-description">
                                    Selecciona uno o varios JSON que pertenezcan exclusivamente a este régimen.
                                </p>
                                <input
                                    type="file"
                                    id="contributivoFiles"
                                    name="contributivo_files[]"
                                    accept=".json,.txt,application/json,text/plain"
                                    multiple
                                    required
                                >
                            </div>

                        </div>


                    </div>



                    <!-- =================================================
                         PASO 2
                    ================================================== -->

                    <div class="admin-section">


                        <div class="section-title">

                            <div class="section-number">
                                2
                            </div>

                            <span>
                                VALOR ADMINISTRATIVO
                            </span>

                        </div>


                        <label
                            for="valor_administrativo"
                            class="input-label"
                        >
                            Valor administrativo
                        </label>


                        <input
                            type="text"
                            id="valor_administrativo"
                            name="valor_administrativo"
                            class="admin-input"
                            value="{{ old('valor_administrativo', '3705644708') }}"
                            required
                        >

                    </div>



                    <!-- =================================================
                         PROGRESO
                    ================================================== -->

                    <div
                        class="progress-area"
                        id="progressArea"
                    >


                        <div class="progress-header">

                            <span class="progress-title">
                                Generando consolidado...
                            </span>


                            <span
                                class="progress-percent"
                                id="progressPercent"
                            >
                                0%
                            </span>

                        </div>


                        <div class="progress-track">

                            <div
                                class="progress-bar"
                                id="progressBar"
                            ></div>

                        </div>


                        <div
                            class="progress-status"
                            id="progressStatus"
                        >
                            Preparando archivos...
                        </div>


                    </div>



                    <!-- =================================================
                         BOTONES
                    ================================================== -->

                    <div class="actions">


                        <a
                            href="{{ route('dashboard') }}"
                            class="button button-secondary"
                        >
                            ← Cancelar
                        </a>


                        <button
                            type="submit"
                            class="button button-primary"
                            id="generateButton"
                        >
                            Generar consolidado
                        </button>


                    </div>



                    <!-- =================================================
                         INFORMACIÓN
                    ================================================== -->

                    <div class="info-box">

                        <strong>Importante:</strong>

                        selecciona al menos un archivo para cada régimen.
                        Puedes cargar hasta 25 JSON por lote. El sistema no
                        determina el régimen a partir del nombre del archivo:
                        verifica que cada JSON esté en su lote correcto.

                    </div>


                </div>


            </form>


        </div>


    </main>



    <!-- =========================================================
         JAVASCRIPT
    ========================================================== -->


    <!-- =========================================================
         TEMA GLOBAL
         IMPORTANTE: ESTE ARCHIVO ES EL MISMO QUE USA
         DASHBOARD, LOGIN, REGISTRO Y USUARIOS.
    ========================================================== -->

    <script src="{{ asset('js/theme.js') }}"></script>


<script>
        const form = document.getElementById('converterForm');
        const generateButton = document.getElementById('generateButton');
        const progressArea = document.getElementById('progressArea');
        const progressBar = document.getElementById('progressBar');
        const progressPercent = document.getElementById('progressPercent');
        const progressStatus = document.getElementById('progressStatus');

        function selectedFileCount(inputId) {
            return document.getElementById(inputId).files.length;
        }

        function selectedFileLabel(inputId, label) {
            const count = selectedFileCount(inputId);
            return count === 1
                ? `1 archivo de ${label}`
                : `${count} archivos de ${label}`;
        }

        form.addEventListener('submit', function (event) {
            const subsidiadoCount = selectedFileCount('subsidiadoFiles');
            const contributivoCount = selectedFileCount('contributivoFiles');

            if (subsidiadoCount === 0 || contributivoCount === 0) {
                event.preventDefault();
                alert('Selecciona al menos un JSON para Subsidiado y uno para Contributivo.');
                return;
            }

            event.preventDefault();

            const formData = new FormData(form);
            generateButton.disabled = true;
            generateButton.textContent = 'Generando...';
            progressArea.classList.add('active');
            progressBar.style.width = '0%';
            progressPercent.textContent = '0%';
            progressStatus.textContent =
                'Preparando ' + selectedFileLabel('subsidiadoFiles', 'Subsidiado')
                + ' y ' + selectedFileLabel('contributivoFiles', 'Contributivo') + '.';

            let progress = 0;
            const progressTimer = setInterval(function () {
                if (progress < 88) {
                    progress += 2;
                    progressBar.style.width = progress + '%';
                    progressPercent.textContent = progress + '%';

                    if (progress < 45) {
                        progressStatus.textContent = 'Cargando lotes JSON al servidor...';
                    } else if (progress < 75) {
                        progressStatus.textContent = 'Calculando duplicados y liquidando servicios...';
                    } else {
                        progressStatus.textContent = 'Generando consolidado Excel...';
                    }
                }
            }, 300);

            fetch(form.action, {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then(async function (response) {
                    clearInterval(progressTimer);

                    if (!response.ok) {
                        const body = await response.text();
                        throw new Error(body || 'El servidor devolvió un error.');
                    }

                    const blob = await response.blob();
                    const contentDisposition =
                        response.headers.get('content-disposition') || '';
                    const nameMatch = contentDisposition.match(/filename="?([^";]+)"?/i);
                    const fileName = nameMatch ? nameMatch[1] : 'CONSOLIDADO.xlsx';

                    progressBar.style.width = '100%';
                    progressPercent.textContent = '100%';
                    progressStatus.textContent = 'Consolidado generado correctamente.';
                    generateButton.textContent = 'Descargando...';

                    const url = window.URL.createObjectURL(blob);
                    const downloadLink = document.createElement('a');
                    downloadLink.href = url;
                    downloadLink.download = fileName;
                    document.body.appendChild(downloadLink);
                    downloadLink.click();
                    downloadLink.remove();
                    window.URL.revokeObjectURL(url);
                })
                .catch(function (error) {
                    clearInterval(progressTimer);
                    console.error(error);
                    alert('No fue posible generar el consolidado. Revisa los archivos y vuelve a intentarlo.');
                    progressArea.classList.remove('active');
                })
                .finally(function () {
                    generateButton.disabled = false;
                    generateButton.textContent = 'Generar consolidado';
                });
        });
    </script>


</body>

</html>
