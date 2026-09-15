<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MedicationServiceClassification extends Model
{
    protected $fillable = ['descripcion_normalizada', 'servicio'];
}
