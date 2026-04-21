<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecepcionIngresoDetalle extends Model
{
    protected $table = 'recepcion_ingreso_detalles';

    public $timestamps = false;

    protected $fillable = [
        'recepcion_ingreso_id',
        'usuario_libro',
        'cod_libro_diario',
        'fecha_inicial_libros',
        'fecha_final_libros',
        'total_deposito',
        'total_traspaso',
        'total_recibos',
        'total_facturas',
        'total_entregado',
        'faltante_sobrante',
    ];

    protected $casts = [
        'fecha_inicial_libros' => 'date',
        'fecha_final_libros'   => 'date',
        'total_deposito'       => 'decimal:2',
        'total_traspaso'       => 'decimal:2',
        'total_recibos'        => 'decimal:2',
        'total_facturas'       => 'decimal:2',
        'total_entregado'      => 'decimal:2',
        'faltante_sobrante'    => 'decimal:2',
    ];

    /**
     * Recepción cabecera a la que pertenece este detalle.
     */
    public function recepcion()
    {
        return $this->belongsTo(RecepcionIngreso::class, 'recepcion_ingreso_id');
    }
}
