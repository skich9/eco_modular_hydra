<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Funcion extends Model
{
    use HasFactory;

    protected $table = 'funciones'; // Asegúrate que coincida con tu tabla
    protected $primaryKey = 'id_funcion';
    
    protected $fillable = [
        'nombre',
        'descripcion',
        'estado'
    ];
    
    // Opcional: Para evitar pluralización automática
    public function getTable()
    {
        return 'funciones';
    }
    
    // Relación con usuarios (a través de asignacion_funcion)
    public function usuarios()
    {
        return $this->belongsToMany(Usuario::class, 'asignacion_funcion', 'id_funcion', 'id_usuario')
                    ->withPivot('fecha_ini', 'fecha_fin', 'usuario_asig')
                    ->withTimestamps();
    }
}
