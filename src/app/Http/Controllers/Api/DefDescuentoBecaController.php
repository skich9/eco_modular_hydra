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
			$items = DefDescuentoBeca::where('beca', true)->get();
			return response()->json(['success' => true, 'data' => $items]);
		} catch (\Exception $e) {
			return response()->json(['success' => false, 'message' => 'Error al obtener definiciones de becas: ' . $e->getMessage()], 500);
		}
	}

	public function store(Request $request)
	{
		try {
			$validator = Validator::make($request->all(), [
				'nombre_beca' => 'required|string|max:255|unique:def_descuentos_beca,nombre_beca,NULL,cod_beca,beca,1',
				'descripcion' => 'nullable|string',
				'monto' => 'required|integer',
				'porcentaje' => 'required|boolean',
				'estado' => 'required|boolean',
				'd_i' => 'nullable|boolean',
				'beca' => 'nullable|boolean',
			]);

			if ($validator->fails()) {
				return response()->json(['success' => false, 'message' => 'Error de validación', 'errors' => $validator->errors()], 422);
			}

			$payload = [
				'nombre_beca' => $request->input('nombre_beca'),
				'descripcion' => $request->input('descripcion'),
				'monto' => (int) $request->input('monto'),
				'porcentaje' => (bool) $request->input('porcentaje'),
				'estado' => (bool) $request->input('estado'),
				'd_i' => $request->input('d_i', null),
				'beca' => true,
			];
			$item = DefDescuentoBeca::create($payload);
			return response()->json(['success' => true, 'message' => 'Definición de beca creada correctamente', 'data' => $item], 201);
		} catch (\Exception $e) {
			return response()->json(['success' => false, 'message' => 'Error al crear definición de beca: ' . $e->getMessage()], 500);
		}
	}

	public function show($id)
	{
		try {
			$item = DefDescuentoBeca::where('cod_beca', $id)->where('beca', true)->first();
			if (!$item) return response()->json(['success' => false, 'message' => 'Definición no encontrada'], 404);
			return response()->json(['success' => true, 'data' => $item]);
		} catch (\Exception $e) {
			return response()->json(['success' => false, 'message' => 'Error al obtener definición: ' . $e->getMessage()], 500);
		}
	}

	public function update(Request $request, $id)
	{
		try {
			$item = DefDescuentoBeca::where('cod_beca', $id)->where('beca', true)->first();
			if (!$item) return response()->json(['success' => false, 'message' => 'Definición no encontrada'], 404);

			$validator = Validator::make($request->all(), [
				'nombre_beca' => 'required|string|max:255|unique:def_descuentos_beca,nombre_beca,' . $id . ',cod_beca,beca,1',
				'descripcion' => 'nullable|string',
				'monto' => 'required|integer',
				'porcentaje' => 'required|boolean',
				'estado' => 'required|boolean',
				'd_i' => 'nullable|boolean',
				'beca' => 'nullable|boolean',
			]);

			if ($validator->fails()) {
				return response()->json(['success' => false, 'message' => 'Error de validación', 'errors' => $validator->errors()], 422);
			}

			$payload = [
				'nombre_beca' => $request->input('nombre_beca'),
				'descripcion' => $request->input('descripcion'),
				'monto' => (int) $request->input('monto'),
				'porcentaje' => (bool) $request->input('porcentaje'),
				'estado' => (bool) $request->input('estado'),
				'd_i' => $request->input('d_i', $item->d_i),
				// mantener como beca
				'beca' => true,
			];
			$item->update($payload);
			return response()->json(['success' => true, 'message' => 'Definición de beca actualizada correctamente', 'data' => $item]);
		} catch (\Exception $e) {
			return response()->json(['success' => false, 'message' => 'Error al actualizar definición de beca: ' . $e->getMessage()], 500);
		}
	}

	public function destroy($id)
	{
		try {
			$item = DefDescuentoBeca::where('cod_beca', $id)->where('beca', true)->first();
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
			$item = DefDescuentoBeca::where('cod_beca', $id)->where('beca', true)->first();
			if (!$item) return response()->json(['success' => false, 'message' => 'Definición no encontrada'], 404);
			$item->estado = !$item->estado;
			$item->save();
			return response()->json(['success' => true, 'message' => 'Estado actualizado correctamente', 'data' => $item]);
		} catch (\Exception $e) {
			return response()->json(['success' => false, 'message' => 'Error al cambiar estado: ' . $e->getMessage()], 500);
		}
	}
}
