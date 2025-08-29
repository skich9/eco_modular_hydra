<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Cobro extends Model
{
	use HasFactory;

	protected $table = 'cobro';
	protected $primaryKey = ['cod_ceta', 'cod_pensum', 'tipo_inscripcion', 'nro_cobro'];
	public $incrementing = false;
	public $timestamps = true;

	protected $fillable = [
		'cod_ceta',
		'cod_pensum',
		'tipo_inscripcion',
		'id_cuota',
		'gestion',
		'nro_cobro',
		'monto',
		'fecha_cobro',
		'cobro_completo',
		'observaciones',
		'id_usuario',
		'id_forma_cobro',
		'pu_mensualidad',
		'order',
		'descuento',
		'id_cuentas_bancarias',
		'nro_factura',
		'nro_recibo',
		'id_item',
		'id_asignacion_costo',
	];

	protected $casts = [
		'cod_ceta' => 'integer',
		'nro_cobro' => 'integer',
		'monto' => 'decimal:2',
		'pu_mensualidad' => 'decimal:2',
		'cobro_completo' => 'boolean',
		'fecha_cobro' => 'date',
	];

	// Manejo de clave primaria compuesta
	protected function setKeysForSaveQuery($query)
	{
		$keys = $this->getKeyName();
		if (!is_array($keys)) {
			return parent::setKeysForSaveQuery($query);
		}
		foreach ($keys as $keyName) {
			$query->where($keyName, '=', $this->getKeyForSaveQuery($keyName));
		}
		return $query;
	}

	protected function getKeyForSaveQuery($keyName = null)
	{
		if (is_null($keyName)) {
			$keyName = $this->getKeyName();
		}
		if (isset($this->original[$keyName])) {
			return $this->original[$keyName];
		}
		return $this->getAttribute($keyName);
	}

	// Relaciones
	public function usuario()
	{
		return $this->belongsTo(Usuario::class, 'id_usuario', 'id_usuario');
	}

	public function cuota()
	{
		return $this->belongsTo(Cuota::class, 'id_cuota', 'id_cuota');
	}

	public function formaCobro()
	{
		return $this->belongsTo(FormaCobro::class, 'id_forma_cobro', 'id_forma_cobro');
	}

	public function cuentaBancaria()
	{
		return $this->belongsTo(CuentaBancaria::class, 'id_cuentas_bancarias', 'id_cuentas_bancarias');
	}

	public function itemCobro()
	{
		return $this->belongsTo(ItemsCobro::class, 'id_item', 'id_item');
	}

	public function asignacionCostos()
	{
		return $this->belongsTo(AsignacionCostos::class, 'id_asignacion_costo', 'id_asignacion_costo');
	}

	public function detalleRegular()
	{
		return $this->hasOne(CobrosDetalleRegular::class, 'nro_cobro', 'nro_cobro');
	}

	public function detalleMulta()
	{
		return $this->hasOne(CobrosDetalleMulta::class, 'nro_cobro', 'nro_cobro');
	}
}
