<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Descuento;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\DescuentoDetalle;
use App\Models\Inscripcion;
use Illuminate\Support\Facades\DB;
use App\Models\AsignacionCostos;

class DescuentoController extends Controller
{
	public function index(Request $request)
	{
		try {
			$query = Descuento::with(['definicion', 'beca', 'detalles']);
			if ($request->has('estado')) {
				$query->where('estado', filter_var($request->get('estado'), FILTER_VALIDATE_BOOLEAN));
			}
			if ($request->has('cod_pensum')) {
				$query->where('cod_pensum', $request->get('cod_pensum'));
			}
			if ($request->has('cod_ceta')) {
				$query->where('cod_ceta', $request->get('cod_ceta'));
			}
			if ($request->has('cod_inscrip')) {
				$query->where('cod_inscrip', $request->get('cod_inscrip'));
			}
			$descuentos = $query->orderByDesc('id_descuentos')->get();
			
			// Agregar tipo_descuento y cuotas calculados
			$descuentos->each(function($d) {
				// El campo 'beca' en def_descuentos_beca: 1=BECA, 0=DESCUENTO
				if ($d->beca && isset($d->beca->beca)) {
					$d->tipo_descuento = $d->beca->beca ? 'BECA' : 'DESCUENTO';
				} else {
					$d->tipo_descuento = 'BECA'; // fallback
				}
				
				// Obtener números de cuota desde detalles
				$cuotasNums = [];
				if ($d->detalles && $d->detalles->count() > 0) {
					foreach ($d->detalles as $det) {
						// Buscar numero_cuota desde asignacion_costos usando id_cuota
						if ($det->id_cuota) {
							$asig = AsignacionCostos::find($det->id_cuota);
							if ($asig && $asig->numero_cuota) {
								$cuotasNums[] = (int)$asig->numero_cuota;
							}
						}
					}
				}
				sort($cuotasNums);
				$d->cuotas = implode(', ', $cuotasNums);
			});
			
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

			DB::transaction(function () use ($descuento) {
				$detalles = DescuentoDetalle::where('id_descuento', $descuento->id_descuentos)->get(['id_descuento_detalle']);
				$idsDetalles = $detalles->pluck('id_descuento_detalle')->filter()->values()->all();
				if (!empty($idsDetalles)) {
					AsignacionCostos::whereIn('id_descuentoDetalle', $idsDetalles)->update(['id_descuentoDetalle' => null]);
					DescuentoDetalle::whereIn('id_descuento_detalle', $idsDetalles)->delete();
				} else {
					DescuentoDetalle::where('id_descuento', $descuento->id_descuentos)->delete();
				}
				$descuento->delete();
			});

			return response()->json(['success' => true, 'message' => 'Descuento y sus detalles eliminados correctamente']);
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

	public function asignar(Request $request)
	{
		try {
			$validator = Validator::make($request->all(), [
				'cod_ceta' => 'required|integer|exists:estudiantes,cod_ceta',
				'cod_pensum' => 'required|string|exists:pensums,cod_pensum',
				'cod_inscrip' => 'required|integer|exists:inscripciones,cod_inscrip',
				'id_usuario' => 'required|integer|exists:usuarios,id_usuario',
				'cod_beca' => 'required|integer|exists:def_descuentos_beca,cod_beca',
				'nombre' => 'required|string|max:255',
				// Aquí se guarda el MONTO descontado total (no porcentaje)
				'porcentaje' => 'required|numeric|min:0',
				'observaciones' => 'nullable|string',
				'codigoArchivo' => 'nullable|string|max:255',
				'fechaSolicitud' => 'required|date',
				'meses' => 'nullable|string|max:255',
				'tipo_inscripcion' => 'nullable|string|max:100',
				'cuotas' => 'required|array|min:1',
				'cuotas.*.numero_cuota' => 'required|integer|min:1',
				// id_cuota en payload es opcional y puede venir de plantilla; se ignorará y se usará id_asignacion_costo
				'cuotas.*.id_cuota' => 'nullable|integer',
				'cuotas.*.monto_descuento' => 'required|numeric|min:0',
				'cuotas.*.observaciones' => 'nullable|string',
			]);
			if ($validator->fails()) {
				return response()->json(['success' => false, 'message' => 'Error de validación', 'errors' => $validator->errors()], 422);
			}

			// Validar que las cuotas no tengan ya un descuento asignado (id_descuentoDetalle no debe estar lleno)
			$codPensum = (string)$request->input('cod_pensum');
			$codInscrip = (int)$request->input('cod_inscrip');
			$cuotasRequest = (array)$request->input('cuotas', []);
			$numerosCuotas = array_map(function($c) { return (int)($c['numero_cuota'] ?? 0); }, $cuotasRequest);
			
			// Verificar si alguna de las cuotas ya tiene un descuento asignado
			$cuotasConDescuento = AsignacionCostos::where('cod_pensum', $codPensum)
				->where('cod_inscrip', $codInscrip)
				->whereIn('numero_cuota', $numerosCuotas)
				->whereNotNull('id_descuentoDetalle')
				->pluck('numero_cuota')
				->toArray();
			
			if (!empty($cuotasConDescuento)) {
				$cuotasStr = implode(', ', $cuotasConDescuento);
				return response()->json([
					'success' => false,
					'message' => "El estudiante ya tiene un descuento o beca activo en las cuotas: {$cuotasStr}. No se puede asignar más de uno por cuota."
				], 422);
			}

			$insc = Inscripcion::find((int)$request->input('cod_inscrip'));
			if (!$insc) return response()->json(['success' => false, 'message' => 'Inscripción no encontrada'], 404);

			// Inferir turno y semestre desde cod_curso (M/T/N y dígitos tras guion)
			$codCurso = (string)($insc->cod_curso ?? '');
			$turno = null;
			if ($codCurso !== '') {
				$last = strtoupper(substr($codCurso, -1));
				if (in_array($last, ['M','T','N'])) $turno = $last;
			}
			$semestre = null;
			if (strpos($codCurso, '-') !== false) {
				$after = substr($codCurso, strpos($codCurso, '-') + 1);
				$firstDigit = null;
				for ($i = 0; $i < strlen($after); $i++) {
					$ch = substr($after, $i, 1);
					if (ctype_digit($ch)) { $firstDigit = $ch; break; }
				}
				if ($firstDigit !== null) $semestre = $firstDigit;
			}

			$payloadMaster = [
				'cod_ceta' => (int)$request->input('cod_ceta'),
				'cod_pensum' => (string)$request->input('cod_pensum'),
				'cod_inscrip' => (int)$request->input('cod_inscrip'),
				'id_usuario' => (int)$request->input('id_usuario'),
				'cod_descuento' => null,
				'cod_beca' => (int)$request->input('cod_beca'),
				'nombre' => (string)$request->input('nombre'),
				'porcentaje' => (float)$request->input('porcentaje'),
				'observaciones' => $request->input('observaciones'),
				'tipo' => (string)($insc->tipo_inscripcion ?? ''),
				'estado' => true,
			];

			[$descuento, $detRows] = DB::transaction(function() use ($payloadMaster, $request, $turno, $semestre) {
				$descuento = Descuento::create($payloadMaster);
				$rows = [];
				$detBase = [
					'id_descuento' => (int)$descuento->id_descuentos,
					'id_usuario' => (int)$request->input('id_usuario'),
					'id_inscripcion' => (int)$request->input('cod_inscrip'),
					'cod_Archivo' => $request->input('codigoArchivo'),
					'fecha_registro' => now(),
					'fecha_solicitud' => $request->input('fechaSolicitud') ? date('Y-m-d', strtotime((string)$request->input('fechaSolicitud'))) : null,
					'observaciones' => $request->input('observaciones'),
					'tipo_inscripcion' => (string)$request->input('tipo_inscripcion', ''),
					'turno' => $turno,
					'semestre' => $semestre,
					'meses_descuento' => $request->input('meses'),
					'estado' => true,
				];

				foreach ((array)$request->input('cuotas', []) as $c) {
					$row = $detBase;
					$asig = AsignacionCostos::where('cod_pensum', (string)$request->input('cod_pensum'))
						->where('cod_inscrip', (int)$request->input('cod_inscrip'))
						->where('numero_cuota', (int)($c['numero_cuota'] ?? 0))
						->first();
					$row['id_cuota'] = $asig ? (int)$asig->id_asignacion_costo : null;
					if (isset($c['observaciones']) && $c['observaciones'] !== '') $row['observaciones'] = (string)$c['observaciones'];
					// Validación de monto_descuento contra saldo referencial por cuota
					$reqMonto = isset($c['monto_descuento']) ? (float)$c['monto_descuento'] : 0.0;
					$canApply = true;
					if (!$asig) { $canApply = false; }
					else {
						$bruto = (float)($asig->monto ?? 0);
						$pagado = (float)($asig->monto_pagado ?? 0);
						$descExist = 0.0;
						try {
							$idDetExist = (int)($asig->id_descuentoDetalle ?? 0);
							if ($idDetExist) {
								$detExist = DescuentoDetalle::where('id_descuento_detalle', $idDetExist)->first(['monto_descuento']);
								$descExist = $detExist ? (float)($detExist->monto_descuento ?? 0) : 0.0;
							} else {
								$detExist = DescuentoDetalle::where('id_cuota', (int)$asig->id_asignacion_costo)->orderByDesc('id_descuento_detalle')->first(['monto_descuento']);
								$descExist = $detExist ? (float)($detExist->monto_descuento ?? 0) : 0.0;
							}
						} catch (\Throwable $e) { $descExist = 0.0; }
						$neto = max(0.0, $bruto - $descExist);
						$saldoRef = max(0.0, $neto - $pagado);
						if ($reqMonto <= 0.0 || $reqMonto > $saldoRef) { $canApply = false; }
					}
					if (!$canApply) { continue; }
					$row['monto_descuento'] = $reqMonto;
					$det = DescuentoDetalle::create($row);
					$rows[] = $det;
					try {
						AsignacionCostos::where('cod_pensum', (string)$request->input('cod_pensum'))
							->where('cod_inscrip', (int)$request->input('cod_inscrip'))
							->where('numero_cuota', (int)($c['numero_cuota'] ?? 0))
							->update(['id_descuentoDetalle' => (int)$det->id_descuento_detalle]);
					} catch (\Throwable $e) { }
				}
				return [ $descuento, $rows ];
			});

			return response()->json(['success' => true, 'message' => 'Descuento asignado correctamente', 'data' => ['descuento' => $descuento, 'detalles' => $detRows]], 201);
		} catch (\Exception $e) {
			return response()->json(['success' => false, 'message' => 'Error al asignar descuento: ' . $e->getMessage()], 500);
		}
	}
}
