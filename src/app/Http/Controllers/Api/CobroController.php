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
use App\Models\DescuentoDetalle;
use App\Models\Descuento;
use App\Models\ParametrosEconomicos;
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
use Carbon\Carbon;

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
				$sem = (int) trim(isset($parts[0]) ? $parts[0] : '');
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
				$map[] = [ 'numero_cuota' => $cuota, 'mes_num' => $mesNum, 'mes_nombre' => (isset($names[$mesNum]) ? $names[$mesNum] : (string)$mesNum) ];
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
				'cod_tipo_cobro' => 'nullable|string|exists:tipo_cobro,cod_tipo_cobro',
				'concepto' => 'nullable|string',
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
			$nroCobro = (int)(isset($row->id) ? $row->id : 0);
			$data = $request->all();
			// Normalizar/derivar cod_tipo_cobro y concepto si no vienen del frontend
			if (empty($data['cod_tipo_cobro'])) {
				$obsCheck = (string)($request->input('observaciones', ''));
				$hasItem = $request->filled('id_item');
				$isRezagado = (preg_match('/\[\s*REZAGADO\s*\]/i', $obsCheck) === 1);
				$isRecuperacion = (preg_match('/\[\s*PRUEBA\s+DE\s+RECUPERACI[OÓ]N\s*\]/i', $obsCheck) === 1);
				$isReincorporacion = (preg_match('/\[\s*REINCORPORACI[OÓ]N\s*\]/i', $obsCheck) === 1);
				if ($hasItem) { $data['cod_tipo_cobro'] = 'MATERIAL_EXTRA'; }
				elseif ($isRezagado) { $data['cod_tipo_cobro'] = 'REZAGADOS'; }
				elseif ($isRecuperacion) { $data['cod_tipo_cobro'] = 'PRUEBA_RECUPERACION'; }
				elseif ($isReincorporacion) { $data['cod_tipo_cobro'] = 'REINCORPORACION'; }
				elseif (strtoupper((string)$request->input('tipo_inscripcion', '')) === 'ARRASTRE') { $data['cod_tipo_cobro'] = 'ARRASTRE'; }
				else { $data['cod_tipo_cobro'] = 'MENSUALIDAD'; }
			}
			if (!isset($data['concepto']) || trim((string)$data['concepto']) === '') {
				if (isset($data['cod_tipo_cobro']) && $data['cod_tipo_cobro'] === 'REINCORPORACION') {
					$data['concepto'] = 'Reincorporación';
				} else {
					$data['concepto'] = (string)($request->input('observaciones', 'Cobro'));
				}
			}
			// Normalizar fecha_cobro a datetime (Y-m-d H:i:s) en zona America/La_Paz
			try {
				$fechaCobroRaw = isset($data['fecha_cobro']) ? (string)$data['fecha_cobro'] : date('Y-m-d H:i:s');
				if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaCobroRaw)) {
					$nowLaPaz = \Carbon\Carbon::now('America/La_Paz');
					$data['fecha_cobro'] = substr($fechaCobroRaw, 0, 10) . ' ' . $nowLaPaz->format('H:i:s');
				} else {
					$data['fecha_cobro'] = \Carbon\Carbon::parse($fechaCobroRaw, 'America/La_Paz')->format('Y-m-d H:i:s');
				}
			} catch (\Throwable $e) {
				$data['fecha_cobro'] = date('Y-m-d H:i:s');
			}
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
			$warnings = [];

			// Query para obtener documentos del estudiante
			$documentosQuery = DB::table('doc_presentados as d')
				->select(
					'd.cod_ceta',
					DB::raw("MAX(CASE WHEN UPPER(d.nombre_doc) LIKE '%CARNET%' THEN d.numero_doc END) as ci_doc"),
					DB::raw("MAX(CASE WHEN UPPER(d.nombre_doc) LIKE '%TELÉFONO%' OR UPPER(d.nombre_doc) LIKE '%TELEFONO%' OR UPPER(d.nombre_doc) LIKE '%CELULAR%' OR UPPER(d.nombre_doc) LIKE '%CEL%' THEN d.numero_doc END) as telefono_doc"),
					DB::raw("MAX(d.numero_doc) as any_doc")
				)
				->groupBy('d.cod_ceta');
			
			$estudiante = Estudiante::with('pensum')
				->leftJoinSub($documentosQuery, 'dp', function($join){ 
					$join->on('dp.cod_ceta', '=', 'estudiantes.cod_ceta'); 
				})
				->find($codCeta);
			
			// Debug para verificar que se obtenga el estudiante correcto
			Log::info('Estudiante obtenido:', [
				'cod_ceta_buscado' => $codCeta,
				'estudiante_cod_ceta' => $estudiante?->cod_ceta,
				'estudiante_nombre' => $estudiante?->nombre,
				'estudiante_apellido' => $estudiante?->apellido,
				'estudiante_completo' => $estudiante?->toArray()
			]);
			
			if (!$estudiante) {
				return response()->json([
					'success' => false,
					'message' => 'Estudiante no encontrado'
				], 404);
			}

			// Estrategia de gestión: si viene gestion en request, usarla; caso contrario usar la última inscripción del estudiante
			if ($gestionReq) {
				$inscripciones = Inscripcion::with('pensum')->where('cod_ceta', $codCeta)
					->where('gestion', $gestionReq)
					->orderByDesc('fecha_inscripcion')
					->orderByDesc('created_at')
					->get();
				if ($inscripciones->isEmpty()) {
					return response()->json([
						'success' => false,
						'message' => 'El estudiante no tiene inscripción en la gestión solicitada',
					], 404);
				}
				$gestionToUse = $gestionReq;
			} else {
				$ultima = Inscripcion::where('cod_ceta', $codCeta)
					->orderByDesc('fecha_inscripcion')
					->orderByDesc('created_at')
					->first();
				if (!$ultima) {
					return response()->json([
						'success' => false,
						'message' => 'El estudiante no posee inscripciones registradas',
					], 404);
				}
				$gestionToUse = $ultima->gestion;
				$inscripciones = Inscripcion::with('pensum')->where('cod_ceta', $codCeta)
					->where('gestion', $gestionToUse)
					->orderByDesc('fecha_inscripcion')
					->orderByDesc('created_at')
					->get();
				$warnings[] = 'Se usó la última inscripción del estudiante: ' . $gestionToUse;
			}
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
						$norm = function($s){
							$str = (string) $s;
							$converted = @iconv('UTF-8','ASCII//TRANSLIT', $str);
							if ($converted !== false && $converted !== null) {
								$str = (string) $converted;
							}
							return strtoupper(trim($str));
						};
						$pick = $rows->first(function($r) use ($norm){
							$tc = $norm(isset($r->tipo_costo) ? $r->tipo_costo : '');
							return $tc === 'INSTACIA' || $tc === 'INSTANCIA' || $tc === 'RECUPERACION' || strpos($tc, 'SEGUNDA') !== false;
						});
						if (!$pick) {
							$pick = $rows->first(function($r) use ($norm){ $tc = $norm(isset($r->tipo_costo) ? $r->tipo_costo : ''); return (strpos($tc,'RECUP') !== false) || (strpos($tc,'INSTANC') !== false); });
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

			// Mapear descuentos por asignación (id_asignacion_costo -> monto_descuento)
			$descuentosPorAsign = [];
			try {
				if ($asignacionesPrimarias && $asignacionesPrimarias->count() > 0) {
					$idsDet = $asignacionesPrimarias->pluck('id_descuentoDetalle')->filter()->map(function($v){ return (int)$v; })->unique()->values();
					if ($idsDet->count() > 0) {
						$detRows = DescuentoDetalle::whereIn('id_descuento_detalle', $idsDet)->get(['id_descuento_detalle','monto_descuento']);
						$detById = [];
						foreach ($detRows as $dr) { $detById[(int)$dr->id_descuento_detalle] = (float)($dr->monto_descuento ?? 0); }
						foreach ($asignacionesPrimarias as $a) {
							$ida = (int)($a->id_asignacion_costo ?? 0);
							$idDet = (int)($a->id_descuentoDetalle ?? 0);
							$descuentosPorAsign[$ida] = ($idDet && isset($detById[$idDet])) ? (float)$detById[$idDet] : 0.0;
						}
					}
					// Fallback: si no hay id_descuentoDetalle en asignacion_costos, buscar detalle por id_cuota (id_asignacion_costo)
					$idsAsign = $asignacionesPrimarias->pluck('id_asignacion_costo')->filter()->map(function($v){ return (int)$v; })->unique()->values();
					if ($idsAsign->count() > 0) {
						$detByCuota = DescuentoDetalle::whereIn('id_cuota', $idsAsign)->get(['id_cuota','monto_descuento']);
						$mapByCuota = [];
						foreach ($detByCuota as $dr) { $mapByCuota[(int)$dr->id_cuota] = (float)($dr->monto_descuento ?? 0); }
						foreach ($asignacionesPrimarias as $a) {
							$ida = (int)($a->id_asignacion_costo ?? 0);
							if ($ida && (!isset($descuentosPorAsign[$ida]) || $descuentosPorAsign[$ida] <= 0)) {
								if (isset($mapByCuota[$ida])) $descuentosPorAsign[$ida] = (float)$mapByCuota[$ida];
							}
						}
					}
				}
			} catch (\Throwable $e) { /* noop */ }

			$cobrosBase = Cobro::where('cod_ceta', $codCeta)
				->where('cod_pensum', $codPensumToUse)
				->when($gestionToUse, function ($q) use ($gestionToUse) {
					$q->where('gestion', $gestionToUse);
				})
				->when($primaryInscripcion, function ($q) use ($primaryInscripcion) {
					$q->where('tipo_inscripcion', $primaryInscripcion->tipo_inscripcion);
				});

			// Calcular total pagado directamente desde asignaciones con pagos (sin filtrar estado)
			$totalPagadoDesdeAsignaciones = 0;
			if ($asignacionesPrimarias && $asignacionesPrimarias->count() > 0) {
				$totalPagadoDesdeAsignaciones = $asignacionesPrimarias
					->sum(function($a) {
						return isset($a->monto_pagado) ? (float)$a->monto_pagado : 0;
					});
			}
			
			$cobrosMensualidad = clone $cobrosBase;
			$cobrosMensualidad = $cobrosMensualidad->with(['recibo', 'factura'])->get();
			$cobrosItems = clone $cobrosBase;
			$cobrosItems = $cobrosItems->with(['recibo', 'factura'])->get();
			$totalMensualidad = $cobrosMensualidad->sum('monto');
			$totalItems = 0; // Ya están incluidos en totalMensualidad
			
			// Calcular total solo de mensualidades pagadas completamente (estado COBRADO)
			$cobrosMensualidadCompletas = clone $cobrosBase;
			$cobrosMensualidadCompletas = $cobrosMensualidadCompletas
				->where(function($q){
					$q->whereNotNull('id_cuota')
						->orWhereNotNull('id_asignacion_costo');
				})
				->whereHas('asignacionCostos', function($q) {
					$q->where('estado_pago', 'COBRADO');
				})
				->get();
			$totalMensualidadCompletas = $cobrosMensualidadCompletas->sum('monto');
			
			// Calcular total de mensualidades con pagos parciales para mostrar en UI (COBRADO + PARCIAL)
			$cobrosMensualidadConParciales = clone $cobrosBase;
			$cobrosMensualidadConParciales = $cobrosMensualidadConParciales
				->whereNull('id_item') // Capturar todos los cobros que no son de items adicionales
				->with(['recibo', 'factura'])
				->get();
			$totalMensualidadConParciales = $cobrosMensualidadConParciales->sum('monto');

			// Próxima mensualidad a pagar con prioridad a PARCIAL; exponer 'parcial_count'
			$mensualidadNext = null; $mensualidadPendingCount = 0; $mensualidadTotalCuotas = $asignacionesPrimarias->count();
			Log::debug('CobroController.resumen: asignaciones', [
				'cod_ceta' => $codCeta,
				'total_cuotas' => $mensualidadTotalCuotas,
				'asignaciones' => $asignacionesPrimarias->toArray()
			]);
			$parciales = $asignacionesPrimarias->filter(function($a){ return (string)($a->estado_pago ? $a->estado_pago : '') === 'PARCIAL'; })->sortBy('numero_cuota')->values();
			$parcialCount = $parciales->count();
			if ($mensualidadTotalCuotas > 0) {
				$orderedAsign = $asignacionesPrimarias->values(); // ya está ordenado por numero_cuota asc
				$nextParcial = $parciales->first();
				if ($nextParcial) {

					$descN = (float) ($descuentosPorAsign[(int)($nextParcial->id_asignacion_costo ?? 0)] ?? 0);
					$neto = max(0, (float)$nextParcial->monto - $descN);
					$restante = max(0, (float)$nextParcial->monto - (float)(isset($nextParcial->monto_pagado) ? $nextParcial->monto_pagado : 0));
					// $restante = max(0, $neto - (float)($nextParcial->monto_pagado ?? 0));

					$mensualidadNext = [
						'numero_cuota' => (int) $nextParcial->numero_cuota,
						'monto' => (float) $restante, // para UI usamos directamente el restante
						'original_monto' => (float) $nextParcial->monto,
						'monto_pagado' => (float) (isset($nextParcial->monto_pagado) ? $nextParcial->monto_pagado : 0),
						'id_asignacion_costo' => (isset($nextParcial->id_asignacion_costo) && $nextParcial->id_asignacion_costo != 0) ? (int)$nextParcial->id_asignacion_costo : null,
						'id_cuota_template' => isset($nextParcial->id_cuota_template) ? ((int)$nextParcial->id_cuota_template ?: null) : null,
						'fecha_vencimiento' => $nextParcial->fecha_vencimiento,
						'estado_pago' => 'PARCIAL',
					];
				} else {
					// No hay PARCIAL: elegir la primera no cobrada (pendiente)
					$nextPend = $orderedAsign->first(function($a){ return (string)(isset($a->estado_pago) ? $a->estado_pago : '') !== 'COBRADO'; });
					if ($nextPend) {
						$descN2 = (float) ($descuentosPorAsign[(int)($nextPend->id_asignacion_costo ?? 0)] ?? 0);
						$neto2 = max(0, (float)$nextPend->monto - $descN2);
						$restante = max(0, (float)$nextPend->monto - (float)(isset($nextPend->monto_pagado) ? $nextPend->monto_pagado : 0));
						// $restante = max(0, $neto2 - (float)($nextPend->monto_pagado ?? 0));

						$mensualidadNext = [
							'numero_cuota' => (int) $nextPend->numero_cuota,
							'monto' => (float) ($restante > 0 ? $restante : (float)$nextPend->monto),
							'original_monto' => (float) $nextPend->monto,
							'monto_pagado' => (float) (isset($nextPend->monto_pagado) ? $nextPend->monto_pagado : 0),
							'id_asignacion_costo' => (isset($nextPend->id_asignacion_costo) && $nextPend->id_asignacion_costo != 0) ? (int)$nextPend->id_asignacion_costo : null,
							'id_cuota_template' => isset($nextPend->id_cuota_template) ? ((int)$nextPend->id_cuota_template ?: null) : null,
							'fecha_vencimiento' => $nextPend->fecha_vencimiento,
							'estado_pago' => (string)(isset($nextPend->estado_pago) ? $nextPend->estado_pago : 'PENDIENTE'),
						];
					}

					// Pending count: solo cuotas en estado pendiente (excluye PARCIAL y COBRADO)
					$mensualidadPendingCount = $asignacionesPrimarias->filter(function($a){
						$st = (string)(isset($a->estado_pago) ? $a->estado_pago : '');
						return $st !== 'COBRADO' && $st !== 'PARCIAL';
					})->count();
				}
				// Pending count: solo cuotas en estado pendiente (excluye PARCIAL y COBRADO)
				$mensualidadPendingCount = $asignacionesPrimarias->filter(function($a){
					$st = (string)(isset($a->estado_pago) ? $a->estado_pago : '');
						return $st !== 'COBRADO' && $st !== 'PARCIAL';
					})->count();
				}

			// Construir resumen para ARRASTRE y exponer sus asignaciones
			$arrastreSummary = null; $asignacionesArrastre = collect(); $descuentosPorAsignArrastre = [];
			if ($arrastreInscripcion) {
				try {
					$asignacionesArrastre = AsignacionCostos::query()
						->where('cod_pensum', (string) $arrastreInscripcion->cod_pensum)
						->where('cod_inscrip', (int) $arrastreInscripcion->cod_inscrip)
						->orderBy('numero_cuota')
						->get();
					$next = null; $pendingCount = 0; $totalCuotas = $asignacionesArrastre->count();
					foreach ($asignacionesArrastre as $asig) {
						$estadoPago = strtoupper((string)($asig->estado_pago ?? ''));
						$pagada = ($estadoPago === 'COBRADO');
						if (!$pagada) {
							$pendingCount++;
							if (!$next) {
								$next = [
									'numero_cuota' => (int) $asig->numero_cuota,
									'monto' => (float) $asig->monto,
									'id_asignacion_costo' => (int) $asig->id_asignacion_costo,
									'id_cuota_template' => (int) ($asig->id_cuota_template ?? 0) ?: null,
									'fecha_vencimiento' => $asig->fecha_vencimiento,
								];
							}
						}
					}
					// Mapear descuentos para ARRASTRE
					if ($asignacionesArrastre && $asignacionesArrastre->count() > 0) {
						$idsDetA = $asignacionesArrastre->pluck('id_descuentoDetalle')->filter()->map(fn($v) => (int)$v)->unique()->values();
						if ($idsDetA->count() > 0) {
							$detRowsA = DescuentoDetalle::whereIn('id_descuento_detalle', $idsDetA)->get(['id_descuento_detalle','monto_descuento']);
							$detByIdA = [];
							foreach ($detRowsA as $dr) { $detByIdA[(int)$dr->id_descuento_detalle] = (float)($dr->monto_descuento ?? 0); }
							foreach ($asignacionesArrastre as $a) {
								$ida = (int)($a->id_asignacion_costo ?? 0);
								$idDet = (int)($a->id_descuentoDetalle ?? 0);
								$descuentosPorAsignArrastre[$ida] = ($idDet && isset($detByIdA[$idDet])) ? (float)$detByIdA[$idDet] : 0.0;
							}
						}
						$idsAsignA = $asignacionesArrastre->pluck('id_asignacion_costo')->filter()->map(fn($v) => (int)$v)->unique()->values();
						if ($idsAsignA->count() > 0) {
							$detByCuotaA = DescuentoDetalle::whereIn('id_cuota', $idsAsignA)->get(['id_cuota','monto_descuento']);
							$mapByCuotaA = [];
							foreach ($detByCuotaA as $dr) { $mapByCuotaA[(int)$dr->id_cuota] = (float)($dr->monto_descuento ?? 0); }
							foreach ($asignacionesArrastre as $a) {
								$ida = (int)($a->id_asignacion_costo ?? 0);
								if ($ida && (!isset($descuentosPorAsignArrastre[$ida]) || $descuentosPorAsignArrastre[$ida] <= 0)) {
									if (isset($mapByCuotaA[$ida])) $descuentosPorAsignArrastre[$ida] = (float)$mapByCuotaA[$ida];
								}
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
			// Priorizar cálculo real desde asignaciones de costos sobre valores configurados
			$montoSemestralFromAsignGestion = null;
			try {
				if ($primaryInscripcion && Schema::hasTable('asignacion_costos')) {
					$montoSemestralFromAsignGestion = (float) DB::table('asignacion_costos')
						->where('cod_inscrip', $primaryInscripcion->cod_inscrip)
						->sum('monto');
				}
			} catch (\Throwable $e) { /* fallback automático abajo */ }
			
			// Prioridad: 1) Cálculo real desde asignaciones, 2) Suma de asignaciones primarias, 3) Costo semestral configurado, 4) Parámetro
			$montoSemestre = ($montoSemestralFromAsignGestion !== null && $montoSemestralFromAsignGestion > 0)
				? $montoSemestralFromAsignGestion
				: (($asignacionesPrimarias->count() > 0 ? (float) $asignacionesPrimarias->sum('monto') : null)
					?: (optional($costoSemestral)->monto_semestre
						?: ($paramMonto ? (float) $paramMonto->valor : null)));
// <<<<<<< HEAD
// 			$saldoMensualidad = isset($montoSemestre) ? (float) $montoSemestre - (float) $totalMensualidadCompletas : null;
// 			$puMensualFromNext = $mensualidadNext ? round((float) (isset($mensualidadNext['monto']) ? $mensualidadNext['monto'] : 0), 2) : null;
// =======
			$totalDescuentos = 0.0;
			if (!empty($descuentosPorAsign)) { foreach ($descuentosPorAsign as $v) { $totalDescuentos += (float)$v; } }
			$montoSemestreNeto = isset($montoSemestre) ? max(0, (float)$montoSemestre - (float)$totalDescuentos) : null;
			// Corrección: Usar solo mensualidades completas para el saldo
			$saldoMensualidad = $montoSemestreNeto !== null ? (float)$montoSemestreNeto - (float)$totalMensualidadCompletas : null;
			
			// Debug para el cálculo del saldo
			Log::info('Cálculo de saldo de mensualidad:', [
				'cod_ceta' => $codCeta,
				'montoSemestre' => $montoSemestre,
				'totalDescuentos' => $totalDescuentos,
				'montoSemestreNeto' => $montoSemestreNeto,
				'totalMensualidad' => $totalMensualidad,
				'totalMensualidadCompletas' => $totalMensualidadCompletas,
				'totalMensualidadConParciales' => $totalMensualidadConParciales,
				'saldoMensualidad' => $saldoMensualidad,
				'formula_usada' => 'montoSemestreNeto - totalMensualidadCompletas'
			]);
			
			$puMensualFromNext = $mensualidadNext ? round((float) ($mensualidadNext['monto'] ?? 0), 2) : null;
// >>>>>>> db8167bb0a817bf7e0af1d0732b63770d42d68e3
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
						$src = (string)(isset($d->nombre_doc) ? $d->nombre_doc : '');
						if (function_exists('mb_strtoupper')) {
							$d->nombre_doc_upper = mb_strtoupper($src, 'UTF-8');
						} else {
							$d->nombre_doc_upper = strtoupper($src);
						}
						return $d;
					});
					foreach ($mapOrder as $m) {
						$match = $upperDocs->first(function($d) use ($m){
							$hay = false;
							foreach ($m['aliases'] as $alias) {
								$aliasU = function_exists('mb_strtoupper') ? mb_strtoupper($alias, 'UTF-8') : strtoupper($alias);
								if (str_starts_with($d->nombre_doc_upper, $aliasU) || str_contains($d->nombre_doc_upper, $aliasU)) {
									$hay = true; break;
								}
							}
							return $hay;
						});
						if ($match) {
							$documentoIdentidad = [
								'tipo_identidad' => $m['tipo'],
								'numero' => (string)(isset($match->numero_doc) ? $match->numero_doc : ''),
								'nombre_doc' => (string)(isset($match->nombre_doc) ? $match->nombre_doc : ''),
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

			$grupos = $inscripciones->pluck('cod_curso')
				->filter()
				->unique()
				->values()
				->all();

			$gestionesAll = Inscripcion::where('cod_ceta', $codCeta)
				->orderByDesc('fecha_inscripcion')
				->orderByDesc('created_at')
				->pluck('gestion')
				->filter()
				->unique()
				->values()
				->all();

			// Preparar objeto estudiante con datos adicionales de documentos y de inscripción
			$estudianteData = $estudiante->toArray();
			// Agregar nombre_completo desde el accessor
			$estudianteData['nombre_completo'] = $estudiante->nombre_completo;
			
			// Debug para verificar datos del estudiante antes de devolver
			Log::info('Datos del estudiante a devolver:', [
				'cod_ceta' => $estudianteData['cod_ceta'] ?? 'no existe',
				'nombre' => $estudianteData['nombre'] ?? 'no existe',
				'apellido' => $estudianteData['apellido'] ?? 'no existe',
				'nombre_completo' => $estudianteData['nombre_completo'] ?? 'no existe',
				'ci_original' => $estudianteData['ci'] ?? 'no existe',
				'ci_dp' => $estudiante->dp->ci_doc ?? 'no existe dp'
			]);
			
			if (isset($estudiante->dp)) {
				$estudianteData['ci'] = $estudiante->dp->ci_doc ?: ($estudiante->ci ?: '');
				$estudianteData['telefono'] = $estudiante->dp->telefono_doc ?: '';
				$estudianteData['cedula'] = $estudiante->dp->ci_doc ?: ($estudiante->ci ?: '');
			} else {
				$estudianteData['ci'] = $estudiante->ci ?: '';
				$estudianteData['telefono'] = '';
				$estudianteData['cedula'] = $estudiante->ci ?: '';
			}
			
			// Si tenemos ci_doc pero cedula está vacío, asignar ci_doc a cedula
			if (empty($estudianteData['cedula']) && !empty($estudianteData['ci_doc'])) {
				$estudianteData['cedula'] = $estudianteData['ci_doc'];
				$estudianteData['ci'] = $estudianteData['ci_doc'];
			}
			
			// Agregar datos de la inscripción principal al estudiante
			if ($primaryInscripcion) {
				$estudianteData['carrera'] = $primaryInscripcion->carrera;
				// Obtener resolución desde el pensum de la inscripción
				$resolucion = DB::table('pensums')
					->where('cod_pensum', $primaryInscripcion->cod_pensum)
					->value('resolucion');
				$estudianteData['resolucion'] = $resolucion;
				$estudianteData['gestion'] = $primaryInscripcion->gestion;
				$estudianteData['grupos'] = $primaryInscripcion->cod_curso;
				$estudianteData['descuento'] = isset($primaryInscripcion->descuento) ? $primaryInscripcion->descuento : null;
				$estudianteData['observaciones'] = isset($primaryInscripcion->observaciones) ? $primaryInscripcion->observaciones : null;
			}

			return response()->json([
				'success' => true,
				'data' => [
					'estudiante' => $estudianteData,
					'inscripciones' => $inscripciones,
					'grupos' => $grupos,
					'gestiones_all' => $gestionesAll,
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
					'asignaciones' => $asignacionesPrimarias->map(function($a) use ($descuentosPorAsign){
						return [
// <<<<<<< HEAD
// 							'numero_cuota' => (int) (isset($a->numero_cuota) ? $a->numero_cuota : 0),
// 							'monto' => (float) (isset($a->monto) ? $a->monto : 0),
// 							'monto_pagado' => (float) (isset($a->monto_pagado) ? $a->monto_pagado : 0),
// 							'estado_pago' => (string) (isset($a->estado_pago) ? $a->estado_pago : ''),
// 							'id_asignacion_costo' => (isset($a->id_asignacion_costo) && $a->id_asignacion_costo != 0) ? (int)$a->id_asignacion_costo : null,
// =======
							'numero_cuota' => (int) ($a->numero_cuota ?? 0),
							'monto' => (float) ($a->monto ?? 0),
							'descuento' => (float) ($descuentosPorAsign[(int)($a->id_asignacion_costo ?? 0)] ?? 0),
							'monto_neto' => max(0, (float) ($a->monto ?? 0) - (float) ($descuentosPorAsign[(int)($a->id_asignacion_costo ?? 0)] ?? 0)),
							'monto_pagado' => (float) ($a->monto_pagado ?? 0),
							'estado_pago' => (string) ($a->estado_pago ?? ''),
							'id_asignacion_costo' => (int) ($a->id_asignacion_costo ?? 0) ?: null,
// >>>>>>> db8167bb0a817bf7e0af1d0732b63770d42d68e3
							'id_cuota_template' => isset($a->id_cuota_template) ? ((int)$a->id_cuota_template ?: null) : null,
							'fecha_vencimiento' => $a->fecha_vencimiento,
						];
					})->values(),

					// Calcular mensualidades pagadas y adeudadas a la fecha actual
					'mensualidades' => [
						'pagadas' => $asignacionesPrimarias->filter(function($a){
							// Incluir COBRADO y PARCIAL que tengan fecha de pago
							return ($a->estado_pago === 'COBRADO' || $a->estado_pago === 'PARCIAL') && $a->fecha_pago;
						})->map(function($a){
							$montoOriginal = (float) (isset($a->monto) ? $a->monto : 0);
							$montoPagado = (float) (isset($a->monto_pagado) ? $a->monto_pagado : 0);
							$estadoPago = (string) (isset($a->estado_pago) ? $a->estado_pago : 'COBRADO');
							$numeroCuota = (int) (isset($a->numero_cuota) ? $a->numero_cuota : 0);
							$fechaPago = $a->fecha_pago;
							
							// Logging para depuración
							try { 
								Log::debug('MensualidadPagada', [
									'numero_cuota' => $numeroCuota,
									'monto_original' => $montoOriginal,
									'monto_pagado' => $montoPagado,
									'estado_pago' => $estadoPago,
									'fecha_pago' => $fechaPago
								]); 
							} catch (\Throwable $e) {}
							
							// Lógica simple: el pago con fecha más reciente es parcial, los demás son completos
							$montoAMostrar = $montoPagado > 0 ? $montoPagado : $montoOriginal;
							
							return [
								'numero_cuota' => $numeroCuota,
								'monto' => (float) $montoAMostrar,
								'monto_pagado' => $montoPagado,
								'estado_pago' => $estadoPago,
								'fecha_pago' => $fechaPago,
								'fecha_vencimiento' => $a->fecha_vencimiento,
								'id_asignacion_costo' => (isset($a->id_asignacion_costo) && $a->id_asignacion_costo != 0) ? (int)$a->id_asignacion_costo : null,
								'id_cuota_template' => isset($a->id_cuota_template) ? $a->id_cuota_template : null,
								'nro_cobro' => null,
								'nro_factura' => isset($a->nro_factura) ? $a->nro_factura : null,
								'nro_recibo' => isset($a->nro_recibo) ? $a->nro_recibo : null,
								'fecha_cobro' => $fechaPago,
								'es_completo' => 'TEMP', // Se marcará después
							];
						})->values()->map(function($item, $index){
							// Simple: marcar el último como 'No', los demás como 'Si'
							$totalItems = 3; // Asumimos 3 pagos basados en tu ejemplo
							$esCompleto = $index === ($totalItems - 1) ? 'No' : 'Si';
							
							// Agregar múltiples campos para depuración
							$item['es_completo'] = $esCompleto;
							$item['completo'] = $esCompleto;
							$item['total'] = $esCompleto;
							$item['es_completo_flag'] = $esCompleto === 'Si';
							
							return $item;
						})->values(),
						'adeudadas' => $asignacionesPrimarias->filter(function($a){
							// Incluir PARCIAL (deben mostrar el saldo restante) y otros estados no cobrados
							// Incluir todas las cuotas no cobradas (vencidas y no vencidas)
							return $a->estado_pago !== 'COBRADO';
						})->values(),
					],
					'asignaciones_arrastre' => ($asignacionesArrastre && $asignacionesArrastre->count() > 0) ? $asignacionesArrastre->map(function($a) use ($descuentosPorAsignArrastre){
						return [
							'numero_cuota' => (int) ($a->numero_cuota ?? 0),
							'monto' => (float) ($a->monto ?? 0),
							'descuento' => (float) ($descuentosPorAsignArrastre[(int)($a->id_asignacion_costo ?? 0)] ?? 0),
							'monto_neto' => max(0, (float) ($a->monto ?? 0) - (float) ($descuentosPorAsignArrastre[(int)($a->id_asignacion_costo ?? 0)] ?? 0)),
							'monto_pagado' => (float) ($a->monto_pagado ?? 0),
							'estado_pago' => (string) ($a->estado_pago ?? ''),
							'id_asignacion_costo' => (int) ($a->id_asignacion_costo ?? 0) ?: null,
							'id_cuota_template' => isset($a->id_cuota_template) ? ((int)$a->id_cuota_template ?: null) : null,
							'fecha_vencimiento' => $a->fecha_vencimiento,
						];
					})->values() : [],
					'arrastre' => $arrastreSummary,
					'cobros' => [
						'mensualidad' => [
							'total' => (float) $totalMensualidad,
							'count' => $cobrosMensualidad->count(),
							'items' => $cobrosMensualidad->map(function($cobro) {
								$cobroArray = $cobro->toArray();
								// Agregar datos de razón social/NIT desde recibo o factura
								$cobroArray['cliente'] = $cobro->recibo?->cliente ?? $cobro->factura?->cliente ?? null;
								$cobroArray['nro_documento_cobro'] = $cobro->recibo?->nro_documento_cobro ?? $cobro->factura?->nro_documento_cobro ?? null;
								return $cobroArray;
							}),
						],
						'items' => [
							'total' => (float) $totalItems,
							'count' => $cobrosItems->count(),
							'items' => $cobrosItems->map(function($cobro) {
								$cobroArray = $cobro->toArray();
								// Agregar datos de razón social/NIT desde recibo o factura
								$cobroArray['cliente'] = $cobro->recibo?->cliente ?? $cobro->factura?->cliente ?? null;
								$cobroArray['nro_documento_cobro'] = $cobro->recibo?->nro_documento_cobro ?? $cobro->factura?->nro_documento_cobro ?? null;
								return $cobroArray;
							}),
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
						'descuentos' => (float) $totalDescuentos,
						'saldo_mensualidad' => $saldoMensualidad,
						'total_pagado' => (float) $totalPagadoDesdeAsignaciones,
						'total_pagado_v2' => (float) $totalMensualidadCompletas,
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
			'items.*.cod_tipo_cobro' => 'nullable|string|exists:tipo_cobro,cod_tipo_cobro',
			'items.*.concepto' => 'nullable|string',
			
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
			'pagos.*.cod_tipo_cobro' => 'nullable|string|exists:tipo_cobro,cod_tipo_cobro',
			'pagos.*.concepto' => 'nullable|string',
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
					foreach ($itemsInput as $it) { $totalMonto += (float)(isset($it['monto']) ? $it['monto'] : 0); }
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
								'id_qr_transaccion' => isset($recentTrx->id_qr_transaccion) ? $recentTrx->id_qr_transaccion : null,
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
			$emitGroupMeta = null; // meta para emisión agrupada post-commit
			DB::transaction(function () use ($request, $items, $reciboService, $facturaService, $cufdRepo, $ops, $cufGen, $payloadBuilder, $cuisRepo, &$results, &$emitGroupMeta) {
				$pv = (int) ($request->input('codigo_punto_venta', 0));
				$sucursal = (int) ($request->input('codigo_sucursal', config('sin.sucursal')));
				$emitirOnline = (bool) $request->boolean('emitir_online', false);
				$emitirOnlineAuto = false;
				try {
					foreach ((array)$items as $autoIt) {
						$td = strtoupper((string)(isset($autoIt['tipo_documento']) ? $autoIt['tipo_documento'] : ''));
						$md = strtoupper((string)(isset($autoIt['medio_doc']) ? $autoIt['medio_doc'] : ''));
						if ($td === 'F' && $md === 'C') { $emitirOnlineAuto = true; break; }
					}
				} catch (\Throwable $e) {}
				if (!$emitirOnline) { $emitirOnline = $emitirOnlineAuto; }
				try {
					Log::warning('batchStore: emitir_online decision', [
						'request_flag' => (bool)$request->boolean('emitir_online', false),
						'auto_flag' => $emitirOnlineAuto,
						'final' => $emitirOnline,
						'offline' => (bool) config('sin.offline'),
					]);
				} catch (\Throwable $e) {}

				// Contexto de inscripción principal del estudiante (para derivar asignación de costos)
				$codCetaCtx = (int) $request->cod_ceta;
				$codPensumCtx = (string) $request->cod_pensum;
				$gestionCtx = $request->gestion;
				// Preferir la inscripción solicitada explícitamente por el frontend (cod_inscrip o tipo_inscripcion)
				$tipoInsReq = (string) $request->tipo_inscripcion;
				$codInsReq = $request->cod_inscrip;
				$insList = Inscripcion::query()
					->where('cod_ceta', $codCetaCtx)
					->when($gestionCtx, function($q) use ($gestionCtx){ $q->where('gestion', $gestionCtx); })
					->orderByDesc('fecha_inscripcion')
					->orderByDesc('created_at')
					->get();
				$primaryInscripcion = null;
				if ($codInsReq) {
					$primaryInscripcion = $insList->firstWhere('cod_inscrip', (int)$codInsReq);
				}
				if (!$primaryInscripcion && $tipoInsReq !== '') {
					$primaryInscripcion = $insList->firstWhere('tipo_inscripcion', (string)$tipoInsReq);
				}
				if (!$primaryInscripcion) {
					$primaryInscripcion = $insList->firstWhere('tipo_inscripcion', 'NORMAL') ?: $insList->first();
				}
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
						->map(function($v) { return (int)$v; })
						->values();
				}
				// Eliminamos tracking por asignación única; usaremos $batchPaidByTpl para decidir la siguiente cuota
				
				// APLICAR DESCUENTO AUTOMÁTICO DE SEMESTRE COMPLETO ANTES DE PROCESAR COBROS
				Log::info('batchStore: INICIO verificación descuento automático');
				try {
					// Obtener parámetros económicos
					$descActivar = ParametrosEconomicos::where('nombre', 'descuento_semestre_completo_activar')->where('estado', true)->first();
					$descFechaLimite = ParametrosEconomicos::where('nombre', 'descuento_semestre_completo_fecha_limite')->where('estado', true)->first();
					$descPorcentaje = ParametrosEconomicos::where('nombre', 'descuento_semestre_completo_porcentaje')->where('estado', true)->first();
					
					$activar = $descActivar && ($descActivar->valor === 'true' || $descActivar->valor === '1');
					$fechaLimite = $descFechaLimite ? $descFechaLimite->valor : null;
					$porcentaje = $descPorcentaje ? (float)$descPorcentaje->valor : 0;
					
					Log::info('batchStore: parámetros descuento', [
						'activar' => $activar,
						'porcentaje' => $porcentaje,
						'tiene_inscripcion' => $primaryInscripcion ? true : false,
					]);
					
					// Verificar si está activo y dentro de fecha
					if ($activar && $porcentaje > 0 && $primaryInscripcion) {
						Log::info('batchStore: condiciones iniciales OK');
						$dentroFecha = true;
						if ($fechaLimite) {
							$hoy = now();
							$limite = \Carbon\Carbon::parse($fechaLimite);
							$dentroFecha = $hoy->lte($limite);
						}
						
						if ($dentroFecha) {
							Log::info('batchStore: dentro de fecha OK');
							// Contar cuotas totales pendientes (sin descuento previo y sin cobrar)
							$cuotasPendientes = AsignacionCostos::where('cod_pensum', $codPensumCtx)
								->where('cod_inscrip', $primaryInscripcion->cod_inscrip)
								->whereNull('id_descuentoDetalle')
								->where(function($q) {
									$q->where('estado_pago', 'pendiente')
									  ->orWhere('estado_pago', 'PARCIAL')
									  ->orWhereNull('estado_pago');
								})
								->get();
							
							$cuotasTotales = $cuotasPendientes->count();
							
							// Obtener IDs de cuotas que se van a pagar en este batch
							$cuotasPagadasBatch = collect($items)
								->filter(fn($it) => isset($it['id_asignacion_costo']) && $it['id_asignacion_costo'])
								->pluck('id_asignacion_costo')
								->unique()
								->values();
							
							$cantidadPagadasBatch = $cuotasPagadasBatch->count();
							
							Log::info('batchStore: conteo cuotas', [
								'cuotas_totales' => $cuotasTotales,
								'cuotas_batch' => $cantidadPagadasBatch,
								'ids' => $cuotasPagadasBatch->toArray(),
							]);
							
							// Si se van a pagar todas las cuotas pendientes, crear asignación automática
							if ($cuotasTotales > 0 && $cantidadPagadasBatch >= $cuotasTotales) {
								Log::info('batchStore: CONDICIÓN CUMPLIDA - creando descuento');
								// Buscar el descuento "Descuento Pago Semestre completo" (cod_beca = 40)
								$codBeca = 40;
								$defDescuento = DB::table('def_descuentos_beca')->where('cod_beca', $codBeca)->first();
								
								if ($defDescuento) {
									// Verificar que no exista ya una asignación de este descuento
									$existeAsignacion = Descuento::where('cod_ceta', $codCetaCtx)
										->where('cod_pensum', $codPensumCtx)
										->where('cod_inscrip', $primaryInscripcion->cod_inscrip)
										->where('cod_beca', $codBeca)
										->where('estado', true)
										->exists();
									
									if (!$existeAsignacion) {
										// Crear el descuento principal
										$descuento = Descuento::create([
											'cod_ceta' => $codCetaCtx,
											'cod_pensum' => $codPensumCtx,
											'cod_inscrip' => $primaryInscripcion->cod_inscrip,
											'cod_beca' => $codBeca,
											'id_usuario' => (int)$request->id_usuario,
											'nombre' => $defDescuento->nombre_beca ?? 'Descuento Pago Semestre completo',
											'observaciones' => 'Asignación automática por pago de semestre completo',
											'porcentaje' => $porcentaje,
											'tipo' => $primaryInscripcion->tipo_inscripcion ?? 'NORMAL',
											'estado' => true,
										]);
										
										// Extraer turno y semestre del código de pensum
										$turno = null;
										$semestre = null;
										if ($codPensumCtx) {
											// Formato: EEA-101N → semestre=1, turno=N
											$parts = explode('-', $codPensumCtx);
											if (count($parts) >= 2) {
												$codigo = $parts[1];
												// Extraer semestre (primer dígito después del guion)
												if (preg_match('/^(\d)/', $codigo, $matches)) {
													$semestre = (int)$matches[1];
												}
												// Extraer turno (última letra: M=mañana, T=tarde, N=noche)
												$ultimaLetra = strtoupper(substr($codigo, -1));
												if (in_array($ultimaLetra, ['M', 'T', 'N'])) {
													$turno = $ultimaLetra;
												}
											}
										}
										
										// Crear detalles para cada cuota que se va a pagar
										foreach ($cuotasPagadasBatch as $idAsignCosto) {
											try {
												$asignCosto = AsignacionCostos::find($idAsignCosto);
												if ($asignCosto && !$asignCosto->id_descuentoDetalle) {
													$montoDescuento = round(($asignCosto->monto * $porcentaje) / 100, 2);
													
													$detalle = DescuentoDetalle::create([
														'id_descuento' => $descuento->id_descuentos,
														'id_usuario' => (int)$request->id_usuario,
														'id_inscripcion' => $primaryInscripcion->cod_inscrip,
														'id_cuota' => $idAsignCosto,
														'monto_descuento' => $montoDescuento,
														'fecha_registro' => now(),
														'fecha_solicitud' => now(),
														'observaciones' => 'Descuento automático de semestre completo',
														'tipo_inscripcion' => $primaryInscripcion->tipo_inscripcion ?? 'NORMAL',
														'turno' => $turno,
														'semestre' => $semestre,
														'estado' => true,
													]);
													
													// Actualizar asignacion_costos con el id_descuentoDetalle
													$asignCosto->id_descuentoDetalle = $detalle->id_descuento_detalle;
													$asignCosto->save();
													
													Log::info('batchStore: descuento detalle creado', [
														'id_descuento_detalle' => $detalle->id_descuento_detalle,
														'id_asignacion_costo' => $idAsignCosto,
														'monto_descuento' => $montoDescuento,
													]);
												}
											} catch (\Throwable $eDetalle) {
												Log::error('batchStore: error al crear detalle de descuento', [
													'id_asignacion_costo' => $idAsignCosto,
													'error' => $eDetalle->getMessage(),
												]);
											}
										}
										
										// Recargar asignaciones para que tengan los descuentos actualizados
										$asignPrimarias = AsignacionCostos::where('cod_pensum', $codPensumCtx)
											->where('cod_inscrip', $primaryInscripcion->cod_inscrip)
											->orderBy('numero_cuota')
											->get();
										
										Log::info('batchStore: descuento automático aplicado', [
											'id_descuento' => $descuento->id_descuentos,
											'porcentaje' => $porcentaje,
											'cuotas_procesadas' => $cantidadPagadasBatch,
										]);
									}
								}
							}
						}
					}
				} catch (\Throwable $e) {
					Log::error('batchStore: error al aplicar descuento automático', [
						'error' => $e->getMessage(),
						'line' => $e->getLine(),
					]);
				}
				
				// Preparar nickname de usuario y forma de cobro para anotar en notas
				$usuarioNick = (string) (DB::table('usuarios')->where('id_usuario', (int)$request->id_usuario)->value('nickname') ? DB::table('usuarios')->where('id_usuario', (int)$request->id_usuario)->value('nickname') : '');
				$formaRow = DB::table('formas_cobro')->where('id_forma_cobro', (string)$request->id_forma_cobro)->first();
				$formaNombre = strtoupper(trim((string)(isset($formaRow->nombre) ? $formaRow->nombre : (isset($formaRow->descripcion) ? $formaRow->descripcion : (isset($formaRow->label) ? $formaRow->label : '')))));
				// Normalizar acentos
				$formaNombre = iconv('UTF-8','ASCII//TRANSLIT',$formaNombre);
				$formaCode = strtoupper(trim((string)(isset($formaRow->id_forma_cobro) ? $formaRow->id_forma_cobro : '')));

				// Agrupación de FACTURA computarizada por lote: preparar variables del grupo
				$factGroupIdx = [];
				$factMontoTotal = 0.0;
				foreach ((array)$items as $gi => $gIt) {
					$td = strtoupper((string)(isset($gIt['tipo_documento']) ? $gIt['tipo_documento'] : ''));
					$md = strtoupper((string)(isset($gIt['medio_doc']) ? $gIt['medio_doc'] : ''));
					if ($td === 'F' && $md === 'C') { $factGroupIdx[] = (int)$gi; $factMontoTotal += (float)(isset($gIt['monto']) ? $gIt['monto'] : 0); }
				}
				$hasFacturaGroup = count($factGroupIdx) > 0;
				$nroFacturaGroup = null; $anioFacturaGroup = null; $cufGroup = null; $cufdGroup = null; $fechaEmisionIsoGroup = null; $factDetalles = [];
				if ($hasFacturaGroup) {
					// Preparar CUFD, correlativo y CUF una sola vez
					$cufd = $cufdRepo->getVigenteOrCreate($pv);
					$anioFacturaGroup = (int) date('Y');
					$nroFacturaGroup = method_exists($facturaService, 'nextFacturaAtomic')
						? $facturaService->nextFacturaAtomic($anioFacturaGroup, $sucursal, (string)$pv)
						: $facturaService->nextFactura($anioFacturaGroup, $sucursal, (string)$pv);
					// Usar misma hora para CUF y XML
					$tEmision = \Carbon\Carbon::now('America/La_Paz');
					$fechaEmisionIsoGroup = $tEmision->format('Y-m-d\\TH:i:s.000');
					$cufData = $cufGen->generate((int) config('sin.nit'), $fechaEmisionIsoGroup, $sucursal, (int) config('sin.modalidad'), 1, (int) config('sin.tipo_factura'), (int) config('sin.cod_doc_sector'), (int) $nroFacturaGroup, (int) $pv);
					$cufGroup = ((string)(isset($cufData['cuf']) ? $cufData['cuf'] : '')) . (string)(isset($cufd['codigo_control']) ? $cufd['codigo_control'] : '');
					$cufdGroup = isset($cufd['codigo_cufd']) ? $cufd['codigo_cufd'] : null;
					$cuisGroup = isset($cufd['codigo_cuis']) ? (string)$cufd['codigo_cuis'] : null;
					// Crear una sola factura local con el total del lote
					$cliInGroup = (array) ($request->input('cliente', []));
                    $cliNameGroup = (string)(isset($cliInGroup['razon']) ? $cliInGroup['razon'] : (isset($cliInGroup['razon_social']) ? $cliInGroup['razon_social'] : ''));
					$cliNumeroGroup = (string)(isset($cliInGroup['numero']) ? $cliInGroup['numero'] : '');
					$facturaService->createComputarizada($anioFacturaGroup, (int)$nroFacturaGroup, [
						'codigo_sucursal' => $sucursal,
						'codigo_punto_venta' => (string)$pv,
						'fecha_emision' => $fechaEmisionIsoGroup,
						'cod_ceta' => (int)$request->cod_ceta,
						'id_usuario' => (int)$request->id_usuario,
						'id_forma_cobro' => (string)(isset($request->id_forma_cobro) ? $request->id_forma_cobro : ''),
						'monto_total' => (float)$factMontoTotal,
						'cliente' => $cliNameGroup,
						'nro_documento_cobro' => $cliNumeroGroup,
						'codigo_cufd' => $cufdGroup,
						'cuf' => $cufGroup,
						'periodo_facturado' => $gestionCtx,
					]);
					try { \Log::warning('batchStore: factura C creada (local, grupo)', [ 'anio' => $anioFacturaGroup, 'nro_factura' => (int)$nroFacturaGroup, 'monto_total' => (float)$factMontoTotal ]); } catch (\Throwable $e) {}
					// Inicializar contenedor para emisión post-commit
					$emitGroupMeta = [
						'anio' => (int)$anioFacturaGroup,
						'nro' => (int)$nroFacturaGroup,
						'sucursal' => (int)$sucursal,
						'pv' => (int)$pv,
						'cuf' => (string)$cufGroup,
						'cufd' => (string)(isset($cufdGroup) ? $cufdGroup : ''),
						'cuis' => (string)(isset($cuisGroup) ? $cuisGroup : ''),
						'fecha_iso' => (string)$fechaEmisionIsoGroup,
						'monto_total' => (float)$factMontoTotal,
						'detalles' => [],
					];
				}

				// Control para agrupar Recibos computarizados en un único nro_recibo por transacción
				$nroReciboBatch = null; $anioReciboBatch = null;
				foreach ($items as $idx => $item) {
					// Asignar SIEMPRE un correlativo atómico global para garantizar unicidad
					$anioItem = (int) date('Y', strtotime((string)(isset($item['fecha_cobro']) ? $item['fecha_cobro'] : date('Y-m-d'))));
					$scopeCobro = 'COBRO:' . $anioItem;
					DB::statement(
						"INSERT INTO doc_counter (scope, last, created_at, updated_at) VALUES (?, 1, NOW(), NOW())\n"
						. "ON DUPLICATE KEY UPDATE last = LAST_INSERT_ID(last + 1), updated_at = NOW()",
						[$scopeCobro]
					);
					$row = DB::selectOne('SELECT LAST_INSERT_ID() AS id');
					$nroCobro = (int)(isset($row->id) ? $row->id : 0);
					Log::info('batchStore:nroCobro', [ 'idx' => $idx, 'nro' => $nroCobro ]);
					$composite = [
						'cod_ceta' => (int)$request->cod_ceta,
						'cod_pensum' => (string)$request->cod_pensum,
						'tipo_inscripcion' => (string)$request->tipo_inscripcion,
						'nro_cobro' => $nroCobro,
						'anio_cobro' => $anioItem,
					];

					$tipoDoc = strtoupper((string)(isset($item['tipo_documento']) ? $item['tipo_documento'] : ''));
					$medioDoc = strtoupper((string)(isset($item['medio_doc']) ? $item['medio_doc'] : ''));
					Log::info('batchStore:item', [ 'idx' => $idx, 'tipo' => $tipoDoc, 'medio' => $medioDoc ]);
					$codigoRecepcionLocal = null; $cufLocal = null; $estadoFacturaLocal = null; $mensajeLocal = null;

					$formaIdItem = (string)(isset($item['id_forma_cobro']) ? $item['id_forma_cobro'] : $request->id_forma_cobro);
					try {
						$formaRowItem = DB::table('formas_cobro')->where('id_forma_cobro', $formaIdItem)->first();
						$formaNombre = strtoupper(trim((string)(isset($formaRowItem->nombre) ? $formaRowItem->nombre : (isset($formaRowItem->descripcion) ? $formaRowItem->descripcion : (isset($formaRowItem->label) ? $formaRowItem->label : '')))));
						$formaNombre = iconv('UTF-8','ASCII//TRANSLIT',$formaNombre);
						$formaCode = strtoupper(trim((string)(isset($formaRowItem->id_forma_cobro) ? $formaRowItem->id_forma_cobro : '')));
					} catch (\Throwable $e) {}
					try { Log::info('batchStore:forma', [ 'idx' => $idx, 'id_forma_cobro' => $formaIdItem, 'nombre' => isset($formaNombre) ? $formaNombre : null, 'code' => isset($formaCode) ? $formaCode : null ]); } catch (\Throwable $e) {}

					// Permitir inserción desde submit manual para QR/TRANSFERENCIA; el callback ya no inserta

					$nroRecibo = isset($item['nro_recibo']) ? $item['nro_recibo'] : null;
					$nroFactura = isset($item['nro_factura']) ? $item['nro_factura'] : null;
					$cliente = $request->input('cliente', []);
					// Usar el código de tipo de documento (pequeño) y no el número del documento para evitar overflow y respetar semántica
					$codTipoDocIdentidad = (int)(isset($cliente['tipo_identidad']) ? $cliente['tipo_identidad'] : 1);
					$numeroDoc = trim((string) (isset($cliente['numero']) ? $cliente['numero'] : ''));

					// Documentos
					if ($tipoDoc === 'R') {
						$anio = (int) date('Y', strtotime($item['fecha_cobro']));
						if ($medioDoc === 'C') {
							if ($nroReciboBatch === null) {
								$cliInRec = (array) ($request->input('cliente', []));
								$cliNameRec = (string)(isset($cliInRec['razon']) ? $cliInRec['razon'] : (isset($cliInRec['razon_social']) ? $cliInRec['razon_social'] : ''));
								$cliNumeroRec = (string)(isset($cliInRec['numero']) ? $cliInRec['numero'] : '');
								$nroRecibo = $reciboService->nextReciboAtomic($anio);
								$reciboService->create($anio, $nroRecibo, [
									'id_usuario' => (int)$request->id_usuario,
									'cliente' => $cliNameRec,
									'nro_documento_cobro' => $cliNumeroRec,
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
							$cliInRecM = (array) ($request->input('cliente', []));
							$cliNameRecM = (string)(isset($cliInRecM['razon']) ? $cliInRecM['razon'] : (isset($cliInRecM['razon_social']) ? $cliInRecM['razon_social'] : ''));
							$cliNumeroRecM = (string)(isset($cliInRecM['numero']) ? $cliInRecM['numero'] : '');
							$reciboService->create($anio, (int)$nroRecibo, [
								'id_usuario' => (int)$request->id_usuario,
								'cliente' => $cliNameRecM,
								'nro_documento_cobro' => $cliNumeroRecM,
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
							if ($hasFacturaGroup) {
								// Reusar la factura del grupo
								$nroFactura = (int)$nroFacturaGroup;
								$cufLocal = $cufGroup;
								$fechaEmisionIso = $fechaEmisionIsoGroup;
							} else {
								// Modo antiguo: una factura por ítem
								$cufd = $cufdRepo->getVigenteOrCreate($pv);
								$nroFactura = method_exists($facturaService, 'nextFacturaAtomic')
									? $facturaService->nextFacturaAtomic($anio, $sucursal, (string)$pv)
									: $facturaService->nextFactura($anio, $sucursal, (string)$pv);
								Log::info('batchStore: factura C', [ 'idx' => $idx, 'anio' => $anio, 'sucursal' => $sucursal, 'pv' => $pv, 'nro' => $nroFactura ]);
								$tEmision = Carbon::now('America/La_Paz');
								$fechaEmision = $tEmision->format('Y-m-d H:i:s.u');
								$fechaEmisionIso = $tEmision->format('Y-m-d\\TH:i:s.000');
								$cufData = $cufGen->generate((int) config('sin.nit'), $fechaEmisionIso, $sucursal, (int) config('sin.modalidad'), 1, (int) config('sin.tipo_factura'), (int) config('sin.cod_doc_sector'), (int) $nroFactura, (int) $pv);
								$cuf = ((string)(isset($cufData['cuf']) ? $cufData['cuf'] : '')) . (string)(isset($cufd['codigo_control']) ? $cufd['codigo_control'] : '');
								$cufLocal = $cuf;
								Log::debug('batchStore:cuf_debug', [ 'componentes' => isset($cufData['componentes']) ? $cufData['componentes'] : [], 'decimal' => isset($cufData['decimal']) ? $cufData['decimal'] : null, 'dv' => isset($cufData['dv']) ? $cufData['dv'] : null, 'cuf_hex' => isset($cufData['cuf']) ? $cufData['cuf'] : null, 'cuf_final' => $cuf, 'codigo_control' => isset($cufd['codigo_control']) ? $cufd['codigo_control'] : null, 'fecha_emision_iso' => $fechaEmisionIso ]);
								$cliIn2 = (array) ($request->input('cliente', []));
                                $cliName2 = (string)(isset($cliIn2['razon']) ? $cliIn2['razon'] : (isset($cliIn2['razon_social']) ? $cliIn2['razon_social'] : ''));
								$cliNumero2 = (string)(isset($cliIn2['numero']) ? $cliIn2['numero'] : '');
								$facturaService->createComputarizada($anio, $nroFactura, [
									'codigo_sucursal' => $sucursal,
									'codigo_punto_venta' => (string)$pv,
									'fecha_emision' => $fechaEmisionIso,
									'cod_ceta' => (int)$request->cod_ceta,
									'id_usuario' => (int)$request->id_usuario,
									'id_forma_cobro' => $formaIdItem,
									'monto_total' => (float)$item['monto'],
									'periodo_facturado' => isset($request->gestion) ? $request->gestion : null,
									'cliente' => $cliName2,
									'nro_documento_cobro' => $cliNumero2,
									'codigo_cufd' => isset($cufd['codigo_cufd']) ? $cufd['codigo_cufd'] : null,
									'cuf' => $cuf,
								]);
								try { Log::warning('batchStore: factura C creada (local)', [ 'anio' => $anio, 'nro_factura' => (int)$nroFactura, 'sucursal' => $sucursal, 'pv' => $pv, 'cuf' => $cuf, 'cufd' => isset($cufd['codigo_cufd']) ? $cufd['codigo_cufd'] : null, 'monto_total' => (float)$item['monto'] ]); } catch (\Throwable $e) {}
							}
							// Paso 3: emisión online (opcional). Si hay agrupación, se difiere al final del loop.
							if ($emitirOnline && !$hasFacturaGroup) {
								if (config('sin.offline')) {
									Log::warning('batchStore: skip recepcionFactura (OFFLINE)');
								} else {
									try {
										// Obtener CUIS vigente requerido por recepcionFactura
										$cuisRow = $cuisRepo->getVigenteOrCreate($pv);
										$cuisCode = isset($cuisRow['codigo_cuis']) ? $cuisRow['codigo_cuis'] : '';
										// Mapear cliente a las claves esperadas por el builder
										$cliIn = (array) $request->input('cliente', []);
										$cliente = [
											'tipo_doc' => isset($cliIn['tipo_doc']) ? (int)$cliIn['tipo_doc'] : (int)(isset($cliIn['tipo_identidad']) ? $cliIn['tipo_identidad'] : 5),
											'numero' => (string)(isset($cliIn['numero']) ? $cliIn['numero'] : ''),
											'razon' => (string)(isset($cliIn['razon']) ? $cliIn['razon'] : (isset($cliIn['razon_social']) ? $cliIn['razon_social'] : 'S/N')),
											'complemento' => isset($cliIn['complemento']) ? $cliIn['complemento'] : null,
											'codigo' => (string)(isset($cliIn['codigo']) ? $cliIn['codigo'] : (isset($cliIn['numero']) ? $cliIn['numero'] : '0')),
										];
										// Detalle por defecto sector educativo (docSector 11): producto SIN 99100 y unidad 58
										$detalle = [
											'codigo_sin' => 99100,
											'codigo' => 'ITEM-' . (int)$nroCobro,
											'descripcion' => isset($item['observaciones']) ? $item['observaciones'] : 'Cobro',
											'cantidad' => 1,
											'unidad_medida' => 58,
											'precio_unitario' => (float)$item['monto'],
											'descuento' => 0,
											'subtotal' => (float)$item['monto'],
										];

										// Obtener CUFD vigente (usa cache si está vigente, sino solicita uno nuevo al SIN)
										try {
											$cufdNow = $cufdRepo->getVigenteOrCreate($pv);
											$cufd = $cufdNow;
											$cuisCode = isset($cufdNow['codigo_cuis']) ? $cufdNow['codigo_cuis'] : $cuisCode;
											
											// Recalcular CUF con el CUFD vigente actual
											$gen = $cufGen->generate((int) config('sin.nit'), $fechaEmisionIso, $sucursal, (int) config('sin.modalidad'), 1, (int) config('sin.tipo_factura'), (int) config('sin.cod_doc_sector'), (int) $nroFactura, (int) $pv);
											$cuf = ((string)(isset($gen['cuf']) ? $gen['cuf'] : '')) . (string)(isset($cufd['codigo_control']) ? $cufd['codigo_control'] : '');
											$cufLocal = $cuf;
											
											// Persistir nuevos valores en la factura local
											\DB::table('factura')
												->where('anio', $anio)
												->where('nro_factura', $nroFactura)
												->where('codigo_sucursal', $sucursal)
												->where('codigo_punto_venta', (string)$pv)
												->update(['codigo_cufd' => (string)(isset($cufd['codigo_cufd']) ? $cufd['codigo_cufd'] : ''), 'cuf' => (string)$cuf]);
											
											Log::info('batchStore: CUFD obtenido (individual)', ['cufd' => isset($cufd['codigo_cufd']) ? $cufd['codigo_cufd'] : null, 'codigo_control' => isset($cufd['codigo_control']) ? $cufd['codigo_control'] : null, 'cuf' => $cuf]);
										} catch (\Throwable $e) {
											Log::error('batchStore: Error obteniendo CUFD (individual)', ['error' => $e->getMessage()]);
										}
										$payloadArgs = [
											'nit' => (int) config('sin.nit'),
											'cod_sistema' => (string) config('sin.cod_sistema'),
											'ambiente' => (int) config('sin.ambiente'),
											'modalidad' => (int) config('sin.modalidad'),
											'tipo_factura' => (int) config('sin.tipo_factura'),
											'doc_sector' => (int) config('sin.cod_doc_sector'),
											'tipo_emision' => 1,
											'sucursal' => $sucursal,
											'punto_venta' => $pv,
											'cuis' => (isset($cufd['codigo_cuis']) ? $cufd['codigo_cuis'] : $cuisCode),
											'cufd' => (string)(isset($cufd['codigo_cufd']) ? $cufd['codigo_cufd'] : ''),
											'cuf' => (string)$cuf,
											'fecha_emision' => (string)$fechaEmisionIso,
											'periodo_facturado' => isset($request->gestion) ? $request->gestion : null,
											'monto_total' => (float) $item['monto'],
											'numero_factura' => (int) $nroFactura,
											'id_forma_cobro' => $formaIdItem,
											'cliente' => $cliente,
											'detalle' => $detalle,
										];
										$payload = $payloadBuilder->buildRecepcionFacturaPayload($payloadArgs);
										Log::debug('batchStore: factura build payload args', [
											'anio' => $anio,
											'nro_factura' => $nroFactura,
											'sucursal' => $sucursal,
											'pv' => $pv,
											'cuf' => $cuf,
											'cufd' => isset($cufd['codigo_cufd']) ? $cufd['codigo_cufd'] : null,
											'cuis' => $cuisCode,
											'monto_total' => (float) $item['monto'],
											'cliente' => $cliente,
										]);
										$payload = $payloadBuilder->buildRecepcionFacturaPayload($payloadArgs);
										Log::warning('batchStore: calling recepcionFactura', [
											'anio' => $anio,
											'nro_factura' => $nroFactura,
											'punto_venta' => $pv,
											'sucursal' => $sucursal,
											'payload_meta' => [
												'codigoAmbiente' => isset($payload['codigoAmbiente']) ? $payload['codigoAmbiente'] : null,
												'codigoModalidad' => isset($payload['codigoModalidad']) ? $payload['codigoModalidad'] : null,
												'codigoDocumentoSector' => isset($payload['codigoDocumentoSector']) ? $payload['codigoDocumentoSector'] : null,
												'tipoFacturaDocumento' => isset($payload['tipoFacturaDocumento']) ? $payload['tipoFacturaDocumento'] : null,
												'len_archivo' => isset($payload['archivo']) ? strlen($payload['archivo']) : null,
												'hashArchivo' => isset($payload['hashArchivo']) ? $payload['hashArchivo'] : null,
											],
										]);
										$resp = $ops->recepcionFactura($payload);
										$root = isset($resp['RespuestaServicioFacturacion']) ? $resp['RespuestaServicioFacturacion'] : (isset($resp['RespuestaRecepcionFactura']) ? $resp['RespuestaRecepcionFactura'] : (is_array($resp) ? reset($resp) : null));
										$codRecep = is_array($root) ? (isset($root['codigoRecepcion']) ? $root['codigoRecepcion'] : null) : null;
										try {
											$estadoCod = is_array($root) && isset($root['codigoEstado']) ? (int)$root['codigoEstado'] : null;
											$mensajes = is_array($root) ? (isset($root['mensajesList']) ? $root['mensajesList'] : null) : null;
											if ($mensajes) {
												if (isset($mensajes['descripcion'])) { $mensajeLocal = (string)$mensajes['descripcion']; }
												elseif (is_array($mensajes) && isset($mensajes[0]['descripcion'])) { $mensajeLocal = (string)$mensajes[0]['descripcion']; }
											}
											Log::warning('batchStore: recepcionFactura response meta', [
												'anio' => $anio,
												'nro_factura' => (int)$nroFactura,
												'estado' => $estadoCod,
												'codigo_recepcion' => $codRecep,
												'mensaje' => $mensajeLocal,
											]);
										} catch (\Throwable $e) {}
										if ($codRecep) {
											$codigoRecepcionLocal = $codRecep;
											$estadoFacturaLocal = 'ACEPTADA';
											\DB::table('factura')
												->where('anio', $anio)
												->where('nro_factura', $nroFactura)
												->where('codigo_sucursal', $sucursal)
												->where('codigo_punto_venta', (string)$pv)
												->update(['codigo_recepcion' => $codRecep, 'estado' => 'ACEPTADA']);
											Log::warning('batchStore: recepcionFactura ok', [ 'codigo_recepcion' => $codRecep ]);
										} else {
											$estadoFacturaLocal = 'RECHAZADA';
											$mensajeRechazo = isset($mensajeLocal) ? $mensajeLocal : 'Factura rechazada por el SIN';
											\DB::table('factura')
												->where('anio', $anio)
												->where('nro_factura', $nroFactura)
												->where('codigo_sucursal', $sucursal)
												->where('codigo_punto_venta', (string)$pv)
												->update(['estado' => 'RECHAZADA']);
											Log::warning('batchStore: recepcionFactura sin codigoRecepcion', [ 'resp' => $resp, 'mensaje' => $mensajeRechazo ]);
											// Agregar información de error al resultado
											$facturaError = [
												'estado' => 'RECHAZADA',
												'mensaje' => $mensajeRechazo,
												'anio' => $anio,
												'nro_factura' => $nroFactura
											];
										}
									} catch (\Throwable $e) {
										Log::error('batchStore: recepcionFactura exception', [ 'error' => $e->getMessage() ]);
									}
								}
							} elseif (!$emitirOnline) {
								// emitir_online = false: registrar causa (sin emisión manual aquí)
								try { Log::warning('batchStore: emitir_online=false, no se invoca recepcionFactura'); } catch (\Throwable $e) {}
							} else {
								// Hay agrupación y la emisión se realizará una sola vez al final
								try { Log::info('batchStore: recepcionFactura diferida (grupo)'); } catch (\Throwable $e) {}
							}
						} else { // medio_doc !== 'C' => Manual con CAFC
							if (!is_numeric($nroFactura)) {
								$nroFactura = isset($item['nro_factura']) ? $item['nro_factura'] : null;
							}
							$range = $facturaService->withinCafcRange((int)$nroFactura);
							if (!$range) {
								throw new \RuntimeException('nro_factura fuera de rango CAFC');
							}
							try {
								Log::warning('batchStore: factura M', [
									'idx' => $idx,
									'anio' => $anio,
									'sucursal' => $sucursal,
									'pv' => $pv,
									'nro' => (int)$nroFactura,
									'cafc' => isset($range['cafc']) ? $range['cafc'] : null,
									'range' => [ 'desde' => isset($range['desde']) ? $range['desde'] : null, 'hasta' => isset($range['hasta']) ? $range['hasta'] : null ],
								]);
							} catch (\Throwable $e) {}
							$cliInM = (array) ($request->input('cliente', []));
                            $cliNameM = (string)(isset($cliInM['razon']) ? $cliInM['razon'] : (isset($cliInM['razon_social']) ? $cliInM['razon_social'] : ''));
							$cliNumeroM = (string)(isset($cliInM['numero']) ? $cliInM['numero'] : '');
							$facturaService->createManual($anio, (int)$nroFactura, [
								'codigo_sucursal' => $sucursal,
								'codigo_punto_venta' => (string)$pv,
								'fecha_emision' => $item['fecha_cobro'],
								'cod_ceta' => (int)$request->cod_ceta,
								'id_usuario' => (int)$request->id_usuario,
								'id_forma_cobro' => $formaIdItem,
								'monto_total' => (float)$item['monto'],
								'periodo_facturado' => isset($request->gestion) ? $request->gestion : null,
								'cliente' => $cliNameM,
								'nro_documento_cobro' => $cliNumeroM,
								'codigo_cafc' => isset($range['cafc']) ? $range['cafc'] : null,
							]);
							try { Log::warning('batchStore: factura M creada (local)', [ 'anio' => $anio, 'nro_factura' => (int)$nroFactura, 'cafc' => isset($range['cafc']) ? $range['cafc'] : null ]); } catch (\Throwable $e) {}
						}
					}
					// Inserción en notas SGA: después de resolver id_asign/id_cuota para formar el detalle correcto
 					// Derivar id_asignacion_costo / id_cuota cuando no vienen en el payload
 					// Nota: si es Rezagado o Prueba de Recuperación, NO asociar a cuotas ni afectar mensualidad/arrastre
					$isRezagado = false; $isRecuperacion = false; $isReincorporacion = false; $isSecundario = false;
					try {
						$obsCheck = (string)(isset($item['observaciones']) ? $item['observaciones'] : (isset($request->observaciones) ? $request->observaciones : ''));
						if ($obsCheck !== '') {
							$isRezagado = (preg_match('/\[\s*REZAGADO\s*\]/i', $obsCheck) === 1);
							// Detectar variantes con o sin acento: [Prueba de recuperación]
							$isRecuperacion = (preg_match('/\[\s*PRUEBA\s+DE\s+RECUPERACI[OÓ]N\s*\]/i', $obsCheck) === 1);
							// Reincorporación como servicio aparte
							$isReincorporacion = (preg_match('/\[\s*REINCORPORACI[OÓ]N\s*\]/i', $obsCheck) === 1);
						}
						// Fallback adicional por detalle explícito
						if (!$isReincorporacion) {
							$detRaw = strtoupper(trim((string)(isset($item['detalle']) ? $item['detalle'] : '')));
							if ($detRaw !== '' && strpos($detRaw, 'REINCORPOR') !== false) { $isReincorporacion = true; }
						}
						$hasItem = isset($item['id_item']) && !empty($item['id_item']);
						$isSecundario = ($isRezagado || $isRecuperacion || $isReincorporacion || $hasItem);
					} catch (\Throwable $e) {}
					$idAsign = isset($item['id_asignacion_costo']) ? $item['id_asignacion_costo'] : null;
					$idCuota = isset($item['id_cuota']) ? $item['id_cuota'] : null;
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
								$tpl = (int)(isset($asig->id_cuota_template) ? $asig->id_cuota_template : 0);
								if (!$tpl) continue;
// <<<<<<< HEAD
// 								$alreadyPaid = (float)(isset($asig->monto_pagado) ? $asig->monto_pagado : 0) + (float)(isset($batchPaidByTpl[$tpl]) ? $batchPaidByTpl[$tpl] : 0);
// 								$remaining = (float)(isset($asig->monto) ? $asig->monto : 0) - $alreadyPaid;
// =======
								$alreadyPaid = (float)($asig->monto_pagado ?? 0) + (float)($batchPaidByTpl[$tpl] ?? 0);
								// Considerar descuento por cuota para calcular el restante
								$descN = 0.0;
								try {
									$idDet = (int)($asig->id_descuentoDetalle ?? 0);
									if ($idDet) {
										$dr = DescuentoDetalle::where('id_descuento_detalle', $idDet)->first(['monto_descuento']);
										$descN = $dr ? (float)($dr->monto_descuento ?? 0) : 0.0;
									} else {
										$dr = DescuentoDetalle::where('id_cuota', (int)($asig->id_asignacion_costo ?? 0))->first(['monto_descuento']);
										$descN = $dr ? (float)($dr->monto_descuento ?? 0) : 0.0;
									}
								} catch (\Throwable $e) { $descN = 0.0; }
								$nominal = (float)($asig->monto ?? 0);
								$neto = max(0, $nominal - $descN);
								$remaining = $neto - $alreadyPaid;
// >>>>>>> db8167bb0a817bf7e0af1d0732b63770d42d68e3
								if ($remaining > 0) { $found = $asig; break; }
							}
							if ($found) { $asignRow = $found; }
						}
						if ($asignRow) {
							$idAsign = $idAsign ?: (int) $asignRow->id_asignacion_costo;
							$idCuota = $idCuota ?: ((int) (isset($asignRow->id_cuota_template) ? $asignRow->id_cuota_template : 0) ?: null);
							try {
// <<<<<<< HEAD
// 								$tplSel = (int)(isset($asignRow->id_cuota_template) ? $asignRow->id_cuota_template : 0);
// 								$prev = (float)(isset($asignRow->monto_pagado) ? $asignRow->monto_pagado : 0);
// 								$total = (float)(isset($asignRow->monto) ? $asignRow->monto : 0);
// 								$rem = $total - ($prev + (float)(isset($batchPaidByTpl[$tplSel]) ? $batchPaidByTpl[$tplSel] : 0));
// 								Log::info('batchStore:target', [ 'idx' => $idx, 'id_asignacion_costo' => $idAsign, 'id_cuota_template' => $idCuota, 'prev_pagado' => $prev, 'total' => $total, 'remaining_before' => $rem ]);
// =======
								$tplSel = (int)($asignRow->id_cuota_template ?? 0);
								$prev = (float)($asignRow->monto_pagado ?? 0);
								$total = (float)($asignRow->monto ?? 0);
								$descN = 0.0;
								try {
									$idDet = (int)($asignRow->id_descuentoDetalle ?? 0);
									if ($idDet) {
										$dr = DescuentoDetalle::where('id_descuento_detalle', $idDet)->first(['monto_descuento']);
										$descN = $dr ? (float)($dr->monto_descuento ?? 0) : 0.0;
									} else {
										$dr = DescuentoDetalle::where('id_cuota', (int)($asignRow->id_asignacion_costo ?? 0))->first(['monto_descuento']);
										$descN = $dr ? (float)($dr->monto_descuento ?? 0) : 0.0;
									}
								} catch (\Throwable $e) { $descN = 0.0; }
								$netoForLog = max(0, $total - $descN);
								$rem = $netoForLog - ($prev + (float)($batchPaidByTpl[$tplSel] ?? 0));
								Log::info('batchStore:target', [ 'idx' => $idx, 'id_asignacion_costo' => $idAsign, 'id_cuota_template' => $idCuota, 'prev_pagado' => $prev, 'total' => $total, 'descuento' => $descN, 'neto' => $netoForLog, 'remaining_before' => $rem ]);
// >>>>>>> db8167bb0a817bf7e0af1d0732b63770d42d68e3
							} catch (\Throwable $e) {}
						}
					}
					if ($isSecundario) { $idAsign = null; $idCuota = null; }
					$order = isset($item['order']) ? (int)$item['order'] : ($idx + 1);

					// Construir detalle de cuota para notas: "Mensualidad - Cuota N (Parcial)"
					$detalle = (string)(isset($item['observaciones']) ? $item['observaciones'] : '');
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
							$descN = 0.0;
							try {
								$idDet = (int)($cuotaRow->id_descuentoDetalle ?? 0);
								if ($idDet) {
									$dr = DescuentoDetalle::where('id_descuento_detalle', $idDet)->first(['monto_descuento']);
									$descN = $dr ? (float)($dr->monto_descuento ?? 0) : 0.0;
								} else {
									$dr = DescuentoDetalle::where('id_cuota', (int)($cuotaRow->id_asignacion_costo ?? 0))->first(['monto_descuento']);
									$descN = $dr ? (float)($dr->monto_descuento ?? 0) : 0.0;
								}
							} catch (\Throwable $e) { $descN = 0.0; }
							$neto = max(0, $totalCuota - $descN);
							$parcial = ($prevPag + (float)$item['monto']) < $neto;
							$detalle = 'Mensualidad - Cuota ' . ($numeroCuota ?: $idCuota) . ($parcial ? ' (Parcial)' : '');
						}
					} else {
						if (!empty(isset($item['id_item']) ? $item['id_item'] : null)) {
							$detFromItem = (string)(isset($item['detalle']) ? $item['detalle'] : '');
							$detalle = $detFromItem !== '' ? $detFromItem : ($detalle !== '' ? $detalle : 'Item');
						}
					}

					// Derivar cod_tipo_cobro por ítem y normalizar fecha con hora
					$codTipoCobroItem = isset($item['cod_tipo_cobro']) ? (string)$item['cod_tipo_cobro'] : null;
					if (!$codTipoCobroItem) {
						$obsCheck = (string)(isset($item['observaciones']) ? $item['observaciones'] : (isset($request->observaciones) ? $request->observaciones : ''));
						$hasItem = isset($item['id_item']) && !empty($item['id_item']);
						$isRezagado = ($obsCheck !== '') ? (preg_match('/\[\s*REZAGADO\s*\]/i', $obsCheck) === 1) : false;
						$isRecuperacion = ($obsCheck !== '') ? (preg_match('/\[\s*PRUEBA\s+DE\s+RECUPERACI[OÓ]N\s*\]/i', $obsCheck) === 1) : false;
						$isReincorporacion = ($obsCheck !== '') ? (preg_match('/\[\s*REINCORPORACI[OÓ]N\s*\]/i', $obsCheck) === 1) : false;
						$detRaw = strtoupper(trim((string)(isset($detalle) ? $detalle : '')));
						if (!$isReincorporacion && $detRaw !== '' && strpos($detRaw, 'REINCORPOR') !== false) { $isReincorporacion = true; }
						if ($hasItem) { $codTipoCobroItem = 'MATERIAL_EXTRA'; }
						elseif ($isRezagado) { $codTipoCobroItem = 'REZAGADOS'; }
						elseif ($isRecuperacion) { $codTipoCobroItem = 'PRUEBA_RECUPERACION'; }
						elseif ($isReincorporacion) { $codTipoCobroItem = 'REINCORPORACION'; }
						elseif (strtoupper((string)$request->tipo_inscripcion) === 'ARRASTRE') { $codTipoCobroItem = 'ARRASTRE'; }
						else { $codTipoCobroItem = 'MENSUALIDAD'; }
					}

					// Formatear concepto según tipo de cobro (DESPUÉS de derivar cod_tipo_cobro)
					$mesNombre = '';
					$numeroCuota = isset($numeroCuota) ? $numeroCuota : 0;
					$parcial = isset($parcial) ? $parcial : false;
					if ($numeroCuota > 0) {
						$meses = $this->calcularMesesPorGestion(isset($request->gestion) ? $request->gestion : null);
						if (is_array($meses)) {
							foreach ($meses as $m) {
								$nq = (int)(isset($m['numero_cuota']) ? $m['numero_cuota'] : 0);
								if ($nq === (int)$numeroCuota) { $mesNombre = (string)(isset($m['mes_nombre']) ? $m['mes_nombre'] : ''); break; }
							}
						}
					}
					if ($mesNombre === '' && $detalle !== '') {
						$mm = [];
						if (preg_match('/\(([^)]+)\)/', $detalle, $mm) === 1) {
							$mesNombre = (string)(isset($mm[1]) ? $mm[1] : '');
						}
					}
					$conceptoOut = isset($item['concepto']) && $item['concepto'] !== '' ? (string)$item['concepto'] : '';
					if ($conceptoOut === '') {
						if ($codTipoCobroItem === 'ARRASTRE') {
							$conceptoOut = 'Nivelacion' . ($parcial ? ' parcial' : '') . ($mesNombre !== '' ? " '" . $mesNombre . "'" : '');
						} elseif ($codTipoCobroItem === 'MENSUALIDAD') {
							$conceptoOut = 'Mensualidad' . ($parcial ? ' Parcial' : '') . ($mesNombre !== '' ? " '" . $mesNombre . "'" : '');
						} elseif ($codTipoCobroItem === 'REINCORPORACION') {
							$conceptoOut = 'Reincorporación';
						} else {
							$conceptoOut = $detalle !== '' ? (string)$detalle : ((string)(isset($item['observaciones']) ? $item['observaciones'] : (isset($request->observaciones) ? $request->observaciones : 'Cobro')));
						}
					}

					$fechaCobroRaw = (string)(isset($item['fecha_cobro']) ? $item['fecha_cobro'] : date('Y-m-d H:i:s'));
					$fechaCobroSave = $fechaCobroRaw;
					try {
						if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaCobroRaw)) {
							$nowLaPaz = \Carbon\Carbon::now('America/La_Paz');
							$fechaCobroSave = substr($fechaCobroRaw, 0, 10) . ' ' . $nowLaPaz->format('H:i:s');
						} else {
							$fechaCobroSave = \Carbon\Carbon::parse($fechaCobroRaw, 'America/La_Paz')->format('Y-m-d H:i:s');
						}
					} catch (\Throwable $e) {
						$fechaCobroSave = date('Y-m-d H:i:s');
					}

					// Inserción en notas SGA usando el detalle correcto
					try {
						$fechaNota = (string)(isset($item['fecha_cobro']) ? $item['fecha_cobro'] : date('Y-m-d'));
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
							$nrCorrelativo = (int)(isset($rowNr->id) ? $rowNr->id : 0);
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
							$nbCorrelativo = (int)(isset($rowNb->id) ? $rowNb->id : 0);
							$tarj4 = trim((string)(isset($item['tarjeta_first4']) ? $item['tarjeta_first4'] : ''));
							$tarjL4 = trim((string)(isset($item['tarjeta_last4']) ? $item['tarjeta_last4'] : ''));
							// Banco destino desde la cuenta seleccionada
							$bancoDest = '';
							try {
								$idCuenta = isset($request->id_cuentas_bancarias) ? $request->id_cuentas_bancarias : (isset($item['id_cuentas_bancarias']) ? $item['id_cuentas_bancarias'] : null);
								if ($idCuenta) {
									$cb = DB::table('cuentas_bancarias')->where('id_cuentas_bancarias', (int)$idCuenta)->first();
									if ($cb) { $bancoDest = trim((string)(isset($cb->banco) ? $cb->banco : '')) . ' - ' . trim((string)(isset($cb->numero_cuenta) ? $cb->numero_cuenta : '')); }
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
								'fecha_deposito' => (string)(isset($item['fecha_deposito']) ? $item['fecha_deposito'] : ''),
								'nro_transaccion' => (string)(isset($item['nro_deposito']) ? $item['nro_deposito'] : ''),
								'prefijo_carrera' => $prefijoCarrera,
								'concepto_est' => $detalle,
								'observacion' => $obsOriginal,
								'anulado' => false,
								'tipo_nota' => (string)(isset($formaIdItem) ? $formaIdItem : ''),
								'banco_origen' => (string)(isset($item['banco_origen']) ? $item['banco_origen'] : ''),
								'nro_tarjeta' => $nroTarjetaFull,
							]);
						}
					} catch (\Throwable $e) {
						\Log::warning('batchStore: nota insert failed', [ 'err' => $e->getMessage() ]);
					}

					// Si hay agrupación de factura, acumular el detalle formateado para el envío único
					if ($hasFacturaGroup && $tipoDoc === 'F' && $medioDoc === 'C') {
						$detalleDesc = isset($detalle) && $detalle !== '' ? (string)$detalle : ((string)(isset($item['observaciones']) ? $item['observaciones'] : 'Cobro'));
						
						
						$codigoSin = 99100; // Default para SIN
						$codigoInterno = null; // Default para PDF
						$actividadEconomica = 853000; // Default
						$unidadMedida = 58; // Default
						
						// Mapeo de palabras clave a nombre_servicio en items_cobro
						$textoDetalle = strtolower($detalleDesc);
						$nombreServicio = null;
						
						if (strpos($textoDetalle, 'mensualidad') !== false) {
							$nombreServicio = 'mensualidad_factura';
						} elseif (strpos($textoDetalle, 'rezagado') !== false || strpos($textoDetalle, '[rezagado]') !== false) {
							$nombreServicio = 'rezagado';
						} elseif (strpos($textoDetalle, 'arrastre') !== false) {
							$nombreServicio = 'arrastre';
						} elseif (strpos($textoDetalle, 'multa') !== false) {
							$nombreServicio = 'multa';
						} elseif (strpos($textoDetalle, 'reincorporacion') !== false || strpos($textoDetalle, 'reincorporación') !== false) {
							$nombreServicio = 'reincorporacion';
						} elseif (strpos($textoDetalle, 'carnet') !== false) {
							$nombreServicio = 'E9';
						}
						
						// Buscar en items_cobro por nombre_servicio
						if ($nombreServicio) {
							$itemCobro = DB::table('items_cobro')
								->where('nombre_servicio', $nombreServicio)
								->first();
							
							if ($itemCobro) {
								$codigoSin = (int)(isset($itemCobro->codigo_producto_impuestos) ? $itemCobro->codigo_producto_impuestos : 99100);
								$codigoInternoRaw = isset($itemCobro->codigo_producto_interno) ? (int)$itemCobro->codigo_producto_interno : 0;
								$codigoInterno = ($codigoInternoRaw > 0) ? $codigoInternoRaw : null;
								$actividadEconomica = (int)(isset($itemCobro->actividad_economica) ? $itemCobro->actividad_economica : 853000);
								$unidadMedida = (int)(isset($itemCobro->unidad_medida) ? $itemCobro->unidad_medida : 58);
							}
						}
						$factDetalles[] = [
							'codigo_sin' => $codigoSin, // Para enviar al SIN
							'codigo_interno' => $codigoInterno, // Para mostrar en PDF
							'codigo' => 'ITEM-' . (int)$nroCobro,
							'descripcion' => $detalleDesc,
							'cantidad' => 1,
							'unidad_medida' => $unidadMedida,
							'precio_unitario' => (float)$item['monto'],
							'descuento' => 0,
							'subtotal' => (float)$item['monto'],
							'actividad_economica' => $actividadEconomica,
						];
						// También acumular en meta para post-commit
						if (is_array($emitGroupMeta)) { $emitGroupMeta['detalles'][] = end($factDetalles); }
					}

					$payload = array_merge($composite, [
						'monto' => $item['monto'],
						'fecha_cobro' => $fechaCobroSave,
						'cobro_completo' => isset($item['cobro_completo']) ? $item['cobro_completo'] : null,
						'observaciones' => isset($item['observaciones']) ? $item['observaciones'] : null,
						'id_usuario' => (int)$request->id_usuario,
						'id_forma_cobro' => isset($item['id_forma_cobro']) ? $item['id_forma_cobro'] : $formaIdItem,
						'pu_mensualidad' => isset($item['pu_mensualidad']) ? $item['pu_mensualidad'] : 0,
						'order' => $order,
						'descuento' => isset($item['descuento']) ? $item['descuento'] : null,
						'id_cuentas_bancarias' => isset($request->id_cuentas_bancarias) ? $request->id_cuentas_bancarias : null,
						'nro_factura' => $nroFactura,
						'nro_recibo' => $nroRecibo,
						'id_item' => isset($item['id_item']) ? $item['id_item'] : null,
						'id_asignacion_costo' => $isSecundario ? null : $idAsign,
						'id_cuota' => $isSecundario ? null : $idCuota,
						'tipo_documento' => $tipoDoc,
						'medio_doc' => $medioDoc,
						'gestion' => isset($request->gestion) ? $request->gestion : null,
						'cod_inscrip' => $primaryInscripcion ? (int)$primaryInscripcion->cod_inscrip : null,
						'cod_tipo_cobro' => $codTipoCobroItem,
						'concepto' => $conceptoOut,
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
									'pu_mensualidad' => (float)(isset($item['pu_mensualidad']) ? $item['pu_mensualidad'] : 0),
									'turno' => (string)(isset($primaryInscripcion->turno) ? $primaryInscripcion->turno : ''),
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
						$batchPaidByTpl[$idCuota] = (isset($batchPaidByTpl[$idCuota]) ? $batchPaidByTpl[$idCuota] : 0) + (float)$item['monto'];
						try { Log::info('batchStore:paidByTpl', [ 'idx' => $idx, 'tpl' => $idCuota, 'batch_paid' => $batchPaidByTpl[$idCuota] ]); } catch (\Throwable $e) {}
					}
					// Actualizar estado de pago de la asignación
					if (!$isSecundario && $idAsign) {
						// Releer siempre desde DB para evitar usar un snapshot desactualizado cuando hay múltiples ítems a la misma cuota
						$toUpd = AsignacionCostos::find((int)$idAsign);
						if ($toUpd) {
							$prevPagado = (float)(isset($toUpd->monto_pagado) ? $toUpd->monto_pagado : 0);
							$newPagado = $prevPagado + (float)$item['monto'];
// <<<<<<< HEAD
// 							$fullNow = $newPagado >= (float) (isset($toUpd->monto) ? $toUpd->monto : 0) || !empty($item['cobro_completo']);
// =======
							$descN = 0.0;
							try {
								$idDet = (int)($toUpd->id_descuentoDetalle ?? 0);
								if ($idDet) {
									$dr = DescuentoDetalle::where('id_descuento_detalle', $idDet)->first(['monto_descuento']);
									$descN = $dr ? (float)($dr->monto_descuento ?? 0) : 0.0;
								} else {
									$dr = DescuentoDetalle::where('id_cuota', (int)($toUpd->id_asignacion_costo ?? 0))->first(['monto_descuento']);
									$descN = $dr ? (float)($dr->monto_descuento ?? 0) : 0.0;
								}
							} catch (\Throwable $e) { $descN = 0.0; }
							$nominal = (float) ($toUpd->monto ?? 0);
							$neto = max(0, $nominal - $descN);
							$fullNow = ($newPagado >= $neto) || !empty($item['cobro_completo']);
// >>>>>>> db8167bb0a817bf7e0af1d0732b63770d42d68e3
							$upd = [ 'monto_pagado' => $newPagado ];
							if ($fullNow) {
								$upd['estado_pago'] = 'COBRADO';
								$upd['fecha_pago'] = $item['fecha_cobro'];
							} else {
								$upd['estado_pago'] = 'PARCIAL';
							}
							try { Log::info('batchStore:asign_update', [ 'idx' => $idx, 'id_asignacion_costo' => (int)$toUpd->id_asignacion_costo, 'add_monto' => (float)$item['monto'], 'prev_pagado' => $prevPagado, 'new_pagado' => $newPagado, 'total' => (float)(isset($toUpd->monto) ? $toUpd->monto : 0), 'estado_final' => $upd['estado_pago'] ]); } catch (\Throwable $e) {}
							$aff = AsignacionCostos::where('id_asignacion_costo', (int)$toUpd->id_asignacion_costo)->update($upd);
							try { Log::info('batchStore:asign_updated', [ 'idx' => $idx, 'id_asignacion_costo' => (int)$toUpd->id_asignacion_costo, 'affected' => $aff ]); } catch (\Throwable $e) {}
						}
					}
					else {
						try { Log::info('batchStore:asign_skip', [ 'idx' => $idx, 'reason' => 'missing_id_asign', 'is_secundario' => $isSecundario ]); } catch (\Throwable $e) {}
					}
					// Rezagados: si el item contiene el marcador, registrar en la tabla 'rezagados'
					try {
						$obsVal = (string)(isset($item['observaciones']) ? $item['observaciones'] : '');
						if ($obsVal !== '' && preg_match('/\[\s*REZAGADO\s*\]\s*Rezagado\s*-\s*([A-Z0-9\-]+)\b.*?-(\s*)([123])er\s*P\.?/i', $obsVal, $mm)) {
							$siglaMateria = strtoupper(trim((string)$mm[1]));
							$parcialNum = (string)trim((string)$mm[3]); // '1' | '2' | '3'
							$fechaPago = (string)(isset($item['fecha_cobro']) ? $item['fecha_cobro'] : date('Y-m-d'));
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
									$nextSeq = (int)(isset($maxRow->mx) ? $maxRow->mx : 0) + 1;
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

					$resultItem = [
						'indice' => $idx,
						'tipo_documento' => $tipoDoc,
						'medio_doc' => $medioDoc,
						'nro_recibo' => $nroRecibo,
						'nro_factura' => $nroFactura,
						'cobro' => $created,
						'codigo_recepcion' => $codigoRecepcionLocal,
						'estado_factura' => $estadoFacturaLocal,
						'mensaje' => $mensajeLocal,
						'cuf' => $cufLocal,
					];
					
					// Si hay error de facturación, agregarlo al resultado
					if (isset($facturaError)) {
						$resultItem['factura_error'] = $facturaError;
					}
					
					$results[] = $resultItem;
				}

				// Insertar detalles de la factura en factura_detalle (SIEMPRE, incluso si no se emite online)
			if ($hasFacturaGroup && !empty($factDetalles)) {
					foreach ($factDetalles as $detIdx => $det) {
						try {
							DB::table('factura_detalle')->insert([
								'anio' => (int)$anioFacturaGroup,
								'nro_factura' => (int)$nroFacturaGroup,
								'id_detalle' => $detIdx + 1,
								'codigo_sin' => (int)(isset($det['codigo_sin']) ? $det['codigo_sin'] : 99100),
								'codigo_interno' => isset($det['codigo_interno']) ? (int)$det['codigo_interno'] : null,
								'codigo' => (string)(isset($det['codigo']) ? $det['codigo'] : ''),
								'descripcion' => (string)(isset($det['descripcion']) ? $det['descripcion'] : ''),
								'cantidad' => (float)(isset($det['cantidad']) ? $det['cantidad'] : 1),
								'unidad_medida' => (int)(isset($det['unidad_medida']) ? $det['unidad_medida'] : 58),
								'precio_unitario' => (float)(isset($det['precio_unitario']) ? $det['precio_unitario'] : 0),
								'descuento' => (float)(isset($det['descuento']) ? $det['descuento'] : 0),
								'subtotal' => (float)(isset($det['subtotal']) ? $det['subtotal'] : 0),
							]);
						} catch (\Throwable $e) {
							Log::error('batchStore: error insertando detalle factura', ['error' => $e->getMessage(), 'detalle' => $det]);
						}
					}
					Log::info('batchStore: detalles insertados en factura_detalle', ['anio' => $anioFacturaGroup, 'nro_factura' => $nroFacturaGroup, 'count' => count($factDetalles)]);
				}

				// Emisión online única para la factura agrupada (punto correcto: después del foreach)
				if ($hasFacturaGroup && $emitirOnline) {
					if (config('sin.offline')) {
						Log::warning('batchStore: skip recepcionFactura (OFFLINE, grupo)');
					} else {
						try {
							// Obtener CUFD NUEVO del SIN (forceNew=true para evitar problemas de sincronización)
							$cufdNow = $cufdRepo->getVigenteOrCreate($pv, true);
							$cufdOld = $cufdGroup;
							$cufdGroup = (string)(isset($cufdNow['codigo_cufd']) ? $cufdNow['codigo_cufd'] : '');
							$cuisGroup = isset($cufdNow['codigo_cuis']) ? $cufdNow['codigo_cuis'] : $cuisGroup;
							
							// Siempre recalcular CUF con el CUFD vigente actual
							$gen = $cufGen->generate((int) config('sin.nit'), (string)$fechaEmisionIsoGroup, $sucursal, (int) config('sin.modalidad'), 1, (int) config('sin.tipo_factura'), (int) config('sin.cod_doc_sector'), (int)$nroFacturaGroup, (int)$pv);
							$cufBase = (string)(isset($gen['cuf']) ? $gen['cuf'] : '');
							$codigoControl = (string)(isset($cufdNow['codigo_control']) ? $cufdNow['codigo_control'] : '');
							$cufGroup = $cufBase . $codigoControl;
							Log::info('batchStore: CUF calculado (grupo)', ['cuf_base' => $cufBase, 'codigo_control' => $codigoControl, 'cuf_final' => $cufGroup, 'cufd' => $cufdGroup]);
							
							// Actualizar DB si el CUFD cambió
							if ($cufdOld !== $cufdGroup) {
								DB::table('factura')
									->where('anio', (int)$anioFacturaGroup)
									->where('nro_factura', (int)$nroFacturaGroup)
									->where('codigo_sucursal', (int)$sucursal)
									->where('codigo_punto_venta', (string)$pv)
									->update(['codigo_cufd' => $cufdGroup, 'cuf' => $cufGroup]);
								Log::warning('batchStore: CUFD rotó antes de emitir (grupo), CUF recalculado', ['cufd_old' => $cufdOld, 'cufd_new' => $cufdGroup, 'cuf_new' => $cufGroup]);
							}
							$cuisCode = isset($cufdNow['codigo_cuis']) ? $cufdNow['codigo_cuis'] : '';
							
							// Construir payload con los valores actualizados
							$cliIn = (array) $request->input('cliente', []);
							$cliente = [
								'tipo_doc' => isset($cliIn['tipo_doc']) ? (int)$cliIn['tipo_doc'] : (int)(isset($cliIn['tipo_identidad']) ? $cliIn['tipo_identidad'] : 5),
								'numero' => (string)(isset($cliIn['numero']) ? $cliIn['numero'] : ''),
								'razon' => (string)(isset($cliIn['razon']) ? $cliIn['razon'] : (isset($cliIn['razon_social']) ? $cliIn['razon_social'] : 'S/N')),
								'complemento' => isset($cliIn['complemento']) ? $cliIn['complemento'] : null,
								'codigo' => (string)(isset($cliIn['codigo']) ? $cliIn['codigo'] : (isset($cliIn['numero']) ? $cliIn['numero'] : '0')),
							];
							
							// IMPORTANTE: Usar las variables actualizadas ($cufGroup, $cufdGroup, $cuisCode)
							$payloadArgs = [
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
								'cufd' => $cufdGroup,
								'cuf' => $cufGroup,
								'fecha_emision' => (string)$fechaEmisionIsoGroup,
								'periodo_facturado' => isset($request->gestion) ? $request->gestion : null,
								'monto_total' => (float)$factMontoTotal,
								'numero_factura' => (int)$nroFacturaGroup,
								'id_forma_cobro' => (string)(isset($request->id_forma_cobro) ? $request->id_forma_cobro : ''),
								'cliente' => $cliente,
								'detalles' => $factDetalles,
							];
							
							// Construir payload DESPUÉS de actualizar todas las variables
							$payload = $payloadBuilder->buildRecepcionFacturaPayload($payloadArgs);
							Log::warning('batchStore: calling recepcionFactura (grupo)', [ 'anio' => (int)$anioFacturaGroup, 'nro_factura' => (int)$nroFacturaGroup, 'punto_venta' => $pv, 'sucursal' => $sucursal, 'cuf' => $cufGroup, 'cufd' => $cufdGroup, 'cuis' => $cuisCode, 'payload_meta' => [ 'len_archivo' => isset($payload['archivo']) ? strlen($payload['archivo']) : null, 'hashArchivo' => isset($payload['hashArchivo']) ? $payload['hashArchivo'] : null ]]);
							$resp = $ops->recepcionFactura($payload);
							$root = isset($resp['RespuestaServicioFacturacion']) ? $resp['RespuestaServicioFacturacion'] : (isset($resp['RespuestaRecepcionFactura']) ? $resp['RespuestaRecepcionFactura'] : (is_array($resp) ? reset($resp) : null));
							$codRecep = is_array($root) ? (isset($root['codigoRecepcion']) ? $root['codigoRecepcion'] : null) : null;
							$mensajeGroup = null; $estadoCod = null;
							try {
								$estadoCod = is_array($root) && isset($root['codigoEstado']) ? (int)$root['codigoEstado'] : null;
								$mensajes = is_array($root) ? (isset($root['mensajesList']) ? $root['mensajesList'] : null) : null;
								if ($mensajes) {
									if (isset($mensajes['descripcion'])) { $mensajeGroup = (string)$mensajes['descripcion']; }
									elseif (is_array($mensajes) && isset($mensajes[0]['descripcion'])) { $mensajeGroup = (string)$mensajes[0]['descripcion']; }
								}
							} catch (\Throwable $e) {}
							if ($codRecep) {
								\DB::table('factura')
									->where('anio', (int)$anioFacturaGroup)
									->where('nro_factura', (int)$nroFacturaGroup)
									->where('codigo_sucursal', (int)$sucursal)
									->where('codigo_punto_venta', (string)$pv)
									->update(['codigo_recepcion' => $codRecep, 'estado' => 'ACEPTADA']);
								Log::warning('batchStore: recepcionFactura ok (grupo)', [ 'codigo_recepcion' => $codRecep ]);
								foreach ($results as &$r) {
									if (is_array($r) && strtoupper((string)(isset($r['tipo_documento']) ? $r['tipo_documento'] : '')) === 'F') {
										$r['codigo_recepcion'] = $codRecep;
										$r['estado_factura'] = 'ACEPTADA';
										$r['mensaje'] = $mensajeGroup;
									}
								}
							} else {
								$mensajeRechazoGroup = isset($mensajeGroup) ? $mensajeGroup : 'Factura rechazada por el SIN';
								\DB::table('factura')
									->where('anio', (int)$anioFacturaGroup)
									->where('nro_factura', (int)$nroFacturaGroup)
									->where('codigo_sucursal', (int)$sucursal)
									->where('codigo_punto_venta', (string)$pv)
									->update(['estado' => 'RECHAZADA']);
								Log::warning('batchStore: recepcionFactura sin codigoRecepcion (grupo)', [ 'resp' => $resp, 'mensaje' => $mensajeRechazoGroup ]);
								
								// Agregar información de error a todos los items de factura
								$facturaErrorGroup = [
									'estado' => 'RECHAZADA',
									'mensaje' => $mensajeRechazoGroup,
									'anio' => (int)$anioFacturaGroup,
									'nro_factura' => (int)$nroFacturaGroup
								];
								
								foreach ($results as &$r) {
									if (is_array($r) && strtoupper((string)(isset($r['tipo_documento']) ? $r['tipo_documento'] : '')) === 'F') {
										$r['estado_factura'] = 'RECHAZADA';
										$r['mensaje'] = $mensajeRechazoGroup;
										$r['factura_error'] = $facturaErrorGroup;
									}
								}
							}
						} catch (\Throwable $e) {
							Log::error('batchStore: recepcionFactura exception (grupo)', [ 'error' => $e->getMessage() ]);
						}
					}
				}

				// Al finalizar la creación de todos los ítems, sincronizar números de doc a qr_transacciones reciente
				try {
					// Calcular monto total del lote y cod_ceta locales
					$totalMonto = 0.0; foreach ($items as $it) { $totalMonto += (float)(isset($it['monto']) ? $it['monto'] : 0); }
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
								$tipoDoc = strtoupper((string)(isset($first['tipo_documento']) ? $first['tipo_documento'] : ''));
								$nroRec = isset($first['nro_recibo']) ? $first['nro_recibo'] : null;
								$nroFac = isset($first['nro_factura']) ? $first['nro_factura'] : null;
								$cob = isset($first['cobro']) ? $first['cobro'] : [];
								$fechaCobro = is_array($cob) ? ((string)(isset($cob['fecha_cobro']) ? $cob['fecha_cobro'] : '')) : '';
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
						$obs = (string)(isset($it['observaciones']) ? $it['observaciones'] : '');
						if ($obs !== '' && preg_match('/\[\s*QR[^\]]*\]\s*alias:([^\s|]+)/i', $obs, $mm)) { $aliasQr = trim((string)$mm[1]); break; }
					}
					if ($aliasQr) { app(\App\Services\Qr\QrSocketNotifier::class)->notifyEvent('factura_generada', [ 'id_pago' => $aliasQr ]); }
				} catch (\Throwable $e) { /* noop */ }
			});

			// Respuesta de éxito (fuera de la transacción) con el arreglo de items creado
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

	public function facturaMeta(int $anio, int $nro)
	{
		try {
			$row = DB::table('factura')
				->select('anio','nro_factura','codigo_sucursal','codigo_punto_venta','fecha_emision','cuf','codigo_recepcion','monto_total')
				->where('anio', (int)$anio)
				->where('nro_factura', (int)$nro)
				->first();
			if (!$row) {
				return response()->json([ 'success' => false, 'message' => 'Factura no encontrada' ], 404);
			}
			// Leyenda: seleccionar aleatoriamente por actividad económica (caeb)
			$actividad = DB::table('sin_actividades')->value('codigo_caeb');
			$leyenda = null;
			if ($actividad) {
				$leyenda = DB::table('sin_list_leyenda_factura')
					->where('codigo_actividad', $actividad)
					->inRandomOrder()
					->value('descripcion_leyenda');
			}
			if (!$leyenda) {
				$leyenda = DB::table('sin_list_leyenda_factura')->value('descripcion_leyenda');
			}
			$leyenda2 = '“Este documento es la Representación Gráfica de un Documento Fiscal Digital emitido en una modalidad de facturación en línea”';
			$payload = (array)$row;
			$payload['leyenda'] = $leyenda;
			$payload['leyenda2'] = $leyenda2;
			return response()->json([ 'success' => true, 'data' => $payload ]);
		} catch (\Throwable $e) {
			return response()->json([ 'success' => false, 'message' => $e->getMessage() ], 500);
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
			Log::info('validar-impuestos: CUIS ok', [ 'codigo_cuis' => isset($cuis['codigo_cuis']) ? $cuis['codigo_cuis'] : null, 'fecha_vigencia' => isset($cuis['fecha_vigencia']) ? $cuis['fecha_vigencia'] : null ]);

			// CUFD vigente o crear
			$cufd = null;
			try {
				$cufd = $cufdRepo->getVigenteOrCreate($pv);
				Log::info('validar-impuestos: CUFD ok', [ 'codigo_cufd' => isset($cufd['codigo_cufd']) ? $cufd['codigo_cufd'] : null, 'fecha_vigencia' => isset($cufd['fecha_vigencia']) ? $cufd['fecha_vigencia'] : null ]);
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
					'sucursal' => [ 'codigo_sucursal' => isset($sucursalInput) ? $sucursalInput : config('sin.sucursal') ],
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
