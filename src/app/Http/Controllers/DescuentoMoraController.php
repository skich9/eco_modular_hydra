<?php

namespace App\Http\Controllers;

use App\Models\AsignacionMora;
use App\Models\DescuentoMora;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

class DescuentoMoraController extends Controller
{
	public function index()
	{
		try {
			$descuentos = DescuentoMora::with([
				'asignacionMora.asignacionCosto.inscripcion.estudiante',
				'asignacionMora.asignacionCosto.pensum',
			])
				->orderBy('created_at', 'desc')
				->get();

			return response()->json([
				'success' => true,
				'data' => $descuentos,
				'message' => 'Lista de descuentos de mora obtenida exitosamente'
			]);
		} catch (\Exception $e) {
			return response()->json([
				'success' => false,
				'message' => 'Error al obtener descuentos de mora: ' . $e->getMessage()
			], Response::HTTP_INTERNAL_SERVER_ERROR);
		}
	}

	public function porEstudiante($codCeta)
	{
		try {
			$descuentos = DescuentoMora::query()
				->whereHas('asignacionMora.asignacionCosto.inscripcion', function ($q) use ($codCeta) {
					$q->where('cod_ceta', $codCeta);
				})
				->with([
					'asignacionMora.asignacionCosto.inscripcion.estudiante',
					'asignacionMora.asignacionCosto.pensum',
				])
				->orderBy('created_at', 'desc')
				->get();

			return response()->json([
				'success' => true,
				'data' => $descuentos,
				'message' => 'Descuentos de mora del estudiante obtenidos exitosamente'
			]);
		} catch (\Exception $e) {
			return response()->json([
				'success' => false,
				'message' => 'Error al obtener descuentos del estudiante: ' . $e->getMessage()
			], Response::HTTP_INTERNAL_SERVER_ERROR);
		}
	}

	public function storeBatch(Request $request)
	{
		try {
			$validator = Validator::make($request->all(), [
				'descuentos' => 'required|array|min:1',
				'descuentos.*.id_asignacion_mora' => 'required|exists:asignacion_mora,id_asignacion_mora',
				'descuentos.*.porcentaje' => 'nullable|boolean',
				'descuentos.*.monto_descuento' => 'nullable|numeric|min:0',
				'observaciones' => 'required|string|min:3',
			]);

			if ($validator->fails()) {
				return response()->json([
					'success' => false,
					'message' => 'Errores de validación',
					'errors' => $validator->errors(),
				], Response::HTTP_UNPROCESSABLE_ENTITY);
			}

			$input = $request->all();
			$rows = isset($input['descuentos']) ? (array) $input['descuentos'] : [];
			$observaciones = isset($input['observaciones']) ? (string) $input['observaciones'] : '';

			$created = [];

			DB::transaction(function () use ($rows, $observaciones, &$created) {
				$idsMora = [];

				foreach ($rows as $r) {
					$monto = isset($r['monto_descuento']) ? (float) $r['monto_descuento'] : 0;
					if ($monto <= 0) continue;

					$idAsignacionMora = (int) $r['id_asignacion_mora'];
					$idsMora[] = $idAsignacionMora;

					DescuentoMora::where('id_asignacion_mora', $idAsignacionMora)
						->where('activo', true)
						->update(['activo' => false]);

					$created[] = DescuentoMora::create([
						'id_asignacion_mora' => $idAsignacionMora,
						'porcentaje' => (bool) (isset($r['porcentaje']) ? $r['porcentaje'] : false),
						'monto_descuento' => $monto,
						'observaciones' => $observaciones,
						'activo' => true,
					]);
				}

				$idsMora = array_values(array_unique($idsMora));
				foreach ($idsMora as $idAsignacionMora) {
					$activo = DescuentoMora::where('id_asignacion_mora', $idAsignacionMora)
						->where('activo', true)
						->orderBy('created_at', 'desc')
						->first();

					$montoActivo = $activo ? (float) $activo->monto_descuento : 0;
					AsignacionMora::where('id_asignacion_mora', $idAsignacionMora)->update([
						'monto_descuento' => $montoActivo,
					]);
				}
			});

			return response()->json([
				'success' => true,
				'data' => collect($created)->map(function ($d) {
					return $d->load([
						'asignacionMora.asignacionCosto.inscripcion.estudiante',
						'asignacionMora.asignacionCosto.pensum',
					]);
				}),
				'message' => 'Descuento(s) de mora registrado(s) exitosamente'
			], Response::HTTP_CREATED);
		} catch (\Exception $e) {
			return response()->json([
				'success' => false,
				'message' => 'Error al registrar descuentos de mora: ' . $e->getMessage()
			], Response::HTTP_INTERNAL_SERVER_ERROR);
		}
	}

	public function toggleStatus($id)
	{
		try {
			$descuento = DescuentoMora::find($id);
			if (!$descuento) {
				return response()->json([
					'success' => false,
					'message' => 'Descuento de mora no encontrado'
				], Response::HTTP_NOT_FOUND);
			}

			DB::transaction(function () use ($descuento) {
				$descuento->activo = !$descuento->activo;
				$descuento->save();

				if ($descuento->activo) {
					DescuentoMora::where('id_asignacion_mora', $descuento->id_asignacion_mora)
						->where('id_descuento_mora', '!=', $descuento->id_descuento_mora)
						->where('activo', true)
						->update(['activo' => false]);
				}

				$activo = DescuentoMora::where('id_asignacion_mora', $descuento->id_asignacion_mora)
					->where('activo', true)
					->orderBy('created_at', 'desc')
					->first();

				$montoActivo = $activo ? (float) $activo->monto_descuento : 0;
				AsignacionMora::where('id_asignacion_mora', $descuento->id_asignacion_mora)->update([
					'monto_descuento' => $montoActivo,
				]);
			});

			return response()->json([
				'success' => true,
				'data' => $descuento->load([
					'asignacionMora.asignacionCosto.inscripcion.estudiante',
					'asignacionMora.asignacionCosto.pensum',
				]),
				'message' => 'Estado actualizado'
			]);
		} catch (\Exception $e) {
			return response()->json([
				'success' => false,
				'message' => 'Error al actualizar estado: ' . $e->getMessage()
			], Response::HTTP_INTERNAL_SERVER_ERROR);
		}
	}
}
