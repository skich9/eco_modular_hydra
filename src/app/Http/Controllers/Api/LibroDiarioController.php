<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use App\Services\Reportes\LibroDiarioAccessService;
use App\Services\Reportes\LibroDiarioAggregatorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint único del Libro Diario: agrega cobros, facturas, QR, recibos y otros ingresos en backend.
 * El front pasa a tener una sola llamada; el PDF también consume estos mismos datos.
 */
class LibroDiarioController extends Controller
{
	public function __construct(
		private readonly LibroDiarioAggregatorService $agregador
	) {
	}

	public function index(Request $request): JsonResponse
	{
		$request->validate([
			'id_usuario' => 'required|integer|min:1',
			'fecha_inicio' => 'nullable|string',
			'fecha_fin' => 'nullable|string',
			'fecha' => 'nullable|string',
			'codigo_carrera' => 'nullable|string|max:50',
		]);

		$authUserId = auth('sanctum')->id();
		$authUser = $authUserId ? Usuario::query()->find((int) $authUserId) : null;
		if (!$authUser) {
			return response()->json(['success' => false, 'message' => 'No autenticado'], 401);
		}

		$idUsuarioReq = (int) $request->query('id_usuario');
		if (!LibroDiarioAccessService::puedeConsultarLibroDiarioDe($authUser, $idUsuarioReq)) {
			return response()->json([
				'success' => false,
				'message' => 'No está autorizado para consultar el Libro Diario de ese usuario. Solo su propio libro o los roles con visión global (rector, tesorería, contabilidad, sistemas) pueden consultar otros usuarios.',
			], 403);
		}

		$fechaInicio = (string) $request->query('fecha_inicio', (string) $request->query('fecha', ''));
		$fechaFin = (string) $request->query('fecha_fin', $fechaInicio);

		$usuarioDisplay = '';
		$target = Usuario::find($idUsuarioReq);
		if ($target) {
			$usuarioDisplay = (string) ($target->nickname ?? $target->nombre ?? $target->id_usuario);
		}

		$resultado = $this->agregador->build([
			'id_usuario' => $idUsuarioReq,
			'fecha_inicio' => $fechaInicio,
			'fecha_fin' => $fechaFin,
			'codigo_carrera' => (string) $request->query('codigo_carrera', ''),
			'usuario_display' => $usuarioDisplay,
		]);

		return response()->json([
			'success' => true,
			'data' => $resultado,
		]);
	}
}
