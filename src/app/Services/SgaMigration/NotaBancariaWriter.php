<?php

namespace App\Services\SgaMigration;

use Illuminate\Support\Facades\DB;

/**
 * Migra `nota_bancaria` de sistemaEco → SGA.
 *
 * PK en SGA: (anio_deposito, correlativo, tipo_nota).
 * Ruteo por prefijo_carrera (E→sga_elec, M→sga_mec).
 * El `correlativo` NO se reusa: se recalcula MAX(correlativo)+1 por (anio_deposito, tipo_nota)
 * en el destino para no chocar con notas preexistentes del SGA.
 */
class NotaBancariaWriter
{
    public function __construct(
        private MapperHelper $mapper,
        private MigrationLog $log,
    ) {}

    public function run(string $from, string $until, bool $dryRun, BatchReport $report): void
    {
        DB::connection(MapperHelper::SOURCE_CONN)->table('nota_bancaria')
            ->whereBetween('fecha_nota', ["{$from} 00:00:00", "{$until} 23:59:59"])
            ->orderBy('anio_deposito')->orderBy('correlativo')
            ->chunk(200, function ($rows) use ($dryRun, $report) {
                foreach ($rows as $r) {
                    $this->processOne($r, $dryRun, $report);
                }
            });
    }

    private function processOne(object $r, bool $dryRun, BatchReport $report): void
    {
        $conn = $this->mapper->resolveConnectionByPrefijo($r->prefijo_carrera);
        if (!$conn) {
            $report->record('nota_bancaria', 'sin_ruta', 'skipped');
            return;
        }

        $sourcePk = "{$r->anio_deposito}|{$r->correlativo}|{$r->tipo_nota}";

        if (!$dryRun && $this->log->alreadyDone('nota_bancaria', $sourcePk, $conn)) {
            $report->record('nota_bancaria', $conn, 'skipped');
            return;
        }

        if ($dryRun) {
            $report->record('nota_bancaria', $conn, 'inserted');
            return;
        }

        try {
            $correlativo = $this->mapper->getNextNumPago($conn, 'nota_bancaria', [
                'anio_deposito' => (int) $r->anio_deposito,
                'tipo_nota'     => $r->tipo_nota,
            ], 'correlativo');

            $row = $this->buildRow($r, $correlativo);
            DB::connection($conn)->table('nota_bancaria')->insert($row);
            $destPk = "{$r->anio_deposito}|{$correlativo}|{$r->tipo_nota}";
            $this->log->write('nota_bancaria', $sourcePk, $conn, 'nota_bancaria', $destPk, 'inserted');
            $report->record('nota_bancaria', $conn, 'inserted');
        } catch (\Throwable $e) {
            $this->log->write('nota_bancaria', $sourcePk, $conn, 'nota_bancaria', null, 'error', $e->getMessage());
            $report->record('nota_bancaria', $conn, 'errors');
        }
    }

    private function buildRow(object $r, int $correlativo): array
    {
        return [
            'anio_deposito'   => (int) $r->anio_deposito,
            'correlativo'     => $correlativo,
            'usuario'         => $r->usuario ?: 'SIS_ECO',
            'fecha_nota'      => $r->fecha_nota,
            'cod_ceta'        => $r->cod_ceta,
            'monto'           => (float) $r->monto,
            'concepto'        => $r->concepto ?: null,
            'nro_factura'     => (string) ($r->nro_factura ?? ''),
            'nro_recibo'      => (string) ($r->nro_recibo ?? ''),
            'banco'           => $r->banco ?: null,
            'fecha_deposito'  => $r->fecha_deposito ?: null,
            'nro_transaccion' => $r->nro_transaccion ?: null,
            'prefijo_carrera' => $r->prefijo_carrera ?: null,
            'concepto_est'    => $r->concepto_est ?: null,
            'observacion'     => $r->observacion ?: null,
            'anulado'         => (bool) $r->anulado,
            'tipo_nota'       => $r->tipo_nota,
            'banco_origen'    => $r->banco_origen ?: null,
            'nro_tarjeta'     => $r->nro_tarjeta ?: null,
        ];
    }
}
