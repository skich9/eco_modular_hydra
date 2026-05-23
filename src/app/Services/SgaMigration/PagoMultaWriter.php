<?php

namespace App\Services\SgaMigration;

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
            'cod_ceta'        => $r->cod_ceta,
            'cod_pensum'      => $r->cod_pensum,
            'gestion'         => $r->gestion,
            'kardex_economico'=> $r->tipo_inscripcion,
            'num_cuota'       => $numCuota,
        ]);

        $detalle = DB::connection(MapperHelper::SOURCE_CONN)->table('cobros_detalle_multa')
            ->where('nro_cobro', $r->nro_cobro)
            ->first();

        $nota    = $this->mapper->getNotaBancaria($r);
        $cuenta  = $this->mapper->getCuentaBancaria($r);
        $banking = $this->resolveBanking($nota, $cuenta);

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
            'observaciones'     => $r->observaciones ?: null,
            'usuario'           => $this->mapper->resolveUsuarioNickname($r->id_usuario),
            'razon'             => null,
            'nro_documento_pago'=> null,
            'autorizacion'      => null,
            'valido'            => 'V',
            'concepto'          => $r->concepto ?: null,
            'codigo_control'    => null,
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

    private function resolveBanking(?object $nota, ?object $cuenta): array
    {
        $out = ['fecha_deposito' => null, 'nro_cuenta' => null, 'nro_deposito' => null, 'banco_origen' => null, 'nro_tarjeta' => null];
        if ($nota) {
            $out['fecha_deposito'] = $nota->fecha_deposito ?: null;
            $out['nro_deposito']   = $nota->nro_transaccion ?: null;
            $out['banco_origen']   = $nota->banco_origen ?: null;
            $out['nro_tarjeta']    = $nota->nro_tarjeta ?: null;
        }
        if ($cuenta) {
            $out['nro_cuenta']   = $out['nro_cuenta'] ?? ($cuenta->nro_cuenta ?? null);
            $out['banco_origen'] = $out['banco_origen'] ?: ($cuenta->banco ?? null);
        }
        return $out;
    }
}
