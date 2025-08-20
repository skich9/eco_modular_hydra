<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Carrera extends Model
{
	use HasFactory;
	
	/**
	 * El nombre de la tabla asociada con el modelo.
	 *
	 * @var string
	 */
	protected $table = 'carrera';
	
	/**
	 * La clave primaria asociada con la tabla.
	 *
	 * @var string
	 */
	protected $primaryKey = 'codigo_carrera';
	
	/**
	 * Indica si la clave primaria es un incremento automático.
	 *
	 * @var bool
	 */
	public $incrementing = false;
	
	/**
	 * El tipo de dato de la clave primaria.
	 *
	 * @var string
	 */
	protected $keyType = 'string';
	
	/**
	 * Los atributos que son asignables en masa.
	 *
	 * @var array<int, string>
	 */
	protected $fillable = [
		'codigo_carrera',
		'nombre',
		'descripcion',
		'prefijo_matricula',
		'callback',
		'estado'
	];
	
	/**
	 * Los atributos que deben ser convertidos a tipos nativos.
	 *
	 * @var array
	 */
	protected $casts = [
		'estado' => 'boolean',
	];
	
	/**
	 * Obtiene todos los pensums que pertenecen a esta carrera.
	 */
	public function pensums()
	{
		return $this->hasMany(Pensum::class, 'codigo_carrera', 'codigo_carrera');
	}
}
