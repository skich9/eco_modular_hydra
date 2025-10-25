<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CuentaBancaria extends Model
{
	use HasFactory;

	protected $table = 'cuentas_bancarias';
	protected $primaryKey = 'id_cuentas_bancarias';
	public $incrementing = true;
	public $timestamps = true;

	protected $fillable = [
		'banco',
		'numero_cuenta',
		'tipo_cuenta',
		'titular',
		'habilitado_QR',
		'I_R',
		'estado',
	];

	protected $casts = [
		'habilitado_QR' => 'boolean',
		'I_R' => 'boolean',
		'estado' => 'boolean',
	];
}
