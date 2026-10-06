@extends('layouts.app')

@section('title', $programa['nombre'].' | SIRUTA')
@section('header_title', $programa['nombre'])
@section('header_subtitle', 'Elige la ruta que vas a generar')

@section('content')
    <style>
        .la-maria-hub {
            width: min(760px, 100%);
            margin: 28px auto 0;
            padding: 32px;
        }

        .la-maria-hub h2 {
            margin: 0;
            font-size: 26px;
        }

        .la-maria-hub > p {
            margin: 12px 0 28px;
            color: var(--text-secondary);
            line-height: 1.6;
        }

        .la-maria-hub-choices {
            display: grid;
            gap: 14px;
        }

        .la-maria-hub-choices a {
            display: block;
            padding: 18px 20px;
            border: 1px solid var(--border-soft);
            border-radius: 10px;
            color: inherit;
            text-decoration: none;
            background: var(--surface-soft);
        }

        .la-maria-hub-choices a:hover {
            border-color: var(--green);
            box-shadow: inset 3px 0 var(--green);
        }

        .la-maria-hub-choices strong {
            display: block;
            font-size: 18px;
        }

        .la-maria-hub-choices small {
            display: block;
            margin-top: 6px;
            color: var(--text-secondary);
            line-height: 1.5;
        }
    </style>

    <a href="{{ route('dashboard') }}" class="back-button">
        ← Volver al dashboard
    </a>

    <section class="card la-maria-hub">
        <h2>¿Qué reporte de La María vas a sacar?</h2>
        <p>
            {{ $programa['descripcion'] }}
            Cada ejecución usa sus propias reglas de clasificación; los catálogos técnicos
            del programa son compartidos.
        </p>

        <div class="la-maria-hub-choices">
            @foreach ($programa['ejecuciones'] as $ejecucion)
                <a href="{{ route($ejecucion['ruta']) }}">
                    <strong>{{ $ejecucion['nombre'] }}</strong>
                    <small>{{ $ejecucion['descripcion'] }}</small>
                </a>
            @endforeach
        </div>
    </section>

@endsection
