<?php

namespace App\Services\SgaMigration;

use Illuminate\Support\Facades\DB;

/**
 * Verificación post-migración (read-only). Cruza tres fuentes por tabla:
 *  1. Origen (eco_backup): cuántos registros del rango se rutean a cada conexión.
 *  2. Log (sga_migration_log): insertados / saltados / con error por conexión.
 *  3. SGA: estado de las secuencias de factura/recibo (last_value vs MAX).
 *
 * No escribe nada. Devuelve un arreglo estructurado para que el comando lo muestre.
 */
class VerifyMigrationService
{
    public function __construct(private MapperHelper $mapper) {}

    public function run(string $from, string $until): array
    {
        return [
            'cobertura'   => $this->checkCobertura($from, $until),
            'log'         => $this->checkLog(),
            'secuencias'  => $this->checkSecuencias(),
            'errores'     => $this->listErrores(),
        ];
    }

    /**
     * Cuenta el origen por tabla/conexión y lo compara con lo registrado en el log
     * (insertados + saltados). Si origen != procesado, hay filas que no pasaron.
     */
    private function checkCobertura(string $from, string $until): array
    {
        $f = "{$from} 00:00:00";
        $u = "{$until} 23:59:59";
        $out = [];

        // Mapa: tabla destino => [source_table_en_log, contador de origen por conexión]
        $out['factura']    = $this->coberturaFactura($f, $u);
        $out['recibo']     = $this->coberturaRecibo($f, $u);
        $out['pago']       = $this->coberturaCobro(['MENSUALIDAD', 'ARRASTRE'], 'cobro_pago', $f, $u);
        $out['pago_multa'] = $this->coberturaCobro(['MORA', 'NIVELACION'], 'cobro_pago_multa', $f, $u);
        $out['material_adicional'] = $this->coberturaCobro(['MATERIAL_EXTRA'], 'cobro_material', $f, $u);
        $out['nota_bancaria']   = $this->coberturaPorPrefijo('nota_bancaria', 'fecha_nota', 'nota_bancaria', $f, $u);
        $out['nota_reposicion'] = $this->coberturaPorPrefijo('nota_reposicion', 'fecha_nota', 'nota_reposicion', $f, $u);
        $out['otros_ingresos']  = $this->coberturaOtrosIngresos($f, $u);

        return $out;
    }

    private function coberturaFactura(string $f, string $u): array
    {
        $origen = ['sga_elec' => 0, 'sga_mec' => 0, 'sin_ruta' => 0];
        DB::connection(MapperHelper::SOURCE_CONN)->table('factura')
            ->select('cod_ceta')->whereBetween('fecha_emision', [$f, $u])
            ->orderBy('nro_factura')
            ->chunk(1000, function ($rows) use (&$origen) {
                foreach ($rows as $r) {
                    $conn = $this->mapper->resolveConnByCodCeta($r->cod_ceta);
                    $origen[$conn ?? 'sin_ruta']++;
                }
            });
        return $this->withLog($origen, 'factura');
    }

    private function coberturaRecibo(string $f, string $u): array
    {
        $origen = ['sga_elec' => 0, 'sga_mec' => 0, 'sin_ruta' => 0];
        DB::connection(MapperHelper::SOURCE_CONN)->table('recibo')
            ->select('cod_ceta')->whereBetween('created_at', [$f, $u])
            ->orderBy('nro_recibo')
            ->chunk(1000, function ($rows) use (&$origen) {
                foreach ($rows as $r) {
                    $conn = $this->mapper->resolveConnByCodCeta($r->cod_ceta);
                    $origen[$conn ?? 'sin_ruta']++;
                }
            });
        return $this->withLog($origen, 'recibo');
    }

    private function coberturaCobro(array $tipos, string $logTable, string $f, string $u): array
    {
        $origen = ['sga_elec' => 0, 'sga_mec' => 0, 'sin_ruta' => 0];
        DB::connection(MapperHelper::SOURCE_CONN)->table('cobro')
            ->select('cod_pensum')->whereIn('cod_tipo_cobro', $tipos)
            ->whereBetween('fecha_cobro', [$f, $u])->whereNotNull('cod_inscrip')
            ->orderBy('fecha_cobro')
            ->chunk(1000, function ($rows) use (&$origen) {
                foreach ($rows as $r) {
                    $conn = $this->mapper->resolveConnectionByPensum($r->cod_pensum);
                    $origen[$conn ?? 'sin_ruta']++;
                }
            });
        return $this->withLog($origen, $logTable);
    }

    private function coberturaPorPrefijo(string $table, string $dateCol, string $logTable, string $f, string $u): array
    {
        $origen = ['sga_elec' => 0, 'sga_mec' => 0, 'sin_ruta' => 0];
        DB::connection(MapperHelper::SOURCE_CONN)->table($table)
            ->select('prefijo_carrera')->whereBetween($dateCol, [$f, $u])
            ->orderBy($dateCol)
            ->chunk(1000, function ($rows) use (&$origen) {
                foreach ($rows as $r) {
                    $conn = $this->mapper->resolveConnectionByPrefijo($r->prefijo_carrera);
                    $origen[$conn ?? 'sin_ruta']++;
                }
            });
        return $this->withLog($origen, $logTable);
    }

    private function coberturaOtrosIngresos(string $f, string $u): array
    {
        $origen = ['sga_elec' => 0, 'sga_mec' => 0, 'sin_ruta' => 0];
        DB::connection(MapperHelper::SOURCE_CONN)->table('otros_ingresos')
            ->select('cod_pensum')->whereBetween('fecha', [$f, $u])
            ->orderBy('id')
            ->chunk(1000, function ($rows) use (&$origen) {
                foreach ($rows as $r) {
                    $conn = $this->mapper->resolveConnectionByPensum($r->cod_pensum);
                    $origen[$conn ?? 'sin_ruta']++;
                }
            });
        return $this->withLog($origen, 'otros_ingresos');
    }

    /** Adjunta el conteo del log (procesado=inserted+skipped) a cada conexión y marca el delta. */
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

    /** Totales del log por tabla destino y estado. */
    private function checkLog(): array
    {
        return DB::table('sga_migration_log')
            ->select('dest_table', 'dest_conn', 'status', DB::raw('count(*) as total'))
            ->groupBy('dest_table', 'dest_conn', 'status')
            ->orderBy('dest_table')->orderBy('dest_conn')
            ->get()->toArray();
    }

    /** ¿La secuencia de factura/recibo quedó >= al MAX de la tabla? */
    private function checkSecuencias(): array
    {
        $out = [];
        $checks = [
            ['factura', 'num_factura', 'factura_num_factura_seq'],
            ['recibo',  'num_recibo',  'recibo_num_recibo_seq'],
        ];
        foreach (['sga_elec', 'sga_mec'] as $conn) {
            foreach ($checks as [$table, $col, $seq]) {
                try {
                    $max = (int) DB::connection($conn)->table($table)->max($col);
                    $last = (int) (DB::connection($conn)->selectOne("SELECT last_value FROM {$seq}")->last_value ?? 0);
                    $out[] = [
                        'conn'  => $conn,
                        'tabla' => $table,
                        'max'   => $max,
                        'seq'   => $last,
                        'ok'    => $last >= $max,
                    ];
                } catch (\Throwable $e) {
                    $out[] = ['conn' => $conn, 'tabla' => $table, 'max' => '—', 'seq' => '—', 'ok' => false, 'error' => $e->getMessage()];
                }
            }
        }
        return $out;
    }

    /** Filas con status=error en el log (las que fallaron al insertar). */
    private function listErrores(): array
    {
        return DB::table('sga_migration_log')
            ->where('status', 'error')
            ->select('source_table', 'source_pk', 'dest_conn', 'dest_table', 'error_message', 'pushed_at')
            ->orderByDesc('pushed_at')
            ->limit(50)
            ->get()->toArray();
    }
}
