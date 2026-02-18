<?php

namespace App\Http\Controllers;

use App\Models\DatosMoraDetalle;
use App\Models\DatosMora;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class DatosMoraDetalleController extends Controller
{
	/**
	 * Display a listing of the resource.
	 */
	public function index(): JsonResponse
	{
		try {
			$detalles = DatosMoraDetalle::with(['datosMora', 'pensum'])
				->orderBy('semestre', 'asc')
				->get();

			return response()->json([
				'success' => true,
				'data' => $detalles,
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
			if (isset($input['semestre']) && is_string($input['semestre'])) {
				$input['semestre'] = trim($input['semestre']);
			}

			if (array_key_exists('estado', $input) && !array_key_exists('activo', $input)) {
				$input['activo'] = filter_var($input['estado'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
				unset($input['estado']);
			}

			// Validar que monto sea null si está vacío
			if (array_key_exists('monto', $input) && $input['monto'] === '') {
				$input['monto'] = null;
			}

			// Crear o buscar DatosMora por gestión
			if (isset($input['gestion'])) {
				$datosMora = DatosMora::firstOrCreate(
					['gestion' => $input['gestion']],
					[
						'tipo_calculo' => $input['tipo_calculo'] ?? 'MONTO_FIJO',
						'monto' => $input['monto'],
						'activo' => true
					]
				);
				$input['id_datos_mora'] = $datosMora->id_datos_mora;
			}

			$validator = Validator::make($input, [
				'id_datos_mora' => 'required|exists:datos_mora,id_datos_mora',
				'cuota' => 'required|integer|min:1|max:5',
				'semestre' => 'required|string|max:30',
				'cod_pensum' => 'nullable|string|max:50|exists:pensums,cod_pensum',
				'monto' => 'nullable|numeric|min:0',
				'fecha_inicio' => 'required|date',
				'fecha_fin' => 'nullable|date|after_or_equal:fecha_inicio',
				'activo' => 'nullable|boolean'
			], [
				'id_datos_mora.required' => 'La gestión es requerida.',
				'id_datos_mora.exists' => 'La gestión no existe.',
				'cuota.required' => 'La cuota es requerida.',
				'cuota.integer' => 'La cuota debe ser un número entero.',
				'cuota.min' => 'La cuota debe ser al menos 1.',
				'cuota.max' => 'La cuota no puede ser mayor a 5.',
				'cod_pensum.exists' => 'El pensum no existe.',
				'semestre.required' => 'El semestre es requerido.',
				'semestre.string' => 'El semestre debe ser un texto.',
				'semestre.max' => 'El semestre no puede exceder 30 caracteres.',
				'monto.numeric' => 'El monto debe ser un número.',
				'monto.min' => 'El monto debe ser mayor o igual a 0.',
				'fecha_inicio.required' => 'La fecha de inicio es requerida.',
				'fecha_inicio.date' => 'La fecha de inicio debe ser una fecha válida.',
				'fecha_fin.date' => 'La fecha fin debe ser una fecha válida.',
				'fecha_fin.after_or_equal' => 'La fecha fin debe ser mayor o igual a la fecha inicio.',
				'activo.boolean' => 'El campo Activo debe ser verdadero o falso.'
			]);

			if ($validator->fails()) {
				return response()->json([
					'success' => false,
					'message' => 'Error de validación',
					'errors' => $validator->errors()
				], Response::HTTP_UNPROCESSABLE_ENTITY);
			}

			// Desactivar configuraciones anteriores que se solapen
			// Buscar configuraciones activas con la misma gestión, pensum, cuota y semestre
			DatosMoraDetalle::where('id_datos_mora', $input['id_datos_mora'])
				->where('cod_pensum', $input['cod_pensum'])
				->where('cuota', $input['cuota'])
				->where('semestre', $input['semestre'])
				->where('activo', true)
				->update(['activo' => false]);

			$detalle = DatosMoraDetalle::create($input);
			$detalle->load(['datosMora', 'pensum']);

			return response()->json([
				'success' => true,
				'data' => $detalle,
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
			$detalle = DatosMoraDetalle::with(['datosMora', 'pensum'])->findOrFail($id);

			return response()->json([
				'success' => true,
				'data' => $detalle,
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
			$detalle = DatosMoraDetalle::findOrFail($id);
			$input = $request->all();

			// Normalizar campos
			if (isset($input['semestre']) && is_string($input['semestre'])) {
				$input['semestre'] = trim($input['semestre']);
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
				'cuota' => 'required|integer|min:1|max:5',
				'semestre' => 'required|string|max:30',
				'cod_pensum' => 'nullable|string|max:50|exists:pensums,cod_pensum',
				'monto' => 'nullable|numeric|min:0',
				'fecha_inicio' => 'required|date',
				'fecha_fin' => 'nullable|date|after_or_equal:fecha_inicio',
				'activo' => 'nullable|boolean'
			], [
				'cuota.required' => 'La cuota es requerida.',
				'cuota.integer' => 'La cuota debe ser un número entero.',
				'cuota.min' => 'La cuota debe ser al menos 1.',
				'cuota.max' => 'La cuota no puede ser mayor a 5.',
				'cod_pensum.exists' => 'El pensum no existe.',
				'semestre.required' => 'El semestre es requerido.',
				'semestre.string' => 'El semestre debe ser un texto.',
				'semestre.max' => 'El semestre no puede exceder 30 caracteres.',
				'monto.numeric' => 'El monto debe ser un número.',
				'monto.min' => 'El monto debe ser mayor o igual a 0.',
				'fecha_inicio.required' => 'La fecha de inicio es requerida.',
				'fecha_inicio.date' => 'La fecha de inicio debe ser una fecha válida.',
				'fecha_fin.date' => 'La fecha fin debe ser una fecha válida.',
				'fecha_fin.after_or_equal' => 'La fecha fin debe ser mayor o igual a la fecha inicio.',
				'activo.boolean' => 'El campo Activo debe ser verdadero o falso.'
			]);

			if ($validator->fails()) {
				return response()->json([
					'success' => false,
					'message' => 'Error de validación',
					'errors' => $validator->errors()
				], Response::HTTP_UNPROCESSABLE_ENTITY);
			}

			$detalle->update($input);
			$detalle->load(['datosMora', 'pensum']);

			return response()->json([
				'success' => true,
				'data' => $detalle,
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
	 * Toggle the status of the specified resource.
	 */
	public function toggleStatus(string $id): JsonResponse
	{
		try {
			$detalle = DatosMoraDetalle::findOrFail($id);
			$detalle->activo = !$detalle->activo;
			$detalle->save();
			$detalle->load(['datosMora', 'pensum']);

			return response()->json([
				'success' => true,
				'data' => $detalle,
				'message' => 'Estado actualizado exitosamente'
			]);
		} catch (\Exception $e) {
			return response()->json([
				'success' => false,
				'message' => 'Error al cambiar el estado: ' . $e->getMessage()
			], Response::HTTP_INTERNAL_SERVER_ERROR);
		}
	}
}
