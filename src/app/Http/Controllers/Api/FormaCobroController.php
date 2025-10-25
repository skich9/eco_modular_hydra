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
			$formas = DB::table('sin_forma_cobro as s')
				->leftJoin('formas_cobro as f', 'f.id_forma_cobro', '=', 's.id_forma_cobro')
				->select(
					's.id_forma_cobro',
					'f.nombre',
					's.activo as activo',
					's.descripcion_sin',
					's.codigo_sin'
				)
				->where(function($q){
					$q->where('s.activo', 1)
						->orWhere('s.activo', true)
						->orWhere('s.activo', '1');
				})
				->whereNotNull('s.codigo_sin')
				->orderBy('s.codigo_sin', 'asc')
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
