<?php

namespace App\Programas\LaMaria;

/**
 * Alertas clínicas y de calidad de un paciente-mes. Un paciente-mes puede
 * tener varias; ninguna implica por sí sola excluir su costo del resumen.
 */
enum EstadoClinico: string
{
    case Correcto = 'CORRECTO';
    case SinDiagnosticoRuta = 'SIN_DIAGNOSTICO_RUTA';
    case InconsistenciaSexoRuta = 'INCONSISTENCIA_SEXO_RUTA';
    case DobleRuta = 'DOBLE_RUTA';
    case SexoNoReconocido = 'SEXO_NO_RECONOCIDO';
    case SinDocumento = 'SIN_DOCUMENTO';
    case SinFecha = 'SIN_FECHA';
}
