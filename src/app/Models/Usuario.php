<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\HasApiTokens;

class Usuario extends Authenticatable
{
    use HasFactory, Notifiable, HasApiTokens;

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

    // Método requerido por Authenticatable para usar 'contrasenia' en lugar de 'password'
    public function getAuthPassword()
    {
        return $this->contrasenia;
    }

    // Método para especificar el nombre del campo identificador (id_usuario en lugar de id)
    public function getAuthIdentifierName()
    {
        return 'id_usuario';
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
                    ->withPivot('fecha_ini', 'fecha_fin', 'activo', 'observaciones', 'asignado_por')
                    ->withTimestamps();
    }

    public function funcionesActivas()
    {
        return $this->belongsToMany(Funcion::class, 'asignacion_funcion', 'id_usuario', 'id_funcion')
            ->withPivot('fecha_ini', 'fecha_fin', 'activo', 'observaciones', 'asignado_por')
            ->wherePivot('activo', true)
            ->where(function($query) {
                $query->whereNull('asignacion_funcion.fecha_fin')
                    ->orWhere('asignacion_funcion.fecha_fin', '>=', now());
            })
            ->where('asignacion_funcion.fecha_ini', '<=', now())
            ->withTimestamps();
    }
}
