<?php

namespace App\Services\SgaMigration;

use Illuminate\Support\Facades\DB;

/**
 * Migra `nota_reposicion` de sistemaEco → SGA.
 *
 * PK en SGA: (correlativo, anio_reposicion, cont).
 * Ruteo por prefijo_carrera (E→sga_elec, M→sga_mec). Casi todas son EEA.
 * El `correlativo` se recalcula MAX+1 por anio_reposicion en el destino; cont=0 (la
 * unicidad la garantiza el correlativo fresco).
 */
class NotaReposicionWriter
{
    public function __construct(
        private MapperHelper $mapper,
        private MigrationLog $log,
    ) {}

    public function run(string $from, string $until, bool $dryRun, BatchReport $report): void
    {
        DB::connection(MapperHelper::SOURCE_CONN)->table('nota_reposicion')
            ->whereBetween('fecha_nota', ["{$from} 00:00:00", "{$until} 23:59:59"])
            ->orderBy('anio_reposicion')->orderBy('correlativo')
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
            $report->record('nota_reposicion', 'sin_ruta', 'skipped');
            return;
        }

        $sourcePk = "{$r->correlativo}|{$r->anio_reposicion}|{$r->cont}";

        if (!$dryRun && $this->log->alreadyDone('nota_reposicion', $sourcePk, $conn)) {
            $report->record('nota_reposicion', $conn, 'skipped');
            return;
        }

        if ($dryRun) {
            $report->record('nota_reposicion', $conn, 'inserted');
            return;
        }

        try {
            $correlativo = $this->mapper->getNextNumPago($conn, 'nota_reposicion', [
                'anio_reposicion' => (int) $r->anio_reposicion,
            ], 'correlativo');

            $row = $this->buildRow($r, $correlativo);
            DB::connection($conn)->table('nota_reposicion')->insert($row);
            $destPk = "{$correlativo}|{$r->anio_reposicion}|0";
            $this->log->write('nota_reposicion', $sourcePk, $conn, 'nota_reposicion', $destPk, 'inserted');
            $report->record('nota_reposicion', $conn, 'inserted');
        } catch (\Throwable $e) {
            $this->log->write('nota_reposicion', $sourcePk, $conn, 'nota_reposicion', null, 'error', $e->getMessage());
            $report->record('nota_reposicion', $conn, 'errors');
        }
    }

    private function buildRow(object $r, int $correlativo): array
    {
        return [
            'correlativo'     => $correlativo,
            'usuario'         => $r->usuario ?: 'SIS_ECO',
            'cod_ceta'        => $r->cod_ceta,
            'monto'           => (float) $r->monto,
            'concepto_adm'    => $r->concepto_adm,
            'fecha_nota'      => $r->fecha_nota,
            'concepto_est'    => $r->concepto_est ?: null,
            'observaciones'   => $r->observaciones ?: null,
            'prefijo_carrera' => $r->prefijo_carrera,
            'anulado'         => (bool) $r->anulado,
            'anio_reposicion' => (int) $r->anio_reposicion,
            'nro_recibo'      => $r->nro_recibo ?: null,
            'tipo_ingreso'    => $r->tipo_ingreso ?: null,
            'cont'            => 0,
        ];
    }
}
