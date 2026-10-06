@extends('layouts.app')
@section('title', $programa['nombre'].' | SIRUTA')
@section('header_title', $programa['nombre'])
@section('header_subtitle', 'Programa pendiente de configuración')
@section('content')
    <section class="card" style="max-width: 760px; margin: 24px auto; padding: 28px;">
        <h2>Este programa todavía no está habilitado</h2>
        <p>
            {{ $programa['descripcion'] !== '' ? $programa['descripcion'] : 'Para generar reportes deben definirse sus reglas, contrato, catálogos y formato de salida.' }}
        </p>
        <p style="margin-top: 12px; color: var(--text-secondary);">
            Cada programa tiene un pipeline de ejecución independiente. Al habilitarlo se crearán
            sus propias reglas de negocio y catálogos, sin reutilizar los de Autoinmunes ni de
            La María.
        </p>
        <a class="btn btn-secondary" href="{{ route('dashboard') }}">Volver al inicio</a>
        @if (auth()->user()->role === 'admin')
            @if (auth()->user()->role === 'admin')
                <a class="btn btn-secondary" href="{{ route('catalogos-referencia.index') }}">Configurar notas técnicas y catálogos</a>
            @endif
        @endif
    </section>
@endsection
