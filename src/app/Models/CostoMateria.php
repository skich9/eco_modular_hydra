<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CostoMateria extends Model
{
    use HasFactory;

    protected $table = 'costo_materia';
    protected $primaryKey = 'id_costo_materia';
    public $incrementing = true;
    public $timestamps = true;

    protected $fillable = [
        'cod_pensum',
        'sigla_materia',
        'gestion',
        'nro_creditos',
        'monto_materia',
        'id_usuario'
    ];

    protected $casts = [
        'nro_creditos' => 'decimal:2',
        'monto_materia' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    // Relación con la tabla materia
    public function materia()
    {
        // Relación por sigla; si se requiere cod_pensum simultáneo, se deberá ajustar manualmente en consultas
        return $this->belongsTo(Materia::class, 'sigla_materia', 'sigla_materia');
    }

    // Relación con la tabla gestion
    public function gestion()
    {
        return $this->belongsTo(Gestion::class, 'gestion', 'gestion');
    }

    // Relación con la tabla usuarios
    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'id_usuario', 'id_usuario');
    }
}
