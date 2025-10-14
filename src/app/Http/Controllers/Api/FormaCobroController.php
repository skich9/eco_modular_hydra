<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FormaCobro;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Response;

class FormaCobroController extends Controller
{
	public function index()
	{
		try {
			// Unir con tabla SIN para obtener la descripcion oficial (descripcion_sin)
			$formas = DB::table('formas_cobro as f')
				->leftJoin('sin_forma_cobro as s', 's.id_forma_cobro', '=', 'f.id_forma_cobro')
				->select(
					'f.id_forma_cobro',
					'f.nombre',           // se mantiene para lógica existente (comparaciones "EFECTIVO", etc.)
					'f.estado as activo',  // por compatibilidad si el modelo lo expone como 'estado'
					's.descripcion_sin',
					's.codigo_sin'
				)
				->orderBy('f.nombre', 'asc')
				->get();
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
