<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ParametroGeneral extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'parametros_generales';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'id_parametros_generales';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'nombre',
        'valor',
        'estado',
        'created_at',
        'updated_at'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'estado' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = true;

    /**
     * Scope a query to only include active parameters.
     */
    public function scopeActivos($query)
    {
        return $query->where('estado', true);
    }

    /**
     * Get parameter by name
     */
    public static function obtenerPorNombre($nombre)
    {
        return static::where('nombre', $nombre)->where('estado', true)->first();
    }

    /**
     * Get parameter value by name
     */
    public static function obtenerValor($nombre, $default = null)
    {
        $parametro = static::obtenerPorNombre($nombre);
        return $parametro ? $parametro->valor : $default;
    }
}