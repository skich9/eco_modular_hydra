<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ParametroCuota extends Model
{
	use HasFactory;

	protected $table = 'parametros_cuota';
	protected $primaryKey = 'id_parametro_cuota';
	public $incrementing = true;
	protected $keyType = 'int';

	protected $fillable = [
		'nombre_cuota',
		'fecha_vencimiento',
		'activo',
	];
}
