<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SIRUTA | Comité de Estudios Médicos</title>
    <link rel="icon" href="{{ asset('favicon.png') }}?v=2" type="image/png">
    <link rel="shortcut icon" href="{{ asset('favicon.ico') }}?v=2">

    <script>
        (function () {
            const theme = localStorage.getItem('json-excel-theme') || 'dark';
            document.documentElement.setAttribute('data-theme', theme);
        })();
    </script>

    <style>
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
            --accent-soft: rgba(22, 163, 74, 0.12);
            --shadow: 0 20px 45px rgba(0, 0, 0, 0.22);
        }

        html[data-theme='light'] {
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
            --accent-soft: rgba(22, 163, 74, 0.1);
            --shadow: 0 18px 40px rgba(15, 23, 42, 0.09);
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            min-height: 100%;
            margin: 0;
            padding: 0;
        }

        body {
            background: var(--bg);
            color: var(--text);
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            transition: background 0.25s ease, color 0.25s ease;
        }

        .page {
            min-height: 100vh;
        }

        .header {
            border-bottom: 1px solid var(--border);
            background: var(--header);
            transition: background 0.25s ease, border-color 0.25s ease;
        }

        .header-inner {
            width: min(1260px, calc(100% - 48px));
            min-height: 116px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 30px;
            margin: 0 auto;
        }

        .brand-area {
            display: flex;
            align-items: center;
            gap: 28px;
        }

        .brand {
            width: 205px;
            color: var(--text);
            font-size: 21px;
            font-weight: 500;
            letter-spacing: 5px;
            line-height: 1.12;
        }

        .brand-divider {
            width: 1px;
            height: 62px;
            background: var(--border);
        }

        .page-heading h1 {
            margin: 0 0 5px;
            color: var(--text);
            font-size: 31px;
            font-weight: 800;
            line-height: 1.15;
        }

        .page-heading p {
            margin: 0;
            color: var(--muted);
            font-size: 15px;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .theme-button {
            border: 1px solid var(--border);
            background: transparent;
            color: var(--text);
            transition: background 0.2s ease, transform 0.2s ease;
        }

        .theme-button:hover {
            background: var(--card-hover);
            transform: scale(1.04);
        }

        .content {
            width: min(1080px, calc(100% - 48px));
            min-height: calc(100vh - 116px);
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(360px, 0.82fr);
            align-items: center;
            gap: clamp(52px, 10vw, 150px);
            margin: 0 auto;
            padding: 45px 0 60px;
        }

        .welcome {
            max-width: 510px;
        }

        .welcome-label {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border: 1px solid var(--border);
            border-radius: 999px;
            color: var(--green);
            background: var(--accent-soft);
            font-size: 13px;
            font-weight: 700;
        }

        .welcome-label::before {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--green);
            content: '';
        }

        .welcome h2 {
            max-width: 11ch;
            margin: 22px 0 15px;
            color: var(--text);
            font-size: clamp(38px, 5vw, 60px);
            font-weight: 800;
            letter-spacing: -0.055em;
            line-height: 0.98;
        }

        .welcome h2 span {
            color: var(--green);
        }

        .welcome p {
            max-width: 490px;
            margin: 0;
            color: var(--muted);
            font-size: 16px;
            line-height: 1.7;
        }

        .login-area {
            width: 100%;
            max-width: 400px;
            justify-self: end;
        }

        .login-card {
            width: 100%;
            padding: 36px;
            border: 1px solid var(--border);
            border-radius: 18px;
            background: var(--card);
            box-shadow: var(--shadow);
            transition: background 0.25s ease, border-color 0.25s ease;
        }

        .login-icon {
            width: 58px;
            height: 58px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 18px;
            border-radius: 14px;
            color: var(--green);
            background: var(--accent-soft);
            font-size: 28px;
        }

        .login-heading {
            margin-bottom: 28px;
            text-align: center;
        }

        .login-heading h2 {
            margin: 0;
            color: var(--text);
            font-size: 27px;
            font-weight: 800;
            line-height: 1.2;
        }

        .login-heading p {
            margin: 9px 0 0;
            color: var(--muted);
            font-size: 14px;
        }

        .errors,
        .success {
            margin-bottom: 22px;
            padding: 14px 16px;
            border-radius: 9px;
            font-size: 14px;
            line-height: 1.5;
        }

        .errors {
            border: 1px solid rgba(239, 68, 68, 0.45);
            color: #f87171;
            background: rgba(239, 68, 68, 0.1);
        }

        .errors div {
            margin: 3px 0;
        }

        .success {
            border: 1px solid rgba(22, 163, 74, 0.35);
            color: var(--green);
            background: var(--accent-soft);
        }

        .login-button {
            width: 100%;
            min-height: 48px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            text-decoration: none;
            font: inherit;
            font-size: 15px;
            font-weight: 800;
            cursor: pointer;
            transition: background 0.2s ease, border-color 0.2s ease, transform 0.2s ease;
        }

        .login-button {
            border: 0;
            color: #ffffff;
            background: var(--green);
        }

        .login-button:hover {
            background: var(--green-hover);
            transform: translateY(-1px);
        }

        .authentik-note {
            margin: 20px 0 0;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.55;
            text-align: center;
        }

        .footer {
            margin-top: 24px;
            color: var(--muted);
            font-size: 12px;
            text-align: center;
        }

        @media (max-width: 900px) {
            .header-inner {
                min-height: auto;
                align-items: flex-start;
                flex-direction: column;
                padding: 22px 0;
            }

            .header-actions {
                width: 100%;
                justify-content: flex-start;
            }

            .content {
                grid-template-columns: 1fr;
                gap: 42px;
                padding: 55px 0 60px;
            }

            .welcome {
                max-width: 640px;
            }

            .login-area {
                max-width: 460px;
                justify-self: start;
            }
        }

        @media (max-width: 650px) {
            .header-inner,
            .content {
                width: min(100% - 28px, 1260px);
            }

            .brand-area {
                gap: 15px;
            }

            .brand {
                width: auto;
                font-size: 16px;
                letter-spacing: 3px;
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

            .login-card {
                padding: 27px 21px;
            }
        }
    </style>
</head>
<body>
    <div class="page">
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
                        <h1>SIRUTA</h1>
                        <p>Sistema Integral de RIPS y Seguimiento de Rutas</p>
                    </div>
                </div>

                <div class="header-actions">
                    <button type="button" class="theme-button" data-theme-toggle aria-label="Cambiar tema" title="Cambiar tema">☀️</button>
                </div>
            </div>
        </header>

        <main class="content">
            <section class="welcome" aria-labelledby="welcome-title">
                <span class="welcome-label">Portal institucional</span>
                <h2 id="welcome-title">RIPS y rutas, <span>en un mismo lugar.</span></h2>
                <p>
                    Accede a la consolidación de RIPS, al seguimiento de rutas y a los reportes institucionales desde un único lugar.
                </p>
            </section>

            <div class="login-area">
                <section class="login-card" aria-labelledby="login-title">
                    <div class="login-heading">
                        <div class="login-icon" aria-hidden="true">🔐</div>
                        <h2 id="login-title">Iniciar sesión</h2>
                        <p>Valida tu identidad con tu cuenta institucional.</p>
                    </div>

                    @if ($errors->any())
                        <div class="errors" role="alert">
                            @foreach ($errors->all() as $error)
                                <div>{{ $error }}</div>
                            @endforeach
                        </div>
                    @endif

                    @if (session('success'))
                        <div class="success" role="status">{{ session('success') }}</div>
                    @endif

                    <a class="login-button authentik-login-button" href="{{ route('authentik.redirect') }}">
                        Ingresar con Authentik
                    </a>

                    <p class="authentik-note">
                        El acceso es habilitado por un administrador y queda registrado para auditoría.
                    </p>
                </section>

                <div class="footer">SIRUTA · Comité de Estudios Médicos</div>
            </div>
        </main>
    </div>

    <script src="{{ asset('js/theme.js') }}"></script>
</body>
</html>
