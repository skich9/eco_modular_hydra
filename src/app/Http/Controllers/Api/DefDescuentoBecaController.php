<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DefDescuentoBeca;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DefDescuentoBecaController extends Controller
{
	public function index()
	{
		try {
			$items = DefDescuentoBeca::all();
			return response()->json(['success' => true, 'data' => $items]);
		} catch (\Exception $e) {
			return response()->json(['success' => false, 'message' => 'Error al obtener definiciones de becas: ' . $e->getMessage()], 500);
		}
	}

	public function store(Request $request)
	{
		try {
			$validator = Validator::make($request->all(), [
				'nombre_beca' => 'required|string|max:255|unique:def_descuentos_beca,nombre_beca',
				'descripcion' => 'nullable|string',
				'monto' => 'required|integer',
				'porcentaje' => 'required|boolean',
				'estado' => 'required|boolean',
			]);

			if ($validator->fails()) {
				return response()->json(['success' => false, 'message' => 'Error de validación', 'errors' => $validator->errors()], 422);
			}

			$item = DefDescuentoBeca::create($request->all());
			return response()->json(['success' => true, 'message' => 'Definición de beca creada correctamente', 'data' => $item], 201);
		} catch (\Exception $e) {
			return response()->json(['success' => false, 'message' => 'Error al crear definición de beca: ' . $e->getMessage()], 500);
		}
	}

	public function show($id)
	{
		try {
			$item = DefDescuentoBeca::find($id);
			if (!$item) return response()->json(['success' => false, 'message' => 'Definición no encontrada'], 404);
			return response()->json(['success' => true, 'data' => $item]);
		} catch (\Exception $e) {
			return response()->json(['success' => false, 'message' => 'Error al obtener definición: ' . $e->getMessage()], 500);
		}
	}

	public function update(Request $request, $id)
	{
		try {
			$item = DefDescuentoBeca::find($id);
			if (!$item) return response()->json(['success' => false, 'message' => 'Definición no encontrada'], 404);

			$validator = Validator::make($request->all(), [
				'nombre_beca' => 'required|string|max:255|unique:def_descuentos_beca,nombre_beca,' . $id . ',cod_beca',
				'descripcion' => 'nullable|string',
				'monto' => 'required|integer',
				'porcentaje' => 'required|boolean',
				'estado' => 'required|boolean',
			]);

			if ($validator->fails()) {
				return response()->json(['success' => false, 'message' => 'Error de validación', 'errors' => $validator->errors()], 422);
			}

			$item->update($request->all());
			return response()->json(['success' => true, 'message' => 'Definición de beca actualizada correctamente', 'data' => $item]);
		} catch (\Exception $e) {
			return response()->json(['success' => false, 'message' => 'Error al actualizar definición de beca: ' . $e->getMessage()], 500);
		}
	}

	public function destroy($id)
	{
		try {
			$item = DefDescuentoBeca::find($id);
			if (!$item) return response()->json(['success' => false, 'message' => 'Definición no encontrada'], 404);

			try {
				$item->delete();
			} catch (\Illuminate\Database\QueryException $qe) {
				return response()->json(['success' => false, 'message' => 'No se puede eliminar: tiene registros relacionados.'], 409);
			}

			return response()->json(['success' => true, 'message' => 'Definición eliminada correctamente']);
		} catch (\Exception $e) {
			return response()->json(['success' => false, 'message' => 'Error al eliminar definición: ' . $e->getMessage()], 500);
		}
	}

	public function toggleStatus($id)
	{
		try {
			$item = DefDescuentoBeca::find($id);
			if (!$item) return response()->json(['success' => false, 'message' => 'Definición no encontrada'], 404);
			$item->estado = !$item->estado;
			$item->save();
			return response()->json(['success' => true, 'message' => 'Estado actualizado correctamente', 'data' => $item]);
		} catch (\Exception $e) {
			return response()->json(['success' => false, 'message' => 'Error al cambiar estado: ' . $e->getMessage()], 500);
		}
	}
}
