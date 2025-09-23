<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ParametroCosto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

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

	public function store(Request $request)
	{
		$validator = Validator::make($request->all(), [
			'nombre_costo'   => 'required|string|max:255|unique:parametros_costos,nombre_costo',
			'nombre_oficial' => 'required|string|max:255',
			'descripcion'    => 'nullable|string',
			'activo'         => 'required|boolean',
		]);

		if ($validator->fails()) {
			return response()->json([
				'success' => false,
				'message' => 'Error de validación',
				'errors'  => $validator->errors(),
			], 422);
		}

		$data = $validator->validated();
		$item = ParametroCosto::create($data);

		return response()->json([
			'success' => true,
			'message' => 'Parámetro de costo creado correctamente',
			'data'    => $item,
		], 201);
	}
}
