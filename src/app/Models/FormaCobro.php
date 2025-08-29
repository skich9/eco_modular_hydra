<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FormaCobro extends Model
{
	use HasFactory;

	protected $table = 'formas_cobro';
	protected $primaryKey = 'id_forma_cobro';
	public $incrementing = false;
	protected $keyType = 'string';

	protected $fillable = [
		'id_forma_cobro',
		'nombre',
		'descripcion',
		'estado',
	];
}
