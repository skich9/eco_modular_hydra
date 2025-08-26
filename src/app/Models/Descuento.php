<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Descuento extends Model
{
	use HasFactory;

	protected $table = 'descuentos';
	protected $primaryKey = 'id_descuentos';
	public $incrementing = true;

	protected $fillable = [
		'cod_ceta',
		'cod_pensum',
		'cod_inscrip',
		'id_usuario',
		'nombre',
		'observaciones',
		'porcentaje',
		'tipo',
		'estado',
	];

	protected $casts = [
		'id_descuentos' => 'integer',
		'cod_ceta' => 'integer',
		'cod_inscrip' => 'integer',
		'id_usuario' => 'integer',
		'porcentaje' => 'float',
		'estado' => 'boolean',
	];

	public function usuario()
	{
		return $this->belongsTo(Usuario::class, 'id_usuario', 'id_usuario');
	}

	public function pensum()
	{
		return $this->belongsTo(Pensum::class, 'cod_pensum', 'cod_pensum');
	}

	public function detalles()
	{
		return $this->hasMany(DescuentoDetalle::class, 'id_descuento', 'id_descuentos');
	}
}
