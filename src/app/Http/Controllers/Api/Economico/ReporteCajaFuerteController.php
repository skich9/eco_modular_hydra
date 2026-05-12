<?php

namespace App\Http\Controllers\Api\Economico;

use App\Http\Controllers\Controller;
use App\Services\Economico\ReporteCajaFuerteService;
use App\Services\Economico\ReporteCajaFuertePdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ReporteCajaFuerteController extends Controller
{
    public function __construct(
        private readonly ReporteCajaFuerteService    $service,
        private readonly ReporteCajaFuertePdfService $pdfService,
    ) {}

    /** GET /api/economico/reporte-caja-fuerte/initial */
    public function initial(): JsonResponse
    {
        return response()->json([
            'cajas' => $this->service->listCajas(),
        ]);
    }

    /** POST /api/economico/reporte-caja-fuerte/movimientos */
    public function movimientos(Request $request): JsonResponse
    {
        $request->validate([
            'fecha_ini'          => 'required|date_format:Y-m',
            'id_caja_actividad'  => 'required|integer|exists:cajas_actividad,id_caja_actividad',
        ]);

        $fechaIni = $request->input('fecha_ini') . '-01';
        $idCaja   = (int) $request->input('id_caja_actividad');

        $saldoAnterior = $this->service->getSaldoAnterior($idCaja, $fechaIni);
        $movimientos   = $this->service->getMovimientos($idCaja, $fechaIni);
        $conSaldos     = $this->service->calcularSaldos($movimientos, $saldoAnterior);

        return response()->json([
            'saldo_anterior'  => $saldoAnterior,
            'movimientos'     => $conSaldos->values(),
            'total_ingresos'  => $movimientos->sum('ingreso'),
            'total_egresos'   => $movimientos->sum('egreso'),
            'saldo_final'     => $saldoAnterior + $movimientos->sum('ingreso') - $movimientos->sum('egreso'),
        ]);
    }

    /** POST /api/economico/reporte-caja-fuerte/verificar */
    public function verificar(Request $request): JsonResponse
    {
        $request->validate([
            'fecha_ini'         => 'required|date_format:Y-m',
            'id_caja_actividad' => 'required|integer|exists:cajas_actividad,id_caja_actividad',
        ]);

        $fechaIni = $request->input('fecha_ini') . '-01';
        $reporte  = $this->service->getReporteMes((int) $request->input('id_caja_actividad'), $fechaIni);

        return response()->json([
            'existe'   => $reporte !== null,
            'anulado'  => $reporte?->anulado ?? false,
            'reporte'  => $reporte,
        ]);
    }

    /** POST /api/economico/reporte-caja-fuerte/imprimir */
    public function imprimir(Request $request): JsonResponse
    {
        $request->validate([
            'fecha_ini'         => 'required|date_format:Y-m',
            'id_caja_actividad' => 'required|integer|exists:cajas_actividad,id_caja_actividad',
            'monto'             => 'required|numeric',
            'reimpreso'         => 'boolean',
        ]);

        $fechaIni = $request->input('fecha_ini') . '-01';
        $idCaja   = (int) $request->input('id_caja_actividad');
        $reimpreso = (bool) $request->input('reimpreso', false);

        $existente = $this->service->getReporteMes($idCaja, $fechaIni);

        if ($existente && !$reimpreso) {
            return response()->json(['message' => 'Ya existe un reporte para este mes.'], 422);
        }

        if (!$existente) {
            $reporte = $this->service->guardarReporte([
                'fecha_ini'         => $request->input('fecha_ini'),
                'id_caja_actividad' => $idCaja,
                'monto'             => $request->input('monto'),
                'usuario'           => Auth::id(),
            ]);
        } else {
            $reporte = $existente;
        }

        $datosPdf = $this->service->datosParaPdf($idCaja, $fechaIni);
        $u        = Auth::user();
        $usuario  = trim(($u->nombre ?? '') . ' ' . ($u->ap_paterno ?? '') . ' ' . ($u->ap_materno ?? ''))
                    ?: ($u->nickname ?? (string) $u->getAuthIdentifier());
        $url      = $this->pdfService->generar($datosPdf, $reporte->cod_documento, $usuario);

        return response()->json([
            'reporte' => $reporte,
            'url'     => $url,
        ]);
    }

    /** POST /api/economico/reporte-caja-fuerte/anular */
    public function anular(Request $request): JsonResponse
    {
        $request->validate([
            'codigo_reporte'    => 'required|integer|exists:reporte_caja_fuerte_mensual,codigo_reporte',
            'motivo_anulacion'  => 'required|string|max:255',
        ]);

        $this->service->anularReporte(
            (int) $request->input('codigo_reporte'),
            $request->input('motivo_anulacion')
        );

        return response()->json(['message' => 'Reporte anulado correctamente.']);
    }
}
