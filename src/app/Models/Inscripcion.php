<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
 

class Inscripcion extends Model
{
	use HasFactory;

	/**
	 * Registrar un scope global condicional para ignorar registros borrados lógicamente
	 * solo si la columna 'deleted_at' existe en el esquema actual.
	 */
	protected static function booted()
	{
		if (Schema::hasColumn('inscripciones', 'deleted_at')) {
			static::addGlobalScope('not_deleted', function (Builder $builder) {
				$builder->whereNull('deleted_at');
			});
		}


	}

	protected $table = 'inscripciones';
	protected $primaryKey = 'cod_inscrip';
	public $incrementing = true;
	public $timestamps = true;

	protected $fillable = [
		'cod_inscrip',
		'id_usuario',
		'cod_ceta',
		'cod_pensum',
		'cod_pensum_sga',
		'cod_curso',
		'carrera',
		'nro_materia',
		'nro_materia_aprob',
		'gestion',
		'tipo_estudiante',
		'fecha_inscripcion',
		'tipo_inscripcion',
		'source_cod_inscrip',
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
