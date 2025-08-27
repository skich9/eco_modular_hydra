<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DefDescuento extends Model
{
	use HasFactory;

	protected $table = 'def_descuentos';
	protected $primaryKey = 'cod_descuento';
	public $incrementing = true;
	public $timestamps = false;

	protected $fillable = [
		'nombre_descuento',
		'descripcion',
		'monto',
		'porcentaje',
		'estado',
	];

	protected $casts = [
		'cod_descuento' => 'integer',
		'monto' => 'integer',
		'porcentaje' => 'boolean',
		'estado' => 'boolean',
	];
}
