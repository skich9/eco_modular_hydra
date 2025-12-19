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
}
