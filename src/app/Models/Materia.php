<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Materia extends Model
{
	use HasFactory;
	
	/**
	 * Nombre de la tabla asociada al modelo.
	 *
	 * @var string
	 */
	protected $table = 'materia';
	
	/**
	 * Clave primaria del modelo.
	 *
	 * @var array
	 */
	protected $primaryKey = ['sigla_materia', 'cod_pensum'];
	
	/**
	 * Indica si la clave primaria es auto-incrementable.
	 *
	 * @var bool
	 */
	public $incrementing = false;
	
	/**
	 * Atributos que son asignables en masa.
	 *
	 * @var array<int, string>
	 */
	protected $fillable = [
		'sigla_materia',
		'cod_pensum',
		'nombre_materia',
		'nombre_material_oficial',
		'activo',
		'estado',
		'orden',
		'descripcion',
		'nro_creditos',
	];
	
	/**
	 * Atributos que deben ser convertidos.
	 *
	 * @var array<string, string>
	 */
	protected $casts = [
		'activo' => 'boolean',
		'estado' => 'boolean',
		'nro_creditos' => 'decimal:2',
	];

	/**
	 * Atributos agregados al array/json del modelo.
	 *
	 * @var array<int, string>
	 */
	protected $appends = ['estado'];

	/**
	 * Accessor de compatibilidad: expone 'estado' basado en 'activo'.
	 */
	public function getEstadoAttribute(): bool
	{
		return (bool) ($this->attributes['activo'] ?? $this->attributes['estado'] ?? false);
	}
	
	/**
	 * Obtiene el pensum asociado con esta materia.
	 */
	public function pensum()
	{
		return $this->belongsTo(Pensum::class, 'cod_pensum', 'cod_pensum');
	}
	
	/**
	 * Configuración para claves primarias compuestas en Laravel.
	 * Este método es necesario para que Laravel maneje correctamente las claves primarias compuestas.
	 */
	protected function setKeysForSaveQuery($query)
	{
		$keys = $this->getKeyName();
		if(!is_array($keys)){
			return parent::setKeysForSaveQuery($query);
		}
		
		foreach($keys as $keyName){
			$query->where($keyName, '=', $this->getKeyForSaveQuery($keyName));
		}
		
		return $query;
	}
	
	/**
	 * Obtiene el valor de una clave específica para la consulta de guardado.
	 */
	protected function getKeyForSaveQuery($keyName = null)
	{
		if(is_null($keyName)){
			$keyName = $this->getKeyName();
		}
		
		if(isset($this->original[$keyName])){
			return $this->original[$keyName];
		}
		
		return $this->getAttribute($keyName);
	}
}
