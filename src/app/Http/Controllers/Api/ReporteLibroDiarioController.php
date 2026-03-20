<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LibroDiarioPdfService;
use App\Services\LibroDiarioIdentificadorHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ReporteLibroDiarioController extends Controller
{
    /**
     * Filas de datos por bloque/página lógica (antes de la fila "Subtotal Página N") cuando hay `datos[]`.
     *
     * Estimación con body compacto (8px, padding mínimo): A4 ≈ 842pt; márgenes @page base
     * (LIBRO_DIARIO_PAGE_MARGIN_TOP_PT + BOTTOM_PT, ajustables con body_vertical_offset_*)
     * → ~562pt de área de contenido aprox.; descontando ~14pt thead + ~12pt subtotal ≈ 536pt para filas de datos.
     * Fila de una línea ~9–11pt en Dompdf; con texto multilínea baja el conteo → valor fijo conservador para diseño/pruebas.
     * Sobrescribir con request `filas_por_pagina` (5–60).
     */
    private const LIBRO_DIARIO_FILAS_POR_PAGINA_DEFAULT = 60;

    /**
     * Margen @page superior base (pt): zona reservada encima del flujo del body (evita solape con header fijo).
     * Bajar este valor = sube el body (más espacio vertical para la tabla). Subir = baja el body.
     * El margin-top de .libro-header se recalcula solo (LIBRO_DIARIO_PAGE_PLUS_HEADER_TOP_SUM_PT).
     */
    private const LIBRO_DIARIO_PAGE_MARGIN_TOP_PT = 150;

    /**
     * Margen @page inferior base (pt): reserva para pie fijo + numeración.
     */
    private const LIBRO_DIARIO_PAGE_MARGIN_BOTTOM_PT = 94;

    /**
     * Invariante Dompdf: marginTop(@page) + margin-top(header fijo) = esta suma (pt).
     * Con header en top:0 y margin negativo, mantener la suma al afinar el margen superior.
     */
    private const LIBRO_DIARIO_PAGE_PLUS_HEADER_TOP_SUM_PT = 16;

    /**
     * Invariante: marginBottom(@page) + margin-bottom(footer fijo) = esta suma (pt).
     */
    private const LIBRO_DIARIO_PAGE_PLUS_FOOTER_BOTTOM_SUM_PT = 18;

    /**
     * Genera el PDF del Libro Diario a partir de HTML/datos enviados por el frontend.
     * Estructura: Header (logo hasta hora cierre), Body (tabla de datos), Footer (totales y firmas).
     * Si llega el array datos, el tbody se trocea en páginas lógicas: cada hoja incluye N filas
     * + una fila de subtotal (suma de ingreso/ingresos solo de esas filas). Opcional: filas_por_pagina (5–60).
     *
     * Espera:
     * - contenido: string (filas <tr> con 11 columnas), usado si datos está vacío
     * - datos: array opcional de items (ingreso o ingresos); prioridad sobre contenido
     * - usuario, fecha, resumen
     * - body_vertical_offset_pt (opcional, int -6..6, default 0): positivo baja el body (más margen sup.);
     *   negativo sube el body (menos margen sup.). Mantiene coherencia con .libro-header.
     * - body_vertical_offset_bottom_pt (opcional, int -6..6, default 0): positivo reduce altura útil inferior;
     *   negativo acerca el límite del flujo al pie (más filas visibles; riesgo si se exagera).
     */
    public function imprimir(Request $request)
    {
        try {
            $contenido = (string)$request->input('contenido', '');
            $datos = $request->input('datos', []);
            $usuario = (string)$request->input('usuario', '');
            $usuarioDisplay = (string)$request->input('usuario_display', $usuario);
            $fecha = (string)$request->input('fecha', '');
            $resumen = $request->input('resumen', []) ?: [];
            $horaApertura = (string)($resumen['hora_apertura'] ?? $request->input('hora_apertura', ''));

            $resumen = is_array($resumen) ? $resumen : [];
            $fmt = function ($v) {
                return number_format((float)($v ?? 0), 2, '.', '');
            };
            $get = function ($key, $sub) use ($resumen) {
                return ($resumen[$key] ?? [])[$sub] ?? 0;
            };
            $fTraspaso = $fmt($get('traspaso', 'factura'));
            $rTraspaso = $fmt($get('traspaso', 'recibo'));
            $fDeposito = $fmt($get('deposito', 'factura'));
            $rDeposito = $fmt($get('deposito', 'recibo'));
            $fEfectivo = $fmt($get('efectivo', 'factura'));
            $rEfectivo = $fmt($get('efectivo', 'recibo'));
            $fCheque = $fmt($get('cheque', 'factura'));
            $rCheque = $fmt($get('cheque', 'recibo'));
            $fTarjeta = $fmt($get('tarjeta', 'factura'));
            $rTarjeta = $fmt($get('tarjeta', 'recibo'));
            $fTransferencia = $fmt($get('transferencia', 'factura'));
            $rTransferencia = $fmt($get('transferencia', 'recibo'));
            $fOtro = $fmt($get('otro', 'factura'));
            $rOtro = $fmt($get('otro', 'recibo'));
            $tFactura = $fmt($resumen['total_factura'] ?? 0);
            $tRecibo = $fmt($resumen['total_recibo'] ?? 0);
            $totalEfectivo = $fmt($resumen['total_efectivo'] ?? 0);
            $totalGeneral = $fmt($resumen['total_general'] ?? $request->input('totales', 0));

            if ($contenido === '' || $usuario === '' || $fecha === '') {
                return response()->json([
                    'success' => false,
                    'url' => '',
                    'message' => 'contenido, usuario y fecha son requeridos para imprimir Libro Diario',
                ], 400);
            }

            $fechaLiteral = $fecha;
            $fechaCorta = $fecha;
            try {
                if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $fecha)) {
                    $dt = \DateTime::createFromFormat('d/m/Y', $fecha);
                } else {
                    $dt = new \DateTime($fecha);
                }
                if ($dt) {
                    $dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
                    $meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
                    $diaSemana = $dias[(int)$dt->format('w')];
                    $dia = $dt->format('d');
                    $mes = $meses[(int)$dt->format('n') - 1];
                    $anio = $dt->format('Y');
                    $fechaLiteral = ucfirst($diaSemana) . ', ' . $dia . ' de ' . $mes . ' de ' . $anio;
                    $fechaCorta = $dt->format('Y-m-d');
                }
            } catch (\Throwable $e) {
            }

            $horaCierre = $this->resolverHoraCierreLibroDiarioPdf($usuario, $fechaCorta, $resumen, $request);

            $carreraVal = (string)($resumen['carrera'] ?? $request->input('carrera', ''));
            if ($carreraVal === '') {
                $carreraVal = '___________________________';
            }

            // Generar código RD-{carrera}-{mes}-{orden} dinámicamente (como en SGA)
            $numeracion = $this->generarNumeracionLibroDiario($request, $resumen, $fechaCorta, $usuario);

            $now = new \DateTime('now', new \DateTimeZone('America/La_Paz'));
            $fechaHoraImp = $this->formatearFechaHoraImpresionPieEspanol($now);

            $styleBorder = 'border: 1px solid #000066;';
            $styleColor = 'color: #000066; font-weight: bold;';

            // Logo compacto (sin height fijo excesivo en la celda)
            $logoW = 48;
            $logoH = 48;
            $logoImg = '';
            $logoPath = public_path('img' . DIRECTORY_SEPARATOR . 'logo.png');
            if (is_file($logoPath) && is_readable($logoPath)) {
                $info = @getimagesize($logoPath);
                if ($info && isset($info[0], $info[1])) {
                    $logoW = min(56, max(36, (int)$info[0]));
                    $logoH = min(56, max(36, (int)$info[1]));
                }
                $logoB64 = base64_encode(file_get_contents($logoPath));
                $logoImg = '<img src="data:image/png;base64,' . $logoB64 . '" width="' . $logoW . '" height="' . $logoH . '" style="display:block;margin:0 auto;vertical-align:middle;border:0;outline:none;" alt="Logo" />';
            }
            $logoPad = 2;
            // border-right:none evita doble trazo vertical logo|texto con Dompdf (colapso imperfecto entre celdas)
            $logoCellStyle = 'width:' . ($logoW + $logoPad * 2) . 'px; min-width:' . ($logoW + $logoPad * 2) . 'px; max-width:' . ($logoW + $logoPad * 2) . 'px; padding:' . $logoPad . 'px; ' . $styleBorder . ' border-right:none; vertical-align:middle; text-align:center; line-height:0;';

            $contenidoBody = $contenido;
            if (is_array($datos) && count($datos) > 0) {
                $filasPorPagina = (int) $request->input('filas_por_pagina', self::LIBRO_DIARIO_FILAS_POR_PAGINA_DEFAULT);
                if ($filasPorPagina < 1) {
                    $filasPorPagina = self::LIBRO_DIARIO_FILAS_POR_PAGINA_DEFAULT;
                }
                $filasPorPagina = max(5, min(60, $filasPorPagina));
                $contenidoBody = $this->construirFilasLibroDiarioConSubtotalesPorPagina(
                    $datos,
                    $fmt,
                    $styleBorder,
                    $styleColor,
                    $filasPorPagina
                );
            }

            $headerHtml = $this->buildHeaderHtml($logoImg, $logoCellStyle, $carreraVal, $fechaLiteral, $usuarioDisplay, $horaApertura, $horaCierre, $numeracion, $styleBorder, $styleColor);
            $totalesYFirmasHtml = $this->buildTotalesYFirmasHtml($fTraspaso, $rTraspaso, $fDeposito, $rDeposito, $fEfectivo, $rEfectivo, $fCheque, $rCheque, $fTarjeta, $rTarjeta, $fTransferencia, $rTransferencia, $fOtro, $rOtro, $tFactura, $tRecibo, $totalEfectivo, $totalGeneral, $styleBorder, $styleColor);
            $footerHtml = $this->buildFooterHtml($usuarioDisplay, $fecha, $fechaHoraImp, $styleBorder, $styleColor);

            $offsetTop = (int) $request->input('body_vertical_offset_pt', 0);
            $offsetTop = max(-6, min(6, $offsetTop));
            $offsetBottom = (int) $request->input('body_vertical_offset_bottom_pt', 0);
            $offsetBottom = max(-6, min(6, $offsetBottom));

            $pageMarginTop = self::LIBRO_DIARIO_PAGE_MARGIN_TOP_PT + $offsetTop;
            $pageMarginBottom = self::LIBRO_DIARIO_PAGE_MARGIN_BOTTOM_PT + $offsetBottom;
            $headerMarginTop = self::LIBRO_DIARIO_PAGE_PLUS_HEADER_TOP_SUM_PT - $pageMarginTop;
            $footerMarginBottom = self::LIBRO_DIARIO_PAGE_PLUS_FOOTER_BOTTOM_SUM_PT - $pageMarginBottom;

            $html = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8" />
    <title>Libro Diario</title>
    <style>
        /* Márgenes @page: espacio entre bordes de hoja y flujo del body (coordinados con header/footer fijos) */
        @page {
            size: A4;
            margin: {$pageMarginTop}pt 1cm {$pageMarginBottom}pt 1cm;
        }
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #000; line-height: 1.12; margin: 0; padding: 0; }
        /* Cuerpo del reporte: tabla compacta 8px; anchos guiados por contenido (cabecera / filas) salvo Observación */
        .tabla {
            width: 100%;
            table-layout: auto;
            border-collapse: collapse;
            {$styleBorder};
            margin: 0;
        }
        .tabla th, .tabla td {
            border: 1px solid #000066;
            padding: 0 1px;
            margin: 0;
            font-size: 8px;
            line-height: 1.05;
            vertical-align: middle;
        }
        .tabla th { background: #e8ecf4; color: #000066; text-align: center; font-weight: bold; padding: 1px 2px; }
        .tabla thead { display: table-header-group; }
        /* Columnas 1–7 y 9–11: ancho mínimo según contenido (nowrap → la columna crece con el texto más ancho de la columna) */
        .tabla th:not(:nth-child(8)),
        .tabla td:not(:nth-child(8)) {
            white-space: nowrap;
            width: 1%;
            max-width: none;
        }
        /* Observación (columna 8): flexible; absorbe espacio sobrante y permite varias líneas sin forzar el ancho de las demás */
        .tabla th:nth-child(8),
        .tabla td:nth-child(8) {
            width: auto;
            min-width: 4em;
            white-space: normal;
            word-wrap: break-word;
            overflow-wrap: break-word;
            vertical-align: middle;
        }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .right { text-align: right; }
        .center { text-align: center; }
        .libro-header {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1;
            background: #fff;
            font-size: 9pt;
            font-weight: bold;
            line-height: 1.1;
            margin: 0;
            padding: 0 0 2px 0;
            margin-top: {$headerMarginTop}pt;
            outline: none;
            box-shadow: none;
        }
        .libro-header img { border: 0; outline: none; vertical-align: middle; }
        .libro-body {
            margin: 0;
            padding: 0;
            position: relative;
        }
        /* Tipografía única del pie (alineada con numeración en LibroDiarioPdfService: DejaVu Sans 7.5pt) */
        .libro-footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            z-index: 1;
            background: #fff;
            font-family: DejaVu Sans, sans-serif;
            font-size: 7.5pt;
            font-weight: normal;
            line-height: 1.1;
            color: #000;
            margin: 0;
            padding: 0 0 1px 0;
            margin-bottom: {$footerMarginBottom}pt;
        }
        .libro-footer table {
            font-family: DejaVu Sans, sans-serif;
            font-size: 7.5pt;
            font-weight: normal;
            line-height: 1.1;
        }
    </style>
</head>
<body>
    <header class="libro-header">{$headerHtml}</header>
    <footer class="libro-footer">{$footerHtml}</footer>

    <main class="libro-body">
        <table class="tabla">
            <thead>
                <tr>
                    <th>P</th>
                    <th>Nº</th>
                    <th>Recibo</th>
                    <th>Factura</th>
                    <th>Razón Social</th>
                    <th>NIT - C.I.</th>
                    <th>Concepto</th>
                    <th>Observación</th>
                    <th>Código CETA</th>
                    <th>Hora</th>
                    <th>Ingresos</th>
                </tr>
            </thead>
            <tbody>
{$contenidoBody}
            </tbody>
        </table>

        <div class="totales-ultima-pagina" style="margin-top: 8pt; page-break-inside: avoid;">
{$totalesYFirmasHtml}
        </div>
    </main>
    <!-- Numeración: LibroDiarioPdfService (callback end_document) para pintarla al final y que no la tape la última página. -->
</body>
</html>
HTML;

            $svc = new LibroDiarioPdfService();
            $path = $svc->generate($html, $usuario, $fechaCorta, $fechaHoraImp);

            if (!is_file($path) || !is_readable($path)) {
                return response()->json([
                    'success' => false,
                    'url' => '',
                    'message' => 'No se pudo generar el PDF del Libro Diario',
                ], 500);
            }

            // Exponer el archivo vía /reportes/libro_diario/...
            $publicRoot = public_path();
            $relPath = str_replace($publicRoot, '', $path);
            $relPath = ltrim(str_replace(DIRECTORY_SEPARATOR, '/', $relPath), '/');
            $url = url($relPath);

            Log::info('[ReporteLibroDiarioController] PDF generado', [
                'path' => $path,
                'url' => $url,
            ]);

            return response()->json([
                'success' => true,
                'url' => $url,
                'message' => 'PDF generado exitosamente',
            ]);
        } catch (\Throwable $e) {
            Log::error('[ReporteLibroDiarioController] imprimir exception', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'url' => '',
                'message' => 'Error interno al generar PDF del Libro Diario',
            ], 500);
        }
    }

    /**
     * Código RD-[CARRERA]-[MM]-[NNN]: NNN correlativo global con mínimo 3 dígitos (ceros a la izquierda).
     * Prioriza codigo_rd almacenado en libro_diario_cierre o enviado en resumen/request.
     */
    private function generarNumeracionLibroDiario($request, array $resumen, string $fechaCorta, string $usuario): string
    {
        $idUsuario = (int) $usuario;

        $codigoRd = trim((string) ($resumen['codigo_rd'] ?? $request->input('codigo_rd', '')));
        if ($codigoRd !== '') {
            return $codigoRd;
        }

        $codigoCarrera = trim((string) ($resumen['codigo_carrera'] ?? $request->input('codigo_carrera', '')));

        $mes = date('m');
        try {
            if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $fechaCorta)) {
                $dt = \DateTime::createFromFormat('d/m/Y', $fechaCorta);
            } else {
                $dt = new \DateTime($fechaCorta);
            }
            if ($dt) {
                $mes = $dt->format('m');
            }
        } catch (\Throwable $e) {
        }

        $rowCierre = null;
        if (Schema::hasTable('libro_diario_cierre') && $idUsuario > 0 && $fechaCorta !== '') {
            try {
                $idRow = (int) ($resumen['id_libro_diario_cierre'] ?? $request->input('id_libro_diario_cierre', 0));
                if ($idRow > 0) {
                    $rowCierre = DB::table('libro_diario_cierre')->where('id', $idRow)->first();
                }
                if (! $rowCierre) {
                    $orden = (int) ($resumen['orden_cierre'] ?? $request->input('orden_cierre', 0));
                    $q = DB::table('libro_diario_cierre')
                        ->where('id_usuario', $idUsuario)
                        ->where('fecha', $fechaCorta);
                    if ($orden > 0) {
                        $q->where('orden_cierre', $orden);
                    }
                    $rowCierre = $q->orderBy('id', 'desc')->first();
                }
            } catch (\Throwable $e) {
            }
        }

        if ($rowCierre && ! empty($rowCierre->codigo_rd)) {
            return trim((string) $rowCierre->codigo_rd);
        }

        if ($codigoCarrera === '' && $rowCierre && isset($rowCierre->codigo_carrera) && trim((string) $rowCierre->codigo_carrera) !== '') {
            $codigoCarrera = trim((string) $rowCierre->codigo_carrera);
        }

        if ($codigoCarrera === '') {
            $codigoCarrera = 'S/N';
        }

        $corrNum = 1;
        if ($rowCierre && isset($rowCierre->correlativo) && (int) $rowCierre->correlativo > 0) {
            $corrNum = (int) $rowCierre->correlativo;
        } elseif ($rowCierre && isset($rowCierre->id)) {
            $corrNum = max(1, (int) $rowCierre->id);
        } else {
            $corrNum = max(1, (int) ($resumen['correlativo'] ?? $request->input('correlativo', 0)));
            if ($corrNum < 1) {
                $corrNum = (int) ($resumen['orden_cierre'] ?? $request->input('orden_cierre', 1));
            }
            if ($corrNum < 1) {
                $corrNum = 1;
            }
        }

        return LibroDiarioIdentificadorHelper::construirCodigoRd($codigoCarrera, $mes, $corrNum);
    }

    /**
     * Genera filas de datos + una fila de subtotal por cada "página lógica" del PDF.
     * Suma solo ingreso/ingresos del bloque actual; page-break-before alinea con hojas siguientes.
     */
    private function construirFilasLibroDiarioConSubtotalesPorPagina(
        array $datos,
        callable $fmt,
        string $styleBorder,
        string $styleColor,
        int $filasPorPagina
    ): string {
        $chunks = array_chunk($datos, $filasPorPagina);
        $html = '';
        $numGlobal = 0;

        foreach ($chunks as $idx => $chunk) {
            if ($idx > 0) {
                $html .= '<tr style="page-break-before: always; height: 0;"><td colspan="11" style="height: 0; padding: 0; margin: 0; border: none; line-height: 0;"></td></tr>';
            }

            $subtotalPag = 0.0;
            foreach ($chunk as $item) {
                $numGlobal++;
                $it = is_array($item) ? $item : (array) $item;
                $ing = (float) ($it['ingreso'] ?? $it['ingresos'] ?? 0);
                $subtotalPag += $ing;

                $p = htmlspecialchars((string) ($it['tipo_pago'] ?? 'E'));
                $razon = htmlspecialchars((string) ($it['razon'] ?? ''));
                $nit = htmlspecialchars((string) (isset($it['nit']) && $it['nit'] !== '0' ? ($it['nit'] ?? '') : ''));
                $concepto = htmlspecialchars((string) ($it['concepto'] ?? ''));
                $obs = htmlspecialchars((string) ($it['observaciones'] ?? ''));
                $codCeta = htmlspecialchars((string) (isset($it['cod_ceta']) && $it['cod_ceta'] !== '0' ? ($it['cod_ceta'] ?? '') : ''));
                $hora = htmlspecialchars((string) ($it['hora'] ?? ''));
                $ingresoStr = $ing > 0 ? $fmt($ing) : '';

                $estilo = $styleBorder . ' padding:0 1px;margin:0;vertical-align:middle;font-size:8px;line-height:1.05;';
                $html .= '<tr id="' . $numGlobal . '">';
                $html .= '<td class="text-center" style="' . $estilo . '">' . $p . '</td>';
                $html .= '<td class="text-center" style="' . $estilo . '">' . $numGlobal . '</td>';
                $html .= '<td class="text-center" style="' . $estilo . '">' . htmlspecialchars($it['recibo'] ?? '0') . '</td>';
                $html .= '<td class="text-center" style="' . $estilo . '">' . htmlspecialchars($it['factura'] ?? '0') . '</td>';
                $html .= '<td style="' . $estilo . '">' . $razon . '</td>';
                $html .= '<td class="text-center" style="' . $estilo . '">' . $nit . '</td>';
                $html .= '<td style="' . $estilo . '">' . $concepto . '</td>';
                $html .= '<td style="' . $estilo . '">' . $obs . '</td>';
                $html .= '<td class="text-center" style="' . $estilo . '">' . $codCeta . '</td>';
                $html .= '<td class="text-center" style="' . $estilo . '">' . $hora . '</td>';
                $html .= '<td class="text-right" style="' . $estilo . '">' . $ingresoStr . '</td>';
                $html .= '</tr>';
            }

            $numPag = $idx + 1;
            $html .= '<tr class="subtotal-pagina" style="background:#e8ecf4; page-break-inside: avoid;">'
                . '<td colspan="10" style="' . $styleBorder . ' padding:1px 2px; margin:0; font-size:8px; line-height:1.05; vertical-align:middle; ' . $styleColor . ' text-align:right;">Subtotal Página ' . $numPag . ':</td>'
                . '<td style="' . $styleBorder . ' padding:1px 2px; margin:0; font-size:8px; line-height:1.05; vertical-align:middle; text-align:right; font-weight:bold;">' . $fmt($subtotalPag) . '</td>'
                . '</tr>';
        }

        return $html;
    }

    private function buildHeaderHtml(string $logoImg, string $logoCellStyle, string $carreraVal, string $fechaLiteral, string $usuarioDisplay, string $horaApertura, string $horaCierre, string $numeracion, string $styleBorder, string $styleColor): string
    {
        $carreraVal = htmlspecialchars($carreraVal, ENT_QUOTES, 'UTF-8');
        $fechaLiteral = htmlspecialchars($fechaLiteral, ENT_QUOTES, 'UTF-8');
        $usuarioDisplay = htmlspecialchars($usuarioDisplay, ENT_QUOTES, 'UTF-8');
        $horaAperturaDisplay = $horaApertura !== '' ? htmlspecialchars($this->formatearHora($horaApertura), ENT_QUOTES, 'UTF-8') : '____________';
        $horaCierreDisplay = $horaCierre !== '' ? htmlspecialchars($this->formatearHora($horaCierre), ENT_QUOTES, 'UTF-8') : '____________';
        $numeracion = htmlspecialchars($numeracion, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<table width="100%" style="border-collapse:collapse;{$styleBorder};background:#fff;font-family:DejaVu Sans,sans-serif;margin:0;line-height:1.1;">
  <tr>
    <td rowspan="3" style="{$logoCellStyle}">{$logoImg}</td>
    <td style="{$styleBorder}padding:1px 4px;{$styleColor}text-align:center;font-size:7.5pt;line-height:1.1;vertical-align:middle;">
      Instituto Tecnológico de Enseñanza Automotriz "CETA"
    </td>
    <td style="{$styleBorder}padding:1px 3px;{$styleColor}text-align:center;font-size:7.5pt;font-weight:bold;line-height:1.1;vertical-align:middle;">Nº:</td>
    <td style="{$styleBorder}padding:1px 3px;{$styleColor}text-align:center;font-size:7.5pt;line-height:1.1;vertical-align:middle;">{$numeracion}</td>
  </tr>
  <tr>
    <td rowspan="2" style="{$styleBorder}padding:2px 5px;{$styleColor}text-align:center;font-size:11pt;font-weight:bold;line-height:1.08;vertical-align:middle;">
      REPORTE DIARIO DE INGRESOS</td>
    <td style="{$styleBorder}padding:1px 3px;{$styleColor}text-align:center;font-size:7.5pt;font-weight:bold;line-height:1.1;vertical-align:middle;">CODIGO:</td>
    <td style="{$styleBorder}padding:1px 3px;{$styleColor}text-align:center;font-size:7.5pt;line-height:1.1;vertical-align:middle;">ING-2</td>
  </tr>
  <tr>
    <td style="{$styleBorder}padding:1px 3px;{$styleColor}text-align:center;font-size:7.5pt;font-weight:bold;line-height:1.1;vertical-align:middle;">Versión:</td>
    <td style="{$styleBorder}padding:1px 3px;{$styleColor}text-align:center;font-size:7.5pt;line-height:1.1;vertical-align:middle;">V.0.</td>
  </tr>
</table>
<table width="100%" style="border-collapse:collapse;margin-top:10px;padding:0;font-size:8pt;font-weight:normal;color:#000;line-height:1.12;">
  <tr><td style="padding:0 0 1px 0;width:92px;vertical-align:middle;"><strong>Carrera:</strong></td><td style="padding:0 0 1px 0;vertical-align:middle;">{$carreraVal}</td></tr>
  <tr><td style="padding:0 0 1px 0;vertical-align:middle;"><strong>Fecha:</strong></td><td style="padding:0 0 1px 0;vertical-align:middle;">{$fechaLiteral}</td></tr>
  <tr><td style="padding:0 0 1px 0;vertical-align:middle;"><strong>Usuario:</strong></td><td style="padding:0 0 1px 0;vertical-align:middle;">{$usuarioDisplay}</td></tr>
  <tr><td style="padding:0 0 1px 0;vertical-align:middle;"><strong>Hora Apertura:</strong></td><td style="padding:0 0 1px 0;vertical-align:middle;">{$horaAperturaDisplay}</td></tr>
  <tr><td style="padding:0 0 1px 0;vertical-align:middle;"><strong>Hora de Cierre:</strong></td><td style="padding:0 0 1px 0;vertical-align:middle;">{$horaCierreDisplay}</td></tr>
</table>
HTML;
    }

    private function formatearHora(string $hora): string
    {
        if ($hora === '') {
            return '';
        }
        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?/', $hora, $m)) {
            $h = str_pad((int)$m[1], 2, '0', STR_PAD_LEFT);
            $min = str_pad((int)$m[2], 2, '0', STR_PAD_LEFT);
            $s = isset($m[3]) ? ':' . str_pad((int)$m[3], 2, '0', STR_PAD_LEFT) : ':00';
            return $h . ':' . $min . $s;
        }
        return $hora;
    }

    /**
     * Primera letra en mayúscula y el resto en minúsculas (UTF-8), p. ej. «miércoles» → «Miércoles».
     */
    private function capitalizarPrimeraLetraUtf8(string $texto): string
    {
        $texto = trim($texto);
        if ($texto === '') {
            return '';
        }
        $primera = mb_strtoupper(mb_substr($texto, 0, 1, 'UTF-8'), 'UTF-8');
        $resto = mb_strtolower(mb_substr($texto, 1, null, 'UTF-8'), 'UTF-8');

        return $primera . $resto;
    }

    /**
     * Fecha y hora de impresión en el pie del PDF: «Miércoles 20 de Marzo de 2026 09:18:02».
     * Solo la primera letra del día de la semana y del mes en mayúscula; «de» en minúsculas; día con dos dígitos; hora HH:mm:ss.
     */
    private function formatearFechaHoraImpresionPieEspanol(\DateTimeInterface $dt): string
    {
        $dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
        $meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

        $diaSemana = $this->capitalizarPrimeraLetraUtf8($dias[(int) $dt->format('w')]);
        $mesNombre = $this->capitalizarPrimeraLetraUtf8($meses[(int) $dt->format('n') - 1]);
        $diaNum = $dt->format('d');
        $anio = $dt->format('Y');
        $hora = $dt->format('H:i:s');

        return $diaSemana . ' ' . $diaNum . ' de ' . $mesNombre . ' de ' . $anio . ' ' . $hora;
    }

    /** Tabla de firmas: se muestra en el pie de página de cada hoja */
    private function buildFooterHtml(string $usuarioDisplay, string $fecha, string $fechaHoraImp, string $styleBorder, string $styleColor): string
    {
        $fechaHoraImp = htmlspecialchars($fechaHoraImp, ENT_QUOTES, 'UTF-8');

        // Misma tipografía que la numeración PDF (LibroDiarioPdfService): DejaVu Sans, 7.5pt, normal, line-height 1.1, #000
        $footerType = 'font-family:DejaVu Sans,sans-serif;font-size:7.5pt;font-weight:normal;line-height:1.1;color:#000;';
        $footerHdr = 'color:#000066;font-weight:normal;'; // títulos de columna: mismo peso que el resto del pie

        return <<<HTML

<table width="100%" style="border-collapse:collapse;{$styleBorder};margin:0;{$footerType}">
    <tr>
        <td style="{$styleBorder}padding:1px 3px;{$footerHdr}text-align:center;width:25%;vertical-align:middle;">Responsable</td>
        <td style="{$styleBorder}padding:1px 3px;{$footerHdr}text-align:center;width:25%;vertical-align:middle;">Recibido</td>
        <td style="{$styleBorder}padding:1px 3px;{$footerHdr}text-align:center;width:25%;vertical-align:middle;">Revisado</td>
        <td style="{$styleBorder}padding:1px 3px;{$footerHdr}text-align:center;width:25%;vertical-align:middle;">Aprobado</td>
    </tr>
    <tr>
        <td style="{$styleBorder}padding:1px 3px;{$footerType}vertical-align:top;">Firma: ____________________<br>Nombre: {$usuarioDisplay}<br>Cargo: ____________________<br>Fecha: {$fecha}</td>
        <td style="{$styleBorder}padding:1px 3px;{$footerType}vertical-align:top;">Firma: ____________________</td>
        <td style="{$styleBorder}padding:1px 3px;{$footerType}vertical-align:top;">Firma: ____________________</td>
        <td style="{$styleBorder}padding:1px 3px;{$footerType}vertical-align:top;">Firma: ____________________</td>
    </tr>
</table>
<table width="100%" style="border-collapse:collapse;margin:0;padding:0;border:none;{$footerType}">
    <tr>
        <td style="text-align:left;width:33%;padding:2px 0 0 1cm;border:none;vertical-align:middle;">Fecha y Hora de Impresión:</td>
        <td style="text-align:center;width:34%;padding:2px 0 0 0;border:none;vertical-align:middle;">{$fechaHoraImp}</td>
        <td style="text-align:right;width:33%;padding:2px 1cm 0 0;border:none;vertical-align:middle;"></td>
    </tr>
</table>
HTML;
    }

    /** Totales: solo en la última página (fluyen al final del contenido) */
    private function buildTotalesYFirmasHtml(string $fTraspaso, string $rTraspaso, string $fDeposito, string $rDeposito, string $fEfectivo, string $rEfectivo, string $fCheque, string $rCheque, string $fTarjeta, string $rTarjeta, string $fTransferencia, string $rTransferencia, string $fOtro, string $rOtro, string $tFactura, string $tRecibo, string $totalEfectivo, string $totalGeneral, string $styleBorder, string $styleColor): string
    {
        return <<<HTML
<table style="border-collapse: collapse; {$styleBorder}; width: 55%; margin-left: auto; font-size: 8pt;">
    <tr>
        <td style="{$styleBorder}"></td>
        <td class="center" style="{$styleBorder} {$styleColor}">Factura</td>
        <td class="center" style="{$styleBorder} {$styleColor}">Recibo</td>
    </tr>
    <tr><td style="{$styleBorder}">Traspaso</td><td class="right" style="{$styleBorder}">{$fTraspaso}</td><td class="right" style="{$styleBorder}">{$rTraspaso}</td></tr>
    <tr><td style="{$styleBorder}">Depósito</td><td class="right" style="{$styleBorder}">{$fDeposito}</td><td class="right" style="{$styleBorder}">{$rDeposito}</td></tr>
    <tr><td style="{$styleBorder}">Efectivo</td><td class="right" style="{$styleBorder}">{$fEfectivo}</td><td class="right" style="{$styleBorder}">{$rEfectivo}</td></tr>
    <tr><td style="{$styleBorder}">Cheque</td><td class="right" style="{$styleBorder}">{$fCheque}</td><td class="right" style="{$styleBorder}">{$rCheque}</td></tr>
    <tr><td style="{$styleBorder}">Tarjeta</td><td class="right" style="{$styleBorder}">{$fTarjeta}</td><td class="right" style="{$styleBorder}">{$rTarjeta}</td></tr>
    <tr><td style="{$styleBorder}">Transferencia Bancaria</td><td class="right" style="{$styleBorder}">{$fTransferencia}</td><td class="right" style="{$styleBorder}">{$rTransferencia}</td></tr>
    <tr><td style="{$styleBorder}">Otro</td><td class="right" style="{$styleBorder}">{$fOtro}</td><td class="right" style="{$styleBorder}">{$rOtro}</td></tr>
    <tr><td style="{$styleBorder} {$styleColor}">Total Parcial</td><td class="right" style="{$styleBorder}">{$tFactura}</td><td class="right" style="{$styleBorder}">{$tRecibo}</td></tr>
    <tr style="background:#e8ecf4;"><td style="{$styleBorder} {$styleColor}">Total Efectivo</td><td colspan="2" class="right" style="{$styleBorder}">{$totalEfectivo}</td></tr>
    <tr><td style="{$styleBorder} {$styleColor}">Total General</td><td colspan="2" class="right" style="{$styleBorder} {$styleColor}">{$totalGeneral}</td></tr>
</table>
HTML;
    }

    /**
     * Hora de cierre en el PDF: prioriza libro_diario_cierre (primera fila usuario+fecha) para no cambiar al reimprimir.
     * Si no hay registro con hora, usa resumen/request; si sigue vacío, hora actual.
     */
    private function resolverHoraCierreLibroDiarioPdf(string $usuario, string $fechaCorta, array $resumen, Request $request): string
    {
        $idUsuario = (int) $usuario;
        if ($idUsuario > 0 && $fechaCorta !== '' && Schema::hasTable('libro_diario_cierre')) {
            try {
                $row = DB::table('libro_diario_cierre')
                    ->where('id_usuario', $idUsuario)
                    ->where('fecha', $fechaCorta)
                    ->orderBy('id', 'asc')
                    ->first();
                if ($row && isset($row->hora_cierre) && $row->hora_cierre !== null && (string) $row->hora_cierre !== '') {
                    $hc = $row->hora_cierre;

                    return is_string($hc) ? trim($hc) : trim(substr((string) $hc, 0, 8));
                }
            } catch (\Throwable $e) {
                // Sin BD o error: seguir con request / hora actual
            }
        }

        $desdeRequest = trim((string) ($resumen['hora_cierre'] ?? $request->input('hora_cierre', '')));
        if ($desdeRequest !== '') {
            return $desdeRequest;
        }

        return now()->format('H:i:s');
    }
}

