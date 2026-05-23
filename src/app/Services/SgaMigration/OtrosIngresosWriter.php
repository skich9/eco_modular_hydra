<?php

namespace App\Services\SgaMigration;

use Illuminate\Support\Facades\DB;

/**
 * Migra `otros_ingresos` (+ `otros_ingresos_detalle`) de sistemaEco → SGA.
 *
 * PK en SGA: (num_factura, num_recibo, nro_documento_pago, fecha) en ambas tablas.
 * Ruteo por cod_pensum (EEA*→sga_elec, 04-MTZ*→sga_mec).
 * nro_documento_pago = nit del cliente (NOT NULL en SGA). valido 'S'→'V'.
 * El detalle se enlaza por otro_ingreso_id → otros_ingresos.id.
 *
 * Nota: codigo_tipo_documento queda en NULL (nullable) — verificar en SGA si requiere
 * un código específico según factura/recibo.
 */
class OtrosIngresosWriter
{
    public function __construct(
        private MapperHelper $mapper,
        private MigrationLog $log,
    ) {}

    public function run(string $from, string $until, bool $dryRun, BatchReport $report): void
    {
        DB::connection(MapperHelper::SOURCE_CONN)->table('otros_ingresos')
            ->whereBetween('fecha', ["{$from} 00:00:00", "{$until} 23:59:59"])
            ->orderBy('id')
            ->chunk(200, function ($rows) use ($dryRun, $report) {
                foreach ($rows as $r) {
                    $this->processOne($r, $dryRun, $report);
                }
            });
    }

    private function processOne(object $r, bool $dryRun, BatchReport $report): void
    {
        $conn = $this->mapper->resolveConnectionByPensum($r->cod_pensum);
        if (!$conn) {
            $report->record('otros_ingresos', 'sin_ruta', 'skipped');
            return;
        }

        $sourcePk = (string) $r->id;

        if (!$dryRun && $this->log->alreadyDone('otros_ingresos', $sourcePk, $conn)) {
            $report->record('otros_ingresos', $conn, 'skipped');
            return;
        }

        if ($dryRun) {
            $report->record('otros_ingresos', $conn, 'inserted');
            return;
        }

        try {
            DB::connection($conn)->transaction(function () use ($r, $conn) {
                DB::connection($conn)->table('otros_ingresos')->insert($this->buildHeader($r));

                $detalles = DB::connection(MapperHelper::SOURCE_CONN)->table('otros_ingresos_detalle')
                    ->where('otro_ingreso_id', $r->id)->get();
                foreach ($detalles as $d) {
                    DB::connection($conn)->table('otros_ingresos_detalle')->insert($this->buildDetalle($r, $d));
                }
            });

            $destPk = "{$r->num_factura}|{$r->num_recibo}|{$r->nit}|{$r->fecha}";
            $this->log->write('otros_ingresos', $sourcePk, $conn, 'otros_ingresos', $destPk, 'inserted');
            $report->record('otros_ingresos', $conn, 'inserted');
        } catch (\Throwable $e) {
            $this->log->write('otros_ingresos', $sourcePk, $conn, 'otros_ingresos', null, 'error', $e->getMessage());
            $report->record('otros_ingresos', $conn, 'errors');
        }
    }

    private function buildHeader(object $r): array
    {
        return [
            'num_factura'           => (int) $r->num_factura,
            'num_recibo'            => (int) $r->num_recibo,
            'razon_social'          => $r->razon_social ?: 'S/N',
            'nro_documento_pago'    => (string) ($r->nit ?: '0'),
            'autorizacion'          => (string) ($r->autorizacion ?: '0'),
            'fecha'                 => $r->fecha,
            'monto'                 => (float) $r->monto,
            'valido'                => $r->valido === 'S' ? 'V' : ($r->valido ?: 'V'),
            'usuario'               => $r->usuario ?: 'SIS_ECO',
            'observaciones'         => $r->observaciones ?: null,
            'concepto'              => $r->concepto ?: null,
            'cod_pensum'            => $r->cod_pensum,
            'gestion'               => $r->gestion,
            'codigo_control'        => $r->codigo_control ?: null,
            'subtotal'              => (float) $r->subtotal,
            'descuento'             => (float) $r->descuento,
            'code_tipo_pago'        => $this->mapper->mapFormaCobro($r->code_tipo_pago),
            'tipo_ingreso'          => $r->tipo_ingreso ?: null,
            'codigo_tipo_documento' => null,
            'estado_factura'        => null,
            'id_item_service'       => null,
        ];
    }

    private function buildDetalle(object $r, object $d): array
    {
        return [
            'num_factura'           => (int) $r->num_factura,
            'num_recibo'            => (int) $r->num_recibo,
            'nro_documento_pago'    => (string) ($r->nit ?: '0'),
            'fecha'                 => $r->fecha,
            'nro_deposito'          => $d->nro_deposito ?: null,
            'fecha_deposito'        => $d->fecha_deposito ?: null,
            'fecha_ini'             => $d->fecha_ini ?: null,
            'fecha_fin'             => $d->fecha_fin ?: null,
            'nro_orden'             => $d->nro_orden ?: null,
            'concepto_alquiler'     => $d->concepto_alquiler ?: null,
            'cta_banco'             => $d->cta_banco ?: null,
            'codigo_tipo_documento' => null,
        ];
    }
}
