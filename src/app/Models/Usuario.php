<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;

class Usuario extends Model
{
    use HasFactory, Notifiable;

    protected $table = 'usuarios';
    protected $primaryKey = 'id_usuario';

    protected $fillable = [
        'nickname',
        'nombre',
        'ap_paterno',
        'ap_materno',
        'contrasenia',
        'ci',
        'estado',
        'id_rol'
    ];

    protected $hidden = [
        'contrasenia',
    ];

    // Mutator para hashear la contraseña automáticamente
    public function setContraseniaAttribute($value)
    {
        $this->attributes['contrasenia'] = Hash::make($value);
    }

    // Relación con Rol
    public function rol()
    {
        return $this->belongsTo(Rol::class, 'id_rol', 'id_rol');
    }

    // Relación con Funciones (a través de asignacion_funcion)
    public function funciones()
    {
        return $this->belongsToMany(Funcion::class, 'asignacion_funcion', 'id_usuario', 'id_funcion')
                    ->withPivot('fecha_ini', 'fecha_fin', 'usuario_asig')
                    ->withTimestamps();
    }
}
