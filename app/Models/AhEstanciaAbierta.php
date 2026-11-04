<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Estancia de una versión de un cierre hospitalario de La María (ver migración).
 */
class AhEstanciaAbierta extends Model
{
    protected $table = 'ah_estancias_abiertas';

    protected $fillable = [
        'antecedente_id', 'clave_paciente', 'autorizacion', 'cod_prestador', 'ingreso_original', 'inicio_tramo', 'fin_corte',
        'destino_final', 'cortes_08', 'bloques_reconocidos', 'bloques_periodo', 'tramos_origen', 'estado', 'continuidad', 'rectifica_version',
    ];

    protected function casts(): array
    {
        return [
            'cortes_08' => 'integer',
            'bloques_reconocidos' => 'integer',
            'bloques_periodo' => 'array',
            'tramos_origen' => 'array',
            'rectifica_version' => 'integer',
        ];
    }
}
