<?php

namespace App\Services\SgaMigration;

use Illuminate\Support\Facades\DB;

/**
 * Migra `recepcion_ingresos` + `recepcion_ingresos_detalle` de sistemaEco → SGA.
 *
 * Destino: tablas `recepcion` + `detalle_recepcion` (relación 1:1 por id_recepcion).
 * Enrutamiento: codigo_carrera empieza con 'E' → sga_elec, 'M' → sga_mec.
 * PK en SGA: id_recepcion = MAX(id_recepcion)+1 en destino.
 */
class RecepcionIngresosWriter
{
    public function __construct(
        private MapperHelper $mapper,
        private MigrationLog $log,
    ) {}

    public function run(string $from, string $until, bool $dryRun, BatchReport $report): void
    {
        DB::connection(MapperHelper::SOURCE_CONN)->table('recepcion_ingresos')
            ->whereBetween('fecha_recepcion', [$from, $until])
            ->orderBy('fecha_recepcion')
            ->orderBy('id')
            ->chunk(200, function ($rows) use ($dryRun, $report) {
                foreach ($rows as $r) {
                    $this->processOne($r, $dryRun, $report);
                }
            });
    }

    private function processOne(object $r, bool $dryRun, BatchReport $report): void
    {
        $conn = str_starts_with($r->codigo_carrera, 'E') ? 'sga_elec'
              : (str_starts_with($r->codigo_carrera, 'M') ? 'sga_mec' : null);

        if (!$conn) {
            $report->record('recepcion', 'sin_ruta', 'skipped');
            return;
        }

        $sourcePk = (string) $r->id;

        if (!$dryRun && $this->log->alreadyDone('recepcion_ingresos', $sourcePk, $conn)) {
            $report->record('recepcion', $conn, 'skipped');
            return;
        }

        if ($dryRun) {
            $report->record('recepcion', $conn, 'inserted');
            return;
        }

        try {
            $idRecepcion = $this->mapper->getNextNumPago($conn, 'recepcion', [], 'id_recepcion');

            DB::connection($conn)->transaction(function () use ($r, $conn, $idRecepcion) {
                DB::connection($conn)->table('recepcion')->insert($this->buildHeader($r, $idRecepcion));

                $detalles = DB::connection(MapperHelper::SOURCE_CONN)
                    ->table('recepcion_ingreso_detalles')
                    ->where('recepcion_ingreso_id', $r->id)
                    ->get();

                foreach ($detalles as $detalle) {
                    DB::connection($conn)->table('detalle_recepcion')->insert(
                        $this->buildDetalle($detalle, $idRecepcion)
                    );
                }
            });

            $destPk = (string) $idRecepcion;
            $this->log->write('recepcion_ingresos', $sourcePk, $conn, 'recepcion', $destPk, 'inserted');
            $report->record('recepcion', $conn, 'inserted');
        } catch (\Throwable $e) {
            $this->log->write('recepcion_ingresos', $sourcePk, $conn, 'recepcion', null, 'error', $e->getMessage());
            $report->record('recepcion', $conn, 'errors');
        }
    }

    /**
     * Resuelve el id_usuario del SGA a partir del nombre completo almacenado
     * en recepcion_ingreso_detalles.usuario_libro (ej. "Isabel Arce Perez").
     * El nickname de eco coincide con el id_usuario del SGA.
     */
    private function resolveUsuarioLibro(?string $nombreCompleto): ?string
    {
        if (!$nombreCompleto) return null;
        static $cache = [];
        if (array_key_exists($nombreCompleto, $cache)) return $cache[$nombreCompleto];

        $nickname = DB::connection(MapperHelper::SOURCE_CONN)
            ->table('usuarios')
            ->whereRaw("CONCAT(nombre, ' ', ap_paterno, ' ', ap_materno) = ?", [trim($nombreCompleto)])
            ->value('nickname');

        return $cache[$nombreCompleto] = $nickname ?: null;
    }

    private function buildHeader(object $r, int $idRecepcion): array
    {
        return [
            'id_recepcion'          => $idRecepcion,
            'fecha_recepcion'       => $r->fecha_recepcion,
            'fecha_registro'        => $r->fecha_registro,
            'usuario_recibi1'       => $r->usuario_recibi1,
            'usuario_entregue1'     => $r->usuario_entregue1,
            'usuario_recibi2'       => $r->usuario_recibi2 ?: null,
            'usuario_entregue2'     => $r->usuario_entregue2 ?: null,
            'usuario'               => $r->usuario_registro,
            'cod_documento'         => $r->cod_documento,
            'num_documento'         => (int) $r->num_documento,
            'observacion'           => $r->observacion ?: null,
            'monto_total'           => $r->monto_total ? (float) $r->monto_total : null,
            'anulado'               => (bool) $r->anulado,
            'motivo_anulacion'      => $r->motivo_anulacion ?: null,
            'id_actividad_economica'=> $r->id_actividad_economica ? (int) $r->id_actividad_economica : null,
            'esingresolibrodia'     => (bool) $r->es_ingreso_libro_diario,
        ];
    }

    private function buildDetalle(object $d, int $idRecepcion): array
    {
        return [
            'id_recepcion'      => $idRecepcion,
            'usuario_libro'     => $this->resolveUsuarioLibro($d->usuario_libro ?? null),
            'cod_libro_diario'  => $d->cod_libro_diario ?: null,
            'fecha_inicial_libros' => $d->fecha_inicial_libros ?: null,
            'fecha_final_libros'   => $d->fecha_final_libros,
            'total_deposito'    => $d->total_deposito ? (float) $d->total_deposito : null,
            'total_traspaso'    => $d->total_traspaso ? (float) $d->total_traspaso : null,
            'total_recibos'     => $d->total_recibos ? (float) $d->total_recibos : null,
            'total_facturas'    => $d->total_facturas ? (float) $d->total_facturas : null,
            'total_entregado'   => $d->total_entregado ? (float) $d->total_entregado : null,
            'faltante_sobrante' => $d->faltante_sobrante ? (float) $d->faltante_sobrante : null,
        ];
    }
}
