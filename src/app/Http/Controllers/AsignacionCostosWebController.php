<?php

namespace App\Http\Controllers;

use App\Models\AsignacionCostos;
use App\Models\CostoSemestral;
use App\Models\Pensum;
use App\Models\Inscripcion;
use App\Models\ParametrosEconomicos;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AsignacionCostosWebController extends Controller
{
	/**
	 * Muestra la vista principal de asignación económica.
	 */
	public function index()
	{
		$pensums = Pensum::where('estado', true)->get();
		$parametrosEconomicos = ParametrosEconomicos::where('estado', true)->get();
		
		// Obtener la gestión actual (esto podría venir de una tabla de configuración)
		$gestionActual = date('Y');
		
		return view('configuracion.asignacion_economica.index', compact('pensums', 'parametrosEconomicos', 'gestionActual'));
	}

	/**
	 * Muestra la vista de asignación económica para un pensum y gestión específicos.
	 */
	public function showAsignacion($codPensum, $gestion)
	{
		$pensum = Pensum::where('cod_pensum', $codPensum)->first();
		
		if (!$pensum) {
			return redirect()->route('asignacion_economica.index')
				->with('error', 'El pensum especificado no existe');
		}
		
		$costosSemestrales = CostoSemestral::where('cod_pensum', $codPensum)
			->where('gestion', $gestion)
			->get();
			
		$idsCostosSemestrales = $costosSemestrales->pluck('id_costo_semestral')->toArray();
		
		$asignaciones = AsignacionCostos::whereIn('id_costo_semestral', $idsCostosSemestrales)
			->where('cod_pensum', $codPensum)
			->with(['inscripcion', 'costoSemestral'])
			->get();
			
		return view('configuracion.asignacion_economica.asignacion', compact('pensum', 'gestion', 'costosSemestrales', 'asignaciones'));
	}

	/**
	 * Almacena un nuevo costo semestral en la base de datos.
	 */
	public function storeCostoSemestral(Request $request)
	{
		$validator = Validator::make($request->all(), [
			'cod_pensum' => 'required|string|exists:pensums,cod_pensum',
			'gestion' => 'required|string',
			'semestre' => 'required|string',
			'monto_semestre' => 'required|numeric|min:0',
			'id_usuario' => 'required|integer|exists:usuarios,id_usuario',
		]);

		if ($validator->fails()) {
			return response()->json([
				'success' => false,
				'errors' => $validator->errors()
			], 422);
		}

		// Verificar si ya existe un costo semestral para el mismo pensum, gestión y semestre
		$costoExistente = CostoSemestral::where('cod_pensum', $request->cod_pensum)
			->where('gestion', $request->gestion)
			->where('semestre', $request->semestre)
			->first();
			
		if ($costoExistente) {
			return response()->json([
				'success' => false,
				'message' => 'Ya existe un costo semestral para este pensum, gestión y semestre'
			], 422);
		}

		$costoSemestral = CostoSemestral::create($request->all());

		return response()->json([
			'success' => true,
			'message' => 'Costo semestral creado exitosamente',
			'data' => $costoSemestral
		], 201);
	}

	/**
	 * Almacena una nueva asignación de costo en la base de datos.
	 */
	public function storeAsignacion(Request $request)
	{
		$validator = Validator::make($request->all(), [
			'cod_pensum' => 'required|string|exists:pensums,cod_pensum',
			'cod_inscrip' => 'required|integer|exists:inscripciones,cod_inscrip',
			'monto' => 'required|numeric|min:0',
			'observaciones' => 'nullable|string',
			'estado' => 'required|boolean',
			'id_costo_semestral' => 'required|integer|exists:costo_semestral,id_costo_semestral',
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
	 * Actualiza una asignación de costo existente.
	 */
	public function updateAsignacion(Request $request, $codPensum, $codInscrip, $idAsignacion)
	{
		$asignacion = AsignacionCostos::where('cod_pensum', $codPensum)
			->where('cod_inscrip', $codInscrip)
			->where('id_asignacion_costo', $idAsignacion)
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
	 * Elimina una asignación de costo.
	 */
	public function destroyAsignacion($codPensum, $codInscrip, $idAsignacion)
	{
		$asignacion = AsignacionCostos::where('cod_pensum', $codPensum)
			->where('cod_inscrip', $codInscrip)
			->where('id_asignacion_costo', $idAsignacion)
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
	 * Actualiza el estado de una asignación de costo.
	 */
	public function toggleStatus($codPensum, $codInscrip, $idAsignacion)
	{
		$asignacion = AsignacionCostos::where('cod_pensum', $codPensum)
			->where('cod_inscrip', $codInscrip)
			->where('id_asignacion_costo', $idAsignacion)
			->first();
		
		if (!$asignacion) {
			return response()->json([
				'success' => false,
				'message' => 'Asignación de costo no encontrada'
			], 404);
		}
		
		$asignacion->estado = !$asignacion->estado;
		$asignacion->save();
		
		return response()->json([
			'success' => true,
			'message' => 'Estado de la asignación de costo actualizado exitosamente',
			'data' => $asignacion
		]);
	}
	
	/**
	 * Obtiene los datos de una asignación de costo para mostrar en un modal.
	 */
	public function show($codPensum, $codInscrip, $idAsignacion)
	{
		$asignacion = AsignacionCostos::where('cod_pensum', $codPensum)
			->where('cod_inscrip', $codInscrip)
			->where('id_asignacion_costo', $idAsignacion)
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
}
