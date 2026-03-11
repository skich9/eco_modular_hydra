<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RolFuncion extends Model
{
	use HasFactory;

	protected $table = 'rol_funcion';
	protected $primaryKey = 'id_rol_funcion';

	protected $fillable = [
		'id_rol',
		'id_funcion'
	];

	public function rol()
	{
		return $this->belongsTo(Rol::class, 'id_rol', 'id_rol');
	}

	public function funcion()
	{
		return $this->belongsTo(Funcion::class, 'id_funcion', 'id_funcion');
	}
}
