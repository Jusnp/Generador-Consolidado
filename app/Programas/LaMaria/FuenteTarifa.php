<?php

namespace App\Programas\LaMaria;

/**
 * Fuente de la que proviene la tarifa aplicada a un servicio.
 * PACTADO_FUERA queda reservado hasta definir su soporte documental.
 */
enum FuenteTarifa: string
{
    case NotaTecnica = 'NOTA_TECNICA';
    case Complemento = 'COMPLEMENTO';
    case PactadoFuera = 'PACTADO_FUERA';
    case Dispensacion = 'DISPENSACION';
    case ReferenciaManual = 'REFERENCIA_MANUAL';
    case Ninguna = 'NINGUNA';
}
