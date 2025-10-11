<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReciboPdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ReciboController extends Controller
{
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
