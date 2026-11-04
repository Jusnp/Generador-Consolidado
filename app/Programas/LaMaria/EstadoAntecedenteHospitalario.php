<?php

namespace App\Programas\LaMaria;

/**
 * Calidad del antecedente del período anterior con que se procesa un mes.
 */
enum EstadoAntecedenteHospitalario: string
{
    /** Cierre vigente con su cadena al día, o calculado en la misma carga desde los RIPS del mes anterior. */
    case Comprobado = 'COMPROBADO';

    /** Existe, pero un JSON cargado del período cambió o su propio antecedente fue reemplazado: debe cerrarse de nuevo. */
    case Incompleto = 'INCOMPLETO';

    /** No hay cierre del período anterior. */
    case Ausente = 'AUSENTE';
}
