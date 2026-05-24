<?php

namespace App\Services\SgaMigration;

use Illuminate\Support\Facades\DB;

/**
 * Migra cobros MATERIAL_EXTRA → tabla `material_adicional` del SGA.
 *
 * PK en SGA: (cod_ceta, cod_pensum, cod_inscrip, kardex_economico, num_comprobante, num_pago_mat).
 * num_pago_mat = MAX(num_pago_mat)+1 por (cod_inscrip, num_comprobante).
 * nombre_libro e insumo (NOT NULL): se toman de items_cobro.nombre_servicio; fallback: concepto.
 */
class MaterialAdicionalWriter
{
    public function __construct(
        private MapperHelper $mapper,
        private MigrationLog $log,
    ) {}

    public function run(string $from, string $until, bool $dryRun, BatchReport $report): void
    {
        DB::connection(MapperHelper::SOURCE_CONN)->table('cobro')
            ->where('cod_tipo_cobro', 'MATERIAL_EXTRA')
            ->whereBetween('fecha_cobro', ["{$from} 00:00:00", "{$until} 23:59:59"])
            ->whereNotNull('cod_inscrip')
            ->orderBy('fecha_cobro')
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
            $report->record('material_adicional', 'sin_ruta', 'skipped');
            return;
        }

        // cobro.cod_inscrip es la PK interna de sistemaEco.
        // El cod_inscrip real del SGA está en inscripciones.source_cod_inscrip.
        $sgaCodInscrip = $this->mapper->resolveSourceCodInscrip((int) $r->cod_inscrip);
        if ($sgaCodInscrip === null) {
            $this->log->write('cobro_material', (string) $r->nro_cobro, $conn, 'material_adicional', null, 'error',
                "inscripcion eco_id={$r->cod_inscrip} sin source_cod_inscrip");
            $report->record('material_adicional', $conn, 'errors');
            return;
        }

        $sourcePk = (string) $r->nro_cobro;

        if (!$dryRun && $this->log->alreadyDone('cobro_material', $sourcePk, $conn)) {
            $report->record('material_adicional', $conn, 'skipped');
            return;
        }

        if ($dryRun) {
            $report->record('material_adicional', $conn, 'inserted');
            return;
        }

        try {
            $row = $this->buildRow($r, $conn, $sgaCodInscrip);
            DB::connection($conn)->table('material_adicional')->insert($row);
            $destPk = "{$r->cod_ceta}|{$r->cod_pensum}|{$sgaCodInscrip}|{$row['num_comprobante']}|{$row['num_pago_mat']}";
            $this->log->write('cobro_material', $sourcePk, $conn, 'material_adicional', $destPk, 'inserted');
            $report->record('material_adicional', $conn, 'inserted');
        } catch (\Throwable $e) {
            $this->log->write('cobro_material', $sourcePk, $conn, 'material_adicional', null, 'error', $e->getMessage());
            $report->record('material_adicional', $conn, 'errors');
        }
    }

    private function buildRow(object $r, string $conn, int $sgaCodInscrip): array
    {
        $numComprobante = $r->nro_recibo  ? (int) $r->nro_recibo  : 0;
        $numPagMat = $this->mapper->getNextNumPago($conn, 'material_adicional', [
            'cod_inscrip'    => $sgaCodInscrip,
            'num_comprobante'=> $numComprobante,
        ], 'num_pago_mat');

        $nombreServicio = $this->resolveNombreServicio($r);

        $nota    = $this->mapper->getNotaBancaria($r);
        $cuenta  = $this->mapper->getCuentaBancaria($r);

        return [
            'cod_ceta'          => $r->cod_ceta,
            'cod_pensum'        => $r->cod_pensum,
            'cod_inscrip'       => $sgaCodInscrip,
            'kardex_economico'  => $r->tipo_inscripcion,
            'num_comprobante'   => $numComprobante,
            'num_pago_mat'      => $numPagMat,
            'costo_total'       => (float) $r->monto,
            'observaciones'     => $r->observaciones ?: null,
            'fecha_pago'        => $r->fecha_cobro,
            'nombre_libro'      => $nombreServicio,
            'insumo'            => $nombreServicio,
            'pago_completo'     => (bool) $r->cobro_completo,
            'costo_mat_ex'      => (float) $r->monto,
            'costo_libro'       => 0.0,
            'usuario'           => $this->mapper->resolveUsuarioNickname($r->id_usuario),
            'razon'             => null,
            'nro_documento_pago'=> null,
            'autorizacion'      => null,
            'valido'            => 'V',
            'concepto'          => $r->concepto ?: null,
            'num_factura'       => $r->nro_factura ? (int) $r->nro_factura : null,
            'codigo_control'    => null,
            'code_tipo_pago'    => $this->mapper->mapFormaCobro($r->id_forma_cobro),
            'fecha_deposito'    => $nota ? ($nota->fecha_deposito ?: null) : null,
            'nro_cuenta'        => $cuenta ? ($cuenta->nro_cuenta ?? null) : null,
            'nro_deposito'      => $nota ? ($nota->nro_transaccion ?: null) : null,
            'nro_nota'          => null,
            'banco_origen'      => $nota ? ($nota->banco_origen ?: ($cuenta->banco ?? null)) : null,
            'nro_tarjeta'       => $nota ? ($nota->nro_tarjeta ?: null) : null,
            'estado_factura'    => null,
            'id_item_service'   => null,
            'orden'             => $r->order ? (int) $r->order : null,
            'anulado'           => false,
            'fecha_anulacion'   => null,
            'usuario_anula'     => null,
        ];
    }

    private function resolveNombreServicio(object $r): string
    {
        if (!empty($r->id_item)) {
            $nombre = DB::connection(MapperHelper::SOURCE_CONN)->table('items_cobro')
                ->where('id_item', $r->id_item)
                ->value('nombre_servicio');
            if ($nombre) return $nombre;
        }
        return $r->concepto ?: 'Material adicional';
    }
}
