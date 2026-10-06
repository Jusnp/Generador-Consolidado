<?php

namespace App\Programas\LaMaria;

/**
 * Identidad compartida del programa La María (catálogos y dominio).
 *
 * Las ejecuciones de Próstata y Cérvix son pipelines independientes;
 * comparten solo el espacio de catálogos técnicos del programa.
 */
final class LaMariaPrograma
{
    public const SLUG = 'la-maria';

    public const RUTA_PROSTATA = 'PROSTATA';

    public const RUTA_CERVIX = 'CERVIX';

    public const CATALOG_OWNERSHIP_LABEL = 'La María · catálogos de referencia';
}
