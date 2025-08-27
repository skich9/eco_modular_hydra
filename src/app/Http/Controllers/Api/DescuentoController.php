<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Descuento;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DescuentoController extends Controller
{
	public function index(Request $request)
	{
		try {
			$query = Descuento::query();
			if ($request->has('estado')) {
				$query->where('estado', filter_var($request->get('estado'), FILTER_VALIDATE_BOOLEAN));
			}
			if ($request->has('cod_pensum')) {
				$query->where('cod_pensum', $request->get('cod_pensum'));
			}
			if ($request->has('cod_ceta')) {
				$query->where('cod_ceta', $request->get('cod_ceta'));
			}
			$descuentos = $query->orderByDesc('id_descuentos')->get();
			return response()->json(['success' => true, 'data' => $descuentos]);
		} catch (\Exception $e) {
			return response()->json(['success' => false, 'message' => 'Error al obtener descuentos: ' . $e->getMessage()], 500);
		}
	}

	public function active()
	{
		try {
			$items = Descuento::where('estado', true)->get();
			return response()->json(['success' => true, 'data' => $items]);
		} catch (\Exception $e) {
			return response()->json(['success' => false, 'message' => 'Error al obtener descuentos activos: ' . $e->getMessage()], 500);
		}
	}

	public function store(Request $request)
	{
		try {
			$validator = Validator::make($request->all(), [
				'cod_ceta' => 'required|integer|exists:estudiantes,cod_ceta',
				'cod_pensum' => 'required|string|exists:pensums,cod_pensum',
				'cod_inscrip' => 'required|integer|exists:inscripciones,cod_inscrip',
				'cod_descuento' => 'nullable|integer|exists:def_descuentos,cod_descuento',
				'cod_beca' => 'nullable|integer|exists:def_descuentos_beca,cod_beca',
				'id_usuario' => 'required|integer|exists:usuarios,id_usuario',
				'nombre' => 'required|string|max:255',
				'observaciones' => 'nullable|string',
				'porcentaje' => 'required|numeric|min:0|max:100',
				'tipo' => 'nullable|string|max:100',
				'estado' => 'nullable|boolean',
			]);

			if ($validator->fails()) {
				return response()->json(['success' => false, 'message' => 'Error de validación', 'errors' => $validator->errors()], 422);
			}

			$descuento = Descuento::create($request->all());

			return response()->json([
				'success' => true,
				'message' => 'Descuento creado correctamente',
				'data' => $descuento
			], 201);
		} catch (\Exception $e) {
			return response()->json(['success' => false, 'message' => 'Error al crear descuento: ' . $e->getMessage()], 500);
		}
	}

	public function show($id)
	{
		try {
			$descuento = Descuento::find($id);
			if (!$descuento) {
				return response()->json(['success' => false, 'message' => 'Descuento no encontrado'], 404);
			}
			return response()->json(['success' => true, 'data' => $descuento]);
		} catch (\Exception $e) {
			return response()->json(['success' => false, 'message' => 'Error al obtener descuento: ' . $e->getMessage()], 500);
		}
	}

	public function update(Request $request, $id)
	{
		try {
			$descuento = Descuento::find($id);
			if (!$descuento) {
				return response()->json(['success' => false, 'message' => 'Descuento no encontrado'], 404);
			}

			$validator = Validator::make($request->all(), [
				'cod_ceta' => 'required|integer|exists:estudiantes,cod_ceta',
				'cod_pensum' => 'required|string|exists:pensums,cod_pensum',
				'cod_inscrip' => 'required|integer|exists:inscripciones,cod_inscrip',
				'cod_descuento' => 'nullable|integer|exists:def_descuentos,cod_descuento',
				'cod_beca' => 'nullable|integer|exists:def_descuentos_beca,cod_beca',
				'id_usuario' => 'required|integer|exists:usuarios,id_usuario',
				'nombre' => 'required|string|max:255',
				'observaciones' => 'nullable|string',
				'porcentaje' => 'required|numeric|min:0|max:100',
				'tipo' => 'nullable|string|max:100',
				'estado' => 'nullable|boolean',
			]);

			if ($validator->fails()) {
				return response()->json(['success' => false, 'message' => 'Error de validación', 'errors' => $validator->errors()], 422);
			}

			$descuento->update($request->all());

			return response()->json([
				'success' => true,
				'message' => 'Descuento actualizado correctamente',
				'data' => $descuento
			]);
		} catch (\Exception $e) {
			return response()->json(['success' => false, 'message' => 'Error al actualizar descuento: ' . $e->getMessage()], 500);
		}
	}

	public function destroy($id)
	{
		try {
			$descuento = Descuento::find($id);
			if (!$descuento) {
				return response()->json(['success' => false, 'message' => 'Descuento no encontrado'], 404);
			}

			$descuento->delete();

			return response()->json(['success' => true, 'message' => 'Descuento eliminado correctamente']);
		} catch (\Illuminate\Database\QueryException $qe) {
			return response()->json(['success' => false, 'message' => 'No se puede eliminar el descuento porque tiene registros relacionados.'], 409);
		} catch (\Exception $e) {
			return response()->json(['success' => false, 'message' => 'Error al eliminar descuento: ' . $e->getMessage()], 500);
		}
	}

	public function toggleStatus($id)
	{
		try {
			$descuento = Descuento::find($id);
			if (!$descuento) {
				return response()->json(['success' => false, 'message' => 'Descuento no encontrado'], 404);
			}

			$descuento->estado = !$descuento->estado;
			$descuento->save();

			return response()->json(['success' => true, 'message' => 'Estado actualizado correctamente', 'data' => $descuento]);
		} catch (\Exception $e) {
			return response()->json(['success' => false, 'message' => 'Error al cambiar estado: ' . $e->getMessage()], 500);
		}
	}
}
