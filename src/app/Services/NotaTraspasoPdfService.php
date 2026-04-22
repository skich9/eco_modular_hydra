<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Dompdf\Dompdf;

class NotaTraspasoPdfService
{
    // ───────────────────────────────────────────────
    // PUBLIC ENTRY POINTS
    // ───────────────────────────────────────────────

    /** Genera PDF por anio + correlativo de nota_traspaso */
    public function buildPdf(int $anio, int $correlativo): string
    {
        $nota = DB::table('nota_traspaso')
            ->where('anio', $anio)
            ->where('correlativo', $correlativo)
            ->first();

        if (!$nota) {
            throw new \RuntimeException("Nota de traspaso no encontrada: {$anio}/{$correlativo}");
        }

        return $this->buildFromNota($nota);
    }

    /** Genera PDF buscando por nro_recibo */
    public function buildPdfByRecibo(int $anio, int $nroRecibo): string
    {
        $nota = DB::table('nota_traspaso')
            ->where('anio', $anio)
            ->where('nro_recibo', (string) $nroRecibo)
            ->orderByDesc('correlativo')
            ->first();

        if (!$nota) {
            throw new \RuntimeException("Nota de traspaso no encontrada para recibo {$anio}/{$nroRecibo}");
        }

        return $this->buildFromNota($nota);
    }

    /** Genera PDF buscando por nro_factura */
    public function buildPdfByFactura(int $anio, int $nroFactura): string
    {
        $nota = DB::table('nota_traspaso')
            ->where('anio', $anio)
            ->where('nro_factura', (string) $nroFactura)
            ->orderByDesc('correlativo')
            ->first();

        if (!$nota) {
            // fallback: sin filtro de año
            $nota = DB::table('nota_traspaso')
                ->where('nro_factura', (string) $nroFactura)
                ->orderByDesc('anio')
                ->orderByDesc('correlativo')
                ->first();
        }

        if (!$nota) {
            throw new \RuntimeException("Nota de traspaso no encontrada para factura {$anio}/{$nroFactura}");
        }

        return $this->buildFromNota($nota);
    }

    // ───────────────────────────────────────────────
    // CORE BUILDER
    // ───────────────────────────────────────────────

    private function buildFromNota(object $nota): string
    {
        $codCeta = (int) ($nota->cod_ceta ?? 0);

        // Estudiante
        $est = null;
        try {
            if ($codCeta > 0) {
                $est = DB::table('estudiantes')->where('cod_ceta', $codCeta)->first();
            }
        } catch (\Throwable $e) { /* no-op */ }

        // Carrera destino + cod_curso
        $carreraDestInfo = $this->resolveCarreraDestino(
            $codCeta,
            (string) ($nota->gestion_destino ?? '')
        );
        $carreraDestino = $carreraDestInfo['nombre'];
        $codCurso       = $carreraDestInfo['cod_curso'];

        // Si no se resolvió la carrera destino, usar fallback por prefijo_carrera
        if ($carreraDestino === '') {
            $carreraDestino = $this->carreraByPrefijo((string) ($nota->prefijo_carrera ?? ''));
        }

        // Logo
        $logo = '';
        try {
            $logoPath = public_path('img/logo.png');
            if (is_string($logoPath) && $logoPath !== '' && file_exists($logoPath)) {
                $raw = null;
                try { $raw = file_get_contents($logoPath); } catch (\Throwable $e) { $raw = null; }
                if (is_string($raw) && $raw !== '') {
                    $logo = 'data:image/png;base64,' . base64_encode($raw);
                } else {
                    $norm = str_replace('\\', '/', $logoPath);
                    $logo = 'file:///' . ltrim($norm, '/');
                }
            }
        } catch (\Throwable $e) { /* no-op */ }

        $html = $this->renderHtml($nota, $est, $carreraDestino, $codCurso, $logo);

        $dompdf = new Dompdf([
            'isRemoteEnabled'     => true,
            'isHtml5ParserEnabled'=> true,
            'isPhpEnabled'        => true,
        ]);
        $dompdf->loadHtml($html, 'UTF-8');
        // Medio carta (8.5 in × 5.5 in) como las otras notas
        $dompdf->setPaper([8.5 * 72, 5.5 * 72]);
        $dompdf->render();
        $pdf = $dompdf->output();

        if (empty($pdf)) {
            throw new \RuntimeException('PDF de nota de traspaso generado está vacío');
        }

        return $pdf;
    }

    // ───────────────────────────────────────────────
    // HTML RENDERER
    // ───────────────────────────────────────────────

    private function renderHtml(
        object  $nota,
        ?object $est,
        string  $carreraDestino,
        string  $codCurso,
        string  $logo
    ): string {
        // ── Datos básicos ──────────────────────────
        $anio        = (int) ($nota->anio ?? date('Y'));
        $correlativo = (int) ($nota->correlativo ?? 0);
        $monto       = (float) ($nota->monto ?? 0);
        $montoFmt    = number_format($monto, 2, '.', ',');
        $literal     = $this->numToLiteral($monto);
        $concepto    = htmlspecialchars((string) ($nota->concepto ?? ''), ENT_QUOTES, 'UTF-8');
        $observacion = htmlspecialchars((string) ($nota->observacion ?? ''), ENT_QUOTES, 'UTF-8');
        $usuario     = htmlspecialchars((string) ($nota->usuario ?? ''), ENT_QUOTES, 'UTF-8');

        // ── Número de nota TE-AANNNNNN ────────────
        $yyStr   = substr((string) $anio, -2);
        $nroNota = 'TE-' . $yyStr . str_pad((string) $correlativo, 5, '0', STR_PAD_LEFT);

        // ── Fecha de nota ─────────────────────────
        $fechaNota = '';
        try {
            $fechaDT = new \DateTime((string) ($nota->fecha_nota ?? 'now'), new \DateTimeZone('America/La_Paz'));
            $fechaNota = $this->fechaConDiaSemana($fechaDT);
        } catch (\Throwable $e) {
            $fechaNota = '';
        }

        // ── Estudiante ────────────────────────────
        $nombreEst = '';
        if ($est) {
            $nombreEst = trim(implode(' ', array_filter([
                $est->nombres    ?? '',
                $est->ap_paterno ?? '',
                $est->ap_materno ?? '',
            ])));
        }
        $codCetaStr = (string) ($nota->cod_ceta ?? '');

        // ── Carrera origen (abreviatura) ──────────
        $carreraOrigenFull = (string) ($nota->carrera_origen ?? '');
        $carreraOrigenAbrev = $this->getCarreraOrigenAbrev($carreraOrigenFull);

        // ── Mensualidades ─────────────────────────
        $cuotaOrigen   = (string) ($nota->cuota_origen   ?? '');
        $gestionOrigen = (string) ($nota->gestion_origen ?? '');
        $cuotaDest     = (string) ($nota->cuota_destino  ?? '');
        $gestionDest   = (string) ($nota->gestion_destino ?? '');

        $mensOrigen = $cuotaOrigen !== '' ? 'Cuota ' . $cuotaOrigen . ($gestionOrigen !== '' ? ' - ' . $gestionOrigen : '') : '';
        $mensDest   = $cuotaDest   !== '' ? 'Cuota ' . $cuotaDest   . ($gestionDest   !== '' ? ' - ' . $gestionDest   : '') : '';

        // ── Est. Origen ───────────────────────────
        $estOrigen = htmlspecialchars((string) ($nota->est_origen ?? ''), ENT_QUOTES, 'UTF-8');

        // ── Documento (F- / R-) ───────────────────
        $nroFactura = (string) ($nota->nro_factura ?? '0');
        if ($nroFactura === '' || $nroFactura === null) { $nroFactura = '0'; }
        $nroRecibo  = (string) ($nota->nro_recibo  ?? '0');

        // ── Logo HTML ─────────────────────────────
        $logoHtml = $logo ? '<img src="' . $logo . '" width="65" height="65" />' : '';

        // ── Identificativo fijo de la nota de traspaso ──
        $codCursoHtml = 'ING-6';

        $html = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8" />
<style>
    @page { size: 8.5in 5.5in; margin: 3mm; }
    body {
        font-family: DejaVu Sans, sans-serif;
        font-size: 8.7pt;
        line-height: 1.12;
        color: #000;
    }
    .sheet {
        width: calc(100% - 60mm);
        margin: 0 auto;
    }
    .header-table  { width: 100%; border-collapse: collapse; }
    .inst-nombre   { font-size: 13pt; font-weight: bold; }
    .inst-carrera  { font-size: 10.5pt; font-weight: bold; margin-top: 2px; }
    .titulo-nota   {
        font-size: 12pt;
        font-weight: bold;
        color: #1E2768;
        border-top: 2px solid #000;
        margin-top: 4px;
        padding-top: 3px;
        text-align: center;
    }
    .seccion-codigos { width: 100%; border-collapse: collapse; margin-top: 4px; margin-bottom: 6px; }
    .seccion-codigos td { text-align: right; font-weight: bold; font-size: 9.5pt; white-space: nowrap; line-height: 1.18; padding: 0; }
    .seccion-est   {
        width: 100%;
        border-collapse: collapse;
        margin-top: 6px;
        font-size: 8.9pt;
    }
    .seccion-est td {
        border: 1px solid #888;
        padding: 2px 4px;
    }
    .seccion-est .row-lbl {
        background: #f5f5f5;
        font-weight: bold;
        white-space: nowrap;
    }
    .lbl           { font-weight: bold; white-space: nowrap; }
    .det-table     {
        width: 100%;
        border-collapse: collapse;
        margin-top: 8px;
        font-size: 9pt;
    }
    .det-table th  {
        background: #C8A951;
        color: #000;
        font-weight: bold;
        border: 1px solid #888;
        padding: 3px 6px;
        text-align: center;
    }
    .det-table td  {
        border: 1px solid #888;
        padding: 3px 6px;
    }
    .det-table .row-lbl {
        background: #f5f5f5;
        font-weight: bold;
        white-space: nowrap;
    }
    .det-title-row td {
        text-align: center;
        font-weight: bold;
        font-size: 9.5pt;
        border: none;
        padding-bottom: 3px;
    }
    .monto-block   { margin-top: 10px; font-size: 8.9pt; }
    .monto-linea   { margin-bottom: 2px; }
    .monto-valor   { float: right; font-weight: bold; }
    .footer-user   { text-align: right; margin-top: 18px; font-size: 8.5pt; color: #333; }
    .bs-label      { color: #1E2768; font-weight: bold; }
    .clearfix::after { content: ''; display: block; clear: both; }
</style>
<title>Nota de Traspaso {$nroNota}</title>
</head>
<body>
<div class="sheet">

<!-- ═══ ENCABEZADO ═══ -->
<table class="header-table">
    <tr>
        <td style="width:14%; text-align:center; vertical-align:middle;">{$logoHtml}</td>
        <td style="width:68%; text-align:center; vertical-align:middle; padding: 0 8px;">
            <div class="inst-nombre">Instituto Tecnológico de Enseñanza Automotriz CETA S.R.L.</div>
            <div class="inst-carrera">Carrera: {$carreraDestino}</div>
            <div class="titulo-nota">NOTA DE TRASPASO</div>
        </td>
    </tr>
</table>

<table class="seccion-codigos">
    <tr>
        <td>
            {$codCursoHtml}<br>
            N° {$nroNota}<br>
            {$fechaNota}
        </td>
    </tr>
</table>

<!-- ═══ DATOS DEL ESTUDIANTE ═══ -->
<table class="seccion-est">
    <tr>
        <td class="row-lbl" style="width:14%;">&nbsp;Estudiante:</td>
        <td>{$nombreEst}</td>
    </tr>
    <tr>
        <td class="row-lbl">&nbsp;Código CETA:</td>
        <td>{$codCetaStr}</td>
    </tr>
</table>

<!-- ═══ DETALLE DE TRASPASO ═══ -->
<table class="det-table" style="margin-top:12px;">
    <tr class="det-title-row">
        <td colspan="3">Detalle de traspaso</td>
    </tr>
    <tr>
        <th style="width:22%;">&nbsp;</th>
        <th style="width:28%;">ORIGEN / DE</th>
        <th style="width:50%;">DESTION / A</th>
    </tr>
    <tr>
        <td class="row-lbl">CARRERA:</td>
        <td style="text-align:center;">{$carreraOrigenAbrev}</td>
        <td>{$carreraDestino}</td>
    </tr>
    <tr>
        <td class="row-lbl">MENSUALIDAD:</td>
        <td style="text-align:center;">{$mensOrigen}</td>
        <td>{$mensDest}</td>
    </tr>
    <tr>
        <td class="row-lbl">Est. Origen:</td>
        <td colspan="2" style="text-align:center;">{$estOrigen}</td>
    </tr>
</table>

<!-- ═══ MONTO Y DATOS FINALES ═══ -->
<div class="monto-block">
    <div class="monto-linea clearfix">
        <span class="bs-label">MONTO:</span>
        <span class="monto-valor">Bs. {$montoFmt}</span>
    </div>
    <div class="monto-linea">
        <span class="bs-label">Literal:</span> {$literal}
    </div>
    <div class="monto-linea">
        <span class="bs-label">Detalle:</span> {$concepto}
    </div>
    <div class="monto-linea">
        <span class="bs-label">Observacion:</span> {$observacion}
    </div>
    <div class="monto-linea">
        <span class="bs-label">Documento:</span> F- {$nroFactura}, R- {$nroRecibo}
    </div>
</div>

<!-- ═══ FOOTER ═══ -->
<div class="footer-user">usuario: {$usuario}</div>

</div>
</body>
</html>
HTML;

        return $html;
    }

    // ───────────────────────────────────────────────
    // HELPERS
    // ───────────────────────────────────────────────

    /** Resuelve nombre de carrera destino y cod_curso del estudiante para la gestión destino */
    private function resolveCarreraDestino(int $codCeta, string $gestionDestino): array
    {
        $result = ['nombre' => '', 'cod_curso' => ''];

        if ($codCeta <= 0) {
            return $result;
        }

        try {
            $query = DB::table('inscripciones')
                ->where('cod_ceta', $codCeta);

            if ($gestionDestino !== '') {
                $query->where('gestion', $gestionDestino);
            }

            $insc = $query->orderByDesc('cod_inscrip')->first();

            if ($insc) {
                // carrera nombre
                if (!empty($insc->carrera)) {
                    $result['nombre'] = trim((string) $insc->carrera);
                } elseif (!empty($insc->cod_pensum)) {
                    $row = DB::table('pensums')
                        ->leftJoin('carrera', 'carrera.codigo_carrera', '=', 'pensums.codigo_carrera')
                        ->where('pensums.cod_pensum', $insc->cod_pensum)
                        ->select([
                            'carrera.nombre as carrera_nombre',
                            'pensums.nombre  as pensum_nombre',
                        ])
                        ->first();
                    if ($row) {
                        $result['nombre'] = trim((string) ($row->carrera_nombre ?: $row->pensum_nombre ?: ''));
                    }
                }

                // cod_curso
                if (!empty($insc->cod_curso)) {
                    $result['cod_curso'] = trim((string) $insc->cod_curso);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('NotaTraspasoPdfService: resolveCarreraDestino error', ['error' => $e->getMessage()]);
        }

        return $result;
    }

    /** Fallback para carrera destino basado en prefijo_carrera ('E' o 'M') */
    private function carreraByPrefijo(string $prefijo): string
    {
        $p = strtoupper(trim($prefijo));
        if ($p === 'M') { return 'Mecánica Automotriz'; }
        if ($p === 'E') { return 'Electricidad y Electrónica Automotriz'; }
        return '';
    }

    /** Convierte nombre de carrera a abreviatura (EEA / MEA / SEC / original) */
    private function getCarreraOrigenAbrev(string $carreraNombre): string
    {
        if ($carreraNombre === '') { return ''; }

        $n = strtoupper((string) @iconv('UTF-8', 'ASCII//TRANSLIT', $carreraNombre));
        if ($n === false || $n === '') {
            $n = strtoupper(preg_replace('/[^A-Za-z0-9 ]/u', '', $carreraNombre));
        }

        if (strpos($n, 'ELECTRON') !== false || strpos($n, 'ELECTRIC') !== false) {
            return 'EEA';
        }
        if (strpos($n, 'MECAN') !== false || strpos($n, 'AUTOMOTR') !== false) {
            return 'MEA';
        }
        if (strpos($n, 'SECRETAR') !== false) {
            return 'SEC';
        }

        // fallback: devolver nombre original
        return htmlspecialchars($carreraNombre, ENT_QUOTES, 'UTF-8');
    }

    /** Fecha larga con día de semana, e.g. "Lunes, 20 de abril de 2026" */
    private function fechaConDiaSemana(\DateTimeInterface $dt): string
    {
        $dias = [
            0 => 'Domingo',
            1 => 'Lunes',
            2 => 'Martes',
            3 => 'Miércoles',
            4 => 'Jueves',
            5 => 'Viernes',
            6 => 'Sábado',
        ];
        $meses = [
            1 => 'enero',    2 => 'febrero',   3 => 'marzo',
            4 => 'abril',    5 => 'mayo',       6 => 'junio',
            7 => 'julio',    8 => 'agosto',     9 => 'septiembre',
            10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
        ];

        try {
            $diaSemana = (int) $dt->format('w'); // 0=domingo
            $dia       = (int) $dt->format('d');
            $mes       = (int) $dt->format('n');
            $anio      = (string) $dt->format('Y');
            return ($dias[$diaSemana] ?? '') . ', ' . $dia . ' de ' . ($meses[$mes] ?? $dt->format('m')) . ' de ' . $anio;
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** Convierte monto numérico a literal en español */
    private function numToLiteral(float $monto): string
    {
        $entero   = (int) floor($monto);
        $centavos = (int) round(($monto - $entero) * 100);

        $u = ['', 'uno', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve',
              'diez', 'once', 'doce', 'trece', 'catorce', 'quince',
              'dieciséis', 'diecisiete', 'dieciocho', 'diecinueve'];
        $d = ['', 'diez', 'veinte', 'treinta', 'cuarenta', 'cincuenta',
              'sesenta', 'setenta', 'ochenta', 'noventa'];
        $c = ['', 'cien', 'doscientos', 'trescientos', 'cuatrocientos', 'quinientos',
              'seiscientos', 'setecientos', 'ochocientos', 'novecientos'];

        $toWords2 = function (int $n) use ($u, $d): string {
            if ($n < 20) { return $u[$n]; }
            $tens = intdiv($n, 10); $ones = $n % 10;
            if ($tens === 2 && $ones > 0) { return 'veinti' . $u[$ones]; }
            $sep = $ones ? ' y ' : '';
            return $d[$tens] . ($sep ? $sep . $u[$ones] : '');
        };

        $toWords3 = function (int $n) use ($c, $toWords2): string {
            if ($n === 0)   { return ''; }
            if ($n === 100) { return 'cien'; }
            $hund = intdiv($n, 100); $rest = $n % 100;
            $pref = $hund ? ($hund === 1 ? 'ciento' : $c[$hund]) : '';
            $mid  = $rest ? ($pref ? ' ' : '') . $toWords2($rest) : '';
            return trim($pref . $mid);
        };

        $toWords = function (int $n) use ($toWords3): string {
            if ($n === 0) { return 'cero'; }
            $parts   = [];
            $millones = intdiv($n, 1_000_000); $n %= 1_000_000;
            $miles    = intdiv($n, 1_000);     $n %= 1_000;
            if ($millones) { $parts[] = ($millones === 1 ? 'un millón' : $toWords3($millones) . ' millones'); }
            if ($miles)    { $parts[] = ($miles === 1    ? 'mil'        : $toWords3($miles)    . ' mil'); }
            if ($n)        { $parts[] = $toWords3($n); }
            return trim(implode(' ', $parts));
        };

        return strtoupper($toWords($entero)) . ' ' . str_pad((string) $centavos, 2, '0', STR_PAD_LEFT) . '/100 Bs.';
    }
}
