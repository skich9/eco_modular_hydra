<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use App\Services\DompdfInstitucionLogoHelper;
use App\Services\LibroDiarioPdfService;
use App\Services\LibroDiarioIdentificadorHelper;
use App\Services\Reportes\LibroDiarioAccessService;
use App\Services\Reportes\LibroDiarioAggregatorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ReporteLibroDiarioController extends Controller
{
    /**
     * Filas de datos por bloque/página lógica (antes de la fila "Subtotal Página N") cuando hay `datos[]`.
     *
     * Márgenes de página como SGA `imprime_libro_ingresos` (mPDF: mgl/mgr 10 mm, mgt/mgb 45 mm, letter).
     * Por defecto el body trocea **30** filas de datos + subtotal por bloque. Otro valor: `filas_por_pagina` 5–80 o `auto` (=30).
     */
    private const LIBRO_DIARIO_FILAS_POR_PAGINA_MIN = 5;

    /** Tope: subir mucho con filas manuales puede cortar filas contra el pie en Dompdf. */
    private const LIBRO_DIARIO_FILAS_POR_PAGINA_MAX = 80;

    /** Filas de datos por página lógica cuando no se envía `filas_por_pagina` (cuerpo de la tabla, sin contar el subtotal). */
    private const LIBRO_DIARIO_FILAS_POR_PAGINA_DEFAULT = 30;

    /**
     * Margen @page superior (pt) — equivale a 45 mm como en SGA mPDF mgt, para flujo bajo el header fijo.
     * Base SGA 45 mm ≈ 128 pt; +14 pt para que el bloque fijo (logo + metadatos compactos) no solape el body en Dompdf.
     * El margin-top de .libro-header se recalcula (LIBRO_DIARIO_PAGE_PLUS_HEADER_TOP_SUM_PT − este valor).
     */
    private const LIBRO_DIARIO_PAGE_MARGIN_TOP_PT = 142;

    /**
     * Margen @page inferior (pt) — equivale a 45 mm como SGA mPDF mgb (pie fijo + “Fecha y Hora” + número de página).
     */
    private const LIBRO_DIARIO_PAGE_MARGIN_BOTTOM_PT = 128;

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
     * Si llega el array datos, el tbody se trocea en bloques por hoja: N filas de datos + subtotal (por defecto N=30).
     *
     * Espera:
     * - contenido: string (filas <tr> con 11 columnas), usado si datos está vacío
     * - datos: array opcional de items (ingreso o ingresos); prioridad sobre contenido
     * - usuario, fecha, resumen
     * - body_vertical_offset_pt (opcional, int -20..20, default 0): positivo baja el body (más margen sup.;
     *   p. ej. +6 si aún se solapa en algún entorno). Negativo sube el body. Coherente con .libro-header.
     * - body_vertical_offset_bottom_pt (opcional, int -6..6, default 0): positivo reduce altura útil inferior;
     *   negativo acerca el límite del flujo al pie (más filas visibles; riesgo si se exagera).
     * - filas_por_pagina (opcional): omitir, null, 0, "auto" → 30 filas de datos por bloque. Entero 5–80: otro valor fijo.
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

            $authUserId = auth('sanctum')->id();
            $authUser = $authUserId ? Usuario::query()->find((int) $authUserId) : null;
            if (!$authUser) {
                return response()->json(['success' => false, 'message' => 'No autenticado'], 401);
            }
            $idUsuarioPdf = (int) $usuario;
            if ($idUsuarioPdf > 0 && !LibroDiarioAccessService::puedeConsultarLibroDiarioDe($authUser, $idUsuarioPdf)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No está autorizado para generar el Libro Diario de ese usuario. Solo su propio libro o roles con visión global (rector, tesorería, contabilidad, sistemas).',
                ], 403);
            }

            $usuarioLibroPdf = $idUsuarioPdf > 0
                ? Usuario::query()->with('rol')->find($idUsuarioPdf)
                : null;
            $pdfUsuarioNickname = $usuarioLibroPdf
                ? trim((string) ($usuarioLibroPdf->nickname ?? ''))
                : '';
            $pdfFooterNombre = $this->nombreCompletoFooterDesdeUsuario($usuarioLibroPdf);
            $pdfFooterCargo = '';
            if ($usuarioLibroPdf && $usuarioLibroPdf->rol) {
                $pdfFooterCargo = trim((string) ($usuarioLibroPdf->rol->nombre ?? ''));
            }
            if ($pdfUsuarioNickname === '' && $usuarioDisplay !== '') {
                if (preg_match('/-\s*(.+)$/u', $usuarioDisplay, $m)) {
                    $pdfUsuarioNickname = trim($m[1]);
                } elseif (!ctype_digit(trim($usuarioDisplay))) {
                    $pdfUsuarioNickname = trim($usuarioDisplay);
                }
            }

            // Opción B: si no llegan `datos` del cliente, reconsultar del backend con el agregador único.
            if ((!is_array($datos) || count($datos) === 0) && (int) $usuario > 0) {
                $fechaFiltro = $fecha !== '' ? $fecha : '';
                $fechaInicio = (string) $request->input('fecha_inicio', $fechaFiltro);
                $fechaFin = (string) $request->input('fecha_fin', $fechaFiltro);
                $codigoCarrera = (string) ($resumen['codigo_carrera'] ?? $request->input('codigo_carrera', ''));
                try {
                    /** @var LibroDiarioAggregatorService $agg */
                    $agg = app(LibroDiarioAggregatorService::class);
                    $res = $agg->build([
                        'id_usuario' => (int) $usuario,
                        'fecha_inicio' => $fechaInicio,
                        'fecha_fin' => $fechaFin,
                        'codigo_carrera' => $codigoCarrera,
                        'usuario_display' => $usuarioDisplay,
                    ]);
                    $datos = $res['datos'] ?? [];
                    if (!is_array($resumen) || empty($resumen)) {
                        $resumen = $res['resumen'] ?? [];
                        if ($codigoCarrera !== '' && !isset($resumen['codigo_carrera'])) {
                            $resumen['codigo_carrera'] = $codigoCarrera;
                        }
                    }
                    if ($horaApertura === '' && !empty($res['usuario_info']['hora_apertura'])) {
                        $horaApertura = (string) $res['usuario_info']['hora_apertura'];
                    }
                    if ($contenido === '') {
                        // Marcador para pasar la validación aguas abajo; el cuerpo real se genera desde `datos`.
                        $contenido = '<!-- generado por LibroDiarioAggregatorService -->';
                    }
                } catch (\Throwable $e) {
                    Log::warning('[ReporteLibroDiarioController] fallback a agregador falló', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $resumen = is_array($resumen) ? $resumen : [];
            $fmt = function ($v) {
                return number_format((float)($v ?? 0), 2, '.', '');
            };
            $get = function ($key, $sub) use ($resumen) {
                return ($resumen[$key] ?? [])[$sub] ?? 0;
            };
            $fTraspaso = $fmt($get('traspaso', 'factura'));
            $rTraspaso = $fmt($get('traspaso', 'recibo'));
            $mfTraspaso = $fmt($get('traspaso', 'mora_factura'));
            $mrTraspaso = $fmt($get('traspaso', 'mora_recibo'));
            $fDeposito = $fmt($get('deposito', 'factura'));
            $rDeposito = $fmt($get('deposito', 'recibo'));
            $mfDeposito = $fmt($get('deposito', 'mora_factura'));
            $mrDeposito = $fmt($get('deposito', 'mora_recibo'));
            $fEfectivo = $fmt($get('efectivo', 'factura'));
            $rEfectivo = $fmt($get('efectivo', 'recibo'));
            $mfEfectivo = $fmt($get('efectivo', 'mora_factura'));
            $mrEfectivo = $fmt($get('efectivo', 'mora_recibo'));
            $fCheque = $fmt($get('cheque', 'factura'));
            $rCheque = $fmt($get('cheque', 'recibo'));
            $mfCheque = $fmt($get('cheque', 'mora_factura'));
            $mrCheque = $fmt($get('cheque', 'mora_recibo'));
            $fTarjeta = $fmt($get('tarjeta', 'factura'));
            $rTarjeta = $fmt($get('tarjeta', 'recibo'));
            $mfTarjeta = $fmt($get('tarjeta', 'mora_factura'));
            $mrTarjeta = $fmt($get('tarjeta', 'mora_recibo'));
            $fTransferencia = $fmt($get('transferencia', 'factura'));
            $rTransferencia = $fmt($get('transferencia', 'recibo'));
            $mfTransferencia = $fmt($get('transferencia', 'mora_factura'));
            $mrTransferencia = $fmt($get('transferencia', 'mora_recibo'));
            $fOtro = $fmt($get('otro', 'factura'));
            $rOtro = $fmt($get('otro', 'recibo'));
            $mfOtro = $fmt($get('otro', 'mora_factura'));
            $mrOtro = $fmt($get('otro', 'mora_recibo'));
            $tFactura = $fmt($resumen['total_factura'] ?? 0);
            $tRecibo = $fmt($resumen['total_recibo'] ?? 0);
            $tMoraFactura = $fmt($resumen['total_mora_factura'] ?? 0);
            $tMoraRecibo = $fmt($resumen['total_mora_recibo'] ?? 0);
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
            $styleBorderTabla = 'border: 1px solid #c00;';
            $styleColor = 'color: #000066; font-weight: bold;';

            $logoPad = 2;
            $logo = DompdfInstitucionLogoHelper::logoParaEncabezadoDompdf($logoPad);
            $logoImg = $logo['html'];
            $logoW = $logo['width'];
            $logoH = $logo['height'];
            // border-right:none evita doble trazo vertical logo|texto con Dompdf (colapso imperfecto entre celdas)
            $logoCellStyle = 'width:' . ($logoW + $logoPad * 2) . 'px; min-width:' . ($logoW + $logoPad * 2) . 'px; max-width:' . ($logoW + $logoPad * 2) . 'px; padding:' . $logoPad . 'px; ' . $styleBorder . ' border-right:none; vertical-align:middle; text-align:center; line-height:0;';

            $offsetTop = (int) $request->input('body_vertical_offset_pt', 0);
            $offsetTop = max(-20, min(20, $offsetTop));
            $offsetBottom = (int) $request->input('body_vertical_offset_bottom_pt', 0);
            $offsetBottom = max(-12, min(12, $offsetBottom));
            $pageMarginTop = self::LIBRO_DIARIO_PAGE_MARGIN_TOP_PT + $offsetTop;
            $pageMarginBottom = self::LIBRO_DIARIO_PAGE_MARGIN_BOTTOM_PT + $offsetBottom;
            $headerMarginTop = self::LIBRO_DIARIO_PAGE_PLUS_HEADER_TOP_SUM_PT - $pageMarginTop;
            $footerMarginBottom = self::LIBRO_DIARIO_PAGE_PLUS_FOOTER_BOTTOM_SUM_PT - $pageMarginBottom;

            $contenidoBody = $contenido;
            if (is_array($datos) && count($datos) > 0) {
                $filasPorPagina = $this->resolverFilasPorPagina($request);
                $contenidoBody = $this->construirFilasLibroDiarioConSubtotalesPorPagina(
                    $datos,
                    $fmt,
                    $styleBorderTabla,
                    $styleColor,
                    $filasPorPagina
                );
            }

            $headerHtml = $this->buildHeaderHtml($logoImg, $logoCellStyle, $carreraVal, $fechaLiteral, $pdfUsuarioNickname, $horaApertura, $horaCierre, $numeracion, $styleBorder, $styleColor);
            $totalesYFirmasHtml = $this->buildTotalesYFirmasHtml(
                $fTraspaso, $rTraspaso, $mfTraspaso, $mrTraspaso,
                $fDeposito, $rDeposito, $mfDeposito, $mrDeposito,
                $fEfectivo, $rEfectivo, $mfEfectivo, $mrEfectivo,
                $fCheque, $rCheque, $mfCheque, $mrCheque,
                $fTarjeta, $rTarjeta, $mfTarjeta, $mrTarjeta,
                $fTransferencia, $rTransferencia, $mfTransferencia, $mrTransferencia,
                $fOtro, $rOtro, $mfOtro, $mrOtro,
                $tFactura, $tRecibo, $tMoraFactura, $tMoraRecibo,
                $totalEfectivo, $totalGeneral
            );
            $footerHtml = $this->buildFooterHtml($pdfFooterNombre, $pdfFooterCargo, $fecha, $fechaHoraImp);

            $html = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8" />
    <title>Libro Diario</title>
    <style>
        /* Márgenes @page: SGA mPDF libro ingresos = 10 mm laterales, 45 mm arriba/abajo (pt vía parámetros pageMargin*). */
        @page {
            size: letter;
            margin: {$pageMarginTop}pt 10mm {$pageMarginBottom}pt 10mm;
        }
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #000; line-height: 1.12; margin: 0; padding: 0; }
        /* Cuerpo: mismas proporciones de columnas que SGA get_libro (anchos th en px, suma 700 → % del ancho útil) */
        .tabla {
            width: 100%;
            table-layout: fixed;
            border-collapse: collapse;
            {$styleBorderTabla}
            margin: 0;
        }
        /* Tipos de letra cuerpo = SGA get_libro / libro_row: tabla 6pt, textos largos 7pt, Hora 5pt, Ingresos 7pt */
        .tabla { font-size: 6pt; }
        .tabla th, .tabla td {
            border: 1px solid #c00;
            padding: 2px 3px;
            margin: 0;
            line-height: 1.1;
            vertical-align: middle;
        }
        .tabla th {
            background: #dee6f0;
            color: #000066;
            text-align: center;
            font-weight: bold;
            font-size: 6pt;
            padding: 3px 4px;
            border-bottom: 2px solid #a00;
        }
        .tabla thead { display: table-header-group; }
        .tabla td:nth-child(1), .tabla td:nth-child(2), .tabla td:nth-child(3), .tabla td:nth-child(4),
        .tabla td:nth-child(9) { font-size: 6pt; }
        .tabla td:nth-child(5), .tabla td:nth-child(6), .tabla td:nth-child(7), .tabla td:nth-child(8),
        .tabla td:nth-child(11) { font-size: 7pt; }
        .tabla td:nth-child(10) { font-size: 5pt; }
        .tabla th:nth-child(1), .tabla td:nth-child(1) { width: 2.1429%; }   /* P 15 */
        .tabla th:nth-child(2), .tabla td:nth-child(2) { width: 2.1429%; }   /* Nº 15 */
        .tabla th:nth-child(3), .tabla td:nth-child(3) { width: 4.2857%; }   /* Recibo 30 */
        .tabla th:nth-child(4), .tabla td:nth-child(4) { width: 5.2857%; }   /* Factura 30 */
        .tabla th:nth-child(5), .tabla td:nth-child(5) { width: 13.7143%; }  /* Razon 145 */
        .tabla th:nth-child(6), .tabla td:nth-child(6) { width: 10.4286%; }  /* NIT 80 */
        .tabla th:nth-child(7), .tabla td:nth-child(7) { width: 17.8571%; }  /* Concepto 160 */
        .tabla th:nth-child(8), .tabla td:nth-child(8) { width: 21.7143%; }  /* Obs 110 */
        .tabla th:nth-child(9), .tabla td:nth-child(9) { width: 8.5714%; }   /* CETA 60 */
        .tabla th:nth-child(10), .tabla td:nth-child(10) { width: 5.5714%; } /* Hora 25 */
        .tabla th:nth-child(11), .tabla td:nth-child(11) { width: 7.2857%; } /* Ingresos 30 */
        .tabla th:nth-child(1), .tabla td:nth-child(1),
        .tabla th:nth-child(2), .tabla td:nth-child(2),
        .tabla th:nth-child(3), .tabla td:nth-child(3),
        .tabla th:nth-child(4), .tabla td:nth-child(4),
        .tabla th:nth-child(6), .tabla td:nth-child(6),
        .tabla th:nth-child(9), .tabla td:nth-child(9),
        .tabla th:nth-child(10), .tabla td:nth-child(10),
        .tabla th:nth-child(11), .tabla td:nth-child(11) {
            white-space: nowrap;
        }
        .tabla th:nth-child(5), .tabla td:nth-child(5),
        .tabla th:nth-child(7), .tabla td:nth-child(7),
        .tabla th:nth-child(8), .tabla td:nth-child(8) {
            white-space: normal;
            word-wrap: break-word;
            overflow-wrap: break-word;
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
            font-size: 8pt;
            font-weight: bold;
            line-height: 1.08;
            margin: 0;
            padding: 0 0 1px 0;
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
        .tabla-totales {
            width: 70%;
            margin-left: auto;
            border-collapse: collapse;
            font-size: 8px;
        }
        .tabla-totales th,
        .tabla-totales td {
            border: 1px solid #c00;
            padding: 2px 4px;
            line-height: 1.12;
            vertical-align: middle;
        }
        .tabla-totales thead th {
            background: #dee6f0;
            color: #000066;
            font-weight: bold;
            text-align: center;
            border-bottom: 2px solid #a00;
        }
        .tabla-totales tbody td.texto-modo {
            color: #000066;
            font-weight: bold;
        }
        .tabla-totales tbody tr.fila-resaltada {
            background: #e8ecf4;
        }
        /* Pie 9pt (SGA); numeración vía LibroDiarioPdfService (~8pt) en esquina inferior derecha */
        .libro-footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            z-index: 1;
            background: #fff;
            font-family: DejaVu Sans, sans-serif;
            font-size: 9pt;
            font-weight: normal;
            line-height: 1.12;
            color: #000;
            margin: 0;
            padding: 0 0 1px 0;
            margin-bottom: {$footerMarginBottom}pt;
        }
        .libro-footer table {
            font-family: DejaVu Sans, sans-serif;
            font-size: 9pt;
            font-weight: normal;
            line-height: 1.12;
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

        <div class="totales-ultima-pagina" style="margin-top: 4pt; page-break-inside: avoid;">
{$totalesYFirmasHtml}
        </div>
    </main>
    <!-- Numeración: LibroDiarioPdfService (end_document) — mismo estilo/banda que "Fecha y Hora de Impresión" (9pt bold #000066, margen 1cm). -->
</body>
</html>
HTML;

            $sufPdfCarrera = trim((string) ($resumen['codigo_carrera'] ?? $request->input('codigo_carrera', '')));
            $svc = new LibroDiarioPdfService();
            $path = $svc->generate($html, $usuario, $fechaCorta, $fechaHoraImp, $sufPdfCarrera !== '' ? $sufPdfCarrera : null);

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
     * Código RD-[CARRERA]-[MM]-[NNN]: NNN correlativo por carrera y mes (001… cada mes; ceros a la izquierda).
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
                    $codCarrFiltro = strtoupper(trim((string) ($resumen['codigo_carrera'] ?? $request->input('codigo_carrera', ''))));
                    $q = DB::table('libro_diario_cierre')
                        ->where('id_usuario', $idUsuario)
                        ->where('fecha', $fechaCorta);
                    if ($codCarrFiltro !== '') {
                        $q->whereRaw('UPPER(TRIM(COALESCE(codigo_carrera, ""))) = ?', [$codCarrFiltro]);
                    } else {
                        $q->where(function ($w) {
                            $w->whereNull('codigo_carrera')
                                ->orWhereRaw("TRIM(COALESCE(codigo_carrera, '')) = ''");
                        });
                    }
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
            $corrNum = max(1, LibroDiarioIdentificadorHelper::maxCorrelativoRegistradoParaMes($codigoCarrera, $mes) + 1);
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
     * Por defecto 30 filas de datos + subtotal. Manual: 5–80. `filas_por_pagina` null, '', 0, "auto" → 30.
     */
    private function resolverFilasPorPagina(Request $request): int
    {
        $raw = $request->input('filas_por_pagina');

        if ($raw === null || $raw === '') {
            return self::LIBRO_DIARIO_FILAS_POR_PAGINA_DEFAULT;
        }
        if (is_string($raw) && strtolower(trim($raw)) === 'auto') {
            return self::LIBRO_DIARIO_FILAS_POR_PAGINA_DEFAULT;
        }
        $n = is_numeric($raw) ? (int) $raw : 0;
        if ($n < 1) {
            return self::LIBRO_DIARIO_FILAS_POR_PAGINA_DEFAULT;
        }

        return max(
            self::LIBRO_DIARIO_FILAS_POR_PAGINA_MIN,
            min(self::LIBRO_DIARIO_FILAS_POR_PAGINA_MAX, $n)
        );
    }

    /**
     * Genera filas de datos + una fila de subtotal por cada "página lógica" del PDF.
     * Suma solo ingreso/ingresos del bloque actual. Salto de hoja: `page-break-after` en el subtotal (Dompdf
     * maneja mejor el corte al final de bloque que `page-break-before` en la primera fila del siguiente tramo).
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
        $totalChunks = count($chunks);

        foreach ($chunks as $idx => $chunk) {
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

                $estilo = $styleBorder . ' padding:2px 3px;margin:0;vertical-align:middle;line-height:1.1;';
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
            $forzarSaltoHoja = ($idx < $totalChunks - 1);
            $estiloSubtotal = 'background:#e8ecf4; page-break-inside: avoid;'
                . ($forzarSaltoHoja ? ' page-break-after: always;' : '');
            $html .= '<tr class="subtotal-pagina" style="' . $estiloSubtotal . '">'
                . '<td colspan="10" style="' . $styleBorder . ' padding:3px 4px; margin:0; font-size:6pt; line-height:1.1; vertical-align:middle; ' . $styleColor . ' text-align:right;">Subtotal Página ' . $numPag . ':</td>'
                . '<td style="' . $styleBorder . ' padding:3px 4px; margin:0; font-size:7pt; line-height:1.1; vertical-align:middle; text-align:right; font-weight:bold;">' . $fmt($subtotalPag) . '</td>'
                . '</tr>';
        }

        return $html;
    }

    private function buildHeaderHtml(string $logoImg, string $logoCellStyle, string $carreraVal, string $fechaLiteral, string $usuarioNickname, string $horaApertura, string $horaCierre, string $numeracion, string $styleBorder, string $styleColor): string
    {
        $carreraVal = htmlspecialchars($carreraVal, ENT_QUOTES, 'UTF-8');
        $fechaLiteral = htmlspecialchars($fechaLiteral, ENT_QUOTES, 'UTF-8');
        $usuarioNickname = htmlspecialchars($usuarioNickname !== '' ? $usuarioNickname : '______________________', ENT_QUOTES, 'UTF-8');
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
    <td rowspan="2" style="{$styleBorder}padding:1px 4px;{$styleColor}text-align:center;font-size:10pt;font-weight:bold;line-height:1.06;vertical-align:middle;">
      REPORTE DIARIO DE INGRESOS</td>
    <td style="{$styleBorder}padding:1px 3px;{$styleColor}text-align:center;font-size:7.5pt;font-weight:bold;line-height:1.1;vertical-align:middle;">CODIGO:</td>
    <td style="{$styleBorder}padding:1px 3px;{$styleColor}text-align:center;font-size:7.5pt;line-height:1.1;vertical-align:middle;">ING-2</td>
  </tr>
  <tr>
    <td style="{$styleBorder}padding:1px 3px;{$styleColor}text-align:center;font-size:7.5pt;font-weight:bold;line-height:1.1;vertical-align:middle;">Versión:</td>
    <td style="{$styleBorder}padding:1px 3px;{$styleColor}text-align:center;font-size:7.5pt;line-height:1.1;vertical-align:middle;">V.0.</td>
  </tr>
</table>
<table width="100%" class="libro-header-meta" style="border-collapse:collapse;margin-top:4px;padding:0;font-size:7.5pt;font-weight:bold;color:#000066;line-height:1.06;">
  <tr><td style="padding:0;width:118px;vertical-align:top;">Carrera:</td><td style="padding:0 0 1px 0;vertical-align:top;">{$carreraVal}</td></tr>
  <tr><td style="padding:0;vertical-align:top;">Fecha:</td><td style="padding:0 0 1px 0;vertical-align:top;">{$fechaLiteral}</td></tr>
  <tr><td style="padding:0;vertical-align:top;">Usuario:</td><td style="padding:0 0 1px 0;vertical-align:top;">{$usuarioNickname}</td></tr>
  <tr><td style="padding:0;vertical-align:top;">Hora de Apertura:</td><td style="padding:0 0 1px 0;vertical-align:top;">{$horaAperturaDisplay}</td></tr>
  <tr><td style="padding:0;vertical-align:top;">Hora de Cierre:</td><td style="padding:0;vertical-align:top;">{$horaCierreDisplay}</td></tr>
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

    /**
     * Nombre listo para el pie: nombre + apellidos en una sola cadena (espacios normalizados).
     */
    private function nombreCompletoFooterDesdeUsuario(?Usuario $u): string
    {
        if (! $u) {
            return '';
        }
        $parts = array_filter(
            array_map('trim', [
                (string) ($u->nombre ?? ''),
                (string) ($u->ap_paterno ?? ''),
                (string) ($u->ap_materno ?? ''),
            ]),
            static fn (string $p): bool => $p !== ''
        );

        return $parts === [] ? '' : implode(' ', $parts);
    }

    /** Tabla de firmas: se muestra en el pie de página de cada hoja */
    private function buildFooterHtml(string $nombreResponsable, string $cargoResponsable, string $fecha, string $fechaHoraImp): string
    {
        $fechaHoraImp = htmlspecialchars($fechaHoraImp, ENT_QUOTES, 'UTF-8');
        $fechaEsc = htmlspecialchars($fecha, ENT_QUOTES, 'UTF-8');
        $nombreHtml = $nombreResponsable !== ''
            ? htmlspecialchars($nombreResponsable, ENT_QUOTES, 'UTF-8')
            : '';
        $cargoHtml = $cargoResponsable !== ''
            ? htmlspecialchars($cargoResponsable, ENT_QUOTES, 'UTF-8')
            : '';

        // Alineado a SGA imprime_libro_ingresos: cuadrícula roja, cabeceras en celdas; fila final azul y negrita
        $red = 'border:1px solid #c00;';
        $hdr = 'border:1px solid #c00;padding:2px 3px;text-align:center;font-weight:bold;vertical-align:middle;';
        $cell = 'border:1px solid #c00;padding:2px 3px;vertical-align:middle;';
        $cellUp = 'border:1px solid #c00;border-width:1px 1px 0 1px;padding:2px 3px;vertical-align:middle;';
        $cellBottom = 'border:1px solid #c00;border-width:0 1px 1px 1px;padding:2px 3px;vertical-align:middle;';
        $footerRow = 'color:#000066;font-weight:bold;font-size:9pt;';
        // Columna 2 (RESPONSABLE) más ancha; 3–5 más estrechas para dar espacio a nombre completo
        $wLab = 'width:8%;';
        $wResp = 'width:28%;';
        $wOt = 'width:20%;';
        $cellNombre = $cell . 'text-align:center;white-space:normal;word-wrap:break-word;word-break:normal;line-height:1.15;vertical-align:top;';

        return <<<HTML

<table width="100%" style="border-collapse:collapse;table-layout:fixed;margin:0;font-family:DejaVu Sans,sans-serif;font-size:9pt;">
    <tr>
        <td style="{$wLab}"></td>
        <td style="{$wResp}{$hdr}">RESPONSABLE</td>
        <td style="{$wOt}{$hdr}">RECIBIDO</td>
        <td style="{$wOt}{$hdr}">REVISADO</td>
        <td style="{$wOt}{$hdr}">APROBADO</td>
    </tr>
    <tr>
        <td style="{$wLab}height:36px;{$cell}text-align:center;">Firma:</td>
        <td style="{$wResp}{$cell}"></td>
        <td style="{$wOt}{$cell}"></td>
        <td style="{$wOt}{$cell}"></td>
        <td style="{$wOt}{$cell}"></td>
    </tr>
    <tr>
        <td style="{$wLab}min-height:24px;{$cell}text-align:center;vertical-align:top;">Nombre:</td>
        <td style="{$wResp}{$cellNombre}">{$nombreHtml}</td>
        <td style="{$wOt}{$cellUp}"></td>
        <td style="{$wOt}{$cellUp}"></td>
        <td style="{$wOt}{$cellUp}"></td>
    </tr>
    <tr>
        <td style="{$wLab}min-height:24px;{$cell}text-align:center;">Cargo:</td>
        <td style="{$wResp}{$cell}text-align:center;white-space:normal;word-wrap:break-word;">{$cargoHtml}</td>
        <td style="{$wOt}{$cellBottom}"></td>
        <td style="{$wOt}{$cellBottom}"></td>
        <td style="{$wOt}{$cellBottom}"></td>
    </tr>
    <tr>
        <td style="{$wLab}{$cell}text-align:center;">Fecha:</td>
        <td style="{$wResp}{$cell}text-align:center;">{$fechaEsc}</td>
        <td style="{$wOt}{$cell}"></td>
        <td style="{$wOt}{$cell}"></td>
        <td style="{$wOt}{$cell}"></td>
    </tr>
</table>
<table width="100%" style="border-collapse:collapse;margin:0;padding:0;border:none;font-family:DejaVu Sans,sans-serif;">
    <tr>
        <td style="text-align:left;width:25%;padding:3px 0 0 0;border:none;vertical-align:middle;{$footerRow}">Fecha y Hora de Impresión</td>
        <td style="text-align:center;width:50%;padding:3px 0 0 0;border:none;vertical-align:middle;{$footerRow}">{$fechaHoraImp}</td>
        <td style="text-align:right;width:25%;padding:3px 1cm 0 0;border:none;vertical-align:middle;{$footerRow}"></td>
    </tr>
</table>
HTML;
    }

    /** Totales: solo en la última página. Mismo lenguaje visual que .tabla (bordes #c00, cabecera #dee6f0). */
    private function buildTotalesYFirmasHtml(
        string $fTraspaso, string $rTraspaso, string $mfTraspaso, string $mrTraspaso,
        string $fDeposito, string $rDeposito, string $mfDeposito, string $mrDeposito,
        string $fEfectivo, string $rEfectivo, string $mfEfectivo, string $mrEfectivo,
        string $fCheque, string $rCheque, string $mfCheque, string $mrCheque,
        string $fTarjeta, string $rTarjeta, string $mfTarjeta, string $mrTarjeta,
        string $fTransferencia, string $rTransferencia, string $mfTransferencia, string $mrTransferencia,
        string $fOtro, string $rOtro, string $mfOtro, string $mrOtro,
        string $tFactura, string $tRecibo, string $tMoraFactura, string $tMoraRecibo,
        string $totalEfectivo, string $totalGeneral
    ): string {
        return <<<HTML
<table class="tabla-totales">
    <thead>
    <tr>
        <th></th>
        <th>Factura</th>
        <th>Recibo</th>
        <th>Mora Fac</th>
        <th>Mora Rec</th>
    </tr>
    </thead>
    <tbody>
    <tr><td>Traspaso</td><td class="right">{$fTraspaso}</td><td class="right">{$rTraspaso}</td><td class="right">{$mfTraspaso}</td><td class="right">{$mrTraspaso}</td></tr>
    <tr><td>Depósito</td><td class="right">{$fDeposito}</td><td class="right">{$rDeposito}</td><td class="right">{$mfDeposito}</td><td class="right">{$mrDeposito}</td></tr>
    <tr><td>Efectivo</td><td class="right">{$fEfectivo}</td><td class="right">{$rEfectivo}</td><td class="right">{$mfEfectivo}</td><td class="right">{$mrEfectivo}</td></tr>
    <tr><td>Cheque</td><td class="right">{$fCheque}</td><td class="right">{$rCheque}</td><td class="right">{$mfCheque}</td><td class="right">{$mrCheque}</td></tr>
    <tr><td>Tarjeta</td><td class="right">{$fTarjeta}</td><td class="right">{$rTarjeta}</td><td class="right">{$mfTarjeta}</td><td class="right">{$mrTarjeta}</td></tr>
    <tr><td>Transferencia Bancaria</td><td class="right">{$fTransferencia}</td><td class="right">{$rTransferencia}</td><td class="right">{$mfTransferencia}</td><td class="right">{$mrTransferencia}</td></tr>
    <tr><td>Otro</td><td class="right">{$fOtro}</td><td class="right">{$rOtro}</td><td class="right">{$mfOtro}</td><td class="right">{$mrOtro}</td></tr>
    <tr class="fila-resaltada"><td class="texto-modo">Total Parcial</td><td class="right">{$tFactura}</td><td class="right">{$tRecibo}</td><td class="right">{$tMoraFactura}</td><td class="right">{$tMoraRecibo}</td></tr>
    <tr class="fila-resaltada"><td class="texto-modo">Total Efectivo</td><td colspan="4" class="right">{$totalEfectivo}</td></tr>
    <tr class="fila-resaltada"><td class="texto-modo">Total General</td><td colspan="4" class="right texto-modo">{$totalGeneral}</td></tr>
    </tbody>
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
                $codCarr = strtoupper(trim((string) ($resumen['codigo_carrera'] ?? $request->input('codigo_carrera', ''))));
                $qH = DB::table('libro_diario_cierre')
                    ->where('id_usuario', $idUsuario)
                    ->where('fecha', $fechaCorta);
                if ($codCarr !== '') {
                    $qH->whereRaw('UPPER(TRIM(COALESCE(codigo_carrera, ""))) = ?', [$codCarr]);
                } else {
                    $qH->where(function ($w) {
                        $w->whereNull('codigo_carrera')
                            ->orWhereRaw("TRIM(COALESCE(codigo_carrera, '')) = ''");
                    });
                }
                $row = $qH->orderBy('id', 'asc')->first();
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

