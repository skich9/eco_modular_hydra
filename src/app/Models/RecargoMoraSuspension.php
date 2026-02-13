<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RecargoMoraSuspension extends Model
{
	use HasFactory;

	protected $table = 'recargo_mora_suspension';
	protected $primaryKey = 'id_suspension';
	public $incrementing = true;
	public $timestamps = true;

	protected $fillable = [
		'id_recargo_mora',
		'id_prorroga',
		'fecha_inicio_suspension',
		'fecha_fin_suspension',
		'dias_suspendidos',
		'motivo',
	];

	protected $casts = [
		'fecha_inicio_suspension' => 'date',
		'fecha_fin_suspension' => 'date',
		'dias_suspendidos' => 'integer',
	];

	/**
	 * Obtiene el recargo de mora asociado.
	 */
	public function recargoMora()
	{
		return $this->belongsTo(RecargoMora::class, 'id_recargo_mora', 'id_recargo_mora');
	}

	/**
	 * Obtiene la prórroga que causó esta suspensión.
	 */
	public function prorroga()
	{
		return $this->belongsTo(Prorroga::class, 'id_prorroga', 'id_prorroga');
	}

	/**
	 * Scope para obtener suspensiones activas (sin fecha fin).
	 */
	public function scopeActivas($query)
	{
		return $query->whereNull('fecha_fin_suspension');
	}

	/**
	 * Scope para obtener suspensiones finalizadas.
	 */
	public function scopeFinalizadas($query)
	{
		return $query->whereNotNull('fecha_fin_suspension');
	}
}
