<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Recibo;
use App\Services\ReciboPdfService;
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
}
