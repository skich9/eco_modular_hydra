<?php

namespace App\Services\SgaMigration;

use Illuminate\Support\Facades\DB;

/**
 * Migra `nota_bancaria` de sistemaEco → SGA.
 *
 * DIFERENCIA ESTRUCTURAL CLAVE (igual que nota_reposicion):
 *   sistemaEco: 1 fila por ítem cobrado (mismo cod_ceta + fecha_nota exacta = misma transacción).
 *   SGA:        1 fila por transacción, con ítems concatenados en concepto
 *               como "Concepto1 Bs{monto1},Concepto2 Bs{monto2}" y monto = SUM().
 *
 * Clave de agrupación: cod_ceta + fecha_nota (timestamp exacto) + anio_deposito + tipo_nota + prefijo_carrera.
 *
 * PK en SGA: (anio_deposito, correlativo, tipo_nota).
 * El correlativo se recalcula MAX(correlativo)+1 filtrando por EXTRACT(YEAR FROM fecha_nota)
 * y tipo_nota, igual que getCorrelativoNotaBancaria() del SGA nativo.
 * anio_deposito se almacena como 2 dígitos (ej. 26 para 2026), igual que el SGA nativo.
 */
class NotaBancariaWriter
{
    public function __construct(
        private MapperHelper $mapper,
        private MigrationLog $log,
    ) {}

    public function run(string $from, string $until, bool $dryRun, BatchReport $report): void
    {
        // Leer todas las filas del rango y agruparlas en PHP por clave de transacción.
        $rawRows = DB::connection(MapperHelper::SOURCE_CONN)->table('nota_bancaria')
            ->whereBetween('fecha_nota', ["{$from} 00:00:00", "{$until} 23:59:59"])
            ->orderBy('anio_deposito')
            ->orderBy('fecha_nota')
            ->orderBy('correlativo')
            ->get();

        // Agrupar por transacción: clave = cod_ceta|fecha_nota|anio_deposito|tipo_nota|prefijo
        $grupos = [];
        foreach ($rawRows as $r) {
            $fechaKey = substr(is_string($r->fecha_nota) ? $r->fecha_nota : (string) $r->fecha_nota, 0, 19);

            $key = implode('|', [
                $r->cod_ceta,
                $fechaKey,
                $r->anio_deposito,
                $r->tipo_nota,
                $r->prefijo_carrera,
            ]);

            if (!isset($grupos[$key])) {
                $grupos[$key] = [
                    'base'       => $r,
                    'monto'      => 0.0,
                    'items'      => [],
                    'source_pks' => [],
                ];
            }

            $grupos[$key]['monto']       += (float) $r->monto;
            $grupos[$key]['items'][]      = [
                'concepto' => $r->concepto,
                'monto'    => (float) $r->monto,
            ];
            $grupos[$key]['source_pks'][] = "{$r->anio_deposito}|{$r->correlativo}|{$r->tipo_nota}";
        }

        foreach ($grupos as $grupo) {
            $this->processGrupo($grupo, $dryRun, $report);
        }
    }

    private function processGrupo(array $grupo, bool $dryRun, BatchReport $report): void
    {
        $r    = $grupo['base'];
        $conn = $this->resolveConn($r);
        if (!$conn) {
            $report->record('nota_bancaria', 'sin_ruta', 'skipped');
            return;
        }

        $sourcePkRepresentante = $grupo['source_pks'][0];

        if (!$dryRun && $this->log->alreadyDone('nota_bancaria', $sourcePkRepresentante, $conn)) {
            $report->record('nota_bancaria', $conn, 'skipped');
            return;
        }

        if ($dryRun) {
            $report->record('nota_bancaria', $conn, 'inserted');
            return;
        }

        try {
            // Replicar getCorrelativoNotaBancaria() del SGA: MAX+1 filtrando por
            // EXTRACT(YEAR FROM fecha_nota) y tipo_nota (no por anio_deposito columna).
            $anioSga = (int) $r->anio_deposito % 100; // 2026 → 26
            $max = DB::connection($conn)->table('nota_bancaria')
                ->whereRaw('EXTRACT(YEAR FROM fecha_nota) = ?', [$r->anio_deposito])
                ->where('tipo_nota', $r->tipo_nota)
                ->max('correlativo');
            $correlativo = (int) $max + 1;

            $row = $this->buildRow($r, $grupo['items'], $grupo['monto'], $correlativo, $anioSga);
            DB::connection($conn)->table('nota_bancaria')->insert($row);

            $destPk = "{$anioSga}|{$correlativo}|{$r->tipo_nota}";

            // Registrar cada PK de origen apuntando al mismo destino (idempotencia completa)
            foreach ($grupo['source_pks'] as $sourcePk) {
                $this->log->write('nota_bancaria', $sourcePk, $conn, 'nota_bancaria', $destPk, 'inserted');
            }

            $report->record('nota_bancaria', $conn, 'inserted');
        } catch (\Throwable $e) {
            foreach ($grupo['source_pks'] as $sourcePk) {
                $this->log->write('nota_bancaria', $sourcePk, $conn, 'nota_bancaria', null, 'error', $e->getMessage());
            }
            $report->record('nota_bancaria', $conn, 'errors');
        }
    }

    /**
     * Enruta al SGA correcto en dos niveles (mismo patrón que NotaReposicionWriter):
     *
     * 1) Con cod_ceta: carácter en posición 5 indica carrera.
     *    Formato: 1{YYYY}{C}{NNN} — C=1 → sga_elec, C=0 → sga_mec.
     *
     * 2) Sin cod_ceta: por usuario.
     *    sga_elec: Isabel, AlejandraR, NicoleS, LuisFC
     *    sga_mec:  JazminB, pamela, DanielM
     */
    private function resolveConn(object $r): ?string
    {
        // Nivel 1: pensum real vía inscripciones (cubre cod_ceta con formato
        // de otra carrera pero pensum actual diferente, ej. 220100039 en EEA).
        if (!empty($r->cod_ceta)) {
            $conn = $this->mapper->resolveConnByCodCeta($r->cod_ceta);
            if ($conn) return $conn;
        }

        // Nivel 2: fallback por usuario (cuando cod_ceta no existe o no está en inscripciones)
        static $elec = ['Isabel', 'AlejandraR', 'NicoleS', 'LuisFC'];
        static $mec  = ['JazminB', 'pamela', 'DanielM'];

        $usuario = trim($r->usuario ?? '');
        if (in_array($usuario, $elec, true)) return 'sga_elec';
        if (in_array($usuario, $mec, true))  return 'sga_mec';

        return null;
    }

    /**
     * Construye la fila para el SGA.
     *
     * concepto: "Mensualidad Bs800.00,Mens. Niv Bs4.00" — igual al formato del SGA legacy.
     * monto:    SUM de todos los ítems del grupo.
     */
    private function buildRow(object $r, array $items, float $montoTotal, int $correlativo, int $anioSga): array
    {
        $concepto    = implode(',', array_map(
            fn($item) => $this->cleanConcepto(trim((string) $item['concepto'])) . ' Bs' . number_format($item['monto'], 2, '.', ''),
            $items
        ));
        $conceptoEst = implode(',', array_map(
            fn($item) => $this->cleanConcepto(trim((string) $item['concepto'])),
            $items
        ));

        return [
            'anio_deposito'   => $anioSga,
            'correlativo'     => $correlativo,
            'usuario'         => $r->usuario ?: 'SIS_ECO',
            'fecha_nota'      => $r->fecha_nota,
            'cod_ceta'        => $r->cod_ceta,
            'monto'           => round($montoTotal, 2),
            'concepto'        => $concepto,
            'nro_factura'     => (string) ($r->nro_factura ?? ''),
            'nro_recibo'      => (string) ($r->nro_recibo ?? ''),
            'banco'           => $r->banco ? trim(explode(' - ', $r->banco)[0]) : null,
            'fecha_deposito'  => $r->fecha_deposito ?: null,
            'nro_transaccion' => $r->nro_transaccion ?: null,
            'prefijo_carrera' => $r->prefijo_carrera ?: null,
            'concepto_est'    => $conceptoEst ?: null,
            'observacion'     => $r->observacion ?: null,
            'anulado'         => (bool) $r->anulado,
            'tipo_nota'       => $r->tipo_nota,
            'banco_origen'    => $r->banco_origen ?: null,
            'nro_tarjeta'     => $r->nro_tarjeta ?: null,
        ];
    }

    private function cleanConcepto(string $concepto): string
    {
        $c = preg_replace('/Cuota \d+\s+/', '', $concepto);
        $c = str_replace(['(', ')'], '', $c);
        $c = preg_replace('/Mensualidad\s*-?\s*/', 'Mens. ', $c);
        return preg_replace('/ {2,}/', ' ', trim($c));
    }
}
