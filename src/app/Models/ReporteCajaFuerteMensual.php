<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class ReporteCajaFuerteMensual extends Model
{
    protected $table = 'reporte_caja_fuerte_mensual';
    protected $primaryKey = 'codigo_reporte';

    protected $fillable = [
        'cod_documento',
        'fecha_inicio',
        'fecha_fin',
        'fecha_impresion',
        'monto',
        'usuario',
        'anulado',
        'motivo_anulacion',
        'id_caja_actividad',
    ];

    protected $casts = [
        'fecha_inicio'    => 'date',
        'fecha_fin'       => 'date',
        'fecha_impresion' => 'datetime',
        'monto'           => 'decimal:2',
        'anulado'         => 'boolean',
    ];

    public function cajaActividad()
    {
        return $this->belongsTo(CajaActividad::class, 'id_caja_actividad', 'id_caja_actividad');
    }

    public function scopeNoAnulado(Builder $query): Builder
    {
        return $query->where('anulado', false);
    }

    /** Último reporte válido para una caja anterior a la fecha dada (para obtener saldo inicial). */
    public static function ultimoReporte(int $idCaja, string $fechaInicio): ?self
    {
        return static::noAnulado()
            ->where('id_caja_actividad', $idCaja)
            ->where('fecha_fin', '<', $fechaInicio)
            ->orderByDesc('fecha_fin')
            ->first();
    }

    /** Reporte del mes exacto (mismo fecha_inicio). Prioriza el registro vigente sobre los anulados. */
    public static function reporteDelMes(int $idCaja, string $fechaInicio): ?self
    {
        return static::where('id_caja_actividad', $idCaja)
            ->where('fecha_inicio', $fechaInicio)
            ->orderBy('anulado')            // 0 (vigente) antes que 1 (anulado)
            ->orderByDesc('codigo_reporte') // más reciente primero si hay varios
            ->first();
    }
}
