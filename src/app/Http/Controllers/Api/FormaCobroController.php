<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FormaCobro;
use Illuminate\Http\Response;

class FormaCobroController extends Controller
{
	public function index()
	{
		try {
			$formas = FormaCobro::orderBy('nombre', 'asc')->get();
			return response()->json([
				'success' => true,
				'data' => $formas,
				'message' => 'Formas de cobro obtenidas correctamente'
			]);
		} catch (\Exception $e) {
			return response()->json([
				'success' => false,
				'message' => 'Error al obtener formas de cobro: ' . $e->getMessage()
			], Response::HTTP_INTERNAL_SERVER_ERROR);
		}
	}
}
