<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OtroIngresoDetalle extends Model
{
	use HasFactory;

	protected $table = 'otros_ingresos_detalle';

	protected $fillable = [
		'otro_ingreso_id',
		'cta_banco',
		'nro_deposito',
		'fecha_deposito',
		'fecha_ini',
		'fecha_fin',
		'nro_orden',
		'concepto_alquiler',
	];

	protected $casts = [
		'fecha_deposito' => 'date',
		'fecha_ini' => 'date',
		'fecha_fin' => 'date',
	];

	public function otroIngreso(): BelongsTo
	{
		return $this->belongsTo(OtroIngreso::class, 'otro_ingreso_id');
	}
}
