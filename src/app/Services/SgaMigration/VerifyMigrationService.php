<?php

namespace App\Services\SgaMigration;

use Illuminate\Support\Facades\DB;

/**
 * Verificación post-migración (read-only). Cruza cuatro fuentes por tabla:
 *  1. Origen (eco_backup): cuántos registros del rango se rutean a cada conexión.
 *  2. Log (sga_migration_log): insertados / saltados / con error por conexión.
 *  3. SGA real: COUNT y SUM(monto) en destino, filtrando por la columna de fecha equivalente.
 *  4. Spot-checks: N filas aleatorias del origen comparadas campo a campo contra destino.
 *
 * No escribe nada. Devuelve un arreglo estructurado para que el comando lo muestre.
 */
class VerifyMigrationService
{
    private const TOL_MONTO = 0.01;

    /**
     * Configuración por tabla destino. Centraliza:
     *  - cómo contar el origen (tabla + columna fecha + filtros + ruteo).
     *  - cómo contar el destino (tabla + columna fecha o whereIn por PK).
     *  - cómo sumar monto en origen y destino (null = no aplica).
     *  - log_table (clave en sga_migration_log).
     *  - source_pk_builder (cómo armar el source_pk desde una fila origen).
     *  - spot_fields: lista de [src_col, dst_col, type] para comparar.
     */
    private array $config;

    public function __construct(private MapperHelper $mapper)
    {
        $this->config = $this->buildConfig();
    }

    public function run(string $from, string $until, ?string $solo = null, int $spotChecks = 5): array
    {
        $tables = $solo ? [$solo] : array_keys($this->config);

        return [
            'cobertura'    => $this->checkCobertura($from, $until, $tables),
            'destino_real' => $this->checkDestinoReal($from, $until, $tables),
            'sumas_monto'  => $this->checkSumasMonto($from, $until, $tables),
            'spot_checks'  => $spotChecks > 0 ? $this->runSpotChecks($from, $until, $tables, $spotChecks) : [],
            'log'          => $this->checkLog($solo),
            'secuencias'   => $this->checkSecuencias($solo),
            'errores'      => $this->listErrores($solo),
        ];
    }

    // ============================================================
    // 1. Cobertura origen vs log (procesado = inserted + skipped)
    // ============================================================
    private function checkCobertura(string $from, string $until, array $tables): array
    {
        $f = "{$from} 00:00:00";
        $u = "{$until} 23:59:59";
        $out = [];
        foreach ($tables as $t) {
            $cfg = $this->config[$t] ?? null;
            if (!$cfg) continue;
            $origen = $this->countOrigenPorConn($cfg, $f, $u);
            $out[$t] = $this->withLog($origen, $cfg['log_table']);
        }
        return $out;
    }

    /** Cuenta filas del origen en el rango y las rutea a sga_elec/sga_mec/sin_ruta. */
    private function countOrigenPorConn(array $cfg, string $f, string $u): array
    {
        $origen = ['sga_elec' => 0, 'sga_mec' => 0, 'sin_ruta' => 0];
        $q = DB::connection(MapperHelper::SOURCE_CONN)->table($cfg['src_table'])
            ->whereBetween($cfg['src_date_col'], [$f, $u]);
        foreach ($cfg['src_filters'] ?? [] as $filter) {
            $filter($q);
        }
        $q->select($cfg['route_cols'])
            ->orderBy($cfg['src_date_col'])
            ->chunk(1000, function ($rows) use (&$origen, $cfg) {
                foreach ($rows as $r) {
                    $conn = ($cfg['route_resolver'])($r);
                    $origen[$conn ?? 'sin_ruta']++;
                }
            });
        return $origen;
    }

    /** Adjunta el conteo del log (procesado = inserted + skipped). */
    private function withLog(array $origen, string $logTable): array
    {
        $out = [];
        foreach (['sga_elec', 'sga_mec'] as $conn) {
            $procesado = DB::table('sga_migration_log')
                ->where('source_table', $logTable)->where('dest_conn', $conn)
                ->whereIn('status', ['inserted', 'skipped'])->count();
            $delta = $origen[$conn] - $procesado;
            $out[$conn] = [
                'origen'    => $origen[$conn],
                'procesado' => $procesado,
                'delta'     => $delta,
                'ok'        => $delta === 0,
            ];
        }
        $out['sin_ruta'] = $origen['sin_ruta'];
        return $out;
    }

    // ============================================================
    // 2. Origen vs DESTINO REAL (count en el SGA)
    // ============================================================
    private function checkDestinoReal(string $from, string $until, array $tables): array
    {
        $f = "{$from} 00:00:00";
        $u = "{$until} 23:59:59";
        $out = [];
        foreach ($tables as $t) {
            $cfg = $this->config[$t] ?? null;
            if (!$cfg) continue;
            $origen = $this->countOrigenPorConn($cfg, $f, $u);
            $out[$t] = $this->countDestinoPorConn($cfg, $f, $u, $origen, $t);
        }
        return $out;
    }

    /** Cuenta filas en el SGA para el rango y compara contra el origen. */
    private function countDestinoPorConn(array $cfg, string $f, string $u, array $origen, string $tableKey): array
    {
        $out = [];
        foreach (['sga_elec', 'sga_mec'] as $conn) {
            try {
                if (!empty($cfg['dest_date_col'])) {
                    $destino = DB::connection($conn)->table($cfg['dest_table'])
                        ->whereBetween($cfg['dest_date_col'], [$f, $u])
                        ->count();
                } else {
                    // Sin columna de fecha en destino (caso recibo): contar por las PKs que el log registró como inserted/skipped.
                    $destino = $this->countDestinoPorLog($conn, $cfg);
                }
                $delta = $origen[$conn] - $destino;
                $out[$conn] = [
                    'origen'  => $origen[$conn],
                    'destino' => $destino,
                    'delta'   => $delta,
                    'ok'      => $delta === 0,
                    'modo'    => !empty($cfg['dest_date_col']) ? 'fecha' : 'pk-via-log',
                ];
            } catch (\Throwable $e) {
                $out[$conn] = ['origen' => $origen[$conn], 'destino' => '—', 'delta' => '—', 'ok' => false, 'error' => $e->getMessage()];
            }
        }
        $out['sin_ruta'] = $origen['sin_ruta'];
        return $out;
    }

    /** Para tablas sin fecha en destino: cuenta PKs distintas del log con status=inserted+skipped. */
    private function countDestinoPorLog(string $conn, array $cfg): int
    {
        return DB::table('sga_migration_log')
            ->where('source_table', $cfg['log_table'])
            ->where('dest_conn', $conn)
            ->whereIn('status', ['inserted', 'skipped'])
            ->count();
    }

    // ============================================================
    // 3. SUM(monto) origen vs destino
    // ============================================================
    private function checkSumasMonto(string $from, string $until, array $tables): array
    {
        $f = "{$from} 00:00:00";
        $u = "{$until} 23:59:59";
        $out = [];
        foreach ($tables as $t) {
            $cfg = $this->config[$t] ?? null;
            if (!$cfg) continue;
            if (empty($cfg['src_amount_col']) || empty($cfg['dest_amount_col'])) {
                $out[$t] = ['na' => true, 'motivo' => $cfg['amount_na_reason'] ?? 'sin columna de monto'];
                continue;
            }
            $out[$t] = $this->sumOrigenVsDestino($cfg, $f, $u);
        }
        return $out;
    }

    private function sumOrigenVsDestino(array $cfg, string $f, string $u): array
    {
        // Origen: sumar agrupando por conexión (resolución por fila).
        $origenSum = ['sga_elec' => 0.0, 'sga_mec' => 0.0, 'sin_ruta' => 0.0];
        $q = DB::connection(MapperHelper::SOURCE_CONN)->table($cfg['src_table'])
            ->whereBetween($cfg['src_date_col'], [$f, $u]);
        foreach ($cfg['src_filters'] ?? [] as $filter) {
            $filter($q);
        }
        $cols = array_unique(array_merge($cfg['route_cols'], [$cfg['src_amount_col']]));
        $q->select($cols)
            ->orderBy($cfg['src_date_col'])
            ->chunk(1000, function ($rows) use (&$origenSum, $cfg) {
                foreach ($rows as $r) {
                    $conn = ($cfg['route_resolver'])($r);
                    $bucket = $conn ?? 'sin_ruta';
                    $origenSum[$bucket] += (float) ($r->{$cfg['src_amount_col']} ?? 0);
                }
            });

        $out = [];
        foreach (['sga_elec', 'sga_mec'] as $conn) {
            try {
                if (!empty($cfg['dest_date_col'])) {
                    $destinoSum = (float) DB::connection($conn)->table($cfg['dest_table'])
                        ->whereBetween($cfg['dest_date_col'], [$f, $u])
                        ->sum($cfg['dest_amount_col']);
                } else {
                    // Sin fecha en destino: sumar uniendo por log (limitación documentada).
                    $destinoSum = null;
                }
                $delta = $destinoSum === null ? null : round($origenSum[$conn] - $destinoSum, 4);
                $out[$conn] = [
                    'origen'  => round($origenSum[$conn], 2),
                    'destino' => $destinoSum === null ? '—' : round($destinoSum, 2),
                    'delta'   => $delta === null ? '—' : $delta,
                    'ok'      => $delta === null ? false : abs($delta) < self::TOL_MONTO,
                    'modo'    => !empty($cfg['dest_date_col']) ? 'fecha' : 'sin-fecha-destino',
                ];
            } catch (\Throwable $e) {
                $out[$conn] = ['origen' => round($origenSum[$conn], 2), 'destino' => '—', 'delta' => '—', 'ok' => false, 'error' => $e->getMessage()];
            }
        }
        $out['sin_ruta'] = round($origenSum['sin_ruta'], 2);
        return $out;
    }

    // ============================================================
    // 4. Spot-checks aleatorios
    // ============================================================
    private function runSpotChecks(string $from, string $until, array $tables, int $n): array
    {
        $f = "{$from} 00:00:00";
        $u = "{$until} 23:59:59";
        $out = [];
        foreach ($tables as $t) {
            $cfg = $this->config[$t] ?? null;
            if (!$cfg || empty($cfg['spot_fields'])) {
                $out[$t] = ['na' => true, 'motivo' => 'sin spot_fields configurados'];
                continue;
            }
            $out[$t] = ['sga_elec' => $this->spotCheckTablaConn($cfg, $f, $u, 'sga_elec', $n),
                        'sga_mec'  => $this->spotCheckTablaConn($cfg, $f, $u, 'sga_mec', $n)];
        }
        return $out;
    }

    private function spotCheckTablaConn(array $cfg, string $f, string $u, string $conn, int $n): array
    {
        // 1. Sacar N filas aleatorias del origen para esa conexión.
        $sampled = [];
        $q = DB::connection(MapperHelper::SOURCE_CONN)->table($cfg['src_table'])
            ->whereBetween($cfg['src_date_col'], [$f, $u]);
        foreach ($cfg['src_filters'] ?? [] as $filter) {
            $filter($q);
        }
        $cols = array_unique(array_merge($cfg['route_cols'], $cfg['src_pk_cols'], array_map(fn($f) => $f[0], $cfg['spot_fields'])));
        // Filtrar por conexión iterando (no podemos hacerlo en SQL puro porque depende de un lookup).
        $candidates = [];
        $q->select($cols)
            ->orderByRaw('RAND()')
            ->limit($n * 50) // sobre-muestreamos para tener suficientes de la conexión deseada
            ->get()
            ->each(function ($r) use ($cfg, $conn, &$candidates, $n) {
                if (count($candidates) >= $n) return;
                if (($cfg['route_resolver'])($r) === $conn) {
                    $candidates[] = $r;
                }
            });
        $sampled = array_slice($candidates, 0, $n);

        $results = [];
        foreach ($sampled as $srcRow) {
            $sourcePk = ($cfg['source_pk_builder'])($srcRow);
            $destRow = $this->fetchDestRow($cfg, $srcRow, $sourcePk, $conn);
            if (!$destRow) {
                $results[] = ['pk' => $sourcePk, 'diffs' => ['_not_found_in_dest' => true]];
                continue;
            }
            $diffs = $this->compareFields($srcRow, $destRow, $cfg['spot_fields']);
            $results[] = ['pk' => $sourcePk, 'diffs' => $diffs];
        }
        return $results;
    }

    /** Trae la fila destino: directo por PK (factura/recibo) o vía log.dest_pk (resto). */
    private function fetchDestRow(array $cfg, object $srcRow, string $sourcePk, string $conn): ?object
    {
        if ($cfg['dest_lookup'] === 'direct') {
            $q = DB::connection($conn)->table($cfg['dest_table']);
            foreach ($cfg['dest_pk_cols'] as $i => $col) {
                $srcCol = $cfg['src_pk_cols'][$i];
                $q->where($col, $srcRow->$srcCol);
            }
            return $q->first();
        }

        // via_log: source_pk → log.dest_pk → destino
        $destPk = DB::table('sga_migration_log')
            ->where('source_table', $cfg['log_table'])
            ->where('source_pk', $sourcePk)
            ->where('dest_conn', $conn)
            ->where('status', 'inserted')
            ->value('dest_pk');
        if (!$destPk) return null;

        $parts = explode('|', $destPk);
        $q = DB::connection($conn)->table($cfg['dest_table']);
        foreach ($cfg['dest_pk_cols'] as $i => $col) {
            if (!isset($parts[$i])) return null;
            $q->where($col, $parts[$i]);
        }
        return $q->first();
    }

    /** Compara campos con tolerancia por tipo. Devuelve solo los que difieren. */
    private function compareFields(object $src, object $dst, array $fields): array
    {
        $diffs = [];
        foreach ($fields as [$srcCol, $dstCol, $type]) {
            $a = $src->$srcCol ?? null;
            $b = $dst->$dstCol ?? null;
            if (!$this->valuesEqual($a, $b, $type)) {
                $diffs[$dstCol] = ['origen' => $a, 'destino' => $b];
            }
        }
        return $diffs;
    }

    private function valuesEqual($a, $b, string $type): bool
    {
        if ($a === null && $b === null) return true;
        return match ($type) {
            'float'  => abs((float) $a - (float) $b) < self::TOL_MONTO,
            'int'    => (int) $a === (int) $b,
            'bool'   => (bool) $a === (bool) $b,
            'string' => trim((string) ($a ?? '')) === trim((string) ($b ?? '')),
            default  => (string) $a === (string) $b,
        };
    }

    // ============================================================
    // 5-7. Heredados (log/secuencias/errores) — con opción --solo
    // ============================================================
    private function checkLog(?string $solo): array
    {
        $q = DB::table('sga_migration_log')
            ->select('dest_table', 'dest_conn', 'status', DB::raw('count(*) as total'))
            ->groupBy('dest_table', 'dest_conn', 'status')
            ->orderBy('dest_table')->orderBy('dest_conn');
        if ($solo) {
            $cfg = $this->config[$solo] ?? null;
            if ($cfg) $q->where('dest_table', $cfg['dest_table']);
        }
        return $q->get()->toArray();
    }

    private function checkSecuencias(?string $solo): array
    {
        $out = [];
        $checks = [
            'factura' => ['factura', 'num_factura', 'factura_num_factura_seq'],
            'recibo'  => ['recibo',  'num_recibo',  'recibo_num_recibo_seq'],
        ];
        if ($solo && !isset($checks[$solo])) return [];
        $iter = $solo ? [$solo => $checks[$solo]] : $checks;
        foreach (['sga_elec', 'sga_mec'] as $conn) {
            foreach ($iter as [$table, $col, $seq]) {
                try {
                    $max = (int) DB::connection($conn)->table($table)->max($col);
                    $last = (int) (DB::connection($conn)->selectOne("SELECT last_value FROM {$seq}")->last_value ?? 0);
                    $out[] = ['conn' => $conn, 'tabla' => $table, 'max' => $max, 'seq' => $last, 'ok' => $last >= $max];
                } catch (\Throwable $e) {
                    $out[] = ['conn' => $conn, 'tabla' => $table, 'max' => '—', 'seq' => '—', 'ok' => false, 'error' => $e->getMessage()];
                }
            }
        }
        return $out;
    }

    private function listErrores(?string $solo): array
    {
        $q = DB::table('sga_migration_log')
            ->where('status', 'error')
            ->select('source_table', 'source_pk', 'dest_conn', 'dest_table', 'error_message', 'pushed_at')
            ->orderByDesc('pushed_at')->limit(50);
        if ($solo) {
            $cfg = $this->config[$solo] ?? null;
            if ($cfg) $q->where('dest_table', $cfg['dest_table']);
        }
        return $q->get()->toArray();
    }

    // ============================================================
    // Configuración por tabla
    // ============================================================
    private function buildConfig(): array
    {
        $byCodCeta = fn($r) => $this->mapper->resolveConnByCodCeta($r->cod_ceta);
        $byPensum  = fn($r) => $this->mapper->resolveConnectionByPensum($r->cod_pensum);
        $byPrefijo = fn($r) => $this->mapper->resolveConnectionByPrefijo($r->prefijo_carrera);

        return [
            'factura' => [
                'src_table' => 'factura',
                'src_date_col' => 'fecha_emision',
                'src_filters' => [],
                'route_cols' => ['cod_ceta'],
                'route_resolver' => $byCodCeta,
                'log_table' => 'factura',
                'dest_table' => 'factura',
                'dest_date_col' => 'fecha_factura',
                'src_amount_col' => null,
                'dest_amount_col' => null,
                'amount_na_reason' => 'el writer no migra monto a la cabecera de factura',
                'src_pk_cols' => ['nro_factura', 'anio', 'es_manual'],
                'dest_pk_cols' => ['num_factura', 'anio', 'es_manual'],
                'dest_lookup' => 'direct',
                'source_pk_builder' => fn($r) => "{$r->nro_factura}|{$r->anio}|{$r->es_manual}",
                'spot_fields' => [
                    ['codigo_cufd', 'codigo_cufd', 'string'],
                    ['codigo_recepcion', 'codigo_recepcion', 'string'],
                    ['cafc', 'cafc', 'string'],
                    ['cuf', 'cuf', 'string'],
                    ['periodo_facturado', 'periodo_facturado', 'string'],
                    ['es_manual', 'es_manual', 'bool'],
                ],
            ],
            'recibo' => [
                'src_table' => 'recibo',
                'src_date_col' => 'created_at',
                'src_filters' => [],
                'route_cols' => ['cod_ceta'],
                'route_resolver' => $byCodCeta,
                'log_table' => 'recibo',
                'dest_table' => 'recibo',
                'dest_date_col' => null,
                'src_amount_col' => null,
                'dest_amount_col' => null,
                'amount_na_reason' => 'el writer no migra monto y recibo no tiene fecha en destino',
                'src_pk_cols' => ['nro_recibo', 'anio'],
                'dest_pk_cols' => ['num_recibo', 'anio'],
                'dest_lookup' => 'direct',
                'source_pk_builder' => fn($r) => "{$r->nro_recibo}|{$r->anio}",
                'spot_fields' => [
                    ['complemento', 'complemento', 'string'],
                    ['periodo_facturado', 'periodo_facturado', 'string'],
                    ['tiene_reposicion', 'tiene_reposicion', 'bool'],
                ],
            ],
            'pago' => [
                'src_table' => 'cobro',
                'src_date_col' => 'fecha_cobro',
                'src_filters' => [fn($q) => $q->whereIn('cod_tipo_cobro', ['MENSUALIDAD', 'ARRASTRE'])->whereNotNull('cod_inscrip')],
                'route_cols' => ['cod_pensum'],
                'route_resolver' => $byPensum,
                'log_table' => 'cobro_pago',
                'dest_table' => 'pago',
                'dest_date_col' => 'fecha_pago',
                'src_amount_col' => 'monto',
                'dest_amount_col' => 'monto',
                'src_pk_cols' => ['nro_cobro'],
                'dest_pk_cols' => ['cod_ceta', 'cod_pensum', 'cod_inscrip', 'kardex_economico', 'num_cuota', 'num_pago'],
                'dest_lookup' => 'via_log',
                'source_pk_builder' => fn($r) => (string) $r->nro_cobro,
                'spot_fields' => [
                    ['monto', 'monto', 'float'],
                    ['nro_recibo', 'num_comprobante', 'int'],
                    ['nro_factura', 'num_factura', 'int'],
                    ['pu_mensualidad', 'pu_mensualidad', 'float'],
                    ['descuento', 'descuento', 'float'],
                    ['cobro_completo', 'pago_completo', 'bool'],
                    ['observaciones', 'observaciones', 'string'],
                    ['concepto', 'concepto', 'string'],
                ],
            ],
            'pago_multa' => [
                'src_table' => 'cobro',
                'src_date_col' => 'fecha_cobro',
                'src_filters' => [fn($q) => $q->whereIn('cod_tipo_cobro', ['MORA', 'NIVELACION'])->whereNotNull('cod_inscrip')],
                'route_cols' => ['cod_pensum'],
                'route_resolver' => $byPensum,
                'log_table' => 'cobro_pago_multa',
                'dest_table' => 'pago_multa',
                'dest_date_col' => 'fecha_pago',
                'src_amount_col' => 'monto',
                'dest_amount_col' => 'monto',
                'src_pk_cols' => ['nro_cobro'],
                'dest_pk_cols' => ['cod_ceta', 'cod_pensum', 'gestion', 'kardex_economico', 'num_cuota', 'num_pago'],
                'dest_lookup' => 'via_log',
                'source_pk_builder' => fn($r) => (string) $r->nro_cobro,
                'spot_fields' => [
                    ['monto', 'monto', 'float'],
                    ['nro_recibo', 'num_comprobante', 'int'],
                    ['nro_factura', 'num_factura', 'int'],
                    ['descuento', 'descuento', 'float'],
                    ['cobro_completo', 'pago_completo', 'bool'],
                    ['gestion', 'gestion', 'string'],
                ],
            ],
            'material_adicional' => [
                'src_table' => 'cobro',
                'src_date_col' => 'fecha_cobro',
                'src_filters' => [fn($q) => $q->where('cod_tipo_cobro', 'MATERIAL_EXTRA')->whereNotNull('cod_inscrip')],
                'route_cols' => ['cod_pensum'],
                'route_resolver' => $byPensum,
                'log_table' => 'cobro_material',
                'dest_table' => 'material_adicional',
                'dest_date_col' => 'fecha_pago',
                'src_amount_col' => 'monto',
                'dest_amount_col' => 'costo_total',
                'src_pk_cols' => ['nro_cobro'],
                'dest_pk_cols' => ['cod_ceta', 'cod_pensum', 'cod_inscrip', 'num_comprobante', 'num_pago_mat'],
                'dest_lookup' => 'via_log',
                'source_pk_builder' => fn($r) => (string) $r->nro_cobro,
                'spot_fields' => [
                    ['monto', 'costo_total', 'float'],
                    ['nro_recibo', 'num_comprobante', 'int'],
                    ['nro_factura', 'num_factura', 'int'],
                    ['cobro_completo', 'pago_completo', 'bool'],
                    ['observaciones', 'observaciones', 'string'],
                    ['concepto', 'concepto', 'string'],
                ],
            ],
            'nota_bancaria' => [
                'src_table' => 'nota_bancaria',
                'src_date_col' => 'fecha_nota',
                'src_filters' => [],
                'route_cols' => ['prefijo_carrera'],
                'route_resolver' => $byPrefijo,
                'log_table' => 'nota_bancaria',
                'dest_table' => 'nota_bancaria',
                'dest_date_col' => 'fecha_nota',
                'src_amount_col' => 'monto',
                'dest_amount_col' => 'monto',
                'src_pk_cols' => ['anio_deposito', 'correlativo', 'tipo_nota'],
                'dest_pk_cols' => ['anio_deposito', 'correlativo', 'tipo_nota'],
                'dest_lookup' => 'via_log',
                'source_pk_builder' => fn($r) => "{$r->anio_deposito}|{$r->correlativo}|{$r->tipo_nota}",
                'spot_fields' => [
                    ['monto', 'monto', 'float'],
                    ['concepto', 'concepto', 'string'],
                    ['banco', 'banco', 'string'],
                    ['nro_transaccion', 'nro_transaccion', 'string'],
                    ['banco_origen', 'banco_origen', 'string'],
                    ['prefijo_carrera', 'prefijo_carrera', 'string'],
                    ['tipo_nota', 'tipo_nota', 'string'],
                ],
            ],
            'nota_reposicion' => [
                'src_table' => 'nota_reposicion',
                'src_date_col' => 'fecha_nota',
                'src_filters' => [],
                'route_cols' => ['prefijo_carrera'],
                'route_resolver' => $byPrefijo,
                'log_table' => 'nota_reposicion',
                'dest_table' => 'nota_reposicion',
                'dest_date_col' => 'fecha_nota',
                'src_amount_col' => 'monto',
                'dest_amount_col' => 'monto',
                'src_pk_cols' => ['correlativo', 'anio_reposicion', 'cont'],
                'dest_pk_cols' => ['correlativo', 'anio_reposicion', 'cont'],
                'dest_lookup' => 'via_log',
                'source_pk_builder' => fn($r) => "{$r->correlativo}|{$r->anio_reposicion}|{$r->cont}",
                'spot_fields' => [
                    ['monto', 'monto', 'float'],
                    ['concepto_adm', 'concepto_adm', 'string'],
                    ['concepto_est', 'concepto_est', 'string'],
                    ['observaciones', 'observaciones', 'string'],
                    ['prefijo_carrera', 'prefijo_carrera', 'string'],
                    ['anulado', 'anulado', 'bool'],
                    ['anio_reposicion', 'anio_reposicion', 'int'],
                ],
            ],
            'otros_ingresos' => [
                'src_table' => 'otros_ingresos',
                'src_date_col' => 'fecha',
                'src_filters' => [],
                'route_cols' => ['cod_pensum'],
                'route_resolver' => $byPensum,
                'log_table' => 'otros_ingresos',
                'dest_table' => 'otros_ingresos',
                'dest_date_col' => 'fecha',
                'src_amount_col' => 'monto',
                'dest_amount_col' => 'monto',
                'src_pk_cols' => ['num_factura', 'num_recibo', 'nit', 'fecha'],
                'dest_pk_cols' => ['num_factura', 'num_recibo', 'nro_documento_pago', 'fecha'],
                'dest_lookup' => 'via_log',
                'source_pk_builder' => fn($r) => "{$r->num_factura}|{$r->num_recibo}|{$r->nit}|{$r->fecha}",
                'spot_fields' => [
                    ['monto', 'monto', 'float'],
                    ['razon_social', 'razon_social', 'string'],
                    ['observaciones', 'observaciones', 'string'],
                    ['concepto', 'concepto', 'string'],
                    ['gestion', 'gestion', 'string'],
                    ['subtotal', 'subtotal', 'float'],
                    ['descuento', 'descuento', 'float'],
                ],
            ],
        ];
    }
}
