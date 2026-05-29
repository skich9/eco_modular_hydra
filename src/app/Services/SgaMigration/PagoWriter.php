<?php

namespace App\Services\SgaMigration;

use Carbon\Carbon;
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
            ->whereIn('cod_tipo_cobro', ['MENSUALIDAD', 'ARRASTRE'])
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
            $report->record('pago', 'sin_ruta', 'skipped');
            return;
        }

        // cobro.cod_inscrip es la PK interna de sistemaEco.
        // El cod_inscrip real del SGA está en inscripciones.source_cod_inscrip.
        $sgaCodInscrip = $this->mapper->resolveSourceCodInscrip((int) $r->cod_inscrip);
        if ($sgaCodInscrip === null) {
            $this->log->write('cobro_pago', (string) $r->nro_cobro, $conn, 'pago', null, 'error',
                "inscripcion eco_id={$r->cod_inscrip} sin source_cod_inscrip");
            $report->record('pago', $conn, 'errors');
            return;
        }

        $sourcePk = (string) $r->nro_cobro;

        if (!empty($r->nro_factura) && $this->isFromExcludedFactura($r, $conn)) {
            if (!$dryRun && !$this->log->alreadyDone('cobro_pago', $sourcePk, $conn)) {
                $this->log->write('cobro_pago', $sourcePk, $conn, 'pago', null, 'excluded',
                    "cobro ligado a factura excluida nro={$r->nro_factura}");
            }
            $report->record('pago', $conn, 'skipped');
            return;
        }

        if (!$dryRun && $this->log->alreadyDone('cobro_pago', $sourcePk, $conn)) {
            $report->record('pago', $conn, 'skipped');
            return;
        }

        if ($dryRun) {
            $report->record('pago', $conn, 'inserted');
            return;
        }

        try {
            $row = $this->buildRow($r, $conn, $sgaCodInscrip);
            DB::connection($conn)->table('pago')->insert($row);
            $destPk = "{$r->cod_ceta}|{$r->cod_pensum}|{$sgaCodInscrip}|{$r->tipo_inscripcion}|{$row['num_cuota']}|{$row['num_pago']}";
            $this->log->write('cobro_pago', $sourcePk, $conn, 'pago', $destPk, 'inserted');
            $report->record('pago', $conn, 'inserted');
        } catch (\Throwable $e) {
            $this->log->write('cobro_pago', $sourcePk, $conn, 'pago', null, 'error', $e->getMessage());
            $report->record('pago', $conn, 'errors');
        }
    }

    private function buildRow(object $r, string $conn, int $sgaCodInscrip): array
    {
        $numCuota  = $this->mapper->resolveNumCuota($r);
        $numPago   = $this->mapper->getNextNumPago($conn, 'pago', [
            'cod_inscrip' => $sgaCodInscrip,
            'num_cuota'   => $numCuota,
        ]);

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
            'cod_inscrip'       => $sgaCodInscrip,
            'kardex_economico'  => $r->tipo_inscripcion,
            'num_cuota'         => $numCuota,
            'num_pago'          => $numPago,
            'monto'             => (float) $r->monto,
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
            'codigo_control'    => 'cod_control', // se usa cod_control por defecto en casi todo
            'codigo_qr'         => null,
            'descuento'         => (float) ($r->descuento ?? 0),
            'pu_mensualidad'    => (float) $r->pu_mensualidad,
            'code_tipo_pago'    => $this->mapper->mapFormaCobro($r->id_forma_cobro),
            'fecha_deposito'    => $banking['fecha_deposito'],
            'nro_cuenta'        => $banking['nro_cuenta'],
            'nro_deposito'      => $banking['nro_deposito'],
            'nro_nota'          => $nroNotaSga,
            'banco_origen'      => $banking['banco_origen'],
            'nro_tarjeta'       => $banking['nro_tarjeta'],
            'estado_factura'    => null,
            'id_item_service'   => $r->cod_tipo_cobro === 'MENSUALIDAD' ? 1 : 2,
            'orden'             => $this->resolveOrden($conn, $r),
            'turno'             => $this->resolveTurno($r),
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

        // banco_origen: solo B manual (sin QR), L, T → de nota_bancaria. D y QR → null
        $bancoOrigen = (!$esQr && in_array(strtoupper($r->id_forma_cobro ?? ''), ['B', 'L', 'T']))
            ? ($nota ? (mb_substr($nota->banco_origen ?? '', 0, 200) ?: null) : null)
            : null;

        // nro_tarjeta: siempre de nota_bancaria (max 200)
        $nroTarjeta = $nota ? (mb_substr($nota->nro_tarjeta ?? '', 0, 200) ?: null) : null;

        return [
            'fecha_deposito' => $fechaDeposito,
            'nro_cuenta'     => $cuenta ? ($cuenta->numero_cuenta ?? null) : null,
            'nro_deposito'   => $nroDeposito,
            'banco_origen'   => $bancoOrigen,
            'nro_tarjeta'    => $nroTarjeta,
        ];
    }

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

    private function isPagoCompleto(object $r): bool
    {
        if (empty($r->id_asignacion_costo)) return true;

        $montoAsignado = DB::connection(MapperHelper::SOURCE_CONN)
            ->table('asignacion_costos')
            ->where('id_asignacion_costo', $r->id_asignacion_costo)
            ->value('monto');

        if ($montoAsignado === null) return true;

        // Acumula todos los cobros de esta asignación hasta este cobro inclusive.
        // El cobro cuyo acumulado llega a >= monto es el que cierra la cuota.
        $acumulado = DB::connection(MapperHelper::SOURCE_CONN)
            ->table('cobro')
            ->where('id_asignacion_costo', $r->id_asignacion_costo)
            ->where('nro_cobro', '<=', $r->nro_cobro)
            ->sum('monto');

        return round((float) $acumulado, 2) >= round((float) $montoAsignado, 2);
    }

    private function resolveTurno(object $r): ?string
    {
        if (!$r->nro_cobro) return null;
        $det = DB::connection(MapperHelper::SOURCE_CONN)->table('cobros_detalle_regular')
            ->where('nro_cobro', $r->nro_cobro)
            ->value('turno');
        return $det ?: null;
    }

    /**
     * Orden del pago dentro del mismo comprobante (0-based).
     * SGA agrupa los ítems de una misma transacción por num_comprobante/num_factura
     * y los ordena desde 0. Como sistemaEco no guarda ese orden, se asigna
     * secuencialmente: MAX(orden)+1 para el mismo comprobante, 0 si es el primero.
     */
    private function resolveOrden(string $conn, object $r): int
    {
        if (!empty($r->nro_recibo)) {
            $where = ['num_comprobante' => (int) $r->nro_recibo];
        } elseif (!empty($r->nro_factura)) {
            $where = ['num_factura' => (int) $r->nro_factura];
        } else {
            return 0;
        }

        $max = DB::connection($conn)->table('pago')->where($where)->max('orden');
        return $max === null ? 0 : (int) $max + 1;
    }
}
