<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CajaActividad extends Model
{
    protected $table = 'cajas_actividad';
    protected $primaryKey = 'id_caja_actividad';

    protected $fillable = [
        'nombre_caja',
        'descripcion',
        'orden',
        'prefijo',
    ];

    public function egresos(): HasMany
    {
        return $this->hasMany(EgresoCajaFuerte::class, 'id_caja_actividad');
    }
}
