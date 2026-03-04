<?php

namespace App\Http\Controllers;

use App\Models\ProrrogaMora;
use App\Models\AsignacionCostos;
use App\Models\AsignacionMora;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;
use Carbon\Carbon;

class ProrrogaMoraController extends Controller
{
	private function prorrogaDebugEnabled()
	{
		return filter_var(env('PRORROGA_MORA_DEBUG', env('MORA_BUSQUEDA_DEBUG', false)), FILTER_VALIDATE_BOOLEAN);
	}

	private function prorrogaLog($level, $event, $context = [])
	{
		$payload = array_merge([
			'feature' => 'prorroga_mora',
			'event' => $event,
		], $context);

		if ($level === 'error') {
			Log::error('prorroga_mora', $payload);
			return;
		}

		if (!$this->prorrogaDebugEnabled()) {
			return;
		}
		Log::info('prorroga_mora', $payload);
	}

	/**
	 * Display a listing of the resource.
	 *
	 * @return JsonResponse
	 */
	public function index()
	{
		try {
			$prorrogas = ProrrogaMora::with([
				'estudiante',
				'asignacionCosto.inscripcion',
				'asignacionCosto.pensum',
				'usuario'
			])
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
		$requestId = uniqid('prorroga_', true);
		$this->prorrogaLog('info', 'store.start', [
			'request_id' => $requestId,
			'payload' => $request->only([
				'id_usuario',
				'cod_ceta',
				'id_asignacion_costo',
				'fecha_inicio_prorroga',
				'fecha_fin_prorroga',
				'activo',
				'motivo',
			]),
		]);

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
				$this->prorrogaLog('info', 'store.validation_failed', [
					'request_id' => $requestId,
					'errors' => $validator->errors()->toArray(),
				]);
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
				$this->prorrogaLog('info', 'store.fecha_fin_no_posterior_hoy', [
					'request_id' => $requestId,
					'hoy' => $hoy->toDateString(),
					'fecha_fin_prorroga' => $fechaFinProrroga->toDateString(),
				]);
				return response()->json([
					'success' => false,
					'message' => 'La fecha fin de prórroga debe ser posterior a la fecha actual'
				], Response::HTTP_UNPROCESSABLE_ENTITY);
			}

			// Obtener la asignación de costo
			$asignacionCosto = AsignacionCostos::with(['inscripcion', 'pensum'])
				->find($input['id_asignacion_costo']);

			if (!$asignacionCosto) {
				$this->prorrogaLog('info', 'store.asignacion_costo_not_found', [
					'request_id' => $requestId,
					'id_asignacion_costo' => $input['id_asignacion_costo'],
				]);
				return response()->json([
					'success' => false,
					'message' => 'Asignación de costo no encontrada'
				], Response::HTTP_NOT_FOUND);
			}

			// Desactivar prórrogas anteriores para el mismo estudiante y asignación de costo
			ProrrogaMora::where('cod_ceta', $input['cod_ceta'])
				->where('id_asignacion_costo', $input['id_asignacion_costo'])
				->where('activo', true)
				->update(['activo' => false]);
			$this->prorrogaLog('info', 'store.desactivar_prorrogas_previas_ok', [
				'request_id' => $requestId,
				'cod_ceta' => $input['cod_ceta'],
				'id_asignacion_costo' => $input['id_asignacion_costo'],
			]);

			// Buscar si existe mora activa para esta cuota (opcional, para congelarla si existe)
			$moraActiva = AsignacionMora::where('id_asignacion_costo', $input['id_asignacion_costo'])
				->where('estado', 'PENDIENTE')
				->whereNull('fecha_fin_mora')
				->first();
			$this->prorrogaLog('info', 'store.mora_activa_lookup', [
				'request_id' => $requestId,
				'id_asignacion_costo' => $input['id_asignacion_costo'],
				'mora_activa_id' => $moraActiva ? $moraActiva->id_asignacion_mora : null,
			]);

			if ($moraActiva) {
				// Congelar la mora actual estableciendo fecha_fin_mora al día antes de la prórroga
				$fechaFinMora = $fechaInicioProrroga->copy()->subDay();
				$moraActiva->fecha_fin_mora = $fechaFinMora;

				// Recalcular monto de mora hasta el día de congelación
				$fechaInicioMora = !empty($moraActiva->fecha_inicio_mora) ? Carbon::parse($moraActiva->fecha_inicio_mora)->startOfDay() : null;
				$diasHastaCongela = 0;
				if ($fechaInicioMora && $fechaFinMora->gte($fechaInicioMora)) {
					$diasHastaCongela = $fechaInicioMora->diffInDays($fechaFinMora) + 1;
				}
				$moraActiva->monto_mora = $moraActiva->monto_base * (int)$diasHastaCongela;
				$moraActiva->estado = 'EN_ESPERA';
				$moraActiva->observaciones = ($moraActiva->observaciones ? $moraActiva->observaciones : '') .
					" | Congelada el {$hoy->format('Y-m-d')} por prórroga hasta {$fechaFinMora->format('Y-m-d')}";
				$moraActiva->save();
				$this->prorrogaLog('info', 'store.mora_congelada_ok', [
					'request_id' => $requestId,
					'mora_activa_id' => $moraActiva->id_asignacion_mora,
					'fecha_inicio_mora' => $fechaInicioMora ? $fechaInicioMora->toDateString() : null,
					'fecha_fin_mora' => $fechaFinMora->toDateString(),
					'dias_hasta_congela' => (int)$diasHastaCongela,
					'monto_base' => $moraActiva->monto_base,
					'monto_mora' => $moraActiva->monto_mora,
					'estado' => $moraActiva->estado,
				]);
			}

			// Crear la prórroga (siempre se crea, independientemente de si hay mora o no)
			$prorroga = ProrrogaMora::create($input);
			$this->prorrogaLog('info', 'store.prorroga_created', [
				'request_id' => $requestId,
				'prorroga_id' => isset($prorroga->id_prorroga_mora) ? $prorroga->id_prorroga_mora : null,
				'cod_ceta' => $input['cod_ceta'],
				'id_asignacion_costo' => $input['id_asignacion_costo'],
				'fecha_inicio_prorroga' => $fechaInicioProrroga->toDateString(),
				'fecha_fin_prorroga' => $fechaFinProrroga->toDateString(),
			]);

			return response()->json([
				'success' => true,
				'data' => $prorroga->load([
					'estudiante',
					'asignacionCosto.inscripcion',
					'asignacionCosto.pensum',
					'usuario'
				]),
				'message' => 'Prórroga creada exitosamente' . ($moraActiva ? ' (mora congelada)' : ''),
				'mora_congelada' => $moraActiva ? true : false
			], Response::HTTP_CREATED);

		} catch (\Exception $e) {
			$this->prorrogaLog('error', 'store.exception', [
				'request_id' => $requestId,
				'message' => $e->getMessage(),
				'file' => $e->getFile(),
				'line' => $e->getLine(),
				'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 25),
			]);
			return response()->json([
				'success' => false,
				'message' => 'Error al crear la prórroga de mora: ' . $e->getMessage()
			], Response::HTTP_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Store multiple prorrogas in storage (batch creation).
	 *
	 * @param Request $request
	 * @return JsonResponse
	 */
	public function storeBatch(Request $request)
	{
		$requestId = uniqid('prorroga_batch_', true);
		$this->prorrogaLog('info', 'storeBatch.start', [
			'request_id' => $requestId,
			'count' => is_array($request->input('prorrogas', [])) ? count($request->input('prorrogas', [])) : null,
		]);

		try {
			$prorrogas = $request->input('prorrogas', []);

			if (empty($prorrogas) || !is_array($prorrogas)) {
				$this->prorrogaLog('info', 'storeBatch.invalid_payload', [
					'request_id' => $requestId,
					'type' => gettype($prorrogas),
				]);
				return response()->json([
					'success' => false,
					'message' => 'No se proporcionaron prórrogas para crear'
				], Response::HTTP_BAD_REQUEST);
			}

			$created = [];
			$errors = [];

			foreach ($prorrogas as $index => $prorrogaData) {
				try {
					$this->prorrogaLog('info', 'storeBatch.item.start', [
						'request_id' => $requestId,
						'index' => $index,
						'payload' => is_array($prorrogaData) ? array_intersect_key($prorrogaData, array_flip([
							'id_usuario',
							'cod_ceta',
							'id_asignacion_costo',
							'fecha_inicio_prorroga',
							'fecha_fin_prorroga',
							'activo',
							'motivo',
						])) : null,
					]);

					// Validar cada prórroga
					$validator = Validator::make($prorrogaData, [
						'id_usuario' => 'required|exists:usuarios,id_usuario',
						'cod_ceta' => 'required|exists:estudiantes,cod_ceta',
						'id_asignacion_costo' => 'required|exists:asignacion_costos,id_asignacion_costo',
						'fecha_inicio_prorroga' => 'required|date',
						'fecha_fin_prorroga' => 'required|date|after:fecha_inicio_prorroga',
						'activo' => 'boolean',
						'motivo' => 'required|string|min:5',
					]);

					if ($validator->fails()) {
						$this->prorrogaLog('info', 'storeBatch.item.validation_failed', [
							'request_id' => $requestId,
							'index' => $index,
							'id_asignacion_costo' => isset($prorrogaData['id_asignacion_costo']) ? $prorrogaData['id_asignacion_costo'] : null,
							'errors' => $validator->errors()->toArray(),
						]);
						$errors[] = [
							'index' => $index,
							'id_asignacion_costo' => isset($prorrogaData['id_asignacion_costo']) ? $prorrogaData['id_asignacion_costo'] : null,
							'errors' => $validator->errors()
						];
						continue;
					}

					$input = $validator->validated();
					$hoy = Carbon::now();
					$fechaInicioProrroga = Carbon::parse($input['fecha_inicio_prorroga']);
					$fechaFinProrroga = Carbon::parse($input['fecha_fin_prorroga']);

					// Validar que fecha_fin_prorroga sea posterior a hoy
					if ($fechaFinProrroga->lte($hoy)) {
						$this->prorrogaLog('info', 'storeBatch.item.fecha_fin_no_posterior_hoy', [
							'request_id' => $requestId,
							'index' => $index,
							'id_asignacion_costo' => $input['id_asignacion_costo'],
							'hoy' => Carbon::parse($hoy)->toDateString(),
							'fecha_fin_prorroga' => $fechaFinProrroga->toDateString(),
						]);
						$errors[] = [
							'index' => $index,
							'id_asignacion_costo' => $input['id_asignacion_costo'],
							'message' => 'La fecha fin de prórroga debe ser posterior a la fecha actual'
						];
						continue;
					}

					// Buscar mora activa para esta asignación de costo
					$moraActiva = AsignacionMora::where('id_asignacion_costo', $input['id_asignacion_costo'])
						->where('estado', 'PENDIENTE')
						->first();
					$this->prorrogaLog('info', 'storeBatch.item.mora_activa_lookup', [
						'request_id' => $requestId,
						'index' => $index,
						'id_asignacion_costo' => $input['id_asignacion_costo'],
						'mora_activa_id' => $moraActiva ? $moraActiva->id_asignacion_mora : null,
					]);

					// Desactivar prórrogas anteriores para el mismo estudiante y asignación de costo
					ProrrogaMora::where('cod_ceta', $input['cod_ceta'])
						->where('id_asignacion_costo', $input['id_asignacion_costo'])
						->where('activo', true)
						->update(['activo' => false]);

					// Si hay mora activa, congelarla
					if ($moraActiva) {
						$fechaFinMora = $fechaInicioProrroga->copy()->subDay();
						$moraActiva->fecha_fin_mora = $fechaFinMora;

						$fechaInicioMora = !empty($moraActiva->fecha_inicio_mora) ? Carbon::parse($moraActiva->fecha_inicio_mora)->startOfDay() : null;
						$diasHastaCongela = 0;
						if ($fechaInicioMora && $fechaFinMora->gte($fechaInicioMora)) {
							$diasHastaCongela = $fechaInicioMora->diffInDays($fechaFinMora) + 1;
						}
						$moraActiva->monto_mora = $moraActiva->monto_base * (int)$diasHastaCongela;
						$moraActiva->estado = 'EN_ESPERA';
						$moraActiva->observaciones = ($moraActiva->observaciones ? $moraActiva->observaciones : '') .
							" | Congelada el {$hoy->format('Y-m-d')} por prórroga hasta {$fechaFinMora->format('Y-m-d')}";
						$moraActiva->save();
						$this->prorrogaLog('info', 'storeBatch.item.mora_congelada_ok', [
							'request_id' => $requestId,
							'index' => $index,
							'mora_activa_id' => $moraActiva->id_asignacion_mora,
							'fecha_inicio_mora' => $fechaInicioMora ? $fechaInicioMora->toDateString() : null,
							'fecha_fin_mora' => $fechaFinMora->toDateString(),
							'dias_hasta_congela' => (int)$diasHastaCongela,
							'monto_base' => $moraActiva->monto_base,
							'monto_mora' => $moraActiva->monto_mora,
							'estado' => $moraActiva->estado,
						]);
					}

					// Crear la prórroga
					$prorroga = ProrrogaMora::create($input);
					$this->prorrogaLog('info', 'storeBatch.item.prorroga_created', [
						'request_id' => $requestId,
						'index' => $index,
						'prorroga_id' => isset($prorroga->id_prorroga_mora) ? $prorroga->id_prorroga_mora : null,
						'cod_ceta' => $input['cod_ceta'],
						'id_asignacion_costo' => $input['id_asignacion_costo'],
						'fecha_inicio_prorroga' => $fechaInicioProrroga->toDateString(),
						'fecha_fin_prorroga' => $fechaFinProrroga->toDateString(),
					]);
					$prorroga->load([
						'estudiante',
						'asignacionCosto.inscripcion',
						'asignacionCosto.pensum',
						'usuario'
					]);

					$created[] = $prorroga;

				} catch (\Exception $e) {
					$this->prorrogaLog('error', 'storeBatch.item.exception', [
						'request_id' => $requestId,
						'index' => $index,
						'id_asignacion_costo' => isset($prorrogaData['id_asignacion_costo']) ? $prorrogaData['id_asignacion_costo'] : null,
						'message' => $e->getMessage(),
						'file' => $e->getFile(),
						'line' => $e->getLine(),
						'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 25),
					]);
					$errors[] = [
						'index' => $index,
						'id_asignacion_costo' => isset($prorrogaData['id_asignacion_costo']) ? $prorrogaData['id_asignacion_costo'] : null,
						'message' => $e->getMessage()
					];
				}
			}

			$response = [
				'success' => count($created) > 0,
				'data' => $created,
				'message' => count($created) . ' prórroga(s) creada(s) exitosamente'
			];

			if (!empty($errors)) {
				$response['errors'] = $errors;
				$response['message'] .= ', ' . count($errors) . ' error(es)';
			}

			return response()->json($response, Response::HTTP_CREATED);

		} catch (\Exception $e) {
			$this->prorrogaLog('error', 'storeBatch.exception', [
				'request_id' => $requestId,
				'message' => $e->getMessage(),
				'file' => $e->getFile(),
				'line' => $e->getLine(),
				'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 25),
			]);
			return response()->json([
				'success' => false,
				'message' => 'Error al crear las prórrogas de mora: ' . $e->getMessage()
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

			$activoAnterior = (bool)$prorroga->activo;
			$prorroga->activo = !$prorroga->activo;
			$prorroga->save();

			if ($activoAnterior === true && $prorroga->activo === false) {
				$actualizados = AsignacionMora::where('id_asignacion_costo', $prorroga->id_asignacion_costo)
					->where('estado', 'EN_ESPERA')
					->whereNotNull('fecha_fin_mora')
					->update([
						'estado' => 'PENDIENTE',
						'updated_at' => now(),
					]);
				$this->prorrogaLog('info', 'toggleStatus.desactivar.descongelar_mora', [
					'prorroga_id' => $id,
					'id_asignacion_costo' => $prorroga->id_asignacion_costo,
					'rows_updated' => $actualizados,
				]);
			}

			return response()->json([
				'success' => true,
				'data' => $prorroga->load(['estudiante', 'asignacionCosto', 'usuario']),
				'message' => 'Estado de prórroga actualizado exitosamente'
			]);
		} catch (\Exception $e) {
			$this->prorrogaLog('error', 'toggleStatus.exception', [
				'prorroga_id' => $id,
				'message' => $e->getMessage(),
				'file' => $e->getFile(),
				'line' => $e->getLine(),
				'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 25),
			]);
			return response()->json([
				'success' => false,
				'message' => 'Error al cambiar estado de prórroga: ' . $e->getMessage()
			], Response::HTTP_INTERNAL_SERVER_ERROR);
		}
	}
}
