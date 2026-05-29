<?php

namespace App\Services\SgaMigration;

use Illuminate\Support\Facades\DB;

/**
 * Migra `nota_reposicion` de sistemaEco → SGA.
 *
 * DIFERENCIA ESTRUCTURAL CLAVE:
 *   sistemaEco: 1 fila por ítem cobrado (mismo cod_ceta + fecha_nota exacta = misma transacción).
 *   SGA:        1 fila por transacción, con ítems concatenados en concepto_adm
 *               como "Concepto1 Bs{monto1},Concepto2 Bs{monto2}" y monto = SUM().
 *
 * Clave de agrupación: cod_ceta + fecha_nota (timestamp exacto) + anio_reposicion + cont + prefijo_carrera.
 * Cuando hay nro_recibo presente se incluye también en la clave para mayor precisión.
 *
 * PK en SGA: (correlativo, anio_reposicion, cont).
 * El correlativo se recalcula MAX+1 por anio_reposicion en el destino; cont=0.
 */
class NotaReposicionWriter
{
    public function __construct(
        private MapperHelper $mapper,
        private MigrationLog $log,
    ) {}

    public function run(string $from, string $until, bool $dryRun, BatchReport $report): void
    {
        // Leer todas las filas del rango y agruparlas en PHP por clave de transacción.
        // No se puede hacer GROUP BY en SQL porque necesitamos mantener todos los metadatos
        // de la primera fila (usuario, observaciones, nro_recibo, tipo_ingreso).
        $rawRows = DB::connection(MapperHelper::SOURCE_CONN)->table('nota_reposicion')
            ->whereBetween('fecha_nota', ["{$from} 00:00:00", "{$until} 23:59:59"])
            ->whereNotNull('nro_recibo')        // solo notas ligadas a un recibo; las de factura no se sincronizan
            ->orderBy('anio_reposicion')
            ->orderBy('fecha_nota')
            ->orderBy('correlativo')
            ->get();

        // Agrupar filas por transacción: clave = cod_ceta|fecha_nota|anio|cont|prefijo
        $grupos = [];
        foreach ($rawRows as $r) {
            $fechaKey = is_string($r->fecha_nota)
                ? $r->fecha_nota
                : (string) $r->fecha_nota;

            // Normalizar a segundos (truncar microsegundos si los hubiera)
            $fechaKey = substr($fechaKey, 0, 19);

            $key = implode('|', [
                $r->cod_ceta,
                $fechaKey,
                $r->anio_reposicion,
                $r->cont,
                $r->prefijo_carrera,
            ]);

            if (!isset($grupos[$key])) {
                // Primera fila del grupo: guarda metadatos base
                $grupos[$key] = [
                    'base'      => $r,
                    'monto'     => 0.0,
                    'items'     => [],      // [['concepto' => '...', 'monto' => x], ...]
                    'source_pks'=> [],      // PKs de origen para el log de idempotencia
                ];
            }

            $grupos[$key]['monto']      += (float) $r->monto;
            $grupos[$key]['items'][]     = [
                'concepto' => $r->concepto_adm,
                'monto'    => (float) $r->monto,
            ];
            $grupos[$key]['source_pks'][] = "{$r->correlativo}|{$r->anio_reposicion}|{$r->cont}";
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
            $report->record('nota_reposicion', 'sin_ruta', 'skipped');
            return;
        }

        // Clave de idempotencia: usamos la PK de la primera fila del grupo como representante.
        // Todas las PKs de origen del grupo se registran en el log apuntando al mismo destino.
        $sourcePkRepresentante = $grupo['source_pks'][0];

        if (!$dryRun && $this->log->alreadyDone('nota_reposicion', $sourcePkRepresentante, $conn)) {
            $report->record('nota_reposicion', $conn, 'skipped');
            return;
        }

        if ($dryRun) {
            $report->record('nota_reposicion', $conn, 'inserted');
            return;
        }

        try {
            $correlativo = $this->mapper->getNextNumPago($conn, 'nota_reposicion', [
                'anio_reposicion' => (int) $r->anio_reposicion,
            ], 'correlativo');

            // cont = MAX(cont existente para este correlativo) + 1.
            // cont=0 puede repetirse para el mismo correlativo (registros sin nro_recibo),
            // por eso se usa MAX en lugar de COUNT para no contarlos como parte de la secuencia.
            // Sin cod_ceta (tipo_ingreso especial): cont = 0.
            $cont = !empty($r->cod_ceta)
                ? (int) DB::connection($conn)->table('nota_reposicion')
                    ->where('correlativo', $correlativo)
                    ->max('cont') + 1
                : 0;

            $row = $this->buildRow($r, $grupo['items'], $grupo['monto'], $correlativo, $cont);
            DB::connection($conn)->table('nota_reposicion')->insert($row);

            $destPk = "{$correlativo}|{$r->anio_reposicion}|{$cont}";

            // Registrar cada PK de origen apuntando al mismo destino (idempotencia completa)
            foreach ($grupo['source_pks'] as $sourcePk) {
                $this->log->write('nota_reposicion', $sourcePk, $conn, 'nota_reposicion', $destPk, 'inserted');
            }

            $report->record('nota_reposicion', $conn, 'inserted');
        } catch (\Throwable $e) {
            foreach ($grupo['source_pks'] as $sourcePk) {
                $this->log->write('nota_reposicion', $sourcePk, $conn, 'nota_reposicion', null, 'error', $e->getMessage());
            }
            $report->record('nota_reposicion', $conn, 'errors');
        }
    }

    /**
     * Enruta al SGA correcto en dos niveles:
     *
     * 1) Con cod_ceta: el carácter en posición 5 indica carrera.
     *    Formato cod_ceta: 1{YYYY}{C}{NNN}  — C=1 → sga_elec, C=0 → sga_mec.
     *
     * 2) Sin cod_ceta (pagos especiales con tipo_ingreso presente): por usuario.
     *    sga_elec: Isabel, AlejandraR, NicoleS, LuisFC
     *    sga_mec:  JazminB, pamela, DanielM
     */
    private function resolveConn(object $r): ?string
    {
        // Nivel 1: pensum real vía inscripciones (cubre cod_ceta con formato
        // de otra carrera pero pensum actual diferente).
        if (!empty($r->cod_ceta)) {
            $conn = $this->mapper->resolveConnByCodCeta($r->cod_ceta);
            if ($conn) return $conn;
        }

        // Nivel 2: fallback por usuario
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
     * concepto_adm: "Concepto1 Bs{monto1},Concepto2 Bs{monto2}" — igual al formato del SGA legacy.
     * monto:        SUM de todos los ítems del grupo.
     */
    private function buildRow(object $r, array $items, float $montoTotal, int $correlativo, int $cont): array
    {
        $conceptoAdm = implode(',', array_map(
            fn($item) => $this->cleanConcepto(trim($item['concepto'])) . ' Bs' . number_format($item['monto'], 2, '.', ''),
            $items
        ));
        $conceptoEst = implode(',', array_map(
            fn($item) => $this->cleanConcepto(trim($item['concepto'])),
            $items
        ));

        return [
            'correlativo'     => $correlativo,
            'usuario'         => $r->usuario ?: 'SIS_ECO',
            'cod_ceta'        => $r->cod_ceta,
            'monto'           => round($montoTotal, 2),
            'concepto_adm'    => $conceptoAdm,
            'fecha_nota'      => $r->fecha_nota,
            'concepto_est'    => $conceptoEst,
            'observaciones'   => $r->observaciones ?: null,
            'prefijo_carrera' => $r->prefijo_carrera,
            'anulado'         => (bool) $r->anulado,
            'anio_reposicion' => (int) $r->anio_reposicion,
            'nro_recibo'      => $r->nro_recibo ?: null,
            'tipo_ingreso'    => $r->tipo_ingreso ?: null,
            'cont'            => $cont,
        ];
    }

    private function cleanConcepto(string $concepto): string
    {
        $c = preg_replace('/Cuota \d+\s+/', '', $concepto);
        $c = str_replace(['(', ')'], '', $c);
        return preg_replace('/\s{2,}/', ' ', trim($c));
    }
}
