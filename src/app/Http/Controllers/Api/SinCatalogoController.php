<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Response;

class SinCatalogoController extends Controller
{
	// Devuelve documentos de identidad desde sin_datos_sincronizacion
	public function documentosIdentidad()
	{
		try {
			$rows = DB::table('sin_datos_sincronizacion')
				->where('tipo', 'sincronizarParametricaTipoDocumentoIdentidad')
				->select('codigo_clasificador', 'descripcion')
				->orderBy('codigo_clasificador')
				->get();
			return response()->json([
				'success' => true,
				'data' => $rows,
				'message' => 'Documentos de identidad (SIN) obtenidos correctamente'
			]);
		} catch (\Throwable $e) {
			return response()->json([
				'success' => false,
				'message' => 'Error al obtener documentos de identidad (SIN): ' . $e->getMessage(),
			], Response::HTTP_INTERNAL_SERVER_ERROR);
		}
	}

	// Devuelve motivos de anulacion de factura
	public function motivosAnulacion()
	{
		try {
			$rows = DB::table('sin_motivo_anulacion_factura')
				->select('codigo_id as codigo', 'descripcion')
				->orderBy('codigo_id')
				->get();
			if (!$rows || count($rows) === 0) {
				// Fallback: leer desde sin_datos_sincronizacion si fue sincronizado allí
				$rows = DB::table('sin_datos_sincronizacion')
					->where('tipo', 'sincronizarParametricaMotivoAnulacion')
					->select('codigo_clasificador as codigo', 'descripcion')
					->orderBy('codigo_clasificador')
					->get();
			}
			return response()->json([
				'success' => true,
				'data' => $rows,
				'message' => 'Motivos de anulación (SIN) obtenidos correctamente'
			]);
		} catch (\Throwable $e) {
			return response()->json([
				'success' => false,
				'message' => 'Error al obtener motivos de anulación (SIN): ' . $e->getMessage(),
			], 500);
		}
	}
}
