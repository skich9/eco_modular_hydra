<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Rezagado extends Model
{
    protected $table = 'rezagados';
    public $incrementing = false; // composite PK
    public $timestamps = true;

    protected $primaryKey = null; // handled manually (composite)

    protected $fillable = [
        'cod_inscrip',
        'num_rezagado',
        'num_pago_rezagado',
        'num_factura',
        'num_recibo',
        'fecha_pago',
        'monto',
        'pago_completo',
        'observaciones',
        'usuario',
        'materia',
        'parcial',
    ];

    protected $casts = [
        'fecha_pago' => 'datetime',
        'pago_completo' => 'boolean',
        'monto' => 'decimal:2',
    ];
}
