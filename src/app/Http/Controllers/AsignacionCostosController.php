<?php

namespace App\Http\Controllers;

use App\Models\AsignacionCostos;
use App\Models\CostoSemestral;
use App\Models\Pensum;
use App\Models\Inscripcion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AsignacionCostosController extends Controller
{
	/**
	 * Display a listing of the resource.
	 */
	public function index()
	{
		$asignaciones = AsignacionCostos::with(['pensum', 'inscripcion', 'costoSemestral'])->get();
		return response()->json([
			'success' => true,
			'data' => $asignaciones
		]);
	}

	/**
	 * Store a newly created resource in storage.
	 */
	public function store(Request $request)
	{
		$validator = Validator::make($request->all(), [
			'cod_pensum' => 'required|string|exists:pensums,cod_pensum',
			'cod_inscrip' => 'required|integer|exists:inscripciones,cod_inscrip',
			'monto' => 'required|numeric|min:0',
			'observaciones' => 'nullable|string',
			'estado' => 'required|boolean',
			'id_costo_semestral' => 'required|integer|exists:costo_semestral,id_costo_semestral',
			'id_descuentoDetalle' => 'nullable|string',
			'id_prorroga' => 'nullable|integer',
			'id_compromisos' => 'nullable|integer',
		]);

		if ($validator->fails()) {
			return response()->json([
				'success' => false,
				'errors' => $validator->errors()
			], 422);
		}

		// Verificar que el costo semestral corresponda al pensum
		$costoSemestral = CostoSemestral::where('id_costo_semestral', $request->id_costo_semestral)
			->where('cod_pensum', $request->cod_pensum)
			->first();
			
		if (!$costoSemestral) {
			return response()->json([
				'success' => false,
				'message' => 'El costo semestral no corresponde al pensum especificado'
			], 422);
		}

		$asignacion = AsignacionCostos::create($request->all());

		return response()->json([
			'success' => true,
			'message' => 'Asignación de costo creada exitosamente',
			'data' => $asignacion
		], 201);
	}

	/**
	 * Display the specified resource.
	 */
	public function show(string $id)
	{
		// Como la clave primaria es compuesta, necesitamos parsear el ID
		$ids = explode('-', $id);
		
		if (count($ids) !== 3) {
			return response()->json([
				'success' => false,
				'message' => 'ID inválido'
			], 400);
		}
		
		$asignacion = AsignacionCostos::where('cod_pensum', $ids[0])
			->where('cod_inscrip', $ids[1])
			->where('id_asignacion_costo', $ids[2])
			->with(['pensum', 'inscripcion', 'costoSemestral'])
			->first();
		
		if (!$asignacion) {
			return response()->json([
				'success' => false,
				'message' => 'Asignación de costo no encontrada'
			], 404);
		}

		return response()->json([
			'success' => true,
			'data' => $asignacion
		]);
	}

	/**
	 * Update the specified resource in storage.
	 */
	public function update(Request $request, string $id)
	{
		// Como la clave primaria es compuesta, necesitamos parsear el ID
		$ids = explode('-', $id);
		
		if (count($ids) !== 3) {
			return response()->json([
				'success' => false,
				'message' => 'ID inválido'
			], 400);
		}
		
		$asignacion = AsignacionCostos::where('cod_pensum', $ids[0])
			->where('cod_inscrip', $ids[1])
			->where('id_asignacion_costo', $ids[2])
			->first();
		
		if (!$asignacion) {
			return response()->json([
				'success' => false,
				'message' => 'Asignación de costo no encontrada'
			], 404);
		}

		$validator = Validator::make($request->all(), [
			'monto' => 'sometimes|required|numeric|min:0',
			'observaciones' => 'nullable|string',
			'estado' => 'sometimes|required|boolean',
			'id_costo_semestral' => 'sometimes|required|integer|exists:costo_semestral,id_costo_semestral',
			'id_descuentoDetalle' => 'nullable|string',
			'id_prorroga' => 'nullable|integer',
			'id_compromisos' => 'nullable|integer',
		]);

		if ($validator->fails()) {
			return response()->json([
				'success' => false,
				'errors' => $validator->errors()
			], 422);
		}

		// Si se está actualizando el costo semestral, verificar que corresponda al pensum
		if ($request->has('id_costo_semestral')) {
			$costoSemestral = CostoSemestral::where('id_costo_semestral', $request->id_costo_semestral)
				->where('cod_pensum', $asignacion->cod_pensum)
				->first();
				
			if (!$costoSemestral) {
				return response()->json([
					'success' => false,
					'message' => 'El costo semestral no corresponde al pensum especificado'
				], 422);
			}
		}

		$asignacion->update($request->all());

		return response()->json([
			'success' => true,
			'message' => 'Asignación de costo actualizada exitosamente',
			'data' => $asignacion
		]);
	}

	/**
	 * Remove the specified resource from storage.
	 */
	public function destroy(string $id)
	{
		// Como la clave primaria es compuesta, necesitamos parsear el ID
		$ids = explode('-', $id);
		
		if (count($ids) !== 3) {
			return response()->json([
				'success' => false,
				'message' => 'ID inválido'
			], 400);
		}
		
		$asignacion = AsignacionCostos::where('cod_pensum', $ids[0])
			->where('cod_inscrip', $ids[1])
			->where('id_asignacion_costo', $ids[2])
			->first();
		
		if (!$asignacion) {
			return response()->json([
				'success' => false,
				'message' => 'Asignación de costo no encontrada'
			], 404);
		}

		// Verificar si la asignación tiene recargos por mora antes de eliminar
		if ($asignacion->recargosMora()->count() > 0) {
			return response()->json([
				'success' => false,
				'message' => 'No se puede eliminar la asignación de costo porque tiene recargos por mora asociados'
			], 400);
		}

		$asignacion->delete();

		return response()->json([
			'success' => true,
			'message' => 'Asignación de costo eliminada exitosamente'
		]);
	}
	
	/**
	 * Obtiene las asignaciones de costo por pensum y gestión.
	 */
	public function getByPensumAndGestion(string $codPensum, string $gestion)
	{
		$costosSemestrales = CostoSemestral::where('cod_pensum', $codPensum)
			->where('gestion', $gestion)
			->get();
			
		$idsCostosSemestrales = $costosSemestrales->pluck('id_costo_semestral')->toArray();
		
		$asignaciones = AsignacionCostos::whereIn('id_costo_semestral', $idsCostosSemestrales)
			->where('cod_pensum', $codPensum)
			->with(['pensum', 'inscripcion', 'costoSemestral'])
			->get();
			
		return response()->json([
			'success' => true,
			'data' => $asignaciones
		]);
	}
}
