<?php

namespace App\Programas\LaMaria;

/**
 * Motivo por el que un paciente-mes quedó en su ruta de cálculo.
 */
enum OrigenRuta: string
{
    case Diagnostico = 'DIAGNOSTICO';
    case Sexo = 'SEXO';
    case Ninguno = 'NINGUNO';
}
