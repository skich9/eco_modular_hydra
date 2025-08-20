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
	 * @var array
	 */
	protected $primaryKey = ['cod_pensum', 'cod_inscrip', 'id_asignacion_costo'];
	
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
	];
	
	/**
	 * Atributos que deben ser convertidos.
	 *
	 * @var array<string, string>
	 */
	protected $casts = [
		'monto' => 'decimal:2',
		'estado' => 'boolean',
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
		return $this->belongsTo(CostoSemestral::class, ['id_costo_semestral', 'cod_pensum'], ['id_costo_semestral', 'cod_pensum']);
	}
	
	/**
	 * Obtiene los recargos por mora asociados con esta asignación de costo.
	 */
	public function recargosMora()
	{
		return $this->hasMany(RecargoMora::class, 'id_asignacion_costo', 'id_asignacion_costo');
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
