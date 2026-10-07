<?php

namespace App\Programas\LaMaria;

/**
 * Coincidencias de un servicio con otros servicios cargados.
 * Solo la copia exacta de un registro deja de sumar.
 */
enum EstadoDuplicidad: string
{
    case Ninguno = 'NINGUNO';
    case PosibleDobleFacturacion = 'POSIBLE_DOBLE_FACTURACION';
    case RepeticionMismaFactura = 'REPETICION_MISMA_FACTURA';
    case DuplicadoExacto = 'DUPLICADO_EXACTO';
}
