<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DefDescuento;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DefDescuentoController extends Controller
{
	public function index()
	{
		try {
			$items = DefDescuento::all();
			return response()->json(['success' => true, 'data' => $items]);
		} catch (\Exception $e) {
			return response()->json(['success' => false, 'message' => 'Error al obtener definiciones de descuentos: ' . $e->getMessage()], 500);
		}
	}

	public function store(Request $request)
	{
		try {
			$validator = Validator::make($request->all(), [
				'nombre_descuento' => 'required|string|max:255|unique:def_descuentos,nombre_descuento',
				'descripcion' => 'nullable|string',
				'monto' => 'required|integer',
				'porcentaje' => 'required|boolean',
				'estado' => 'required|boolean',
			]);

			if ($validator->fails()) {
				return response()->json(['success' => false, 'message' => 'Error de validación', 'errors' => $validator->errors()], 422);
			}

			$item = DefDescuento::create($request->all());
			return response()->json(['success' => true, 'message' => 'Definición de descuento creada correctamente', 'data' => $item], 201);
		} catch (\Exception $e) {
			return response()->json(['success' => false, 'message' => 'Error al crear definición de descuento: ' . $e->getMessage()], 500);
		}
	}

	public function show($id)
	{
		try {
			$item = DefDescuento::find($id);
			if (!$item) return response()->json(['success' => false, 'message' => 'Definición no encontrada'], 404);
			return response()->json(['success' => true, 'data' => $item]);
		} catch (\Exception $e) {
			return response()->json(['success' => false, 'message' => 'Error al obtener definición: ' . $e->getMessage()], 500);
		}
	}

	public function update(Request $request, $id)
	{
		try {
			$item = DefDescuento::find($id);
			if (!$item) return response()->json(['success' => false, 'message' => 'Definición no encontrada'], 404);

			$validator = Validator::make($request->all(), [
				'nombre_descuento' => 'required|string|max:255|unique:def_descuentos,nombre_descuento,' . $id . ',cod_descuento',
				'descripcion' => 'nullable|string',
				'monto' => 'required|integer',
				'porcentaje' => 'required|boolean',
				'estado' => 'required|boolean',
			]);

			if ($validator->fails()) {
				return response()->json(['success' => false, 'message' => 'Error de validación', 'errors' => $validator->errors()], 422);
			}

			$item->update($request->all());
			return response()->json(['success' => true, 'message' => 'Definición de descuento actualizada correctamente', 'data' => $item]);
		} catch (\Exception $e) {
			return response()->json(['success' => false, 'message' => 'Error al actualizar definición de descuento: ' . $e->getMessage()], 500);
		}
	}

	public function destroy($id)
	{
		try {
			$item = DefDescuento::find($id);
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
			$item = DefDescuento::find($id);
			if (!$item) return response()->json(['success' => false, 'message' => 'Definición no encontrada'], 404);
			$item->estado = !$item->estado;
			$item->save();
			return response()->json(['success' => true, 'message' => 'Estado actualizado correctamente', 'data' => $item]);
		} catch (\Exception $e) {
			return response()->json(['success' => false, 'message' => 'Error al cambiar estado: ' . $e->getMessage()], 500);
		}
	}
}
