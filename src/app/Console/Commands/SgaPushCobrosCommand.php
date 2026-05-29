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
use App\Services\SgaMigration\MapperHelper;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SgaPushCobrosCommand extends Command
{
    private const DEFAULT_FROM  = '2026-04-23';
    private const DEFAULT_UNTIL = '2026-05-22';

    private const TABLAS_CONCATENADAS = ['nota_bancaria', 'nota_reposicion'];

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
        // nota_bancaria y nota_reposicion deben correr ANTES que pago/pago_multa/material_adicional
        // para que resolveNroNotaSga() encuentre el correlativo ya insertado en el SGA.
        $this->runTable('nota_bancaria',      $solo, fn() => $this->notaBancariaWriter->run($from, $until, $dryRun, $report));
        $this->runTable('nota_reposicion',    $solo, fn() => $this->notaReposicionWriter->run($from, $until, $dryRun, $report));
        $this->runTable('pago',               $solo, fn() => $this->pagoWriter->run($from, $until, $dryRun, $report));
        $this->runTable('pago_multa',         $solo, fn() => $this->pagoMultaWriter->run($from, $until, $dryRun, $report));
        $this->runTable('material_adicional', $solo, fn() => $this->materialWriter->run($from, $until, $dryRun, $report));
        $this->runTable('otros_ingresos',     $solo, fn() => $this->otrosIngresosWriter->run($from, $until, $dryRun, $report));
        $this->runTable('recepcion',          $solo, fn() => $this->recepcionWriter->run($from, $until, $dryRun, $report));

        // Actualizar secuencias (solo corrida real, no dry-run)
        if (!$dryRun) {
            $this->fixSequences($solo);
        }

        $this->printReport($report, $dryRun, $from, $until, $solo);

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

    private function printReport(BatchReport $report, bool $dryRun, string $from, string $until, ?string $solo): void
    {
        $this->newLine();
        $label = $dryRun ? 'Reporte DRY-RUN' : 'Reporte';
        $this->line("<comment>{$label}</comment>");

        // Pivotear por tabla: una fila por tabla con columnas EEA / MEA
        $byTable = [];
        foreach ($report->rows() as $r) {
            $t = $r['table'];
            if (!isset($byTable[$t])) {
                $byTable[$t] = ['eea_ins' => 0, 'mea_ins' => 0, 'saltados' => 0, 'errores' => 0];
            }
            if ($r['conn'] === 'sga_elec') $byTable[$t]['eea_ins']  += $r['inserted'];
            if ($r['conn'] === 'sga_mec')  $byTable[$t]['mea_ins']  += $r['inserted'];
            $byTable[$t]['saltados'] += $r['skipped'];
            $byTable[$t]['errores']  += $r['errors'];
        }

        if (empty($byTable)) {
            $this->line('   (sin tablas procesadas)');
        } else {
            $source   = $this->getSourceCounts($from, $until, $solo);
            $headers  = ['Tabla', 'Origen eco', 'EEA ins', 'MEA ins', 'Saltados', 'Errores'];

            $buildRow = fn(string $tabla, array $d) => [
                $tabla,
                number_format($source[$tabla] ?? 0),
                number_format($d['eea_ins']),
                number_format($d['mea_ins']),
                $d['saltados'] > 0 ? number_format($d['saltados']) : '—',
                $d['errores']  > 0 ? number_format($d['errores'])  : '—',
            ];

            // Tabla principal (todas menos las concatenadas)
            $rowsPrincipal = [];
            foreach ($byTable as $tabla => $d) {
                if (!in_array($tabla, self::TABLAS_CONCATENADAS)) {
                    $rowsPrincipal[] = $buildRow($tabla, $d);
                }
            }
            if ($rowsPrincipal) {
                $this->table($headers, $rowsPrincipal);
            }

            // Tabla secundaria: nota_bancaria y nota_reposicion
            $rowsConcat = [];
            foreach (self::TABLAS_CONCATENADAS as $tabla) {
                if (isset($byTable[$tabla])) {
                    $rowsConcat[] = $buildRow($tabla, $byTable[$tabla]);
                }
            }
            if ($rowsConcat) {
                $this->newLine();
                $this->line('<comment>Tablas con registros concatenados (*)</comment>');
                $this->table($headers, $rowsConcat);
                $this->warn('(*) Origen eco > EEA ins + MEA ins es esperado: varias filas de eco_prod_backup');
                $this->warn('    (sistemaEco) se concatenan en un solo registro del SGA. No es un error.');
            }
        }

        if ($report->totalErrors() > 0) {
            $this->error('Migración completada CON ERRORES — revisar sga_migration_log.');
        } else {
            $this->info('Migración completada' . ($dryRun ? ' (dry-run, nada fue escrito)' : ' OK.'));
        }
    }

    /**
     * Cuenta cuántos registros hay en eco_prod_backup para cada tabla en el rango dado.
     * Solo lectura — no escribe nada.
     */
    private function getSourceCounts(string $from, string $until, ?string $solo): array
    {
        $src   = MapperHelper::SOURCE_CONN;
        $from0 = $from . ' 00:00:00';
        $until9 = $until . ' 23:59:59';
        $counts = [];

        $run = fn(string $table) => !$solo || $solo === $table;

        try {
            if ($run('factura')) {
                $counts['factura'] = DB::connection($src)->table('factura')
                    ->whereBetween('fecha_emision', [$from0, $until9])->count();
            }
            if ($run('recibo')) {
                $nros = DB::connection($src)->table('cobro')
                    ->whereBetween('fecha_cobro', [$from0, $until9])
                    ->whereNotNull('nro_recibo')->distinct()->pluck('nro_recibo');
                $counts['recibo'] = DB::connection($src)->table('recibo')
                    ->whereIn('nro_recibo', $nros)->count();
            }
            if ($run('pago')) {
                $counts['pago'] = DB::connection($src)->table('cobro')
                    ->whereIn('cod_tipo_cobro', ['MENSUALIDAD', 'ARRASTRE'])
                    ->whereBetween('fecha_cobro', [$from0, $until9])
                    ->whereNotNull('cod_inscrip')->count();
            }
            if ($run('pago_multa')) {
                $counts['pago_multa'] = DB::connection($src)->table('cobro')
                    ->whereIn('cod_tipo_cobro', ['MORA', 'NIVELACION'])
                    ->whereBetween('fecha_cobro', [$from0, $until9])
                    ->whereNotNull('cod_inscrip')->count();
            }
            if ($run('material_adicional')) {
                $counts['material_adicional'] = DB::connection($src)->table('cobro')
                    ->where('cod_tipo_cobro', 'MATERIAL_EXTRA')
                    ->whereBetween('fecha_cobro', [$from0, $until9])
                    ->whereNotNull('cod_inscrip')->count();
            }
            if ($run('nota_bancaria')) {
                $counts['nota_bancaria'] = DB::connection($src)->table('nota_bancaria')
                    ->whereBetween('fecha_nota', [$from0, $until9])->count();
            }
            if ($run('nota_reposicion')) {
                $counts['nota_reposicion'] = DB::connection($src)->table('nota_reposicion')
                    ->whereBetween('fecha_nota', [$from0, $until9])->count();
            }
            if ($run('otros_ingresos')) {
                $counts['otros_ingresos'] = DB::connection($src)->table('otros_ingresos')
                    ->whereBetween('fecha', [$from0, $until9])->count();
            }
            if ($run('recepcion')) {
                $counts['recepcion'] = DB::connection($src)->table('recepcion_ingresos')
                    ->whereBetween('fecha_recepcion', [$from, $until])->count();
            }
        } catch (\Throwable) {
            // Si falla alguna consulta de origen, se muestra 0 pero no se interrumpe el reporte
        }

        return $counts;
    }
}
