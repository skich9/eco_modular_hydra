<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CobrosDetalleRegular extends Model
{
	use HasFactory;

	protected $table = 'cobros_detalle_regular';
	protected $primaryKey = 'nro_cobro';
	public $incrementing = false;
	public $timestamps = true;

	protected $fillable = [
		'nro_cobro',
		'cod_inscrip',
		'pu_mensualidad',
		'turno',
	];

	protected $casts = [
		'cod_inscrip' => 'integer',
		'nro_cobro' => 'integer',
		'pu_mensualidad' => 'decimal:4',
	];

	public function cobro()
	{
		return $this->belongsTo(Cobro::class, 'nro_cobro', 'nro_cobro');
	}
}
