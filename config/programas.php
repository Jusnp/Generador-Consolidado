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
