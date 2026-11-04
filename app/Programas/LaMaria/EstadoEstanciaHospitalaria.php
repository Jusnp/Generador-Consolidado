<?php

namespace App\Programas\LaMaria;

/**
 * Estado de una estancia en el cierre hospitalario de un período. Solo las
 * estancias abiertas al cierre (corte 08) pueden unirse a una continuación del
 * período siguiente; las demás son evidencia y nunca generan unidades.
 */
enum EstadoEstanciaHospitalaria: string
{
    /** Abierta al cierre; su estancia inicia en el período o tiene antecedente verificado. */
    case AbiertaVerificada = 'ABIERTA_VERIFICADA';

    /** Continuación unida a una estancia verificada del período anterior y aún abierta al cierre. */
    case ContinuacionAcreditada = 'CONTINUACION_ACREDITADA';

    /** Abierta al cierre, pero su inicio o su antecedente no están acreditados: lo que dependa de ella queda condicionado. */
    case PendienteDeSoporte = 'PENDIENTE_DE_SOPORTE';

    /** Abierta en el período anterior; el período cerrado no trae su continuación. No genera unidades. */
    case SinContinuacionObservada = 'SIN_CONTINUACION_OBSERVADA';

    /** Continuación acreditada que termina con egreso real dentro del período. */
    case CerradaConSoporte = 'CERRADA_CON_SOPORTE';

    /** Estancia de una versión anterior del mismo período que la nueva versión corrige o ya no contiene. */
    case Rectificada = 'RECTIFICADA';

    public function abierta(): bool
    {
        return in_array($this, [self::AbiertaVerificada, self::ContinuacionAcreditada, self::PendienteDeSoporte], true);
    }

    public function verificada(): bool
    {
        return in_array($this, [self::AbiertaVerificada, self::ContinuacionAcreditada], true);
    }
}
