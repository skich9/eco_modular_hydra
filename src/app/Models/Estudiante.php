<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Estudiante extends Model
{
	use HasFactory;

	protected $table = 'estudiantes';
	protected $primaryKey = 'cod_ceta';
	public $incrementing = false;
	protected $keyType = 'int';
	public $timestamps = true;

	protected $fillable = [
		'cod_ceta',
		'ci',
		'nombres',
		'ap_paterno',
		'ap_materno',
		'email',
		'carrera',
		'resolucion',
		'gestion',
		'grupos',
		'descuento',
		'observaciones',
		'cod_pensum',
		'estado',
	];

	public function pensum()
	{
		return $this->belongsTo(Pensum::class, 'cod_pensum', 'cod_pensum');
	}

	public function inscripciones()
	{
		return $this->hasMany(Inscripcion::class, 'cod_ceta', 'cod_ceta');
	}

	public function getNombreCompletoAttribute()
	{
		$parts = [];
		if (!empty($this->ap_paterno)) {
			$parts[] = trim($this->ap_paterno);
		}
		if (!empty($this->ap_materno)) {
			$parts[] = trim($this->ap_materno);
		}
		if (!empty($this->nombres)) {
			$parts[] = trim($this->nombres);
		}
		return implode(' ', $parts);
	}
}
