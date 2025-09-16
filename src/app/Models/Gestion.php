<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Gestion extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'gestion';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'gestion';

    /**
     * Indicates if the primary key is auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * The data type of the primary key.
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'gestion',
        'fecha_ini',
        'fecha_fin',
        'orden',
        'fecha_graduacion',
        'activo'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'fecha_ini' => 'date',
        'fecha_fin' => 'date',
        'fecha_graduacion' => 'date',
        'activo' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    /**
     * Append virtual attributes to JSON.
     *
     * @var array<int, string>
     */
    protected $appends = ['estado'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha_ini' => 'date',
            'fecha_fin' => 'date',
            'fecha_graduacion' => 'date',
            'activo' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Accessor: mantener compatibilidad con frontend que espera 'estado'.
     */
    public function getEstadoAttribute(): bool
    {
        return (bool) ($this->attributes['activo'] ?? false);
    }

    /**
     * Relación: Una gestión tiene muchos costos de materias
     */
    public function costosMateria()
    {
        return $this->hasMany(CostoMateria::class, 'gestion', 'gestion');
    }

    /**
     * Relación: Una gestión tiene muchas inscripciones
     */
   

    /**
     * Relación: Una gestión tiene muchos costos semestrales
     */
    public function costosSemestrales()
    {
        return $this->hasMany(CostoSemestral::class, 'gestion', 'gestion');
    }

    /**
     * Scope para gestiones activas
     */
    public function scopeActiva($query)
    {
        return $query->where('activo', true);
    }

    /**
     * Scope para gestiones por orden
     */
    public function scopePorOrden($query, $orden = 'asc')
    {
        return $query->orderBy('orden', $orden);
    }

    /**
     * Scope para gestiones por rango de fechas
     */
    public function scopeEntreFechas($query, $fechaInicio, $fechaFin)
    {
        return $query->where('fecha_ini', '>=', $fechaInicio)
                    ->where('fecha_fin', '<=', $fechaFin);
    }

    /**
     * Verificar si la gestión está activa
     */
    public function estaActiva(): bool
    {
        return (bool) $this->activo === true;
    }

    /**
     * Obtener la gestión actual (la más reciente activa)
     */
    public static function gestionActual()
    {
        return static::activa()
                    ->porOrden('desc')
                    ->first();
    }

    /**
     * Obtener gestiones por año
     */
    public static function porAnio($anio)
    {
        return static::where('gestion', 'like', "%{$anio}%")
                    ->get();
    }
}