<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RecargoMora extends Model
{
	use HasFactory;

	protected $table = 'recargo_mora';
	protected $primaryKey = 'id_recargo_mora';
	public $incrementing = true;
	public $timestamps = true;

	protected $fillable = [
		'id_asignacion_costo',
		'id_datos_mora_detalle',
		'fecha_inicio_mora',
		'fecha_fin_mora',
		'dias_mora',
		'dias_suspendidos',
		'dias_efectivos',
		'monto_base',
		'porcentaje_aplicado',
		'monto_fijo_aplicado',
		'monto_mora_calculado',
		'monto_descuento',
		'monto_mora_final',
		'estado',
		'nro_cobro',
		'observaciones',
	];

	protected $casts = [
		'fecha_inicio_mora' => 'date',
		'fecha_fin_mora' => 'date',
		'dias_mora' => 'integer',
		'dias_suspendidos' => 'integer',
		'dias_efectivos' => 'integer',
		'monto_base' => 'decimal:2',
		'porcentaje_aplicado' => 'decimal:4',
		'monto_fijo_aplicado' => 'decimal:2',
		'monto_mora_calculado' => 'decimal:2',
		'monto_descuento' => 'decimal:2',
		'monto_mora_final' => 'decimal:2',
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
	 * Obtiene el cobro asociado (cuando se paga).
	 */
	public function cobro()
	{
		return $this->belongsTo(Cobro::class, 'nro_cobro', 'nro_cobro');
	}

	/**
	 * Obtiene las suspensiones de mora.
	 */
	public function suspensiones()
	{
		return $this->hasMany(RecargoMoraSuspension::class, 'id_recargo_mora', 'id_recargo_mora');
	}

	/**
	 * Obtiene los descuentos aplicados.
	 */
	public function descuentos()
	{
		return $this->hasMany(DescuentoMora::class, 'id_recargo_mora', 'id_recargo_mora');
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
