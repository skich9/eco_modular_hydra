<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Revierte los registros insertados en el SGA por sga:push-cobros.
 *
 * Usa sga_migration_log (status='inserted') como fuente de verdad:
 * cada entrada contiene dest_conn, dest_table y dest_pk suficientes
 * para localizar y borrar exactamente la fila en el SGA.
 * Tras borrar la fila en SGA, elimina la entrada del log para que
 * sga:push-cobros pueda reinsertar los datos corregidos.
 *
 * Usa --from/--until para filtrar por pushed_at y acotar solo la
 * corrida que se quiere revertir (evita tocar corridas anteriores).
 */
class SgaRollbackCobrosCommand extends Command
{
    protected $signature = 'sga:rollback-cobros
        {--from=   : Filtrar por pushed_at desde (Y-m-d). Sin valor: todas las entradas del log.}
        {--until=  : Filtrar por pushed_at hasta (Y-m-d). Sin valor: todas las entradas del log.}
        {--solo=   : Solo esta tabla (factura|recibo|pago|pago_multa|material_adicional|nota_bancaria|nota_reposicion|otros_ingresos|recepcion)}
        {--dry-run : Muestra cuántos registros se borrarían sin ejecutar nada}';

    protected $description = 'Revierte registros insertados en SGA por sga:push-cobros. Borra filas del SGA y limpia sga_migration_log para permitir re-push.';

    /**
     * Mapeo tabla → columnas del PK en SGA (en el mismo orden que dest_pk del log).
     * Orden idéntico al de sga:push-cobros para consistencia visual.
     * Para otros_ingresos se indica 'cascade' = tabla hija a borrar primero.
     */
    private const TABLE_CONFIG = [
        'factura' => [
            'pk' => ['num_factura', 'anio', 'es_manual'],
        ],
        'recibo' => [
            'pk' => ['num_recibo', 'anio'],
        ],
        'pago' => [
            'pk' => ['cod_ceta', 'cod_pensum', 'cod_inscrip', 'kardex_economico', 'num_cuota', 'num_pago'],
        ],
        'pago_multa' => [
            'pk' => ['cod_ceta', 'cod_pensum', 'gestion', 'kardex_economico', 'num_cuota', 'num_pago'],
        ],
        'material_adicional' => [
            'pk' => ['cod_ceta', 'cod_pensum', 'cod_inscrip', 'num_comprobante', 'num_pago_mat'],
        ],
        'nota_bancaria' => [
            'pk' => ['anio_deposito', 'correlativo', 'tipo_nota'],
        ],
        'nota_reposicion' => [
            'pk' => ['correlativo', 'anio_reposicion', 'cont'],
        ],
        'otros_ingresos' => [
            // dest_pk almacena el valor de nit; en SGA la columna se llama nro_documento_pago
            'pk'      => ['num_factura', 'num_recibo', 'nro_documento_pago', 'fecha'],
            'cascade' => 'otros_ingresos_detalle',
        ],
        'recepcion' => [
            'pk'      => ['id_recepcion'],
            'cascade' => 'detalle_recepcion',
        ],
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $solo   = $this->option('solo');

        // Parsear fechas opcionales (filtran por pushed_at en el log)
        try {
            $from  = $this->option('from')  ? Carbon::parse($this->option('from'))->format('Y-m-d')  : null;
            $until = $this->option('until') ? Carbon::parse($this->option('until'))->format('Y-m-d') : null;
        } catch (\Throwable) {
            $this->error('Fecha inválida. Use formato Y-m-d.');
            return self::FAILURE;
        }

        // Validar --solo
        if ($solo && !array_key_exists($solo, self::TABLE_CONFIG)) {
            $this->error("Tabla '{$solo}' no reconocida. Opciones: " . implode('|', array_keys(self::TABLE_CONFIG)));
            return self::FAILURE;
        }

        $tables = $solo ? [$solo] : array_keys(self::TABLE_CONFIG);
        $mode   = $dryRun ? '<comment>[DRY-RUN]</comment>' : '<fg=red>[ROLLBACK]</>';

        $rangoLabel = ($from || $until)
            ? '   pushed_at: ' . ($from ?? '∞') . ' → ' . ($until ?? '∞')
            : '   pushed_at: <comment>sin filtro (todas las entradas del log)</comment>';

        $this->line("{$mode} Tablas en scope: <comment>" . implode(', ', $tables) . '</comment>');
        $this->line($rangoLabel);
        $this->newLine();

        if (!$dryRun) {
            if (!$this->confirm('¿Confirmar BORRADO de registros insertados en SGA? Esta acción no se puede deshacer.', false)) {
                $this->line('Rollback cancelado.');
                return self::SUCCESS;
            }
            $this->newLine();
        }

        // Pre-inicializar totals en orden fijo: sga_elec luego sga_mec, por cada tabla en scope
        $totals = [];
        foreach ($tables as $table) {
            foreach (['sga_elec', 'sga_mec'] as $conn) {
                $totals["{$conn}|{$table}"] = ['conn' => $conn, 'table' => $table, 'deleted' => 0, 'errors' => 0];
            }
        }

        foreach ($tables as $table) {
            $this->line("   Procesando <comment>{$table}</comment>...");
            $this->rollbackTable($table, $from, $until, $dryRun, $totals);
        }

        $this->printReport($totals, $dryRun);

        $hasErrors = collect($totals)->sum('errors') > 0;
        return $hasErrors ? self::FAILURE : self::SUCCESS;
    }

    private function rollbackTable(string $table, ?string $from, ?string $until, bool $dryRun, array &$totals): void
    {
        $config  = self::TABLE_CONFIG[$table];
        $pkCols  = $config['pk'];
        $cascade = $config['cascade'] ?? null;

        $q = DB::table('sga_migration_log')
            ->where('dest_table', $table)
            ->where('status', 'inserted')
            ->whereNotNull('dest_pk');

        if ($from)  $q->where('pushed_at', '>=', "{$from} 00:00:00");
        if ($until) $q->where('pushed_at', '<=', "{$until} 23:59:59");

        // chunkById usa WHERE id > last_id en lugar de OFFSET, lo que es seguro
        // cuando se borran entradas dentro del loop (chunk con OFFSET saltaría registros).
        $q->chunkById(200, function ($entries) use ($table, $pkCols, $cascade, $dryRun, &$totals) {
                foreach ($entries as $entry) {
                    $key = "{$entry->dest_conn}|{$table}";

                    if ($dryRun) {
                        $totals[$key]['deleted']++;
                        continue;
                    }

                    try {
                        $where = $this->parsePk($entry->dest_pk, $pkCols);

                        // Borrar detalle antes que el header (FK)
                        if ($cascade) {
                            DB::connection($entry->dest_conn)->table($cascade)->where($where)->delete();
                        }

                        DB::connection($entry->dest_conn)->table($table)->where($where)->delete();

                        // Limpiar log para permitir re-push
                        DB::table('sga_migration_log')->where('id', $entry->id)->delete();

                        $totals[$key]['deleted']++;
                    } catch (\Throwable $e) {
                        $totals[$key]['errors']++;
                        $this->warn("   Error [{$entry->dest_conn}] id={$entry->id} pk={$entry->dest_pk}: " . $e->getMessage());
                    }
                }
            });
    }

    /**
     * Convierte "val1|val2|val3" + ['col1','col2','col3'] → ['col1'=>'val1', 'col2'=>'val2', ...]
     */
    private function parsePk(string $destPk, array $columns): array
    {
        $values = explode('|', $destPk);
        if (count($values) !== count($columns)) {
            throw new \RuntimeException(
                "dest_pk '{$destPk}' tiene " . count($values) . " partes, se esperaban " . count($columns)
            );
        }
        return array_combine($columns, $values);
    }

    private function printReport(array $totals, bool $dryRun): void
    {
        $this->newLine();
        $label = $dryRun ? 'Reporte DRY-RUN' : 'Resumen';
        $this->line("<comment>{$label}</comment>");

        // Filtrar combinaciones sin actividad
        $active = array_filter($totals, fn($r) => $r['deleted'] > 0 || $r['errors'] > 0);

        if (empty($active)) {
            $this->line('   (sin registros encontrados en sga_migration_log con status=inserted para el rango indicado)');
            return;
        }

        $rows = [];
        foreach ($active as $r) {
            $rows[] = [$r['conn'], $r['table'], $r['deleted'], $r['errors']];
        }
        $this->table(['Conexión', 'Tabla', $dryRun ? 'A borrar' : 'Borrados', 'Errores'], $rows);

        $totalErrors = collect($totals)->sum('errors');
        if ($totalErrors > 0) {
            $this->error('Rollback completado CON ERRORES — revisar mensajes arriba.');
        } else {
            $msg = $dryRun
                ? 'Dry-run OK. Ejecuta sin --dry-run para aplicar el rollback.'
                : 'Rollback completado OK. Ahora puedes re-ejecutar sga:push-cobros.';
            $this->info($msg);
        }
    }
}
