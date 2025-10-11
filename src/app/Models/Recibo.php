<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Recibo extends Model
{
	protected $table = 'recibo';
	public $incrementing = false; // PK compuesta
	public $timestamps = true;
	protected $primaryKey = null; // compuesto, Laravel no lo maneja directo
	protected $fillable = [
		'nro_recibo',
		'anio',
		'id_usuario',
		'cod_ceta',
		'complemento',
		'cod_tipo_doc_identidad',
		'id_forma_cobro',
		'periodo_facturado',
		'monto_total',
		'estado',
		'monto_gift_card',
		'num_gift_card',
		'tipo_emision',
		'codigo_excepcion',
		'codigo_doc_sector',
	];
}
