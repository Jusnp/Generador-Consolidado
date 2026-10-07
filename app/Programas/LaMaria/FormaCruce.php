<?php

namespace App\Programas\LaMaria;

/**
 * Forma en que el servicio del RIPS se relacionó con su fuente de tarifa.
 */
enum FormaCruce: string
{
    case CodigoExacto = 'CODIGO_EXACTO';
    case CumExacto = 'CUM_EXACTO';
    case PrincipioTarifaUnica = 'PRINCIPIO_TARIFA_UNICA';
    case PrincipioAmbiguo = 'PRINCIPIO_AMBIGUO';
    case DocumentoCumMes = 'DOCUMENTO_CUM_MES';
    case ManualCodigo = 'MANUAL_CODIGO';
    case SinCruce = 'SIN_CRUCE';
}
