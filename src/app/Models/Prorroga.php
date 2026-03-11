<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Prorroga extends Model
{
	use HasFactory;

	protected $table = 'prorrogas';
	protected $primaryKey = 'id_prorroga';
	public $incrementing = true;
	public $timestamps = true;

	protected $fillable = [
		'usuario_id',
		'cod_ceta',
		'id_cuota',
		'id_asignacion_costo',
		'fecha_solicitud',
		'fecha_inicio_prorroga',
		'fecha_fin_prorroga',
		'estado',
		'motivo',
		'observaciones',
		'aprobado_por',
		'fecha_aprobacion',
	];

	protected $casts = [
		'fecha_solicitud' => 'date',
		'fecha_inicio_prorroga' => 'date',
		'fecha_fin_prorroga' => 'date',
		'fecha_aprobacion' => 'datetime',
	];

	/**
	 * Obtiene el usuario que solicitó la prórroga.
	 */
	public function usuario()
	{
		return $this->belongsTo(Usuario::class, 'usuario_id', 'id_usuario');
	}

	/**
	 * Obtiene el estudiante asociado.
	 */
	public function estudiante()
	{
		return $this->belongsTo(Estudiante::class, 'cod_ceta', 'cod_ceta');
	}

	/**
	 * Obtiene la asignación de costo (cuota) asociada.
	 */
	public function asignacionCosto()
	{
		return $this->belongsTo(AsignacionCostos::class, 'id_asignacion_costo', 'id_asignacion_costo');
	}

	/**
	 * Obtiene el usuario que aprobó la prórroga.
	 */
	public function aprobador()
	{
		return $this->belongsTo(Usuario::class, 'aprobado_por', 'id_usuario');
	}

	/**
	 * Obtiene las suspensiones de mora asociadas a esta prórroga.
	 */
	public function suspensiones()
	{
		return $this->hasMany(RecargoMoraSuspension::class, 'id_prorroga', 'id_prorroga');
	}

	/**
	 * Scope para obtener prórrogas aprobadas.
	 */
	public function scopeAprobadas($query)
	{
		return $query->where('estado', 'APROBADA');
	}

	/**
	 * Scope para obtener prórrogas pendientes.
	 */
	public function scopePendientes($query)
	{
		return $query->where('estado', 'PENDIENTE');
	}
}
