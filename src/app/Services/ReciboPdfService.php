<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Dompdf\Dompdf;

class ReciboPdfService
{
    private function numToLiteral(float $monto): string
    {
        $entero = (int) floor($monto);
        $centavos = (int) round(($monto - $entero) * 100);

        $u = ['', 'uno', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve', 'diez', 'once', 'doce', 'trece', 'catorce', 'quince', 'dieciséis', 'diecisiete', 'dieciocho', 'diecinueve'];
        $d = ['', 'diez', 'veinte', 'treinta', 'cuarenta', 'cincuenta', 'sesenta', 'setenta', 'ochenta', 'noventa'];
        $c = ['', 'cien', 'doscientos', 'trescientos', 'cuatrocientos', 'quinientos', 'seiscientos', 'setecientos', 'ochocientos', 'novecientos'];

        $toWords2 = function (int $n) use ($u, $d): string {
            if ($n < 20) return $u[$n];
            $tens = intdiv($n, 10); $ones = $n % 10;
            if ($tens === 2 && $ones > 0) return 'veinti' . $u[$ones];
            $sep = $ones ? ' y ' : '';
            return $d[$tens] . ($sep ? $sep . $u[$ones] : '');
        };

        $toWords3 = function (int $n) use ($c, $toWords2): string {
            if ($n === 0) return '';
            if ($n === 100) return 'cien';
            $hund = intdiv($n, 100); $rest = $n % 100;
            $pref = $hund ? ($hund === 1 ? 'ciento' : $c[$hund]) : '';
            $mid = $rest ? ($pref ? ' ' : '') . $toWords2($rest) : '';
            return trim($pref . $mid);
        };

        $toWords = function (int $n) use ($toWords3): string {
            if ($n === 0) return 'cero';
            $parts = [];
            $millones = intdiv($n, 1000000); $n %= 1000000;
            $miles = intdiv($n, 1000); $n %= 1000;
            if ($millones) $parts[] = ($millones === 1 ? 'un millón' : $toWords3($millones) . ' millones');
            if ($miles) $parts[] = ($miles === 1 ? 'mil' : $toWords3($miles) . ' mil');
            if ($n) $parts[] = $toWords3($n);
            return trim(implode(' ', $parts));
        };

        $literal = strtoupper($toWords($entero)) . ' ' . str_pad((string)$centavos, 2, '0', STR_PAD_LEFT) . '/100 Bs.';
        return $literal;
    }

    private function renderHtmlTarjeta(object $recibo, array $cobros, ?object $est, array $extras = []): string
    {
        $fechaDT = isset($extras['fecha_dt']) && $extras['fecha_dt'] instanceof \DateTimeInterface ? $extras['fecha_dt'] : new \DateTime('now', new \DateTimeZone('America/La_Paz'));
        $fechaLiteral = $this->fechaLiteral($fechaDT);
        $total = (float)($recibo->monto_total ?? 0);
        $totalFmt = number_format($total, 2, '.', '');
        $literal = $this->numToLiteral($total);
        $nombre = '';
        if ($est) {
            $nombre = trim(implode(' ', array_filter([
                $est->nombres ?? '',
                $est->ap_paterno ?? '',
                $est->ap_materno ?? ''
            ])));
        }
        $usuario = null; $usuarioNombre = '';
        if (isset($recibo->id_usuario)) {
            $usuario = DB::table('usuarios')->where('id_usuario', $recibo->id_usuario)->first();
            $usuarioNombre = (string) ($usuario->usuario ?? ($usuario->nombre ?? ''));
        }
        $detalles = []; $observaciones = [];
        foreach ($cobros as $c) {
            $c = (object)$c;
            $obs = trim((string)($c->observaciones ?? ''));
            if ($obs !== '') $observaciones[] = $obs;
            if (!empty($c->id_item)) {
                try {
                    $it = DB::table('items_cobro')->where('id_item', $c->id_item)->first();
                    $detalles[] = $it ? ((string)($it->descripcion ?? $it->nombre ?? 'Item ' . $c->id_item)) : ('Item ' . $c->id_item);
                } catch (\Throwable $e) { $detalles[] = 'Item ' . $c->id_item; }
            } else {
                $numCuota = null;
                try {
                    if (!empty($c->id_asignacion_costo)) {
                        $asig = DB::table('asignacion_costos')->where('id_asignacion_costo', $c->id_asignacion_costo)->first();
                        if ($asig && isset($asig->numero_cuota)) $numCuota = (int)$asig->numero_cuota;
                    } elseif (!empty($c->id_cuota)) {
                        $asig = DB::table('asignacion_costos')->where('id_cuota_template', $c->id_cuota)->first();
                        if ($asig && isset($asig->numero_cuota)) $numCuota = (int)$asig->numero_cuota;
                    }
                } catch (\Throwable $e) {}
                $lbl = 'Mensualidad' . ($numCuota ? (' - Cuota ' . $numCuota) : '');
                $detalles[] = $lbl;
            }
        }
        $detalle = implode(' | ', array_unique(array_filter($detalles)));
        $obsLinea = implode(' | ', array_unique(array_filter($observaciones)));
        $carrera = (string)($extras['carrera'] ?? '');
        $logo = (string)($extras['logo'] ?? '');
        $logoHtml = $logo ? ('<img src="' . $logo . '" width="60" height="60" />') : '';
        $nb = isset($extras['nota']) ? $extras['nota'] : null;
        $dest = isset($extras['dest']) ? $extras['dest'] : null;
        $bancoDest = $dest ? ((string)($dest->banco ?? '')) : '';
        $fechaDep = $nb ? ((string)($nb->fecha_deposito ?? '')) : '';
        $codCobro = (string)($extras['cod_cobro'] ?? '');
        $docLine = 'Doc.: F- ' . ((string)($recibo->nro_factura ?? '0')) . ', R- ' . ((string)($recibo->nro_recibo ?? ''));

        $html = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8" />
    <style>
        @page { size: 8.5in 5.5in; margin: 3mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 8.7pt; line-height: 1.12; }
        .right { text-align:right; }
        .small { font-size: 8pt; color: #333; }
        .tabla { width:100%; border-collapse: collapse; page-break-inside: avoid; }
        .tabla th, .tabla td { border: 1px solid #000; padding: 2px; }
        .sinborde td { border: none; }
        .label { background:#C8C8C8; font-weight:bold; }
        .separador { border-bottom:2px dotted #000; margin: 4px 0; }
    </style>
    <title>Recibo {$recibo->nro_recibo}/{$recibo->anio}</title>
    </head>
    <body>
        <table class="sinborde" style="width:100%">
            <tr>
                <td style="width:20%; text-align:center; vertical-align:top">{$logoHtml}</td>
                <td style="width:80%; text-align:center; vertical-align:top">
                    <div style="font-size:13pt; color:black; font-weight:bold;">Instituto Tecnológico de Enseñanza Automotriz CETA S.R.L.</div>
                    <div style="font-size:11pt; color:black; font-weight:bold;">Carrera: {$carrera}</div>
                    <div style="font-size:12pt; color:black; font-weight:bold; border-top:2px solid #000; margin-top:3px; padding-top:3px;">NOTA PAGO CON TARJETA</div>
                </td>
            </tr>
        </table>
        <table class="sinborde" style="width:100%; margin-top:2px">
            <tr>
                <td style="width:60%"></td>
                <td class="right" style="width:40%">
                    N° E-{$recibo->nro_recibo}<br>
                    {$fechaLiteral}
                </td>
            </tr>
        </table>
        <table class="tabla" style="margin-top:4px">
            <tr>
                <td class="label" style="width:20%">&nbsp;Estudiante:</td>
                <td style="width:36%">{$nombre}</td>
                <td class="label" style="width:20%">&nbsp;Banco Dest:</td>
                <td style="width:24%">{$bancoDest}</td>
            </tr>
            <tr>
                <td class="label">&nbsp;Cod. CETA:</td>
                <td>{$recibo->cod_ceta}</td>
                <td class="label">&nbsp;Fecha Cobro:</td>
                <td>{$fechaDT->format('d/m/Y')}</td>
            </tr>
        </table>
        <table class="sinborde" style="width:100%; margin-top:3px">
            <tr>
                <td style="width:70%">
                    <div><span style="font-weight:bold">MONTO:</span></div>
                    <div><span style="font-weight:bold">Literal:</span> {$literal}</div>
                    <div><span style="font-weight:bold">Detalle:</span> {$detalle}</div>
                    <div><span style="font-weight:bold">Obs.:</span> {$obsLinea}</div>
                    <div><span style="font-weight:bold">Doc.:</span> {$docLine}</div>
                </td>
                <td class="right" style="width:30%; vertical-align:top"><div style="font-weight:bold">{$totalFmt}</div></td>
            </tr>
        </table>
        <div class="separador"></div>
        <table class="sinborde" style="width:100%; margin-top:2px">
            <tr>
                <td style="width:60%"></td>
                <td style="width:40%" class="small">{$usuarioNombre} - Firma:</td>
            </tr>
        </table>
        <table class="sinborde" style="width:100%; margin-top:2px">
            <tr>
                <td style="width:60%; vertical-align:top">
                    <div><span style="font-weight:bold">Estudiante:</span> {$nombre}</div>
                    <div><span style="font-weight:bold">Código CETA:</span> {$recibo->cod_ceta}</div>
                    <div><span style="font-weight:bold">Detalle:</span> {$detalle}</div>
                </td>
                <td class="right" style="width:40%">
                    N° E-{$recibo->nro_recibo}<br>
                    {$fechaLiteral}
                    <div><span style="font-weight:bold">Banco Dest:</span> {$bancoDest}</div>
                </td>
            </tr>
        </table>
        <table class="sinborde" style="width:100%; margin-top:3px">
            <tr>
                <td class="small" style="border-top:1px solid #000; padding-top:3px">Solo para fines informativos</td>
                <td class="small right" style="border-top:1px solid #000; padding-top:3px">usuario: {$usuarioNombre}</td>
            </tr>
        </table>
    </body>
    </html>
HTML;
        return $html;
    }
    private function renderHtmlTransferencia(object $recibo, array $cobros, ?object $est, array $extras = []): string
    {
        $fechaDT = isset($extras['fecha_dt']) && $extras['fecha_dt'] instanceof \DateTimeInterface ? $extras['fecha_dt'] : new \DateTime('now', new \DateTimeZone('America/La_Paz'));
        $fechaLiteral = $this->fechaLiteral($fechaDT);
        $total = (float)($recibo->monto_total ?? 0);
        $totalFmt = number_format($total, 2, '.', '');
        $literal = $this->numToLiteral($total);
        $nombre = '';
        if ($est) {
            $nombre = trim(implode(' ', array_filter([
                $est->nombres ?? '',
                $est->ap_paterno ?? '',
                $est->ap_materno ?? ''
            ])));
        }
        $usuario = null; $usuarioNombre = '';
        if (isset($recibo->id_usuario)) {
            $usuario = DB::table('usuarios')->where('id_usuario', $recibo->id_usuario)->first();
            $usuarioNombre = (string) ($usuario->usuario ?? ($usuario->nombre ?? ''));
        }
        $detalles = []; $observaciones = [];
        foreach ($cobros as $c) {
            $c = (object)$c;
            $obs = trim((string)($c->observaciones ?? ''));
            if ($obs !== '') $observaciones[] = $obs;
            if (!empty($c->id_item)) {
                try {
                    $it = DB::table('items_cobro')->where('id_item', $c->id_item)->first();
                    $detalles[] = $it ? ((string)($it->descripcion ?? $it->nombre ?? 'Item ' . $c->id_item)) : ('Item ' . $c->id_item);
                } catch (\Throwable $e) { $detalles[] = 'Item ' . $c->id_item; }
            } else {
                $numCuota = null;
                try {
                    if (!empty($c->id_asignacion_costo)) {
                        $asig = DB::table('asignacion_costos')->where('id_asignacion_costo', $c->id_asignacion_costo)->first();
                        if ($asig && isset($asig->numero_cuota)) $numCuota = (int)$asig->numero_cuota;
                    } elseif (!empty($c->id_cuota)) {
                        $asig = DB::table('asignacion_costos')->where('id_cuota_template', $c->id_cuota)->first();
                        if ($asig && isset($asig->numero_cuota)) $numCuota = (int)$asig->numero_cuota;
                    }
                } catch (\Throwable $e) {}
                $lbl = 'Mensualidad' . ($numCuota ? (' - Cuota ' . $numCuota) : '');
                $detalles[] = $lbl;
            }
        }
        $detalle = implode(' | ', array_unique(array_filter($detalles)));
        $obsLinea = implode(' | ', array_unique(array_filter($observaciones)));
        $carrera = (string)($extras['carrera'] ?? '');
        $logo = (string)($extras['logo'] ?? '');
        $logoHtml = $logo ? ('<img src="' . $logo . '" width="60" height="60" />') : '';
        $nb = isset($extras['nota']) ? $extras['nota'] : null;
        $dest = isset($extras['dest']) ? $extras['dest'] : null;
        $bancoDest = $dest ? ((string)($dest->banco ?? '')) : '';
        $fechaTrans = $nb ? ((string)($nb->fecha_deposito ?? '')) : '';
        $nroTrans = $nb ? ((string)($nb->nro_transaccion ?? '')) : '';
        $fechaTransFmt = $fechaTrans !== '' ? $fechaTrans : $fechaDT->format('d/m/Y');
        $docLine = 'Doc.: F- ' . ((string)($recibo->nro_factura ?? '0')) . ', R- ' . ((string)($recibo->nro_recibo ?? ''));

        $html = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8" />
    <style>
        @page { size: 8.5in 5.5in; margin: 3mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 8.7pt; line-height: 1.12; }
        .right { text-align:right; }
        .small { font-size: 8pt; color: #333; }
        .tabla { width:100%; border-collapse: collapse; page-break-inside: avoid; }
        .tabla th, .tabla td { border: 1px solid #000; padding: 2px; }
        .sinborde td { border: none; }
        .label { background:#C8C8C8; font-weight:bold; }
        .separador { border-bottom:2px dotted #000; margin: 4px 0; }
    </style>
    <title>Recibo {$recibo->nro_recibo}/{$recibo->anio}</title>
    </head>
    <body>
        <table class="sinborde" style="width:100%">
            <tr>
                <td style="width:20%; text-align:center; vertical-align:top">{$logoHtml}</td>
                <td style="width:80%; text-align:center; vertical-align:top">
                    <div style="font-size:13pt; color:black; font-weight:bold;">Instituto Tecnológico de Enseñanza Automotriz CETA S.R.L.</div>
                    <div style="font-size:11pt; color:black; font-weight:bold;">Carrera: {$carrera}</div>
                    <div style="font-size:12pt; color:black; font-weight:bold; border-top:2px solid #000; margin-top:3px; padding-top:3px;">NOTA DE TRANSFERENCIA</div>
                </td>
            </tr>
        </table>
        <table class="sinborde" style="width:100%; margin-top:2px">
            <tr>
                <td style="width:60%"></td>
                <td class="right" style="width:40%">
                    N° E-{$recibo->nro_recibo}<br>
                    {$fechaLiteral}
                </td>
            </tr>
        </table>
        <table class="tabla" style="margin-top:4px">
            <colgroup>
                <col style="width:18%" />
                <col style="width:34%" />
                <col style="width:16%" />
                <col style="width:20%" />
                <col style="width:6%" />
                <col style="width:6%" />
            </colgroup>
            <tr>
                <td class="label">&nbsp;Estudiante:</td>
                <td>{$nombre}</td>
                <td class="label">&nbsp;Banco Dest:</td>
                <td colspan="3">{$bancoDest}</td>
            </tr>
            <tr>
                <td class="label">&nbsp;Cod. CETA:</td>
                <td>{$recibo->cod_ceta}</td>
                <td class="label">&nbsp;Fecha Trans.:</td>
                <td>{$fechaTransFmt}</td>
                <td class="label" style="white-space:nowrap">&nbsp;N° Trans.:</td>
                <td>{$nroTrans}</td>
            </tr>
        </table>
        <table class="sinborde" style="width:100%; margin-top:8px">
            <tr>
                <td style="width:70%">
                    <div><span style="font-weight:bold">MONTO:</span></div>
                    <div><span style="font-weight:bold">Literal:</span>{$literal}</div>
                    <div><span style="font-weight:bold">Detalle:</span>{$detalle}</div>
                    <div><span style="font-weight:bold">Obs.:</span></div>
                    <div><span style="font-weight:bold">Doc.:</span>{$docLine}</div>
                </td>
                <td class="right" style="width:30%; vertical-align:top"><div style="font-weight:bold">{$totalFmt}</div></td>
            </tr>
        </table>
        <div class="separador" style="margin-top:6px"></div>
        <table class="sinborde" style="width:100%; margin-top:8px">
            <tr>
                <td style="width:60%"></td>
                <td style="width:40%" class="small">{$usuarioNombre} - Firma:</td>
            </tr>
        </table>
        <div style="text-align:center; margin-top:6px">Carrera: {$carrera}</div>
        <table class="sinborde" style="width:100%; margin-top:10px">
            <tr>
                <td style="width:60%; vertical-align:top">
                    <div><span style="font-weight:bold">Estudiante:</span> {$nombre}</div>
                    <div><span style="font-weight:bold">Código</span><br/>CETA:<br/>{$recibo->cod_ceta}</div>
                    <div><span style="font-weight:bold">MONTO:</span></div>
                    <div><span style="font-weight:bold">Detalle:</span>{$detalle}</div>
                </td>
                <td class="right" style="width:40%">
                    <div style="font-weight:bold">TRANSFERENCIA</div>
                    N° E-{$recibo->nro_recibo}<br>
                    {$fechaLiteral}
                    <div><span style="font-weight:bold">Banco Dest:</span> {$bancoDest}</div>
                    <div><span style="font-weight:bold">Fecha Trans.:</span> {$fechaTransFmt}</div>
                    <div><span style="font-weight:bold">N° Trans.:</span> {$nroTrans}</div>
                </td>
            </tr>
        </table>
        <table class="sinborde" style="width:100%; margin-top:8px">
            <tr>
                <td class="small" style="border-top:1px solid #000; padding-top:3px">Solo para fines informativos</td>
                <td class="small right" style="border-top:1px solid #000; padding-top:3px">usuario: {$usuarioNombre}</td>
            </tr>
        </table>
        <div class="right" style="margin-top:6px; font-weight:bold">Bs. {$totalFmt}</div>
    </body>
    </html>
HTML;
        return $html;
    }
    private function renderHtmlDeposito(object $recibo, array $cobros, ?object $est, array $extras = []): string
    {
        $fechaDT = isset($extras['fecha_dt']) && $extras['fecha_dt'] instanceof \DateTimeInterface ? $extras['fecha_dt'] : new \DateTime('now', new \DateTimeZone('America/La_Paz'));
        $fechaLiteral = $this->fechaLiteral($fechaDT);
        $total = (float)($recibo->monto_total ?? 0);
        $totalFmt = number_format($total, 2, '.', '');
        $literal = $this->numToLiteral($total);
        $nombre = '';
        if ($est) {
            $nombre = trim(implode(' ', array_filter([
                $est->nombres ?? '',
                $est->ap_paterno ?? '',
                $est->ap_materno ?? ''
            ])));
        }
        $usuario = null; $usuarioNombre = '';
        if (isset($recibo->id_usuario)) {
            $usuario = DB::table('usuarios')->where('id_usuario', $recibo->id_usuario)->first();
            $usuarioNombre = (string) ($usuario->usuario ?? ($usuario->nombre ?? ''));
        }
        $detalles = []; $observaciones = [];
        foreach ($cobros as $c) {
            $c = (object)$c;
            $obs = trim((string)($c->observaciones ?? ''));
            if ($obs !== '') $observaciones[] = $obs;
            if (!empty($c->id_item)) {
                try {
                    $it = DB::table('items_cobro')->where('id_item', $c->id_item)->first();
                    $detalles[] = $it ? ((string)($it->descripcion ?? $it->nombre ?? 'Item ' . $c->id_item)) : ('Item ' . $c->id_item);
                } catch (\Throwable $e) { $detalles[] = 'Item ' . $c->id_item; }
            } else {
                $numCuota = null;
                try {
                    if (!empty($c->id_asignacion_costo)) {
                        $asig = DB::table('asignacion_costos')->where('id_asignacion_costo', $c->id_asignacion_costo)->first();
                        if ($asig && isset($asig->numero_cuota)) $numCuota = (int)$asig->numero_cuota;
                    } elseif (!empty($c->id_cuota)) {
                        $asig = DB::table('asignacion_costos')->where('id_cuota_template', $c->id_cuota)->first();
                        if ($asig && isset($asig->numero_cuota)) $numCuota = (int)$asig->numero_cuota;
                    }
                } catch (\Throwable $e) {}
                $lbl = 'Mensualidad' . ($numCuota ? (' - Cuota ' . $numCuota) : '');
                $detalles[] = $lbl;
            }
        }
        $detalle = implode(' | ', array_unique(array_filter($detalles)));
        $obsLinea = implode(' | ', array_unique(array_filter($observaciones)));
        $carrera = (string)($extras['carrera'] ?? '');
        $logo = (string)($extras['logo'] ?? '');
        $logoHtml = $logo ? ('<img src="' . $logo . '" width="60" height="60" />') : '';
        $nb = isset($extras['nota']) ? $extras['nota'] : null;
        $dest = isset($extras['dest']) ? $extras['dest'] : null;
        $bancoDest = $dest ? ((string)($dest->banco ?? '')) : '';
        $fechaDep = $nb ? ((string)($nb->fecha_deposito ?? '')) : '';
        $nroTrans = $nb ? ((string)($nb->nro_transaccion ?? '')) : '';
        $fechaDepFmt = $fechaDep !== '' ? $fechaDep : $fechaDT->format('d/m/Y');
        $docLine = 'Doc.: F- ' . ((string)($recibo->nro_factura ?? '0')) . ', R- ' . ((string)($recibo->nro_recibo ?? ''));

        $html = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8" />
    <style>
        @page { size: 8.5in 5.5in; margin: 3mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 8.7pt; line-height: 1.12; }
        .right { text-align:right; }
        .small { font-size: 8pt; color: #333; }
        .tabla { width:100%; border-collapse: collapse; page-break-inside: avoid; }
        .tabla th, .tabla td { border: 1px solid #000; padding: 2px; }
        .sinborde td { border: none; }
        .label { background:#C8C8C8; font-weight:bold; }
        .separador { border-bottom:2px dotted #000; margin: 4px 0; }
    </style>
    <title>Recibo {$recibo->nro_recibo}/{$recibo->anio}</title>
    </head>
    <body>
        <table class="sinborde" style="width:100%">
            <tr>
                <td style="width:20%; text-align:center; vertical-align:top">{$logoHtml}</td>
                <td style="width:80%; text-align:center; vertical-align:top">
                    <div style="font-size:13pt; color:black; font-weight:bold;">Instituto Tecnológico de Enseñanza Automotriz CETA S.R.L.</div>
                    <div style="font-size:11pt; color:black; font-weight:bold;">Carrera: {$carrera}</div>
                    <div style="font-size:12pt; color:black; font-weight:bold; border-top:2px solid #000; margin-top:3px; padding-top:3px;">NOTA DE DEPOSITO</div>
                </td>
            </tr>
        </table>
        <table class="sinborde" style="width:100%; margin-top:2px">
            <tr>
                <td style="width:60%"></td>
                <td class="right" style="width:40%">
                    N° E-{$recibo->nro_recibo}<br>
                    {$fechaLiteral}
                </td>
            </tr>
        </table>
        <table class="tabla" style="margin-top:4px">
            <colgroup>
                <col style="width:18%" />
                <col style="width:34%" />
                <col style="width:16%" />
                <col style="width:20%" />
                <col style="width:6%" />
                <col style="width:6%" />
            </colgroup>
            <tr>
                <td class="label">&nbsp;Estudiante:</td>
                <td>{$nombre}</td>
                <td class="label">&nbsp;Banco:</td>
                <td colspan="3">{$bancoDest}</td>
            </tr>
            <tr>
                <td class="label">&nbsp;Cod. CETA:</td>
                <td>{$recibo->cod_ceta}</td>
                <td class="label">&nbsp;Fecha Dep.:</td>
                <td>{$fechaDepFmt}</td>
                <td class="label" style="white-space:nowrap">&nbsp;N° Trans.:</td>
                <td>{$nroTrans}</td>
            </tr>
        </table>
        <table class="sinborde" style="width:100%; margin-top:8px">
            <tr>
                <td style="width:70%">
                    <div><span style="font-weight:bold">MONTO:</span></div>
                    <div><span style="font-weight:bold">Literal:</span>{$literal}</div>
                    <div><span style="font-weight:bold">Detalle:</span>{$detalle}</div>
                    <div><span style="font-weight:bold">Obs.:</span></div>
                    <div><span style="font-weight:bold">Doc.:</span>{$docLine}</div>
                </td>
                <td class="right" style="width:30%; vertical-align:top"><div style="font-weight:bold">{$totalFmt}</div></td>
            </tr>
        </table>
        <div class="separador" style="margin-top:6px"></div>
        <table class="sinborde" style="width:100%; margin-top:8px">
            <tr>
                <td style="width:60%"></td>
                <td style="width:40%" class="small">{$usuarioNombre} - Firma:</td>
            </tr>
        </table>
        <div style="text-align:center; margin-top:6px">Carrera: {$carrera}</div>
        <table class="sinborde" style="width:100%; margin-top:10px">
            <tr>
                <td style="width:60%; vertical-align:top">
                    <div><span style="font-weight:bold">Estudiante:</span> {$nombre}</div>
                    <div><span style="font-weight:bold">Código</span><br/>CETA:<br/>{$recibo->cod_ceta}</div>
                    <div><span style="font-weight:bold">MONTO:</span></div>
                    <div><span style="font-weight:bold">Detalle:</span>{$detalle}</div>
                </td>
                <td class="right" style="width:40%">
                    <div style="font-weight:bold">DEPÓSITO EN CUENTA</div>
                    N° E-{$recibo->nro_recibo}<br>
                    {$fechaLiteral}
                    <div><span style="font-weight:bold">Banco:</span> {$bancoDest}</div>
                    <div><span style="font-weight:bold">Fecha Depósito:</span> {$fechaDepFmt}</div>
                    <div><span style="font-weight:bold">N° Trans.:</span> {$nroTrans}</div>
                </td>
            </tr>
        </table>
        <table class="sinborde" style="width:100%; margin-top:8px">
            <tr>
                <td class="small" style="border-top:1px solid #000; padding-top:3px">Solo para fines informativos</td>
                <td class="small right" style="border-top:1px solid #000; padding-top:3px">usuario: {$usuarioNombre}</td>
            </tr>
        </table>
        <div class="right" style="margin-top:6px; font-weight:bold">Bs. {$totalFmt}</div>
    </body>
    </html>
HTML;
        return $html;
    }
    private function renderHtmlCheque(object $recibo, array $cobros, ?object $est, array $extras = []): string
    {
        $fechaDT = isset($extras['fecha_dt']) && $extras['fecha_dt'] instanceof \DateTimeInterface ? $extras['fecha_dt'] : new \DateTime('now', new \DateTimeZone('America/La_Paz'));
        $fechaLiteral = $this->fechaLiteral($fechaDT);
        $total = (float)($recibo->monto_total ?? 0);
        $totalFmt = number_format($total, 2, '.', '');
        $literal = $this->numToLiteral($total);
        $nombre = '';
        if ($est) {
            $nombre = trim(implode(' ', array_filter([
                $est->nombres ?? '',
                $est->ap_paterno ?? '',
                $est->ap_materno ?? ''
            ])));
        }
        $usuario = null; $usuarioNombre = '';
        if (isset($recibo->id_usuario)) {
            $usuario = DB::table('usuarios')->where('id_usuario', $recibo->id_usuario)->first();
            $usuarioNombre = (string) ($usuario->usuario ?? ($usuario->nombre ?? ''));
        }
        $detalles = []; $observaciones = [];
        foreach ($cobros as $c) {
            $c = (object)$c;
            $obs = trim((string)($c->observaciones ?? ''));
            if ($obs !== '') $observaciones[] = $obs;
            if (!empty($c->id_item)) {
                try {
                    $it = DB::table('items_cobro')->where('id_item', $c->id_item)->first();
                    $detalles[] = $it ? ((string)($it->descripcion ?? $it->nombre ?? 'Item ' . $c->id_item)) : ('Item ' . $c->id_item);
                } catch (\Throwable $e) { $detalles[] = 'Item ' . $c->id_item; }
            } else {
                $numCuota = null;
                try {
                    if (!empty($c->id_asignacion_costo)) {
                        $asig = DB::table('asignacion_costos')->where('id_asignacion_costo', $c->id_asignacion_costo)->first();
                        if ($asig && isset($asig->numero_cuota)) $numCuota = (int)$asig->numero_cuota;
                    } elseif (!empty($c->id_cuota)) {
                        $asig = DB::table('asignacion_costos')->where('id_cuota_template', $c->id_cuota)->first();
                        if ($asig && isset($asig->numero_cuota)) $numCuota = (int)$asig->numero_cuota;
                    }
                } catch (\Throwable $e) {}
                $lbl = 'Mensualidad' . ($numCuota ? (' - Cuota ' . $numCuota) : '');
                $detalles[] = $lbl;
            }
        }
        $detalle = implode(' | ', array_unique(array_filter($detalles)));
        $obsLinea = implode(' | ', array_unique(array_filter($observaciones)));
        $carrera = (string)($extras['carrera'] ?? '');
        $logo = (string)($extras['logo'] ?? '');
        $logoHtml = $logo ? ('<img src="' . $logo . '" width="60" height="60" />') : '';
        $nb = isset($extras['nota']) ? $extras['nota'] : null;
        $dest = isset($extras['dest']) ? $extras['dest'] : null;
        $bancoDest = $dest ? ((string)($dest->banco ?? '')) : '';
        $nroCheque = $nb ? ((string)($nb->nro_transaccion ?? '')) : '';
        $docLine = 'Doc.: F- ' . ((string)($recibo->nro_factura ?? '0')) . ', R- ' . ((string)($recibo->nro_recibo ?? ''));

        $html = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8" />
    <style>
        @page { size: 8.5in 5.5in; margin: 3mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 8.7pt; line-height: 1.12; }
        .right { text-align:right; }
        .small { font-size: 8pt; color: #333; }
        .tabla { width:100%; border-collapse: collapse; page-break-inside: avoid; }
        .tabla th, .tabla td { border: 1px solid #000; padding: 2px; }
        .sinborde td { border: none; }
        .label { background:#C8C8C8; font-weight:bold; }
        .separador { border-bottom:2px dotted #000; margin: 4px 0; }
    </style>
    <title>Recibo {$recibo->nro_recibo}/{$recibo->anio}</title>
    </head>
    <body>
        <table class="sinborde" style="width:100%">
            <tr>
                <td style="width:20%; text-align:center; vertical-align:top">{$logoHtml}</td>
                <td style="width:80%; text-align:center; vertical-align:top">
                    <div style="font-size:13pt; color:black; font-weight:bold;">Instituto Tecnológico de Enseñanza Automotriz CETA S.R.L.</div>
                    <div style="font-size:11pt; color:black; font-weight:bold;">Carrera: {$carrera}</div>
                    <div style="font-size:12pt; color:black; font-weight:bold; border-top:2px solid #000; margin-top:3px; padding-top:3px;">NOTA PAGO CON CHEQUE</div>
                </td>
            </tr>
        </table>
        <table class="sinborde" style="width:100%; margin-top:2px">
            <tr>
                <td style="width:60%"></td>
                <td class="right" style="width:40%">
                    N° E-{$recibo->nro_recibo}<br>
                    {$fechaLiteral}
                </td>
            </tr>
        </table>
        <table class="tabla" style="margin-top:4px">
            <tr>
                <td class="label" style="width:20%">&nbsp;Estudiante:</td>
                <td style="width:36%">{$nombre}</td>
                <td class="label" style="width:20%">&nbsp;Banco:</td>
                <td style="width:24%">{$bancoDest}</td>
            </tr>
            <tr>
                <td class="label">&nbsp;Cod. CETA:</td>
                <td>{$recibo->cod_ceta}</td>
                <td class="label">&nbsp;Fecha Cobro:</td>
                <td>{$fechaDT->format('d/m/Y')}</td>
            </tr>
            <tr>
                <td class="label">&nbsp;N° Cheque:</td>
                <td colspan="3">{$nroCheque}</td>
            </tr>
        </table>
        <table class="sinborde" style="width:100%; margin-top:3px">
            <tr>
                <td style="width:70%">
                    <div><span style="font-weight:bold">MONTO:</span></div>
                    <div><span style="font-weight:bold">Literal:</span> {$literal}</div>
                    <div><span style="font-weight:bold">Detalle:</span> {$detalle}</div>
                    <div><span style="font-weight:bold">Obs.:</span> {$obsLinea}</div>
                    <div><span style="font-weight:bold">Doc.:</span> {$docLine}</div>
                </td>
                <td class="right" style="width:30%; vertical-align:top"><div style="font-weight:bold">{$totalFmt}</div></td>
            </tr>
        </table>
        <div class="separador"></div>
        <table class="sinborde" style="width:100%; margin-top:2px">
            <tr>
                <td style="width:60%"></td>
                <td style="width:40%" class="small">{$usuarioNombre} - Firma:</td>
            </tr>
        </table>
        <table class="sinborde" style="width:100%; margin-top:2px">
            <tr>
                <td style="width:60%; vertical-align:top">
                    <div><span style="font-weight:bold">Estudiante:</span> {$nombre}</div>
                    <div><span style="font-weight:bold">Código CETA:</span> {$recibo->cod_ceta}</div>
                    <div><span style="font-weight:bold">Detalle:</span> {$detalle}</div>
                </td>
                <td class="right" style="width:40%">
                    <div style="font-weight:bold">PAGO CON CHEQUE</div>
                    N° E-{$recibo->nro_recibo}<br>
                    {$fechaLiteral}
                    <div><span style="font-weight:bold">Banco:</span> {$bancoDest}</div>
                    <div><span style="font-weight:bold">Fecha Cobro:</span> {$fechaDT->format('d/m/Y')}</div>
                    <div><span style="font-weight:bold">N° Cheque:</span> {$nroCheque}</div>
                </td>
            </tr>
        </table>
        <table class="sinborde" style="width:100%; margin-top:3px">
            <tr>
                <td class="small" style="border-top:1px solid #000; padding-top:3px">Solo para fines informativos</td>
                <td class="small right" style="border-top:1px solid #000; padding-top:3px">usuario: {$usuarioNombre}</td>
            </tr>
        </table>
    </body>
    </html>
HTML;
        return $html;
    }
    private function fechaLiteral(
        \DateTimeInterface $dt,
        string $prefix = ''
    ): string {
        $dias = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];
        $meses = [1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril', 5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto', 9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre'];
        $diaN = (int)$dt->format('N');
        $d = (int)$dt->format('j');
        $m = (int)$dt->format('n');
        $y = (int)$dt->format('Y');
        $texto = sprintf('%s, %d de %s de %d', $dias[$diaN], $d, $meses[$m], $y);
        return $prefix ? ($prefix . ' ' . $texto) : $texto;
    }
    public function buildPdf(int $anio, int $nroRecibo): string
    {
        $recibo = DB::table('recibo')
            ->where('anio', $anio)
            ->where('nro_recibo', $nroRecibo)
            ->first();
        if (!$recibo) {
            throw new \RuntimeException('Recibo no encontrado');
        }

        $cobros = DB::table('cobro')
            ->where('nro_recibo', $nroRecibo)
            ->whereYear('fecha_cobro', $anio)
            ->orderBy('fecha_cobro')
            ->get()
            ->all();

        $est = null;
        if (!empty($recibo->cod_ceta)) {
            $est = DB::table('estudiantes')->where('cod_ceta', $recibo->cod_ceta)->first();
        }

        // Derivar carrera desde cod_pensum del primer cobro
        $carrera = '';
        try {
            $codPensum = isset($cobros[0]) ? ($cobros[0]->cod_pensum ?? null) : null;
            if ($codPensum) {
                $rowCarr = DB::table('pensums')
                    ->join('carrera', 'carrera.codigo_carrera', '=', 'pensums.codigo_carrera')
                    ->where('pensums.cod_pensum', $codPensum)
                    ->select('carrera.nombre as nombre')
                    ->first();
                if ($rowCarr) { $carrera = (string) ($rowCarr->nombre ?? ''); }
            }
        } catch (\Throwable $e) {
            Log::warning('ReciboPdfService: no se pudo derivar carrera', [ 'error' => $e->getMessage() ]);
        }

        // Logo opcional desde public/img/logo-ceta.png
        $logo = null;
        try {
            if (function_exists('public_path')) {
                $path = public_path('img/logo-ceta.png');
                if (is_file($path)) {
                    $data = base64_encode(@file_get_contents($path) ?: '');
                    if ($data) { $logo = 'data:image/png;base64,' . $data; }
                }
            }
        } catch (\Throwable $e) {}

        $formaIds = array_values(array_unique(array_map(function($c){ return isset($c->id_forma_cobro) ? (string)$c->id_forma_cobro : ''; }, $cobros)));
        $isTarjeta = false; $isCheque = false; $isDeposito = false; $isTransferencia = false; $isOtro = false;
        if (!empty($formaIds)) {
            try {
                $rows = DB::table('formas_cobro')->whereIn('id_forma_cobro', $formaIds)->get();
                foreach ($rows as $r) {
                    $raw = strtoupper(trim((string)($r->nombre ?? $r->descripcion ?? $r->label ?? '')));
                    $norm = iconv('UTF-8','ASCII//TRANSLIT',$raw);
                    $code = strtoupper(trim((string)($r->id_forma_cobro ?? '')));
                    if ($norm === 'TARJETA') { $isTarjeta = true; }
                    if ($norm === 'CHEQUE') { $isCheque = true; }
                    if ($norm === 'DEPOSITO') { $isDeposito = true; }
                    if ($norm === 'TRANSFERENCIA') { $isTransferencia = true; }
                    if ($norm === 'OTRO' || $code === 'O') { $isOtro = true; }
                }
            } catch (\Throwable $e) {}
        }

        $html = '';
        if ($isTarjeta) {
            $nb = null; $dest = null; $fechaCobroDT = null; $codCobro = '';
            try {
                $nb = DB::table('nota_bancaria')
                    ->where('nro_recibo', (string)$recibo->nro_recibo)
                    ->orderBy('fecha_nota','desc')
                    ->first();
            } catch (\Throwable $e) {}
            try {
                $idDest = null; foreach ($cobros as $c) { if (!empty($c->id_cuentas_bancarias)) { $idDest = $c->id_cuentas_bancarias; break; } }
                if ($idDest) { $dest = DB::table('cuentas_bancarias')->where('id_cuentas_bancarias', $idDest)->first(); }
            } catch (\Throwable $e) {}
            try {
                $fechaCobroDT = new \DateTime($cobros && isset($cobros[0]) ? ((string)($cobros[0]->fecha_cobro ?? 'now')) : 'now');
            } catch (\Throwable $e) { $fechaCobroDT = new \DateTime('now'); }
            if ($nb) { $codCobro = (string)($nb->nro_transaccion ?? ''); }
            $html = $this->renderHtmlTarjeta($recibo, $cobros, $est, [ 'carrera' => $carrera, 'logo' => $logo, 'nota' => $nb, 'dest' => $dest, 'fecha_dt' => $fechaCobroDT, 'cod_cobro' => $codCobro ]);
        } elseif ($isCheque) {
            $nb = null; $dest = null; $fechaCobroDT = null;
            try {
                $nb = DB::table('nota_bancaria')
                    ->where('nro_recibo', (string)$recibo->nro_recibo)
                    ->orderBy('fecha_nota','desc')
                    ->first();
            } catch (\Throwable $e) {}
            try {
                $idDest = null; foreach ($cobros as $c) { if (!empty($c->id_cuentas_bancarias)) { $idDest = $c->id_cuentas_bancarias; break; } }
                if ($idDest) { $dest = DB::table('cuentas_bancarias')->where('id_cuentas_bancarias', $idDest)->first(); }
            } catch (\Throwable $e) {}
            try {
                $fechaCobroDT = new \DateTime($cobros && isset($cobros[0]) ? ((string)($cobros[0]->fecha_cobro ?? 'now')) : 'now');
            } catch (\Throwable $e) { $fechaCobroDT = new \DateTime('now'); }
            $html = $this->renderHtmlCheque($recibo, $cobros, $est, [ 'carrera' => $carrera, 'logo' => $logo, 'nota' => $nb, 'dest' => $dest, 'fecha_dt' => $fechaCobroDT ]);
        } elseif ($isTransferencia) {
            $nb = null; $dest = null; $fechaCobroDT = null;
            try {
                $nb = DB::table('nota_bancaria')
                    ->where('nro_recibo', (string)$recibo->nro_recibo)
                    ->orderBy('fecha_nota','desc')
                    ->first();
            } catch (\Throwable $e) {}
            try {
                $idDest = null; foreach ($cobros as $c) { if (!empty($c->id_cuentas_bancarias)) { $idDest = $c->id_cuentas_bancarias; break; } }
                if ($idDest) { $dest = DB::table('cuentas_bancarias')->where('id_cuentas_bancarias', $idDest)->first(); }
            } catch (\Throwable $e) {}
            try {
                $fechaCobroDT = new \DateTime($cobros && isset($cobros[0]) ? ((string)($cobros[0]->fecha_cobro ?? 'now')) : 'now');
            } catch (\Throwable $e) { $fechaCobroDT = new \DateTime('now'); }
            $html = $this->renderHtmlTransferencia($recibo, $cobros, $est, [ 'carrera' => $carrera, 'logo' => $logo, 'nota' => $nb, 'dest' => $dest, 'fecha_dt' => $fechaCobroDT ]);
        } elseif ($isDeposito) {
            $nb = null; $dest = null; $fechaCobroDT = null;
            try {
                $nb = DB::table('nota_bancaria')
                    ->where('nro_recibo', (string)$recibo->nro_recibo)
                    ->orderBy('fecha_nota','desc')
                    ->first();
            } catch (\Throwable $e) {}
            try {
                $idDest = null; foreach ($cobros as $c) { if (!empty($c->id_cuentas_bancarias)) { $idDest = $c->id_cuentas_bancarias; break; } }
                if ($idDest) { $dest = DB::table('cuentas_bancarias')->where('id_cuentas_bancarias', $idDest)->first(); }
            } catch (\Throwable $e) {}
            try {
                $fechaCobroDT = new \DateTime($cobros && isset($cobros[0]) ? ((string)($cobros[0]->fecha_cobro ?? 'now')) : 'now');
            } catch (\Throwable $e) { $fechaCobroDT = new \DateTime('now'); }
            $html = $this->renderHtmlDeposito($recibo, $cobros, $est, [ 'carrera' => $carrera, 'logo' => $logo, 'nota' => $nb, 'dest' => $dest, 'fecha_dt' => $fechaCobroDT ]);
        } elseif ($isOtro) {
            $html = $this->renderHtmlOtro($recibo, $cobros, $est, [ 'carrera' => $carrera, 'logo' => $logo ]);
        } else {
            $html = $this->renderHtml($recibo, $cobros, $est, [ 'carrera' => $carrera, 'logo' => $logo ]);
        }

        $dompdf = new Dompdf();
        $dompdf->loadHtml($html);
        // Media carta horizontal (Half Letter landscape) 8.5 x 5.5 pulgadas => 612 x 396 puntos
        $dompdf->setPaper([0, 0, 612, 396], 'landscape');
        $dompdf->render();
        return $dompdf->output();
    }

    // NOTA OTRO TIPO DE PAGO (para id_forma_cobro = 'O')
    private function renderHtmlOtro(object $recibo, array $cobros, ?object $est, array $extras = []): string
    {
        $fechaDT = new \DateTime('now', new \DateTimeZone('America/La_Paz'));
        $fechaLiteral = $this->fechaLiteral($fechaDT);
        $total = (float)($recibo->monto_total ?? 0);
        $totalFmt = number_format($total, 2, '.', '');
        $literal = $this->numToLiteral($total);
        $nombre = '';
        if ($est) {
            $nombre = trim(implode(' ', array_filter([
                $est->nombres ?? '',
                $est->ap_paterno ?? '',
                $est->ap_materno ?? ''
            ])));
        }
        // Usuario para firma/footer
        $usuario = null; $usuarioNombre = '';
        if (isset($recibo->id_usuario)) {
            $usuario = DB::table('usuarios')->where('id_usuario', $recibo->id_usuario)->first();
            $usuarioNombre = (string) ($usuario->usuario ?? ($usuario->nombre ?? ''));
        }
        // Detalles y observaciones
        $detalles = []; $observaciones = [];
        foreach ($cobros as $c) {
            $c = (object)$c;
            $obs = trim((string)($c->observaciones ?? ''));
            if ($obs !== '') $observaciones[] = $obs;
            if (!empty($c->id_item)) {
                try {
                    $it = DB::table('items_cobro')->where('id_item', $c->id_item)->first();
                    $detalles[] = $it ? ((string)($it->descripcion ?? $it->nombre ?? 'Item ' . $c->id_item)) : ('Item ' . $c->id_item);
                } catch (\Throwable $e) { $detalles[] = 'Item ' . $c->id_item; }
            } else {
                $numCuota = null;
                try {
                    if (!empty($c->id_asignacion_costo)) {
                        $asig = DB::table('asignacion_costos')->where('id_asignacion_costo', $c->id_asignacion_costo)->first();
                        if ($asig && isset($asig->numero_cuota)) $numCuota = (int)$asig->numero_cuota;
                    } elseif (!empty($c->id_cuota)) {
                        $asig = DB::table('asignacion_costos')->where('id_cuota_template', $c->id_cuota)->first();
                        if ($asig && isset($asig->numero_cuota)) $numCuota = (int)$asig->numero_cuota;
                    }
                } catch (\Throwable $e) {}
                $lbl = 'Mensualidad' . ($numCuota ? (' - Cuota ' . $numCuota) : '');
                $detalles[] = $lbl;
            }
        }
        $detalle = implode(' | ', array_unique(array_filter($detalles)));
        $obsLinea = implode(' | ', array_unique(array_filter($observaciones)));
        $carrera = (string)($extras['carrera'] ?? '');
        $logo = (string)($extras['logo'] ?? '');
        $logoHtml = $logo ? ('<img src="' . $logo . '" width="60" height="60" />') : '';
        $docLine = 'Doc.: F- ' . ((string)($recibo->nro_factura ?? '0')) . ', R- ' . ((string)($recibo->nro_recibo ?? ''));

        $html = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8" />
    <style>
        @page { size: 8.5in 5.5in; margin: 3mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 8.7pt; line-height: 1.12; }
        .right { text-align:right; }
        .small { font-size: 8pt; color: #333; }
        .tabla { width:100%; border-collapse: collapse; page-break-inside: avoid; }
        .tabla th, .tabla td { border: 1px solid #000; padding: 2px; }
        .sinborde td { border: none; }
        .label { background:#C8C8C8; font-weight:bold; }
        .separador { border-bottom:2px dotted #000; margin: 4px 0; }
    </style>
    <title>Recibo {$recibo->nro_recibo}/{$recibo->anio}</title>
    </head>
    <body>
        <table class="sinborde" style="width:100%">
            <tr>
                <td style="width:20%; text-align:center; vertical-align:top">{$logoHtml}</td>
                <td style="width:80%; text-align:center; vertical-align:top">
                    <div style="font-size:13pt; color:black; font-weight:bold;">Instituto Tecnológico de Enseñanza Automotriz CETA S.R.L.</div>
                    <div style="font-size:11pt; color:black; font-weight:bold;">Carrera: {$carrera}</div>
                    <div style="font-size:12pt; color:#1E2768; font-weight:bold; border-top:2px solid #000; margin-top:3px; padding-top:3px;">NOTA OTRO TIPO DE PAGO</div>
                </td>
            </tr>
        </table>
        <table class="sinborde" style="width:100%; margin-top:2px">
            <tr>
                <td style="width:60%"></td>
                <td class="right" style="width:40%">
                    N° E-{$recibo->nro_recibo}<br>
                    {$fechaLiteral}
                </td>
            </tr>
        </table>
        <table class="tabla" style="margin-top:4px">
            <tr>
                <td class="label" style="width:20%">&nbsp;Estudiante:</td>
                <td style="width:60%">{$nombre}</td>
                <td class="right" style="width:20%; font-weight:bold">{$totalFmt}</td>
            </tr>
            <tr>
                <td class="label">&nbsp;Código CETA:</td>
                <td colspan="2">{$recibo->cod_ceta}</td>
            </tr>
        </table>
        <table class="sinborde" style="width:100%; margin-top:3px">
            <tr>
                <td style="width:70%">
                    <div><span style="font-weight:bold; color:#0B2161">MONTO:</span></div>
                    <div><span style="font-weight:bold; color:#0B2161">Literal:</span> {$literal}</div>
                    <div><span style="font-weight:bold; color:#0B2161">Detalle:</span> {$detalle}</div>
                    <div><span style="font-weight:bold; color:#0B2161">Observacion:</span> {$obsLinea}</div>
                    <div><span style="font-weight:bold; color:#0B2161">{$docLine}</span></div>
                </td>
                <td class="right" style="width:30%; vertical-align:top"><div style="font-weight:bold">{$totalFmt}</div></td>
            </tr>
        </table>
        <div class="separador"></div>
        <table class="sinborde" style="width:100%; margin-top:2px">
            <tr>
                <td style="width:60%"></td>
                <td style="width:40%" class="small">{$usuarioNombre} - Firma:</td>
            </tr>
        </table>
        <div style="text-align:center; margin-top:6px">Carrera: {$carrera}</div>
        <div class="titulo" style="text-align:center; font-weight:bold; margin-top:6px">NOTA OTRO TIPO DE PAGO</div>
        <table class="sinborde" style="width:100%; margin-top:8px">
            <tr>
                <td style="width:60%; vertical-align:top">
                    <div><span style="font-weight:bold">Estudiante:</span> {$nombre}</div>
                    <div><span style="font-weight:bold">Código CETA:</span> {$recibo->cod_ceta}</div>
                    <div><span style="font-weight:bold">Detalle:</span> {$detalle}</div>
                </td>
                <td class="right" style="width:40%">
                    N° E-{$recibo->nro_recibo}<br>
                    {$fechaLiteral}
                </td>
            </tr>
        </table>
        <table class="sinborde" style="width:100%; margin-top:3px">
            <tr>
                <td class="small" style="border-top:1px solid #000; padding-top:3px">Solo para fines informativos</td>
                <td class="small right" style="border-top:1px solid #000; padding-top:3px">usuario: {$usuarioNombre}</td>
            </tr>
        </table>
    </body>
    </html>
HTML;
        return $html;
    }

    private function renderHtml(object $recibo, array $cobros, ?object $est, array $extras = []): string
    {
        $fechaDT = new \DateTime('now', new \DateTimeZone('America/La_Paz'));
        $fechaLiteral = $this->fechaLiteral($fechaDT);
        $total = (float)($recibo->monto_total ?? 0);
        $totalFmt = number_format($total, 2, '.', '');
        $literal = $this->numToLiteral($total);
        $nombre = '';
        if ($est) {
            $nombre = trim(implode(' ', array_filter([
                $est->nombres ?? '',
                $est->ap_paterno ?? '',
                $est->ap_materno ?? ''
            ])));
        }
        // Datos de usuario para footer
        $usuario = null; $usuarioNombre = '';
        if (isset($recibo->id_usuario)) {
            $usuario = DB::table('usuarios')->where('id_usuario', $recibo->id_usuario)->first();
            $usuarioNombre = (string) ($usuario->usuario ?? ($usuario->nombre ?? ''));
        }
        // Construir detalle real (qué se está cobrando) y observaciones (texto libre)
        $detalles = [];
        $observaciones = [];
        foreach ($cobros as $c) {
            $c = (object)$c;
            // Acumular observaciones si existen
            $obs = trim((string)($c->observaciones ?? ''));
            if ($obs !== '') $observaciones[] = $obs;
            // Determinar etiqueta de detalle
            if (!empty($c->id_item)) {
                try {
                    $it = DB::table('items_cobro')->where('id_item', $c->id_item)->first();
                    $nombre = $it ? ((string)($it->descripcion ?? $it->nombre ?? 'Item ' . $c->id_item)) : ('Item ' . $c->id_item);
                    $detalles[] = $nombre;
                } catch (\Throwable $e) {
                    $detalles[] = 'Item ' . $c->id_item;
                }
            } else {
                // Mensualidad (identificar cuota)
                $numCuota = null;
                try {
                    if (!empty($c->id_asignacion_costo)) {
                        $asig = DB::table('asignacion_costos')->where('id_asignacion_costo', $c->id_asignacion_costo)->first();
                        if ($asig && isset($asig->numero_cuota)) $numCuota = (int)$asig->numero_cuota;
                    } elseif (!empty($c->id_cuota)) {
                        $asig = DB::table('asignacion_costos')->where('id_cuota_template', $c->id_cuota)->first();
                        if ($asig && isset($asig->numero_cuota)) $numCuota = (int)$asig->numero_cuota;
                    }
                } catch (\Throwable $e) {}
                $lbl = 'Mensualidad' . ($numCuota ? (' - Cuota ' . $numCuota) : '');
                $detalles[] = $lbl;
            }
        }
        $detalle = implode(' | ', array_unique(array_filter($detalles)));
        $obsLinea = implode(' | ', array_unique(array_filter($observaciones)));

        $carrera = (string)($extras['carrera'] ?? '');
        $logo = (string)($extras['logo'] ?? '');
        $logoHtml = $logo ? ('<img src="' . $logo . '" width="60" height="60" />') : '';

        $html = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8" />
    <style>
        @page { size: 8.5in 5.5in; margin: 3mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 8.7pt; line-height: 1.12; }
        .encabezado { text-align:center; font-weight:bold; }
        .titulo { color:#1E2768; font-size: 10.5pt; font-weight:bold; margin-top: 0; text-align:center; }
        .right { text-align:right; }
        .small { font-size: 8pt; color: #333; }
        .tabla { width:100%; border-collapse: collapse; page-break-inside: avoid; }
        .tabla tr, .tabla td, .tabla th { page-break-inside: avoid; }
        .tabla th, .tabla td { border: 1px solid #000; padding: 2px; }
        .sinborde td { border: none; }
        .label { background:#C8C8C8; font-weight:bold; }
        .firma { border-top:1px solid #000; padding-top:3px; }
        .separador { border-bottom:2px dotted #000; margin: 4px 0; }
    </style>
    <title>Recibo {$recibo->nro_recibo}/{$recibo->anio}</title>
    </head>
    <body>
        <table class="sinborde" style="width:100%">
            <tr>
                <td style="width:20%; text-align:center; vertical-align:top">{$logoHtml}</td>
                <td style="width:80%; text-align:center; vertical-align:top">
                    <div style="font-size:13pt; color:black; font-weight:bold;">Instituto Tecnológico de Enseñanza Automotriz CETA S.R.L.</div>
                    <div style="font-size:11pt; color:black; font-weight:bold; border-bottom:2px solid #000; padding-bottom:3px;">Carrera: {$carrera}</div>
                </td>
            </tr>
        </table>
        <div class="titulo">NOTA DE REPOSICIÓN</div>
        <table class="sinborde" style="width:100%; margin-top:2px">
            <tr>
                <td style="width:60%"></td>
                <td class="right" style="width:40%">
                    ING-1<br>
                    N° E-{$recibo->nro_recibo}<br>
                    {$fechaLiteral}
                </td>
            </tr>
        </table>

        <table class="tabla" style="margin-top:4px">
            <tr>
                <td class="label" style="width:20%">&nbsp;Estudiante:</td>
                <td style="width:80%">{$nombre}</td>
            </tr>
            <tr>
                <td class="label">&nbsp;Código CETA:</td>
                <td>{$recibo->cod_ceta}</td>
            </tr>
        </table>

        <table class="sinborde" style="width:100%; margin-top:3px">
            <tr>
                <td style="width:70%">
                    <div style="color:#0B2161; font-weight:bold">MONTO:</div>
                    <div><span style="color:#0B2161; font-weight:bold">Literal:</span> {$literal}</div>
                    <div><span style="color:#0B2161; font-weight:bold">Detalle:</span> {$detalle}</div>
                    <div><span style="color:#0B2161; font-weight:bold">Observación:</span> {$obsLinea}</div>
                    <div><span style="color:#0B2161; font-weight:bold">Recibo:</span> {$recibo->nro_recibo}</div>
                </td>
                <td class="right" style="width:30%; vertical-align:top"><div style="font-weight:bold">{$totalFmt}</div></td>
            </tr>
        </table>

        <div class="separador"></div>
        <table class="sinborde" style="width:100%; margin-top:2px">
            <tr>
                <td style="width:60%"></td>
                <td style="width:40%" class="firma">{$usuarioNombre} - Firma:</td>
            </tr>
        </table>

        <table class="sinborde" style="width:100%; margin-top:2px">
            <tr>
                <td style="width:60%; vertical-align:top">
                    <div><span style="font-weight:bold">Estudiante:</span> {$nombre}</div>
                    <div><span style="font-weight:bold">Código CETA:</span> {$recibo->cod_ceta}</div>
                    <div><span style="font-weight:bold">Detalle:</span> {$detalle}</div>
                    <div><span style="font-weight:bold">Observación:</span> {$obsLinea}</div>
                </td>
                <td class="right" style="width:40%">
                    N° E-{$recibo->nro_recibo}<br>
                    {$fechaLiteral}
                </td>
            </tr>
        </table>

        <table class="sinborde" style="width:100%; margin-top:3px">
            <tr>
                <td class="small" style="border-top:1px solid #000; padding-top:3px">Solo para fines informativos</td>
                <td class="small right" style="border-top:1px solid #000; padding-top:3px">usuario: {$usuarioNombre}</td>
            </tr>
        </table>
    </body>
    </html>
HTML;
        return $html;
    }
}
