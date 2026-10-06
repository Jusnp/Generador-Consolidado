@auth
    <style>
        .siruta-menu-trigger {
            position: fixed;
            top: 14px;
            left: 14px;
            z-index: 1200;
            border: 1px solid #58718c;
            border-radius: 9px;
            padding: 10px 14px;
            background: #19222d;
            color: #fff;
            cursor: pointer;
            font: 600 14px system-ui, sans-serif;
            box-shadow: 0 8px 24px rgb(0 0 0 / 25%);
        }
        .siruta-menu-backdrop {
            position: fixed;
            inset: 0;
            z-index: 1290;
            border: 0;
            margin: 0;
            padding: 0;
            background: rgb(0 0 0 / 45%);
            cursor: pointer;
        }
        .siruta-menu-backdrop[hidden] { display: none; }
        .siruta-navigation {
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            z-index: 1300;
            width: min(320px, 88vw);
            height: 100dvh;
            margin: 0;
            border: 0;
            border-right: 1px solid #334252;
            padding: 24px;
            background: #19222d;
            color: #f4f7fa;
            font: 15px system-ui, sans-serif;
            overflow-y: auto;
            transform: translateX(-105%);
            transition: transform 0.22s ease;
            box-shadow: 12px 0 40px rgb(0 0 0 / 35%);
        }
        .siruta-navigation.is-open { transform: translateX(0); }
        .siruta-navigation header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
            gap: 12px;
        }
        .siruta-navigation button {
            cursor: pointer;
            background: transparent;
            color: inherit;
            border: 1px solid #58718c;
            border-radius: 8px;
            padding: 8px 12px;
        }
        .siruta-navigation a {
            display: block;
            padding: 12px;
            margin: 5px 0;
            color: inherit;
            text-decoration: none;
            border-radius: 8px;
        }
        .siruta-navigation a:hover,
        .siruta-navigation a[aria-current="page"] {
            background: rgb(22 163 74 / 18%);
            box-shadow: inset 3px 0 #16a34a;
        }
        .siruta-navigation small {
            display: block;
            font-size: 12px;
            margin-top: 5px;
            color: #a5b9cb;
        }
        .siruta-navigation h2 {
            margin-top: 24px;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: .07em;
        }
        html[data-theme="light"] .siruta-navigation,
        html[data-theme="light"] .siruta-menu-trigger {
            background: #fff;
            color: #142033;
            border-color: #d8e0e5;
        }
        html[data-theme="light"] .siruta-navigation small { color: #58718c; }
        html.siruta-menu-open { overflow: hidden; }
    </style>

    <button type="button" class="siruta-menu-trigger" id="siruta-menu-open" aria-controls="siruta-navigation" aria-expanded="false" aria-label="Abrir menú de programas">☰ Menú</button>
    <button type="button" class="siruta-menu-backdrop" id="siruta-menu-backdrop" hidden aria-label="Cerrar menú"></button>

    <aside class="siruta-navigation" id="siruta-navigation" aria-labelledby="siruta-menu-title" aria-hidden="true">
        <header>
            <strong id="siruta-menu-title">SIRUTA</strong>
            <button type="button" id="siruta-menu-close" aria-label="Cerrar menú">✕</button>
        </header>
        <nav aria-label="Navegación principal">
            <a href="{{ route('dashboard') }}">Inicio</a>

            <h2>Programas</h2>
            @foreach (app(\App\Programas\ProgramaRegistry::class)->all() as $slug => $definition)
                @php
                    $isCurrent = request()->route('programa') === $slug
                        || ($definition['hub'] && request()->routeIs('la-maria', 'la-maria.*'))
                        || ($definition['habilitado'] && $definition['ruta'] && ! $definition['hub'] && request()->routeIs($definition['ruta'], $definition['ruta'].'.*'));
                @endphp
                <a href="{{ route('programas.show', $slug) }}" @if ($isCurrent) aria-current="page" @endif>
                    {{ $definition['nombre'] }}
                    @if (! $definition['habilitado'])<small>Pendiente de configuración</small>@endif
                </a>
            @endforeach

            @if (auth()->user()->role === 'admin')
                <h2>Administración</h2>
                <a href="{{ route('catalogos-referencia.index') }}">Catálogos y contratos</a>
                <a href="{{ route('users.index') }}">Usuarios</a>
                <a href="{{ route('activity-logs.index') }}">Registro de actividad</a>
            @endif
        </nav>
    </aside>

    <script>
        (() => {
            const panel = document.getElementById('siruta-navigation');
            const backdrop = document.getElementById('siruta-menu-backdrop');
            const trigger = document.getElementById('siruta-menu-open');
            const closeButton = document.getElementById('siruta-menu-close');

            const openMenu = () => {
                panel.classList.add('is-open');
                panel.setAttribute('aria-hidden', 'false');
                backdrop.hidden = false;
                trigger.setAttribute('aria-expanded', 'true');
                document.documentElement.classList.add('siruta-menu-open');
            };

            const closeMenu = () => {
                panel.classList.remove('is-open');
                panel.setAttribute('aria-hidden', 'true');
                backdrop.hidden = true;
                trigger.setAttribute('aria-expanded', 'false');
                document.documentElement.classList.remove('siruta-menu-open');
                trigger.focus();
            };

            trigger.addEventListener('click', openMenu);
            closeButton.addEventListener('click', closeMenu);
            backdrop.addEventListener('click', closeMenu);
            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && panel.classList.contains('is-open')) {
                    closeMenu();
                }
            });
        })();
    </script>
@endauth
