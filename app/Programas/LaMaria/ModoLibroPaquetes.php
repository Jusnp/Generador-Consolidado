<?php

namespace App\Programas\LaMaria;

/**
 * Cálculo de los paquetes hospitalarios de La María.
 *
 * POR_LOTE (predeterminado): solo con los archivos cargados en la ejecución.
 * PERSISTENTE: con el libro de paquetes entre ejecuciones; requiere que la ruta
 * tenga un punto de inicio registrado.
 */
enum ModoLibroPaquetes: string
{
    case PorLote = 'POR_LOTE';
    case Persistente = 'PERSISTENTE';

    public static function configurado(): self
    {
        if (! app()->bound('config')) {
            return self::PorLote;
        }

        return self::tryFrom((string) config('programas.la-maria.libro_paquetes', self::PorLote->value)) ?? self::PorLote;
    }
}
