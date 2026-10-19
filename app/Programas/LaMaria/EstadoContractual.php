<?php

namespace App\Programas\LaMaria;

/**
 * Estado contractual de un precio del complemento de medicamentos. Solo un
 * precio VERIFICADO y vigente se usa como tarifa; PENDIENTE se informa como
 * valor condicionado y RECHAZADO se conserva únicamente como historial.
 */
enum EstadoContractual: string
{
    case Pendiente = 'PENDIENTE';
    case Verificado = 'VERIFICADO';
    case Rechazado = 'RECHAZADO';
}
