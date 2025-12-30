<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Factura extends Model
{
	protected $table = 'factura';
	public $incrementing = false;
	public $timestamps = true;
	protected $primaryKey = null;
	
	protected $fillable = [
		'nro_factura',
		'anio',
		'tipo',
		'es_manual',
		'codigo_tipo_emision',
		'codigo_sucursal',
		'codigo_punto_venta',
		'fecha_emision',
		'periodo_facturado',
		'cod_ceta',
		'id_usuario',
		'id_forma_cobro',
		'monto_total',
		'cliente',
		'nro_documento_cobro',
		'codigo_cufd',
		'cuf',
		'codigo_cafc',
		'codigo_recepcion',
		'pdf_path',
		'qr_path',
		'estado',
		'codigo_doc_sector',
		'codigo_excepcion',
		'cafc',
		'leyenda',
		'leyenda2',
	];

	protected $casts = [
		'fecha_emision' => 'datetime',
		'monto_total' => 'decimal:2',
	];
}
