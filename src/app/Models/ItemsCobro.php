<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ItemsCobro extends Model
{
	use HasFactory;
	
	/**
	 * Nombre de la tabla asociada al modelo.
	 *
	 * @var string
	 */
	protected $table = 'items_cobro';
	
	/**
	 * Clave primaria del modelo.
	 *
	 * @var string
	 */
	protected $primaryKey = 'id_item';
	
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
		'codigo_producto_impuesto',
		'codigo_producto_interno',
		'unidad_medida',
		'nombre_servicio',
		'nro_creditos',
		'costo',
		'facturado',
		'actividad_economica',
		'descripcion',
		'tipo_item',
		'estado',
		'id_parametro_economico',
	];
	
	/**
	 * Atributos que deben ser convertidos.
	 *
	 * @var array<string, string>
	 */
	protected $casts = [
		'nro_creditos' => 'decimal:2',
		'facturado' => 'boolean',
		'estado' => 'boolean',
	];
	
	/**
	 * Obtiene el parámetro económico asociado con este item de cobro.
	 */
	public function parametroEconomico()
	{
		return $this->belongsTo(ParametrosEconomicos::class, 'id_parametro_economico', 'id_parametro_economico');
	}
}
