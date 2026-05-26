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
    public function __construct(
        private MapperHelper $mapper,
        private MigrationLog $log,
    ) {}

    public function run(string $from, string $until, bool $dryRun, BatchReport $report): void
    {
        DB::connection(MapperHelper::SOURCE_CONN)->table('cobro')
            ->whereIn('cod_tipo_cobro', ['MORA', 'NIVELACION'])
            ->whereBetween('fecha_cobro', ["{$from} 00:00:00", "{$until} 23:59:59"])
            ->whereNotNull('cod_inscrip')
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

        $sourcePk = (string) $r->nro_cobro;

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
        $numCuota = $this->mapper->resolveNumCuota($r);
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
        $cuenta           = $this->mapper->getCuentaBancaria($r);
        $esQr             = strtoupper($r->id_forma_cobro ?? '') === 'B' && $this->mapper->isQrPayment($r);
        $qrTransaccion    = $esQr ? $this->mapper->getQrTransaccion($r) : null;
        $qrRespuestaBanco = ($esQr && $qrTransaccion) ? $this->mapper->getQrRespuestaBanco($qrTransaccion) : null;
        $banking          = $this->resolveBanking($r, $nota, $cuenta, $esQr, $qrTransaccion, $qrRespuestaBanco);
        $clienteDoc       = $this->mapper->resolveClienteDoc($r);

        return [
            'cod_ceta'          => $r->cod_ceta,
            'cod_pensum'        => $r->cod_pensum,
            'gestion'           => $r->gestion,
            'kardex_economico'  => $r->tipo_inscripcion,
            'num_cuota'         => $numCuota,
            'num_pago'          => $numPago,
            'monto'             => (float) $r->monto,
            'dias_multa'        => $detalle ? (int) $detalle->dias_multa : 0,
            'num_comprobante'   => $r->nro_recibo  ? (int) $r->nro_recibo  : 0,
            'num_factura'       => $r->nro_factura ? (int) $r->nro_factura : 0,
            'fecha_pago'        => $r->fecha_cobro,
            'pago_completo'     => (bool) $r->cobro_completo,
            'observaciones'     => $this->mapper->resolveObservacionesPago($r, $banking, $nota, $esQr),
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
            'nro_nota'          => null,
            'banco_origen'      => $banking['banco_origen'],
            'nro_tarjeta'       => $banking['nro_tarjeta'],
            'estado_factura'    => null,
            'id_item_service'   => null,
            'orden'             => $r->order ? (int) $r->order : null,
            'anulado'           => false,
            'fecha_anulacion'   => null,
            'usuario_anula'     => null,
        ];
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
