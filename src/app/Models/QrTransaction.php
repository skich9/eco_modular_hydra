<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QrTransaction extends Model
{
	use HasFactory;

	protected $table = 'qr_transacciones';
	protected $primaryKey = 'id_qr_transaccion';
	public $incrementing = false;
	protected $keyType = 'int';

	protected $fillable = [
		'id_qr_transaccion',
		'id_usuario',
		'nro_factura',
		'anio',
		'nro_recibo',
		'anio_recibo',
		'id_cuenta_bancaria',
		'alias',
		'codigo_qr',
		'cod_ceta',
		'cod_pensum',
		'tipo_inscripcion',
		'id_cuota',
		'id_forma_cobro',
		'monto_total',
		'moneda',
		'estado',
		'detalle_glosa',
		'fecha_generacion',
		'fecha_expiracion',
		'nro_autorizacion',
	];
}
