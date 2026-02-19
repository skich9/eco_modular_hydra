<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProrrogaMora extends Model
{
	use HasFactory;

	protected $table = 'prorrogas_mora';
	protected $primaryKey = 'id_prorroga_mora';
	public $incrementing = true;
	public $timestamps = true;

	protected $fillable = [
		'id_usuario',
		'cod_ceta',
		'id_asignacion_costo',
		'fecha_inicio_prorroga',
		'fecha_fin_prorroga',
		'activo',
		'motivo',
	];

	protected $casts = [
		'fecha_inicio_prorroga' => 'date',
		'fecha_fin_prorroga' => 'date',
	];

	/**
	 * Obtiene el usuario que solicitó la prórroga.
	 */
	public function usuario()
	{
		return $this->belongsTo(Usuario::class, 'id_usuario', 'id_usuario');
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
	 * Verifica si la prórroga está activa en una fecha dada.
	 *
	 * @param string|null $fecha
	 * @return bool
	 */
	public function estaActiva($fecha = null)
	{
		$fecha = $fecha ? \Carbon\Carbon::parse($fecha) : \Carbon\Carbon::today();

		return $fecha->between(
			\Carbon\Carbon::parse($this->fecha_inicio_prorroga),
			\Carbon\Carbon::parse($this->fecha_fin_prorroga)
		);
	}

	/**
	 * Scope para obtener prórrogas activas en una fecha.
	 *
	 * @param mixed $query
	 * @param mixed $fecha
	 * @return mixed
	 */
	public function scopeActivas($query, $fecha = null)
	{
		$fecha = $fecha ? $fecha : \Carbon\Carbon::today();

		return $query->where('activo', true)
			->where('fecha_inicio_prorroga', '<=', $fecha)
			->where('fecha_fin_prorroga', '>=', $fecha);
	}

	/**
	 * Scope para obtener solo prórrogas activas (sin filtro de fecha).
	 *
	 * @param mixed $query
	 * @return mixed
	 */
	public function scopeSoloActivas($query)
	{
		return $query->where('activo', true);
	}
}
