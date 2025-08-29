<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Cuota extends Model
{
	use HasFactory;

	protected $table = 'cuotas';
	protected $primaryKey = 'id_cuota';
	public $incrementing = true;
	public $timestamps = true;

	protected $fillable = [
		'nombre',
		'descripcion',
		'monto',
		'fecha_vencimiento',
		'estado',
		'tipo',
	];

	protected $casts = [
		'monto' => 'decimal:2',
		'fecha_vencimiento' => 'date',
	];
}
