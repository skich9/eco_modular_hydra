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
		'id_usuario',
		'id_inscripcion',
		'id_cuota',
		'monto_descuento',
		'cod_Archivo',
		'fecha_registro',
		'fecha_solicitud',
		'observaciones',
		'tipo_inscripcion',
		'turno',
		'semestre',
		'meses_descuento',
		'estado',
	];

	protected $casts = [
		'id_descuento_detalle' => 'integer',
		'id_descuento' => 'integer',
		'id_usuario' => 'integer',
		'id_inscripcion' => 'integer',
		'id_cuota' => 'integer',
		'monto_descuento' => 'decimal:2',
		'fecha_registro' => 'datetime',
		'fecha_solicitud' => 'date',
		'estado' => 'boolean',
	];

	public function descuento()
	{
		return $this->belongsTo(Descuento::class, 'id_descuento', 'id_descuentos');
	}

	public function usuario()
	{
		return $this->belongsTo(Usuario::class, 'id_usuario', 'id_usuario');
	}
}
