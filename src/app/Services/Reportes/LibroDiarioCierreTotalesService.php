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
                // Recalcula y persiste: (a)/(b) = fila Efectivo del resumen; caché puede tener regla antigua.
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
     * (a) RECIBOS y (b) FACTURAS (ING-4 / recepción): solo fila **Efectivo** del resumen
     * (recibo + mora_recibo / factura + mora_factura), alineado a SGA: no usar «Total parcial»
     * (suma de todos los medios). Depósito y traspaso siguen siendo sus canales completos.
     * `total_entregado`: suma de las cuatro columnas del formulario «Datos de recepción de ingresos»
     * (depósito + traspaso + (a) + (b)), no el `total_general` del libro (incluye otros medios no listados).
     */
    private function mapearResumenAMontos(array $res): array
    {
        $ef = $res['efectivo'] ?? null;
        $ef = is_array($ef) ? $ef : [];
        $recEf = (float) ($ef['recibo'] ?? 0) + (float) ($ef['mora_recibo'] ?? 0);
        $facEf = (float) ($ef['factura'] ?? 0) + (float) ($ef['mora_factura'] ?? 0);

        $deposito = $this->sumarCanal($res, 'deposito');
        $traspaso = $this->sumarCanal($res, 'traspaso');
        $recibos = round($recEf, 2);
        $facturas = round($facEf, 2);
        $entregado = round($deposito + $traspaso + $recibos + $facturas, 2);

        return [
            'total_deposito'  => $deposito,
            'total_traspaso'  => $traspaso,
            'total_recibos'   => $recibos,
            'total_facturas'  => $facturas,
            'total_entregado' => $entregado,
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
