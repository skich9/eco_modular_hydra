<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Rol extends Model
{
    use HasFactory;

    protected $table = 'rol'; // Nombre exacto de la tabla
    
    protected $primaryKey = 'id_rol';
    protected $fillable = ['nombre', 'descripcion', 'estado'];
    
    // Añade esto para evitar pluralización
    public function getTable()
    {
        return 'rol';
    }
    
    // Relación con usuarios
    public function usuarios()
    {
        return $this->hasMany(Usuario::class, 'id_rol', 'id_rol');
    }
}