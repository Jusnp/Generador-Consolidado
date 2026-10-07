<?php

namespace App\Programas\LaMaria;

/**
 * Clasificación económica de un servicio frente al resumen mensual.
 */
enum ClaseCosto: string
{
    case Valido = 'VALIDO';
    case ValidoConAlerta = 'VALIDO_CON_ALERTA';
    case PotencialNoSuma = 'POTENCIAL_NO_SUMA';
    case SinCosto = 'SIN_COSTO';
}
