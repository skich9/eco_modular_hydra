<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DatosMora extends Model
{
	use HasFactory;

	protected $table = 'datos_mora';
	protected $primaryKey = 'id_datos_mora';
	public $incrementing = true;
	public $timestamps = true;

	protected $fillable = [
		'gestion',
		'tipo_calculo',
		'porcentaje_diario',
		'monto_fijo_diario',
		'activo',
		'descripcion',
	];

	protected $casts = [
		'porcentaje_diario' => 'decimal:4',
		'monto_fijo_diario' => 'decimal:2',
		'activo' => 'boolean',
	];

	/**
	 * Obtiene los detalles de mora asociados a esta configuración.
	 */
	public function detalles()
	{
		return $this->hasMany(DatosMoraDetalle::class, 'id_datos_mora', 'id_datos_mora');
	}

	/**
	 * Scope para obtener solo configuraciones activas.
	 */
	public function scopeActivo($query)
	{
		return $query->where('activo', true);
	}

	/**
	 * Scope para obtener configuración por gestión.
	 */
	public function scopePorGestion($query, $gestion)
	{
		return $query->where('gestion', $gestion);
	}
}
