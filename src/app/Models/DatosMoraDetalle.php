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
		'numero_cuota',
		'dia_corte',
		'siguiente_dia_habil',
		'porcentaje_override',
		'monto_fijo_override',
		'fecha_inicio_vigencia',
		'fecha_fin_vigencia',
		'activo',
		'descripcion',
	];

	protected $casts = [
		'numero_cuota' => 'integer',
		'dia_corte' => 'integer',
		'siguiente_dia_habil' => 'boolean',
		'porcentaje_override' => 'decimal:4',
		'monto_fijo_override' => 'decimal:2',
		'fecha_inicio_vigencia' => 'date',
		'fecha_fin_vigencia' => 'date',
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
	 * Obtiene los recargos de mora que usan esta configuración.
	 */
	public function recargosMora()
	{
		return $this->hasMany(RecargoMora::class, 'id_datos_mora_detalle', 'id_datos_mora_detalle');
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
			$q->whereNull('fecha_inicio_vigencia')
				->orWhere('fecha_inicio_vigencia', '<=', $fecha);
		})->where(function ($q) use ($fecha) {
			$q->whereNull('fecha_fin_vigencia')
				->orWhere('fecha_fin_vigencia', '>=', $fecha);
		});
	}
}
