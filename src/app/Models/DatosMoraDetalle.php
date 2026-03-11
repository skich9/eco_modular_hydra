<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DatosMoraDetalle extends Model
{
	use HasFactory;

	protected $table = 'datos_mora_detalle';
	protected $primaryKey = 'id_datos_mora_detalle';
	public $incrementing = true;
	public $timestamps = true;

	protected $fillable = [
		'id_datos_mora',
		'semestre',
		'cod_pensum',
		'cuota',
		'monto',
		'fecha_inicio',
		'fecha_fin',
		'activo',
	];

	protected $casts = [
		'cuota' => 'integer',
		'monto' => 'decimal:2',
		'fecha_inicio' => 'date',
		'fecha_fin' => 'date',
		'activo' => 'boolean',
	];

	/**
	 * Obtiene la configuración general de mora.
	 */
	public function datosMora()
	{
		return $this->belongsTo(DatosMora::class, 'id_datos_mora', 'id_datos_mora');
	}

	/**
	 * Obtiene las asignaciones de mora que usan esta configuración.
	 */
	public function asignacionesMora()
	{
		return $this->hasMany(AsignacionMora::class, 'id_datos_mora_detalle', 'id_datos_mora_detalle');
	}

	/**
	 * Obtiene el pensum asociado.
	 */
	public function pensum()
	{
		return $this->belongsTo(Pensum::class, 'cod_pensum', 'cod_pensum');
	}

	/**
	 * Scope para obtener solo configuraciones activas.
	 */
	public function scopeActivo($query)
	{
		return $query->where('activo', true);
	}

	/**
	 * Scope para obtener configuración por semestre.
	 */
	public function scopePorSemestre($query, $semestre)
	{
		return $query->where('semestre', $semestre);
	}

	/**
	 * Scope para obtener configuración vigente en una fecha.
	 */
	public function scopeVigente($query, $fecha = null)
	{
		$fecha = $fecha ?? now();
		return $query->where(function ($q) use ($fecha) {
			$q->whereNull('fecha_inicio')
				->orWhere('fecha_inicio', '<=', $fecha);
		})->where(function ($q) use ($fecha) {
			$q->whereNull('fecha_fin')
				->orWhere('fecha_fin', '>=', $fecha);
		});
	}
}
