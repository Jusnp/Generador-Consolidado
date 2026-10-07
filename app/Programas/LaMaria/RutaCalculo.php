<?php

namespace App\Programas\LaMaria;

/**
 * Ruta en la que se procesan los servicios y costos de un paciente-mes.
 */
enum RutaCalculo: string
{
    case Prostata = 'PROSTATA';
    case Cervix = 'CERVIX';
    case NoAsignable = 'NO_ASIGNABLE';
}
