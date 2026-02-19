<?php

namespace App\Http\Controllers;

use App\Models\ProrrogaMora;
use App\Models\AsignacionCostos;
use App\Models\AsignacionMora;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;
use Carbon\Carbon;

class ProrrogaMoraController extends Controller
{
	/**
	 * Display a listing of the resource.
	 *
	 * @return JsonResponse
	 */
	public function index()
	{
		try {
			$prorrogas = ProrrogaMora::with(['estudiante', 'asignacionCosto', 'usuario'])
				->orderBy('created_at', 'desc')
				->get();

			return response()->json([
				'success' => true,
				'data' => $prorrogas,
				'message' => 'Lista de prórrogas de mora obtenida exitosamente'
			]);
		} catch (\Exception $e) {
			return response()->json([
				'success' => false,
				'message' => 'Error al obtener las prórrogas de mora: ' . $e->getMessage()
			], Response::HTTP_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Store a newly created resource in storage.
	 *
	 * @param Request $request
	 * @return JsonResponse
	 */
	public function store(Request $request)
	{
		try {
			$validator = Validator::make($request->all(), [
				'id_usuario' => 'required|exists:usuarios,id_usuario',
				'cod_ceta' => 'required|exists:estudiantes,cod_ceta',
				'id_asignacion_costo' => 'required|exists:asignacion_costos,id_asignacion_costo',
				'fecha_inicio_prorroga' => 'required|date',
				'fecha_fin_prorroga' => 'required|date|after:fecha_inicio_prorroga',
				'motivo' => 'required|string|min:5',
			]);

			if ($validator->fails()) {
				return response()->json([
					'success' => false,
					'message' => 'Errores de validación',
					'errors' => $validator->errors()
				], Response::HTTP_UNPROCESSABLE_ENTITY);
			}

			$input = $request->all();
			$fechaInicioProrroga = Carbon::parse($input['fecha_inicio_prorroga']);
			$fechaFinProrroga = Carbon::parse($input['fecha_fin_prorroga']);
			$hoy = Carbon::today();

			// Validar que la fecha fin de prórroga sea mayor a hoy
			if ($fechaFinProrroga->lte($hoy)) {
				return response()->json([
					'success' => false,
					'message' => 'La fecha fin de prórroga debe ser posterior a la fecha actual'
				], Response::HTTP_UNPROCESSABLE_ENTITY);
			}

			// Obtener la asignación de costo
			$asignacionCosto = AsignacionCostos::with(['inscripcion', 'pensum'])
				->find($input['id_asignacion_costo']);

			if (!$asignacionCosto) {
				return response()->json([
					'success' => false,
					'message' => 'Asignación de costo no encontrada'
				], Response::HTTP_NOT_FOUND);
			}

			// Verificar si ya existe una prórroga ACTIVA para esta asignación
			$prorrogaExistente = ProrrogaMora::where('id_asignacion_costo', $input['id_asignacion_costo'])
				->where('activo', true)
				->first();
			if ($prorrogaExistente) {
				return response()->json([
					'success' => false,
					'message' => 'Ya existe una prórroga activa para esta cuota'
				], Response::HTTP_CONFLICT);
			}

			// Buscar si existe mora activa para esta cuota (opcional, para congelarla si existe)
			$moraActiva = AsignacionMora::where('id_asignacion_costo', $input['id_asignacion_costo'])
				->where('estado', 'PENDIENTE')
				->whereNull('fecha_fin_mora')
				->first();

			if ($moraActiva) {
				// Congelar la mora actual estableciendo fecha_fin_mora al día antes de la prórroga
				$fechaFinMora = $fechaInicioProrroga->copy()->subDay();
				$moraActiva->fecha_fin_mora = $fechaFinMora;

				// Recalcular monto de mora hasta el día de congelación
				$diasHastaCongela = Carbon::parse($moraActiva->fecha_inicio_mora)->diffInDays($fechaFinMora) + 1;
				$moraActiva->monto_mora = $moraActiva->monto_base * $diasHastaCongela;
				$moraActiva->observaciones = ($moraActiva->observaciones ? $moraActiva->observaciones : '') .
					" | Congelada el {$hoy->format('Y-m-d')} por prórroga hasta {$fechaFinMora->format('Y-m-d')}";
				$moraActiva->save();
			}

			// Crear la prórroga (siempre se crea, independientemente de si hay mora o no)
			$prorroga = ProrrogaMora::create($input);

			return response()->json([
				'success' => true,
				'data' => $prorroga->load(['estudiante', 'asignacionCosto', 'usuario']),
				'message' => 'Prórroga creada exitosamente' . ($moraActiva ? ' (mora congelada)' : ''),
				'mora_congelada' => $moraActiva ? true : false
			], Response::HTTP_CREATED);

		} catch (\Exception $e) {
			return response()->json([
				'success' => false,
				'message' => 'Error al crear la prórroga de mora: ' . $e->getMessage()
			], Response::HTTP_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Display the specified resource.
	 *
	 * @param string $id
	 * @return JsonResponse
	 */
	public function show($id)
	{
		try {
			$prorroga = ProrrogaMora::with(['estudiante', 'asignacionCosto', 'usuario'])->find($id);

			if (!$prorroga) {
				return response()->json([
					'success' => false,
					'message' => 'Prórroga de mora no encontrada'
				], Response::HTTP_NOT_FOUND);
			}

			return response()->json([
				'success' => true,
				'data' => $prorroga,
				'message' => 'Prórroga de mora obtenida exitosamente'
			]);
		} catch (\Exception $e) {
			return response()->json([
				'success' => false,
				'message' => 'Error al obtener la prórroga de mora: ' . $e->getMessage()
			], Response::HTTP_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Update the specified resource in storage.
	 *
	 * @param Request $request
	 * @param string $id
	 * @return JsonResponse
	 */
	public function update(Request $request, $id)
	{
		try {
			$prorroga = ProrrogaMora::find($id);

			if (!$prorroga) {
				return response()->json([
					'success' => false,
					'message' => 'Prórroga de mora no encontrada'
				], Response::HTTP_NOT_FOUND);
			}

			$validator = Validator::make($request->all(), [
				'fecha_inicio_prorroga' => 'sometimes|date',
				'fecha_fin_prorroga' => 'sometimes|date|after:fecha_inicio_prorroga',
			]);

			if ($validator->fails()) {
				return response()->json([
					'success' => false,
					'message' => 'Errores de validación',
					'errors' => $validator->errors()
				], Response::HTTP_UNPROCESSABLE_ENTITY);
			}

			$prorroga->update($request->all());

			return response()->json([
				'success' => true,
				'data' => $prorroga->load(['estudiante', 'asignacionCosto', 'usuario']),
				'message' => 'Prórroga de mora actualizada exitosamente'
			]);
		} catch (\Exception $e) {
			return response()->json([
				'success' => false,
				'message' => 'Error al actualizar la prórroga de mora: ' . $e->getMessage()
			], Response::HTTP_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Remove the specified resource from storage.
	 *
	 * @param string $id
	 * @return JsonResponse
	 */
	public function destroy($id)
	{
		try {
			$prorroga = ProrrogaMora::find($id);

			if (!$prorroga) {
				return response()->json([
					'success' => false,
					'message' => 'Prórroga de mora no encontrada'
				], Response::HTTP_NOT_FOUND);
			}

			$prorroga->delete();

			return response()->json([
				'success' => true,
				'message' => 'Prórroga de mora eliminada exitosamente'
			]);
		} catch (\Exception $e) {
			return response()->json([
				'success' => false,
				'message' => 'Error al eliminar la prórroga de mora: ' . $e->getMessage()
			], Response::HTTP_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Obtiene la configuración de mora aplicable para una asignación de costo.
	 *
	 * @param AsignacionCostos $asignacionCosto
	 * @return mixed
	 */
	private function obtenerConfiguracionMora($asignacionCosto)
	{
		if (!$asignacionCosto->inscripcion) {
			return null;
		}

		$gestion = $asignacionCosto->inscripcion->gestion;
		$codPensum = $asignacionCosto->cod_pensum;
		$numeroCuota = $asignacionCosto->numero_cuota;

		// Obtener semestre (simplificado, puede necesitar ajustes)
		$semestre = isset($asignacionCosto->inscripcion->semestre)
			? $asignacionCosto->inscripcion->semestre
			: null;

		if (!$semestre || !$gestion) {
			return null;
		}

		$configuracion = \App\Models\DatosMoraDetalle::where('cod_pensum', $codPensum)
			->where('cuota', $numeroCuota)
			->where('semestre', $semestre)
			->where('activo', true)
			->whereHas('datosMora', function($query) use ($gestion) {
				$query->where('gestion', $gestion);
			})
			->with('datosMora')
			->first();

		return $configuracion;
	}

	/**
	 * Obtiene las prórrogas activas.
	 *
	 * @return JsonResponse
	 */
	public function activas()
	{
		try {
			$hoy = Carbon::today();
			$prorrogas = ProrrogaMora::activas($hoy)
				->with(['estudiante', 'asignacionCosto', 'usuario'])
				->get();

			return response()->json([
				'success' => true,
				'data' => $prorrogas,
				'message' => 'Prórrogas activas obtenidas exitosamente'
			]);
		} catch (\Exception $e) {
			return response()->json([
				'success' => false,
				'message' => 'Error al obtener prórrogas activas: ' . $e->getMessage()
			], Response::HTTP_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Obtiene las prórrogas por estudiante.
	 *
	 * @param string $codCeta
	 * @return JsonResponse
	 */
	public function porEstudiante($codCeta)
	{
		try {
			$prorrogas = ProrrogaMora::where('cod_ceta', $codCeta)
				->with(['estudiante', 'asignacionCosto', 'usuario'])
				->orderBy('created_at', 'desc')
				->get();

			return response()->json([
				'success' => true,
				'data' => $prorrogas,
				'message' => 'Prórrogas del estudiante obtenidas exitosamente'
			]);
		} catch (\Exception $e) {
			return response()->json([
				'success' => false,
				'message' => 'Error al obtener prórrogas del estudiante: ' . $e->getMessage()
			], Response::HTTP_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Activa o desactiva una prórroga.
	 *
	 * @param string $id
	 * @return JsonResponse
	 */
	public function toggleStatus($id)
	{
		try {
			$prorroga = ProrrogaMora::find($id);

			if (!$prorroga) {
				return response()->json([
					'success' => false,
					'message' => 'Prórroga de mora no encontrada'
				], Response::HTTP_NOT_FOUND);
			}

			$prorroga->activo = !$prorroga->activo;
			$prorroga->save();

			return response()->json([
				'success' => true,
				'data' => $prorroga->load(['estudiante', 'asignacionCosto', 'usuario']),
				'message' => 'Estado de prórroga actualizado exitosamente'
			]);
		} catch (\Exception $e) {
			return response()->json([
				'success' => false,
				'message' => 'Error al cambiar estado de prórroga: ' . $e->getMessage()
			], Response::HTTP_INTERNAL_SERVER_ERROR);
		}
	}
}
