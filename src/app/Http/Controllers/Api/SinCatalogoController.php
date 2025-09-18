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
}
