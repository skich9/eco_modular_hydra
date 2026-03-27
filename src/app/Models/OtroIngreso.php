<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OtroIngreso extends Model
{
	use HasFactory;

	protected $table = 'otros_ingresos';

	protected $fillable = [
		'num_factura',
		'num_recibo',
		'nit',
		'fecha',
		'razon_social',
		'autorizacion',
		'codigo_control',
		'monto',
		'valido',
		'usuario',
		'concepto',
		'observaciones',
		'cod_pensum',
		'codigo_carrera',
		'gestion',
		'subtotal',
		'descuento',
		'code_tipo_pago',
		'tipo_ingreso',
		'cod_tipo_ingreso',
		'factura_recibo',
		'es_computarizada',
	];

	protected $casts = [
		'fecha' => 'datetime',
		'monto' => 'decimal:2',
		'subtotal' => 'decimal:2',
		'descuento' => 'decimal:2',
		'es_computarizada' => 'boolean',
	];

	public function detalle(): HasOne
	{
		return $this->hasOne(OtroIngresoDetalle::class, 'otro_ingreso_id');
	}
}
