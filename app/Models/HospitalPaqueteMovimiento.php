<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Movimiento del libro de paquetes hospitalarios de La María (ver migración).
 */
class HospitalPaqueteMovimiento extends Model
{
    protected $table = 'hospital_paquete_movimientos';

    protected $fillable = [
        'programa_slug', 'ruta', 'tipo_documento', 'num_documento', 'episodio_clave', 'segmento_ancla_id', 'segmentos',
        'bloque_inicio', 'numero_bloque', 'mes_contable', 'signo', 'valor', 'tarifa_paquete', 'motivo', 'estado_episodio',
        'movimiento_revertido_id', 'bloque_vigente', 'clave_idempotencia', 'ejecucion_origen', 'programa_ejecucion_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'bloque_inicio' => 'datetime',
            'segmentos' => 'array',
            'signo' => 'integer',
            'valor' => 'decimal:2',
            'tarifa_paquete' => 'decimal:2',
        ];
    }
}
