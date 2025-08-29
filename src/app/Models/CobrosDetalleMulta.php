<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CobrosDetalleMulta extends Model
{
	use HasFactory;

	protected $table = 'cobros_detalle_multa';
	protected $primaryKey = 'nro_cobro';
	public $incrementing = false;
	public $timestamps = true;

	protected $fillable = [
		'nro_cobro',
		'pu_multa',
		'dias_multa',
	];

	protected $casts = [
		'nro_cobro' => 'integer',
		'pu_multa' => 'decimal:2',
		'dias_multa' => 'integer',
	];

	public function cobro()
	{
		return $this->belongsTo(Cobro::class, 'nro_cobro', 'nro_cobro');
	}
}
