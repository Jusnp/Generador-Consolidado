<?php

use App\Programas\Autoinmunes\AutoinmunesPipeline;
use App\Programas\LaMaria\LaMariaCervixPipeline;
use App\Programas\LaMaria\LaMariaProstataPipeline;

/**
 * Programas de ejecución SIRUTA (cada uno con pipeline y reglas propias).
 *
 * La selección ocurre solo desde el menú lateral. La María aparece como un solo
 * programa; dentro el usuario elige la ejecución (Próstata o Cérvix).
 *
 * «roles» define quién puede usar cada programa (se valida en el servidor); sin
 * la clave, el programa es solo para administradores.
 */
return [
    'autoinmunes' => [
        'nombre' => 'Autoinmunes',
        'descripcion' => 'Consolidado RIPS de los regímenes subsidiado y contributivo.',
        'pipeline' => AutoinmunesPipeline::class,
        'roles' => ['admin', 'user'],
    ],
    'la-maria' => [
        'nombre' => 'La María',
        'descripcion' => 'Seguimiento mensual de las rutas de próstata (C61) y cérvix (C53).',
        'pipeline' => null,
        'hub' => true,
        'roles' => ['admin', 'user'],
        // Precio de dispensación administrativa sin tarifa contractual: FASE_1
        // (vigente, suma) o CONDICIONADA (se reporta sin sumar). Cambiarlo requiere aprobación.
        'politica_dispensacion' => env('LA_MARIA_POLITICA_DISPENSACION', 'FASE_1'),
        // Paquetes hospitalarios: POR_LOTE (predeterminado) o PERSISTENTE (libro entre
        // ejecuciones). PERSISTENTE exige un punto de inicio por ruta y su aprobación.
        'libro_paquetes' => env('LA_MARIA_LIBRO_PAQUETES', 'POR_LOTE'),
        // R-B: la cirugía acreditada de una estancia de menos de 24 horas recibe la excepción
        // cobrable por fuera de la política hospitalaria (igual que en una estancia de 24 horas
        // o más). Sin acuerdo contractual firmado queda desactivada y la cirugía sigue condicionada.
        'excepcion_cirugia_estancia_corta' => (bool) env('LA_MARIA_EXCEPCION_CIRUGIA_ESTANCIA_CORTA', false),
        // Historial hospitalario versionado (tablas ah_antecedentes y ah_estancias_abiertas): con ACTIVO
        // y libro POR_LOTE, cada mes usa el cierre del mes anterior guardado con la-maria:cerrar-periodo.
        // DESACTIVADO conserva la continuidad dentro del lote. El secreto (HMAC del paciente) es obligatorio
        // al activarlo y no debe cambiar: las claves guardadas dependen de él.
        'historial_hospitalario' => [
            'modo' => env('LA_MARIA_HISTORIAL_HOSPITALARIO', 'DESACTIVADO'),
            'secreto' => env('LA_MARIA_HISTORIAL_SECRETO'),
            // Único período (aaaa-mm) autorizado para cerrar sin antecedente (--arranque). Sin valor no hay arranque.
            'arranque' => env('LA_MARIA_HISTORIAL_ARRANQUE'),
        ],
        // Informe administrativo histórico de cada ruta (hoja «inf admin»). Se lee
        // automáticamente; un informe cargado en el formulario reemplaza al de su ruta.
        // Si es requerido y falta, el reporte se detiene con un error claro.
        'informes_administrativos' => [
            'requerido' => (bool) env('LA_MARIA_INFORMES_ADMIN_REQUERIDOS', true),
            'PROSTATA' => env('LA_MARIA_INFORME_ADMIN_PROSTATA'),
            'CERVIX' => env('LA_MARIA_INFORME_ADMIN_CERVIX'),
        ],
        'ejecuciones' => [
            'prostata' => [
                'nombre' => 'Próstata',
                'descripcion' => 'Ruta de próstata (C61).',
                'pipeline' => LaMariaProstataPipeline::class,
            ],
            'cervix' => [
                'nombre' => 'Cérvix',
                'descripcion' => 'Ruta de cérvix (C53).',
                'pipeline' => LaMariaCervixPipeline::class,
            ],
        ],
    ],
    'salud-mental' => [
        'nombre' => 'Salud Mental',
        'descripcion' => 'Ejecución pendiente: reglas y catálogos propios aún no definidos.',
        'pipeline' => null,
    ],
    'coosalud' => [
        'nombre' => 'Coosalud',
        'descripcion' => 'Ejecución pendiente: reglas y catálogos propios aún no definidos.',
        'pipeline' => null,
    ],
    'nueva-eps' => [
        'nombre' => 'Nueva EPS',
        'descripcion' => 'Ejecución pendiente: reglas y catálogos propios aún no definidos.',
        'pipeline' => null,
    ],
];
