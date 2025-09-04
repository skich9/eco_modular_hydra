<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RazonSocial extends Model
{
	use HasFactory;

	protected $table = 'razon_social';

	// Composite PK is managed via updateOrCreate with attributes, so we don't set $primaryKey
	public $incrementing = false;
	protected $keyType = 'string';

	protected $fillable = [
		'razon_social',
		'nit',
		'tipo',
		'id_tipo_doc_identidad',
		'complemento',
	];
}
