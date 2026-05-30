<?php

namespace App\Services\SgaMigration;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Migra cobros MATERIAL_EXTRA → tabla `material_adicional` del SGA.
 *
 * PK en SGA: (cod_ceta, cod_pensum, cod_inscrip, kardex_economico, num_comprobante, num_pago_mat).
 * num_pago_mat proviene de la secuencia PostgreSQL material_adicional_num_pago_mat_seq.
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
        $numComprobante = $r->nro_recibo ? (int) $r->nro_recibo : 0;
        $numPagMat = (int) DB::connection($conn)
            ->selectOne("SELECT nextval('material_adicional_num_pago_mat_seq'::regclass) as val")
            ->val;

        $nota             = $this->mapper->getNotaBancaria($r);
        $nroNotaSga       = $r->nro_recibo ? (int) $r->nro_recibo : 0;
        $cuenta           = $this->mapper->getCuentaBancaria($r);
        $esQr             = strtoupper($r->id_forma_cobro ?? '') === 'B' && $this->mapper->isQrPayment($r);
        $qrTransaccion    = $esQr ? $this->mapper->getQrTransaccion($r) : null;
        $qrRespuestaBanco = ($esQr && $qrTransaccion) ? $this->mapper->getQrRespuestaBanco($qrTransaccion) : null;
        $banking          = $this->resolveBanking($r, $nota, $cuenta, $esQr, $qrTransaccion, $qrRespuestaBanco);
        $clienteDoc       = $this->mapper->resolveClienteDoc($r);

        return [
            'cod_ceta'          => $r->cod_ceta,
            'cod_pensum'        => $r->cod_pensum,
            'cod_inscrip'       => $sgaCodInscrip,
            'kardex_economico'  => $r->tipo_inscripcion,
            'num_comprobante'   => $numComprobante,
            'num_pago_mat'      => $numPagMat,
            'costo_total'       => (float) $r->monto,
            'observaciones'     => $this->mapper->resolveObservacionesPago($r, $banking, $nota, $esQr),
            'fecha_pago'        => $r->fecha_cobro,
            'nombre_libro'      => '',
            'insumo'            => '',
            'pago_completo'     => 't',
            'costo_mat_ex'      => 0,
            'costo_libro'       => 0.0,
            'usuario'           => $this->mapper->resolveUsuarioNickname($r->id_usuario),
            'razon'             => $clienteDoc['cliente'],
            'nro_documento_pago'=> $clienteDoc['nro_documento_cobro'],
            'autorizacion'      => 0,
            'valido'            => 'V',
            'concepto'          => $r->concepto ?: null,
            'num_factura'       => $r->nro_factura ? (int) $r->nro_factura : 0,
            'codigo_control'    => 0,
            'code_tipo_pago'    => $this->mapper->mapFormaCobro($r->id_forma_cobro),
            'fecha_deposito'    => $banking['fecha_deposito'],
            'nro_cuenta'        => $banking['nro_cuenta'],
            'nro_deposito'      => $banking['nro_deposito'],
            'nro_nota'          => $nroNotaSga,
            'banco_origen'      => $banking['banco_origen'],
            'nro_tarjeta'       => $banking['nro_tarjeta'],
            'estado_factura'    => null,
            'id_item_service'   => $this->resolveItemService($conn, $r->id_item ? (int) $r->id_item : null),
            'orden'             => $r->order !== null ? (int) $r->order : null,
            'anulado'           => false,
            'fecha_anulacion'   => null,
            'usuario_anula'     => null,
        ];
    }

    private function resolveBanking(
        object  $r,
        ?object $nota,
        ?object $cuenta,
        bool    $esQr,
        ?object $qrTransaccion,
        ?object $qrRespuestaBanco
    ): array {
        if ($esQr) {
            $fechaRaw      = ($qrTransaccion->processed_at ?? null)
                          ?: ($qrRespuestaBanco->fecha_respuesta ?? null);
            $fechaDeposito = $fechaRaw ? Carbon::parse($fechaRaw)->format('Y-m-d') : null;
        } else {
            $fechaDeposito = $nota ? ($nota->fecha_deposito ?: null) : null;
        }

        $nroDeposito = $esQr
            ? (mb_substr($qrRespuestaBanco->numeroordenoriginante ?? '', 0, 50) ?: null)
            : ($nota ? (mb_substr($nota->nro_transaccion ?? '', 0, 35) ?: null) : null);

        $bancoOrigen = (!$esQr && in_array(strtoupper($r->id_forma_cobro ?? ''), ['B', 'L', 'T']))
            ? ($nota ? (mb_substr($nota->banco_origen ?? '', 0, 200) ?: null) : null)
            : null;

        $nroTarjeta = $nota ? (mb_substr($nota->nro_tarjeta ?? '', 0, 200) ?: null) : null;

        return [
            'fecha_deposito' => $fechaDeposito,
            'nro_cuenta'     => $cuenta ? ($cuenta->numero_cuenta ?? null) : null,
            'nro_deposito'   => $nroDeposito,
            'banco_origen'   => $bancoOrigen,
            'nro_tarjeta'    => $nroTarjeta,
        ];
    }

    private function resolveItemService(string $conn, ?int $idItem): ?int
    {
        static $cache = [];
        if ($idItem === null) return null;
        $cacheKey = "{$conn}:{$idItem}";
        if (array_key_exists($cacheKey, $cache)) return $cache[$cacheKey];

        $nombre = DB::connection(MapperHelper::SOURCE_CONN)
            ->table('items_cobro')
            ->where('id_item', $idItem)
            ->value('nombre_servicio');

        if (!$nombre) {
            return $cache[$cacheKey] = null;
        }

        $found = DB::connection($conn)
            ->table('sin_item_service')
            ->where('nombre_servicio', trim((string) $nombre))
            ->value('id_item');

        return $cache[$cacheKey] = $found !== null ? (int) $found : null;
    }
}
