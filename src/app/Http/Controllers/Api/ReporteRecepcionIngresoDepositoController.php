<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RecepcionIngreso;
use App\Models\Usuario;
use App\Services\DompdfInstitucionLogoHelper;
use App\Services\Economico\BolivianosALetras;
use App\Services\Economico\RecepcionIngresoDepositoPdfService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * PDF «Reporte de ingresos diarios para depósitos» (Cod. form. ING-4), alineado al patrón del Libro Diario.
 * `imprimirEstructura`: plantilla con placeholders; en fases posteriores se conectan datos de negocio.
 */
class ReporteRecepcionIngresoDepositoController extends Controller
{
    /** @return array<string, string> Placeholders documentados para reemplazo futuro */
    public const PLACEHOLDER_KEYS = [
        'numero_formulario' => 'Nº formulario institucional (ej. EEA-04-016)',
        'total_facturas_monto' => 'Importe numérico total facturas',
        'total_facturas_literal' => 'Importe en letras (facturas)',
        'total_recibos_monto' => 'Importe numérico total recibos',
        'total_recibos_literal' => 'Importe en letras (recibos)',
        'entrega_firma_cajero_nombre' => 'Nombre quien entrega (cajero)',
        'recibe_firma_tesoreria_nombre' => 'Nombre quien recibe (tesorería)',
        'fecha_recepcion' => 'Fecha recepción (YYYY-MM-DD o formato a mostrar)',
        'fecha_reportes_desde' => 'Inicio rango reportes diarios (texto largo)',
        'fecha_reportes_hasta' => 'Fin rango reportes diarios (texto largo)',
        'actividad' => 'Actividad económica / carrera',
        'filas_detalle_html' => 'Filas <tr>…</tr> de la tabla principal',
        'subtotal_fecha' => 'Fecha del subtotal',
        'subtotal_recibos' => 'Subtotal columna recibos',
        'subtotal_facturas' => 'Subtotal columna facturas',
        'subtotal_general' => 'Subtotal columna total general',
        'total_recibos' => 'Total general recibos',
        'total_facturas' => 'Total general facturas',
        'total_general' => 'Total general',
    ];

    /** Márgenes @page verticales mínimos (Dompdf): cabecera arriba, pie abajo. */
    private const PAGE_MARGIN_TOP_PT = 18;

    private const PAGE_MARGIN_BOTTOM_PT = 12;

    /** Alto mínimo del bloque = área útil A4 (~842pt) menos márgenes arriba/abajo. */
    private const WRAP_MIN_HEIGHT_PT = 800;

    /** Reserva bajo la tabla para no solapar concepto + firmas (pie position:absolute bottom:0). */
    private const MAIN_PADDING_BOTTOM_PARA_PIE_PT = 248;

    public function __construct(
        private readonly RecepcionIngresoDepositoPdfService $pdfService
    ) {
    }

    /**
     * Genera un PDF con la estructura del formulario ING-4 (sin datos de negocio aún).
     * POST body opcional (reservado): reservado para cuando se conecten datos.
     */
    public function imprimirEstructura(Request $request)
    {
        try {
            $authUserId = auth('sanctum')->id();
            if (!$authUserId || !Usuario::query()->find((int) $authUserId)) {
                return response()->json(['success' => false, 'message' => 'No autenticado', 'url' => ''], 401);
            }

            $styleBorder = 'border: 1px solid #000066;';
            $styleColor = 'color: #000066; font-weight: bold;';

            $numeroFormulario = '____________';
            $totalFacturasMonto = '____________';
            $totalFacturasLiteral = '________________________________________________';
            $totalRecibosMonto = '____________';
            $totalRecibosLiteral = '________________________________________________';
            $entregaNombre = '______________________________';
            $recibeNombre = '______________________________';
            $fechaRecepcion = '____-__-__';
            $actividad = '________________________________';
            $subtotalFecha = '____-__-__';
            $subtotalRecibos = '____________';
            $subtotalFacturas = '____________';
            $subtotalGeneral = '____________';
            $totalRecibos = '____________';
            $totalFacturas = '____________';
            $totalGeneral = '____________';

            $filasDetalleHtml = <<<'HTML'
                <tr>
                    <td style="text-align:center;">&nbsp;</td>
                    <td>&nbsp;</td>
                    <td style="text-align:center;">&nbsp;</td>
                    <td style="text-align:right;">&nbsp;</td>
                    <td style="text-align:right;">&nbsp;</td>
                    <td style="text-align:right;">&nbsp;</td>
                </tr>
HTML;

            $logoPad = 2;
            $logo = DompdfInstitucionLogoHelper::logoParaEncabezadoDompdf($logoPad);
            $logoImg = $logo['html'];
            $logoW = $logo['width'];
            $logoH = $logo['height'];
            $logoCellStyle = 'width:' . ($logoW + $logoPad * 2) . 'px; min-width:' . ($logoW + $logoPad * 2) . 'px; max-width:' . ($logoW + $logoPad * 2) . 'px; padding:' . $logoPad . 'px; ' . $styleBorder . ' border-right:none; vertical-align:middle; text-align:center; line-height:0;';

            $headerHtml = $this->buildEncabezadoIng4($logoImg, $logoCellStyle, $numeroFormulario, $styleBorder, $styleColor);
            $bloqueConcepto = $this->buildBloqueConcepto(
                $totalFacturasMonto,
                $totalFacturasLiteral,
                $totalRecibosMonto,
                $totalRecibosLiteral,
                $styleBorder
            );
            $bloqueFirmas = $this->buildBloqueFirmas($entregaNombre, $recibeNombre, $styleBorder);
            $bloqueFechasActividad = $this->buildBloqueFechasActividad(
                $fechaRecepcion,
                $actividad,
                $styleBorder
            );
            $tablaHtml = $this->buildTablaDetalle(
                $filasDetalleHtml,
                $subtotalFecha,
                $subtotalRecibos,
                $subtotalFacturas,
                $subtotalGeneral,
                $totalRecibos,
                $totalFacturas,
                $totalGeneral,
                $styleBorder,
                $styleColor
            );

            $fechaCorta = (new \DateTime('now', new \DateTimeZone('America/La_Paz')))->format('Y-m-d');
            $pageMt = self::PAGE_MARGIN_TOP_PT;
            $pageMb = self::PAGE_MARGIN_BOTTOM_PT;
            $wrapMin = self::WRAP_MIN_HEIGHT_PT;
            $mainPadBottom = self::MAIN_PADDING_BOTTOM_PARA_PIE_PT;

            $html = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8" />
    <title>Recepción ingresos depósitos (ING-4)</title>
    <style>
        @page { size: A4; margin: {$pageMt}pt 0.8cm {$pageMb}pt 0.8cm; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 8.5pt;
            color: #000;
            line-height: 1.15;
            margin: 0;
            padding: 0;
        }
        .recepcion-main {
            margin: 0;
            padding: 0;
        }
        .recepcion-wrap__foot table { border-collapse: collapse; }
    </style>
</head>
<body>
<div class="recepcion-wrap" style="position:relative;width:100%;min-height:{$wrapMin}pt;">
    <div class="recepcion-wrap__main" style="padding-bottom:{$mainPadBottom}pt;">
{$headerHtml}
        <main class="recepcion-main">
{$bloqueFechasActividad}
{$tablaHtml}
        </main>
    </div>
    <div class="recepcion-wrap__foot" style="position:absolute;bottom:0;left:0;right:0;background:#fff;">
{$bloqueConcepto}
{$bloqueFirmas}
    </div>
</div>
</body>
</html>
HTML;

            $slug = 'estructura';
            $path = $this->pdfService->generate($html, $slug, $fechaCorta);

            if (!is_file($path) || !is_readable($path)) {
                return response()->json([
                    'success' => false,
                    'url' => '',
                    'message' => 'No se pudo generar el PDF (recepción ingresos depósito)',
                ], 500);
            }

            $publicRoot = public_path();
            $relPath = ltrim(str_replace(DIRECTORY_SEPARATOR, '/', str_replace($publicRoot, '', $path)), '/');
            $url = url($relPath);

            Log::info('[ReporteRecepcionIngresoDepositoController] PDF estructura generado', ['path' => $path]);

            return response()->json([
                'success' => true,
                'url' => $url,
                'message' => 'PDF de estructura ING-4 generado (sin datos de negocio)',
                'placeholders' => array_keys(self::PLACEHOLDER_KEYS),
            ]);
        } catch (\Throwable $e) {
            Log::error('[ReporteRecepcionIngresoDepositoController] exception', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'url' => '',
                'message' => 'Error al generar PDF de recepción ingresos depósito',
            ], 500);
        }
    }

    /**
     * Vista previa ING-4 con datos reales, sin persistir (equivalente a SGA con opcion=vista_previa).
     * POST: fecha_recepcion, fecha_inicial_libros, fecha_final_libros, detalles[], observacion?,
     * usuario_entregue1, usuario_recibi1, usuario_entregue2?, usuario_recibi2?, id_actividad_economica?.
     */
    public function vistaPrevia(Request $request)
    {
        try {
            $authUserId = auth('sanctum')->id();
            if (!$authUserId || ! Usuario::query()->find((int) $authUserId)) {
                return response()->json(['success' => false, 'message' => 'No autenticado', 'url' => ''], 401);
            }

            // Incluir todos los campos de cada detalle en las reglas: si no, `validate()` los omite
            // y desaparecen de `$data['detalles']` (p. ej. fecha_inicial_libros).
            $data = $request->validate([
                'fecha_recepcion'         => 'required|date',
                'fecha_inicial_libros'   => 'required|date',
                'fecha_final_libros'     => 'required|date',
                'detalles'               => 'required|array|min:1',
                'detalles.*.usuario_libro'         => 'nullable|string',
                'detalles.*.cod_libro_diario'      => 'nullable|string',
                'detalles.*.fecha_inicial_libros'  => 'required|date',
                'detalles.*.fecha_final_libros'   => 'nullable|date',
                'detalles.*.total_deposito'        => 'nullable|numeric',
                'detalles.*.total_traspaso'        => 'nullable|numeric',
                'detalles.*.total_recibos'         => 'nullable|numeric',
                'detalles.*.total_facturas'        => 'nullable|numeric',
                'detalles.*.total_entregado'       => 'nullable|numeric',
                'observacion'            => 'nullable|string',
                'usuario_entregue1'     => 'required|string',
                'usuario_recibi1'       => 'required|string',
                'usuario_entregue2'     => 'nullable|string',
                'usuario_recibi2'      => 'nullable|string',
                'id_actividad_economica' => 'nullable|integer',
            ]);

            $rowsIn = $data['detalles'];
            $normalizados = [];
            foreach ($rowsIn as $r) {
                if (! is_array($r)) {
                    continue;
                }
                $fi = $r['fecha_inicial_libros'] ?? $r['fecha_inicial'] ?? null;
                if (! $fi) {
                    return response()->json(['success' => false, 'message' => 'Cada detalle requiere fecha_inicial (libro).', 'url' => ''], 422);
                }
                $ff = $r['fecha_final_libros'] ?? $r['fecha_final'] ?? $fi;
                $normalizados[] = [
                    'usuario_libro'     => (string) ($r['usuario_libro'] ?? '—'),
                    'cod_libro_diario'  => (string) ($r['cod_libro_diario'] ?? '—'),
                    'fecha_inicial'     => Carbon::parse((string) $fi)->format('Y-m-d'),
                    'fecha_final'       => Carbon::parse((string) $ff)->format('Y-m-d'),
                    'total_recibos'     => (float) ($r['total_recibos'] ?? 0),
                    'total_facturas'    => (float) ($r['total_facturas'] ?? 0),
                    'total_entregado'  => (float) ($r['total_entregado'] ?? 0),
                ];
            }
            if ($normalizados === []) {
                return response()->json(['success' => false, 'message' => 'No hay detalles válidos.', 'url' => ''], 422);
            }
            usort($normalizados, function (array $a, array $b): int {
                $c = strcmp($a['fecha_inicial'], $b['fecha_inicial']);
                if ($c !== 0) {
                    return $c;
                }

                return strcmp($a['cod_libro_diario'], $b['cod_libro_diario']);
            });

            return $this->renderRecepcionIng4Pdf([
                'cod_num' => '—',
                'fecha_recepcion' => $data['fecha_recepcion'],
                'fecha_inicial_libros' => $data['fecha_inicial_libros'],
                'fecha_final_libros' => $data['fecha_final_libros'],
                'normalizados' => $normalizados,
                'observacion' => trim((string) ($data['observacion'] ?? '')),
                'usuario_entregue1' => $data['usuario_entregue1'],
                'usuario_recibi1' => $data['usuario_recibi1'],
                'usuario_entregue2' => $data['usuario_entregue2'] ?? null,
                'usuario_recibi2' => $data['usuario_recibi2'] ?? null,
                'id_actividad_economica' => $data['id_actividad_economica'] ?? null,
                'slug' => 'vista_previa_u' . (int) $authUserId,
                'log_tag' => 'vista previa',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('[ReporteRecepcionIngresoDepositoController] vistaPrevia', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'url' => '',
                'message' => 'Error al generar vista previa: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * PDF ING-4 definitivo tras registrar (misma plantilla que vista previa, con Nº correlativo real).
     * POST no requiere body; los datos se leen de `recepcion_ingresos` + `recepcion_ingreso_detalles`.
     */
    public function documentoRegistrado(int $id): \Illuminate\Http\JsonResponse
    {
        try {
            $authUserId = auth('sanctum')->id();
            if (! $authUserId || ! Usuario::query()->find((int) $authUserId)) {
                return response()->json(['success' => false, 'message' => 'No autenticado', 'url' => ''], 401);
            }

            $cab = RecepcionIngreso::with('detalles')->find($id);
            if (! $cab) {
                return response()->json(['success' => false, 'message' => 'Recepción no encontrada', 'url' => ''], 404);
            }
            if ($cab->detalles->isEmpty()) {
                return response()->json(['success' => false, 'message' => 'La recepción no tiene detalles.', 'url' => ''], 422);
            }

            $normalizados = [];
            foreach ($cab->detalles as $d) {
                $fiDt = $d->fecha_inicial_libros ?? $d->fecha_final_libros;
                $fi = $fiDt->format('Y-m-d');
                $ff = $d->fecha_final_libros->format('Y-m-d');
                $normalizados[] = [
                    'usuario_libro' => (string) ($d->usuario_libro ?? '—'),
                    'cod_libro_diario' => (string) ($d->cod_libro_diario ?? '—'),
                    'fecha_inicial' => $fi,
                    'fecha_final' => $ff,
                    'total_recibos' => (float) $d->total_recibos,
                    'total_facturas' => (float) $d->total_facturas,
                    'total_entregado' => (float) $d->total_entregado,
                ];
            }
            usort($normalizados, function (array $a, array $b): int {
                $c = strcmp($a['fecha_inicial'], $b['fecha_inicial']);
                if ($c !== 0) {
                    return $c;
                }

                return strcmp($a['cod_libro_diario'], $b['cod_libro_diario']);
            });

            $fechasIni = $cab->detalles->map(function ($d) {
                $dt = $d->fecha_inicial_libros ?? $d->fecha_final_libros;

                return $dt->format('Y-m-d');
            });
            $fechaIniLibros = $fechasIni->min();
            $fechaFinLibros = $cab->detalles->map(fn ($d) => $d->fecha_final_libros->format('Y-m-d'))->max();

            return $this->renderRecepcionIng4Pdf([
                'cod_num' => (string) $cab->cod_documento,
                'fecha_recepcion' => $cab->fecha_recepcion->format('Y-m-d'),
                'fecha_inicial_libros' => $fechaIniLibros,
                'fecha_final_libros' => $fechaFinLibros,
                'normalizados' => $normalizados,
                'observacion' => trim((string) ($cab->observacion ?? '')),
                'usuario_entregue1' => (string) $cab->usuario_entregue1,
                'usuario_recibi1' => (string) $cab->usuario_recibi1,
                'usuario_entregue2' => $cab->usuario_entregue2,
                'usuario_recibi2' => $cab->usuario_recibi2,
                'id_actividad_economica' => $cab->id_actividad_economica,
                'slug' => 'recepcion_' . $cab->id . '_u' . (int) $authUserId,
                'log_tag' => 'documento registrado',
            ]);
        } catch (\Throwable $e) {
            Log::error('[ReporteRecepcionIngresoDepositoController] documentoRegistrado', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'url' => '',
                'message' => 'Error al generar PDF: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * @param  array<string, mixed>  $ctx  cod_num, fecha_recepcion, fecha_inicial_libros, fecha_final_libros, normalizados, observacion, usuario_entregue*, usuario_recibi*, id_actividad_economica?, slug, log_tag
     */
    private function renderRecepcionIng4Pdf(array $ctx): \Illuminate\Http\JsonResponse
    {
        $normalizados = $ctx['normalizados'];
        if (! is_array($normalizados) || $normalizados === []) {
            return response()->json(['success' => false, 'message' => 'No hay detalles para el PDF.', 'url' => ''], 422);
        }

        $nombreActividad = '—';
        if (! empty($ctx['id_actividad_economica']) && Schema::hasTable('actividades_economicas')) {
            $n = DB::table('actividades_economicas')
                ->where('id_actividad_economica', (int) $ctx['id_actividad_economica'])
                ->value('nombre');
            if (is_string($n) && $n !== '') {
                $nombreActividad = $n;
            }
        }
        $conceptoEntrega = 'Caja Fuerte de Tesorería';

        $observacion = trim((string) ($ctx['observacion'] ?? ''));
        $sumaFalt = 0.0;
        foreach ($normalizados as $row) {
            $falt = $row['total_entregado'] - $row['total_recibos'] - $row['total_facturas'];
            $sumaFalt += $falt;
        }
        if (abs($sumaFalt) > 0.001) {
            if ($sumaFalt > 0) {
                $observacion = 'Sobrante:' . $this->fmtMonto2($sumaFalt) . '. ' . $observacion;
            } else {
                $observacion = 'Faltante:' . $this->fmtMonto2($sumaFalt) . '. ' . $observacion;
            }
        }

        $styleBorder = 'border: 1px solid #000066;';
        $styleColor = 'color: #000066; font-weight: bold;';

        $fr = Carbon::parse($ctx['fecha_recepcion'])->format('d/m/Y');
        $lDesde = $this->fechaLargaEs($ctx['fecha_inicial_libros']);
        $lHasta = $this->fechaLargaEs($ctx['fecha_final_libros']);
        $codNum = (string) $ctx['cod_num'];

        $sumRecT = 0.0;
        $sumFacT = 0.0;
        $sumGenT = 0.0;
        $tbody = '';
        $fechaAnalisis = '';
        $subRec = 0.0;
        $subFac = 0.0;
        $subGen = 0.0;

        foreach ($normalizados as $dato) {
            $fKey = $dato['fecha_inicial'];
            $totalRec = $dato['total_recibos'];
            $totalFac = $dato['total_facturas'];
            $totalG = $dato['total_entregado'];
            if ($fechaAnalisis === '') {
                $fechaAnalisis = $fKey;
            } elseif ($fechaAnalisis !== $fKey) {
                $tbody .= $this->filaSubtotalSga(
                    $styleBorder,
                    $this->fmtFechaCortaYmd($fechaAnalisis),
                    $subRec,
                    $subFac,
                    $subGen
                );
                $subRec = 0.0;
                $subFac = 0.0;
                $subGen = 0.0;
                $fechaAnalisis = $fKey;
            }
            $celdaFecha = htmlspecialchars(
                $this->textoFechaCelda($dato['fecha_inicial'], $dato['fecha_final']),
                ENT_QUOTES,
                'UTF-8'
            );
            $u = htmlspecialchars($dato['usuario_libro'], ENT_QUOTES, 'UTF-8');
            $cod = htmlspecialchars($dato['cod_libro_diario'], ENT_QUOTES, 'UTF-8');
            $tbody .= '<tr>
    <td style="' . $styleBorder . 'padding:2px 4px;text-align:center;vertical-align:middle;">' . $celdaFecha . '</td>
    <td style="' . $styleBorder . 'padding:2px 4px;vertical-align:middle;">' . $u . '</td>
    <td style="' . $styleBorder . 'padding:2px 4px;text-align:center;vertical-align:middle;">' . $cod . '</td>
    <td style="' . $styleBorder . 'padding:2px 4px;text-align:right;">' . $this->fmtMonto2($totalRec) . '</td>
    <td style="' . $styleBorder . 'padding:2px 4px;text-align:right;">' . $this->fmtMonto2($totalFac) . '</td>
    <td style="' . $styleBorder . 'padding:2px 4px;text-align:right;">' . $this->fmtMonto2($totalG) . '</td>
  </tr>';
            $sumRecT += $totalRec;
            $sumFacT += $totalFac;
            $sumGenT += $totalG;
            $subRec += $totalRec;
            $subFac += $totalFac;
            $subGen += $totalG;
        }
        if ($subGen > 0.0) {
            $tbody .= $this->filaSubtotalSga(
                $styleBorder,
                $this->fmtFechaCortaYmd($fechaAnalisis),
                $subRec,
                $subFac,
                $subGen
            );
        }
        $tbody .= $this->filaTotalSga($styleBorder, $sumRecT, $sumFacT, $sumGenT);
        if ($observacion !== '') {
            $obsH = htmlspecialchars($observacion, ENT_QUOTES, 'UTF-8');
            $tbody .= '<tr>
    <td colspan="6" style="border:1px solid #000066;padding:4px 6px;text-align:left;font-weight:bold;">Observación: ' . $obsH . '</td>
  </tr>';
        }

        $logoPad = 2;
        $logo = DompdfInstitucionLogoHelper::logoParaEncabezadoDompdf($logoPad);
        $logoImg = $logo['html'];
        $logoW = $logo['width'];
        $logoCellStyle = 'width:' . ($logoW + $logoPad * 2) . 'px; min-width:' . ($logoW + $logoPad * 2) . 'px; max-width:' . ($logoW + $logoPad * 2) . 'px; padding:' . $logoPad . 'px; ' . $styleBorder . ' border-right:none; vertical-align:middle; text-align:center; line-height:0;';

        $headerHtml = $this->buildEncabezadoIng4($logoImg, $logoCellStyle, $codNum, $styleBorder, $styleColor);
        $bloqueFechas = $this->buildBloqueFechasRecepcionRangoActividad(
            $fr,
            $lDesde,
            $lHasta,
            $nombreActividad,
            $styleBorder
        );
        $tablaHtml = $this->buildTablaCuerpoSga($tbody, $styleBorder, $styleColor);
        $litFac = BolivianosALetras::monto($sumFacT);
        $litRec = BolivianosALetras::monto($sumRecT);
        $bloqueConcepto = $this->buildBloqueConcepto(
            $this->fmtMonto2($sumFacT),
            $litFac,
            $this->fmtMonto2($sumRecT),
            $litRec,
            $styleBorder,
            $conceptoEntrega
        );
        $e1 = $this->nombreFirmasPorNickname((string) $ctx['usuario_entregue1']);
        $r1 = $this->nombreFirmasPorNickname((string) $ctx['usuario_recibi1']);
        $e2 = $this->nombreFirmasPorNickname((string) ($ctx['usuario_entregue2'] ?? ''));
        $r2 = $this->nombreFirmasPorNickname((string) ($ctx['usuario_recibi2'] ?? ''));
        $hayFirma2 = trim((string) ($ctx['usuario_entregue2'] ?? '')) !== ''
            || trim((string) ($ctx['usuario_recibi2'] ?? '')) !== '';
        $bloqueFirmas = $this->buildBloqueFirmasSga4($e1, $r1, $e2, $r2, $styleBorder, $hayFirma2);

        $fechaCorta = (new \DateTime('now', new \DateTimeZone('America/La_Paz')))->format('Y-m-d');
        $pageMt = self::PAGE_MARGIN_TOP_PT;
        $pageMb = self::PAGE_MARGIN_BOTTOM_PT;
        $wrapMin = self::WRAP_MIN_HEIGHT_PT;
        $mainPadBottom = self::MAIN_PADDING_BOTTOM_PARA_PIE_PT;

        $html = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8" />
    <title>ING-4 Recepción de ingresos</title>
    <style>
        @page { size: A4; margin: {$pageMt}pt 0.8cm {$pageMb}pt 0.8cm; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 8.5pt;
            color: #000;
            line-height: 1.15;
            margin: 0;
            padding: 0;
        }
        .recepcion-wrap__foot table { border-collapse: collapse; }
    </style>
</head>
<body>
<div class="recepcion-wrap" style="position:relative;width:100%;min-height:{$wrapMin}pt;">
    <div class="recepcion-wrap__main" style="padding-bottom:{$mainPadBottom}pt;">
{$headerHtml}
        <main class="recepcion-main">
{$bloqueFechas}
{$tablaHtml}
        </main>
    </div>
    <div class="recepcion-wrap__foot" style="position:absolute;bottom:0;left:0;right:0;background:#fff;">
{$bloqueConcepto}
{$bloqueFirmas}
    </div>
</div>
</body>
</html>
HTML;

        $slug = (string) $ctx['slug'];
        $path = $this->pdfService->generate($html, $slug, $fechaCorta);
        if (! is_file($path) || ! is_readable($path)) {
            return response()->json([
                'success' => false,
                'url' => '',
                'message' => 'No se pudo generar el PDF',
            ], 500);
        }
        $publicRoot = public_path();
        $relPath = ltrim(str_replace(DIRECTORY_SEPARATOR, '/', str_replace($publicRoot, '', $path)), '/');
        $url = url($relPath);

        Log::info('[ReporteRecepcionIngresoDepositoController] PDF ' . ($ctx['log_tag'] ?? 'ING-4'), ['path' => $path]);

        return response()->json([
            'success' => true,
            'url' => $url,
            'message' => 'PDF generado',
        ]);
    }

    private function buildEncabezadoIng4(
        string $logoImg,
        string $logoCellStyle,
        string $numeroFormulario,
        string $styleBorder,
        string $styleColor
    ): string {
        $numeroFormulario = htmlspecialchars($numeroFormulario, ENT_QUOTES, 'UTF-8');
        $inst = htmlspecialchars('Instituto Tecnológico de Enseñanza Automotriz "CETA"', ENT_QUOTES, 'UTF-8');

        $logoRow = $logoImg !== ''
            ? '<td rowspan="3" style="' . $logoCellStyle . '">' . $logoImg . '</td>'
            : '';

        return <<<HTML
<table width="100%" style="border-collapse:collapse;{$styleBorder};background:#fff;font-family:DejaVu Sans,sans-serif;margin:0 0 4px 0;line-height:1.1;">
  <tr>
    {$logoRow}
    <td style="{$styleBorder}padding:2px 5px;{$styleColor}text-align:center;font-size:8pt;line-height:1.15;vertical-align:middle;">
      {$inst}
    </td>
    <td style="{$styleBorder}padding:1px 4px;{$styleColor}text-align:center;font-size:7.5pt;vertical-align:middle;">Nº:</td>
    <td style="{$styleBorder}padding:1px 4px;text-align:center;font-size:7.5pt;vertical-align:middle;">{$numeroFormulario}</td>
  </tr>
  <tr>
    <td rowspan="2" style="{$styleBorder}padding:4px 6px;{$styleColor}text-align:center;font-size:10.5pt;line-height:1.12;vertical-align:middle;">
      REPORTE DE INGRESOS DIARIOS PARA<br/>DEPÓSITOS
    </td>
    <td style="{$styleBorder}padding:1px 4px;{$styleColor}text-align:center;font-size:7.5pt;vertical-align:middle;">Cod. Form. :</td>
    <td style="{$styleBorder}padding:1px 4px;text-align:center;font-size:7.5pt;vertical-align:middle;">ING-4</td>
  </tr>
  <tr>
    <td style="{$styleBorder}padding:1px 4px;{$styleColor}text-align:center;font-size:7.5pt;vertical-align:middle;">Versión :</td>
    <td style="{$styleBorder}padding:1px 4px;text-align:center;font-size:7.5pt;vertical-align:middle;">V.0.</td>
  </tr>
</table>
HTML;
    }

    private function buildBloqueConcepto(
        string $montoFac,
        string $literalFac,
        string $montoRec,
        string $literalRec,
        string $styleBorder,
        string $entregaA = 'Caja Fuerte de Tesorería'
    ): string {
        $montoFac = htmlspecialchars($montoFac, ENT_QUOTES, 'UTF-8');
        $literalFac = htmlspecialchars($literalFac, ENT_QUOTES, 'UTF-8');
        $montoRec = htmlspecialchars($montoRec, ENT_QUOTES, 'UTF-8');
        $literalRec = htmlspecialchars($literalRec, ENT_QUOTES, 'UTF-8');
        $entregaA = htmlspecialchars($entregaA, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<table width="100%" style="border-collapse:collapse;{$styleBorder};margin:0 0 5px 0;font-size:8pt;line-height:1.2;">
  <tr>
    <td style="{$styleBorder}padding:4px 6px;vertical-align:top;">
      <strong>CONCEPTO:</strong> El importe <strong>TOTAL FACTURAS</strong> de Bs{$montoFac}.- ({$literalFac}) se Depositará a las Cuentas Bancarias Fiscales del Instituto.<br/><br/>
      El importe <strong>TOTAL RECIBOS</strong> de Bs{$montoRec}.- ({$literalRec}) se hace entrega a: <strong>{$entregaA}</strong><br/><br/>
      Con la firma al pie del presente Formulario.
    </td>
  </tr>
</table>
HTML;
    }

    private function buildBloqueFirmas(string $nombreEntrega, string $nombreRecibe, string $styleBorder): string
    {
        $nombreEntrega = htmlspecialchars($nombreEntrega, ENT_QUOTES, 'UTF-8');
        $nombreRecibe = htmlspecialchars($nombreRecibe, ENT_QUOTES, 'UTF-8');
        $firmaRowHeight = 'height:72pt;min-height:72pt;';

        return <<<HTML
<table width="100%" style="border-collapse:collapse;{$styleBorder};margin:0 0 5px 0;font-size:7.5pt;line-height:1.15;">
  <tr>
    <td style="{$styleBorder}padding:2px 4px;text-align:center;width:50%;color:#000066;font-weight:bold;">ENTREGUE CONFORME (a Caja Fuerte):</td>
    <td style="{$styleBorder}padding:2px 4px;text-align:center;width:50%;color:#000066;font-weight:bold;">RECIBI CONFORME:</td>
  </tr>
  <tr>
    <td style="{$styleBorder}padding:4px 6px;vertical-align:top;{$firmaRowHeight}">Firma: ____________________<br/>Nombre: {$nombreEntrega}</td>
    <td style="{$styleBorder}padding:4px 6px;vertical-align:top;{$firmaRowHeight}">Firma: ____________________<br/>Nombre: {$nombreRecibe}</td>
  </tr>
</table>
<table width="100%" style="border-collapse:collapse;{$styleBorder};margin:0;font-size:7.5pt;line-height:1.15;">
  <tr>
    <td style="{$styleBorder}padding:2px 4px;text-align:center;width:50%;color:#000066;font-weight:bold;">ENTREGUE CONFORME:</td>
    <td style="{$styleBorder}padding:2px 4px;text-align:center;width:50%;color:#000066;font-weight:bold;">RECIBI CONFORME:</td>
  </tr>
  <tr>
    <td style="{$styleBorder}padding:4px 6px;vertical-align:top;{$firmaRowHeight}">Firma: ____________________<br/>Nombre: ________________________________</td>
    <td style="{$styleBorder}padding:4px 6px;vertical-align:top;{$firmaRowHeight}">Firma: ____________________<br/>Nombre: ________________________________</td>
  </tr>
</table>
HTML;
    }

    private function buildBloqueFechasActividad(
        string $fechaRecepcion,
        string $actividad,
        string $styleBorder
    ): string {
        $fechaRecepcion = htmlspecialchars($fechaRecepcion, ENT_QUOTES, 'UTF-8');
        $actividad = htmlspecialchars($actividad, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<table width="100%" style="border-collapse:collapse;{$styleBorder};margin:0 0 0px 0;font-size:8pt;line-height:1.2;">
  <tr>
    <td style="{$styleBorder}padding:3px 6px;"><strong>FECHA RECEPCIÓN:</strong> {$fechaRecepcion}</td>
  </tr>
  <tr>
    <td style="{$styleBorder}padding:3px 6px;"><strong>ACTIVIDAD:</strong> {$actividad}</td>
  </tr>
</table>
HTML;
    }

    private function buildTablaDetalle(
        string $filasBody,
        string $subtotalFecha,
        string $subRec,
        string $subFac,
        string $subGen,
        string $totRec,
        string $totFac,
        string $totGen,
        string $styleBorder,
        string $styleColor
    ): string {
        $subtotalFecha = htmlspecialchars($subtotalFecha, ENT_QUOTES, 'UTF-8');
        $subRec = htmlspecialchars($subRec, ENT_QUOTES, 'UTF-8');
        $subFac = htmlspecialchars($subFac, ENT_QUOTES, 'UTF-8');
        $subGen = htmlspecialchars($subGen, ENT_QUOTES, 'UTF-8');
        $totRec = htmlspecialchars($totRec, ENT_QUOTES, 'UTF-8');
        $totFac = htmlspecialchars($totFac, ENT_QUOTES, 'UTF-8');
        $totGen = htmlspecialchars($totGen, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<table width="100%" style="border-collapse:collapse;{$styleBorder};margin:0;font-size:7.5pt;line-height:1.1;">
  <thead>
    <tr>
      <th rowspan="2" style="{$styleBorder}padding:2px 3px;{$styleColor}text-align:center;vertical-align:middle;">FECHA</th>
      <th rowspan="2" style="{$styleBorder}padding:2px 3px;{$styleColor}text-align:center;vertical-align:middle;">SECRETARIA / CAJERA</th>
      <th rowspan="2" style="{$styleBorder}padding:2px 3px;{$styleColor}text-align:center;vertical-align:middle;">Numero de<br/>Reporte Diario</th>
      <th colspan="3" style="{$styleBorder}padding:2px 3px;{$styleColor}text-align:center;vertical-align:middle;">TOTAL</th>
    </tr>
    <tr>
      <th style="{$styleBorder}padding:2px 3px;{$styleColor}text-align:center;">(a)<br/>RECIBOS</th>
      <th style="{$styleBorder}padding:2px 3px;{$styleColor}text-align:center;">(b)<br/>FACTURAS</th>
      <th style="{$styleBorder}padding:2px 3px;{$styleColor}text-align:center;">(a+b)<br/>GENERAL</th>
    </tr>
  </thead>
  <tbody>
{$filasBody}
    <tr>
      <td colspan="3" style="{$styleBorder}padding:3px 4px;font-weight:bold;">SUBTOTAL en Bs fecha {$subtotalFecha}:</td>
      <td style="{$styleBorder}padding:3px 4px;text-align:right;">{$subRec}</td>
      <td style="{$styleBorder}padding:3px 4px;text-align:right;">{$subFac}</td>
      <td style="{$styleBorder}padding:3px 4px;text-align:right;">{$subGen}</td>
    </tr>
    <tr>
      <td colspan="3" style="{$styleBorder}padding:3px 4px;font-weight:bold;">TOTAL GENERAL en Bs:</td>
      <td style="{$styleBorder}padding:3px 4px;text-align:right;font-weight:bold;">{$totRec}</td>
      <td style="{$styleBorder}padding:3px 4px;text-align:right;font-weight:bold;">{$totFac}</td>
      <td style="{$styleBorder}padding:3px 4px;text-align:right;font-weight:bold;">{$totGen}</td>
    </tr>
  </tbody>
</table>
HTML;
    }

    private function buildBloqueFechasRecepcionRangoActividad(
        string $fechaRecepcionDmY,
        string $literalDesde,
        string $literalHasta,
        string $actividad,
        string $styleBorder
    ): string {
        $fechaRecepcionDmY = htmlspecialchars($fechaRecepcionDmY, ENT_QUOTES, 'UTF-8');
        $literalDesde = htmlspecialchars($literalDesde, ENT_QUOTES, 'UTF-8');
        $literalHasta = htmlspecialchars($literalHasta, ENT_QUOTES, 'UTF-8');
        $actividad = htmlspecialchars($actividad, ENT_QUOTES, 'UTF-8');
        $rango = $literalDesde . ' AL ' . $literalHasta;

        return <<<HTML
<table width="100%" style="border-collapse:collapse;{$styleBorder};margin:0 0 4px 0;font-size:8pt;line-height:1.2;">
  <tr>
    <td style="{$styleBorder}padding:3px 6px;"><strong>FECHA RECEPCIÓN:</strong> {$fechaRecepcionDmY}</td>
  </tr>
  <tr>
    <td style="{$styleBorder}padding:3px 6px;"><strong>FECHA REPORTES DIARIOS:</strong> {$rango}</td>
  </tr>
  <tr>
    <td style="{$styleBorder}padding:3px 6px;"><strong>ACTIVIDAD:</strong> {$actividad}</td>
  </tr>
</table>
HTML;
    }

    private function buildTablaCuerpoSga(
        string $tbodyInner,
        string $styleBorder,
        string $styleColor
    ): string {
        return <<<HTML
<table width="100%" style="border-collapse:collapse;{$styleBorder};margin:0;font-size:7.5pt;line-height:1.1;">
  <thead>
    <tr>
      <th rowspan="2" style="{$styleBorder}padding:2px 3px;{$styleColor}text-align:center;vertical-align:middle;">FECHA</th>
      <th rowspan="2" style="{$styleBorder}padding:2px 3px;{$styleColor}text-align:center;vertical-align:middle;">SECRETARIA / CAJERA</th>
      <th rowspan="2" style="{$styleBorder}padding:2px 3px;{$styleColor}text-align:center;vertical-align:middle;">Numero de<br/>Reporte Diario</th>
      <th colspan="3" style="{$styleBorder}padding:2px 3px;{$styleColor}text-align:center;vertical-align:middle;">TOTAL</th>
    </tr>
    <tr>
      <th style="{$styleBorder}padding:2px 3px;{$styleColor}text-align:center;">(a)<br/>RECIBOS</th>
      <th style="{$styleBorder}padding:2px 3px;{$styleColor}text-align:center;">(b)<br/>FACTURAS</th>
      <th style="{$styleBorder}padding:2px 3px;{$styleColor}text-align:center;">(a+b)<br/>GENERAL</th>
    </tr>
  </thead>
  <tbody>
{$tbodyInner}
  </tbody>
</table>
HTML;
    }

    private function buildBloqueFirmasSga4(
        string $e1,
        string $r1,
        string $e2,
        string $r2,
        string $styleBorder,
        bool $incluirSegundaPareja = true
    ): string {
        $e1 = htmlspecialchars($e1, ENT_QUOTES, 'UTF-8');
        $r1 = htmlspecialchars($r1, ENT_QUOTES, 'UTF-8');
        $e2h = $e2 !== '' ? htmlspecialchars($e2, ENT_QUOTES, 'UTF-8') : '______________________________';
        $r2h = $r2 !== '' ? htmlspecialchars($r2, ENT_QUOTES, 'UTF-8') : '______________________________';
        $firmaRowHeight = 'height:72pt;min-height:72pt;';

        $tabla1 = <<<HTML
<table width="100%" style="border-collapse:collapse;{$styleBorder};margin:0 0 5px 0;font-size:7.5pt;line-height:1.15;">
  <tr>
    <td style="{$styleBorder}padding:2px 4px;text-align:center;width:50%;color:#000066;font-weight:bold;">ENTREGUE CONFORME (a Caja Fuerte):</td>
    <td style="{$styleBorder}padding:2px 4px;text-align:center;width:50%;color:#000066;font-weight:bold;">RECIBI CONFORME:</td>
  </tr>
  <tr>
    <td style="{$styleBorder}padding:4px 6px;vertical-align:top;{$firmaRowHeight}">Firma: ____________________<br/>Nombre: {$e1}</td>
    <td style="{$styleBorder}padding:4px 6px;vertical-align:top;{$firmaRowHeight}">Firma: ____________________<br/>Nombre: {$r1}</td>
  </tr>
</table>
HTML;

        if (! $incluirSegundaPareja) {
            return $tabla1;
        }

        $tabla2 = <<<HTML
<table width="100%" style="border-collapse:collapse;{$styleBorder};margin:0;font-size:7.5pt;line-height:1.15;">
  <tr>
    <td style="{$styleBorder}padding:2px 4px;text-align:center;width:50%;color:#000066;font-weight:bold;">ENTREGUE CONFORME:</td>
    <td style="{$styleBorder}padding:2px 4px;text-align:center;width:50%;color:#000066;font-weight:bold;">RECIBI CONFORME:</td>
  </tr>
  <tr>
    <td style="{$styleBorder}padding:4px 6px;vertical-align:top;{$firmaRowHeight}">Firma: ____________________<br/>Nombre: {$e2h}</td>
    <td style="{$styleBorder}padding:4px 6px;vertical-align:top;{$firmaRowHeight}">Firma: ____________________<br/>Nombre: {$r2h}</td>
  </tr>
</table>
HTML;

        return $tabla1 . $tabla2;
    }

    private function filaSubtotalSga(
        string $styleBorder,
        string $fechaSubDmY,
        float $subRec,
        float $subFac,
        float $subGen
    ): string {
        $fe = htmlspecialchars($fechaSubDmY, ENT_QUOTES, 'UTF-8');

        return '<tr>
    <td colspan="3" style="' . $styleBorder . 'padding:3px 4px;font-weight:bold;">SUBTOTAL en Bs fecha ' . $fe . ':</td>
    <td style="' . $styleBorder . 'padding:3px 4px;text-align:right;font-weight:bold;">' . $this->fmtMonto2($subRec) . '</td>
    <td style="' . $styleBorder . 'padding:3px 4px;text-align:right;font-weight:bold;">' . $this->fmtMonto2($subFac) . '</td>
    <td style="' . $styleBorder . 'padding:3px 4px;text-align:right;font-weight:bold;">' . $this->fmtMonto2($subGen) . '</td>
  </tr>';
    }

    private function filaTotalSga(string $styleBorder, float $sumRec, float $sumFac, float $sumGen): string
    {
        return '<tr>
    <td colspan="3" style="' . $styleBorder . 'padding:3px 4px;font-weight:bold;">TOTAL GENERAL en Bs:</td>
    <td style="' . $styleBorder . 'padding:3px 4px;text-align:right;font-weight:bold;">' . $this->fmtMonto2($sumRec) . '</td>
    <td style="' . $styleBorder . 'padding:3px 4px;text-align:right;font-weight:bold;">' . $this->fmtMonto2($sumFac) . '</td>
    <td style="' . $styleBorder . 'padding:3px 4px;text-align:right;font-weight:bold;">' . $this->fmtMonto2($sumGen) . '</td>
  </tr>';
    }

    private function fmtMonto2(float $v): string
    {
        return number_format($v, 2, ',', '.');
    }

    private function fmtFechaCortaYmd(string $ymd): string
    {
        return Carbon::parse($ymd)->format('d/m/Y');
    }

    private function textoFechaCelda(string $ymdInicial, string $ymdFinal): string
    {
        $a = $this->fmtFechaCortaYmd($ymdInicial);
        if ($ymdFinal !== '' && $ymdFinal !== $ymdInicial) {
            return $a . ' al ' . $this->fmtFechaCortaYmd($ymdFinal);
        }

        return $a;
    }

    private function fechaLargaEs(string $fecha): string
    {
        $c = Carbon::parse($fecha, 'America/La_Paz');
        $c->locale('es');

        return (string) $c->isoFormat('dddd, D [de] MMMM [de] YYYY');
    }

    private function nombreFirmasPorNickname(string $nick): string
    {
        $n = trim($nick);
        if ($n === '') {
            return '';
        }
        $norm = Usuario::normalizeNickname($n);
        $nombre = Usuario::query()->where('nickname', $norm)->value('nombre');

        return $nombre ? (string) $nombre : $n;
    }
}
