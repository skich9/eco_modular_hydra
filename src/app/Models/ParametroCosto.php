<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ParametroCosto extends Model
{
	use HasFactory;

	protected $table = 'parametros_costos';
	protected $primaryKey = 'id_parametro_costo';
	public $incrementing = true;

	protected $fillable = [
		'nombre',
		'valor',
		'descripcion',
		'gestion',
		'estado'
	];

	protected $casts = [
		'valor' => 'decimal:2',
		'estado' => 'boolean'
	];
}
