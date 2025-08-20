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
	 * @var array
	 */
	protected $primaryKey = ['id_costo_semestral', 'cod_pensum', 'gestion'];
	
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
		return $this->hasMany(AsignacionCostos::class, ['id_costo_semestral', 'cod_pensum'], ['id_costo_semestral', 'cod_pensum']);
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
