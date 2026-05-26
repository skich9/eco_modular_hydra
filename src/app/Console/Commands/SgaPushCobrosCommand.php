<?php

namespace App\Console\Commands;

use App\Services\SgaMigration\BatchReport;
use App\Services\SgaMigration\FacturaWriter;
use App\Services\SgaMigration\MaterialAdicionalWriter;
use App\Services\SgaMigration\NotaBancariaWriter;
use App\Services\SgaMigration\NotaReposicionWriter;
use App\Services\SgaMigration\OtrosIngresosWriter;
use App\Services\SgaMigration\RecepcionIngresosWriter;
use App\Services\SgaMigration\PagoMultaWriter;
use App\Services\SgaMigration\PagoWriter;
use App\Services\SgaMigration\ReciboWriter;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SgaPushCobrosCommand extends Command
{
    private const DEFAULT_FROM  = '2026-04-23';
    private const DEFAULT_UNTIL = '2026-05-22';

    protected $signature = 'sga:push-cobros
        {--from=   : Fecha inicial Y-m-d (default ' . self::DEFAULT_FROM . ')}
        {--until=  : Fecha final Y-m-d (default ' . self::DEFAULT_UNTIL . ')}
        {--solo=   : Solo esta tabla (factura|recibo|pago|pago_multa|material_adicional|nota_bancaria|nota_reposicion|otros_ingresos|recepcion)}
        {--dry-run : Simula sin escribir nada}';

    protected $description = 'Migra cobros históricos de sistemaEco → SGA por lote. Sin --dry-run escribe en el SGA.';

    public function __construct(
        private FacturaWriter           $facturaWriter,
        private ReciboWriter            $reciboWriter,
        private PagoWriter              $pagoWriter,
        private PagoMultaWriter         $pagoMultaWriter,
        private MaterialAdicionalWriter $materialWriter,
        private NotaBancariaWriter      $notaBancariaWriter,
        private NotaReposicionWriter    $notaReposicionWriter,
        private OtrosIngresosWriter       $otrosIngresosWriter,
        private RecepcionIngresosWriter   $recepcionWriter,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $from  = $this->option('from')
                ? Carbon::parse($this->option('from'))->format('Y-m-d')
                : Carbon::parse($this->ask('Fecha inicial (Y-m-d)', self::DEFAULT_FROM))->format('Y-m-d');

            $until = $this->option('until')
                ? Carbon::parse($this->option('until'))->format('Y-m-d')
                : Carbon::parse($this->ask('Fecha final (Y-m-d)', self::DEFAULT_UNTIL))->format('Y-m-d');
        } catch (\Throwable) {
            $this->error('Fecha inválida. Use formato Y-m-d.');
            return self::FAILURE;
        }

        if ($from > $until) {
            $this->error('--from no puede ser mayor que --until.');
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $solo   = $this->option('solo');

        $mode = $dryRun ? '<comment>[DRY-RUN]</comment>' : '<info>[REAL]</info>';
        $this->line("{$mode} Migración cobros sistemaEco → SGA   rango: {$from} → {$until}");
        if ($solo) $this->line("   Solo tabla: {$solo}");
        $this->newLine();

        if (!$dryRun) {
            if (!$this->confirm("¿Confirmar migración REAL al SGA del rango {$from} → {$until}?", false)) {
                $this->line('Migración cancelada.');
                return self::SUCCESS;
            }
            $this->newLine();
        }

        $report = new BatchReport();

        $this->runTable('factura',            $solo, fn() => $this->facturaWriter->run($from, $until, $dryRun, $report));
        $this->runTable('recibo',             $solo, fn() => $this->reciboWriter->run($from, $until, $dryRun, $report));
        $this->runTable('pago',               $solo, fn() => $this->pagoWriter->run($from, $until, $dryRun, $report));
        $this->runTable('pago_multa',         $solo, fn() => $this->pagoMultaWriter->run($from, $until, $dryRun, $report));
        $this->runTable('material_adicional', $solo, fn() => $this->materialWriter->run($from, $until, $dryRun, $report));
        $this->runTable('nota_bancaria',      $solo, fn() => $this->notaBancariaWriter->run($from, $until, $dryRun, $report));
        $this->runTable('nota_reposicion',    $solo, fn() => $this->notaReposicionWriter->run($from, $until, $dryRun, $report));
        $this->runTable('otros_ingresos',     $solo, fn() => $this->otrosIngresosWriter->run($from, $until, $dryRun, $report));
        $this->runTable('recepcion',          $solo, fn() => $this->recepcionWriter->run($from, $until, $dryRun, $report));

        // Actualizar secuencias (solo corrida real, no dry-run)
        if (!$dryRun) {
            $this->fixSequences($solo);
        }

        $this->printReport($report, $dryRun);

        return $report->totalErrors() === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function runTable(string $table, ?string $solo, \Closure $fn): void
    {
        if ($solo && $solo !== $table) return;
        $this->line("   Procesando <comment>{$table}</comment>...");
        $fn();
    }

    private function fixSequences(?string $solo): void
    {
        $conns = ['sga_elec', 'sga_mec'];
        if (!$solo || $solo === 'factura') {
            foreach ($conns as $conn) {
                try {
                    $this->facturaWriter->fixSequence($conn);
                } catch (\Throwable $e) {
                    $this->warn("   setval factura [{$conn}]: " . $e->getMessage());
                }
            }
        }
        if (!$solo || $solo === 'recibo') {
            foreach ($conns as $conn) {
                try {
                    $this->reciboWriter->fixSequence($conn);
                } catch (\Throwable $e) {
                    $this->warn("   setval recibo [{$conn}]: " . $e->getMessage());
                }
            }
        }
    }

    private function printReport(BatchReport $report, bool $dryRun): void
    {
        $this->newLine();
        $label = $dryRun ? 'Reporte DRY-RUN' : 'Reporte';
        $this->line("<comment>{$label}</comment>");
        $rows = [];
        foreach ($report->rows() as $r) {
            $rows[] = [$r['conn'], $r['table'], $r['inserted'], $r['skipped'], $r['errors']];
        }
        if ($rows) {
            $this->table(['Conexión', 'Tabla', 'Insertados', 'Saltados', 'Errores'], $rows);
        } else {
            $this->line('   (sin tablas procesadas)');
        }

        if ($report->totalErrors() > 0) {
            $this->error('Migración completada CON ERRORES — revisar sga_migration_log.');
        } else {
            $this->info('Migración completada' . ($dryRun ? ' (dry-run, nada fue escrito)' : ' OK.'));
        }
    }
}
