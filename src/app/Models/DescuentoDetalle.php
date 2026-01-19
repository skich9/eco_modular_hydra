<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DescuentoDetalle extends Model
{
	use HasFactory;

	protected $table = 'descuento_detalle';
	protected $primaryKey = 'id_descuento_detalle';
	public $incrementing = true;

	protected $fillable = [
		'id_descuento',
		'id_inscripcion',
		'id_cuota',
		'monto_descuento',
		'cod_Archivo',
		'observaciones',
		'tipo_inscripcion',
		'meses_descuento',
	];

	protected $casts = [
		'id_descuento_detalle' => 'integer',
		'id_descuento' => 'integer',
		'id_inscripcion' => 'integer',
		'id_cuota' => 'integer',
		'monto_descuento' => 'decimal:2',
	];

	public function descuento()
	{
		return $this->belongsTo(Descuento::class, 'id_descuento', 'id_descuentos');
	}
}
