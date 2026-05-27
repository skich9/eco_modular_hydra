<?php

namespace App\Console\Commands;

use App\Services\SgaMigration\MapperHelper;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Verificación propia de sga:push-cobros — misma estructura que sga:verify-migration
 * pero adaptada: recibo usa fecha_cobro, incluye recepcion, marca tablas concatenadas.
 * No escribe nada.
 */
class SgaVerifyPushCobrosCommand extends Command
{
    private const DEFAULT_FROM  = '2026-04-23';
    private const DEFAULT_UNTIL = '2026-05-22';

    private const TABLAS_CONCATENADAS = ['nota_bancaria', 'nota_reposicion'];

    private const LOG_SOURCE_TABLE = [
        'factura'            => 'factura',
        'recibo'             => 'recibo',
        'pago'               => 'cobro_pago',
        'pago_multa'         => 'cobro_pago_multa',
        'material_adicional' => 'cobro_material',
        'nota_bancaria'      => 'nota_bancaria',
        'nota_reposicion'    => 'nota_reposicion',
        'otros_ingresos'     => 'otros_ingresos',
        'recepcion'          => 'recepcion_ingresos',
    ];

    // Columna de fecha en la tabla destino del SGA (null = sin fecha → pk-via-log)
    private const DEST_DATE_COL = [
        'factura'            => 'fecha_factura',
        'recibo'             => null,
        'pago'               => 'fecha_pago',
        'pago_multa'         => 'fecha_pago',
        'material_adicional' => 'fecha_pago',
        'nota_bancaria'      => 'fecha_nota',
        'nota_reposicion'    => 'fecha_nota',
        'otros_ingresos'     => 'fecha',
        'recepcion'          => 'fecha_recepcion',
    ];

    // Columnas de monto [origen, destino] (null = N/A)
    private const MONTO_COLS = [
        'factura'            => null,
        'recibo'             => null,
        'pago'               => ['monto',   'monto'],
        'pago_multa'         => ['monto',   'monto'],
        'material_adicional' => ['monto',   'costo_total'],
        'nota_bancaria'      => ['monto',   'monto'],
        'nota_reposicion'    => ['monto',   'monto'],
        'otros_ingresos'     => ['monto',   'monto'],
        'recepcion'          => ['monto_total', 'monto_total'],
    ];

    protected $signature = 'sga:verify-push-cobros
        {--from=  : Fecha inicial Y-m-d (default ' . self::DEFAULT_FROM . ')}
        {--until= : Fecha final Y-m-d (default ' . self::DEFAULT_UNTIL . ')}
        {--solo=  : Solo esta tabla}';

    protected $description = 'Verificación post-migración sga:push-cobros: cobertura, destino real, sumas, secuencias, errores. Solo lectura.';

    private MapperHelper $mapper;

    public function handle(MapperHelper $mapper): int
    {
        $this->mapper = $mapper;

        try {
            $from  = Carbon::parse($this->option('from')  ?: self::DEFAULT_FROM)->format('Y-m-d');
            $until = Carbon::parse($this->option('until') ?: self::DEFAULT_UNTIL)->format('Y-m-d');
        } catch (\Throwable) {
            $this->error('Fecha inválida. Use formato Y-m-d.');
            return self::FAILURE;
        }

        $solo   = $this->option('solo');
        $tablas = $solo ? [$solo] : array_keys(self::LOG_SOURCE_TABLE);
        $f      = $from  . ' 00:00:00';
        $u      = $until . ' 23:59:59';

        $this->info("Verificación push-cobros   rango: {$from} → {$until}");
        if ($solo) $this->line("   Solo tabla: {$solo}");
        $this->newLine();

        $allOk  = true;
        $origen = $this->getOrigenPorConn($tablas, $f, $u, $from, $until);
        $logCnt = $this->getLogCounts($tablas, $f, $u);

        // 1. Cobertura origen vs LOG
        $this->line('<comment>1) Cobertura: origen vs LOG (procesado = inserted + skipped + excluded)</comment>');
        $allOk = $this->printCobertura($tablas, $origen, $logCnt) && $allOk;

        // 1.b Cobertura origen vs DESTINO REAL
        $this->newLine();
        $this->line('<comment>1.b) Cobertura: origen vs DESTINO REAL (pk-via-log)</comment>');
        $allOk = $this->printDestinoReal($tablas, $origen, $f, $u) && $allOk;

        // 2. Suma de control SUM(monto)
        $this->newLine();
        $this->line('<comment>2) Suma de control SUM(monto) origen vs destino</comment>');
        $allOk = $this->printSumasMonto($tablas, $f, $u) && $allOk;

        // 3. Log por tabla/estado
        $this->newLine();
        $this->line('<comment>3) Log por tabla/estado</comment>');
        $this->printLogDetalle($tablas);

        // 4. Secuencias factura/recibo
        $this->newLine();
        $this->line('<comment>4) Secuencias factura/recibo (last_value >= MAX)</comment>');
        $allOk = $this->printSecuencias($solo) && $allOk;

        // 5. Detalle recepcion (detalle_recepcion vs recepcion_ingreso_detalles)
        $this->newLine();
        $this->line('<comment>5) Cobertura detalle_recepcion (hijo de recepcion)</comment>');
        $allOk = $this->printDetalleRecepcion($f, $u) && $allOk;

        // 6. Errores en log
        $this->newLine();
        $this->line('<comment>6) Errores registrados en el log (últimos 50)</comment>');
        $allOk = $this->printErrores($tablas) && $allOk;

        $this->newLine();
        if ($allOk) {
            $this->info('VERIFICACIÓN OK — cobertura completa, sumas y secuencias alineadas, sin errores.');
            return self::SUCCESS;
        }
        $this->error('VERIFICACIÓN CON OBSERVACIONES — revisar deltas / sumas / secuencias / errores arriba.');
        return self::FAILURE;
    }

    // ─────────────────────────────────────────────
    // 1. Cobertura vs LOG
    // ─────────────────────────────────────────────
    private function printCobertura(array $tablas, array $origen, array $logCnt): bool
    {
        $allOk = true;
        $rows  = [];

        foreach ($tablas as $tabla) {
            $esConcatenada = in_array($tabla, self::TABLAS_CONCATENADAS);
            foreach (['sga_elec', 'sga_mec'] as $conn) {
                $o    = $origen[$tabla][$conn]['origen'] ?? 0;
                $proc = $logCnt[$tabla][$conn]['_procesado'] ?? 0;
                $delta = $o - $proc;
                // Para tablas concatenadas el delta esperado > 0
                $ok = $esConcatenada ? true : ($delta === 0);
                if (!$ok) $allOk = false;
                $estado = $esConcatenada ? 'CONCAT(*)' : ($ok ? 'OK' : 'FALTAN!');
                $rows[] = [$tabla, $conn, $o, $proc, $delta, $estado];
            }
            $sr = $origen[$tabla]['sin_ruta'] ?? 0;
            if ($sr > 0) {
                $allOk = false;
                $rows[] = [$tabla, 'sin_ruta', $sr, '—', '—', 'REVISAR'];
            }
        }

        $this->table(['Tabla', 'Conexión', 'Origen', 'Procesado(log)', 'Diferencia', 'Estado'], $rows);

        if (in_array('nota_bancaria', $tablas) || in_array('nota_reposicion', $tablas)) {
            $this->warn('(*) CONCAT: varias filas de sistemaEco se concatenan en un registro del SGA — delta esperado > 0.');
        }

        return $allOk;
    }

    // ─────────────────────────────────────────────
    // 1.b Cobertura vs DESTINO REAL (pk-via-log)
    // ─────────────────────────────────────────────
    private function printDestinoReal(array $tablas, array $origen, string $f, string $u): bool
    {
        $allOk = true;
        $rows  = [];

        foreach ($tablas as $tabla) {
            $esConcatenada = in_array($tabla, self::TABLAS_CONCATENADAS);
            $logSource     = self::LOG_SOURCE_TABLE[$tabla];

            foreach (['sga_elec', 'sga_mec'] as $conn) {
                $o = $origen[$tabla][$conn]['origen'] ?? 0;
                try {
                    $sourcePks = $this->getSourcePksForConn($tabla, $conn, $f, $u);

                    if (empty($sourcePks)) {
                        $rows[] = [$tabla, $conn, $o, 0, $o, 'pk-via-log', $o === 0 ? 'OK' : 'DIFIERE!'];
                        if ($o > 0) $allOk = false;
                        continue;
                    }

                    $destPks = [];
                    foreach (array_chunk($sourcePks, 1000) as $lote) {
                        $chunk = DB::table('sga_migration_log')
                            ->where('source_table', $logSource)
                            ->where('dest_conn', $conn)
                            ->where('status', 'inserted')
                            ->whereIn('source_pk', $lote)
                            ->pluck('dest_pk')
                            ->toArray();
                        $destPks = array_merge($destPks, $chunk);
                    }

                    $destino = $this->countDestInSga($tabla, $conn, $destPks);
                    $delta   = $o - $destino;
                    $ok      = $esConcatenada ? true : ($delta === 0);
                    if (!$ok) $allOk = false;
                    $estado  = $esConcatenada ? 'CONCAT(*)' : ($ok ? 'OK' : 'DIFIERE!');
                    $rows[]  = [$tabla, $conn, $o, $destino, $delta, 'pk-via-log', $estado];
                } catch (\Throwable $e) {
                    $allOk = false;
                    $rows[] = [$tabla, $conn, $o, '—', '—', '—', 'ERROR: ' . mb_substr($e->getMessage(), 0, 50)];
                }
            }
        }

        $this->table(['Tabla', 'Conexión', 'Origen', 'Destino(SGA)', 'Diferencia', 'Modo', 'Estado'], $rows);

        if (in_array('nota_bancaria', $tablas) || in_array('nota_reposicion', $tablas)) {
            $this->warn('(*) CONCAT: varias filas de sistemaEco se concatenan en un registro del SGA — delta esperado > 0.');
        }

        return $allOk;
    }

    private function getSourcePksForConn(string $tabla, string $conn, string $f, string $u): array
    {
        $src = MapperHelper::SOURCE_CONN;
        $pks = [];

        switch ($tabla) {
            case 'factura':
                // GROUP BY source_pk para evitar duplicados por cod_ceta distinto
                DB::connection($src)->table('factura')
                    ->selectRaw('MIN(cod_ceta) AS cod_ceta, nro_factura, anio, es_manual')
                    ->whereBetween('fecha_emision', [$f, $u])
                    ->groupBy('nro_factura', 'anio', 'es_manual')
                    ->orderBy('nro_factura')
                    ->chunk(1000, function ($rows) use ($conn, &$pks) {
                        foreach ($rows as $r) {
                            if ($this->mapper->resolveConnByCodCeta($r->cod_ceta) === $conn) {
                                $pks[] = "{$r->nro_factura}|{$r->anio}|{$r->es_manual}";
                            }
                        }
                    });
                break;

            case 'recibo':
                $nros = DB::connection($src)->table('cobro')
                    ->whereBetween('fecha_cobro', [$f, $u])
                    ->whereNotNull('nro_recibo')->distinct()->pluck('nro_recibo');
                foreach ($nros->chunk(1000) as $lote) {
                    DB::connection($src)->table('recibo')
                        ->select('nro_recibo', 'anio', 'cod_ceta')
                        ->whereIn('nro_recibo', $lote)->get()
                        ->each(function ($r) use ($conn, &$pks) {
                            if ($this->mapper->resolveConnByCodCeta($r->cod_ceta) === $conn) {
                                $pks[] = "{$r->nro_recibo}|{$r->anio}";
                            }
                        });
                }
                break;

            case 'pago':
                DB::connection($src)->table('cobro')
                    ->select('nro_cobro', 'cod_pensum')
                    ->whereIn('cod_tipo_cobro', ['MENSUALIDAD', 'ARRASTRE'])
                    ->whereBetween('fecha_cobro', [$f, $u])
                    ->whereNotNull('cod_inscrip')
                    ->orderBy('nro_cobro')
                    ->chunk(1000, function ($rows) use ($conn, &$pks) {
                        foreach ($rows as $r) {
                            if ($this->mapper->resolveConnectionByPensum($r->cod_pensum) === $conn) {
                                $pks[] = (string) $r->nro_cobro;
                            }
                        }
                    });
                break;

            case 'pago_multa':
                DB::connection($src)->table('cobro')
                    ->select('nro_cobro', 'cod_pensum')
                    ->whereIn('cod_tipo_cobro', ['MORA', 'NIVELACION'])
                    ->whereBetween('fecha_cobro', [$f, $u])
                    ->whereNotNull('cod_inscrip')
                    ->where('monto', '>', 0)
                    ->orderBy('nro_cobro')
                    ->chunk(1000, function ($rows) use ($conn, &$pks) {
                        foreach ($rows as $r) {
                            if ($this->mapper->resolveConnectionByPensum($r->cod_pensum) === $conn) {
                                $pks[] = (string) $r->nro_cobro;
                            }
                        }
                    });
                break;

            case 'material_adicional':
                DB::connection($src)->table('cobro')
                    ->select('nro_cobro', 'cod_pensum')
                    ->where('cod_tipo_cobro', 'MATERIAL_EXTRA')
                    ->whereBetween('fecha_cobro', [$f, $u])
                    ->whereNotNull('cod_inscrip')
                    ->orderBy('nro_cobro')
                    ->chunk(1000, function ($rows) use ($conn, &$pks) {
                        foreach ($rows as $r) {
                            if ($this->mapper->resolveConnectionByPensum($r->cod_pensum) === $conn) {
                                $pks[] = (string) $r->nro_cobro;
                            }
                        }
                    });
                break;

            case 'nota_bancaria':
                DB::connection($src)->table('nota_bancaria')
                    ->select('anio_deposito', 'correlativo', 'tipo_nota', 'prefijo_carrera')
                    ->whereBetween('fecha_nota', [$f, $u])
                    ->orderBy('correlativo')
                    ->chunk(1000, function ($rows) use ($conn, &$pks) {
                        foreach ($rows as $r) {
                            if ($this->mapper->resolveConnectionByPrefijo($r->prefijo_carrera) === $conn) {
                                $pks[] = "{$r->anio_deposito}|{$r->correlativo}|{$r->tipo_nota}";
                            }
                        }
                    });
                break;

            case 'nota_reposicion':
                DB::connection($src)->table('nota_reposicion')
                    ->select('correlativo', 'anio_reposicion', 'cont', 'prefijo_carrera')
                    ->whereBetween('fecha_nota', [$f, $u])
                    ->orderBy('correlativo')
                    ->chunk(1000, function ($rows) use ($conn, &$pks) {
                        foreach ($rows as $r) {
                            if ($this->mapper->resolveConnectionByPrefijo($r->prefijo_carrera) === $conn) {
                                $pks[] = "{$r->correlativo}|{$r->anio_reposicion}|{$r->cont}";
                            }
                        }
                    });
                break;

            case 'otros_ingresos':
                DB::connection($src)->table('otros_ingresos')
                    ->select('id', 'cod_pensum')
                    ->whereBetween('fecha', [$f, $u])
                    ->orderBy('id')
                    ->chunk(1000, function ($rows) use ($conn, &$pks) {
                        foreach ($rows as $r) {
                            if ($this->mapper->resolveConnectionByPensum($r->cod_pensum) === $conn) {
                                $pks[] = (string) $r->id;
                            }
                        }
                    });
                break;

            case 'recepcion':
                $from  = substr($f, 0, 10);
                $until = substr($u, 0, 10);
                DB::connection($src)->table('recepcion_ingresos')
                    ->select('id', 'codigo_carrera')
                    ->whereBetween('fecha_recepcion', [$from, $until])
                    ->orderBy('id')
                    ->chunk(1000, function ($rows) use ($conn, &$pks) {
                        foreach ($rows as $r) {
                            $c = str_starts_with($r->codigo_carrera, 'E') ? 'sga_elec'
                               : (str_starts_with($r->codigo_carrera, 'M') ? 'sga_mec' : null);
                            if ($c === $conn) {
                                $pks[] = (string) $r->id;
                            }
                        }
                    });
                break;
        }

        return $pks;
    }

    private function countDestInSga(string $tabla, string $conn, array $destPks): int
    {
        if (empty($destPks)) return 0;

        // otros_ingresos: dest_pk is composite without a simple extractable SGA PK
        // → count distinct non-null dest_pks (each inserted log entry = one SGA record)
        if ($tabla === 'otros_ingresos') {
            return count(array_unique(array_filter($destPks)));
        }

        // factura: PK compuesta (num_factura, anio, es_manual) — num_factura NO es globalmente único
        // (puede repetirse en distintos años). Usamos comparación de tuplas con PostgreSQL.
        if ($tabla === 'factura') {
            $tuples = array_values(array_unique(array_filter(array_map(function ($pk) {
                $parts = explode('|', $pk);
                if (count($parts) < 3) return null;
                [$num, $anio, $esManual] = $parts;
                $bool = ($esManual && $esManual !== '0') ? 'true' : 'false';
                return "({$num},{$anio},{$bool})";
            }, $destPks))));
            if (empty($tuples)) return 0;
            $inClause = implode(',', $tuples);
            return (int) DB::connection($conn)->selectOne(
                "SELECT COUNT(*) AS cnt FROM factura WHERE (num_factura, anio, es_manual) IN ({$inClause})"
            )->cnt;
        }

        // recibo y recepcion tienen PK globalmente única en el SGA.
        // El resto (num_pago, correlativo, num_pago_mat) son locales por grupo
        // → usar conteo de dest_pks distintos del log como proxy exacto.
        [$destTable, $col, $idx] = match ($tabla) {
            'recibo'    => ['recibo',    'num_recibo',   0],
            'recepcion' => ['recepcion', 'id_recepcion', 0],
            default     => [null, null, null],
        };

        if (!$destTable) {
            return count(array_unique(array_filter($destPks)));
        }

        $values = array_unique(array_filter(
            array_map(fn($pk) => explode('|', $pk)[$idx] ?? null, $destPks)
        ));

        $total = 0;
        foreach (array_chunk($values, 1000) as $lote) {
            $total += DB::connection($conn)->table($destTable)->whereIn($col, $lote)->count();
        }
        return $total;
    }

    // ─────────────────────────────────────────────
    // 2. Suma de control SUM(monto)
    // ─────────────────────────────────────────────
    private function printSumasMonto(array $tablas, string $f, string $u): bool
    {
        $allOk = true;
        $rows  = [];
        $src   = MapperHelper::SOURCE_CONN;

        $srcTables = [
            'factura' => ['factura', 'fecha_emision', []],
            'recibo'  => ['recibo',  'created_at',    []],
            'pago'    => ['cobro',   'fecha_cobro',   [fn($q) => $q->whereIn('cod_tipo_cobro', ['MENSUALIDAD','ARRASTRE'])->whereNotNull('cod_inscrip')]],
            'pago_multa'         => ['cobro', 'fecha_cobro', [fn($q) => $q->whereIn('cod_tipo_cobro', ['MORA','NIVELACION'])->whereNotNull('cod_inscrip')->where('monto', '>', 0)]],
            'material_adicional' => ['cobro', 'fecha_cobro', [fn($q) => $q->where('cod_tipo_cobro','MATERIAL_EXTRA')->whereNotNull('cod_inscrip')]],
            'nota_bancaria'   => ['nota_bancaria',   'fecha_nota', []],
            'nota_reposicion' => ['nota_reposicion', 'fecha_nota', []],
            'otros_ingresos'  => ['otros_ingresos',  'fecha',      []],
            'recepcion'       => ['recepcion_ingresos', 'fecha_recepcion', []],
        ];

        $destTables = [
            'factura'=>'factura','recibo'=>'recibo','pago'=>'pago','pago_multa'=>'pago_multa',
            'material_adicional'=>'material_adicional','nota_bancaria'=>'nota_bancaria',
            'nota_reposicion'=>'nota_reposicion','otros_ingresos'=>'otros_ingresos','recepcion'=>'recepcion',
        ];

        foreach ($tablas as $tabla) {
            $montos = self::MONTO_COLS[$tabla] ?? null;
            if (!$montos) {
                $rows[] = [$tabla, '—', '—', '—', '—', 'N/A: sin columna de monto'];
                continue;
            }

            [$srcMontoCol, $dstMontoCol] = $montos;
            [$srcTable, $srcDateCol, $filters] = $srcTables[$tabla];
            $dateCol  = self::DEST_DATE_COL[$tabla];
            $destTable = $destTables[$tabla];

            // Sumar origen agrupado por conexión
            $sumaOrigen = ['sga_elec' => 0.0, 'sga_mec' => 0.0];
            try {
                $q = DB::connection($src)->table($srcTable)
                    ->whereBetween($srcDateCol, [$f, $u])
                    ->select([$this->routeCol($tabla), $srcMontoCol]);
                foreach ($filters as $filter) { $filter($q); }
                $q->orderBy($srcDateCol)->chunk(1000, function ($rows2) use ($tabla, $srcMontoCol, &$sumaOrigen) {
                    foreach ($rows2 as $r) {
                        $conn = $this->resolveConn($tabla, $r);
                        if ($conn) $sumaOrigen[$conn] += (float) ($r->$srcMontoCol ?? 0);
                    }
                });
            } catch (\Throwable $e) {
                $rows[] = [$tabla, '—', '—', '—', '—', 'ERROR origen: ' . mb_substr($e->getMessage(), 0, 60)];
                continue;
            }

            foreach (['sga_elec', 'sga_mec'] as $conn) {
                try {
                    if ($dateCol) {
                        $sumaDestino = (float) DB::connection($conn)->table($destTable)
                            ->whereBetween($dateCol, [$f, $u])->sum($dstMontoCol);
                    } else {
                        $sumaDestino = null;
                    }
                    $so = round($sumaOrigen[$conn], 2);
                    $sd = $sumaDestino !== null ? round($sumaDestino, 2) : '—';
                    $delta = $sumaDestino !== null ? round($so - (float)$sd, 2) : '—';
                    $ok = $sumaDestino !== null && abs($so - (float)$sd) < 0.01;
                    if (!$ok && $sumaDestino !== null) $allOk = false;
                    $estado = $sumaDestino === null ? 'N/A' : ($ok ? 'OK' : 'DIFIERE!');
                    $rows[] = [$tabla, $conn, $so, $sd, $delta, $estado];
                } catch (\Throwable $e) {
                    $allOk = false;
                    $rows[] = [$tabla, $conn, round($sumaOrigen[$conn], 2), '—', '—', 'ERROR: ' . mb_substr($e->getMessage(), 0, 50)];
                }
            }
        }

        $this->table(['Tabla', 'Conexión', 'Suma origen', 'Suma destino', 'Diferencia', 'Estado'], $rows);
        return $allOk;
    }

    // ─────────────────────────────────────────────
    // 3. Log detalle
    // ─────────────────────────────────────────────
    private function printLogDetalle(array $tablas): void
    {
        $logSources = array_map(fn($t) => self::LOG_SOURCE_TABLE[$t] ?? $t, $tablas);
        $rows = DB::table('sga_migration_log')
            ->whereIn('source_table', $logSources)
            ->selectRaw('dest_table, dest_conn, status, count(*) as total')
            ->groupBy('dest_table', 'dest_conn', 'status')
            ->orderBy('dest_table')->orderBy('dest_conn')
            ->get()->toArray();

        $tableRows = array_map(fn($r) => [$r->dest_table, $r->dest_conn, $r->status, $r->total], $rows);
        $this->table(['Tabla destino', 'Conexión', 'Estado', 'Total'], $tableRows ?: [['—', '—', '—', 0]]);
    }

    // ─────────────────────────────────────────────
    // 4. Secuencias
    // ─────────────────────────────────────────────
    private function printSecuencias(?string $solo): bool
    {
        $allOk = true;
        $checks = [
            'factura' => ['factura', 'num_factura', 'factura_num_factura_seq'],
            'recibo'  => ['recibo',  'num_recibo',  'recibo_num_recibo_seq'],
        ];
        if ($solo && !isset($checks[$solo])) {
            $this->line('   (sin secuencias aplicables para --solo)');
            return true;
        }
        $iter = $solo ? [$solo => $checks[$solo]] : $checks;
        $rows = [];
        foreach (['sga_elec', 'sga_mec'] as $conn) {
            foreach ($iter as [$table, $col, $seq]) {
                try {
                    $max  = (int) DB::connection($conn)->table($table)->max($col);
                    $last = (int) (DB::connection($conn)->selectOne("SELECT last_value FROM {$seq}")->last_value ?? 0);
                    $ok   = $last >= $max;
                    if (!$ok) $allOk = false;
                    $rows[] = [$conn, $table, $max, $last, $ok ? 'OK' : 'DESFASE!', ''];
                } catch (\Throwable $e) {
                    $allOk = false;
                    $rows[] = [$conn, $table, '—', '—', 'DESFASE!', mb_substr($e->getMessage(), 0, 60)];
                }
            }
        }
        $this->table(['Conexión', 'Tabla', 'MAX', 'Secuencia', 'Estado', 'Error'], $rows);
        return $allOk;
    }

    // ─────────────────────────────────────────────
    // 5. Detalle recepcion
    // ─────────────────────────────────────────────
    private function printDetalleRecepcion(string $f, string $u): bool
    {
        $src   = MapperHelper::SOURCE_CONN;
        $allOk = true;
        $rows  = [];

        foreach (['sga_elec', 'sga_mec'] as $conn) {
            try {
                // Source PKs de recepcion en el rango de fechas para este conn
                $sourcePksInRange = $this->getSourcePksForConn('recepcion', $conn, $f, $u);

                if (empty($sourcePksInRange)) {
                    $rows[] = [$conn, 0, 0, 0, '—', 'N/A'];
                    continue;
                }

                // dest_pk (id_recepcion en SGA) para esos source PKs
                $idRecepcionesLog = [];
                foreach (array_chunk($sourcePksInRange, 1000) as $lote) {
                    $chunk = DB::table('sga_migration_log')
                        ->where('source_table', 'recepcion_ingresos')
                        ->where('dest_conn', $conn)
                        ->where('status', 'inserted')
                        ->whereIn('source_pk', $lote)
                        ->pluck('dest_pk')
                        ->toArray();
                    $idRecepcionesLog = array_merge($idRecepcionesLog, $chunk);
                }

                // IDs en eco para contar detalles
                $sourceIds = array_map('intval', $sourcePksInRange);

                $conDetalle = 0;
                foreach (array_chunk($sourceIds, 1000) as $lote) {
                    $conDetalle += DB::connection($src)->table('recepcion_ingreso_detalles')
                        ->whereIn('recepcion_ingreso_id', $lote)
                        ->distinct('recepcion_ingreso_id')
                        ->count('recepcion_ingreso_id');
                }

                // Contar detalle_recepcion en SGA para esos id_recepcion
                $totalSga = 0;
                foreach (array_chunk($idRecepcionesLog, 1000) as $lote) {
                    $totalSga += DB::connection($conn)->table('detalle_recepcion')
                        ->whereIn('id_recepcion', $lote)
                        ->count();
                }

                $delta  = $conDetalle - $totalSga;
                $ok     = $delta === 0;
                if (!$ok) $allOk = false;
                $rows[] = [$conn, count($sourceIds), $conDetalle, $totalSga, $delta, $ok ? 'OK' : 'FALTAN!'];
            } catch (\Throwable $e) {
                $allOk = false;
                $rows[] = [$conn, '—', '—', '—', '—', 'ERROR: ' . mb_substr($e->getMessage(), 0, 60)];
            }
        }

        $this->table(['Conexión', 'Recepcion migradas', 'Con detalle (eco)', 'detalle_recepcion (SGA)', 'Diferencia', 'Estado'], $rows);
        $this->line('   <fg=gray>Nota: "Con detalle (eco)" puede ser menor que "Recepcion migradas" si alguna recepcion no tenía detalle en sistemaEco.</fg>');
        return $allOk;
    }

    // ─────────────────────────────────────────────
    // 6. Errores en log
    // ─────────────────────────────────────────────
    private function printErrores(array $tablas): bool
    {
        $logSources = array_map(fn($t) => self::LOG_SOURCE_TABLE[$t] ?? $t, $tablas);
        $errores = DB::table('sga_migration_log')
            ->where('status', 'error')
            ->whereIn('source_table', $logSources)
            ->select('dest_table', 'dest_conn', 'source_pk', 'error_message', 'pushed_at')
            ->orderByDesc('pushed_at')->limit(50)->get();

        if ($errores->isEmpty()) {
            $this->line('   Sin errores.');
            return true;
        }
        $rows = $errores->map(fn($e) => [
            $e->dest_table, $e->dest_conn, $e->source_pk,
            mb_substr($e->error_message ?? '', 0, 80),
        ])->toArray();
        $this->table(['Tabla', 'Conexión', 'Source PK', 'Error (80c)'], $rows);
        return false;
    }

    // ─────────────────────────────────────────────
    // Helpers: origen por conexión
    // ─────────────────────────────────────────────
    private function getOrigenPorConn(array $tablas, string $f, string $u, string $from, string $until): array
    {
        $result = [];
        $init   = ['sga_elec' => ['origen' => 0], 'sga_mec' => ['origen' => 0], 'sin_ruta' => 0];

        foreach ($tablas as $tabla) {
            $result[$tabla] = $init;
            try {
                match ($tabla) {
                    'factura' => $this->countFactura($result[$tabla], $f, $u),
                    'recibo'  => $this->countRecibo($result[$tabla], $f, $u),
                    'pago'    => $this->countCobro($result[$tabla], $f, $u, ['MENSUALIDAD','ARRASTRE']),
                    'pago_multa'         => $this->countCobro($result[$tabla], $f, $u, ['MORA','NIVELACION']),
                    'material_adicional' => $this->countCobro($result[$tabla], $f, $u, ['MATERIAL_EXTRA']),
                    'nota_bancaria'   => $this->countPrefijo($result[$tabla], 'nota_bancaria',   'fecha_nota', $f, $u),
                    'nota_reposicion' => $this->countPrefijo($result[$tabla], 'nota_reposicion', 'fecha_nota', $f, $u),
                    'otros_ingresos'  => $this->countPensum($result[$tabla], 'otros_ingresos', 'fecha', $f, $u),
                    'recepcion'       => $this->countRecepcion($result[$tabla], $from, $until),
                    default => null,
                };
            } catch (\Throwable $e) {
                $this->warn("   Error contando origen [{$tabla}]: " . $e->getMessage());
            }
        }
        return $result;
    }

    private function countFactura(array &$d, string $f, string $u): void
    {
        // GROUP BY source_pk (nro_factura, anio, es_manual) para no contar duplicados
        // eco puede tener 2 filas con mismo nro_factura pero distinto cod_ceta.
        DB::connection(MapperHelper::SOURCE_CONN)->table('factura')
            ->selectRaw('MIN(cod_ceta) AS cod_ceta, nro_factura, anio, es_manual')
            ->whereBetween('fecha_emision', [$f, $u])
            ->groupBy('nro_factura', 'anio', 'es_manual')
            ->orderBy('nro_factura')
            ->chunk(500, function ($rows) use (&$d) {
                foreach ($rows as $r) {
                    $conn = $this->mapper->resolveConnByCodCeta($r->cod_ceta);
                    $conn ? $d[$conn]['origen']++ : $d['sin_ruta']++;
                }
            });
    }

    private function countRecibo(array &$d, string $f, string $u): void
    {
        $nros = DB::connection(MapperHelper::SOURCE_CONN)->table('cobro')
            ->whereBetween('fecha_cobro', [$f, $u])->whereNotNull('nro_recibo')
            ->distinct()->pluck('nro_recibo');
        if ($nros->isEmpty()) return;
        foreach ($nros->chunk(1000) as $lote) {
            DB::connection(MapperHelper::SOURCE_CONN)->table('recibo')
                ->select('cod_ceta')->whereIn('nro_recibo', $lote)->get()
                ->each(function ($r) use (&$d) {
                    $conn = $this->mapper->resolveConnByCodCeta($r->cod_ceta);
                    $conn ? $d[$conn]['origen']++ : $d['sin_ruta']++;
                });
        }
    }

    private function countCobro(array &$d, string $f, string $u, array $tipos): void
    {
        $q = DB::connection(MapperHelper::SOURCE_CONN)->table('cobro')
            ->select('cod_pensum')->whereIn('cod_tipo_cobro', $tipos)
            ->whereBetween('fecha_cobro', [$f, $u])->whereNotNull('cod_inscrip');

        // PagoMultaWriter salta cobros con monto <= 0 → excluir para que el conteo coincida
        $esSoloMulta = $tipos === ['MORA', 'NIVELACION'];
        if ($esSoloMulta) {
            $q->where('monto', '>', 0);
        }

        $q->orderBy('fecha_cobro')->chunk(500, function ($rows) use (&$d) {
            foreach ($rows as $r) {
                $conn = $this->mapper->resolveConnectionByPensum($r->cod_pensum);
                $conn ? $d[$conn]['origen']++ : $d['sin_ruta']++;
            }
        });
    }

    private function countPrefijo(array &$d, string $table, string $dateCol, string $f, string $u): void
    {
        DB::connection(MapperHelper::SOURCE_CONN)->table($table)
            ->select('prefijo_carrera')->whereBetween($dateCol, [$f, $u])
            ->orderBy($dateCol)->chunk(500, function ($rows) use (&$d) {
                foreach ($rows as $r) {
                    $conn = $this->mapper->resolveConnectionByPrefijo($r->prefijo_carrera);
                    $conn ? $d[$conn]['origen']++ : $d['sin_ruta']++;
                }
            });
    }

    private function countPensum(array &$d, string $table, string $dateCol, string $f, string $u): void
    {
        DB::connection(MapperHelper::SOURCE_CONN)->table($table)
            ->select('cod_pensum')->whereBetween($dateCol, [$f, $u])
            ->orderBy($dateCol)->chunk(500, function ($rows) use (&$d) {
                foreach ($rows as $r) {
                    $conn = $this->mapper->resolveConnectionByPensum($r->cod_pensum);
                    $conn ? $d[$conn]['origen']++ : $d['sin_ruta']++;
                }
            });
    }

    private function countRecepcion(array &$d, string $from, string $until): void
    {
        DB::connection(MapperHelper::SOURCE_CONN)->table('recepcion_ingresos')
            ->select('codigo_carrera')->whereBetween('fecha_recepcion', [$from, $until])
            ->orderBy('fecha_recepcion')->chunk(500, function ($rows) use (&$d) {
                foreach ($rows as $r) {
                    $conn = str_starts_with($r->codigo_carrera, 'E') ? 'sga_elec'
                          : (str_starts_with($r->codigo_carrera, 'M') ? 'sga_mec' : null);
                    $conn ? $d[$conn]['origen']++ : $d['sin_ruta']++;
                }
            });
    }

    private function getLogCounts(array $tablas, string $f, string $u): array
    {
        $out = [];

        foreach ($tablas as $tabla) {
            $logSource = self::LOG_SOURCE_TABLE[$tabla] ?? $tabla;

            foreach (['sga_elec', 'sga_mec'] as $conn) {
                $connPks = $this->getSourcePksForConn($tabla, $conn, $f, $u);
                if (empty($connPks)) continue;

                foreach (array_chunk($connPks, 1000) as $lote) {
                    // Conteo por status (para sección 3)
                    DB::table('sga_migration_log')
                        ->where('source_table', $logSource)
                        ->where('dest_conn', $conn)
                        ->whereIn('source_pk', $lote)
                        ->selectRaw('status, count(*) as total')
                        ->groupBy('status')
                        ->get()
                        ->each(function ($r) use (&$out, $tabla, $conn) {
                            $out[$tabla][$conn][$r->status] =
                                ($out[$tabla][$conn][$r->status] ?? 0) + (int) $r->total;
                        });

                    // Procesados distintos por conn (evita doble conteo entre runs)
                    $procesado = (int) DB::table('sga_migration_log')
                        ->where('source_table', $logSource)
                        ->where('dest_conn', $conn)
                        ->whereIn('source_pk', $lote)
                        ->whereIn('status', ['inserted', 'skipped', 'excluded'])
                        ->distinct('source_pk')
                        ->count('source_pk');
                    $out[$tabla][$conn]['_procesado'] =
                        ($out[$tabla][$conn]['_procesado'] ?? 0) + $procesado;
                }
            }
        }

        return $out;
    }

    private function routeCol(string $tabla): string
    {
        return match ($tabla) {
            'factura' => 'cod_ceta',
            'nota_bancaria', 'nota_reposicion' => 'prefijo_carrera',
            'recepcion' => 'codigo_carrera',
            default => 'cod_pensum',
        };
    }

    private function resolveConn(string $tabla, object $r): ?string
    {
        return match ($tabla) {
            'factura' => $this->mapper->resolveConnByCodCeta($r->cod_ceta),
            'nota_bancaria', 'nota_reposicion' => $this->mapper->resolveConnectionByPrefijo($r->prefijo_carrera),
            'recepcion' => str_starts_with($r->codigo_carrera, 'E') ? 'sga_elec'
                         : (str_starts_with($r->codigo_carrera, 'M') ? 'sga_mec' : null),
            default => $this->mapper->resolveConnectionByPensum($r->cod_pensum),
        };
    }
}
