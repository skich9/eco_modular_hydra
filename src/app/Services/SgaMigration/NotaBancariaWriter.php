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
 * El correlativo se recalcula MAX+1 por (anio_deposito, tipo_nota) en el destino para no
 * chocar con notas preexistentes del SGA.
 */
class NotaBancariaWriter
{
    public function __construct(
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
            $correlativo  = (int) $r->correlativo + 10000;
            $anioDeposito = (int) $r->anio_deposito % 100;

            $row = $this->buildRow($r, $grupo['items'], $grupo['monto'], $correlativo, $anioDeposito);
            DB::connection($conn)->table('nota_bancaria')->insert($row);

            $destPk = "{$anioDeposito}|{$correlativo}|{$r->tipo_nota}";

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
        if (!empty($r->cod_ceta)) {
            $cod = (string) $r->cod_ceta;
            if (strlen($cod) >= 6) {
                if ($cod[5] === '1') return 'sga_elec';
                if ($cod[5] === '0') return 'sga_mec';
            }
        }

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
    private function buildRow(object $r, array $items, float $montoTotal, int $correlativo, int $anioDeposito): array
    {
        // Formato SGA: "Concepto1 Bs800.00,Concepto2 Bs4.00"
        $concepto = implode(',', array_map(
            fn($item) => trim((string) $item['concepto']) . ' Bs' . number_format($item['monto'], 2, '.', ''),
            $items
        ));

        // concepto_est: solo nombres sin montos
        $conceptoEst = implode(',', array_map(
            fn($item) => trim((string) $item['concepto']),
            $items
        ));

        return [
            'anio_deposito'   => $anioDeposito,
            'correlativo'     => $correlativo,
            'usuario'         => $r->usuario ?: 'SIS_ECO',
            'fecha_nota'      => $r->fecha_nota,
            'cod_ceta'        => $r->cod_ceta,
            'monto'           => round($montoTotal, 2),
            'concepto'        => $concepto,
            'nro_factura'     => (string) ($r->nro_factura ?? ''),
            'nro_recibo'      => (string) ($r->nro_recibo ?? ''),
            'banco'           => $r->banco ?: null,
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
}
