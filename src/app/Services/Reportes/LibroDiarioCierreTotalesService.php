<?php

namespace App\Services\Reportes;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Persiste en `libro_diario_cierre_totales` los importes alineados con
 * {@see LibroDiarioAggregatorService} (resumen de métodos de pago) por cierre.
 */
class LibroDiarioCierreTotalesService
{
    public function __construct(
        private readonly LibroDiarioAggregatorService $aggregator
    ) {
    }

    public function syncFromCierreId(int $idCierre, ?string $codigoCarreraFallback = null): void
    {
        if (!Schema::hasTable('libro_diario_cierre')) {
            return;
        }
        if (!Schema::hasTable('libro_diario_cierre_totales')) {
            return;
        }
        $tot = $this->calcularMontosDesdeAgregador($idCierre, $codigoCarreraFallback);
        if ($tot === null) {
            return;
        }
        $now = now();
        $payload = $tot + ['updated_at' => $now];
        if (DB::table('libro_diario_cierre_totales')->where('id_libro_diario_cierre', $idCierre)->exists()) {
            DB::table('libro_diario_cierre_totales')->where('id_libro_diario_cierre', $idCierre)->update($payload);
        } else {
            $payload['id_libro_diario_cierre'] = $idCierre;
            $payload['created_at'] = $now;
            DB::table('libro_diario_cierre_totales')->insert($payload);
        }
    }

    /**
     * @return array{total_deposito: float, total_traspaso: float, total_recibos: float, total_facturas: float, total_entregado: float}|null
     */
    private function calcularMontosDesdeAgregador(int $idCierre, ?string $codigoCarreraFallback = null): ?array
    {
        if (!Schema::hasTable('libro_diario_cierre')) {
            return null;
        }
        $cierre = DB::table('libro_diario_cierre')->where('id', $idCierre)->first();
        if (!$cierre) {
            return null;
        }
        $carrera = trim((string) ($cierre->codigo_carrera ?? $codigoCarreraFallback ?? ''));
        if ($carrera === '') {
            return null;
        }
        $idUsuario = (int) $cierre->id_usuario;
        if ($idUsuario <= 0) {
            return null;
        }
        $fecha = (string) $cierre->fecha;
        $u = DB::table('usuarios')->where('id_usuario', $idUsuario)->first();
        $display = (string) ($u->nombre ?? $u->nickname ?? '');

        $agg = $this->aggregator->build([
            'id_usuario' => $idUsuario,
            'fecha_inicio' => $fecha,
            'fecha_fin' => $fecha,
            'codigo_carrera' => $carrera,
            'usuario_display' => $display,
        ]);
        $res = $agg['resumen'] ?? [];

        return $this->mapearResumenAMontos($res);
    }

    /**
     * @return array{total_deposito: float, total_traspaso: float, total_recibos: float, total_facturas: float, total_entregado: float}
     */
    public function obtenerOComputar(int $idCierre, string $carreraFallback): array
    {
        try {
            if (Schema::hasTable('libro_diario_cierre_totales')) {
                // Siempre recalcula y persiste para alinear (a)/(b) con total_general; la fila
                // caché podría quedar con regla antigua (capital sin mora en recibos/facturas).
                $this->syncFromCierreId($idCierre, $carreraFallback);
                $row = DB::table('libro_diario_cierre_totales')->where('id_libro_diario_cierre', $idCierre)->first();
                if ($row) {
                    return $this->filaAMontos($row);
                }
            } else {
                $calc = $this->calcularMontosDesdeAgregador($idCierre, $carreraFallback);
                if ($calc !== null) {
                    return $calc;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[LibroDiarioCierreTotales] compute failed', [
                'id' => $idCierre,
                'e'  => $e->getMessage(),
            ]);
        }
        if (Schema::hasTable('libro_diario_cierre_totales')) {
            $row2 = DB::table('libro_diario_cierre_totales')->where('id_libro_diario_cierre', $idCierre)->first();
            if ($row2) {
                return $this->filaAMontos($row2);
            }
        }
        $ultimo = $this->calcularMontosDesdeAgregador($idCierre, $carreraFallback);
        if ($ultimo !== null) {
            return $ultimo;
        }

        return [
            'total_deposito'  => 0.0,
            'total_traspaso'  => 0.0,
            'total_recibos'   => 0.0,
            'total_facturas'  => 0.0,
            'total_entregado' => 0.0,
        ];
    }

    private function filaAMontos(object $row): array
    {
        return [
            'total_deposito'  => (float) $row->total_deposito,
            'total_traspaso'  => (float) $row->total_traspaso,
            'total_recibos'   => (float) $row->total_recibos,
            'total_facturas'  => (float) $row->total_facturas,
            'total_entregado' => (float) $row->total_entregado,
        ];
    }

    /**
     * @return array{total_deposito: float, total_traspaso: float, total_recibos: float, total_facturas: float, total_entregado: float}
     */
    /**
     * (a) RECIBOS y (b) FACTURAS incluyen la mora asociada a cada tipo, igual que
     * compone `total_general` en {@see LibroDiarioAggregatorService::construirResumenMetodosPago}.
     */
    private function mapearResumenAMontos(array $res): array
    {
        $capRec = (float) ($res['total_recibo'] ?? 0);
        $capFac = (float) ($res['total_factura'] ?? 0);
        $moraRec = (float) ($res['total_mora_recibo'] ?? 0);
        $moraFac = (float) ($res['total_mora_factura'] ?? 0);

        return [
            'total_deposito'  => $this->sumarCanal($res, 'deposito'),
            'total_traspaso'  => $this->sumarCanal($res, 'traspaso'),
            'total_recibos'   => round($capRec + $moraRec, 2),
            'total_facturas'  => round($capFac + $moraFac, 2),
            'total_entregado' => (float) ($res['total_general'] ?? 0),
        ];
    }

    private function sumarCanal(array $resumen, string $canal): float
    {
        $b = $resumen[$canal] ?? null;
        if (!is_array($b)) {
            return 0.0;
        }
        $s = (float) ($b['factura'] ?? 0) + (float) ($b['recibo'] ?? 0)
            + (float) ($b['mora_factura'] ?? 0) + (float) ($b['mora_recibo'] ?? 0);
        return round($s, 2);
    }
}
