<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EgresoCajaFuerte extends Model
{
    protected $table = 'egresos_caja_fuerte';
    protected $primaryKey = 'codigo_egreso';

    protected $fillable = [
        'correlativo',
        'fecha_egreso',
        'monto',
        'descripcion',
        'observacion',
        'usuario',
        'usuario_modifica',
        'anular',
        'motivo_anulacion',
        'id_caja_actividad',
    ];

    protected $casts = [
        'monto'       => 'decimal:2',
        'anular'      => 'boolean',
        'fecha_egreso' => 'date',
    ];

    public function caja(): BelongsTo
    {
        return $this->belongsTo(CajaActividad::class, 'id_caja_actividad');
    }
}
