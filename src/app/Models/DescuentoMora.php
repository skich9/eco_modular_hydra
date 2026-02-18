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
		'id_asignacion_mora',
		'porcentaje',
		'monto_descuento',
		'observaciones',
	];

	protected $casts = [
		'porcentaje' => 'boolean',
		'monto_descuento' => 'decimal:2',
	];

	/**
	 * Obtiene la asignación de mora asociada.
	 */
	public function asignacionMora()
	{
		return $this->belongsTo(AsignacionMora::class, 'id_asignacion_mora', 'id_asignacion_mora');
	}
}
