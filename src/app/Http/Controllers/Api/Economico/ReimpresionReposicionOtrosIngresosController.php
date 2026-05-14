<?php

namespace App\Http\Controllers\Api\Economico;

use App\Http\Controllers\Controller;
use App\Services\Economico\NotaOtrosIngresosPdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lista y PDF de reimpresión de nota reposición otros ingresos (flujo tipo SGA reimpresiones).
 */
class ReimpresionReposicionOtrosIngresosController extends Controller
{
	public function __construct(
		private readonly NotaOtrosIngresosPdfService $notaOtrosPdfService
	) {
	}

	public function buscarPorFecha(Request $request): JsonResponse
	{
		$request->validate([
			'fecha_ini' => 'required|string|max:15',
			'fecha_fin' => 'required|string|max:15',
			'opcion' => 'nullable|string|max:32',
		]);
		try {
			$rows = $this->notaOtrosPdfService->listarReimpresionOtrosPorRangoFecha(
				$request->string('fecha_ini')->toString(),
				$request->string('fecha_fin')->toString()
			);
		} catch (\InvalidArgumentException $e) {
			return response()->json([
				'success' => false,
				'message' => $e->getMessage(),
				'rows' => [],
			], 422);
		}

		return response()->json(['success' => true, 'rows' => $rows]);
	}

	public function buscarPorDocumento(Request $request): JsonResponse
	{
		$request->validate([
			'nro_nota_deposito' => ['required', 'string', 'size:8', 'regex:/^[A-Za-z0-9]{8}$/'],
		]);

		$key = $request->string('nro_nota_deposito')->toString();
		$rows = $this->notaOtrosPdfService->listarReimpresionOtrosPorDocumento($key);

		return response()->json(['success' => true, 'rows' => $rows]);
	}

	public function generarNotaReposicion(Request $request): JsonResponse
	{
		$request->validate([
			'num_doc' => ['required', 'string', 'size:8', 'regex:/^[A-Za-z0-9]{8}$/'],
		]);

		$url = $this->notaOtrosPdfService->generarPdfReimpresionReposicionOtros($request->string('num_doc')->toString());
		if ($url === null) {
			return response()->json([
				'success' => false,
				'message' => 'No existe nota de reposición para ese documento.',
			], 404);
		}

		return response()->json([
			'success' => true,
			'url' => $url,
		]);
	}
}
