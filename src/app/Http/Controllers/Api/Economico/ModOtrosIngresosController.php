<?php

namespace App\Http\Controllers\Api\Economico;

use App\Http\Controllers\Controller;
use App\Services\Economico\OtrosIngresosService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ModOtrosIngresosController extends Controller
{
	public function __construct(
		private readonly OtrosIngresosService $service
	) {
	}

	public function initialData(): JsonResponse
	{
		return response()->json([
			'success' => true,
			'data' => [
				'pensums' => $this->service->listPensumsConCarrera(),
				'gestiones' => $this->service->listGestionesActivas(),
				'gestion_cobro' => $this->service->getGestionCobroValor(),
			],
		]);
	}

	public function buscar(Request $request): JsonResponse
	{
		$request->validate(['documento' => 'required|string|max:30']);
		$rows = $this->service->buscarDocumento($request->string('documento')->toString());
		return response()->json([
			'success' => true,
			'data' => $rows,
		]);
	}

	public function eliminar(Request $request): JsonResponse
	{
		$request->validate(['id' => 'required|integer']);
		$ok = $this->service->eliminarPorId((int) $request->input('id'));
		return response()->json([
			'success' => $ok,
			'message' => $ok ? 'exito' : 'No se pudo eliminar el registro',
		], $ok ? 200 : 422);
	}

	public function registrarMod(Request $request): JsonResponse
	{
		$request->validate([
			'id' => 'required|integer',
			'num_factura' => 'required|integer',
			'num_recibo' => 'required|integer',
			'razon_social' => 'nullable|string|max:255',
			'nit' => 'required|string|max:50',
			'autorizacion' => 'nullable|string|max:100',
			'fecha' => 'required|string',
			'monto' => 'required|numeric',
			'valido' => 'nullable|string|max:1',
			'concepto' => 'nullable|string',
			'cod_pensum' => 'required|string|max:50',
			'codigo_carrera' => 'nullable|string|max:50',
			'gestion' => 'required|string|max:30',
			'subtotal' => 'nullable|numeric',
			'descuento' => 'nullable|numeric',
			'observaciones' => 'nullable|string',
		]);

		$gestion = $request->string('gestion')->toString();
		$codPensum = $request->string('cod_pensum')->toString();
		$aut = trim((string) $request->input('autorizacion', ''));
		if (!$this->service->autorizacionPermitidaParaGestion($gestion, $aut, $codPensum)) {
			return response()->json([
				'estado' => 'La autorización no corresponde a una directiva activa para la gestión seleccionada.',
			], 422);
		}

		$ok = $this->service->actualizarPorId((int) $request->input('id'), $request->all());
		return response()->json([
			'estado' => $ok ? 'exito' : 'No se pudo modificar el registro',
			'hora' => now()->format('H:i:s'),
		], $ok ? 200 : 422);
	}
}
