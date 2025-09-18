<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cobro;
use App\Models\Estudiante;
use App\Models\Inscripcion;
use App\Models\AsignacionCostos;
use App\Models\CostoSemestral;
use App\Models\Gestion;
use App\Models\ParametroCosto;
use App\Models\Cuota;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CobroController extends Controller
{
	public function index()
	{
		try {
			$cobros = Cobro::with(['usuario', 'cuota', 'formaCobro', 'cuentaBancaria', 'itemCobro', 'detalleRegular', 'detalleMulta'])
				->get();
			return response()->json([
				'success' => true,
				'data' => $cobros
			]);
		} catch (\Exception $e) {
			return response()->json([
				'success' => false,
				'message' => 'Error al obtener cobros: ' . $e->getMessage()
			], 500);
		}
	}

	public function store(Request $request)
	{
		try {
			$validator = Validator::make($request->all(), [
				'cod_ceta' => 'required|integer',
				'cod_pensum' => 'required|string|max:50',
				'tipo_inscripcion' => 'required|string|max:255',
				'nro_cobro' => 'required|integer',
				'monto' => 'required|numeric|min:0',
				'fecha_cobro' => 'required|date',
				'cobro_completo' => 'nullable|boolean',
				'observaciones' => 'nullable|string',
				'id_usuario' => 'required|integer|exists:usuarios,id_usuario',
				'id_forma_cobro' => 'required|string|exists:formas_cobro,id_forma_cobro',
				'pu_mensualidad' => 'required|numeric|min:0',
				'order' => 'required|integer',
				'descuento' => 'nullable|string|max:255',
				'id_cuentas_bancarias' => 'nullable|integer|exists:cuentas_bancarias,id_cuentas_bancarias',
				'nro_factura' => 'nullable|integer',
				'nro_recibo' => 'nullable|integer',
				'id_item' => 'nullable|integer|exists:items_cobro,id_item',
				'id_asignacion_costo' => 'nullable|integer',
				'id_cuota' => 'nullable|integer|exists:cuotas,id_cuota',
				'gestion' => 'nullable|string|max:255',
			]);

			if ($validator->fails()) {
				return response()->json([
					'success' => false,
					'message' => 'Error de validación',
					'errors' => $validator->errors()
				], 422);
			}

			// Verificar que no exista el registro con la misma clave compuesta
			$exists = Cobro::where('cod_ceta', $request->cod_ceta)
				->where('cod_pensum', $request->cod_pensum)
				->where('tipo_inscripcion', $request->tipo_inscripcion)
				->where('nro_cobro', $request->nro_cobro)
				->exists();
			if ($exists) {
				return response()->json([
					'success' => false,
					'message' => 'Ya existe un cobro con esa clave compuesta.'
				], 409);
			}

			$cobro = Cobro::create($request->all());

			return response()->json([
				'success' => true,
				'message' => 'Cobro creado correctamente',
				'data' => $cobro->load(['usuario', 'cuota', 'formaCobro', 'cuentaBancaria', 'itemCobro', 'detalleRegular', 'detalleMulta'])
			], 201);
		} catch (\Exception $e) {
			return response()->json([
				'success' => false,
				'message' => 'Error al crear cobro: ' . $e->getMessage()
			], 500);
		}
	}

	public function show($cod_ceta, $cod_pensum, $tipo_inscripcion, $nro_cobro)
	{
		try {
			$cobro = Cobro::with(['usuario', 'cuota', 'formaCobro', 'cuentaBancaria', 'itemCobro', 'detalleRegular', 'detalleMulta'])
				->where('cod_ceta', $cod_ceta)
				->where('cod_pensum', $cod_pensum)
				->where('tipo_inscripcion', $tipo_inscripcion)
				->where('nro_cobro', $nro_cobro)
				->first();

			if (!$cobro) {
				return response()->json([
					'success' => false,
					'message' => 'Cobro no encontrado'
				], 404);
			}

			return response()->json([
				'success' => true,
				'data' => $cobro
			]);
		} catch (\Exception $e) {
			return response()->json([
				'success' => false,
				'message' => 'Error al obtener cobro: ' . $e->getMessage()
			], 500);
		}
	}

	public function update(Request $request, $cod_ceta, $cod_pensum, $tipo_inscripcion, $nro_cobro)
	{
		try {
			$cobro = Cobro::where('cod_ceta', $cod_ceta)
				->where('cod_pensum', $cod_pensum)
				->where('tipo_inscripcion', $tipo_inscripcion)
				->where('nro_cobro', $nro_cobro)
				->first();

			if (!$cobro) {
				return response()->json([
					'success' => false,
					'message' => 'Cobro no encontrado'
				], 404);
			}

			$validator = Validator::make($request->all(), [
				'monto' => 'sometimes|numeric|min:0',
				'fecha_cobro' => 'sometimes|date',
				'cobro_completo' => 'nullable|boolean',
				'observaciones' => 'nullable|string',
				'id_usuario' => 'sometimes|integer|exists:usuarios,id_usuario',
				'id_forma_cobro' => 'sometimes|string|exists:formas_cobro,id_forma_cobro',
				'pu_mensualidad' => 'sometimes|numeric|min:0',
				'order' => 'sometimes|integer',
				'descuento' => 'nullable|string|max:255',
				'id_cuentas_bancarias' => 'nullable|integer|exists:cuentas_bancarias,id_cuentas_bancarias',
				'nro_factura' => 'nullable|integer',
				'nro_recibo' => 'nullable|integer',
				'id_item' => 'nullable|integer|exists:items_cobro,id_item',
				'id_asignacion_costo' => 'nullable|integer',
				'id_cuota' => 'nullable|integer|exists:cuotas,id_cuota',
				'gestion' => 'nullable|string|max:255',
			]);

			if ($validator->fails()) {
				return response()->json([
					'success' => false,
					'message' => 'Error de validación',
					'errors' => $validator->errors()
				], 422);
			}

			$cobro->update($request->all());

			return response()->json([
				'success' => true,
				'message' => 'Cobro actualizado correctamente',
				'data' => $cobro->fresh()->load(['usuario', 'cuota', 'formaCobro', 'cuentaBancaria', 'itemCobro', 'detalleRegular', 'detalleMulta'])
			]);
		} catch (\Exception $e) {
			return response()->json([
				'success' => false,
				'message' => 'Error al actualizar cobro: ' . $e->getMessage()
			], 500);
		}
	}

	/**
	 * Resumen de pagos y deudas por estudiante y gestión
	 */
	public function resumen(Request $request)
	{
		try {
			$validator = Validator::make($request->all(), [
				'cod_ceta' => 'required|integer',
				'gestion' => 'nullable|string|max:255',
			]);
			if ($validator->fails()) {
				return response()->json([
					'success' => false,
					'message' => 'Error de validación',
					'errors' => $validator->errors()
				], 422);
			}

			$codCeta = (int) $request->input('cod_ceta');
			$gestionReq = $request->input('gestion');
			// Gestion inicial (podría ser null). La gestion final se determinará tras cargar la inscripción
			$gestion = $gestionReq ?: optional(Gestion::gestionActual())->gestion;
			$warnings = [];

			$estudiante = Estudiante::with('pensum')->find($codCeta);
			if (!$estudiante) {
				return response()->json([
					'success' => false,
					'message' => 'Estudiante no encontrado'
				], 404);
			}

			// Obtener todas las inscripciones por gestión para el estudiante (NORMAL y ARRASTRE)
			$inscripciones = Inscripcion::where('cod_ceta', $codCeta)
				->when($gestion, function ($q) use ($gestion) {
					$q->where('gestion', $gestion);
				})
				->orderByDesc('fecha_inscripcion')
				->orderByDesc('created_at')
				->get();
			// Manejo explícito: si no hay inscripciones
			if ($inscripciones->isEmpty()) {
				if ($gestionReq) {
					return response()->json([
						'success' => false,
						'message' => 'El estudiante no tiene inscripción en la gestión solicitada',
					], 404);
				}
				// Fallback automático: usar la última inscripción disponible
				$ultima = Inscripcion::where('cod_ceta', $codCeta)
					->orderByDesc('fecha_inscripcion')
					->orderByDesc('created_at')
					->first();
				if ($ultima) {
					$inscripciones = Inscripcion::where('cod_ceta', $codCeta)
						->where('gestion', $ultima->gestion)
						->orderByDesc('fecha_inscripcion')
						->orderByDesc('created_at')
						->get();
					$warnings[] = 'No hay inscripción en la gestión actual; se usó la última disponible: ' . $ultima->gestion;
					$gestion = $ultima->gestion;
				} else {
					return response()->json([
						'success' => false,
						'message' => 'El estudiante no posee inscripciones registradas',
					], 404);
				}
			}

			// Determinar gestión a usar en cálculos y respuesta
			$gestionToUse = $gestion ?: optional(Gestion::gestionActual())->gestion;
			// Seleccionar inscripción principal: priorizar NORMAL si existe, caso contrario la primera
			$primaryInscripcion = $inscripciones->firstWhere('tipo_inscripcion', 'NORMAL') ?: $inscripciones->first();
			// Determinar pensum a usar (desde la inscripción principal, si existe)
			$codPensumToUse = optional($primaryInscripcion)->cod_pensum ?: optional($estudiante)->cod_pensum;

			$costoSemestral = null;
			if ($gestionToUse) {
				$costoSemestral = CostoSemestral::where('cod_pensum', $codPensumToUse)
					->where('gestion', $gestionToUse)
					->first();
			}

			$asignacion = null;
			if ($primaryInscripcion && $costoSemestral) {
				$asignacion = AsignacionCostos::where('cod_pensum', $codPensumToUse)
					->where('cod_inscrip', $primaryInscripcion->cod_inscrip)
					->where('id_costo_semestral', $costoSemestral->id_costo_semestral)
					->first();
			}

			$cobrosBase = Cobro::where('cod_ceta', $codCeta)
				->where('cod_pensum', $codPensumToUse)
				->when($gestionToUse, function ($q) use ($gestionToUse) {
					$q->where('gestion', $gestionToUse);
				});

			$cobrosMensualidad = (clone $cobrosBase)->whereNotNull('id_cuota')->get();
			$cobrosItems = (clone $cobrosBase)->whereNotNull('id_item')->get();
			$totalMensualidad = $cobrosMensualidad->sum('monto');
			$totalItems = $cobrosItems->sum('monto');

			// Parámetros y cálculo de monto/nro_cuotas/pu
			$paramMonto = null;        // MONTO_SEMESTRAL_FIJO
			$paramNroCuotas = null;    // NRO_CUOTAS
			if (!$costoSemestral && $gestionToUse) {
				$pcQuery = ParametroCosto::query();
				if (Schema::hasColumn('parametros_costos', 'gestion')) {
					$pcQuery->where('gestion', $gestionToUse);
				}
				$pcQuery->where('nombre', 'MONTO_SEMESTRAL_FIJO');
				if (Schema::hasColumn('parametros_costos', 'estado')) {
					$pcQuery->where('estado', true);
				}
				$paramMonto = $pcQuery->first();
			}
			// Calcular NRO_CUOTAS con fallback a parametros_costos
			$paramNroCuotas = null;
			$nroCuotasFromTable = 0;
			if ($gestionToUse && Schema::hasColumn('cuotas', 'gestion')) {
				$nroCuotasFromTable = (int) Cuota::where('gestion', $gestionToUse)->count();
			}
			if ($nroCuotasFromTable === 0 && $gestionToUse) {
				$pcQuery2 = ParametroCosto::query();
				if (Schema::hasColumn('parametros_costos', 'gestion')) {
					$pcQuery2->where('gestion', $gestionToUse);
				}
				$pcQuery2->where('nombre', 'NRO_CUOTAS');
				if (Schema::hasColumn('parametros_costos', 'estado')) {
					$pcQuery2->where('estado', true);
				}
				$paramNroCuotas = $pcQuery2->first();
			}
			$nroCuotas = $nroCuotasFromTable > 0 ? $nroCuotasFromTable : ($paramNroCuotas ? (int) round((float) $paramNroCuotas->valor) : null);

			// Calcular monto del semestre, saldo y precio unitario mensual
			$montoSemestre = optional($costoSemestral)->monto_semestre
				?: optional($asignacion)->monto
				?: ($paramMonto ? (float) $paramMonto->valor : null);
			$saldoMensualidad = isset($montoSemestre) ? (float) $montoSemestre - (float) $totalMensualidad : null;
			$puMensual = ($montoSemestre !== null && $nroCuotas) ? round(((float) $montoSemestre) / max(1, $nroCuotas), 2) : null;

			// Documentos presentados del estudiante y deducción de documento de identidad
			$documentosPresentados = collect();
			$documentoIdentidad = null;
			try {
				if (Schema::hasTable('doc_presentados')) {
					$documentosPresentados = DB::table('doc_presentados')
						->where('cod_ceta', $codCeta)
						->select('id_doc_presentados','cod_ceta','numero_doc','nombre_doc','procedencia','entregado')
						->get();
					// Mapeo a tipo_identidad del frontend (prioridad: CI, CEX, PAS, NIT)
					$mapOrder = [
						[
							'tipo' => 1, // CI
							'aliases' => [
								'CI -', ' CI ', 'CI', 'CARNET DE IDENTIDAD', 'CÉDULA DE IDENTIDAD', 'CEDULA DE IDENTIDAD'
							]
						],
						[
							'tipo' => 2, // CEX
							'aliases' => [
								'CEX -', ' CEX ', 'CEX', 'CÉDULA DE IDENTIDAD DE EXTRANJERO', 'CEDULA DE IDENTIDAD DE EXTRANJERO'
							]
						],
						[
							'tipo' => 3, // PAS
							'aliases' => [
								'PAS -', ' PAS ', 'PAS', 'PASAPORTE'
							]
						],
						[
							'tipo' => 5, // NIT
							'aliases' => [
								'NIT -', ' NIT ', 'NIT', 'NUMERO DE IDENTIFICACION TRIBUTARIA', 'NÚMERO DE IDENTIFICACIÓN TRIBUTARIA'
							]
						],
					];
					$upperDocs = $documentosPresentados->map(function($d){
						$d->nombre_doc_upper = mb_strtoupper((string)($d->nombre_doc ?? ''));
						return $d;
					});
					foreach ($mapOrder as $m) {
						$match = $upperDocs->first(function($d) use ($m){
							$hay = false;
							foreach ($m['aliases'] as $alias) {
								$aliasU = mb_strtoupper($alias);
								if (str_starts_with($d->nombre_doc_upper, $aliasU) || str_contains($d->nombre_doc_upper, $aliasU)) {
									$hay = true; break;
								}
							}
							return $hay;
						});
						if ($match) {
							$documentoIdentidad = [
								'tipo_identidad' => $m['tipo'],
								'numero' => (string)($match->numero_doc ?? ''),
								'nombre_doc' => (string)($match->nombre_doc ?? ''),
							];
							break;
						}
					}
				}
			} catch (\Throwable $e) {
				// No bloquear el resumen si falla esta parte; solo agregar warning
				$warnings[] = 'No se pudo leer documentos presentados: ' . $e->getMessage();
			}
			return response()->json([
				'success' => true,
				'data' => [
					'estudiante' => $estudiante,
					'inscripciones' => $inscripciones,
					'inscripcion' => $primaryInscripcion,
					'gestion' => $gestionToUse,
					'costo_semestral' => $costoSemestral,
					'parametros_costos' => [
						'monto_fijo' => $paramMonto,
						'nro_cuotas' => $paramNroCuotas,
					],
					'asignacion_costos' => $asignacion,
					'cobros' => [
						'mensualidad' => [
							'total' => (float) $totalMensualidad,
							'count' => $cobrosMensualidad->count(),
							'items' => $cobrosMensualidad,
						],
						'items' => [
							'total' => (float) $totalItems,
							'count' => $cobrosItems->count(),
							'items' => $cobrosItems,
						],
					],
					'totales' => [
						'monto_semestral' => isset($montoSemestre) ? (float)$montoSemestre : null,
						'saldo_mensualidad' => $saldoMensualidad,
						'total_pagado' => (float) ($totalMensualidad + $totalItems),
						'nro_cuotas' => $nroCuotas,
						'pu_mensual' => $puMensual,
					],
					'documentos_presentados' => $documentosPresentados,
					'documento_identidad' => $documentoIdentidad,
					'warnings' => $warnings,
				]
			]);
		} catch (\Exception $e) {
			return response()->json([
				'success' => false,
				'message' => 'Error al generar resumen: ' . $e->getMessage()
			], 500);
		}
	}

	/**
	 * Registro por lote de cobros
	 */
	public function batchStore(Request $request)
	{
		$rules = [
			'cod_ceta' => 'required|integer',
			'cod_pensum' => 'required|string|max:50',
			'tipo_inscripcion' => 'required|string|max:255',
			'gestion' => 'nullable|string|max:255',
			'id_usuario' => 'required|integer|exists:usuarios,id_usuario',
			'id_forma_cobro' => 'required|string|exists:formas_cobro,id_forma_cobro',
			'id_cuentas_bancarias' => 'nullable|integer|exists:cuentas_bancarias,id_cuentas_bancarias',
			'pagos' => 'required|array|min:1',
			'pagos.*.nro_cobro' => 'required|integer',
			'pagos.*.monto' => 'required|numeric|min:0',
			'pagos.*.fecha_cobro' => 'required|date',
			'pagos.*.pu_mensualidad' => 'nullable|numeric|min:0',
			'pagos.*.order' => 'nullable|integer',
			'pagos.*.descuento' => 'nullable|string|max:255',
			'pagos.*.observaciones' => 'nullable|string',
			'pagos.*.nro_factura' => 'nullable|integer',
			'pagos.*.nro_recibo' => 'nullable|integer',
			'pagos.*.id_item' => 'nullable|integer|exists:items_cobro,id_item',
			'pagos.*.id_asignacion_costo' => 'nullable|integer',
			'pagos.*.id_cuota' => 'nullable|integer|exists:cuotas,id_cuota',
		];

		$validator = Validator::make($request->all(), $rules);
		if ($validator->fails()) {
			return response()->json([
				'success' => false,
				'message' => 'Error de validación',
				'errors' => $validator->errors(),
			], 422);
		}

		try {
			$created = [];
			DB::transaction(function () use ($request, & $created) {
				foreach ($request->input('pagos') as $pago) {
					$composite = [
						'cod_ceta' => (int)$request->cod_ceta,
						'cod_pensum' => (string)$request->cod_pensum,
						'tipo_inscripcion' => (string)$request->tipo_inscripcion,
						'nro_cobro' => (int)$pago['nro_cobro'],
					];
					$exists = Cobro::where($composite)->exists();
					if ($exists) {
						throw new \RuntimeException('Cobro duplicado para nro_cobro: ' . $pago['nro_cobro']);
					}
					$payload = array_merge($composite, [
						'monto' => $pago['monto'],
						'fecha_cobro' => $pago['fecha_cobro'],
						'cobro_completo' => $pago['cobro_completo'] ?? null,
						'observaciones' => $pago['observaciones'] ?? null,
						'id_usuario' => (int)$request->id_usuario,
						'id_forma_cobro' => (string)$request->id_forma_cobro,
						'pu_mensualidad' => $pago['pu_mensualidad'] ?? 0,
						'order' => $pago['order'] ?? 0,
						'descuento' => $pago['descuento'] ?? null,
						'id_cuentas_bancarias' => $request->id_cuentas_bancarias ?? null,
						'nro_factura' => $pago['nro_factura'] ?? null,
						'nro_recibo' => $pago['nro_recibo'] ?? null,
						'id_item' => $pago['id_item'] ?? null,
						'id_asignacion_costo' => $pago['id_asignacion_costo'] ?? null,
						'id_cuota' => $pago['id_cuota'] ?? null,
						'gestion' => $request->gestion ?? null,
					]);
					$created[] = Cobro::create($payload)->load(['usuario', 'cuota', 'formaCobro', 'cuentaBancaria', 'itemCobro']);
				}
			});

			return response()->json([
				'success' => true,
				'message' => 'Cobros creados correctamente',
				'data' => $created,
			], 201);
		} catch (\Throwable $e) {
			return response()->json([
				'success' => false,
				'message' => 'Error al crear cobros por lote: ' . $e->getMessage(),
			], 500);
		}
	}

	public function destroy($cod_ceta, $cod_pensum, $tipo_inscripcion, $nro_cobro)
	{
		try {
			$cobro = Cobro::where('cod_ceta', $cod_ceta)
				->where('cod_pensum', $cod_pensum)
				->where('tipo_inscripcion', $tipo_inscripcion)
				->where('nro_cobro', $nro_cobro)
				->first();

			if (!$cobro) {
				return response()->json([
					'success' => false,
					'message' => 'Cobro no encontrado'
				], 404);
			}

			$cobro->delete();

			return response()->json([
				'success' => true,
				'message' => 'Cobro eliminado correctamente'
			]);
		} catch (\Exception $e) {
			return response()->json([
				'success' => false,
				'message' => 'Error al eliminar cobro: ' . $e->getMessage()
			], 500);
		}
	}
}
