<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Funcion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class FuncionController extends Controller
{
	public function index(Request $request)
	{
		$query = Funcion::query();

		if ($request->has('activo')) {
			$activoParam = $request->input('activo');

			\Log::info('Api\FuncionController@index - Parámetro activo:', [
				'valor_recibido' => $activoParam,
				'tipo' => gettype($activoParam)
			]);

			$activoValue = null;
			if (in_array($activoParam, ['true', '1', 1, true], true)) {
				$activoValue = 1;
			} elseif (in_array($activoParam, ['false', '0', 0, false], true)) {
				$activoValue = 0;
			}

			if ($activoValue !== null) {
				\Log::info('Api\FuncionController@index - Aplicando filtro:', ['activo' => $activoValue]);
				$query->where('activo', $activoValue);
			} else {
				\Log::warning('Api\FuncionController@index - Valor no reconocido:', ['valor' => $activoParam]);
			}
		}

		if ($request->has('modulo')) {
			$query->where('modulo', $request->modulo);
		}

		$funciones = $query->orderBy('modulo')->orderBy('nombre')->get();

		\Log::info('Api\FuncionController@index - Resultado:', [
			'total' => $funciones->count(),
			'funciones' => $funciones->map(function($f) {
				return [
					'id' => $f->id_funcion,
					'codigo' => $f->codigo,
					'activo' => $f->activo
				];
			})->toArray()
		]);

		return response()->json([
			'success' => true,
			'data' => $funciones
		]);
	}

	public function byModule()
	{
		$funciones = Funcion::activas()->get();

		$grouped = $funciones->groupBy('modulo')->map(function ($items) {
			return $items->values();
		});

		return response()->json([
			'success' => true,
			'data' => $grouped
		]);
	}

	public function show($id)
	{
		$funcion = Funcion::find($id);

		if (!$funcion) {
			return response()->json([
				'success' => false,
				'message' => 'Función no encontrada'
			], 404);
		}

		return response()->json([
			'success' => true,
			'data' => $funcion
		]);
	}

	public function store(Request $request)
	{
		$validator = Validator::make($request->all(), [
			'codigo' => 'required|string|max:100|unique:funciones,codigo',
			'nombre' => 'required|string|max:100',
			'descripcion' => 'nullable|string',
			'modulo' => 'required|string|max:50',
			'icono' => 'nullable|string|max:50',
			'activo' => 'boolean'
		]);

		if ($validator->fails()) {
			return response()->json([
				'success' => false,
				'message' => 'Error de validación',
				'errors' => $validator->errors()
			], 422);
		}

		$funcion = Funcion::create($request->all());

		return response()->json([
			'success' => true,
			'message' => 'Función creada exitosamente',
			'data' => $funcion
		], 201);
	}

	public function update(Request $request, $id)
	{
		$funcion = Funcion::find($id);

		if (!$funcion) {
			return response()->json([
				'success' => false,
				'message' => 'Función no encontrada'
			], 404);
		}

		$validator = Validator::make($request->all(), [
			'codigo' => 'required|string|max:100|unique:funciones,codigo,' . $id . ',id_funcion',
			'nombre' => 'required|string|max:100',
			'descripcion' => 'nullable|string',
			'modulo' => 'required|string|max:50',
			'icono' => 'nullable|string|max:50',
			'activo' => 'boolean'
		]);

		if ($validator->fails()) {
			return response()->json([
				'success' => false,
				'message' => 'Error de validación',
				'errors' => $validator->errors()
			], 422);
		}

		$funcion->update($request->all());

		return response()->json([
			'success' => true,
			'message' => 'Función actualizada exitosamente',
			'data' => $funcion
		]);
	}

	public function destroy($id)
	{
		$funcion = Funcion::find($id);

		if (!$funcion) {
			return response()->json([
				'success' => false,
				'message' => 'Función no encontrada'
			], 404);
		}

		$funcion->update(['activo' => false]);

		return response()->json([
			'success' => true,
			'message' => 'Función desactivada exitosamente'
		]);
	}
}
