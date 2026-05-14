<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SgaPushCobro extends Model
{
    protected $table = 'sga_push_cobros';

    protected $fillable = [
        'cobro_uid',
        'nro_cobro',
        'anio_cobro',
        'cod_ceta',
        'cod_pensum',
        'destino_conn',
        'destino_tabla',
        'payload',
        'response',
        'sincronizado',
        'intentos',
        'ultimo_error',
        'sincronizado_at',
    ];

    protected $casts = [
        'payload'        => 'array',
        'response'       => 'array',
        'sincronizado'   => 'boolean',
        'sincronizado_at'=> 'datetime',
    ];

    public function scopePendientes($query)
    {
        return $query->where('sincronizado', false);
    }

    public function scopePorConexion($query, string $conn)
    {
        return $query->where('destino_conn', $conn);
    }
}
