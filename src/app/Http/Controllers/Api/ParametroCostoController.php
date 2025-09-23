<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ParametroCosto;
use Illuminate\Http\Request;

class ParametroCostoController extends Controller
{
	public function index(Request $request)
	{
		$items = ParametroCosto::query()
			->orderBy('nombre_oficial')
			->get(['id_parametro_costo','nombre_costo','nombre_oficial','descripcion','activo']);

		return response()->json([
			'success' => true,
			'data' => $items,
		]);
	}

	public function store(Request $request)
	{
		$data = $request->validate([
			'nombre_costo'   => ['required','string','max:255'],
			'nombre_oficial' => ['required','string','max:255'],
			'descripcion'    => ['nullable','string'],
			'activo'         => ['required','boolean'],
		]);

		$item = new ParametroCosto();
		$item->nombre_costo = $data['nombre_costo'];
		$item->nombre_oficial = $data['nombre_oficial'];
		$item->descripcion = $data['descripcion'] ?? null;
		$item->activo = (bool) $data['activo'];
		$item->save();

		return response()->json([
			'success' => true,
			'data' => $item->only(['id_parametro_costo','nombre_costo','nombre_oficial','descripcion','activo'])
		]);
	}

	public function activos(Request $request)
	{
		$items = ParametroCosto::query()
			->where('activo', true)
			->orderBy('nombre_oficial')
			->get(['id_parametro_costo','nombre_costo','nombre_oficial','descripcion','activo']);

		return response()->json([
			'success' => true,
			'data' => $items,
		]);
	}

	public function update(Request $request, $id)
	{
		$data = $request->validate([
			'nombre_costo'   => ['nullable','string','max:255'],
			'nombre_oficial' => ['nullable','string','max:255'],
			'descripcion'    => ['nullable','string'],
			'activo'         => ['nullable','boolean'],
		]);

		$item = ParametroCosto::query()->findOrFail($id);
		$item->fill($data);
		$item->save();

		return response()->json([
			'success' => true,
			'data' => $item->only(['id_parametro_costo','nombre_costo','nombre_oficial','descripcion','activo'])
		]);
	}
}
