<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AsignacionCostos extends Model
{
	use HasFactory;
	
	/**
	 * Nombre de la tabla asociada al modelo.
	 *
	 * @var string
	 */
	protected $table = 'asignacion_costos';
	
	/**
	 * Clave primaria del modelo.
	 *
	 * @var string
	 */
	protected $primaryKey = 'id_asignacion_costo';
	
	/**
	 * Indica si la clave primaria es auto-incrementable.
	 *
	 * @var bool
	 */
	public $incrementing = true;
	
	/**
	 * Atributos que son asignables en masa.
	 *
	 * @var array<int, string>
	 */
	protected $fillable = [
		'cod_pensum',
		'cod_inscrip',
		'id_asignacion_costo',
		'monto',
		'observaciones',
		'estado',
		'id_costo_semestral',
		'id_descuentoDetalle',
		'id_prorroga',
		'id_compromisos',
		// Nuevos campos para manejo de cuotas
		'numero_cuota',
		'fecha_vencimiento',
		'estado_pago',
		'fecha_pago',
		'monto_pagado',
		'id_cuota_template',
	];
	
	/**
	 * Atributos que deben ser convertidos.
	 *
	 * @var array<string, string>
	 */
	protected $casts = [
		'monto' => 'decimal:2',
		'estado' => 'boolean',
		'monto_pagado' => 'decimal:2',
		'fecha_vencimiento' => 'date',
		'fecha_pago' => 'date',
	];
	
	/**
	 * Obtiene el pensum asociado con esta asignación de costo.
	 */
	public function pensum()
	{
		return $this->belongsTo(Pensum::class, 'cod_pensum', 'cod_pensum');
	}
	
	/**
	 * Obtiene la inscripción asociada con esta asignación de costo.
	 */
	public function inscripcion()
	{
		return $this->belongsTo(Inscripcion::class, 'cod_inscrip', 'cod_inscrip');
	}
	
	/**
	 * Obtiene el costo semestral asociado con esta asignación de costo.
	 */
	public function costoSemestral()
	{
		return $this->belongsTo(CostoSemestral::class, 'id_costo_semestral', 'id_costo_semestral');
	}
	
	/**
	 * Obtiene los recargos por mora asociados con esta asignación de costo.
	 */
	public function recargosMora()
	{
		return $this->hasMany(RecargoMora::class, 'id_asignacion_costo', 'id_asignacion_costo');
	}
}

