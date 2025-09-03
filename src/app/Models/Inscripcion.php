<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Inscripcion extends Model
{
	use HasFactory;
	use SoftDeletes;

	protected $table = 'inscripciones';
	protected $primaryKey = 'cod_inscrip';
	public $incrementing = true;
	public $timestamps = true;

	protected $fillable = [
		'cod_inscrip',
		'id_usuario',
		'cod_ceta',
		'cod_pensum',
		'cod_curso',
		'nro_materia',
		'nro_materia_aprob',
		'gestion',
		'tipo_estudiante',
		'fecha_inscripcion',
		'tipo_inscripcion',
	];

	public function estudiante()
	{
		return $this->belongsTo(Estudiante::class, 'cod_ceta', 'cod_ceta');
	}

	public function pensum()
	{
		return $this->belongsTo(Pensum::class, 'cod_pensum', 'cod_pensum');
	}
}
