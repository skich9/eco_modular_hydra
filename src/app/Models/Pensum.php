<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Pensum extends Model
{
    use HasFactory;
    
    protected $table = 'pensums';
    protected $primaryKey = 'cod_pensum';
    protected $keyType = 'string';
    public $incrementing = false;
    
    protected $fillable = [
        'cod_pensum',
        'codigo_carrera',
        'nombre',
        'descripcion',
        'cantidad_semestres',
        'orden',
        'nivel',
        'estado'
    ];
    
    protected $casts = [
        'estado' => 'boolean',
        'orden' => 'integer',
        'cantidad_semestres' => 'integer'
    ];
    
    // Relaciones
    public function materias()
    {
        return $this->hasMany(Materia::class, 'cod_pensum', 'cod_pensum');
    }
    
    public function costosemestrales()
    {
        return $this->hasMany(CostoSemestral::class, 'cod_pensum', 'cod_pensum');
    }
    
    public function carrera()
    {
        return $this->belongsTo(Carrera::class, 'codigo_carrera', 'codigo_carrera');
    }
}
