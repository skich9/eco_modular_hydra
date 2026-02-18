<?php

namespace App\Http\Controllers;

use App\Models\DatosMora;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class DatosMoraController extends Controller
{
	/**
	 * Display a listing of the resource.
	 */
	public function index(): JsonResponse
	{
		try {
			$configuraciones = DatosMora::orderBy('gestion', 'desc')->get();

			return response()->json([
				'success' => true,
				'data' => $configuraciones,
				'message' => 'Lista de configuraciones de mora obtenida exitosamente'
			]);
		} catch (\Exception $e) {
			return response()->json([
				'success' => false,
				'message' => 'Error al obtener las configuraciones de mora: ' . $e->getMessage()
			], Response::HTTP_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Store a newly created resource in storage.
	 */
	public function store(Request $request): JsonResponse
	{
		try {
			$input = $request->all();

			// Normalizar campos
			if (isset($input['gestion']) && is_string($input['gestion'])) {
				$input['gestion'] = trim($input['gestion']);
			}

			if (array_key_exists('estado', $input) && !array_key_exists('activo', $input)) {
				$input['activo'] = filter_var($input['estado'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
				unset($input['estado']);
			}

			// Validar que monto sea null si está vacío
			if (array_key_exists('monto', $input) && $input['monto'] === '') {
				$input['monto'] = null;
			}

			$validator = Validator::make($input, [
				'gestion' => 'required|string|max:30|unique:datos_mora,gestion',
				'tipo_calculo' => 'required|in:PORCENTAJE,MONTO_FIJO,AMBOS',
				'monto' => 'nullable|numeric|min:0',
				'activo' => 'nullable|boolean'
			], [
				'gestion.required' => 'El campo Gestión es requerido.',
				'gestion.string' => 'El campo Gestión debe ser un texto.',
				'gestion.max' => 'El campo Gestión no puede exceder 30 caracteres.',
				'gestion.unique' => 'Ya existe una configuración para esta gestión.',
				'tipo_calculo.required' => 'El tipo de cálculo es requerido.',
				'tipo_calculo.in' => 'El tipo de cálculo debe ser PORCENTAJE, MONTO_FIJO o AMBOS.',
				'monto.numeric' => 'El monto debe ser un número.',
				'monto.min' => 'El monto debe ser mayor o igual a 0.',
				'activo.boolean' => 'El campo Activo debe ser verdadero o falso.'
			]);

			if ($validator->fails()) {
				return response()->json([
					'success' => false,
					'message' => 'Error de validación',
					'errors' => $validator->errors()
				], Response::HTTP_UNPROCESSABLE_ENTITY);
			}

			$configuracion = DatosMora::create($input);

			return response()->json([
				'success' => true,
				'data' => $configuracion,
				'message' => 'Configuración de mora creada exitosamente'
			], Response::HTTP_CREATED);

		} catch (\Exception $e) {
			return response()->json([
				'success' => false,
				'message' => 'Error al crear la configuración de mora: ' . $e->getMessage()
			], Response::HTTP_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Display the specified resource.
	 */
	public function show(string $id): JsonResponse
	{
		try {
			$configuracion = DatosMora::findOrFail($id);

			return response()->json([
				'success' => true,
				'data' => $configuracion,
				'message' => 'Configuración de mora obtenida exitosamente'
			]);
		} catch (\Exception $e) {
			return response()->json([
				'success' => false,
				'message' => 'Configuración de mora no encontrada'
			], Response::HTTP_NOT_FOUND);
		}
	}

	/**
	 * Update the specified resource in storage.
	 */
	public function update(Request $request, string $id): JsonResponse
	{
		try {
			$configuracion = DatosMora::findOrFail($id);
			$input = $request->all();

			// Normalizar campos
			if (isset($input['gestion']) && is_string($input['gestion'])) {
				$input['gestion'] = trim($input['gestion']);
			}

			if (array_key_exists('estado', $input) && !array_key_exists('activo', $input)) {
				$input['activo'] = filter_var($input['estado'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
				unset($input['estado']);
			}

			// Validar que monto sea null si está vacío
			if (array_key_exists('monto', $input) && $input['monto'] === '') {
				$input['monto'] = null;
			}

			$validator = Validator::make($input, [
				'gestion' => 'required|string|max:30|unique:datos_mora,gestion,' . $id . ',id_datos_mora',
				'tipo_calculo' => 'required|in:PORCENTAJE,MONTO_FIJO,AMBOS',
				'monto' => 'nullable|numeric|min:0',
				'activo' => 'nullable|boolean'
			], [
				'gestion.required' => 'El campo Gestión es requerido.',
				'gestion.string' => 'El campo Gestión debe ser un texto.',
				'gestion.max' => 'El campo Gestión no puede exceder 30 caracteres.',
				'gestion.unique' => 'Ya existe una configuración para esta gestión.',
				'tipo_calculo.required' => 'El tipo de cálculo es requerido.',
				'tipo_calculo.in' => 'El tipo de cálculo debe ser PORCENTAJE, MONTO_FIJO o AMBOS.',
				'monto.numeric' => 'El monto debe ser un número.',
				'monto.min' => 'El monto debe ser mayor o igual a 0.',
				'activo.boolean' => 'El campo Activo debe ser verdadero o falso.'
			]);

			if ($validator->fails()) {
				return response()->json([
					'success' => false,
					'message' => 'Error de validación',
					'errors' => $validator->errors()
				], Response::HTTP_UNPROCESSABLE_ENTITY);
			}

			$configuracion->update($input);

			return response()->json([
				'success' => true,
				'data' => $configuracion,
				'message' => 'Configuración de mora actualizada exitosamente'
			]);

		} catch (\Exception $e) {
			return response()->json([
				'success' => false,
				'message' => 'Error al actualizar la configuración de mora: ' . $e->getMessage()
			], Response::HTTP_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Remove the specified resource from storage.
	 */
	public function destroy(string $id): JsonResponse
	{
		try {
			$configuracion = DatosMora::findOrFail($id);
			$configuracion->delete();

			return response()->json([
				'success' => true,
				'message' => 'Configuración de mora eliminada exitosamente'
			]);
		} catch (\Exception $e) {
			return response()->json([
				'success' => false,
				'message' => 'Error al eliminar la configuración de mora: ' . $e->getMessage()
			], Response::HTTP_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Toggle the status of the specified resource.
	 */
	public function toggleStatus(string $id): JsonResponse
	{
		try {
			$configuracion = DatosMora::findOrFail($id);
			$configuracion->activo = !$configuracion->activo;
			$configuracion->save();

			return response()->json([
				'success' => true,
				'data' => $configuracion,
				'message' => 'Estado actualizado exitosamente'
			]);
		} catch (\Exception $e) {
			return response()->json([
				'success' => false,
				'message' => 'Error al cambiar el estado: ' . $e->getMessage()
			], Response::HTTP_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Find or create DatosMora by gestion.
	 */
	public function findOrCreate(Request $request): JsonResponse
	{
		try {
			$validator = Validator::make($request->all(), [
				'gestion' => 'required|string|max:10'
			]);

			if ($validator->fails()) {
				return response()->json([
					'success' => false,
					'message' => 'Gestión requerida',
					'errors' => $validator->errors()
				], Response::HTTP_UNPROCESSABLE_ENTITY);
			}

			$gestion = trim($request->input('gestion'));

			// Buscar o crear el registro de datos_mora
			$datosMora = DatosMora::firstOrCreate(
				['gestion' => $gestion],
				[
					'tipo_calculo' => 'diario',
					'monto' => 0,
					'activo' => true
				]
			);

			return response()->json([
				'success' => true,
				'data' => $datosMora,
				'message' => 'Datos de mora obtenidos exitosamente'
			]);
		} catch (\Exception $e) {
			return response()->json([
				'success' => false,
				'message' => 'Error al buscar o crear datos de mora: ' . $e->getMessage()
			], Response::HTTP_INTERNAL_SERVER_ERROR);
		}
	}
}
