<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Versión de un segmento de hospitalización de La María (ver migración).
 */
class HospitalSegmento extends Model
{
    protected $table = 'hospital_segmentos';

    protected $fillable = [
        'programa_slug', 'ruta', 'prestador', 'num_factura', 'tipo_documento', 'num_documento',
        'consecutivo_servicio', 'identidad', 'version', 'identidad_vigente', 'fecha_ingreso', 'fecha_egreso',
        'fecha_corte', 'fecha_ingreso_rips', 'fecha_egreso_rips', 'condicion_destino', 'num_autorizacion', 'cod_prestador_servicio', 'hash_contenido', 'estado',
        'reemplaza_segmento_id', 'estado_continuidad', 'archivo_origen', 'ejecucion_origen', 'programa_ejecucion_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'fecha_ingreso' => 'datetime',
            'fecha_egreso' => 'datetime',
            'fecha_corte' => 'datetime',
            'version' => 'integer',
        ];
    }
}
