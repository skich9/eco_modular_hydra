<?php

namespace App\Services\SgaMigration;

use Illuminate\Support\Facades\DB;

/**
 * Migra `recibo` de sistemaEco (eco_backup) → SGA (sga_elec / sga_mec).
 *
 * PK en SGA: (num_recibo, anio).
 * Estrategia de colisión: SKIP.
 * Al terminar, el comando llama a fixSequence() para actualizar el nextval.
 */
class ReciboWriter
{
    public function __construct(
        private MapperHelper $mapper,
        private MigrationLog $log,
    ) {}

    public function run(string $from, string $until, bool $dryRun, BatchReport $report): void
    {
        // Filtrar por fecha_cobro del cobro relacionado, NO por created_at del recibo.
        // Razón: created_at refleja cuándo se sincronizó el registro (ej. 3:45 AM del 23/04),
        // no la fecha real del cobro. El recibo se selecciona si al menos un cobro
        // del rango lo referencia.
        $nrosEnRango = DB::connection(MapperHelper::SOURCE_CONN)->table('cobro')
            ->whereBetween('fecha_cobro', ["{$from} 00:00:00", "{$until} 23:59:59"])
            ->whereNotNull('nro_recibo')
            ->distinct()
            ->pluck('nro_recibo')
            ->all();

        if (empty($nrosEnRango)) {
            return;
        }

        foreach (array_chunk($nrosEnRango, 500) as $lote) {
            DB::connection(MapperHelper::SOURCE_CONN)->table('recibo')
                ->whereIn('nro_recibo', $lote)
                ->orderBy('nro_recibo')
                ->chunk(200, function ($rows) use ($dryRun, $report) {
                    foreach ($rows as $r) {
                        $this->processOne($r, $dryRun, $report);
                    }
                });
        }
    }

    private function processOne(object $r, bool $dryRun, BatchReport $report): void
    {
        $conn = $this->mapper->resolveConnByCodCeta($r->cod_ceta);
        if (!$conn) {
            $report->record('recibo', 'sin_ruta', 'skipped');
            return;
        }

        $sourcePk = "{$r->nro_recibo}|{$r->anio}";

        if (!$dryRun && $this->log->alreadyDone('recibo', $sourcePk, $conn)) {
            $report->record('recibo', $conn, 'skipped');
            return;
        }

        $exists = DB::connection($conn)->table('recibo')
            ->where('num_recibo', (int) $r->nro_recibo)
            ->where('anio', (int) $r->anio)
            ->exists();

        if ($exists) {
            if (!$dryRun) {
                $this->log->write('recibo', $sourcePk, $conn, 'recibo', $sourcePk, 'skipped');
            }
            $report->record('recibo', $conn, 'skipped');
            return;
        }

        if ($dryRun) {
            $report->record('recibo', $conn, 'inserted');
            return;
        }

        try {
            DB::connection($conn)->table('recibo')->insert($this->buildRow($r));
            $this->log->write('recibo', $sourcePk, $conn, 'recibo', $sourcePk, 'inserted');
            $report->record('recibo', $conn, 'inserted');
        } catch (\Throwable $e) {
            $this->log->write('recibo', $sourcePk, $conn, 'recibo', null, 'error', $e->getMessage());
            $report->record('recibo', $conn, 'errors');
        }
    }

    private function buildRow(object $r): array
    {
        return [
            'num_recibo'             => (int) $r->nro_recibo,
            'anio'                   => (int) $r->anio,
            'usuario'                => $this->mapper->resolveUsuarioNickname($r->id_usuario),
            'descuento_adicional'    => 0,
            'codigo_metodo_pago'     => $this->mapper->mapMetodoPago($r->id_forma_cobro),
            'complemento'            => $r->complemento ?: null,
            'cod_tipo_doc_identidad' => $r->cod_tipo_doc_identidad ? (int) $r->cod_tipo_doc_identidad : 1,
            'monto_gift_card'        => 0,
            'num_gift_card'          => null,
            'tipo_emision'           => $r->tipo_emision ? (int) $r->tipo_emision : null,
            'codigo_excepcion'       => $r->codigo_excepcion ? (int) $r->codigo_excepcion : null,
            'codigo_doc_sector'      => $r->codigo_doc_sector ? (int) $r->codigo_doc_sector : null,
            'tiene_reposicion'       => (bool) $r->tiene_reposicion,
            'periodo_facturado'      => $r->periodo_facturado ?: null,
        ];
    }

    public function fixSequence(string $conn): void
    {
        DB::connection($conn)->statement(
            "SELECT setval('recibo_num_recibo_seq', (SELECT COALESCE(MAX(num_recibo), 0) FROM recibo))"
        );
    }
}
