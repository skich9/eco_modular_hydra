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
        {--from=         : Fecha inicial Y-m-d (default ' . self::DEFAULT_FROM . ')}
        {--until=        : Fecha final Y-m-d (default ' . self::DEFAULT_UNTIL . ')}
        {--solo=         : Solo esta tabla (factura|recibo|pago|pago_multa|material_adicional|nota_bancaria|nota_reposicion|otros_ingresos)}
        {--spot-checks=5 : Cantidad de spot-checks aleatorios por tabla/conexión (0 = desactivar)}
        {--export=       : Ruta a archivo JSON para volcar el reporte completo}';

    protected $description = 'Verificación post-migración (read-only): cobertura origen-log-destino, sumas, spot-checks, secuencias y errores.';

    public function handle(VerifyMigrationService $service): int
    {
        try {
            $from  = $this->option('from')  ? Carbon::parse($this->option('from'))->format('Y-m-d')  : self::DEFAULT_FROM;
            $until = $this->option('until') ? Carbon::parse($this->option('until'))->format('Y-m-d') : self::DEFAULT_UNTIL;
        } catch (\Throwable) {
            $this->error('Fecha inválida. Use formato Y-m-d.');
            return self::FAILURE;
        }

        if ($from > $until) {
            $this->error('--from no puede ser mayor que --until.');
            return self::FAILURE;
        }

        $solo        = $this->option('solo');
        $spotChecks  = (int) $this->option('spot-checks');
        $exportPath  = $this->option('export');

        $this->info("Verificación migración cobros sistemaEco → SGA   rango: {$from} → {$until}");
        if ($solo)        $this->line("   Solo tabla: {$solo}");
        if ($spotChecks)  $this->line("   Spot-checks por tabla/conexión: {$spotChecks}");
        $this->newLine();

        $res   = $service->run($from, $until, $solo, $spotChecks);
        $allOk = true;

        // 1. Cobertura origen vs LOG (procesado=inserted+skipped)
        $this->line('<comment>1) Cobertura: origen vs LOG (procesado = inserted + skipped)</comment>');
        $rows = [];
        foreach ($res['cobertura'] as $tabla => $conns) {
            foreach (['sga_elec', 'sga_mec'] as $conn) {
                $c = $conns[$conn];
                $allOk = $allOk && $c['ok'];
                $rows[] = [$tabla, $conn, $c['origen'], $c['procesado'], $c['delta'], $c['ok'] ? 'OK' : 'FALTAN!'];
            }
            if (($conns['sin_ruta'] ?? 0) > 0) {
                $allOk = false;
                $rows[] = [$tabla, 'sin_ruta', $conns['sin_ruta'], '—', '—', 'REVISAR'];
            }
        }
        $this->table(['Tabla', 'Conexión', 'Origen', 'Procesado(log)', 'Delta', 'Estado'], $rows);

        // 1.b Cobertura origen vs DESTINO REAL (count en el SGA)
        $this->line('<comment>1.b) Cobertura: origen vs DESTINO REAL (count en SGA)</comment>');
        $rows = [];
        foreach ($res['destino_real'] as $tabla => $conns) {
            foreach (['sga_elec', 'sga_mec'] as $conn) {
                $c = $conns[$conn];
                if (isset($c['error'])) {
                    $allOk = false;
                    $rows[] = [$tabla, $conn, $c['origen'], '—', '—', '—', 'ERROR: ' . mb_substr($c['error'], 0, 50)];
                    continue;
                }
                $allOk = $allOk && $c['ok'];
                $rows[] = [$tabla, $conn, $c['origen'], $c['destino'], $c['delta'], $c['modo'], $c['ok'] ? 'OK' : 'DIFIERE!'];
            }
        }
        $this->table(['Tabla', 'Conexión', 'Origen', 'Destino(SGA)', 'Delta', 'Modo', 'Estado'], $rows);

        // 2. SUM(monto) origen vs destino
        $this->line('<comment>2) Suma de control SUM(monto) origen vs destino</comment>');
        $rows = [];
        foreach ($res['sumas_monto'] as $tabla => $data) {
            if (!empty($data['na'])) {
                $rows[] = [$tabla, '—', '—', '—', '—', 'N/A: ' . ($data['motivo'] ?? '')];
                continue;
            }
            foreach (['sga_elec', 'sga_mec'] as $conn) {
                $c = $data[$conn];
                if (isset($c['error'])) {
                    $allOk = false;
                    $rows[] = [$tabla, $conn, $c['origen'], '—', '—', 'ERROR: ' . mb_substr($c['error'], 0, 50)];
                    continue;
                }
                $allOk = $allOk && $c['ok'];
                $rows[] = [$tabla, $conn, $c['origen'], $c['destino'], $c['delta'], $c['ok'] ? 'OK' : 'DIFIERE!'];
            }
        }
        $this->table(['Tabla', 'Conexión', 'Suma origen', 'Suma destino', 'Delta', 'Estado'], $rows);

        // 3. Detalle del log por tabla/estado
        $this->line('<comment>3) Log por tabla/estado</comment>');
        $rows = [];
        foreach ($res['log'] as $l) {
            $rows[] = [$l->dest_table, $l->dest_conn, $l->status, $l->total];
        }
        $this->table(['Tabla destino', 'Conexión', 'Estado', 'Total'], $rows ?: [['—', '—', '—', 0]]);

        // 4. Secuencias
        $this->line('<comment>4) Secuencias factura/recibo (last_value >= MAX)</comment>');
        $rows = [];
        foreach ($res['secuencias'] as $s) {
            $allOk = $allOk && $s['ok'];
            $rows[] = [$s['conn'], $s['tabla'], $s['max'], $s['seq'], $s['ok'] ? 'OK' : 'DESFASE!', $s['error'] ?? ''];
        }
        if ($rows) {
            $this->table(['Conexión', 'Tabla', 'MAX', 'Secuencia', 'Estado', 'Error'], $rows);
        } else {
            $this->line('   (sin secuencias aplicables para --solo)');
        }

        // 5. Spot-checks
        $this->line('<comment>5) Spot-checks aleatorios (diff por campo)</comment>');
        if (empty($res['spot_checks'])) {
            $this->line('   (desactivados)');
        } else {
            $rows = [];
            foreach ($res['spot_checks'] as $tabla => $data) {
                if (!empty($data['na'])) {
                    $rows[] = [$tabla, '—', '—', '—', '—', 'N/A: ' . ($data['motivo'] ?? '')];
                    continue;
                }
                foreach (['sga_elec', 'sga_mec'] as $conn) {
                    $samples = $data[$conn] ?? [];
                    $okCount   = 0;
                    $diffCount = 0;
                    foreach ($samples as $s) {
                        if (empty($s['diffs'])) {
                            $okCount++;
                            continue;
                        }
                        $diffCount++;
                        foreach ($s['diffs'] as $field => $vals) {
                            if ($field === '_not_found_in_dest') {
                                $rows[] = [$tabla, $conn, $s['pk'], 'NOT FOUND', '—', '—'];
                            } else {
                                $rows[] = [
                                    $tabla, $conn, $s['pk'], $field,
                                    $this->short($vals['origen']),
                                    $this->short($vals['destino']),
                                ];
                            }
                        }
                    }
                    if ($okCount > 0 && $diffCount === 0 && empty($samples) === false) {
                        // Resumen positivo cuando todas las muestras pasaron
                        $rows[] = [$tabla, $conn, "{$okCount} muestras OK", '—', '—', '—'];
                    }
                    if ($diffCount > 0) {
                        $allOk = false;
                    }
                }
            }
            $this->table(['Tabla', 'Conexión', 'PK', 'Campo', 'Origen', 'Destino'], $rows ?: [['—', '—', '—', '—', '—', '—']]);
        }

        // 6. Errores en log
        $this->line('<comment>6) Errores registrados en el log (últimos 50)</comment>');
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

        // Export JSON
        if ($exportPath) {
            $this->writeExport($exportPath, $res, $from, $until, $solo);
        }

        $this->newLine();
        if ($allOk) {
            $this->info('VERIFICACIÓN OK — cobertura completa, sumas y muestras coinciden, secuencias alineadas, sin errores.');
            return self::SUCCESS;
        }
        $this->error('VERIFICACIÓN CON OBSERVACIONES — revisar deltas / sumas / spot-checks / errores arriba.');
        return self::FAILURE;
    }

    private function short($v): string
    {
        if ($v === null) return 'null';
        if (is_bool($v)) return $v ? 'true' : 'false';
        return mb_substr((string) $v, 0, 40);
    }

    private function writeExport(string $path, array $res, string $from, string $until, ?string $solo): void
    {
        $payload = [
            'generado_en' => now()->toIso8601String(),
            'rango'       => ['from' => $from, 'until' => $until],
            'solo'        => $solo,
            'resultado'   => $res,
        ];
        try {
            $dir = dirname($path);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->line("   Reporte exportado a: <info>{$path}</info>");
        } catch (\Throwable $e) {
            $this->warn("   No se pudo exportar el reporte: " . $e->getMessage());
        }
    }
}
