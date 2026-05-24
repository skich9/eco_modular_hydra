<?php

namespace App\Services\SgaMigration;

use Illuminate\Support\Facades\DB;

/**
 * Migra cobros MENSUALIDAD y ARRASTRE → tabla `pago` del SGA.
 *
 * PK en SGA: (cod_ceta, cod_pensum, cod_inscrip, kardex_economico, num_cuota, num_pago).
 * num_pago = MAX(num_pago)+1 por (cod_inscrip, num_cuota) — igual que Pagos.php línea 116.
 * Idempotencia: fuente de verdad es sga_migration_log (source_pk = nro_cobro).
 */
class PagoWriter
{
    public function __construct(
        private MapperHelper $mapper,
        private MigrationLog $log,
    ) {}

    public function run(string $from, string $until, bool $dryRun, BatchReport $report): void
    {
        DB::connection(MapperHelper::SOURCE_CONN)->table('cobro')
            ->whereIn('cod_tipo_cobro', ['MENSUALIDAD', 'ARRASTRE'])
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
            $report->record('pago', 'sin_ruta', 'skipped');
            return;
        }

        $sourcePk = (string) $r->nro_cobro;

        if (!$dryRun && $this->log->alreadyDone('cobro_pago', $sourcePk, $conn)) {
            $report->record('pago', $conn, 'skipped');
            return;
        }

        if ($dryRun) {
            $report->record('pago', $conn, 'inserted');
            return;
        }

        try {
            $row = $this->buildRow($r, $conn);
            DB::connection($conn)->table('pago')->insert($row);
            $destPk = "{$r->cod_ceta}|{$r->cod_pensum}|{$r->cod_inscrip}|{$r->tipo_inscripcion}|{$row['num_cuota']}|{$row['num_pago']}";
            $this->log->write('cobro_pago', $sourcePk, $conn, 'pago', $destPk, 'inserted');
            $report->record('pago', $conn, 'inserted');
        } catch (\Throwable $e) {
            $this->log->write('cobro_pago', $sourcePk, $conn, 'pago', null, 'error', $e->getMessage());
            $report->record('pago', $conn, 'errors');
        }
    }

    private function buildRow(object $r, string $conn): array
    {
        $numCuota  = $this->mapper->resolveNumCuota($r);
        $numPago   = $this->mapper->getNextNumPago($conn, 'pago', [
            'cod_inscrip' => (int) $r->cod_inscrip,
            'num_cuota'   => $numCuota,
        ]);

        $nota    = $this->mapper->getNotaBancaria($r);
        $cuenta  = $this->mapper->getCuentaBancaria($r);
        $banking = $this->resolveBanking($r, $nota, $cuenta);

        return [
            'cod_ceta'          => $r->cod_ceta,
            'cod_pensum'        => $r->cod_pensum,
            'cod_inscrip'       => (int) $r->cod_inscrip,
            'kardex_economico'  => $r->tipo_inscripcion,
            'num_cuota'         => $numCuota,
            'num_pago'          => $numPago,
            'monto'             => (float) $r->monto,
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
            'pu_mensualidad'    => (float) $r->pu_mensualidad,
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
            'turno'             => $this->resolveTurno($r),
            'anulado'           => false,
            'fecha_anulacion'   => null,
            'usuario_anula'     => null,
        ];
    }

    private function resolveBanking(object $r, ?object $nota, ?object $cuenta): array
    {
        $out = ['fecha_deposito' => null, 'nro_cuenta' => null, 'nro_deposito' => null, 'banco_origen' => null, 'nro_tarjeta' => null];

        if ($nota) {
            $out['fecha_deposito'] = $nota->fecha_deposito ?: null;
            $out['nro_deposito']   = $nota->nro_transaccion ?: null;
            $out['banco_origen']   = $nota->banco_origen ?: null;
            $out['nro_tarjeta']    = $nota->nro_tarjeta ?: null;
        }
        if ($cuenta) {
            $out['nro_cuenta']  = $out['nro_cuenta'] ?? ($cuenta->nro_cuenta ?? null);
            $out['banco_origen'] = $out['banco_origen'] ?: ($cuenta->banco ?? null);
        }
        return $out;
    }

    private function resolveTurno(object $r): ?string
    {
        if (!$r->nro_cobro) return null;
        $det = DB::connection(MapperHelper::SOURCE_CONN)->table('cobros_detalle_regular')
            ->where('nro_cobro', $r->nro_cobro)
            ->value('turno');
        return $det ?: null;
    }
}
