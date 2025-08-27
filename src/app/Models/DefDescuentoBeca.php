<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DefDescuentoBeca extends Model
{
	use HasFactory;

	protected $table = 'def_descuentos_beca';
	protected $primaryKey = 'cod_beca';
	public $incrementing = true;
	public $timestamps = false;

	protected $fillable = [
		'nombre_beca',
		'descripcion',
		'monto',
		'porcentaje',
		'estado',
	];

	protected $casts = [
		'cod_beca' => 'integer',
		'monto' => 'integer',
		'porcentaje' => 'boolean',
		'estado' => 'boolean',
	];
}
