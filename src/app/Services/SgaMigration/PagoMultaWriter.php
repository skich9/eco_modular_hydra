<?php

namespace App\Services\SgaMigration;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Migra cobros MORA y NIVELACION → tabla `pago_multa` del SGA.
 *
 * PK en SGA: (cod_ceta, cod_pensum, gestion, kardex_economico, num_cuota, num_pago).
 * num_pago = MAX(num_pago)+1 por (cod_ceta, cod_pensum, gestion, kardex_economico, num_cuota).
 * dias_multa y pu_multa vienen de cobros_detalle_multa (fallback 0).
 */
class PagoMultaWriter
{
    private array $excludedFacturas = [];

    public function __construct(
        private MapperHelper $mapper,
        private MigrationLog $log,
    ) {
        foreach (config('sga_migration.facturas_excluidas', []) as $conn => $porAnio) {
            foreach ($porAnio as $anio => $porNro) {
                foreach ($porNro as $nro => $codCetas) {
                    foreach ($codCetas as $codCeta) {
                        $this->excludedFacturas[$conn][(int) $anio][(int) $nro][(string) $codCeta] = true;
                    }
                }
            }
        }
    }

    public function run(string $from, string $until, bool $dryRun, BatchReport $report): void
    {
        DB::connection(MapperHelper::SOURCE_CONN)->table('cobro')
            ->whereIn('cod_tipo_cobro', ['MORA', 'NIVELACION'])
            ->whereBetween('fecha_cobro', ["{$from} 00:00:00", "{$until} 23:59:59"])
            ->whereNotNull('cod_inscrip')
            ->where(fn($q) => $q->whereNull('reposicion_factura')->orWhere('reposicion_factura', '!=', 1)->orWhere('tipo_documento', '!=', 'F')->orWhereNull('tipo_documento'))
            ->orderBy('fecha_cobro')
            ->chunk(200, function ($rows) use ($dryRun, $report) {
                foreach ($rows as $r) {
                    $this->processOne($r, $dryRun, $report);
                }
            });
    }

    private function processOne(object $r, bool $dryRun, BatchReport $report): void
    {
        $conn = $this->mapper->resolveConnectionByPensum($r->cod_pensum);
        if (!$conn) {
            $report->record('pago_multa', 'sin_ruta', 'skipped');
            return;
        }

        if ((float) $r->monto <= 0) {
            $report->record('pago_multa', $conn, 'skipped');
            return;
        }

        $sourcePk = (string) $r->nro_cobro;

        if (!empty($r->nro_factura) && $this->isFromExcludedFactura($r, $conn)) {
            if (!$dryRun && !$this->log->alreadyDone('cobro_pago_multa', $sourcePk, $conn)) {
                $this->log->write('cobro_pago_multa', $sourcePk, $conn, 'pago_multa', null, 'excluded',
                    "cobro ligado a factura excluida nro={$r->nro_factura}");
            }
            $report->record('pago_multa', $conn, 'skipped');
            return;
        }

        if (!$dryRun && $this->log->alreadyDone('cobro_pago_multa', $sourcePk, $conn)) {
            $report->record('pago_multa', $conn, 'skipped');
            return;
        }

        if ($dryRun) {
            $report->record('pago_multa', $conn, 'inserted');
            return;
        }

        try {
            $row = $this->buildRow($r, $conn);
            DB::connection($conn)->table('pago_multa')->insert($row);
            $destPk = "{$r->cod_ceta}|{$r->cod_pensum}|{$r->gestion}|{$r->tipo_inscripcion}|{$row['num_cuota']}|{$row['num_pago']}";
            $this->log->write('cobro_pago_multa', $sourcePk, $conn, 'pago_multa', $destPk, 'inserted');
            $report->record('pago_multa', $conn, 'inserted');
        } catch (\Throwable $e) {
            $this->log->write('cobro_pago_multa', $sourcePk, $conn, 'pago_multa', null, 'error', $e->getMessage());
            $report->record('pago_multa', $conn, 'errors');
        }
    }

    private function buildRow(object $r, string $conn): array
    {
        $numCuota = $this->resolveNumCuotaMora($r);
        $numPago  = $this->mapper->getNextNumPago($conn, 'pago_multa', [
            'cod_ceta'         => $r->cod_ceta,
            'cod_pensum'       => $r->cod_pensum,
            'gestion'          => $r->gestion,
            'kardex_economico' => $r->tipo_inscripcion,
            'num_cuota'        => $numCuota,
        ]);

        $detalle = DB::connection(MapperHelper::SOURCE_CONN)->table('cobros_detalle_multa')
            ->where('nro_cobro', $r->nro_cobro)
            ->first();

        $nota             = $this->mapper->getNotaBancaria($r);
        $nroNotaSga       = $this->mapper->resolveNroNotaSga($conn, $r);
        $cuenta           = $this->mapper->getCuentaBancaria($r);
        $esQr             = strtoupper($r->id_forma_cobro ?? '') === 'B' && $this->mapper->isQrPayment($r);
        $qrTransaccion    = $esQr ? $this->mapper->getQrTransaccion($r) : null;
        $qrRespuestaBanco = ($esQr && $qrTransaccion) ? $this->mapper->getQrRespuestaBanco($qrTransaccion) : null;
        $banking          = $this->resolveBanking($r, $nota, $cuenta, $esQr, $qrTransaccion, $qrRespuestaBanco);
        $clienteDoc       = $this->mapper->resolveClienteDoc($r);
        $reposicionCobro  = $this->resolveReposicionFacturaCobro($r);

        $obs = $this->mapper->resolveObservacionesPago($r, $banking, $nota, $esQr);
        if ($reposicionCobro && !empty($reposicionCobro->observaciones)) {
            $obs = ($obs !== null && $obs !== '' ? $obs . ' | ' : '') . trim($reposicionCobro->observaciones);
        }

        return [
            'cod_ceta'          => $r->cod_ceta,
            'cod_pensum'        => $r->cod_pensum,
            'gestion'           => $r->gestion,
            'kardex_economico'  => $r->tipo_inscripcion,
            'num_cuota'         => $numCuota,
            'num_pago'          => $numPago,
            'monto'             => (float) $r->monto,
            'dias_multa'        => (int) $r->monto,
            'num_comprobante'   => $r->nro_recibo  ? (int) $r->nro_recibo  : 0,
            'num_factura'       => $r->nro_factura
                ? (int) $r->nro_factura
                : ($reposicionCobro?->nro_factura ? (int) $reposicionCobro->nro_factura : 0),
            'fecha_pago'        => $r->fecha_cobro,
            'pago_completo'     => $this->isPagoCompleto($r),
            'observaciones'     => $obs,
            'usuario'           => $this->mapper->resolveUsuarioNickname($r->id_usuario),
            'razon'             => $clienteDoc['cliente'],
            'nro_documento_pago'=> $clienteDoc['nro_documento_cobro'],
            'autorizacion'      => '0',
            'valido'            => 'V',
            'concepto'          => $r->concepto ?: null,
            'codigo_control'    => 'cod_control',
            'codigo_qr'         => null,
            'descuento'         => (float) ($r->descuento ?? 0),
            'pu_multa'          => $detalle ? (float) $detalle->pu_multa : (float) $r->pu_mensualidad,
            'code_tipo_pago'    => $this->mapper->mapFormaCobro($r->id_forma_cobro),
            'fecha_deposito'    => $banking['fecha_deposito'],
            'nro_cuenta'        => $banking['nro_cuenta'],
            'nro_deposito'      => $banking['nro_deposito'],
            'nro_nota'          => $nroNotaSga,
            'banco_origen'      => $banking['banco_origen'],
            'nro_tarjeta'       => $banking['nro_tarjeta'],
            'estado_factura'    => null,
            'id_item_service'   => 3,
            'orden'             => $this->resolveOrden($conn, $r),
            'anulado'           => false,
            'fecha_anulacion'   => null,
            'usuario_anula'     => null,
        ];
    }

    /**
     * num_cuota para moras/nivelaciones.
     * 1) asignacion_costos.numero_cuota si hay id_asignacion_costo.
     * 2) Mes extraído del concepto entre paréntesis: "Mens. (Mayo) Niv" → 4.
     *    Mapeo para gestión 1/YYYY (Feb-Jun): Feb=1, Mar=2, Abr=3, May=4, Jun=5.
     * 3) Fallback id_cuota / order / 1.
     */
    private function resolveReposicionFacturaCobro(object $r): ?object
    {
        if (empty($r->reposicion_factura) || empty($r->cod_inscrip)) return null;

        return DB::connection(MapperHelper::SOURCE_CONN)->table('cobro')
            ->where('cod_inscrip',        $r->cod_inscrip)
            ->where('gestion',            $r->gestion)
            ->where('cod_tipo_cobro',     $r->cod_tipo_cobro)
            ->where('anio_cobro',         $r->anio_cobro)
            ->where('reposicion_factura', 1)
            ->where('tipo_documento',     'F')
            ->whereNotNull('nro_factura')
            ->first();
    }

    private function isFromExcludedFactura(object $r, string $conn): bool
    {
        $anio = (int) substr((string) $r->fecha_cobro, 0, 4);
        return isset($this->excludedFacturas[$conn][$anio][(int) $r->nro_factura][(string) $r->cod_ceta]);
    }

    private function resolveNumCuotaMora(object $r): int
    {
        if (!empty($r->id_asignacion_costo)) {
            $cuota = DB::connection(MapperHelper::SOURCE_CONN)
                ->table('asignacion_costos')
                ->where('id_asignacion_costo', $r->id_asignacion_costo)
                ->value('numero_cuota');
            if ($cuota !== null) return (int) $cuota;
        }

        if (!empty($r->concepto) && preg_match('/\(([^)]+)\)/i', $r->concepto, $m)) {
            static $mesMap = [
                'febrero' => 1, 'feb' => 1,
                'marzo'   => 2, 'mar' => 2,
                'abril'   => 3, 'abr' => 3,
                'mayo'    => 4, 'may' => 4,
                'junio'   => 5, 'jun' => 5,
            ];
            $mes = mb_strtolower(trim($m[1]));
            if (isset($mesMap[$mes])) return $mesMap[$mes];
        }

        return (int) (($r->id_cuota ?? 0) ?: ($r->order ?? 0) ?: 1);
    }

    private function isPagoCompleto(object $r): bool
    {
        if (empty($r->id_asignacion_costo)) return true;

        $montoMora = DB::connection(MapperHelper::SOURCE_CONN)
            ->table('asignacion_mora')
            ->where('id_asignacion_costo', $r->id_asignacion_costo)
            ->value('monto_mora');

        if ($montoMora === null) return true;

        // Acumula todos los cobros de esta asignación hasta este cobro inclusive.
        $acumulado = DB::connection(MapperHelper::SOURCE_CONN)
            ->table('cobro')
            ->where('id_asignacion_costo', $r->id_asignacion_costo)
            ->where('nro_cobro', '<=', $r->nro_cobro)
            ->sum('monto');

        return round((float) $acumulado, 2) >= round((float) $montoMora, 2);
    }

    /**
     * Orden del pago-multa dentro del mismo comprobante.
     * La mora es siempre el último ítem: MAX(orden en pago ∪ pago_multa)+1.
     * Si no hay registros previos → 0 (es el único ítem).
     * Para múltiples moras en el mismo comprobante, el procesamiento en
     * fecha_cobro ASC garantiza que la mora más antigua recibe el orden menor.
     */
    private function resolveOrden(string $conn, object $r): int
    {
        if (!empty($r->nro_recibo)) {
            $key = ['num_comprobante' => (int) $r->nro_recibo];
        } elseif (!empty($r->nro_factura)) {
            $key = ['num_factura' => (int) $r->nro_factura];
        } else {
            return 0;
        }

        $maxPago  = DB::connection($conn)->table('pago')->where($key)->max('orden');
        $maxMulta = DB::connection($conn)->table('pago_multa')->where($key)->max('orden');

        $max = max($maxPago ?? -1, $maxMulta ?? -1);
        return $max === -1 ? 0 : (int) $max + 1;
    }

    private function resolveBanking(
        object  $r,
        ?object $nota,
        ?object $cuenta,
        bool    $esQr,
        ?object $qrTransaccion,
        ?object $qrRespuestaBanco
    ): array {
        // fecha_deposito: QR usa processed_at / fecha_respuesta; otros usan nota_bancaria
        if ($esQr) {
            $fechaRaw      = ($qrTransaccion->processed_at ?? null)
                          ?: ($qrRespuestaBanco->fecha_respuesta ?? null);
            $fechaDeposito = $fechaRaw ? Carbon::parse($fechaRaw)->format('Y-m-d') : null;
        } else {
            $fechaDeposito = $nota ? ($nota->fecha_deposito ?: null) : null;
        }

        // nro_deposito: QR → numeroordenoriginante (max 50); otros → nro_transaccion (max 35)
        $nroDeposito = $esQr
            ? (mb_substr($qrRespuestaBanco->numeroordenoriginante ?? '', 0, 50) ?: null)
            : ($nota ? (mb_substr($nota->nro_transaccion ?? '', 0, 35) ?: null) : null);

        // banco_origen: solo B manual (sin QR), L, T → de nota. D y QR → null
        $bancoOrigen = (!$esQr && in_array(strtoupper($r->id_forma_cobro ?? ''), ['B', 'L', 'T']))
            ? ($nota ? (mb_substr($nota->banco_origen ?? '', 0, 200) ?: null) : null)
            : null;

        return [
            'fecha_deposito' => $fechaDeposito,
            'nro_cuenta'     => $cuenta ? ($cuenta->numero_cuenta ?? null) : null,
            'nro_deposito'   => $nroDeposito,
            'banco_origen'   => $bancoOrigen,
            'nro_tarjeta'    => $nota ? (mb_substr($nota->nro_tarjeta ?? '', 0, 200) ?: null) : null,
        ];
    }
}
