<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ParametroCosto;
use Illuminate\Http\Request;

class ParametroCostoController extends Controller
{
	public function activos(Request $request)
	{
		$items = ParametroCosto::query()
			->where('activo', true)
			->orderBy('nombre_oficial')
			->get(['id_parametro_costo','nombre_costo','nombre_oficial','descripcion','activo','created_at','updated_at']);

		return response()->json([
			'success' => true,
			'data' => $items,
		]);
	}
}
