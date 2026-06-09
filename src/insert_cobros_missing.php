<?php
// Insertar SOLO los pagos faltantes para:
//   cobro 9266 → pago en sga_mec (120260168, Mensualidad Abril, efectivo)
//   cobro 9270 → pago en sga_elec (220241049, Mensualidad Abril, QR)
// Ejecutar con:
// docker compose exec angular_laravel_php php artisan tinker < insert_cobros_missing.php

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

$src = 'eco_backup';

// ─── Lookups básicos ──────────────────────────────────────────────────
$nick20 = DB::connection($src)->table('usuarios')->where('id_usuario', 20)->value('nickname') ?? 'SIS_ECO';
$nick3  = DB::connection($src)->table('usuarios')->where('id_usuario',  3)->value('nickname') ?? 'SIS_ECO';

$fac2596 = DB::connection($src)->table('factura')->where('nro_factura', 2596)->where('anio', 2026)->first();
$fac2599 = DB::connection($src)->table('factura')->where('nro_factura', 2599)->where('anio', 2026)->first();

$asig8372     = DB::connection($src)->table('asignacion_costos')->where('id_asignacion_costo', 8372)->first();
$asig3140     = DB::connection($src)->table('asignacion_costos')->where('id_asignacion_costo', 3140)->first();
$numCuota9266 = $asig8372 ? (int) $asig8372->numero_cuota : 3;
$numCuota9270 = $asig3140 ? (int) $asig3140->numero_cuota : 3;

// ─── QR para cobro 9270 ───────────────────────────────────────────────
$qrAlias      = '22024104919120128042026';
$qrTxn        = DB::connection($src)->table('qr_transacciones')->where('alias', $qrAlias)->first();
$qrResp       = $qrTxn
    ? DB::connection($src)->table('qr_respuestas_banco')
        ->where('id_qr_transaccion', $qrTxn->id_qr_transaccion)
        ->orderByDesc('id_respuesta_banco')->first()
    : null;
$notaBanc9270 = DB::connection($src)->table('nota_bancaria')->where('nro_factura', 2599)->first();
$banco9270    = $notaBanc9270 ? trim(explode(' - ', $notaBanc9270->banco ?? '')[0]) : null;
$fechaDep9270 = null;
$nroDep9270   = null;
if ($qrResp) {
    $fechaDep9270 = Carbon::parse($qrResp->fecha_respuesta)->format('Y-m-d');
    $nroDep9270   = mb_substr($qrResp->numeroordenoriginante ?? '', 0, 50) ?: null;
} elseif ($qrTxn && $qrTxn->processed_at) {
    $fechaDep9270 = Carbon::parse($qrTxn->processed_at)->format('Y-m-d');
}
$partes  = array_filter([$banco9270, $nroDep9270, $fechaDep9270]);
$obs9270 = 'Transferencia: [QR] alias:' . $qrAlias . ($partes ? ' ' . implode('-', array_values($partes)) : '');

echo "nick20={$nick20}  nick3={$nick3}\n";
echo "numCuota9266={$numCuota9266}  numCuota9270={$numCuota9270}\n";
echo "obs9270: {$obs9270}\n\n";

// ─── PAGO cobro 9266 en sga_mec (Efectivo, factura 2596) ──────────────
$numPago9266 = (int) DB::connection('sga_mec')->table('pago')
    ->where('cod_ceta', '120260168')->where('cod_pensum', '04-MTZ-23')
    ->where('cod_inscrip', 38256)->where('num_cuota', $numCuota9266)
    ->max('num_pago') + 1;

$maxOrd9266 = (int) DB::connection('sga_mec')->selectOne("
    SELECT COALESCE(MAX(t.orden), -1) + 1 AS v
    FROM (SELECT orden FROM pago WHERE num_factura = 2596
          UNION ALL SELECT orden FROM pago_multa WHERE num_factura = 2596) t
")->v;

DB::connection('sga_mec')->table('pago')->insert([
    'cod_ceta'          => '120260168',
    'cod_pensum'        => '04-MTZ-23',
    'cod_inscrip'       => 38256,
    'kardex_economico'  => 'NORMAL',
    'num_cuota'         => $numCuota9266,
    'num_pago'          => $numPago9266,
    'monto'             => 800.00,
    'num_comprobante'   => 0,
    'num_factura'       => 2596,
    'fecha_pago'        => '2026-04-28 18:50:17',
    'pago_completo'     => true,
    'observaciones'     => 'Efectivo',
    'usuario'           => $nick20,
    'razon'             => $fac2596->cliente ?? null,
    'nro_documento_pago'=> $fac2596->nro_documento_cobro ?? null,
    'autorizacion'      => '0',
    'valido'            => 'V',
    'concepto'          => "Mensualidad 'Abril'",
    'codigo_control'    => 'cod_control',
    'codigo_qr'         => null,
    'descuento'         => 0.00,
    'pu_mensualidad'    => 800.00,
    'code_tipo_pago'    => 'E',
    'fecha_deposito'    => null,
    'nro_cuenta'        => null,
    'nro_deposito'      => null,
    'nro_nota'          => 0,
    'banco_origen'      => null,
    'nro_tarjeta'       => null,
    'estado_factura'    => null,
    'id_item_service'   => 1,
    'orden'             => $maxOrd9266,
    'turno'             => null,
    'anulado'           => false,
    'fecha_anulacion'   => null,
    'usuario_anula'     => null,
]);
echo "PAGO cobro 9266 (sga_mec) insertado num_pago={$numPago9266}\n";

// ─── PAGO cobro 9270 en sga_elec (QR, factura 2599) ──────────────────
$numPago9270 = (int) DB::connection('sga_elec')->table('pago')
    ->where('cod_ceta', '220241049')->where('cod_pensum', 'EEA-19')
    ->where('cod_inscrip', 48226)->where('num_cuota', $numCuota9270)
    ->max('num_pago') + 1;

$maxOrd9270 = (int) DB::connection('sga_elec')->selectOne("
    SELECT COALESCE(MAX(t.orden), -1) + 1 AS v
    FROM (SELECT orden FROM pago WHERE num_factura = 2599
          UNION ALL SELECT orden FROM pago_multa WHERE num_factura = 2599) t
")->v;

DB::connection('sga_elec')->table('pago')->insert([
    'cod_ceta'          => '220241049',
    'cod_pensum'        => 'EEA-19',
    'cod_inscrip'       => 48226,
    'kardex_economico'  => 'NORMAL',
    'num_cuota'         => $numCuota9270,
    'num_pago'          => $numPago9270,
    'monto'             => 800.00,
    'num_comprobante'   => 0,
    'num_factura'       => 2599,
    'fecha_pago'        => '2026-04-28 19:12:23',
    'pago_completo'     => true,
    'observaciones'     => $obs9270,
    'usuario'           => $nick3,
    'razon'             => $fac2599->cliente ?? null,
    'nro_documento_pago'=> $fac2599->nro_documento_cobro ?? null,
    'autorizacion'      => '0',
    'valido'            => 'V',
    'concepto'          => "Mensualidad 'Abril'",
    'codigo_control'    => 'cod_control',
    'codigo_qr'         => null,
    'descuento'         => 0.00,
    'pu_mensualidad'    => 800.00,
    'code_tipo_pago'    => 'B',
    'fecha_deposito'    => $fechaDep9270,
    'nro_cuenta'        => null,
    'nro_deposito'      => $nroDep9270,
    'nro_nota'          => 0,
    'banco_origen'      => null,
    'nro_tarjeta'       => null,
    'estado_factura'    => null,
    'id_item_service'   => 1,
    'orden'             => $maxOrd9270,
    'turno'             => null,
    'anulado'           => false,
    'fecha_anulacion'   => null,
    'usuario_anula'     => null,
]);
echo "PAGO cobro 9270 (sga_elec) insertado num_pago={$numPago9270}\n";

echo "\n=== COMPLETADO ===\n";
