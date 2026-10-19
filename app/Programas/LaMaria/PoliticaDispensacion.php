<?php

namespace App\Programas\LaMaria;

/**
 * Uso del precio de la dispensación administrativa cuando ninguna fuente
 * contractual (nota técnica, lista oficial o complemento verificado) tiene
 * tarifa para el medicamento.
 *
 * FASE_1 es la política vigente: el precio suma si el propio RIPS trae el CUM
 * dispensado al paciente en el mes. CONDICIONADA lo reporta como valor
 * condicionado sin sumarlo; solo se aplica con aprobación expresa.
 */
enum PoliticaDispensacion: string
{
    case Fase1 = 'FASE_1';
    case Condicionada = 'CONDICIONADA';

    public static function configurada(): self
    {
        if (! app()->bound('config')) {
            return self::Fase1;
        }

        return self::tryFrom((string) config('programas.la-maria.politica_dispensacion', self::Fase1->value)) ?? self::Fase1;
    }
}
