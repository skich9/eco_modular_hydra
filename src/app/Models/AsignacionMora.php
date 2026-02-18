<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AsignacionMora extends Model
{
	use HasFactory;

	protected $table = 'asignacion_mora';
	protected $primaryKey = 'id_asignacion_mora';
	public $incrementing = true;
	public $timestamps = true;

	protected $fillable = [
		'id_asignacion_costo',
		'id_datos_mora_detalle',
		'fecha_inicio_mora',
		'fecha_fin_mora',
		'monto_base',
		'monto_mora',
		'monto_descuento',
		'estado',
		'observaciones',
	];

	protected $casts = [
		'fecha_inicio_mora' => 'date',
		'fecha_fin_mora' => 'date',
		'monto_base' => 'decimal:2',
		'monto_mora' => 'decimal:2',
		'monto_descuento' => 'decimal:2',
	];

	/**
	 * Obtiene la asignación de costo (cuota) asociada.
	 */
	public function asignacionCosto()
	{
		return $this->belongsTo(AsignacionCostos::class, 'id_asignacion_costo', 'id_asignacion_costo');
	}

	/**
	 * Obtiene la configuración de mora aplicada.
	 */
	public function datosMoraDetalle()
	{
		return $this->belongsTo(DatosMoraDetalle::class, 'id_datos_mora_detalle', 'id_datos_mora_detalle');
	}

	/**
	 * Obtiene los descuentos aplicados.
	 */
	public function descuentos()
	{
		return $this->hasMany(DescuentoMora::class, 'id_asignacion_mora', 'id_asignacion_mora');
	}

	/**
	 * Scope para obtener moras pendientes de pago.
	 */
	public function scopePendientes($query)
	{
		return $query->where('estado', 'PENDIENTE');
	}

	/**
	 * Scope para obtener moras pagadas.
	 */
	public function scopePagadas($query)
	{
		return $query->where('estado', 'PAGADO');
	}

	/**
	 * Scope para obtener moras condonadas.
	 */
	public function scopeCondonadas($query)
	{
		return $query->where('estado', 'CONDONADO');
	}
}
