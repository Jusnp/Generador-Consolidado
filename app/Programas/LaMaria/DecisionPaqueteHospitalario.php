<?php

namespace App\Programas\LaMaria;

/**
 * Decisión contractual para un servicio prestado durante una hospitalización
 * o una urgencia (estancia de menos de 24 horas).
 */
enum DecisionPaqueteHospitalario: string
{
    case IncluidoEnPaquete = 'INCLUIDO_EN_PAQUETE';
    case CobrablePorFuera = 'COBRABLE_POR_FUERA';
    case ReglaNoDefinida = 'REGLA_NO_DEFINIDA';
    case CobradoComoUrgencia = 'COBRADO_COMO_URGENCIA';
    case IncluidoEnUrgencia = 'INCLUIDO_EN_URGENCIA';
    case UrgenciaTarifaPendiente = 'URGENCIA_TARIFA_PENDIENTE';
}
