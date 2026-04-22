<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Recibo;
use App\Services\ReciboPdfService;
use App\Services\NotaTraspasoPdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ReciboController extends Controller
{
	/**
	 * Obtener todos los recibos
	 */
	public function index(Request $request)
	{
		try {
			$recibos = Recibo::select([
				'nro_recibo',
				'cliente',
				'nro_documento_cobro',
				'anio',
				'monto_total',
				'estado',
				'created_at'
			])
			->orderBy('nro_recibo', 'desc')
			->get();

			return response()->json([
				'success' => true,
				'data' => $recibos
			]);
		} catch (\Throwable $e) {
			Log::error('ReciboController.index error', ['error' => $e->getMessage()]);
			return response()->json([
				'success' => false,
				'message' => 'Error al obtener recibos: ' . $e->getMessage(),
			], 500);
		}
	}

	/**
	 * Obtener un recibo específico
	 */
	public function show($anio, $nro_recibo)
	{
		try {
			$recibo = Recibo::where('anio', $anio)
				->where('nro_recibo', $nro_recibo)
				->first();

			if (!$recibo) {
				return response()->json([
					'success' => false,
					'message' => 'Recibo no encontrado'
				], 404);
			}

			return response()->json([
				'success' => true,
				'data' => $recibo
			]);
		} catch (\Throwable $e) {
			Log::error('ReciboController.show error', ['error' => $e->getMessage()]);
			return response()->json([
				'success' => false,
				'message' => 'Error al obtener recibo: ' . $e->getMessage(),
			], 500);
		}
	}

	public function pdf($anio, $nro_recibo, ReciboPdfService $pdfService)
	{
		try {
			$anio = (int) $anio;
			$nro = (int) $nro_recibo;
			$content = $pdfService->buildPdf($anio, $nro);
			$filename = sprintf('recibo_%d_%d.pdf', $anio, $nro);
			return response($content, 200, [
				'Content-Type' => 'application/pdf',
				'Content-Disposition' => 'attachment; filename="' . $filename . '"'
			]);
		} catch (\Throwable $e) {
			Log::error('ReciboController.pdf error', [ 'error' => $e->getMessage() ]);
			return response()->json([
				'success' => false,
				'message' => 'No se pudo generar el PDF del recibo: ' . $e->getMessage(),
			], 500);
		}
	}

	public function notaBancariaPdfByFactura($anio, $nro_factura, ReciboPdfService $pdfService)
	{
		try {
			$anio = (int) $anio;
			$nroFactura = (int) $nro_factura;
			$content = $pdfService->buildNotaBancariaPdfByFactura($anio, $nroFactura);
			$filename = sprintf('nota_bancaria_factura_%d_%d.pdf', $anio, $nroFactura);
			return response($content, 200, [
				'Content-Type' => 'application/pdf',
				'Content-Disposition' => 'attachment; filename="' . $filename . '"'
			]);
		} catch (\Throwable $e) {
			Log::error('ReciboController.notaBancariaPdfByFactura error', [ 'error' => $e->getMessage() ]);
			return response()->json([
				'success' => false,
				'message' => 'No se pudo generar el PDF de la nota bancaria: ' . $e->getMessage(),
			], 500);
		}
	}

	/** GET /notas-traspaso/{anio}/{correlativo}/pdf */
	public function notaTraspasoPdf($anio, $correlativo, NotaTraspasoPdfService $pdfService)
	{
		try {
			$content  = $pdfService->buildPdf((int) $anio, (int) $correlativo);
			$filename = sprintf('nota_traspaso_%d_%d.pdf', (int) $anio, (int) $correlativo);
			return response($content, 200, [
				'Content-Type'        => 'application/pdf',
				'Content-Disposition' => 'attachment; filename="' . $filename . '"',
			]);
		} catch (\Throwable $e) {
			Log::error('ReciboController.notaTraspasoPdf error', ['error' => $e->getMessage()]);
			return response()->json(['success' => false, 'message' => 'No se pudo generar el PDF: ' . $e->getMessage()], 500);
		}
	}

	/** GET /notas-traspaso-por-factura/{anio}/{nro_factura}/pdf */
	public function notaTraspasoPdfByFactura($anio, $nro_factura, NotaTraspasoPdfService $pdfService)
	{
		try {
			$content  = $pdfService->buildPdfByFactura((int) $anio, (int) $nro_factura);
			$filename = sprintf('nota_traspaso_factura_%d_%d.pdf', (int) $anio, (int) $nro_factura);
			return response($content, 200, [
				'Content-Type'        => 'application/pdf',
				'Content-Disposition' => 'attachment; filename="' . $filename . '"',
			]);
		} catch (\Throwable $e) {
			Log::error('ReciboController.notaTraspasoPdfByFactura error', ['error' => $e->getMessage()]);
			return response()->json(['success' => false, 'message' => 'No se pudo generar el PDF: ' . $e->getMessage()], 500);
		}
	}
}
