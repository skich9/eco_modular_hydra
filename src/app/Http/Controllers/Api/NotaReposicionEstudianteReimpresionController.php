<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Economico\NotaReposicionEstudianteReimpresionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class NotaReposicionEstudianteReimpresionController extends Controller
{
	public function __construct(
		private NotaReposicionEstudianteReimpresionService $service
	) {}

	public function buscarPorDocumento(Request $request)
	{
		$v = Validator::make($request->all(), [
			'nro_nota_deposito' => 'required|string|min:1|max:32',
		]);
		if ($v->fails()) {
			return response()->json(['success' => false, 'message' => 'Validación', 'errors' => $v->errors()], 422);
		}
		$raw = trim((string) $request->input('nro_nota_deposito'));
		$doc = strtoupper(preg_replace('/\s+/u', '', $raw) ?? '');
		if (strlen($doc) !== 8) {
			return response()->json([
				'success' => false,
				'message' => 'El número ingresado debe contener 8 caracteres (prefijo + año 2 dígitos + correlativo 5).',
			], 422);
		}
		$data = $this->service->listarPorDocumento($doc);

		return response()->json(['success' => true, 'data' => $data]);
	}

	public function buscarPorFecha(Request $request)
	{
		$v = Validator::make($request->all(), [
			'fecha_ini' => 'required|string',
			'fecha_fin' => 'required|string',
		]);
		if ($v->fails()) {
			return response()->json(['success' => false, 'message' => 'Validación', 'errors' => $v->errors()], 422);
		}
		try {
			$data = $this->service->listarPorFechaDmY(
				(string) $request->input('fecha_ini'),
				(string) $request->input('fecha_fin')
			);
		} catch (\Throwable $e) {
			return response()->json(['success' => false, 'message' => 'Fechas inválidas (use dd/mm/yyyy).'], 422);
		}

		return response()->json(['success' => true, 'data' => $data]);
	}

	public function buscarPorCodCeta(Request $request)
	{
		$v = Validator::make($request->all(), [
			'cod_ceta' => 'required|integer',
		]);
		if ($v->fails()) {
			return response()->json(['success' => false, 'message' => 'Validación', 'errors' => $v->errors()], 422);
		}
		$data = $this->service->listarPorCodCeta((int) $request->input('cod_ceta'));

		return response()->json(['success' => true, 'data' => $data]);
	}

	public function generarPdf(Request $request)
	{
		$v = Validator::make($request->all(), [
			'num_doc' => 'required|string|min:1|max:32',
			'cont' => 'required|integer',
		]);
		if ($v->fails()) {
			return response()->json(['success' => false, 'message' => 'Validación', 'errors' => $v->errors()], 422);
		}
		$doc = strtoupper(preg_replace('/\s+/u', '', (string) $request->input('num_doc')) ?? '');
		$cont = (int) $request->input('cont');
		try {
			$bin = $this->service->generarPdf($doc, $cont);
		} catch (\InvalidArgumentException $e) {
			return response()->json(['success' => false, 'message' => $e->getMessage()], 404);
		} catch (\Throwable $e) {
			return response()->json(['success' => false, 'message' => 'Error al generar PDF: '.$e->getMessage()], 500);
		}

		$name = 'nota_reposicion_'.$doc.'_'.$cont.'.pdf';

		return response($bin, 200, [
			'Content-Type' => 'application/pdf',
			'Content-Disposition' => 'attachment; filename="'.$name.'"',
		]);
	}
}
