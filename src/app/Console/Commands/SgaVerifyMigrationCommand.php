<?php

namespace App\Console\Commands;

use App\Services\SgaMigration\VerifyMigrationService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SgaVerifyMigrationCommand extends Command
{
    private const DEFAULT_FROM  = '2026-04-23';
    private const DEFAULT_UNTIL = '2026-05-22';

    protected $signature = 'sga:verify-migration
        {--from=  : Fecha inicial Y-m-d (default ' . self::DEFAULT_FROM . ')}
        {--until= : Fecha final Y-m-d (default ' . self::DEFAULT_UNTIL . ')}';

    protected $description = 'Verificación post-migración (read-only): cobertura origen vs log, secuencias y errores.';

    public function handle(VerifyMigrationService $service): int
    {
        try {
            $from  = $this->option('from')  ? Carbon::parse($this->option('from'))->format('Y-m-d')  : self::DEFAULT_FROM;
            $until = $this->option('until') ? Carbon::parse($this->option('until'))->format('Y-m-d') : self::DEFAULT_UNTIL;
        } catch (\Throwable) {
            $this->error('Fecha inválida. Use formato Y-m-d.');
            return self::FAILURE;
        }

        $this->info("Verificación migración cobros sistemaEco → SGA   rango: {$from} → {$until}");
        $this->newLine();

        $res = $service->run($from, $until);
        $allOk = true;

        // 1. Cobertura: origen vs procesado (insertado+saltado)
        $this->line('<comment>1) Cobertura: origen (ruteado) vs procesado en log</comment>');
        $rows = [];
        foreach ($res['cobertura'] as $tabla => $conns) {
            foreach (['sga_elec', 'sga_mec'] as $conn) {
                $c = $conns[$conn];
                $allOk = $allOk && $c['ok'];
                $rows[] = [$tabla, $conn, $c['origen'], $c['procesado'], $c['delta'], $c['ok'] ? 'OK' : 'FALTAN!'];
            }
            if (($conns['sin_ruta'] ?? 0) > 0) {
                $rows[] = [$tabla, 'sin_ruta', $conns['sin_ruta'], '—', '—', 'revisar'];
            }
        }
        $this->table(['Tabla', 'Conexión', 'Origen', 'Procesado', 'Delta', 'Estado'], $rows);

        // 2. Detalle del log por tabla/estado
        $this->line('<comment>2) Log por tabla/estado</comment>');
        $rows = [];
        foreach ($res['log'] as $l) {
            $rows[] = [$l->dest_table, $l->dest_conn, $l->status, $l->total];
        }
        $this->table(['Tabla destino', 'Conexión', 'Estado', 'Total'], $rows ?: [['—', '—', '—', 0]]);

        // 3. Secuencias
        $this->line('<comment>3) Secuencias factura/recibo (last_value >= MAX)</comment>');
        $rows = [];
        foreach ($res['secuencias'] as $s) {
            $allOk = $allOk && $s['ok'];
            $rows[] = [$s['conn'], $s['tabla'], $s['max'], $s['seq'], $s['ok'] ? 'OK' : 'DESFASE!', $s['error'] ?? ''];
        }
        $this->table(['Conexión', 'Tabla', 'MAX', 'Secuencia', 'Estado', 'Error'], $rows);

        // 4. Errores en log
        $this->line('<comment>4) Errores registrados en el log</comment>');
        if (empty($res['errores'])) {
            $this->line('   Sin errores.');
        } else {
            $allOk = false;
            $rows = [];
            foreach ($res['errores'] as $e) {
                $rows[] = [$e->dest_table, $e->dest_conn, $e->source_pk, mb_substr($e->error_message ?? '', 0, 80)];
            }
            $this->table(['Tabla', 'Conexión', 'Source PK', 'Error (80c)'], $rows);
        }

        $this->newLine();
        if ($allOk) {
            $this->info('VERIFICACIÓN OK — cobertura completa, secuencias alineadas, sin errores.');
            return self::SUCCESS;
        }
        $this->error('VERIFICACIÓN CON OBSERVACIONES — revisar deltas/secuencias/errores arriba.');
        return self::FAILURE;
    }
}
