<?php

namespace App\Http\Controllers\Api\Economico;

use App\Http\Controllers\Controller;
use App\Services\Economico\OtrosIngresosService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class OtrosIngresosController extends Controller
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
				'tipos_ingreso' => $this->service->listTiposIngreso(),
				'formas_cobro' => $this->service->listFormasCobroParaOtrosIngresos(),
				'siguiente_num_recibo' => $this->service->siguienteNumeroReciboOtrosIngresos(),
				'siguiente_num_factura' => $this->service->siguienteNumeroFacturaOtrosIngresos(),
			],
		]);
	}

	/** Siguiente correlativo de Nº recibo en `otros_ingresos` (vista previa para el formulario). */
	public function siguienteNumRecibo(): JsonResponse
	{
		return response()->json([
			'success' => true,
			'siguiente' => $this->service->siguienteNumeroReciboOtrosIngresos(),
		]);
	}

	/** Siguiente correlativo de Nº factura en `otros_ingresos.num_factura` (vista previa). */
	public function siguienteNumFactura(): JsonResponse
	{
		return response()->json([
			'success' => true,
			'siguiente' => $this->service->siguienteNumeroFacturaOtrosIngresos(),
		]);
	}

	public function getAutorizaciones(Request $request): JsonResponse
	{
		$request->validate(['cod_pensum' => 'required|string|max:50']);
		$rows = $this->service->getAutorizaciones($request->string('cod_pensum')->toString());
		return response()->json($rows);
	}

	/** Directivas activas por gestión y pensum (`eco_directiva_gestion`; `cod_pensum` vacío = todos los pensums). */
	public function getDirectivas(Request $request): JsonResponse
	{
		$request->validate([
			'gestion' => 'required|string|max:30',
			'cod_pensum' => 'nullable|string|max:50',
		]);
		$gestion = $request->string('gestion')->toString();
		$codPensum = $request->filled('cod_pensum')
			? $request->string('cod_pensum')->toString()
			: null;
		$rows = $this->service->listDirectivasParaSelector($gestion, $codPensum);
		return response()->json($rows);
	}

	/** Texto plano: exito | fuera_de_rango | no_activo (rango según `eco_directiva_gestion`). */
	public function perteneceDirectiva(Request $request): \Illuminate\Http\Response
	{
		$request->validate([
			'factura' => 'required|numeric',
			'autorizacion' => 'nullable|string|max:80',
			'gestion' => 'nullable|string|max:30',
			'cod_pensum' => 'nullable|string|max:50',
		]);
		$result = $this->service->perteneceRangoDirectiva(
			(int) $request->input('factura'),
			trim((string) $request->input('autorizacion', '')),
			$request->filled('gestion') ? $request->string('gestion')->toString() : null,
			$request->filled('cod_pensum') ? $request->string('cod_pensum')->toString() : null,
		);
		return response($result, 200)->header('Content-Type', 'text/plain; charset=UTF-8');
	}

	/** Texto plano: "exito" o HTML con <li>...</li> como en SGA. */
	public function facturaExiste(Request $request): \Illuminate\Http\Response
	{
		$request->validate([
			'factura' => 'required|numeric',
			'autorizacion' => 'nullable|string|max:80',
		]);
		$msg = $this->service->facturaExiste(
			(int) $request->input('factura'),
			(string) $request->input('autorizacion', '')
		);
		return response($msg, 200)->header('Content-Type', 'text/html; charset=UTF-8');
	}

	public function registrar(Request $request): JsonResponse
	{
		$user = Auth::user();
		if (!$user) {
			return response()->json(['message' => 'No autenticado'], 401);
		}

		$request->validate([
			'fecha' => 'required|string',
			'cod_pensum' => 'required|string|max:50',
			'codigo_carrera' => 'nullable|string|max:50',
			'nombre_carrera' => 'nullable|string|max:255',
			'gestion' => 'required|string|max:30',
			'nit' => 'required|string|max:50',
			'razon_social' => 'nullable|string|max:255',
			'monto' => 'required|numeric',
			'subtotal' => 'nullable|numeric',
			'descuento' => 'nullable|numeric',
			'num_factura' => 'nullable|integer',
			'num_recibo' => 'nullable|integer',
			'autorizacion' => 'nullable|string|max:100',
			'codigo_control' => 'nullable|string|max:80',
			'valido' => 'nullable|string|max:1',
			'tipo_ingreso_text' => 'nullable|string|max:150',
			'cod_tipo_ingreso' => 'nullable|string|max:40',
			'tipo_pago' => ['nullable', 'string', 'max:255', Rule::exists('formas_cobro', 'id_forma_cobro')],
			'observacion' => 'nullable|string',
			'factura_recibo' => 'nullable|string|max:1',
			'computarizada' => 'nullable',
			'cta_banco' => 'nullable|string|max:120',
			'nro_deposito' => 'nullable|string|max:80',
			'fecha_deposito' => 'nullable|string',
			'fecha_ini' => 'nullable|string',
			'fecha_fin' => 'nullable|string',
			'nro_orden' => 'nullable|numeric',
			'concepto_alq' => 'nullable|string|max:100',
		]);

		$gestion = $request->string('gestion')->toString();
		$codPensum = $request->string('cod_pensum')->toString();
		$aut = trim((string) $request->input('autorizacion', ''));
		if (!$this->service->autorizacionPermitidaParaGestion($gestion, $aut, $codPensum)) {
			return response()->json([
				'message' => 'La autorización no corresponde a una directiva activa para la gestión seleccionada.',
			], 422);
		}

		$payload = $this->service->registrar($request->all(), $user);
		return response()->json($payload);
	}

	/**
	 * Sirve el PDF generado en `storage/app/public/notas-otros-ingresos/` (enlace firmado; válido minutos).
	 * Evita 403 por acceso directo a `/storage/...` cuando nginx/Apache bloquean symlinks o permisos.
	 */
	public function descargarNotaPdf(Request $request, string $filename): BinaryFileResponse
	{
		if (str_contains($filename, '..') || !preg_match('/^[A-Za-z0-9_\-\.]+\.pdf$/', $filename)) {
			abort(404);
		}
		$path = 'notas-otros-ingresos/'.$filename;
		if (!Storage::disk('public')->exists($path)) {
			abort(404);
		}

		$fullPath = Storage::disk('public')->path($path);

		return response()->file($fullPath, [
			'Content-Type' => 'application/pdf',
			'Content-Disposition' => 'inline; filename="'.$filename.'"',
		]);
	}
}
