<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Funcion extends Model
{
	use HasFactory;

	protected $table = 'funciones';
	protected $primaryKey = 'id_funcion';

	protected $fillable = [
		'codigo',
		'nombre',
		'descripcion',
		'modulo',
		'icono',
		'activo'
	];

	protected $casts = [
		'activo' => 'boolean',
	];

	public function roles()
	{
		return $this->belongsToMany(Rol::class, 'rol_funcion', 'id_funcion', 'id_rol')
			->withTimestamps();
	}

	public function usuarios()
	{
		return $this->belongsToMany(Usuario::class, 'asignacion_funcion', 'id_funcion', 'id_usuario')
			->withPivot('fecha_ini', 'fecha_fin', 'activo', 'observaciones', 'asignado_por')
			->withTimestamps();
	}

	public function scopeActivas($query)
	{
		return $query->where('activo', true);
	}

	public function scopePorModulo($query, $modulo)
	{
		return $query->where('modulo', $modulo);
	}
}
