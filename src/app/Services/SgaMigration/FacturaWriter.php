<?php

namespace App\Services\SgaMigration;

use Illuminate\Support\Facades\DB;

/**
 * Migra `factura` de sistemaEco (eco_backup) → SGA (sga_elec / sga_mec).
 *
 * PK en SGA: (num_factura, anio, es_manual).
 * Estrategia de colisión: SKIP (no sobreescribir).
 * Al terminar la corrida real, el comando llama a fixSequence() para actualizar el nextval.
 *
 * Exclusiones explícitas: facturas copiadas manualmente por error → config/sga_migration.php
 */
class FacturaWriter
{
    /**
     * Cache de la lista de exclusión explícita.
     * Estructura: [conn][anio][nro_factura][cod_ceta] = true
     *
     * La clave incluye cod_ceta para no excluir facturas legítimas de otros
     * estudiantes que comparten el mismo nro_factura en la misma conexión.
     * Ver config/sga_migration.php → facturas_excluidas para el detalle.
     */
    private array $excluded = [];

    public function __construct(
        private MapperHelper $mapper,
        private MigrationLog $log,
    ) {
        // Precarga la lista de exclusión desde config para no llamar config() en cada fila.
        // Formato config: [conn][anio][nro_factura] = ['cod_ceta1', 'cod_ceta2', ...]
        foreach (config('sga_migration.facturas_excluidas', []) as $conn => $porAnio) {
            foreach ($porAnio as $anio => $porNro) {
                foreach ($porNro as $nro => $codCetas) {
                    foreach ($codCetas as $codCeta) {
                        $this->excluded[$conn][(int) $anio][(int) $nro][(string) $codCeta] = true;
                    }
                }
            }
        }
    }

    public function run(string $from, string $until, bool $dryRun, BatchReport $report): void
    {
        DB::connection(MapperHelper::SOURCE_CONN)->table('factura')
            ->whereBetween('fecha_emision', ["{$from} 00:00:00", "{$until} 23:59:59"])
            ->orderBy('nro_factura')
            ->chunk(200, function ($rows) use ($dryRun, $report) {
                foreach ($rows as $r) {
                    $this->processOne($r, $dryRun, $report);
                }
            });
    }

    private function processOne(object $r, bool $dryRun, BatchReport $report): void
    {
        $conn = $this->mapper->resolveConnByCodCeta($r->cod_ceta);
        if (!$conn) {
            $report->record('factura', 'sin_ruta', 'skipped');
            return;
        }

        $sourcePk = "{$r->nro_factura}|{$r->anio}|{$r->es_manual}";

        // Exclusión explícita por (conn + nro_factura + anio + cod_ceta):
        // Copia manual detectada para este estudiante específico.
        // El mismo nro_factura puede ser legítimo para otro cod_ceta → no se excluye.
        // Ver config/sga_migration.php → facturas_excluidas para el detalle.
        if (isset($this->excluded[$conn][(int) $r->anio][(int) $r->nro_factura][(string) $r->cod_ceta])) {
            if (!$dryRun && !$this->log->alreadyDone('factura', $sourcePk, $conn)) {
                $this->log->write('factura', $sourcePk, $conn, 'factura', null, 'excluded',
                    "Copia manual en eco_backup (cod_ceta={$r->cod_ceta}); " .
                    'factura ya existe en SGA vía ServiciosOnline con CUF definitivo');
            }
            $report->record('factura', $conn, 'skipped');
            return;
        }

        if (!$dryRun && $this->log->alreadyDone('factura', $sourcePk, $conn)) {
            $report->record('factura', $conn, 'skipped');
            return;
        }

        // Colisión: la PK ya existe en el SGA destino → skip
        $exists = DB::connection($conn)->table('factura')
            ->where('num_factura', (int) $r->nro_factura)
            ->where('anio', (int) $r->anio)
            ->where('es_manual', (bool) $r->es_manual)
            ->exists();

        if ($exists) {
            if (!$dryRun) {
                $this->log->write('factura', $sourcePk, $conn, 'factura', $sourcePk, 'skipped');
            }
            $report->record('factura', $conn, 'skipped');
            return;
        }

        if ($dryRun) {
            $report->record('factura', $conn, 'inserted');
            return;
        }

        try {
            DB::connection($conn)->table('factura')->insert($this->buildRow($r));
            $this->log->write('factura', $sourcePk, $conn, 'factura', $sourcePk, 'inserted');
            $report->record('factura', $conn, 'inserted');
        } catch (\Throwable $e) {
            $this->log->write('factura', $sourcePk, $conn, 'factura', null, 'error', $e->getMessage());
            $report->record('factura', $conn, 'errors');
        }
    }

    private function buildRow(object $r): array
    {
        return [
            'num_factura'         => (int) $r->nro_factura,
            'anio'                => (int) $r->anio,
            'fecha_factura'       => $r->fecha_emision,
            'usuario'             => $this->mapper->resolveUsuarioNickname($r->id_usuario),
            'aceptado_impuestos'  => $r->estado === 'VALIDADA' ? 'T' : (bool) $r->aceptado_impuestos,
            'fecha_envio'         => $r->fecha_emision,
            'estado_factura'      => $r->estado === 'VIGENTE' ? 'Enviado' : ($r->estado ?? 'Enviado'),
            'mensaje_impuestos'   => null,
            'eliminacion_factura' => (bool) $r->eliminacion_factura,
            'ruta_archivo'        => null,
            'codigo_cufd'         => $r->codigo_cufd ?: null,
            'codigo_recepcion'    => $r->codigo_recepcion ?: null,
            'descuento_adicional' => 0,
            'codigo_metodo_pago'  => $this->mapper->mapMetodoPago($r->id_forma_cobro),
            'complemento'         => null,
            'cod_tipo_doc_identidad' => 1,
            'monto_gift_card'     => 0,
            'num_gift_card'       => null,
            'cafc'                => $r->cafc ?: null,
            'tipo_emision'        => $r->codigo_tipo_emision ? (int) $r->codigo_tipo_emision : null,
            'cuf'                 => $r->cuf ?: null,
            'codigo_excepcion'    => $r->codigo_excepcion ? (int) $r->codigo_excepcion : 0,
            'codigo_doc_sector'   => 11,
            'codigo_tipo_emision' => null,
            'periodo_facturado'   => $r->periodo_facturado ?: null,
            'es_manual'           => (bool) $r->es_manual,
            'codigo_evento'       => $r->codigo_evento ?: null,
            'descripcion_evento'  => $r->descripcion_evento ?: null,
        ];
    }

    /** Actualiza la secuencia de num_factura en el SGA para evitar conflictos futuros. */
    public function fixSequence(string $conn): void
    {
        DB::connection($conn)->statement(
            "SELECT setval('factura_num_factura_seq', (SELECT COALESCE(MAX(num_factura), 0) FROM factura))"
        );
    }
}
