<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ParametroGeneral;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;

class ParametroGeneralController extends Controller
{
	/**
	 * Listar parámetros generales
	 */
	public function index()
	{
		try {
			$items = ParametroGeneral::orderBy('nombre')->get();
			return response()->json([
				'success' => true,
				'data' => $items,
				'message' => 'Lista de parámetros generales obtenida exitosamente'
			]);
		} catch (\Exception $e) {
			return response()->json([
				'success' => false,
				'message' => 'Error al obtener parámetros generales: ' . $e->getMessage()
			], Response::HTTP_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Crear parámetro general
	 */
	public function store(Request $request)
	{
		try {
			$validator = Validator::make($request->all(), [
				'nombre' => 'required|string|max:150',
				'valor' => 'nullable|string|max:255',
				'estado' => 'required|boolean',
			]);

			if ($validator->fails()) {
				return response()->json([
					'success' => false,
					'errors' => $validator->errors(),
					'message' => 'Error de validación'
				], Response::HTTP_UNPROCESSABLE_ENTITY);
			}

			$item = ParametroGeneral::create($validator->validated());

			return response()->json([
				'success' => true,
				'data' => $item,
				'message' => 'Parámetro general creado exitosamente'
			], Response::HTTP_CREATED);
		} catch (\Exception $e) {
			return response()->json([
				'success' => false,
				'message' => 'Error al crear parámetro general: ' . $e->getMessage()
			], Response::HTTP_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Mostrar parámetro general
	 */
	public function show($id)
	{
		try {
			$item = ParametroGeneral::find($id);
			if (!$item) {
				return response()->json([
					'success' => false,
					'message' => 'Parámetro general no encontrado'
				], Response::HTTP_NOT_FOUND);
			}

			return response()->json([
				'success' => true,
				'data' => $item
			]);
		} catch (\Exception $e) {
			return response()->json([
				'success' => false,
				'message' => 'Error al obtener parámetro general: ' . $e->getMessage()
			], Response::HTTP_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Actualizar parámetro general
	 */
	public function update(Request $request, $id)
	{
		try {
			$item = ParametroGeneral::find($id);
			if (!$item) {
				return response()->json([
					'success' => false,
					'message' => 'Parámetro general no encontrado'
				], Response::HTTP_NOT_FOUND);
			}

			$validator = Validator::make($request->all(), [
				'nombre' => 'required|string|max:150',
				'valor' => 'nullable|string|max:255',
				'estado' => 'required|boolean',
			]);

			if ($validator->fails()) {
				return response()->json([
					'success' => false,
					'errors' => $validator->errors(),
					'message' => 'Error de validación'
				], Response::HTTP_UNPROCESSABLE_ENTITY);
			}

			$item->update($validator->validated());

			return response()->json([
				'success' => true,
				'data' => $item,
				'message' => 'Parámetro general actualizado exitosamente'
			]);
		} catch (\Exception $e) {
			return response()->json([
				'success' => false,
				'message' => 'Error al actualizar parámetro general: ' . $e->getMessage()
			], Response::HTTP_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Eliminar parámetro general
	 */
	public function destroy($id)
	{
		try {
			$item = ParametroGeneral::find($id);
			if (!$item) {
				return response()->json([
					'success' => false,
					'message' => 'Parámetro general no encontrado'
				], Response::HTTP_NOT_FOUND);
			}

			$item->delete();

			return response()->json([
				'success' => true,
				'message' => 'Parámetro general eliminado exitosamente'
			]);
		} catch (\Exception $e) {
			return response()->json([
				'success' => false,
				'message' => 'Error al eliminar parámetro general: ' . $e->getMessage()
			], Response::HTTP_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Toggle estado
	 */
	public function toggleStatus($id)
	{
		try {
			$item = ParametroGeneral::find($id);
			if (!$item) {
				return response()->json([
					'success' => false,
					'message' => 'Parámetro general no encontrado'
				], Response::HTTP_NOT_FOUND);
			}

			$item->estado = !$item->estado;
			$item->save();

			return response()->json([
				'success' => true,
				'data' => $item,
				'message' => 'Estado actualizado correctamente'
			]);
		} catch (\Exception $e) {
			return response()->json([
				'success' => false,
				'message' => 'Error al actualizar estado: ' . $e->getMessage()
			], Response::HTTP_INTERNAL_SERVER_ERROR);
		}
	}
}
