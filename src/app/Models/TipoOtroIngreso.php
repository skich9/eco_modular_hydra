<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TipoOtroIngreso extends Model
{
	use HasFactory;

	protected $table = 'tipo_otro_ingreso';

	protected $fillable = [
		'cod_tipo_ingreso',
		'nom_tipo_ingreso',
		'descripcion_tipo_ingreso',
	];
}
