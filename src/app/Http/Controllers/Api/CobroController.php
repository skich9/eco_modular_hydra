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
use Illuminate\Support\Facades\Log;
use App\Repositories\Sin\CuisRepository;
use App\Repositories\Sin\CufdRepository;
use App\Services\ReciboService;
use App\Services\FacturaService;
use App\Services\Siat\OperationsService;
use App\Services\Siat\CufGenerator;
use App\Services\Siat\FacturaPayloadBuilder;
use App\Services\Qr\QrSocketNotifier;

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

	private function calcularMesesPorGestion($gestion)
	{
		$map = [];
		try {
			$sem = null;
			if (is_string($gestion) && strpos($gestion, '/') !== false) {
				$parts = explode('/', $gestion);
				$sem = (int) trim($parts[0] ?? '');
			}
			if ($sem === 1) {
				$meses = [2,3,4,5,6];
			} elseif ($sem === 2) {
				$meses = [7,8,9,10,11];
			} else {
				// Fallback: devolver vacío si no se reconoce formato
				$meses = [];
			}
			$names = [
				1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',
				7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'
			];
			foreach ($meses as $idx => $mesNum) {
				$cuota = $idx + 1; // cuotas 1..5
				$map[] = [ 'numero_cuota' => $cuota, 'mes_num' => $mesNum, 'mes_nombre' => $names[$mesNum] ?? (string)$mesNum ];
			}
		} catch (\Throwable $e) { /* noop */ }
		return $map;
	}

	public function store(Request $request)
	{
		try {
			$validator = Validator::make($request->all(), [
				'cod_ceta' => 'required|integer',
				'cod_pensum' => 'required|string|max:50',
				'tipo_inscripcion' => 'required|string|max:255',
				'cod_inscrip' => 'nullable|integer',
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

			// Generar correlativo atómico por año para nro_cobro
			$anioCobro = (int) date('Y', strtotime((string)$request->fecha_cobro));
			$scopeCobro = 'COBRO:' . $anioCobro;
			DB::statement(
				"INSERT INTO doc_counter (scope, last, created_at, updated_at) VALUES (?, 1, NOW(), NOW())\n"
				. "ON DUPLICATE KEY UPDATE last = LAST_INSERT_ID(last + 1), updated_at = NOW()",
				[$scopeCobro]
			);
			$row = DB::selectOne('SELECT LAST_INSERT_ID() AS id');
			$nroCobro = (int)($row->id ?? 0);
			$data = $request->all();
			$data['nro_cobro'] = $nroCobro;
			$data['anio_cobro'] = $anioCobro;
			$cobro = Cobro::create($data);

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

			// Identificar (si existe) la inscripción ARRASTRE en la gestión seleccionada
			$arrastreInscripcion = $inscripciones->firstWhere('tipo_inscripcion', 'ARRASTRE');

			$costoSemestral = null;
			$recuperacionRow = null; $recuperacionMonto = null;
			if ($gestionToUse) {
				$costoSemestral = CostoSemestral::where('cod_pensum', $codPensumToUse)
					->where('gestion', $gestionToUse)
					->first();
				// Prueba de Recuperación: buscar por varias variantes de tipo_costo con fallback seguro
				try {
					$rows = DB::table('costo_semestral')
						->where('cod_pensum', $codPensumToUse)
						->where('gestion', $gestionToUse)
						->get();
					if ($rows && $rows->count()) {
						$norm = function($s){ $s = (string)$s; $s = @iconv('UTF-8','ASCII//TRANSLIT',$s); return strtoupper(trim($s)); };
						$pick = $rows->first(function($r) use ($norm){
							$tc = $norm($r->tipo_costo ?? '');
							return $tc === 'INSTACIA' || $tc === 'INSTANCIA' || $tc === 'RECUPERACION' || strpos($tc, 'SEGUNDA') !== false;
						});
						if (!$pick) {
							$pick = $rows->first(function($r) use ($norm){ $tc = $norm($r->tipo_costo ?? ''); return (strpos($tc,'RECUP') !== false) || (strpos($tc,'INSTANC') !== false); });
						}
						if ($pick) {
							$recuperacionRow = $pick;
							$recuperacionMonto = isset($pick->monto_semestre) ? (float)$pick->monto_semestre : (isset($pick->monto) ? (float)$pick->monto : null);
						}
					}
				} catch (\Throwable $e) {
					// no bloquear resumen por error en recuperación
				}
			}

			$asignacion = null;
			if ($primaryInscripcion && $costoSemestral) {
				$asignacion = AsignacionCostos::where('cod_pensum', $codPensumToUse)
					->where('cod_inscrip', $primaryInscripcion->cod_inscrip)
					->where('id_costo_semestral', $costoSemestral->id_costo_semestral)
					->first();
			}

			// Colección de asignaciones de costos (todas las cuotas) para la inscripción principal
			$asignacionesPrimarias = collect();
			if ($primaryInscripcion) {
				$queryAsign = AsignacionCostos::where('cod_pensum', $codPensumToUse)
					->where('cod_inscrip', $primaryInscripcion->cod_inscrip);
				$asignacionesPrimarias = $queryAsign->orderBy('numero_cuota')->get();
			}

			$cobrosBase = Cobro::where('cod_ceta', $codCeta)
				->where('cod_pensum', $codPensumToUse)
				->when($gestionToUse, function ($q) use ($gestionToUse) {
					$q->where('gestion', $gestionToUse);
				})
				->when($primaryInscripcion, function ($q) use ($primaryInscripcion) {
					$q->where('tipo_inscripcion', $primaryInscripcion->tipo_inscripcion);
				});

			$cobrosMensualidad = (clone $cobrosBase)
				->where(function($q){
					$q->whereNotNull('id_cuota')
						->orWhereNotNull('id_asignacion_costo');
				})
				->get();
			$cobrosItems = (clone $cobrosBase)->whereNotNull('id_item')->get();
			$totalMensualidad = $cobrosMensualidad->sum('monto');
			$totalItems = $cobrosItems->sum('monto');

			// Próxima mensualidad a pagar con prioridad a PARCIAL; exponer 'parcial_count'
			$mensualidadNext = null; $mensualidadPendingCount = 0; $mensualidadTotalCuotas = $asignacionesPrimarias->count();
			$parciales = $asignacionesPrimarias->filter(function($a){ return (string)($a->estado_pago ?? '') === 'PARCIAL'; })->sortBy('numero_cuota')->values();
			$parcialCount = $parciales->count();
			if ($mensualidadTotalCuotas > 0) {
				$orderedAsign = $asignacionesPrimarias->values(); // ya está ordenado por numero_cuota asc
				$nextParcial = $parciales->first();
				if ($nextParcial) {
					$restante = max(0, (float)$nextParcial->monto - (float)($nextParcial->monto_pagado ?? 0));
					$mensualidadNext = [
						'numero_cuota' => (int) $nextParcial->numero_cuota,
						'monto' => (float) $restante, // para UI usamos directamente el restante
						'original_monto' => (float) $nextParcial->monto,
						'monto_pagado' => (float) ($nextParcial->monto_pagado ?? 0),
						'id_asignacion_costo' => (int) ($nextParcial->id_asignacion_costo ?? 0) ?: null,
						'id_cuota_template' => isset($nextParcial->id_cuota_template) ? ((int)$nextParcial->id_cuota_template ?: null) : null,
						'fecha_vencimiento' => $nextParcial->fecha_vencimiento,
						'estado_pago' => 'PARCIAL',
					];
				} else {
					// No hay PARCIAL: elegir la primera no cobrada (pendiente)
					$nextPend = $orderedAsign->first(function($a){ return (string)($a->estado_pago ?? '') !== 'COBRADO'; });
					if ($nextPend) {
						$restante = max(0, (float)$nextPend->monto - (float)($nextPend->monto_pagado ?? 0));
						$mensualidadNext = [
							'numero_cuota' => (int) $nextPend->numero_cuota,
							'monto' => (float) ($restante > 0 ? $restante : (float)$nextPend->monto),
							'original_monto' => (float) $nextPend->monto,
							'monto_pagado' => (float) ($nextPend->monto_pagado ?? 0),
							'id_asignacion_costo' => (int) ($nextPend->id_asignacion_costo ?? 0) ?: null,
							'id_cuota_template' => isset($nextPend->id_cuota_template) ? ((int)$nextPend->id_cuota_template ?: null) : null,
							'fecha_vencimiento' => $nextPend->fecha_vencimiento,
							'estado_pago' => (string)($nextPend->estado_pago ?? 'PENDIENTE'),
						];
					}

					// Pending count: solo cuotas en estado pendiente (excluye PARCIAL y COBRADO)
					$mensualidadPendingCount = $asignacionesPrimarias->filter(function($a){
						$st = (string)($a->estado_pago ?? '');
						return $st !== 'COBRADO' && $st !== 'PARCIAL';
					})->count();
				}
				// Pending count: solo cuotas en estado pendiente (excluye PARCIAL y COBRADO)
				$mensualidadPendingCount = $asignacionesPrimarias->filter(function($a){
					$st = (string)($a->estado_pago ?? '');
					return $st !== 'COBRADO' && $st !== 'PARCIAL';
				})->count();
			}

			// Construir resumen para ARRASTRE: próxima cuota pendiente desde asignacion_costos
			$arrastreSummary = null;
			if ($arrastreInscripcion) {
				try {
					$asignaciones = AsignacionCostos::query()
						->where('cod_pensum', (string) $arrastreInscripcion->cod_pensum)
						->where('cod_inscrip', (int) $arrastreInscripcion->cod_inscrip)
						->orderBy('numero_cuota')
						->get();
					$paidCuotaIds = $cobrosMensualidad->pluck('id_cuota')->filter()->map(fn($v) => (int)$v)->unique()->values();
					$next = null; $pendingCount = 0; $totalCuotas = $asignaciones->count();
					foreach ($asignaciones as $asig) {
						$tplId = (int) ($asig->id_cuota_template ?? 0);
						$pagada = $tplId ? $paidCuotaIds->contains($tplId) : false;
						if (!$pagada) {
							$pendingCount++;
							if (!$next) {
								$next = [
									'numero_cuota' => (int) $asig->numero_cuota,
									'monto' => (float) $asig->monto,
									'id_asignacion_costo' => (int) $asig->id_asignacion_costo,
									'id_cuota_template' => $tplId ?: null,
									'fecha_vencimiento' => $asig->fecha_vencimiento,
								];
							}
						}
					}
					$arrastreSummary = [
						'has' => true,
						'inscripcion' => $arrastreInscripcion,
						'next_cuota' => $next,
						'pending_count' => $pendingCount,
						'total_cuotas' => $totalCuotas,
					];
				} catch (\Throwable $e) {
					$arrastreSummary = [ 'has' => false, 'error' => $e->getMessage() ];
				}
			}

			// Parámetros y cálculo de monto/nro_cuotas/pu
			$paramMonto = null;        // MONTO_SEMESTRAL_FIJO
			$paramNroCuotas = null;    // NRO_CUOTAS
			if (!$costoSemestral && $gestionToUse) {
				if (Schema::hasColumn('parametros_costos', 'nombre') && Schema::hasColumn('parametros_costos', 'valor')) {
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
			}
			// Calcular NRO_CUOTAS con prioridad desde asignacion_costos y fallback a parametros_costos/cuotas
			$paramNroCuotas = null;
			$nroCuotasFromTable = 0;
			$nroCuotasFromAsignacion = $asignacionesPrimarias->count();
			if ($gestionToUse && Schema::hasColumn('cuotas', 'gestion')) {
				$nroCuotasFromTable = (int) Cuota::where('gestion', $gestionToUse)->count();
			}
			if ($nroCuotasFromTable === 0 && $gestionToUse) {
				if (Schema::hasColumn('parametros_costos', 'nombre') && Schema::hasColumn('parametros_costos', 'valor')) {
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
			}
			// Selección final de número de cuotas:
			// 1) usar asignaciones del estudiante si existen
			// 2) si no, usar ParametroCosto NRO_CUOTAS
			// 3) en última instancia, usar cantidad de filas en 'cuotas' para la gestión solo si es razonable (<= 12)
			$nroCuotas = null;
			if ($nroCuotasFromAsignacion > 0) {
				$nroCuotas = $nroCuotasFromAsignacion;
			} elseif ($paramNroCuotas) {
				$nroCuotas = (int) round((float) $paramNroCuotas->valor);
			} elseif ($nroCuotasFromTable > 0 && $nroCuotasFromTable <= 12) {
				$nroCuotas = $nroCuotasFromTable;
			}

			// Calcular monto del semestre, saldo y precio unitario mensual
			// Preferencia: sumar todos los montos de asignacion_costos para TODAS las inscripciones del estudiante en la gestión seleccionada
			$montoSemestralFromAsignGestion = null;
			try {
				$inscripIds = $inscripciones->pluck('cod_inscrip')->filter()->map(fn($v) => (int)$v)->values();
				if ($inscripIds->count() > 0 && Schema::hasTable('asignacion_costos')) {
					$montoSemestralFromAsignGestion = (float) DB::table('asignacion_costos')
						->whereIn('cod_inscrip', $inscripIds)
						->sum('monto');
				}
			} catch (\Throwable $e) { /* fallback automático abajo */ }
			$montoSemestre = ($montoSemestralFromAsignGestion !== null && $montoSemestralFromAsignGestion > 0)
				? $montoSemestralFromAsignGestion
				: (optional($costoSemestral)->monto_semestre
					?: ($asignacionesPrimarias->count() > 0 ? (float) $asignacionesPrimarias->sum('monto') : null)
					?: ($paramMonto ? (float) $paramMonto->valor : null));
			$saldoMensualidad = isset($montoSemestre) ? (float) $montoSemestre - (float) $totalMensualidad : null;
			$puMensualFromNext = $mensualidadNext ? round((float) ($mensualidadNext['monto'] ?? 0), 2) : null;
			$puMensualFromAsignacion = $asignacionesPrimarias->count() > 0 ? round((float) $asignacionesPrimarias->avg('monto'), 2) : null;
			$puMensualNominal = $puMensualFromAsignacion !== null
				? $puMensualFromAsignacion
				: (($montoSemestre !== null && $nroCuotas) ? round(((float) $montoSemestre) / max(1, $nroCuotas), 2) : null);
			// pu_mensual (para mostrar en UI): si hay PARCIAL, mostrar su restante; si no, mostrar nominal
			$puMensual = $puMensualFromNext !== null ? $puMensualFromNext : $puMensualNominal;

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
			$mensualidadMeses = $this->calcularMesesPorGestion($gestionToUse);

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
					'recuperacion_pendiente' => [
						'has' => $recuperacionMonto !== null,
						'monto' => $recuperacionMonto,
					],
					// Compatibilidad: misma información bajo la clave 'recuperacion'
					'recuperacion' => [
						'has' => $recuperacionMonto !== null,
						'monto' => $recuperacionMonto,
					],
					'asignacion_costos' => $asignacion,
					// Exponer todas las cuotas ordenadas con datos clave para el modal 
					'asignaciones' => $asignacionesPrimarias->map(function($a){
						return [
							'numero_cuota' => (int) ($a->numero_cuota ?? 0),
							'monto' => (float) ($a->monto ?? 0),
							'monto_pagado' => (float) ($a->monto_pagado ?? 0),
							'estado_pago' => (string) ($a->estado_pago ?? ''),
							'id_asignacion_costo' => (int) ($a->id_asignacion_costo ?? 0) ?: null,
							'id_cuota_template' => isset($a->id_cuota_template) ? ((int)$a->id_cuota_template ?: null) : null,
							'fecha_vencimiento' => $a->fecha_vencimiento,
						];
					})->values(),
					'arrastre' => $arrastreSummary,
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
					'mensualidad_next' => $mensualidadNext ? [
						'next_cuota' => $mensualidadNext,
						'pending_count' => $mensualidadPendingCount,
						'parcial_count' => $parcialCount,
						'total_cuotas' => $mensualidadTotalCuotas,
					] : null,
					'totales' => [
						'monto_semestral' => isset($montoSemestre) ? (float)$montoSemestre : null,
						'saldo_mensualidad' => $saldoMensualidad,
						'total_pagado' => (float) ($totalMensualidad + $totalItems),
						'nro_cuotas' => $nroCuotas,
						'pu_mensual' => $puMensual,
						'pu_mensual_nominal' => $puMensualNominal,
					],
					'mensualidad_meses' => $mensualidadMeses,
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
	public function batchStore(Request $request, ReciboService $reciboService, FacturaService $facturaService, CufdRepository $cufdRepo, OperationsService $ops, CufGenerator $cufGen, FacturaPayloadBuilder $payloadBuilder, CuisRepository $cuisRepo)
	{
		$rules = [
			'cod_ceta' => 'required|integer',
			'cod_pensum' => 'required|string|max:50',
			'tipo_inscripcion' => 'required|string|max:255',
			'cod_inscrip' => 'nullable|integer',
			'gestion' => 'nullable|string|max:255',
			'codigo_sucursal' => 'nullable|integer|min:0',
			'codigo_punto_venta' => 'nullable|integer|min:0',
			'id_usuario' => 'required|integer|exists:usuarios,id_usuario',
			'id_forma_cobro' => 'required|string|exists:formas_cobro,id_forma_cobro',
			'id_cuentas_bancarias' => 'nullable|integer|exists:cuentas_bancarias,id_cuentas_bancarias',
			'emitir_online' => 'sometimes|boolean',
			// Aceptar esquema antiguo 'pagos' o nuevo 'items'
			'pagos' => 'sometimes|array|min:1',
			'items' => 'sometimes|array|min:1',
			// Campos por pago/item (duplicados para items.* y pagos.*)
			'items.*.nro_cobro' => 'nullable|integer',
			'items.*.monto' => 'required_with:items|numeric|min:0',
			'items.*.fecha_cobro' => 'required_with:items|date',
			'items.*.pu_mensualidad' => 'nullable|numeric|min:0',
			'items.*.order' => 'nullable|integer',
			'items.*.descuento' => 'nullable|numeric|min:0',
			'items.*.observaciones' => 'nullable|string',
			'items.*.nro_factura' => 'nullable|integer',
			'items.*.nro_recibo' => 'nullable|integer',
			'items.*.id_item' => 'nullable|integer|exists:items_cobro,id_item',
			'items.*.id_asignacion_costo' => 'nullable|integer',
			'items.*.id_cuota' => 'nullable|integer|exists:cuotas,id_cuota',
			'items.*.tipo_documento' => 'nullable|in:F,R',
			'items.*.medio_doc' => 'nullable|in:C,M',
			
			'pagos.*.nro_cobro' => 'nullable|integer',
			'pagos.*.monto' => 'required_with:pagos|numeric|min:0',
			'pagos.*.fecha_cobro' => 'required_with:pagos|date',
			'pagos.*.pu_mensualidad' => 'nullable|numeric|min:0',
			'pagos.*.order' => 'nullable|integer',
			'pagos.*.descuento' => 'nullable|numeric|min:0',
			'pagos.*.observaciones' => 'nullable|string',
			'pagos.*.nro_factura' => 'nullable|integer',
			'pagos.*.nro_recibo' => 'nullable|integer',
			'pagos.*.id_item' => 'nullable|integer|exists:items_cobro,id_item',
			'pagos.*.id_asignacion_costo' => 'nullable|integer',
			'pagos.*.id_cuota' => 'nullable|integer|exists:cuotas,id_cuota',
			'pagos.*.tipo_documento' => 'nullable|in:F,R',
			'pagos.*.medio_doc' => 'nullable|in:C,M',
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
			$results = [];
			// Guardia anti-duplicados SOLO para intentos manuales con marcador QR (no bloquear callback QR)
			try {
				$itemsInput = $request->input('items');
				if (!is_array($itemsInput) || count($itemsInput) === 0) { $itemsInput = $request->input('pagos', []); }
				$hasQrMarker = false;
				foreach ((array)$itemsInput as $it) {
					$obs = isset($it['observaciones']) ? (string)$it['observaciones'] : '';
					if (stripos($obs, '[QR') !== false) { $hasQrMarker = true; break; }
				}
				$qrContext = (bool)$request->boolean('qr_context', false);
				$manualWithQrMarker = (!$qrContext) && $hasQrMarker;
				if ($manualWithQrMarker) {
					$totalMonto = 0.0;
					foreach ($itemsInput as $it) { $totalMonto += (float)($it['monto'] ?? 0); }
					$codCetaGuard = (int) $request->input('cod_ceta');
					if ($codCetaGuard > 0 && $totalMonto > 0) {
						$recentTrx = DB::table('qr_transacciones')
							->where('cod_ceta', $codCetaGuard)
							->where('estado', 'completado')
							->whereBetween('updated_at', [now()->subMinutes(10), now()->addMinutes(1)])
							->orderByDesc('updated_at')
							->first();
						if ($recentTrx && abs(((float)$recentTrx->monto_total) - $totalMonto) < 0.001) {
							$existsSimilar = DB::table('cobro')
								->where('cod_ceta', $codCetaGuard)
								->whereDate('fecha_cobro', date('Y-m-d'))
								->whereBetween('created_at', [now()->subMinutes(10), now()->addMinutes(1)])
								->whereRaw('ABS(monto - ?) < 0.001', [$totalMonto])
								->exists();
							if ($existsSimilar) {
								return response()->json([
									'success' => false,
									'message' => 'Cobro ya registrado recientemente por pago QR',
								], 409);
							}
							// Hay transacción QR completada reciente con el mismo monto pero aún no hay cobro: permitir continuar
							\Log::info('batchStore: allowing manual save after QR completion (no existing cobro)', [
								'cod_ceta' => $codCetaGuard,
								'monto' => $totalMonto,
								'id_qr_transaccion' => $recentTrx->id_qr_transaccion ?? null,
							]);
						}
					}
				}
			} catch (\Throwable $e) { /* guardia best-effort */ }
			$items = $request->input('items');
			if (!is_array($items) || count($items) === 0) {
				$items = $request->input('pagos', []); // compatibilidad
			}
			Log::info('batchStore: start', [ 'count' => count($items) ]);
			// Validación: no mezclar FACTURA (F) y RECIBO (R) en el mismo lote
			try {
				$hasF = false; $hasR = false;
				foreach ((array)$items as $it) {
					$raw = isset($it['tipo_documento']) ? strtoupper(trim((string)$it['tipo_documento'])) : '';
					if ($raw === 'F') { $hasF = true; }
					if ($raw === 'R') { $hasR = true; }
					if ($hasF && $hasR) { break; }
				}
				if ($hasF && $hasR) {
					return response()->json([
						'success' => false,
						'message' => 'No se puede mezclar FACTURA y RECIBO en el mismo registro. Use un único tipo de documento en el lote.',
					], 422);
				}
			} catch (\Throwable $e) { /* no bloquear si la inspección falla */ }
			DB::transaction(function () use ($request, $items, $reciboService, $facturaService, $cufdRepo, $ops, $cufGen, $payloadBuilder, $cuisRepo, & $results) {
				$pv = (int) ($request->input('codigo_punto_venta', 0));
				$sucursal = (int) ($request->input('codigo_sucursal', config('sin.sucursal')));
				$emitirOnline = (bool) $request->boolean('emitir_online', false);
				// Contexto de inscripción principal del estudiante (para derivar asignación de costos)
				$codCetaCtx = (int) $request->cod_ceta;
				$codPensumCtx = (string) $request->cod_pensum;
				$gestionCtx = $request->gestion;
				$primaryInscripcion = Inscripcion::query()
					->where('cod_ceta', $codCetaCtx)
					->when($gestionCtx, function($q) use ($gestionCtx){ $q->where('gestion', $gestionCtx); })
					->orderByDesc('fecha_inscripcion')
					->orderByDesc('created_at')
					->first();
				// Precargar asignaciones y cuotas pagadas para mapear automáticamente si faltan ids
				$asignPrimarias = collect();
				$paidTplIds = collect();
				// Monto aplicado por plantilla de cuota dentro de este batch (para reusar misma cuota en pagos parciales sucesivos)
				$batchPaidByTpl = [];
				if ($primaryInscripcion) {
					$asignPrimarias = AsignacionCostos::where('cod_pensum', $codPensumCtx)
						->where('cod_inscrip', $primaryInscripcion->cod_inscrip)
						->orderBy('numero_cuota')
						->get();
					$paidTplIds = Cobro::where('cod_ceta', $codCetaCtx)
						->where('cod_pensum', $codPensumCtx)
						->where('tipo_inscripcion', (string)$request->tipo_inscripcion)
						->pluck('id_cuota')
						->filter()
						->map(fn($v) => (int)$v)
						->values();
				}
				// Eliminamos tracking por asignación única; usaremos $batchPaidByTpl para decidir la siguiente cuota
				// Preparar nickname de usuario y forma de cobro para anotar en notas
				$usuarioNick = (string) (DB::table('usuarios')->where('id_usuario', (int)$request->id_usuario)->value('nickname') ?? '');
				$formaRow = DB::table('formas_cobro')->where('id_forma_cobro', (string)$request->id_forma_cobro)->first();
				$formaNombre = strtoupper(trim((string)($formaRow->nombre ?? $formaRow->descripcion ?? $formaRow->label ?? '')));
				// Normalizar acentos
				$formaNombre = iconv('UTF-8','ASCII//TRANSLIT',$formaNombre);
				$formaCode = strtoupper(trim((string)($formaRow->id_forma_cobro ?? '')));

				// Control para agrupar Recibos computarizados en un único nro_recibo por transacción
				$nroReciboBatch = null; $anioReciboBatch = null;
				foreach ($items as $idx => $item) {
					// Asignar SIEMPRE un correlativo atómico global para garantizar unicidad
					$anioItem = (int) date('Y', strtotime((string)($item['fecha_cobro'] ?? date('Y-m-d'))));
					$scopeCobro = 'COBRO:' . $anioItem;
					DB::statement(
						"INSERT INTO doc_counter (scope, last, created_at, updated_at) VALUES (?, 1, NOW(), NOW())\n"
						. "ON DUPLICATE KEY UPDATE last = LAST_INSERT_ID(last + 1), updated_at = NOW()",
						[$scopeCobro]
					);
					$row = DB::selectOne('SELECT LAST_INSERT_ID() AS id');
					$nroCobro = (int)($row->id ?? 0);
					Log::info('batchStore:nroCobro', [ 'idx' => $idx, 'nro' => $nroCobro ]);
					$composite = [
						'cod_ceta' => (int)$request->cod_ceta,
						'cod_pensum' => (string)$request->cod_pensum,
						'tipo_inscripcion' => (string)$request->tipo_inscripcion,
						'nro_cobro' => $nroCobro,
						'anio_cobro' => $anioItem,
					];

					$tipoDoc = strtoupper((string)($item['tipo_documento'] ?? 'R'));
					$medioDoc = strtoupper((string)($item['medio_doc'] ?? 'C'));
					Log::info('batchStore:item', [ 'idx' => $idx, 'tipo' => $tipoDoc, 'medio' => $medioDoc ]);

					$formaIdItem = (string)($item['id_forma_cobro'] ?? $request->id_forma_cobro);
					try {
						$formaRowItem = DB::table('formas_cobro')->where('id_forma_cobro', $formaIdItem)->first();
						$formaNombre = strtoupper(trim((string)($formaRowItem->nombre ?? $formaRowItem->descripcion ?? $formaRowItem->label ?? '')));
						$formaNombre = iconv('UTF-8','ASCII//TRANSLIT',$formaNombre);
						$formaCode = strtoupper(trim((string)($formaRowItem->id_forma_cobro ?? '')));
					} catch (\Throwable $e) {}
					try { Log::info('batchStore:forma', [ 'idx' => $idx, 'id_forma_cobro' => $formaIdItem, 'nombre' => $formaNombre ?? null, 'code' => $formaCode ?? null ]); } catch (\Throwable $e) {}

					// Permitir inserción desde submit manual para QR/TRANSFERENCIA; el callback ya no inserta

					$nroRecibo = $item['nro_recibo'] ?? null;
					$nroFactura = $item['nro_factura'] ?? null;
					$cliente = $request->input('cliente', []);
					// Usar el código de tipo de documento (pequeño) y no el número del documento para evitar overflow y respetar semántica
					$codTipoDocIdentidad = (int)($cliente['tipo_identidad'] ?? 1);
					$numeroDoc = trim((string) ($cliente['numero'] ?? ''));

					// Documentos
					if ($tipoDoc === 'R') {
						$anio = (int) date('Y', strtotime($item['fecha_cobro']));
						if ($medioDoc === 'C') {
							if ($nroReciboBatch === null) {
								$nroRecibo = $reciboService->nextReciboAtomic($anio);
								$reciboService->create($anio, $nroRecibo, [
									'id_usuario' => (int)$request->id_usuario,
									'id_forma_cobro' => $formaIdItem,
									'cod_ceta' => (int)$request->cod_ceta,
									'monto_total' => (float)$item['monto'],
									'periodo_facturado' => null,
									'codigo_doc_sector' => config('sin.cod_doc_sector'),
									'cod_tipo_doc_identidad' => $codTipoDocIdentidad,
								]);
								$nroReciboBatch = (int) $nroRecibo; $anioReciboBatch = (int) $anio;
							} else {
								// Reutilizar el mismo Recibo y acumular el total
								$nroRecibo = $nroReciboBatch; $anio = $anioReciboBatch ?: $anio;
								DB::table('recibo')
									->where('anio', (int)$anio)
									->where('nro_recibo', (int)$nroRecibo)
									->update([ 'monto_total' => DB::raw('monto_total + ' . ((float)$item['monto'])) ]);
							}
						} else {
							if (!is_numeric($nroRecibo)) {
								throw new \InvalidArgumentException('nro_recibo requerido para recibo manual');
							}
							$anio = (int) date('Y', strtotime($item['fecha_cobro']));
							if ($reciboService->exists($anio, (int)$nroRecibo)) {
								throw new \RuntimeException('nro_recibo manual duplicado: ' . $nroRecibo);
							}
							$reciboService->create($anio, (int)$nroRecibo, [
								'id_usuario' => (int)$request->id_usuario,
								'id_forma_cobro' => $formaIdItem,
								'cod_ceta' => (int)$request->cod_ceta,
								'monto_total' => (float)$item['monto'],
								'periodo_facturado' => null,
								'codigo_doc_sector' => config('sin.cod_doc_sector'),
								'cod_tipo_doc_identidad' => $codTipoDocIdentidad,
							]);
						}
					} elseif ($tipoDoc === 'F') {
						$anio = (int) date('Y', strtotime($item['fecha_cobro']));
						if ($medioDoc === 'C') {
							// Computarizada: asegurar CUFD, generar correlativo, generar CUF e insertar factura
							$cufd = $cufdRepo->getVigenteOrCreate($pv);
							$nroFactura = method_exists($facturaService, 'nextFacturaAtomic')
								? $facturaService->nextFacturaAtomic($anio, $sucursal, (string)$pv)
								: $facturaService->nextFactura($anio, $sucursal, (string)$pv);
							Log::info('batchStore: factura C', [ 'idx' => $idx, 'anio' => $anio, 'sucursal' => $sucursal, 'pv' => $pv, 'nro' => $nroFactura ]);
							// Generar CUF según especificación SIAT
							$fechaEmision = date('Y-m-d H:i:s.u');
							$cufData = $cufGen->generate(
								(int) config('sin.nit'),
								$fechaEmision,
								$sucursal,
								(int) config('sin.modalidad'),
								1, // tipo_emision: en línea
								(int) config('sin.tipo_factura'),
								(int) config('sin.cod_doc_sector'),
								(int) $nroFactura,
								(int) $pv
							);
							$cuf = $cufData['cuf'];
							$facturaService->createComputarizada($anio, $nroFactura, [
								'codigo_sucursal' => $sucursal,
								'codigo_punto_venta' => (string)$pv,
								'fecha_emision' => $item['fecha_cobro'],
								'cod_ceta' => (int)$request->cod_ceta,
								'id_usuario' => (int)$request->id_usuario,
								'id_forma_cobro' => $formaIdItem,
								'monto_total' => (float)$item['monto'],
								'codigo_cufd' => $cufd['codigo_cufd'] ?? null,
								'cuf' => $cuf,
							]);
							// Paso 3: emisión online (opcional, solo si emitir_online=true y SIN_OFFLINE=false)
							if ($emitirOnline) {
								if (config('sin.offline')) {
									Log::info('batchStore: skip recepcionFactura (OFFLINE)');
								} else {
									try {
										// Obtener CUIS vigente requerido por recepcionFactura
										$cuisRow = $cuisRepo->getVigenteOrCreate($pv);
										$cuisCode = $cuisRow['codigo_cuis'] ?? '';
										$payload = $payloadBuilder->buildRecepcionFacturaPayload([
											'nit' => (int) config('sin.nit'),
											'cod_sistema' => (string) config('sin.cod_sistema'),
											'ambiente' => (int) config('sin.ambiente'),
											'modalidad' => (int) config('sin.modalidad'),
											'tipo_factura' => (int) config('sin.tipo_factura'),
											'doc_sector' => (int) config('sin.cod_doc_sector'),
											'tipo_emision' => 1,
											'sucursal' => $sucursal,
											'punto_venta' => $pv,
											'cuis' => $cuisCode,
											'cufd' => $cufd['codigo_cufd'] ?? '',
											'cuf' => $cuf,
											'fecha_emision' => $fechaEmision,
											'monto_total' => (float) $item['monto'],
											'numero_factura' => (int) $nroFactura,
											'id_forma_cobro' => $formaIdItem,
											'cliente' => $request->input('cliente', []),
											'detalle' => [
												'codigo_sin' => 0,
												'codigo' => 'ITEM-' . (int)$nroCobro,
												'descripcion' => $item['observaciones'] ?? 'Cobro',
												'cantidad' => 1,
												'unidad_medida' => 1,
												'precio_unitario' => (float)$item['monto'],
												'descuento' => 0,
												'subtotal' => (float)$item['monto'],
											],
										]);
										$resp = $ops->recepcionFactura($payload);
										$codRecep = $resp['RespuestaRecepcionFactura']['codigoRecepcion'] ?? null;
										if ($codRecep) {
											\DB::table('factura')
												->where('anio', $anio)
												->where('nro_factura', $nroFactura)
												->where('codigo_sucursal', $sucursal)
												->where('codigo_punto_venta', (string)$pv)
												->update(['codigo_recepcion' => $codRecep]);
											Log::info('batchStore: recepcionFactura ok', [ 'codigo_recepcion' => $codRecep ]);
										} else {
											Log::warning('batchStore: recepcionFactura sin codigoRecepcion', [ 'resp' => $resp ]);
										}
									} catch (\Throwable $e) {
										Log::error('batchStore: recepcionFactura exception', [ 'error' => $e->getMessage() ]);
									}
								}
							}
						} else { // Manual
							if (!is_numeric($nroFactura)) {
								$nroFactura = $item['nro_factura'] ?? null;
							}
							if (!is_numeric($nroFactura)) {
								throw new \InvalidArgumentException('nro_factura requerido para factura manual');
							}
							$range = $facturaService->withinCafcRange((int)$nroFactura);
							if (!$range) {
								throw new \RuntimeException('nro_factura fuera de rango CAFC');
							}
							Log::info('batchStore: factura M', [ 'idx' => $idx, 'anio' => $anio, 'sucursal' => $sucursal, 'pv' => $pv, 'nro' => (int)$nroFactura ]);
							$facturaService->createManual($anio, (int)$nroFactura, [
								'codigo_sucursal' => $sucursal,
								'codigo_punto_venta' => (string)$pv,
								'fecha_emision' => $item['fecha_cobro'],
								'cod_ceta' => (int)$request->cod_ceta,
								'id_usuario' => (int)$request->id_usuario,
								'id_forma_cobro' => $formaIdItem,
								'monto_total' => (float)$item['monto'],
								'codigo_cafc' => $range['cafc'] ?? null,
							]);
						}
					}

 					// Inserción en notas SGA: después de resolver id_asign/id_cuota para formar el detalle correcto
 					// Derivar id_asignacion_costo / id_cuota cuando no vienen en el payload
 					// Nota: si es Rezagado o Prueba de Recuperación, NO asociar a cuotas ni afectar mensualidad/arrastre
					$isRezagado = false; $isRecuperacion = false; $isReincorporacion = false; $isSecundario = false;
					try {
						$obsCheck = (string)($item['observaciones'] ?? '');
						if ($obsCheck !== '') {
							$isRezagado = (preg_match('/\[\s*REZAGADO\s*\]/i', $obsCheck) === 1);
							// Detectar variantes con o sin acento: [Prueba de recuperación]
							$isRecuperacion = (preg_match('/\[\s*PRUEBA\s+DE\s+RECUPERACI[OÓ]N\s*\]/i', $obsCheck) === 1);
							// Reincorporación como servicio aparte
							$isReincorporacion = (preg_match('/\[\s*REINCORPORACI[OÓ]N\s*\]/i', $obsCheck) === 1);
						}
						// Fallback adicional por detalle explícito
						if (!$isReincorporacion) {
							$detRaw = strtoupper(trim((string)($item['detalle'] ?? '')));
							if ($detRaw !== '' && strpos($detRaw, 'REINCORPOR') !== false) { $isReincorporacion = true; }
						}
						$hasItem = isset($item['id_item']) && !empty($item['id_item']);
						$isSecundario = ($isRezagado || $isRecuperacion || $isReincorporacion || $hasItem);
					} catch (\Throwable $e) {}
					$idAsign = $item['id_asignacion_costo'] ?? null;
					$idCuota = $item['id_cuota'] ?? null;
					$asignRow = null;
					if (!$isSecundario && ((!$idAsign || !$idCuota) && $primaryInscripcion)) {
						if ($idAsign) {
							$asignRow = AsignacionCostos::find((int)$idAsign);
						} elseif ($idCuota) {
							$asignRow = AsignacionCostos::where('cod_pensum', $codPensumCtx)
								->where('cod_inscrip', $primaryInscripcion->cod_inscrip)
								->where('id_cuota_template', (int)$idCuota)
								->first();
						} else {
							$found = null;
							foreach ($asignPrimarias as $asig) {
								$tpl = (int)($asig->id_cuota_template ?? 0);
								if (!$tpl) continue;
								$alreadyPaid = (float)($asig->monto_pagado ?? 0) + (float)($batchPaidByTpl[$tpl] ?? 0);
								$remaining = (float)($asig->monto ?? 0) - $alreadyPaid;
								if ($remaining > 0) { $found = $asig; break; }
							}
							if ($found) { $asignRow = $found; }
						}
						if ($asignRow) {
							$idAsign = $idAsign ?: (int) $asignRow->id_asignacion_costo;
							$idCuota = $idCuota ?: ((int) ($asignRow->id_cuota_template ?? 0) ?: null);
							try {
								$tplSel = (int)($asignRow->id_cuota_template ?? 0);
								$prev = (float)($asignRow->monto_pagado ?? 0);
								$total = (float)($asignRow->monto ?? 0);
								$rem = $total - ($prev + (float)($batchPaidByTpl[$tplSel] ?? 0));
								Log::info('batchStore:target', [ 'idx' => $idx, 'id_asignacion_costo' => $idAsign, 'id_cuota_template' => $idCuota, 'prev_pagado' => $prev, 'total' => $total, 'remaining_before' => $rem ]);
							} catch (\Throwable $e) {}
						}
					}
					if ($isSecundario) { $idAsign = null; $idCuota = null; }
					$order = isset($item['order']) ? (int)$item['order'] : ($idx + 1);

					// Construir detalle de cuota para notas: "Mensualidad - Cuota N (Parcial)"
					$detalle = (string)($item['observaciones'] ?? '');
					$obsOriginal = $detalle;
					if ($idCuota) {
						$cuotaRow = $asignRow;
						if (!$cuotaRow && $primaryInscripcion) {
							$cuotaRow = AsignacionCostos::where('cod_pensum', $codPensumCtx)
								->where('cod_inscrip', $primaryInscripcion->cod_inscrip)
								->where('id_cuota_template', (int)$idCuota)
								->first();
						}
						if ($cuotaRow) {
							$numeroCuota = (int)($cuotaRow->numero_cuota ?? 0);
							$prevPag = (float)($cuotaRow->monto_pagado ?? 0);
							$totalCuota = (float)($cuotaRow->monto ?? 0);
							$parcial = ($prevPag + (float)$item['monto']) < $totalCuota;
							$detalle = 'Mensualidad - Cuota ' . ($numeroCuota ?: $idCuota) . ($parcial ? ' (Parcial)' : '');
						}
					} else {
						if (!empty($item['id_item'] ?? null)) {
							$detFromItem = (string)($item['detalle'] ?? '');
							$detalle = $detFromItem !== '' ? $detFromItem : ($detalle !== '' ? $detalle : 'Item');
						}
					}

					// Inserción en notas SGA usando el detalle correcto
					try {
						$fechaNota = (string)($item['fecha_cobro'] ?? date('Y-m-d'));
						$anioFull = (int) date('Y', strtotime($fechaNota));
						$anio2 = (int) date('y', strtotime($fechaNota));
						$prefijoCarrera = 'E';
						$codCeta = (int) $request->cod_ceta;
						$monto = (float) $item['monto'];
						$isEfectivo = ($formaNombre === 'EFECTIVO');
						$isBancario = in_array($formaNombre, ['TARJETA','CHEQUE','DEPOSITO','TRANSFERENCIA','QR']) || in_array($formaCode, ['O']);

						if ($isEfectivo) {
							DB::statement(
								"INSERT INTO doc_counter (scope, last, created_at, updated_at) VALUES (?, 1, NOW(), NOW())\n"
								. "ON DUPLICATE KEY UPDATE last = LAST_INSERT_ID(last + 1), updated_at = NOW()",
								['NOTA_REPOSICION']
							);
							$rowNr = DB::selectOne('SELECT LAST_INSERT_ID() AS id');
							$nrCorrelativo = (int)($rowNr->id ?? 0);
							DB::table('nota_reposicion')->insert([
								'correlativo' => $nrCorrelativo,
								'usuario' => $usuarioNick,
								'cod_ceta' => $codCeta,
								'monto' => $monto,
								'concepto_adm' => $detalle,
								'fecha_nota' => $fechaNota,
								'concepto_est' => $detalle,
								'observaciones' => $obsOriginal,
								'prefijo_carrera' => $prefijoCarrera,
								'anulado' => false,
								'anio_reposicion' => $anio2,
								'nro_recibo' => $nroRecibo ? (string)$nroRecibo : null,
								'tipo_ingreso' => null,
								'cont' => 2,
							]);
						}

						if ($isBancario) {
							DB::statement(
								"INSERT INTO doc_counter (scope, last, created_at, updated_at) VALUES (?, 1, NOW(), NOW())\n"
								. "ON DUPLICATE KEY UPDATE last = LAST_INSERT_ID(last + 1), updated_at = NOW()",
								['NOTA_BANCARIA']
							);
							$rowNb = DB::selectOne('SELECT LAST_INSERT_ID() AS id');
							$nbCorrelativo = (int)($rowNb->id ?? 0);
							$tarj4 = trim((string)($item['tarjeta_first4'] ?? ''));
							$tarjL4 = trim((string)($item['tarjeta_last4'] ?? ''));
							// Banco destino desde la cuenta seleccionada
							$bancoDest = '';
							try {
								$idCuenta = $request->id_cuentas_bancarias ?? ($item['id_cuentas_bancarias'] ?? null);
								if ($idCuenta) {
									$cb = DB::table('cuentas_bancarias')->where('id_cuentas_bancarias', (int)$idCuenta)->first();
									if ($cb) { $bancoDest = trim((string)($cb->banco ?? '')) . ' - ' . trim((string)($cb->numero_cuenta ?? '')); }
								}
							} catch (\Throwable $e) {}
							// nro_tarjeta completo: first4 + 00000000 + last4
							$nroTarjetaFull = ($tarj4 && $tarjL4) ? ($tarj4 . '00000000' . $tarjL4) : null;
							DB::table('nota_bancaria')->insert([
								'anio_deposito' => $anioFull,
								'correlativo' => $nbCorrelativo,
								'usuario' => $usuarioNick,
								'fecha_nota' => $fechaNota,
								'cod_ceta' => $codCeta,
								'monto' => $monto,
								'concepto' => $detalle,
								'nro_factura' => $nroFactura ? (string)$nroFactura : '',
								'nro_recibo' => $nroRecibo ? (string)$nroRecibo : '',
								'banco' => $bancoDest,
								'fecha_deposito' => (string)($item['fecha_deposito'] ?? ''),
								'nro_transaccion' => (string)($item['nro_deposito'] ?? ''),
								'prefijo_carrera' => $prefijoCarrera,
								'concepto_est' => $detalle,
								'observacion' => $obsOriginal,
								'anulado' => false,
								'tipo_nota' => (string)($formaIdItem ?? ''),
								'banco_origen' => (string)($item['banco_origen'] ?? ''),
								'nro_tarjeta' => $nroTarjetaFull,
							]);
						}
					} catch (\Throwable $e) {
						\Log::warning('batchStore: nota insert failed', [ 'err' => $e->getMessage() ]);
					}

					$payload = array_merge($composite, [
						'monto' => $item['monto'],
						'fecha_cobro' => $item['fecha_cobro'],
						'cobro_completo' => $item['cobro_completo'] ?? null,
						'observaciones' => $item['observaciones'] ?? null,
						'id_usuario' => (int)$request->id_usuario,
						'id_forma_cobro' => $item['id_forma_cobro'] ?? $formaIdItem,
						'pu_mensualidad' => $item['pu_mensualidad'] ?? 0,
						'order' => $order,
						'descuento' => $item['descuento'] ?? null,
						'id_cuentas_bancarias' => $request->id_cuentas_bancarias ?? null,
						'nro_factura' => $nroFactura,
						'nro_recibo' => $nroRecibo,
						'id_item' => $item['id_item'] ?? null,
						'id_asignacion_costo' => $isSecundario ? null : $idAsign,
						'id_cuota' => $isSecundario ? null : $idCuota,
						'tipo_documento' => $tipoDoc,
						'medio_doc' => $medioDoc,
						'gestion' => $request->gestion ?? null,
						'cod_inscrip' => $primaryInscripcion ? (int)$primaryInscripcion->cod_inscrip : null,
					]);
					$created = Cobro::create($payload)->load(['usuario', 'cuota', 'formaCobro', 'cuentaBancaria', 'itemCobro']);

					if (!$isSecundario) {
						try {
							DB::table('cobros_detalle_regular')->updateOrInsert(
								[
									'nro_cobro' => (int)$nroCobro,
								],
								[
									'cod_ceta' => (int)$request->cod_ceta,
									'cod_pensum' => (string)$request->cod_pensum,
									'tipo_inscripcion' => (string)$request->tipo_inscripcion,
									'cod_inscrip' => $primaryInscripcion ? (int)$primaryInscripcion->cod_inscrip : 0,
									'pu_mensualidad' => (float)($item['pu_mensualidad'] ?? 0),
									'turno' => (string)($primaryInscripcion->turno ?? ''),
									'updated_at' => now(),
									'created_at' => DB::raw('COALESCE(created_at, NOW())'),
								]
							);
						} catch (\Throwable $e) {
							Log::warning('batchStore: detalle_regular insert failed', [ 'err' => $e->getMessage() ]);
						}
					}

					// Acumular pagos del batch por plantilla de cuota para seguir aplicando a la misma cuota si aún queda saldo
					if ($idCuota) {
						$batchPaidByTpl[$idCuota] = ($batchPaidByTpl[$idCuota] ?? 0) + (float)$item['monto'];
						try { Log::info('batchStore:paidByTpl', [ 'idx' => $idx, 'tpl' => $idCuota, 'batch_paid' => $batchPaidByTpl[$idCuota] ]); } catch (\Throwable $e) {}
					}
					// Actualizar estado de pago de la asignación
					if (!$isSecundario && $idAsign) {
						// Releer siempre desde DB para evitar usar un snapshot desactualizado cuando hay múltiples ítems a la misma cuota
						$toUpd = AsignacionCostos::find((int)$idAsign);
						if ($toUpd) {
							$prevPagado = (float)($toUpd->monto_pagado ?? 0);
							$newPagado = $prevPagado + (float)$item['monto'];
							$fullNow = $newPagado >= (float) ($toUpd->monto ?? 0) || !empty($item['cobro_completo']);
							$upd = [ 'monto_pagado' => $newPagado ];
							if ($fullNow) {
								$upd['estado_pago'] = 'COBRADO';
								$upd['fecha_pago'] = $item['fecha_cobro'];
							} else {
								$upd['estado_pago'] = 'PARCIAL';
							}
							try { Log::info('batchStore:asign_update', [ 'idx' => $idx, 'id_asignacion_costo' => (int)$toUpd->id_asignacion_costo, 'add_monto' => (float)$item['monto'], 'prev_pagado' => $prevPagado, 'new_pagado' => $newPagado, 'total' => (float)($toUpd->monto ?? 0), 'estado_final' => $upd['estado_pago'] ]); } catch (\Throwable $e) {}
							$aff = AsignacionCostos::where('id_asignacion_costo', (int)$toUpd->id_asignacion_costo)->update($upd);
							try { Log::info('batchStore:asign_updated', [ 'idx' => $idx, 'id_asignacion_costo' => (int)$toUpd->id_asignacion_costo, 'affected' => $aff ]); } catch (\Throwable $e) {}
						}
					}
					else {
						try { Log::info('batchStore:asign_skip', [ 'idx' => $idx, 'reason' => 'missing_id_asign', 'is_secundario' => $isSecundario ]); } catch (\Throwable $e) {}
					}
					// Rezagados: si el item contiene el marcador, registrar en la tabla 'rezagados'
					try {
						$obsVal = (string)($item['observaciones'] ?? '');
						if ($obsVal !== '' && preg_match('/\[\s*REZAGADO\s*\]\s*Rezagado\s*-\s*([A-Z0-9\-]+)\b.*?-(\s*)([123])er\s*P\.?/i', $obsVal, $mm)) {
							$siglaMateria = strtoupper(trim((string)$mm[1]));
							$parcialNum = (string)trim((string)$mm[3]); // '1' | '2' | '3'
							$fechaPago = (string)($item['fecha_cobro'] ?? date('Y-m-d'));
							$anioPago = (int) date('Y', strtotime($fechaPago));
							$codInscrip = $primaryInscripcion ? (int)$primaryInscripcion->cod_inscrip : null;
							if ($codInscrip) {
								// Reutilizar num_rezagado para la misma materia/año si existe
								$rowExist = DB::table('rezagados')
									->where('cod_inscrip', $codInscrip)
									->where(DB::raw('YEAR(fecha_pago)'), $anioPago)
									->where('materia', $siglaMateria)
									->orderByDesc('num_rezagado')
									->first();
								$numRezagado = $rowExist ? (int)$rowExist->num_rezagado : null;
								if (!$numRezagado) {
									$maxRow = DB::table('rezagados')
										->select(DB::raw('MAX(num_rezagado) as mx'))
										->where('cod_inscrip', $codInscrip)
										->where(DB::raw('YEAR(fecha_pago)'), $anioPago)
										->first();
									$nextSeq = (int)($maxRow->mx ?? 0) + 1;
									$numRezagado = max(1, $nextSeq);
								}
								$numPagoRezagado = (int)$parcialNum; // un pago por parcial
								$obsClean = trim(preg_replace('/\|?\s*\[\s*REZAGADO\s*\]\s*.+$/i', '', (string)$item['observaciones']));
								DB::table('rezagados')->updateOrInsert(
									[
										'cod_inscrip' => $codInscrip,
										'num_rezagado' => $numRezagado,
										'num_pago_rezagado' => $numPagoRezagado,
									],
									[
										'num_factura' => is_numeric($nroFactura) ? (int)$nroFactura : null,
										'num_recibo' => is_numeric($nroRecibo) ? (int)$nroRecibo : null,
										'fecha_pago' => $fechaPago,
										'monto' => (float)$item['monto'],
										'pago_completo' => true,
										'observaciones' => $obsClean !== '' ? $obsClean : null,
										'usuario' => (int)$request->id_usuario,
										'materia' => $siglaMateria,
										'parcial' => (string)$parcialNum,
										'updated_at' => now(),
										'created_at' => DB::raw('COALESCE(created_at, NOW())'),
									]
								);
							}
						}
					} catch (\Throwable $e) {
						Log::warning('batchStore: rezagados insert failed', [ 'err' => $e->getMessage() ]);
					}

					$results[] = [
						'indice' => $idx,
						'tipo_documento' => $tipoDoc,
						'medio_doc' => $medioDoc,
						'nro_recibo' => $nroRecibo,
						'nro_factura' => $nroFactura,
						'cobro' => $created,
					];
				}

				// Al finalizar la creación de todos los ítems, sincronizar números de doc a qr_transacciones reciente
				try {
					$totalMonto = 0.0; foreach ($items as $it) { $totalMonto += (float)($it['monto'] ?? 0); }
					$codCetaGuard = (int) $request->input('cod_ceta');
					if ($codCetaGuard > 0 && $totalMonto > 0) {
						$recentTrx = DB::table('qr_transacciones')
							->where('cod_ceta', $codCetaGuard)
							->where('estado', 'completado')
							->whereBetween('updated_at', [now()->subMinutes(30), now()->addMinutes(1)])
							->orderByDesc('updated_at')
							->first();
						if ($recentTrx && abs(((float)$recentTrx->monto_total) - $totalMonto) < 0.001) {
							$docUpd = [];
							$first = isset($results[0]) ? $results[0] : null;
							if (is_array($first)) {
								$tipoDoc = strtoupper((string)($first['tipo_documento'] ?? ''));
								$nroRec = $first['nro_recibo'] ?? null;
								$nroFac = $first['nro_factura'] ?? null;
								$cob = $first['cobro'] ?? [];
								$fechaCobro = is_array($cob) ? ((string)($cob['fecha_cobro'] ?? '')) : '';
								$anioDoc = $fechaCobro ? (int)date('Y', strtotime($fechaCobro)) : (int)date('Y');
								if ($tipoDoc === 'R' && is_numeric($nroRec)) { $docUpd['nro_recibo'] = (int)$nroRec; $docUpd['anio_recibo'] = $anioDoc; }
								elseif ($tipoDoc === 'F' && is_numeric($nroFac)) { $docUpd['nro_factura'] = (int)$nroFac; $docUpd['anio'] = $anioDoc; }
							}
							if (!empty($docUpd)) {
								$aff = DB::table('qr_transacciones')->where('id_qr_transaccion', (int)$recentTrx->id_qr_transaccion)
									->update(array_merge(['updated_at' => now()], $docUpd));
								Log::info('batchStore: synced docnums to qr_transacciones', [ 'trx' => (int)$recentTrx->id_qr_transaccion, 'upd' => $docUpd, 'affected' => $aff ]);
							}
						}
					}
				} catch (\Throwable $e) {
					Log::warning('batchStore: sync docnums to qr_transacciones failed', [ 'error' => $e->getMessage() ]);
				}

				// Emitir evento de sockets para multi-sesión: si el batch contiene un ítem QR, notificar factura_generada
				try {
					$aliasQr = null;
					foreach ($items as $it) {
						$obs = (string)($it['observaciones'] ?? '');
						if ($obs !== '' && preg_match('/\[\s*QR[^\]]*\]\s*alias:([^\s|]+)/i', $obs, $mm)) { $aliasQr = trim((string)$mm[1]); break; }
					}
					if ($aliasQr) { app(\App\Services\Qr\QrSocketNotifier::class)->notifyEvent('factura_generada', [ 'id_pago' => $aliasQr ]); }
				} catch (\Throwable $e) { /* noop */ }
			});

			return response()->json([
				'success' => true,
				'message' => 'Cobros creados correctamente',
				'data' => [ 'items' => $results ],
			], 201);
		} catch (\Throwable $e) {
			Log::error('batchStore: exception', [ 'error' => $e->getMessage() ]);
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

	/**
	 * Validar y preparar contexto de impuestos (CUIS/CUFD vigentes)
	 */
	public function validarImpuestos(Request $request, CuisRepository $cuisRepo, CufdRepository $cufdRepo)
	{
		$rules = [
			'codigo_punto_venta' => 'nullable|integer|min:0',
			'codigo_sucursal' => 'nullable|integer|min:0',
			'id_usuario' => 'nullable|integer|exists:usuarios,id_usuario',
			'ip_equipo' => 'nullable|string|max:50',
		];
		$validator = Validator::make($request->all(), $rules);
		if ($validator->fails()) {
			Log::warning('validar-impuestos: validation failed', [ 'errors' => $validator->errors()->toArray() ]);
			return response()->json([
				'success' => false,
				'message' => 'Error de validación',
				'errors' => $validator->errors(),
			], 422);
		}

		$pv = (int) ($request->input('codigo_punto_venta', 0));
		$sucursalInput = $request->input('codigo_sucursal');
		Log::info('validar-impuestos: start', [ 'pv' => $pv, 'codigo_sucursal' => $sucursalInput ]);

		try {
			// CUIS vigente o crear
			$cuis = $cuisRepo->getVigenteOrCreate($pv);
			Log::info('validar-impuestos: CUIS ok', [ 'codigo_cuis' => $cuis['codigo_cuis'] ?? null, 'fecha_vigencia' => $cuis['fecha_vigencia'] ?? null ]);

			// CUFD vigente o crear
			$cufd = null;
			try {
				$cufd = $cufdRepo->getVigenteOrCreate($pv);
				Log::info('validar-impuestos: CUFD ok', [ 'codigo_cufd' => $cufd['codigo_cufd'] ?? null, 'fecha_vigencia' => $cufd['fecha_vigencia'] ?? null ]);
			} catch (\Throwable $e) {
				// No bloquear: en algunos PV puede no requerirse CUFD inmediato
				Log::warning('validar-impuestos: CUFD lookup failed', [ 'pv' => $pv, 'error' => $e->getMessage() ]);
			}

			// TODO: evento significativo/leyendas si es necesario (quedará para paso 2)
			return response()->json([
				'success' => true,
				'data' => [
					'cuis' => $cuis,
					'cufd' => $cufd,
					'punto_venta' => [ 'codigo_punto_venta' => $pv ],
					'sucursal' => [ 'codigo_sucursal' => $sucursalInput ?? config('sin.sucursal') ],
				],
			]);
		} catch (\Throwable $e) {
			Log::error('validar-impuestos: exception', [ 'pv' => $pv, 'error' => $e->getMessage(), 'trace' => substr($e->getTraceAsString(), 0, 1000) ]);
			return response()->json([
				'success' => false,
				'message' => 'Error al validar impuestos: ' . $e->getMessage(),
			], 500);
		}
	}
}
