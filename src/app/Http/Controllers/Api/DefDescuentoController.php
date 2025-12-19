<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DefDescuentoBeca;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DefDescuentoController extends Controller
{
	public function index()
	{
		try {
			$rows = DefDescuentoBeca::where('beca', false)->get();
			$items = $rows->map(function($r){
				return [
					'cod_descuento' => (int) ($r->cod_beca ?? 0),
					'nombre_descuento' => (string) ($r->nombre_beca ?? ''),
					'descripcion' => $r->descripcion,
					'monto' => (int) ($r->monto ?? 0),
					'porcentaje' => (bool) ($r->porcentaje ?? false),
					'estado' => (bool) ($r->estado ?? false),
					'created_at' => $r->created_at,
					'updated_at' => $r->updated_at,
				];
			});
			return response()->json(['success' => true, 'data' => $items]);
		} catch (\Exception $e) {
			return response()->json(['success' => false, 'message' => 'Error al obtener definiciones de descuentos: ' . $e->getMessage()], 500);
		}
	}

	public function store(Request $request)
	{
		try {
			$validator = Validator::make($request->all(), [
				// unique among descuentos only (beca = 0)
				'nombre_descuento' => 'required|string|max:255|unique:def_descuentos_beca,nombre_beca,NULL,cod_beca,beca,0',
				'descripcion' => 'nullable|string',
				'monto' => 'required|integer',
				'porcentaje' => 'required|boolean',
				'estado' => 'required|boolean',
			]);

			if ($validator->fails()) {
				return response()->json(['success' => false, 'message' => 'Error de validación', 'errors' => $validator->errors()], 422);
			}

			$payload = [
				'nombre_beca' => $request->input('nombre_descuento'),
				'descripcion' => $request->input('descripcion'),
				'monto' => (int) $request->input('monto'),
				'porcentaje' => (bool) $request->input('porcentaje'),
				'estado' => (bool) $request->input('estado'),
				'beca' => false,
			];
			$row = DefDescuentoBeca::create($payload);
			$data = [
				'cod_descuento' => (int) ($row->cod_beca ?? 0),
				'nombre_descuento' => (string) ($row->nombre_beca ?? ''),
				'descripcion' => $row->descripcion,
				'monto' => (int) ($row->monto ?? 0),
				'porcentaje' => (bool) ($row->porcentaje ?? false),
				'estado' => (bool) ($row->estado ?? false),
				'created_at' => $row->created_at,
				'updated_at' => $row->updated_at,
			];
			return response()->json(['success' => true, 'message' => 'Definición de descuento creada correctamente', 'data' => $data], 201);
		} catch (\Exception $e) {
			return response()->json(['success' => false, 'message' => 'Error al crear definición de descuento: ' . $e->getMessage()], 500);
		}
	}

	public function show($id)
	{
		try {
			$row = DefDescuentoBeca::where('cod_beca', $id)->where('beca', false)->first();
			if (!$row) return response()->json(['success' => false, 'message' => 'Definición no encontrada'], 404);
			$data = [
				'cod_descuento' => (int) ($row->cod_beca ?? 0),
				'nombre_descuento' => (string) ($row->nombre_beca ?? ''),
				'descripcion' => $row->descripcion,
				'monto' => (int) ($row->monto ?? 0),
				'porcentaje' => (bool) ($row->porcentaje ?? false),
				'estado' => (bool) ($row->estado ?? false),
				'created_at' => $row->created_at,
				'updated_at' => $row->updated_at,
			];
			return response()->json(['success' => true, 'data' => $data]);
		} catch (\Exception $e) {
			return response()->json(['success' => false, 'message' => 'Error al obtener definición: ' . $e->getMessage()], 500);
		}
	}

	public function update(Request $request, $id)
	{
		try {
			$row = DefDescuentoBeca::where('cod_beca', $id)->where('beca', false)->first();
			if (!$row) return response()->json(['success' => false, 'message' => 'Definición no encontrada'], 404);

			$validator = Validator::make($request->all(), [
				// unique among descuentos only (beca = 0), ignore current cod_beca
				'nombre_descuento' => 'required|string|max:255|unique:def_descuentos_beca,nombre_beca,' . $id . ',cod_beca,beca,0',
				'descripcion' => 'nullable|string',
				'monto' => 'required|integer',
				'porcentaje' => 'required|boolean',
				'estado' => 'required|boolean',
			]);

			if ($validator->fails()) {
				return response()->json(['success' => false, 'message' => 'Error de validación', 'errors' => $validator->errors()], 422);
			}

			$payload = [
				'nombre_beca' => $request->input('nombre_descuento'),
				'descripcion' => $request->input('descripcion'),
				'monto' => (int) $request->input('monto'),
				'porcentaje' => (bool) $request->input('porcentaje'),
				'estado' => (bool) $request->input('estado'),
			];
			$row->update($payload);
			$data = [
				'cod_descuento' => (int) ($row->cod_beca ?? 0),
				'nombre_descuento' => (string) ($row->nombre_beca ?? ''),
				'descripcion' => $row->descripcion,
				'monto' => (int) ($row->monto ?? 0),
				'porcentaje' => (bool) ($row->porcentaje ?? false),
				'estado' => (bool) ($row->estado ?? false),
				'created_at' => $row->created_at,
				'updated_at' => $row->updated_at,
			];
			return response()->json(['success' => true, 'message' => 'Definición de descuento actualizada correctamente', 'data' => $data]);
		} catch (\Exception $e) {
			return response()->json(['success' => false, 'message' => 'Error al actualizar definición de descuento: ' . $e->getMessage()], 500);
		}
	}

	public function destroy($id)
	{
		try {
			$row = DefDescuentoBeca::where('cod_beca', $id)->where('beca', false)->first();
			if (!$row) return response()->json(['success' => false, 'message' => 'Definición no encontrada'], 404);

			try {
				$row->delete();
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
			$row = DefDescuentoBeca::where('cod_beca', $id)->where('beca', false)->first();
			if (!$row) return response()->json(['success' => false, 'message' => 'Definición no encontrada'], 404);
			$row->estado = !$row->estado;
			$row->save();
			$data = [
				'cod_descuento' => (int) ($row->cod_beca ?? 0),
				'nombre_descuento' => (string) ($row->nombre_beca ?? ''),
				'descripcion' => $row->descripcion,
				'monto' => (int) ($row->monto ?? 0),
				'porcentaje' => (bool) ($row->porcentaje ?? false),
				'estado' => (bool) ($row->estado ?? false),
				'created_at' => $row->created_at,
				'updated_at' => $row->updated_at,
			];
			return response()->json(['success' => true, 'message' => 'Estado actualizado correctamente', 'data' => $data]);
		} catch (\Exception $e) {
			return response()->json(['success' => false, 'message' => 'Error al cambiar estado: ' . $e->getMessage()], 500);
		}
	}
}
