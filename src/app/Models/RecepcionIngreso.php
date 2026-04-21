<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecepcionIngreso extends Model
{
    protected $table = 'recepcion_ingresos';

    protected $fillable = [
        'codigo_carrera',
        'fecha_recepcion',
        'fecha_registro',
        'usuario_entregue1',
        'usuario_recibi1',
        'usuario_entregue2',
        'usuario_recibi2',
        'usuario_registro',
        'cod_documento',
        'num_documento',
        'monto_total',
        'id_actividad_economica',
        'es_ingreso_libro_diario',
        'anulado',
        'motivo_anulacion',
        'observacion',
    ];

    protected $casts = [
        'fecha_recepcion'       => 'date',
        'fecha_registro'        => 'datetime',
        'monto_total'           => 'decimal:2',
        'anulado'               => 'boolean',
        'es_ingreso_libro_diario' => 'boolean',
    ];

    /**
     * Detalles (uno por tesorero/libro diario) de esta recepción.
     */
    public function detalles()
    {
        return $this->hasMany(RecepcionIngresoDetalle::class, 'recepcion_ingreso_id');
    }
}
