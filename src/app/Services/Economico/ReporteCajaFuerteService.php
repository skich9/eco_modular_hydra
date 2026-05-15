<?php

namespace App\Services\Economico;

use App\Models\CajaActividad;
use App\Models\ReporteCajaFuerteMensual;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReporteCajaFuerteService
{
    private const TZ = 'America/La_Paz';

    // ─── Catálogo ────────────────────────────────────────────────────────────

    public function listCajas(): Collection
    {
        return CajaActividad::orderBy('orden')->get(['id_caja_actividad', 'nombre_caja', 'prefijo', 'descripcion']);
    }

    // ─── Saldo anterior ──────────────────────────────────────────────────────

    public function getSaldoAnterior(int $idCaja, string $fechaIni): float
    {
        $ultimo = ReporteCajaFuerteMensual::ultimoReporte($idCaja, $fechaIni);
        return $ultimo ? (float) $ultimo->monto : 0.0;
    }

    // ─── Cálculo de rangos de fechas (replica lógica SGA) ────────────────────

    /**
     * Calcula los cuatro rangos de fecha que necesita el query de ingresos,
     * replicando la lógica del SGA:
     *
     *  $fechaIniReporte  = último día hábil del mes anterior (inicio del rango ajustado)
     *  $fechaFinReporte  = antepenúltimo día hábil del mes actual (fin del rango ajustado)
     *  $nuevoMes         = primer día del mes (para esingresolibrodia=FALSE)
     *  $nuevoMesFin      = último día del mes  (para esingresolibrodia=FALSE)
     *
     * Se ignoran los feriados (tabla no disponible en sistemaEco), solo se
     * excluyen sábados y domingos, igual que get_previous_dia_habil() del SGA.
     */
    private function calcularRangosFecha(Carbon $carbon): array
    {
        $nuevoMes    = $carbon->copy()->startOfMonth();   // 2026-04-01
        $nuevoMesFin = $carbon->copy()->endOfMonth();     // 2026-04-30
        $anioReporte = $carbon->year;

        // Día anterior al primer día del mes (para calcular inicio del rango)
        $diaAnterior = $nuevoMes->copy()->subDay();       // 2026-03-31

        // Si el mes anterior es de otro año (enero), el inicio es el primer día del mes
        if ($diaAnterior->year !== $anioReporte) {
            $fechaIniReporte = $nuevoMes->copy();
        } else {
            // get_fecha_ini_reporte_caja: si es día hábil úsalo tal cual; si no, retrocede al anterior hábil
            $fechaIniReporte = $this->diaHabilOAnterior($diaAnterior);
        }

        // Diciembre: fin = último día del mes sin ajuste
        if ($carbon->month === 12) {
            $fechaFinReporte = $nuevoMesFin->copy();
        } else {
            // get_fecha_fin_reporte_caja: siempre retrocede al menos 1 día hábil
            $fechaFinReporte = $this->anteriorDiaHabil($nuevoMesFin);
        }

        return [
            'fecha_ini_reporte' => $fechaIniReporte->toDateString(),
            'fecha_fin_reporte' => $fechaFinReporte->toDateString(),
            'nuevo_mes'         => $nuevoMes->toDateString(),
            'nuevo_mes_fin'     => $nuevoMesFin->toDateString(),
        ];
    }

    /**
     * Si $fecha es día hábil (lunes-viernes) la devuelve tal cual.
     * Si es sábado o domingo retrocede hasta el viernes anterior.
     * Replica get_fecha_ini_reporte_caja() del SGA.
     */
    private function diaHabilOAnterior(Carbon $fecha): Carbon
    {
        $d = $fecha->copy();
        while ($d->isWeekend()) {
            $d->subDay();
        }
        return $d;
    }

    /**
     * Siempre retrocede al menos 1 día hábil desde $fecha.
     * Si $fecha es día hábil → va al día hábil anterior.
     * Si $fecha es fin de semana → va al viernes, luego 1 día hábil más atrás.
     * Replica get_fecha_fin_reporte_caja() del SGA.
     */
    private function anteriorDiaHabil(Carbon $fecha): Carbon
    {
        $d = $fecha->copy();
        // Si es fin de semana, primero llegar al viernes anterior
        while ($d->isWeekend()) {
            $d->subDay();
        }
        // Retroceder un día hábil adicional
        $d->subDay();
        while ($d->isWeekend()) {
            $d->subDay();
        }
        return $d;
    }

    // ─── Movimientos del mes ─────────────────────────────────────────────────

    /**
     * Retorna todos los movimientos (ingresos + egresos) del mes/año de $fechaIni
     * para la caja indicada, ordenados por fecha y correlativo.
     *
     * Replica la lógica de recepcion_model::get_recepcion() del SGA:
     *  - Ingresos con es_ingreso_libro_diario=true  → filtro por fecha_final_libros ajustada a días hábiles
     *  - Ingresos con es_ingreso_libro_diario=false → filtro por mes/año completo
     *  - Egresos → filtro simple por mes/año de fecha_egreso
     *
     * Cada elemento tiene: tipo, correlativo, fecha, descripcion, ingreso, egreso
     */
    public function getMovimientos(int $idCaja, string $fechaIni): Collection
    {
        $carbon = Carbon::parse($fechaIni, self::TZ);
        $mes    = $carbon->month;
        $anio   = $carbon->year;
        $rangos = $this->calcularRangosFecha($carbon);

        /*
         * Ingresos: JOIN con recepcion_ingreso_detalles para filtrar por fecha_final_libros.
         * Usamos los mismos dos filtros que el SGA:
         *   Branch 1 (libro diario=true):  fecha_final_libros BETWEEN $fecha_ini_reporte AND $fecha_fin_reporte
         *   Branch 2 (libro diario=false): fecha_final_libros BETWEEN $nuevo_mes AND $nuevo_mes_fin
         *
         * La descripción replica el CASE del SGA:
         *   si observacion vacía → "Ingresos diarios no facturados de {min_ini} a {max_fin}"
         *   si observacion tiene valor → observacion
         */
        $ingresos = DB::table('recepcion_ingresos as r')
            ->join('recepcion_ingreso_detalles as dr', 'r.id', '=', 'dr.recepcion_ingreso_id')
            ->select(
                DB::raw("CASE WHEN r.es_ingreso_libro_diario = 0 THEN 'otro_ingreso' ELSE 'ingreso' END as tipo"),
                'r.cod_documento as correlativo',
                'r.fecha_recepcion as fecha',
                DB::raw("
                    CASE
                        WHEN r.observacion IS NULL OR r.observacion = ''
                        THEN CONCAT('Ingresos diarios no facturados de ', MIN(dr.fecha_inicial_libros), ' a ', MAX(dr.fecha_final_libros))
                        ELSE r.observacion
                    END as descripcion
                "),
                DB::raw('SUM(dr.total_entregado) as ingreso'),
                DB::raw('0.00 as egreso')
            )
            ->where('r.id_caja_actividad', $idCaja)
            ->where('r.anulado', false)
            ->where(function ($q) use ($rangos) {
                $q->where(function ($q2) use ($rangos) {
                    // Branch 1: libro diario → rango ajustado a días hábiles
                    $q2->where('r.es_ingreso_libro_diario', true)
                       ->whereBetween('dr.fecha_final_libros', [
                           $rangos['fecha_ini_reporte'],
                           $rangos['fecha_fin_reporte'],
                       ]);
                })->orWhere(function ($q2) use ($rangos) {
                    // Branch 2: no libro diario → mes completo
                    $q2->where('r.es_ingreso_libro_diario', false)
                       ->whereBetween('dr.fecha_final_libros', [
                           $rangos['nuevo_mes'],
                           $rangos['nuevo_mes_fin'],
                       ]);
                });
            })
            ->groupBy('r.id', 'r.cod_documento', 'r.fecha_recepcion', 'r.observacion');

        // Egresos: filtro simple por mes/año (mismo que SGA: EXTRACT(MONTH/YEAR))
        $egresos = DB::table('egresos_caja_fuerte')
            ->select(
                DB::raw("'egreso' as tipo"),
                'correlativo',
                'fecha_egreso as fecha',
                'descripcion',
                DB::raw('0.00 as ingreso'),
                'monto as egreso'
            )
            ->where('id_caja_actividad', $idCaja)
            ->where('anular', false)
            ->whereMonth('fecha_egreso', $mes)
            ->whereYear('fecha_egreso', $anio);

        return $ingresos->unionAll($egresos)
            ->orderBy('fecha')
            ->orderBy('correlativo')
            ->get();
    }

    /**
     * Agrega la columna saldo acumulado a cada movimiento.
     */
    public function calcularSaldos(Collection $movimientos, float $saldoInicial): Collection
    {
        $saldo = $saldoInicial;
        return $movimientos->map(function ($fila) use (&$saldo) {
            $saldo += (float) $fila->ingreso - (float) $fila->egreso;
            $fila->saldo = $saldo;
            return $fila;
        });
    }

    // ─── Verificación ────────────────────────────────────────────────────────

    public function getReporteMes(int $idCaja, string $fechaIni): ?ReporteCajaFuerteMensual
    {
        return ReporteCajaFuerteMensual::reporteDelMes($idCaja, $fechaIni);
    }

    // ─── Guardar reporte ─────────────────────────────────────────────────────

    public function guardarReporte(array $data): ReporteCajaFuerteMensual
    {
        $carbon   = Carbon::parse($data['fecha_ini'], self::TZ);
        $fechaIni = $carbon->startOfMonth()->toDateString();
        $fechaFin = $carbon->copy()->endOfMonth()->toDateString();
        $caja     = CajaActividad::find($data['id_caja_actividad']);

        // Siguiente correlativo: extrae el número base del último cod_documento de esta caja
        $maxNum = ReporteCajaFuerteMensual::where('id_caja_actividad', $data['id_caja_actividad'])
            ->get(['cod_documento'])
            ->map(function ($r) {
                preg_match('/(\d+)$/', $r->cod_documento, $m);
                return isset($m[1]) ? (int) $m[1] : 0;
            })
            ->max() ?? 0;

        $codDocumento = sprintf('%s-CF-%02d', $caja->prefijo, $maxNum + 1);

        return ReporteCajaFuerteMensual::create([
            'cod_documento'     => $codDocumento,
            'fecha_inicio'      => $fechaIni,
            'fecha_fin'         => $fechaFin,
            'fecha_impresion'   => Carbon::now(self::TZ),
            'monto'             => $data['monto'],
            'usuario'           => $data['usuario'],
            'id_caja_actividad' => $data['id_caja_actividad'],
        ]);
    }

    // ─── Lista de reportes ───────────────────────────────────────────────────

    public function getListaReportes(): Collection
    {
        return DB::table('reporte_caja_fuerte_mensual as r')
            ->leftJoin('cajas_actividad as ca', 'r.id_caja_actividad', '=', 'ca.id_caja_actividad')
            ->leftJoin('usuarios as u', 'r.usuario', '=', 'u.id_usuario')
            ->select(
                'r.codigo_reporte',
                'r.cod_documento',
                'r.fecha_inicio',
                'r.fecha_fin',
                'r.fecha_impresion',
                'r.monto',
                'r.anulado',
                'r.motivo_anulacion',
                'r.id_caja_actividad',
                'ca.nombre_caja',
                DB::raw("TRIM(CONCAT(COALESCE(u.nombre,''), ' ', COALESCE(u.ap_paterno,''), ' ', COALESCE(u.ap_materno,''))) as nombre_usuario")
            )
            ->orderByDesc('r.fecha_inicio')
            ->get();
    }

    // ─── Anular reporte ──────────────────────────────────────────────────────

    public function anularReporte(int $codigoReporte, string $motivo): void
    {
        ReporteCajaFuerteMensual::where('codigo_reporte', $codigoReporte)->update([
            'anulado'          => true,
            'motivo_anulacion' => $motivo,
        ]);
    }

    // ─── Datos para PDF ──────────────────────────────────────────────────────

    public function datosParaPdf(int $idCaja, string $fechaIni): array
    {
        $carbon        = Carbon::parse($fechaIni, self::TZ);
        $caja          = CajaActividad::find($idCaja);
        $saldoAnterior = $this->getSaldoAnterior($idCaja, $carbon->startOfMonth()->toDateString());
        $movimientos   = $this->getMovimientos($idCaja, $fechaIni);
        $conSaldos     = $this->calcularSaldos($movimientos, $saldoAnterior);

        $totalIngresos = $movimientos->sum('ingreso');
        $totalEgresos  = $movimientos->sum('egreso');
        $saldoFinal    = $saldoAnterior + $totalIngresos - $totalEgresos;

        return [
            'caja'           => $caja,
            'mes'            => $carbon->translatedFormat('F Y'),
            'saldo_anterior' => $saldoAnterior,
            'movimientos'    => $conSaldos->values(),
            'total_ingresos' => $totalIngresos,
            'total_egresos'  => $totalEgresos,
            'saldo_final'    => $saldoFinal,
        ];
    }
}
