<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DescuentoMora extends Model
{
	use HasFactory;

	protected $table = 'descuento_mora';
	protected $primaryKey = 'id_descuento_mora';
	public $incrementing = true;
	public $timestamps = true;

	protected $fillable = [
		'id_recargo_mora',
		'tipo_descuento',
		'porcentaje_descuento',
		'monto_descuento',
		'motivo',
		'autorizado_por',
		'fecha_autorizacion',
		'observaciones',
	];

	protected $casts = [
		'porcentaje_descuento' => 'decimal:4',
		'monto_descuento' => 'decimal:2',
		'fecha_autorizacion' => 'datetime',
	];

	/**
	 * Obtiene el recargo de mora asociado.
	 */
	public function recargoMora()
	{
		return $this->belongsTo(RecargoMora::class, 'id_recargo_mora', 'id_recargo_mora');
	}

	/**
	 * Obtiene el usuario que autorizó el descuento.
	 */
	public function autorizador()
	{
		return $this->belongsTo(Usuario::class, 'autorizado_por', 'id_usuario');
	}
}
