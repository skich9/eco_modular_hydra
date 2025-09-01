<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CostoSemestral extends Model
{
	use HasFactory;
	
	/**
	 * Nombre de la tabla asociada al modelo.
	 *
	 * @var string
	 */
	protected $table = 'costo_semestral';
	
	/**
	 * Clave primaria del modelo.
	 *
	 * @var string
	 */
	protected $primaryKey = 'id_costo_semestral';
	
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
		'id_costo_semestral',
		'cod_pensum',
		'gestion',
		'cod_inscrip',
		'semestre',
		'monto_semestre',
		'id_usuario',
	];
	
	/**
	 * Atributos que deben ser convertidos.
	 *
	 * @var array<string, string>
	 */
	protected $casts = [
		'monto_semestre' => 'decimal:2',
	];
	
	/**
	 * Obtiene el pensum asociado con este costo semestral.
	 */
	public function pensum()
	{
		return $this->belongsTo(Pensum::class, 'cod_pensum', 'cod_pensum');
	}
	
	/**
	 * Obtiene el usuario asociado con este costo semestral.
	 */
	public function usuario()
	{
		return $this->belongsTo(Usuario::class, 'id_usuario', 'id_usuario');
	}
	
	/**
	 * Obtiene las asignaciones de costos asociadas con este costo semestral.
	 */
	public function asignacionesCostos()
	{
		return $this->hasMany(AsignacionCostos::class, 'id_costo_semestral', 'id_costo_semestral');
	}
}

