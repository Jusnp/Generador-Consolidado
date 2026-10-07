<?php

namespace App\Programas\LaMaria;

/**
 * Confiabilidad de la tarifa aplicada a un servicio.
 */
enum EstadoTarifario: string
{
    case ConTarifa = 'CON_TARIFA';
    case SinTarifa = 'SIN_TARIFA';
    case RevisarPresentacion = 'REVISAR_PRESENTACION';
    case PrecioConflictivo = 'PRECIO_CONFLICTIVO';
    case SinPrecioVigente = 'SIN_PRECIO_VIGENTE';
}
