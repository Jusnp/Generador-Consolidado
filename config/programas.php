<?php

use App\Programas\Autoinmunes\AutoinmunesPipeline;
use App\Programas\LaMaria\LaMariaCervixPipeline;
use App\Programas\LaMaria\LaMariaProstataPipeline;

/**
 * Programas de ejecución SIRUTA (cada uno con pipeline y reglas propias).
 *
 * La selección ocurre solo desde el menú lateral. La María aparece como un solo
 * programa; dentro el usuario elige la ejecución (Próstata o Cérvix).
 */
return [
    'autoinmunes' => [
        'nombre' => 'Autoinmunes',
        'descripcion' => 'Consolidado RIPS de los regímenes subsidiado y contributivo.',
        'pipeline' => AutoinmunesPipeline::class,
    ],
    'la-maria' => [
        'nombre' => 'La María',
        'descripcion' => 'Seguimiento mensual de las rutas de próstata (C61) y cérvix (C53).',
        'pipeline' => null,
        'hub' => true,
        // Precio de dispensación administrativa sin tarifa contractual: FASE_1
        // (vigente, suma) o CONDICIONADA (se reporta sin sumar). Cambiarlo requiere aprobación.
        'politica_dispensacion' => env('LA_MARIA_POLITICA_DISPENSACION', 'FASE_1'),
        // Paquetes hospitalarios: POR_LOTE (predeterminado) o PERSISTENTE (libro entre
        // ejecuciones). PERSISTENTE exige un punto de inicio por ruta y su aprobación.
        'libro_paquetes' => env('LA_MARIA_LIBRO_PAQUETES', 'POR_LOTE'),
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
