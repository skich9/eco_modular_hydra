<?php

namespace App\Console\Commands;

use App\Services\SgaMigration\BatchReport;
use App\Services\SgaMigration\MapperHelper;
use App\Services\SgaMigration\MigrationLog;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Re-inserta los cobros-factura de reposición que fueron excluidos en la migración
 * principal (PagoWriter/PagoMultaWriter los filtraban en su cláusula WHERE).
 *
 * Por cada cobro con reposicion_factura=1 y tipo_documento='F':
 *   1. FIX del recibo hermano: mueve cod_inscrip+000 (pago) / cod_pensum+000 (pago_multa),
 *      limpia num_factura y reconstruye observaciones.
 *   2. INSERT del cobro-factura como nuevo pago/pago_multa.
 *
 * El hermano se localiza en sistemaEco (eco_backup) por (cod_ceta, gestion,
 * cod_tipo_cobro, monto) y se verifica en sga_migration_log.
 *
 * Uso:
 *   php artisan sga:reinsert-reposicion [--dry-run] [--from=] [--until=]
 *   php artisan sga:reinsert-reposicion --rollback [--from=pushed_at_date]
 */
class SgaReinsertReposicionCommand extends Command
{
    protected $signature = 'sga:reinsert-reposicion
        {--from=     : Fecha inicial Y-m-d de cobros (insert) o de pushed_at (rollback). Default 2026-04-23}
        {--until=    : Fecha final Y-m-d de cobros (insert). Default 2026-05-22}
        {--dry-run   : Simula sin escribir nada}
        {--rollback  : Revierte lo insertado por este comando (usa --from como pushed_at mínimo)}';

    protected $description = 'Re-inserta cobros-factura de reposición excluidos en la migración y corrige los pagos de recibo hermano.';

    public function __construct(
        private MapperHelper $mapper,
        private MigrationLog $log,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($this->option('rollback')) {
            return $this->handleRollback($dryRun);
        }

        return $this->handleInsert($dryRun);
    }

    // ─── ROLLBACK ────────────────────────────────────────────────────────────

    private function handleRollback(bool $dryRun): int
    {
        $from = $this->option('from') ? Carbon::parse($this->option('from'))->format('Y-m-d') : null;

        $mode = $dryRun ? '<comment>[DRY-RUN]</comment>' : '<fg=red>[ROLLBACK]</>';
        $rangoLabel = $from ? "pushed_at >= {$from}" : 'sin filtro de fecha';
        $this->line("{$mode} Rollback reposición   {$rangoLabel}");
        $this->newLine();

        if (!$dryRun) {
            if (!$this->confirm('¿Confirmar BORRADO de lo insertado por sga:reinsert-reposicion?', false)) {
                $this->line('Cancelado.');
                return self::SUCCESS;
            }
            $this->newLine();
        }

        $sourceTables = [
            'reposicion_pago'            => ['dest_table' => 'pago',       'pk' => ['cod_ceta','cod_pensum','cod_inscrip','kardex_economico','num_cuota','num_pago']],
            'reposicion_pago_multa'      => ['dest_table' => 'pago_multa', 'pk' => ['cod_ceta','cod_pensum','gestion','kardex_economico','num_cuota','num_pago']],
            'reposicion_recibo_fix'      => ['dest_table' => 'pago',       'pk' => ['cod_ceta','cod_pensum','cod_inscrip','kardex_economico','num_cuota','num_pago']],
            'reposicion_recibo_multa_fix'=> ['dest_table' => 'pago_multa', 'pk' => ['cod_ceta','cod_pensum','gestion','kardex_economico','num_cuota','num_pago']],
        ];

        $deleted = 0;
        $errors  = 0;

        foreach ($sourceTables as $sourceTable => $cfg) {
            $q = DB::table('sga_migration_log')
                ->where('source_table', $sourceTable)
                ->where('status', 'inserted')
                ->whereNotNull('dest_pk');

            if ($from) {
                $q->where('pushed_at', '>=', "{$from} 00:00:00");
            }

            $entries = $q->get();
            $this->line("  [{$sourceTable}] {$entries->count()} entradas");

            foreach ($entries as $entry) {
                if ($dryRun) {
                    $deleted++;
                    continue;
                }

                try {
                    $where = array_combine($cfg['pk'], explode('|', $entry->dest_pk));
                    DB::connection($entry->dest_conn)->table($cfg['dest_table'])->where($where)->delete();

                    // Para fix de recibo: eliminar log original del hermano para permitir re-push
                    if (in_array($sourceTable, ['reposicion_recibo_fix', 'reposicion_recibo_multa_fix'])) {
                        $origSrcTable = $sourceTable === 'reposicion_recibo_fix' ? 'cobro_pago' : 'cobro_pago_multa';
                        DB::table('sga_migration_log')
                            ->where('source_table', $origSrcTable)
                            ->where('source_pk',    $entry->source_pk)
                            ->where('dest_conn',    $entry->dest_conn)
                            ->where('status',       'inserted')
                            ->delete();
                    }

                    DB::table('sga_migration_log')->where('id', $entry->id)->delete();
                    $deleted++;
                } catch (\Throwable $e) {
                    $errors++;
                    $this->warn("  Error id={$entry->id} pk={$entry->dest_pk}: {$e->getMessage()}");
                }
            }
        }

        $this->newLine();
        $label = $dryRun ? 'A revertir' : 'Revertidos';
        $this->info("{$label}: {$deleted}   Errores: {$errors}");

        if (!$dryRun && $errors === 0) {
            $this->info('Ahora puedes correr sga:push-cobros para reinsertar los recibos originales.');
        }

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    // ─── INSERT PRINCIPAL ─────────────────────────────────────────────────────

    private function handleInsert(bool $dryRun): int
    {
        $from  = $this->option('from')  ?: '2026-04-23';
        $until = $this->option('until') ?: '2026-05-22';

        try {
            $from  = Carbon::parse($from)->format('Y-m-d');
            $until = Carbon::parse($until)->format('Y-m-d');
        } catch (\Throwable) {
            $this->error('Fecha inválida. Use formato Y-m-d.');
            return self::FAILURE;
        }

        if ($from > $until) {
            $this->error('--from no puede ser mayor que --until.');
            return self::FAILURE;
        }

        $mode = $dryRun ? '<comment>[DRY-RUN]</comment>' : '<info>[REAL]</info>';
        $this->line("{$mode} Re-insertar reposiciones   rango: {$from} → {$until}");
        $this->newLine();

        if (!$dryRun) {
            if (!$this->confirm("¿Confirmar operación REAL en el SGA del rango {$from} → {$until}?", false)) {
                $this->line('Cancelado.');
                return self::SUCCESS;
            }
            $this->newLine();
        }

        $report = new BatchReport();

        $cobrosFactura = DB::connection(MapperHelper::SOURCE_CONN)->table('cobro')
            ->where('reposicion_factura', 1)
            ->where('tipo_documento', 'F')
            ->whereBetween('fecha_cobro', ["{$from} 00:00:00", "{$until} 23:59:59"])
            ->whereNotNull('cod_inscrip')
            ->whereIn('cod_tipo_cobro', ['MENSUALIDAD', 'ARRASTRE', 'MORA', 'NIVELACION'])
            ->orderBy('fecha_cobro')
            ->get();

        $this->info("Cobros-factura de reposición encontrados: {$cobrosFactura->count()}");

        foreach ($cobrosFactura as $factura) {
            $this->processFactura($factura, $dryRun, $report);
        }

        $this->newLine();
        $this->info('=== Reporte ===');
        foreach ($report->rows() as $row) {
            $this->line("  [{$row['table']}][{$row['conn']}] inserted={$row['inserted']} skipped={$row['skipped']} errors={$row['errors']}");
        }

        return self::SUCCESS;
    }

    private function processFactura(object $factura, bool $dryRun, BatchReport $report): void
    {
        $esPago      = in_array($factura->cod_tipo_cobro, ['MENSUALIDAD', 'ARRASTRE']);
        $tabla       = $esPago ? 'pago' : 'pago_multa';
        $logFactura  = $esPago ? 'reposicion_pago'       : 'reposicion_pago_multa';
        $logFix      = $esPago ? 'reposicion_recibo_fix' : 'reposicion_recibo_multa_fix';
        $hermanoSrcTable = $esPago ? 'cobro_pago'        : 'cobro_pago_multa';

        $conn = $this->mapper->resolveConnectionByPensum($factura->cod_pensum);
        if (!$conn) {
            $this->warn("nro_cobro={$factura->nro_cobro}: sin ruta para pensum={$factura->cod_pensum}");
            $report->record($tabla, 'sin_ruta', 'skipped');
            return;
        }

        // Buscar hermano recibo en sistemaEco por (cod_ceta, gestion, cod_tipo_cobro, monto).
        // No se usa cod_inscrip porque para cobros ARRASTRE el cobro-factura puede tener
        // el eco_cod_inscrip de la inscripción NORMAL, diferente al del recibo ARRASTRE.
        // El monto identifica cada ítem dentro de la misma transacción de reposición.
        $reciboHermano = DB::connection(MapperHelper::SOURCE_CONN)->table('cobro')
            ->where('cod_ceta',           $factura->cod_ceta)
            ->where('gestion',            $factura->gestion)
            ->where('cod_tipo_cobro',     $factura->cod_tipo_cobro)
            ->where('monto',              $factura->monto)
            ->where('reposicion_factura', 1)
            ->where('tipo_documento',     'R')
            ->whereNotNull('nro_recibo')
            ->first();

        if (!$reciboHermano) {
            $this->warn("nro_cobro={$factura->nro_cobro}: no se encontró hermano recibo.");
            $report->record($tabla, $conn, 'skipped');
            return;
        }

        // Verificar que el hermano fue migrado y obtener su dest_pk
        $logHermano = DB::table('sga_migration_log')
            ->where('source_table', $hermanoSrcTable)
            ->where('source_pk',    (string) $reciboHermano->nro_cobro)
            ->where('dest_conn',    $conn)
            ->where('status',       'inserted')
            ->first();

        if (!$logHermano || !$logHermano->dest_pk) {
            $this->warn("nro_cobro={$factura->nro_cobro}: hermano nro_cobro={$reciboHermano->nro_cobro} no migrado o sin dest_pk.");
            $report->record($tabla, $conn, 'skipped');
            return;
        }

        $sourcePkFactura = (string) $factura->nro_cobro;
        $sourcePkRecibo  = (string) $reciboHermano->nro_cobro;

        // Idempotencia INSERT factura
        if (!$dryRun && $this->log->alreadyDone($logFactura, $sourcePkFactura, $conn)) {
            $report->record($tabla, $conn, 'skipped');
            return;
        }

        if ($dryRun) {
            $this->line("  [dry] FIX {$tabla} nro_cobro={$reciboHermano->nro_cobro} | INSERT nro_cobro={$factura->nro_cobro}");
            $report->record($tabla, $conn, 'inserted');
            return;
        }

        DB::beginTransaction();
        try {
            // PASO 1: FIX hermano primero → libera el slot (cod_inscrip/cod_pensum) para la factura
            if (!$this->log->alreadyDone($logFix, $sourcePkRecibo, $conn)) {
                if ($esPago) {
                    $this->fixReciboPago($reciboHermano, $conn, $logHermano->dest_pk, $logFix, $sourcePkRecibo, $report);
                } else {
                    $this->fixReciboMulta($reciboHermano, $conn, $logHermano->dest_pk, $logFix, $sourcePkRecibo, $report);
                }
            }

            // PASO 2: INSERT cobro-factura (slot ya libre tras el FIX)
            if ($esPago) {
                $this->insertPago($factura, $reciboHermano, $logHermano->dest_pk, $conn, $sourcePkFactura, $report);
            } else {
                $this->insertPagoMulta($factura, $reciboHermano, $logHermano->dest_pk, $conn, $sourcePkFactura, $report);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error("nro_cobro={$factura->nro_cobro}: {$e->getMessage()}");
            $this->log->write($logFactura, $sourcePkFactura, $conn, $tabla, null, 'error', $e->getMessage());
            $report->record($tabla, $conn, 'errors');
        }
    }

    // ─── INSERT pago (MENSUALIDAD / ARRASTRE) ────────────────────────────────

    /**
     * @param string $destPkHermano formato: cod_ceta|cod_pensum|cod_inscrip|kardex|num_cuota|num_pago
     */
    private function insertPago(
        object $factura, object $hermano, string $destPkHermano,
        string $conn, string $sourcePk, BatchReport $report
    ): void {
        // cod_inscrip y kardex del hermano (valores SGA correctos, ej. ARRASTRE=48092).
        // num_cuota desde el cobro-factura. num_pago: INSERT con un slot libre garantizado,
        // luego intentar mover al num_pago del hermano si ese slot quedó libre tras el FIX.
        [, , $sgaCodInscrip, $kardex, , $hermanoNumPago] = explode('|', $destPkHermano);
        $sgaCodInscrip  = (int) $sgaCodInscrip;
        $hermanoNumPago = (int) $hermanoNumPago;
        $numCuota       = $this->mapper->resolveNumCuota($factura);

        // Usar MAX+1 como num_pago temporal → nunca colisiona con ninguna fila existente
        $tempNumPago = $this->mapper->getNextNumPago($conn, 'pago', [
            'cod_inscrip' => $sgaCodInscrip,
            'num_cuota'   => $numCuota,
        ]);

        $row = $this->buildPagoRow($factura, $hermano, $conn, $sgaCodInscrip, $kardex, $numCuota, $tempNumPago);
        DB::connection($conn)->table('pago')->insert($row);

        // Intentar mover al num_pago canónico del hermano si ese slot quedó libre
        // (el FIX ya corrió en PASO 1 y pudo haber liberado ese slot)
        $finalNumPago = $tempNumPago;
        if ($tempNumPago !== $hermanoNumPago) {
            $slotLibre = !DB::connection($conn)->table('pago')
                ->where('cod_ceta',        $factura->cod_ceta)
                ->where('cod_pensum',       $factura->cod_pensum)
                ->where('cod_inscrip',      $sgaCodInscrip)
                ->where('kardex_economico', $kardex)
                ->where('num_cuota',        $numCuota)
                ->where('num_pago',         $hermanoNumPago)
                ->exists();
            if ($slotLibre) {
                DB::connection($conn)->table('pago')
                    ->where('cod_ceta',        $factura->cod_ceta)
                    ->where('cod_pensum',       $factura->cod_pensum)
                    ->where('cod_inscrip',      $sgaCodInscrip)
                    ->where('kardex_economico', $kardex)
                    ->where('num_cuota',        $numCuota)
                    ->where('num_pago',         $tempNumPago)
                    ->update(['num_pago' => $hermanoNumPago]);
                $finalNumPago = $hermanoNumPago;
            }
        }

        $destPk = "{$factura->cod_ceta}|{$factura->cod_pensum}|{$sgaCodInscrip}|{$kardex}|{$numCuota}|{$finalNumPago}";
        $this->log->write('reposicion_pago', $sourcePk, $conn, 'pago', $destPk, 'inserted');
        $report->record('pago', $conn, 'inserted');
    }

    private function buildPagoRow(
        object $factura, object $hermano, string $conn,
        int $sgaCodInscrip, string $kardex, int $numCuota, int $numPago
    ): array {
        // Banking: usar nro_recibo del hermano para encontrar la nota_bancaria.
        // La nota_bancaria en sistemaEco se vincula al recibo, no a la factura.
        $notaLookup = (object) [
            'nro_recibo'           => $hermano->nro_recibo,
            'nro_factura'          => null,
            'id_cuentas_bancarias' => $factura->id_cuentas_bancarias ?? null,
            'id_forma_cobro'       => $factura->id_forma_cobro,
            'qr_alias'             => $factura->qr_alias ?? null,
            'observaciones'        => $factura->observaciones ?? null,
            'cod_ceta'             => $factura->cod_ceta,
        ];
        $nota             = $this->mapper->getNotaBancaria($notaLookup);
        $cuenta           = $this->mapper->getCuentaBancaria($factura);
        $esQr             = strtoupper($factura->id_forma_cobro ?? '') === 'B' && $this->mapper->isQrPayment($notaLookup);
        $qrTransaccion    = $esQr ? $this->mapper->getQrTransaccion($notaLookup) : null;
        $qrRespuestaBanco = ($esQr && $qrTransaccion) ? $this->mapper->getQrRespuestaBanco($qrTransaccion) : null;
        $banking          = $this->resolveBanking($factura, $nota, $cuenta, $esQr, $qrTransaccion, $qrRespuestaBanco);
        $clienteDoc       = $this->mapper->resolveClienteDoc($factura);
        $obs              = trim($this->mapper->resolveObservacionesPago($factura, $banking, $nota, $esQr) ?? '');
        $obs             .= ' Nro recibo: ' . $hermano->nro_recibo;

        return [
            'cod_ceta'           => $factura->cod_ceta,
            'cod_pensum'         => $factura->cod_pensum,
            'cod_inscrip'        => $sgaCodInscrip,
            'kardex_economico'   => $kardex,
            'num_cuota'          => $numCuota,
            'num_pago'           => $numPago,
            'monto'              => (float) $factura->monto,
            'num_comprobante'    => 0,
            'num_factura'        => $factura->nro_factura ? (int) $factura->nro_factura : 0,
            'fecha_pago'         => $factura->fecha_cobro,
            'pago_completo'      => $this->isPagoCompleto($factura),
            'observaciones'      => $obs,
            'usuario'            => 'Reposicion',
            'razon'              => $clienteDoc['cliente'],
            'nro_documento_pago' => $clienteDoc['nro_documento_cobro'],
            'autorizacion'       => '0',
            'valido'             => 'V',
            'concepto'           => $factura->concepto ?: null,
            'codigo_control'     => 'cod_control',
            'codigo_qr'          => null,
            'descuento'          => (float) ($factura->descuento ?? 0),
            'pu_mensualidad'     => (float) $factura->pu_mensualidad,
            'code_tipo_pago'     => 'E',
            'fecha_deposito'     => null,
            'nro_cuenta'         => null,
            'nro_deposito'       => null,
            'nro_nota'           => 0,
            'banco_origen'       => null,
            'nro_tarjeta'        => null,
            'estado_factura'     => null,
            'id_item_service'    => $factura->cod_tipo_cobro === 'MENSUALIDAD' ? 1 : 2,
            'orden'              => $this->resolveOrdenPago($conn, $factura),
            'turno'              => $this->resolveTurno($factura),
            'anulado'            => false,
            'fecha_anulacion'    => null,
            'usuario_anula'      => null,
        ];
    }

    // ─── INSERT pago_multa (MORA / NIVELACION) ────────────────────────────────

    /**
     * @param string $destPkHermano formato: cod_ceta|cod_pensum|gestion|kardex|num_cuota|num_pago
     */
    private function insertPagoMulta(
        object $factura, object $hermano, string $destPkHermano,
        string $conn, string $sourcePk, BatchReport $report
    ): void {
        [, , $gestion, $kardex, , $hermanoNumPago] = explode('|', $destPkHermano);
        $hermanoNumPago = (int) $hermanoNumPago;
        $numCuota       = $this->resolveNumCuotaMora($factura);

        $tempNumPago = $this->mapper->getNextNumPago($conn, 'pago_multa', [
            'cod_ceta'         => $factura->cod_ceta,
            'cod_pensum'       => $factura->cod_pensum,
            'gestion'          => $gestion,
            'kardex_economico' => $kardex,
            'num_cuota'        => $numCuota,
        ]);

        $row = $this->buildPagoMultaRow($factura, $hermano, $conn, $gestion, $kardex, $numCuota, $tempNumPago);
        DB::connection($conn)->table('pago_multa')->insert($row);

        $finalNumPago = $tempNumPago;
        if ($tempNumPago !== $hermanoNumPago) {
            $slotLibre = !DB::connection($conn)->table('pago_multa')
                ->where('cod_ceta',        $factura->cod_ceta)
                ->where('cod_pensum',       $factura->cod_pensum)
                ->where('gestion',          $gestion)
                ->where('kardex_economico', $kardex)
                ->where('num_cuota',        $numCuota)
                ->where('num_pago',         $hermanoNumPago)
                ->exists();
            if ($slotLibre) {
                DB::connection($conn)->table('pago_multa')
                    ->where('cod_ceta',        $factura->cod_ceta)
                    ->where('cod_pensum',       $factura->cod_pensum)
                    ->where('gestion',          $gestion)
                    ->where('kardex_economico', $kardex)
                    ->where('num_cuota',        $numCuota)
                    ->where('num_pago',         $tempNumPago)
                    ->update(['num_pago' => $hermanoNumPago]);
                $finalNumPago = $hermanoNumPago;
            }
        }

        $destPk = "{$factura->cod_ceta}|{$factura->cod_pensum}|{$gestion}|{$kardex}|{$numCuota}|{$finalNumPago}";
        $this->log->write('reposicion_pago_multa', $sourcePk, $conn, 'pago_multa', $destPk, 'inserted');
        $report->record('pago_multa', $conn, 'inserted');
    }

    private function buildPagoMultaRow(
        object $factura, object $hermano, string $conn,
        string $gestion, string $kardex, int $numCuota, int $numPago
    ): array {
        $detalle = DB::connection(MapperHelper::SOURCE_CONN)->table('cobros_detalle_multa')
            ->where('nro_cobro', $factura->nro_cobro)->first();

        $notaLookup = (object) [
            'nro_recibo'           => $hermano->nro_recibo,
            'nro_factura'          => null,
            'id_cuentas_bancarias' => $factura->id_cuentas_bancarias ?? null,
            'id_forma_cobro'       => $factura->id_forma_cobro,
            'qr_alias'             => $factura->qr_alias ?? null,
            'observaciones'        => $factura->observaciones ?? null,
            'cod_ceta'             => $factura->cod_ceta,
        ];
        $nota             = $this->mapper->getNotaBancaria($notaLookup);
        $cuenta           = $this->mapper->getCuentaBancaria($factura);
        $esQr             = strtoupper($factura->id_forma_cobro ?? '') === 'B' && $this->mapper->isQrPayment($notaLookup);
        $qrTransaccion    = $esQr ? $this->mapper->getQrTransaccion($notaLookup) : null;
        $qrRespuestaBanco = ($esQr && $qrTransaccion) ? $this->mapper->getQrRespuestaBanco($qrTransaccion) : null;
        $banking          = $this->resolveBanking($factura, $nota, $cuenta, $esQr, $qrTransaccion, $qrRespuestaBanco);
        $clienteDoc       = $this->mapper->resolveClienteDoc($factura);
        $obs              = trim($this->mapper->resolveObservacionesPago($factura, $banking, $nota, $esQr) ?? '');
        $obs             .= ' Nro recibo: ' . $hermano->nro_recibo;

        return [
            'cod_ceta'           => $factura->cod_ceta,
            'cod_pensum'         => $factura->cod_pensum,
            'gestion'            => $gestion,
            'kardex_economico'   => $kardex,
            'num_cuota'          => $numCuota,
            'num_pago'           => $numPago,
            'monto'              => (float) $factura->monto,
            'dias_multa'         => (int) $factura->monto,
            'num_comprobante'    => 0,
            'num_factura'        => $factura->nro_factura ? (int) $factura->nro_factura : 0,
            'fecha_pago'         => $factura->fecha_cobro,
            'pago_completo'      => $this->isPagoCompletoMora($factura),
            'observaciones'      => $obs,
            'usuario'            => 'Reposicion',
            'razon'              => $clienteDoc['cliente'],
            'nro_documento_pago' => $clienteDoc['nro_documento_cobro'],
            'autorizacion'       => '0',
            'valido'             => 'V',
            'concepto'           => $factura->concepto ?: null,
            'codigo_control'     => 'cod_control',
            'codigo_qr'          => null,
            'descuento'          => (float) ($factura->descuento ?? 0),
            'pu_multa'           => $detalle ? (float) $detalle->pu_multa : (float) $factura->pu_mensualidad,
            'code_tipo_pago'     => 'E',
            'fecha_deposito'     => null,
            'nro_cuenta'         => null,
            'nro_deposito'       => null,
            'nro_nota'           => 0,
            'banco_origen'       => null,
            'nro_tarjeta'        => null,
            'estado_factura'     => null,
            'id_item_service'    => 3,
            'orden'              => $this->resolveOrdenMulta($conn, $factura),
            'anulado'            => false,
            'fecha_anulacion'    => null,
            'usuario_anula'      => null,
        ];
    }

    // ─── FIX del recibo hermano en tabla pago ────────────────────────────────

    private function fixReciboPago(
        object $recibo, string $conn, string $destPk,
        string $logFix, string $sourcePkRecibo, BatchReport $report
    ): void {
        [$codCeta, $codPensum, $codInscrip, $kardex, $numCuota, $numPago] = explode('|', $destPk);

        $sgaConn       = DB::connection($conn);
        $newCodInscrip = (int) $codInscrip . '000';
        $newDestPk     = "{$codCeta}|{$codPensum}|{$newCodInscrip}|{$kardex}|{$numCuota}|{$numPago}";

        $original = $sgaConn->table('pago')
            ->where('cod_ceta',         $codCeta)
            ->where('cod_pensum',       $codPensum)
            ->where('cod_inscrip',      (int) $codInscrip)
            ->where('kardex_economico', $kardex)
            ->where('num_cuota',        (int) $numCuota)
            ->where('num_pago',         (int) $numPago)
            ->first();

        // Verificar si la fila +000 ya existe (ejecución anterior interrumpida: SGA
        // completó pero el log MySQL fue revertido → no repetir INSERT, solo limpiar y loguear).
        $newAlreadyExists = $sgaConn->table('pago')
            ->where('cod_ceta',         $codCeta)
            ->where('cod_pensum',       $codPensum)
            ->where('cod_inscrip',      $newCodInscrip)
            ->where('kardex_economico', $kardex)
            ->where('num_cuota',        (int) $numCuota)
            ->where('num_pago',         (int) $numPago)
            ->exists();

        if ($newAlreadyExists) {
            // Ya movido a +000 en SGA: solo limpiar original si sobrevivió y escribir log
            if ($original) {
                $sgaConn->table('pago')
                    ->where('cod_ceta', $codCeta)->where('cod_pensum', $codPensum)
                    ->where('cod_inscrip', (int) $codInscrip)->where('kardex_economico', $kardex)
                    ->where('num_cuota', (int) $numCuota)->where('num_pago', (int) $numPago)
                    ->delete();
            }
            $this->log->write($logFix, $sourcePkRecibo, $conn, 'pago', $newDestPk, 'inserted');
            $report->record('pago_recibo_fix', $conn, 'inserted');
            return;
        }

        if (!$original) {
            $this->warn("fixReciboPago: no se encontró pago original ni +000 para dest_pk={$destPk}");
            return;
        }

        // Reconstruir observaciones limpias solo con el cobro-recibo
        $nota      = $this->mapper->getNotaBancaria($recibo);
        $cuenta    = $this->mapper->getCuentaBancaria($recibo);
        $esQr      = strtoupper($recibo->id_forma_cobro ?? '') === 'B' && $this->mapper->isQrPayment($recibo);
        $qrTx      = $esQr ? $this->mapper->getQrTransaccion($recibo) : null;
        $qrResp    = ($esQr && $qrTx) ? $this->mapper->getQrRespuestaBanco($qrTx) : null;
        $banking   = $this->resolveBanking($recibo, $nota, $cuenta, $esQr, $qrTx, $qrResp);
        $obsLimpia = $this->mapper->resolveObservacionesPago($recibo, $banking, $nota, $esQr);

        // DELETE + INSERT (cod_inscrip es parte de la PK)
        $sgaConn->table('pago')
            ->where('cod_ceta',         $codCeta)
            ->where('cod_pensum',       $codPensum)
            ->where('cod_inscrip',      (int) $codInscrip)
            ->where('kardex_economico', $kardex)
            ->where('num_cuota',        (int) $numCuota)
            ->where('num_pago',         (int) $numPago)
            ->delete();

        $newRow                  = (array) $original;
        $newRow['cod_inscrip']   = $newCodInscrip;
        $newRow['num_factura']   = 0;
        $newRow['observaciones'] = $obsLimpia;

        $sgaConn->table('pago')->insert($newRow);

        $this->log->write($logFix, $sourcePkRecibo, $conn, 'pago', $newDestPk, 'inserted');
        $report->record('pago_recibo_fix', $conn, 'inserted');
    }

    // ─── FIX del recibo hermano en tabla pago_multa ───────────────────────────

    private function fixReciboMulta(
        object $recibo, string $conn, string $destPk,
        string $logFix, string $sourcePkRecibo, BatchReport $report
    ): void {
        [$codCeta, $codPensum, $gestion, $kardex, $numCuota, $numPago] = explode('|', $destPk);

        $sgaConn      = DB::connection($conn);
        $newCodPensum = $codPensum . '000';
        $newDestPk    = "{$codCeta}|{$newCodPensum}|{$gestion}|{$kardex}|{$numCuota}|{$numPago}";

        $original = $sgaConn->table('pago_multa')
            ->where('cod_ceta',         $codCeta)
            ->where('cod_pensum',       $codPensum)
            ->where('gestion',          $gestion)
            ->where('kardex_economico', $kardex)
            ->where('num_cuota',        (int) $numCuota)
            ->where('num_pago',         (int) $numPago)
            ->first();

        $newAlreadyExists = $sgaConn->table('pago_multa')
            ->where('cod_ceta',         $codCeta)
            ->where('cod_pensum',       $newCodPensum)
            ->where('gestion',          $gestion)
            ->where('kardex_economico', $kardex)
            ->where('num_cuota',        (int) $numCuota)
            ->where('num_pago',         (int) $numPago)
            ->exists();

        if ($newAlreadyExists) {
            if ($original) {
                $sgaConn->table('pago_multa')
                    ->where('cod_ceta', $codCeta)->where('cod_pensum', $codPensum)
                    ->where('gestion', $gestion)->where('kardex_economico', $kardex)
                    ->where('num_cuota', (int) $numCuota)->where('num_pago', (int) $numPago)
                    ->delete();
            }
            $this->log->write($logFix, $sourcePkRecibo, $conn, 'pago_multa', $newDestPk, 'inserted');
            $report->record('pago_multa_recibo_fix', $conn, 'inserted');
            return;
        }

        if (!$original) {
            $this->warn("fixReciboMulta: no se encontró pago_multa original ni +000 para dest_pk={$destPk}");
            return;
        }

        $nota      = $this->mapper->getNotaBancaria($recibo);
        $cuenta    = $this->mapper->getCuentaBancaria($recibo);
        $esQr      = strtoupper($recibo->id_forma_cobro ?? '') === 'B' && $this->mapper->isQrPayment($recibo);
        $qrTx      = $esQr ? $this->mapper->getQrTransaccion($recibo) : null;
        $qrResp    = ($esQr && $qrTx) ? $this->mapper->getQrRespuestaBanco($qrTx) : null;
        $banking   = $this->resolveBanking($recibo, $nota, $cuenta, $esQr, $qrTx, $qrResp);
        $obsLimpia = $this->mapper->resolveObservacionesPago($recibo, $banking, $nota, $esQr);

        $sgaConn->table('pago_multa')
            ->where('cod_ceta',         $codCeta)
            ->where('cod_pensum',       $codPensum)
            ->where('gestion',          $gestion)
            ->where('kardex_economico', $kardex)
            ->where('num_cuota',        (int) $numCuota)
            ->where('num_pago',         (int) $numPago)
            ->delete();

        $newRow                  = (array) $original;
        $newRow['cod_pensum']    = $newCodPensum;
        $newRow['num_factura']   = 0;
        $newRow['observaciones'] = $obsLimpia;

        $sgaConn->table('pago_multa')->insert($newRow);

        $this->log->write($logFix, $sourcePkRecibo, $conn, 'pago_multa', $newDestPk, 'inserted');
        $report->record('pago_multa_recibo_fix', $conn, 'inserted');
    }

    // ─── Helpers compartidos ─────────────────────────────────────────────────

    private function resolveBanking(
        object $r, ?object $nota, ?object $cuenta,
        bool $esQr, ?object $qrTx, ?object $qrResp
    ): array {
        if ($esQr) {
            $fechaRaw      = ($qrTx->processed_at ?? null) ?: ($qrResp->fecha_respuesta ?? null);
            $fechaDeposito = $fechaRaw ? Carbon::parse($fechaRaw)->format('Y-m-d') : null;
        } else {
            $fechaDeposito = $nota ? ($nota->fecha_deposito ?: null) : null;
        }

        $nroDeposito = $esQr
            ? (mb_substr($qrResp->numeroordenoriginante ?? '', 0, 50) ?: null)
            : ($nota ? (mb_substr($nota->nro_transaccion ?? '', 0, 35) ?: null) : null);

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

    private function resolveOrdenPago(string $conn, object $r): int
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

    private function resolveOrdenMulta(string $conn, object $r): int
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
        $max      = max($maxPago ?? -1, $maxMulta ?? -1);
        return $max === -1 ? 0 : (int) $max + 1;
    }

    private function resolveTurno(object $r): ?string
    {
        if (!$r->nro_cobro) return null;
        return DB::connection(MapperHelper::SOURCE_CONN)->table('cobros_detalle_regular')
            ->where('nro_cobro', $r->nro_cobro)->value('turno') ?: null;
    }

    private function isPagoCompleto(object $r): bool
    {
        if (empty($r->id_asignacion_costo)) return true;
        $montoAsignado = DB::connection(MapperHelper::SOURCE_CONN)->table('asignacion_costos')
            ->where('id_asignacion_costo', $r->id_asignacion_costo)->value('monto');
        if ($montoAsignado === null) return true;
        $acumulado = DB::connection(MapperHelper::SOURCE_CONN)->table('cobro')
            ->where('id_asignacion_costo', $r->id_asignacion_costo)
            ->where('nro_cobro', '<=', $r->nro_cobro)
            ->selectRaw('COALESCE(SUM(monto), 0) + COALESCE(SUM(descuento), 0) AS total')
            ->value('total');
        return round((float) $acumulado, 2) >= round((float) $montoAsignado, 2);
    }

    private function isPagoCompletoMora(object $r): bool
    {
        if (empty($r->id_asignacion_costo)) return true;
        $montoMora = DB::connection(MapperHelper::SOURCE_CONN)->table('asignacion_mora')
            ->where('id_asignacion_costo', $r->id_asignacion_costo)->value('monto_mora');
        if ($montoMora === null) return true;
        $acumulado = DB::connection(MapperHelper::SOURCE_CONN)->table('cobro')
            ->where('id_asignacion_costo', $r->id_asignacion_costo)
            ->where('nro_cobro', '<=', $r->nro_cobro)
            ->selectRaw('COALESCE(SUM(monto), 0) + COALESCE(SUM(descuento), 0) AS total')
            ->value('total');
        return round((float) $acumulado, 2) >= round((float) $montoMora, 2);
    }

    private function resolveNumCuotaMora(object $r): int
    {
        if (!empty($r->id_asignacion_costo)) {
            $cuota = DB::connection(MapperHelper::SOURCE_CONN)->table('asignacion_costos')
                ->where('id_asignacion_costo', $r->id_asignacion_costo)->value('numero_cuota');
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
}
