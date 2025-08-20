<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ParametrosEconomicos extends Model
{
	use HasFactory;
	
	/**
	 * Nombre de la tabla asociada al modelo.
	 *
	 * @var string
	 */
	protected $table = 'parametros_economicos';
	
	/**
	 * Clave primaria del modelo.
	 *
	 * @var string
	 */
	protected $primaryKey = 'id_parametro_economico';
	
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
		'nombre',
		'valor',
		'estado',
		'descripcion',
	];
	
	/**
	 * Atributos que deben ser convertidos.
	 *
	 * @var array<string, string>
	 */
	protected $casts = [
		'valor' => 'string',
		'estado' => 'boolean',
	];
	
	/**
	 * Obtiene los items de cobro asociados con este parámetro económico.
	 */
	public function itemsCobro()
	{
		return $this->hasMany(ItemsCobro::class, 'id_parametro_economico', 'id_parametro_economico');
	}
	
	/**
	 * Obtiene las materias asociadas con este parámetro económico.
	 */
	public function materias()
	{
		return $this->hasMany(Materia::class, 'id_parametro_economico', 'id_parametro_economico');
	}
}
