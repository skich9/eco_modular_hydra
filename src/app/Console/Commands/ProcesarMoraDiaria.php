<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\AsignacionCostos;
use App\Models\AsignacionMora;
use App\Models\DatosMoraDetalle;
use App\Models\ProrrogaMora;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcesarMoraDiaria extends Command
{
	/**
	 * The name and signature of the console command.
	 *
	 * @var string
	 */
	protected $signature = 'mora:procesar-diaria {--force : Forzar ejecución sin confirmación} {--desactivar-prorrogas : Desactivar prórrogas vencidas durante el procesamiento}';

	/**
	 * The console command description.
	 *
	 * @var string
	 */
	protected $description = 'Procesa y actualiza las moras diarias de los estudiantes según configuración';

	/**
	 * Execute the console command.
	 */
	public function handle()
	{
		$this->info('=== Iniciando Procesamiento de Mora Diaria ===');
		$this->info('Fecha: ' . Carbon::now()->format('Y-m-d H:i:s'));

		$hoy = Carbon::today();
		$morasCreadas = 0;
		$morasActualizadas = 0;
		$morasCerradas = 0;
		$errores = 0;

		try {
			// 1. Desactivar prórrogas vencidas (opcional)
			$this->info("\n1. Prórrogas vencidas...");
			if ($this->shouldDesactivarProrrogasVencidas()) {
				$this->info("   - Desactivando prórrogas vencidas...");
				$prorrogasDesactivadas = $this->desactivarProrrogasVencidas($hoy);
				$this->info("   ✓ Prórrogas desactivadas: {$prorrogasDesactivadas}");
			} else {
				$this->info("   - Saltado (configurado para NO desactivar prórrogas vencidas)");
			}

			// 2. Cerrar moras de cuotas que ya fueron pagadas
			$this->info("\n2. Cerrando moras de cuotas pagadas...");
			$morasCerradas = $this->cerrarMorasPagadas($hoy);
			$this->info("   ✓ Moras cerradas: {$morasCerradas}");

			// 3. Buscar asignaciones de costos pendientes o parciales
			// También incluir cuotas con mora CONGELADA_PRORROGA y prórroga terminada
			$this->info("\n3. Buscando cuotas pendientes o parciales...");

			// Cuotas con estado_pago PENDIENTE/PARCIAL
			$asignacionesPendientes = AsignacionCostos::whereIn('estado_pago', ['PENDIENTE', 'PARCIAL', 'pendiente', 'parcial'])
				->with(['pensum', 'inscripcion'])
				->get();

			// Cuotas con mora CONGELADA_PRORROGA y prórroga terminada (para crear mora post-prórroga)
			$cuotasConProrrogaTerminada = AsignacionCostos::whereHas('asignacionesMora', function($query) {
					$query->where('estado', 'CONGELADA_PRORROGA');
				})
				->whereExists(function($query) use ($hoy) {
					$query->select(DB::raw(1))
						->from('prorrogas_mora')
						->whereColumn('prorrogas_mora.id_asignacion_costo', 'asignacion_costos.id_asignacion_costo')
						->where('prorrogas_mora.fecha_fin_prorroga', '<', $hoy);
				})
				->with(['pensum', 'inscripcion'])
				->get();

			// Combinar ambas colecciones y eliminar duplicados
			$asignacionesPendientes = $asignacionesPendientes->merge($cuotasConProrrogaTerminada)->unique('id_asignacion_costo');

			$this->info("   ✓ Cuotas encontradas: {$asignacionesPendientes->count()}");

			// Log para debug: verificar si id_asignacion_costo=225 y 233 están en la lista
			$debugIds = [223, 224, 225, 228, 229, 233];
			$idsEncontrados = $asignacionesPendientes->pluck('id_asignacion_costo')->toArray();
			$debugIdsEncontrados = array_intersect($debugIds, $idsEncontrados);
			if (!empty($debugIdsEncontrados)) {
				Log::info('[MORA_DIARIA][DEBUG] IDs de debug encontrados en asignacionesPendientes', [
					'ids_debug' => $debugIdsEncontrados,
					'total_cuotas' => $asignacionesPendientes->count(),
				]);
			} else {
				Log::info('[MORA_DIARIA][DEBUG] NINGÚN ID de debug encontrado en asignacionesPendientes', [
					'ids_buscados' => $debugIds,
					'total_cuotas' => $asignacionesPendientes->count(),
					'primeros_10_ids' => array_slice($idsEncontrados, 0, 10),
				]);
			}

			if ($asignacionesPendientes->isEmpty()) {
				$this->info("\n✓ No hay cuotas pendientes para procesar.");
				return 0;
			}

			// Construir grupos por estudiante + gestión + cuota.
			// Esto evita duplicar mora cuando existen dos inscripciones activas
			// (ej. NORMAL + ARRASTRE) para la misma cuota.
			$asignacionesPorGrupo = [];
			foreach ($asignacionesPendientes as $asignacionTmp) {
				$gestionTmp = $this->obtenerGestionInscripcion($asignacionTmp);
				$grupoKeyTmp = $this->buildGrupoMoraKey($asignacionTmp, $gestionTmp);
				if (!$grupoKeyTmp) {
					continue;
				}

				if (!isset($asignacionesPorGrupo[$grupoKeyTmp])) {
					$asignacionesPorGrupo[$grupoKeyTmp] = [];
				}

				$asignacionesPorGrupo[$grupoKeyTmp][] = (int)$asignacionTmp->id_asignacion_costo;
			}

			foreach ($asignacionesPorGrupo as $key => $ids) {
				$asignacionesPorGrupo[$key] = array_values(array_unique($ids));
			}

			$gruposPausados = [];

			// 4. Procesar cada asignación de costo
			$this->info("\n4. Procesando cada cuota...");
			$progressBar = $this->output->createProgressBar($asignacionesPendientes->count());
			$progressBar->start();

			foreach ($asignacionesPendientes as $asignacion) {
				try {
					// Obtener datos necesarios
					$codPensum = $asignacion->cod_pensum;
					$numeroCuota = $asignacion->numero_cuota;

					// Log detallado para asignaciones específicas
					$debugIds = [223, 224, 228, 229, 225];
					if (in_array($asignacion->id_asignacion_costo, $debugIds)) {
						Log::info('[MORA_DIARIA][DEBUG] Procesando asignación', [
							'id_asignacion_costo' => (int)$asignacion->id_asignacion_costo,
							'cod_ceta' => (int)(isset($asignacion->cod_ceta) ? $asignacion->cod_ceta : 0),
							'cod_pensum' => (string)$codPensum,
							'numero_cuota' => (int)$numeroCuota,
							'hoy' => $hoy->toDateString(),
						]);
					}

					// Obtener semestre y gestión de la inscripción
					$semestre = $this->obtenerSemestreInscripcion($asignacion);
					$gestion = $this->obtenerGestionInscripcion($asignacion);

					if (!$gestion) {
						if (in_array($asignacion->id_asignacion_costo, $debugIds)) {
							Log::info('[MORA_DIARIA][DEBUG] skip: sin gestion', [
								'id_asignacion_costo' => (int)$asignacion->id_asignacion_costo,
							]);
						}
						$progressBar->advance();
						continue;
					}

					if (!$semestre) {
						if (in_array($asignacion->id_asignacion_costo, $debugIds)) {
							Log::info('[MORA_DIARIA][DEBUG] skip: sin semestre determinable', [
								'id_asignacion_costo' => (int)$asignacion->id_asignacion_costo,
								'cod_pensum' => (string)$codPensum,
								'cod_curso' => (string)(isset($asignacion->inscripcion->cod_curso) ? $asignacion->inscripcion->cod_curso : ''),
								'gestion' => (string)$gestion,
							]);
						}
						$progressBar->advance();
						continue;
					}

					$grupoKey = $this->buildGrupoMoraKey($asignacion, $gestion);
					$idsGrupo = ($grupoKey && isset($asignacionesPorGrupo[$grupoKey]))
						? $asignacionesPorGrupo[$grupoKey]
						: [];

					$esDuplicado = count($idsGrupo) > 1;

					// Buscar configuración de mora aplicable por gestión, pensum, cuota y semestre exacto.
					$codPensumNormalizado = $this->normalizarCodPensum($codPensum);
					$pensumsBusqueda = [$codPensum];
					if ($codPensumNormalizado !== '' && $codPensumNormalizado !== $codPensum) {
						$pensumsBusqueda[] = $codPensumNormalizado;
					}

					$queryCfg = DatosMoraDetalle::whereIn('cod_pensum', array_values(array_unique($pensumsBusqueda)))
						->where('cuota', $numeroCuota)
						->where('semestre', $semestre)
						->where('activo', true)
						->whereHas('datosMora', function($query) use ($gestion) {
							$query->where('gestion', $gestion);
						});

					$configuracionMora = $queryCfg->with('datosMora')->orderBy('semestre', 'asc')->first();

					if (in_array($asignacion->id_asignacion_costo, $debugIds)) {
						Log::info("[DEBUG] Configuración encontrada: " . ($configuracionMora ? 'SÍ' : 'NO'));
					}

					if (!$configuracionMora) {
						static $noCfg = 0;
						$noCfg++;
						if ($noCfg <= 10) {
							$this->warn("Sin configuración de mora para id_asignacion={$asignacion->id_asignacion_costo} pensum={$codPensum} cuota={$numeroCuota} semestre={$semestre} gestion={$gestion}");
						}
						if (in_array($asignacion->id_asignacion_costo, $debugIds)) {
							Log::info('[MORA_DIARIA][DEBUG] skip: sin configuracion mora', [
								'id_asignacion_costo' => (int)$asignacion->id_asignacion_costo,
								'cod_pensum' => (string)$codPensum,
								'numero_cuota' => (int)$numeroCuota,
								'semestre' => (string)$semestre,
								'gestion' => (string)$gestion,
							]);
						}
						$progressBar->advance();
						continue;
					}

					// Si es duplicado y ya tiene mora, marcarla como PAUSADA_DUPLICIDAD
					if (in_array($asignacion->id_asignacion_costo, $debugIds)) {
						Log::info("[DEBUG] ¿Es duplicado?: " . ($esDuplicado ? 'SÍ' : 'NO'));
					}
					if ($esDuplicado && !isset($gruposPausados[$grupoKey])) {
						DB::table('asignacion_mora as am')
							->join('asignacion_costos as ac', 'am.id_asignacion_costo', '=', 'ac.id_asignacion_costo')
							->join('inscripciones as i', 'ac.cod_inscrip', '=', 'i.cod_inscrip')
							->whereIn('am.id_asignacion_costo', $idsGrupo)
							->where('am.estado', 'PENDIENTE')
							->where('i.tipo_inscripcion', '!=', 'NORMAL')
							->update([
								'am.estado' => 'PAUSADA_DUPLICIDAD',
								'am.updated_at' => now(),
							]);
						$gruposPausados[$grupoKey] = true;
					}

					// Verificar si existe prórroga activa para esta cuota
					$prorrogaActiva = ProrrogaMora::where('id_asignacion_costo', $asignacion->id_asignacion_costo)
						->activas($hoy)
						->first();

					if (in_array($asignacion->id_asignacion_costo, $debugIds)) {
						Log::info("[DEBUG] ¿Prórroga activa?: " . ($prorrogaActiva ? 'SÍ' : 'NO'));
					}

					if (in_array($asignacion->id_asignacion_costo, $debugIds)) {
						$todasProrrogas = ProrrogaMora::where('id_asignacion_costo', $asignacion->id_asignacion_costo)
							->orderBy('id_prorroga_mora', 'asc')
							->get(['id_prorroga_mora', 'fecha_inicio_prorroga', 'fecha_fin_prorroga', 'activo'])
							->toArray();
						Log::info('[MORA_DIARIA][DEBUG] TODAS las prorrogas para este id_asignacion_costo', [
							'id_asignacion_costo' => (int)$asignacion->id_asignacion_costo,
							'hoy' => $hoy->toDateString(),
							'prorrogas' => $todasProrrogas,
						]);
					}

					// Buscar prórroga terminada más reciente por ID (desactivada o vencida)
					// Incluir prórrogas con activo=0 O prórrogas con activo=1 pero fecha_fin_prorroga < hoy
					// Ordenar por id_prorroga_mora DESC para obtener la última prórroga creada
					$prorrogaTerminada = ProrrogaMora::where('id_asignacion_costo', $asignacion->id_asignacion_costo)
						->where('fecha_fin_prorroga', '<', $hoy)
						->where(function($q) use ($hoy) {
							$q->where('activo', 0)
							  ->orWhere(function($q2) use ($hoy) {
								  $q2->where('activo', 1)
									 ->where('fecha_fin_prorroga', '<', $hoy);
							  });
						})
						->orderBy('id_prorroga_mora', 'desc')
						->first();

					// Si hay prórroga activa Y hay prórroga terminada, verificar si hay gap para crear mora entre prórrogas
					if ($prorrogaActiva && $prorrogaTerminada) {
						$fechaFinAnterior = Carbon::parse($prorrogaTerminada->fecha_fin_prorroga)->startOfDay();
						$fechaInicioActiva = Carbon::parse($prorrogaActiva->fecha_inicio_prorroga)->startOfDay();
						$fechaInicioGap = $fechaFinAnterior->copy()->addDay();
						$fechaFinGap = $fechaInicioActiva->copy()->subDay();

						if ($fechaInicioGap->lte($fechaFinGap)) {
							// Hay gap entre prórrogas, verificar si ya existe mora en ese rango
							$moraEnGap = AsignacionMora::where('id_asignacion_costo', $asignacion->id_asignacion_costo)
								->where('fecha_inicio_mora', '>=', $fechaInicioGap)
								->where('fecha_inicio_mora', '<=', $fechaFinGap)
								->whereIn('estado', ['PENDIENTE', 'CONGELADA_PRORROGA', 'PAUSADA_DUPLICIDAD', 'CERRADA_SIN_CUOTA', 'EN_ESPERA'])
								->first();

							if (!$moraEnGap) {
								// Crear mora entre prórrogas
								$fechaFinMoraCfg = Carbon::parse($configuracionMora->fecha_fin);
								$fechaCalculoGap = $fechaFinGap->lt($fechaFinMoraCfg) ? $fechaFinGap : $fechaFinMoraCfg;
								$diasGap = $fechaInicioGap->diffInDays($fechaCalculoGap) + 1;
								$montoMoraGap = $configuracionMora->monto * $diasGap;

								// Buscar mora anterior para vincular
								$moraAnterior = AsignacionMora::where('id_asignacion_costo', $asignacion->id_asignacion_costo)
									->where('fecha_inicio_mora', '<', $fechaInicioGap)
									->orderBy('id_asignacion_mora', 'desc')
									->first();

								AsignacionMora::create([
									'id_asignacion_costo' => $asignacion->id_asignacion_costo,
									'id_mora_vinculada' => $moraAnterior ? $moraAnterior->id_asignacion_mora : null,
									'id_datos_mora_detalle' => $configuracionMora->id_datos_mora_detalle,
									'fecha_inicio_mora' => $fechaInicioGap,
									'fecha_fin_mora' => $configuracionMora->fecha_fin,
									'monto_base' => $configuracionMora->monto,
									'monto_mora' => $montoMoraGap,
									'monto_descuento' => 0,
									'estado' => 'PENDIENTE',
									'observaciones' => "Mora entre prórrogas aplicada desde {$fechaInicioGap->format('Y-m-d')} hasta {$fechaFinGap->format('Y-m-d')}"
								]);

								$morasCreadas++;
							}
						}
					}

					// Si hay prórroga activa (y ya procesamos gaps), skip el resto
					if ($prorrogaActiva) {
						if (in_array($asignacion->id_asignacion_costo, $debugIds)) {
							Log::info('[MORA_DIARIA][DEBUG] skip: prorroga activa (no procesa mora)', [
								'id_asignacion_costo' => (int)$asignacion->id_asignacion_costo,
								'id_prorroga_mora' => (int)(isset($prorrogaActiva->id_prorroga_mora) ? $prorrogaActiva->id_prorroga_mora : 0),
								'fecha_inicio_prorroga' => (string)(isset($prorrogaActiva->fecha_inicio_prorroga) ? $prorrogaActiva->fecha_inicio_prorroga : ''),
								'fecha_fin_prorroga' => (string)(isset($prorrogaActiva->fecha_fin_prorroga) ? $prorrogaActiva->fecha_fin_prorroga : ''),
								'hoy' => $hoy->toDateString(),
							]);
						}
						$progressBar->advance();
						continue;
					}

					// Verificar si la fecha de inicio de mora ya pasó
					$fechaInicioMora = Carbon::parse($configuracionMora->fecha_inicio);
					if (in_array($asignacion->id_asignacion_costo, $debugIds)) {
						Log::info("[DEBUG] Fecha inicio: {$fechaInicioMora->format('Y-m-d')}, Hoy: {$hoy->format('Y-m-d')}, ¿Inicio > Hoy?: " . ($fechaInicioMora->gt($hoy) ? 'SÍ (se salta)' : 'NO'));
					}
					if ($fechaInicioMora->gt($hoy)) {
						if (in_array($asignacion->id_asignacion_costo, $debugIds)) {
							Log::info('[MORA_DIARIA][DEBUG] skip: fecha_inicio_cfg > hoy', [
								'id_asignacion_costo' => (int)$asignacion->id_asignacion_costo,
								'fecha_inicio_cfg' => $fechaInicioMora->toDateString(),
								'hoy' => $hoy->toDateString(),
							]);
						}
						$progressBar->advance();
						continue;
					}

					// Verificar si ya existe asignación de mora para esta cuota
					$asignacionMora = AsignacionMora::where('id_asignacion_costo', $asignacion->id_asignacion_costo)
						->whereIn('estado', ['PENDIENTE', 'CONGELADA_PRORROGA', 'PAUSADA_DUPLICIDAD', 'CERRADA_SIN_CUOTA', 'EN_ESPERA'])
						->orderBy('id_asignacion_mora', 'desc')
						->first();

					if (in_array($asignacion->id_asignacion_costo, $debugIds)) {
						Log::info("[DEBUG] ¿Mora existente?: " . ($asignacionMora ? "SÍ (ID: {$asignacionMora->id_asignacion_mora})" : 'NO (se debe crear)'));
					}

					// prorrogaTerminada ya fue buscada arriba para detectar gaps

					if (in_array($asignacion->id_asignacion_costo, $debugIds)) {
						$prorrogaTerminadaLog = null;
						if ($prorrogaTerminada) {
							$prorrogaTerminadaLog = [
								'id_prorroga_mora' => (int)(isset($prorrogaTerminada->id_prorroga_mora) ? $prorrogaTerminada->id_prorroga_mora : 0),
								'fecha_inicio_prorroga' => (string)(isset($prorrogaTerminada->fecha_inicio_prorroga) ? $prorrogaTerminada->fecha_inicio_prorroga : ''),
								'fecha_fin_prorroga' => (string)(isset($prorrogaTerminada->fecha_fin_prorroga) ? $prorrogaTerminada->fecha_fin_prorroga : ''),
								'activo' => (int)(isset($prorrogaTerminada->activo) ? $prorrogaTerminada->activo : 0),
							];
						}
						$moraExistenteLog = null;
						if ($asignacionMora) {
							$moraExistenteLog = [
								'id_asignacion_mora' => (int)(isset($asignacionMora->id_asignacion_mora) ? $asignacionMora->id_asignacion_mora : 0),
								'estado' => (string)(isset($asignacionMora->estado) ? $asignacionMora->estado : ''),
								'fecha_inicio_mora' => (string)(isset($asignacionMora->fecha_inicio_mora) ? $asignacionMora->fecha_inicio_mora : ''),
								'fecha_fin_mora' => (string)(isset($asignacionMora->fecha_fin_mora) ? $asignacionMora->fecha_fin_mora : ''),
							];
						}

						$prorrogaActivaLog = null;
						if ($prorrogaActiva) {
							$prorrogaActivaLog = [
								'id_prorroga_mora' => (int)(isset($prorrogaActiva->id_prorroga_mora) ? $prorrogaActiva->id_prorroga_mora : 0),
								'fecha_inicio_prorroga' => (string)(isset($prorrogaActiva->fecha_inicio_prorroga) ? $prorrogaActiva->fecha_inicio_prorroga : ''),
								'fecha_fin_prorroga' => (string)(isset($prorrogaActiva->fecha_fin_prorroga) ? $prorrogaActiva->fecha_fin_prorroga : ''),
								'activo' => (int)(isset($prorrogaActiva->activo) ? $prorrogaActiva->activo : 0),
							];
						}

						Log::info('[MORA_DIARIA][DEBUG] prorrogaTerminada lookup', [
							'id_asignacion_costo' => (int)$asignacion->id_asignacion_costo,
							'hoy' => $hoy->toDateString(),
							'prorroga_terminada' => $prorrogaTerminadaLog,
							'prorroga_activa' => $prorrogaActivaLog,
							'mora_existente' => $moraExistenteLog,
						]);
					}

					if ($asignacionMora) {
						$estadoMora = strtoupper(trim((string)$asignacionMora->estado));
						// Si hay prórroga terminada, verificar si necesita crear nueva mora post-prórroga
						if ($prorrogaTerminada) {
							// Verificar si ya existe mora posterior a la prórroga
							$fechaFinProrroga = Carbon::parse($prorrogaTerminada->fecha_fin_prorroga)->startOfDay();
							$fechaInicioPosterior = $fechaFinProrroga->copy()->addDay();

							// Buscar la mora CONGELADA_PRORROGA que corresponde a esta prórroga terminada
							// Esta será la mora que se debe vincular a la nueva mora post-prórroga
							// IMPORTANTE: Buscar la mora CONGELADA más antigua (id más bajo) para vincular correctamente
							// en casos de múltiples prórrogas sobre la misma mora
							$moraCongeladaVincular = AsignacionMora::where('id_asignacion_costo', $asignacion->id_asignacion_costo)
								->where('estado', 'CONGELADA_PRORROGA')
								->orderBy('id_asignacion_mora', 'asc')  // ASC para obtener la más antigua
								->first();

							// Si no hay mora congelada, usar la mora actual
							$moraAVincular = $moraCongeladaVincular ?: $asignacionMora;

							// Verificar si la mora a vincular es anterior o igual a la fecha fin de prórroga
							if ($moraAVincular && Carbon::parse($moraAVincular->fecha_inicio_mora)->startOfDay()->lte($fechaFinProrroga)) {
								if (in_array($asignacion->id_asignacion_costo, $debugIds)) {
									Log::info('[MORA_DIARIA][DEBUG] post-prorroga check: mora anterior a fin_prorroga', [
										'id_asignacion_costo' => (int)$asignacion->id_asignacion_costo,
										'id_asignacion_mora_actual' => (int)$asignacionMora->id_asignacion_mora,
										'id_mora_congelada_vincular' => $moraCongeladaVincular ? (int)$moraCongeladaVincular->id_asignacion_mora : null,
										'id_mora_a_vincular' => (int)$moraAVincular->id_asignacion_mora,
										'mora_fecha_inicio' => (string)$moraAVincular->fecha_inicio_mora,
										'prorroga_fin' => $fechaFinProrroga->toDateString(),
										'inicio_posterior' => $fechaInicioPosterior->toDateString(),
									]);
								}
								// Crear nueva mora post-prórroga si no existe
								$moraPostProrroga = AsignacionMora::where('id_asignacion_costo', $asignacion->id_asignacion_costo)
									->where('fecha_inicio_mora', '>=', $fechaInicioPosterior)
									->whereIn('estado', ['PENDIENTE', 'CONGELADA_PRORROGA', 'PAUSADA_DUPLICIDAD', 'CERRADA_SIN_CUOTA', 'EN_ESPERA'])
									->orderBy('id_asignacion_mora', 'desc')
									->first();

								if (in_array($asignacion->id_asignacion_costo, $debugIds)) {
									$existePostSinFiltro = AsignacionMora::where('id_asignacion_costo', $asignacion->id_asignacion_costo)
										->where('fecha_inicio_mora', '>=', $fechaInicioPosterior)
										->orderBy('id_asignacion_mora', 'desc')
										->first();
									Log::info('[MORA_DIARIA][DEBUG] moraPostProrroga existence (with/without estado filter)', [
										'id_asignacion_costo' => (int)$asignacion->id_asignacion_costo,
										'con_filtro' => $moraPostProrroga ? (int)$moraPostProrroga->id_asignacion_mora : null,
										'sin_filtro' => $existePostSinFiltro ? [
											'id_asignacion_mora' => (int)$existePostSinFiltro->id_asignacion_mora,
											'estado' => (string)$existePostSinFiltro->estado,
											'fecha_inicio_mora' => (string)$existePostSinFiltro->fecha_inicio_mora,
										] : null,
									]);
								}

								if (in_array($asignacion->id_asignacion_costo, $debugIds)) {
									$moraPostExistenteLog = null;
									if ($moraPostProrroga) {
										$moraPostExistenteLog = [
											'id_asignacion_mora' => (int)(isset($moraPostProrroga->id_asignacion_mora) ? $moraPostProrroga->id_asignacion_mora : 0),
											'estado' => (string)(isset($moraPostProrroga->estado) ? $moraPostProrroga->estado : ''),
											'fecha_inicio_mora' => (string)(isset($moraPostProrroga->fecha_inicio_mora) ? $moraPostProrroga->fecha_inicio_mora : ''),
											'fecha_fin_mora' => (string)(isset($moraPostProrroga->fecha_fin_mora) ? $moraPostProrroga->fecha_fin_mora : ''),
										];
									}

									Log::info('[MORA_DIARIA][DEBUG] moraPostProrroga lookup', [
										'id_asignacion_costo' => (int)$asignacion->id_asignacion_costo,
										'fecha_fin_prorroga' => $fechaFinProrroga->toDateString(),
										'fecha_inicio_posterior' => $fechaInicioPosterior->toDateString(),
										'mora_post_existente' => $moraPostExistenteLog,
									]);
								}

								if (!$moraPostProrroga) {
									// Crear nueva mora desde el día siguiente al fin de prórroga
									// Asegurar que fecha_inicio_posterior no sea mayor a hoy
									if ($fechaInicioPosterior->gt($hoy)) {
										if (in_array($asignacion->id_asignacion_costo, $debugIds)) {
											Log::info('[MORA_DIARIA][DEBUG] skip: inicio_posterior > hoy', [
												'id_asignacion_costo' => (int)$asignacion->id_asignacion_costo,
												'inicio_posterior' => $fechaInicioPosterior->toDateString(),
												'hoy' => $hoy->toDateString(),
											]);
										}
										$progressBar->advance();
										continue;
									}

									// Calcular días desde fechaInicioPosterior hasta hoy (o fecha_fin_cfg si es menor)
									$fechaFinMoraCfg = Carbon::parse($configuracionMora->fecha_fin)->startOfDay();
									$fechaCalculoFin = $hoy->lt($fechaFinMoraCfg) ? $hoy : $fechaFinMoraCfg;
									$diasPostProrroga = $fechaInicioPosterior->diffInDays($fechaCalculoFin) + 1;
									$montoMoraPostProrroga = $configuracionMora->monto * $diasPostProrroga;

									if (in_array($asignacion->id_asignacion_costo, $debugIds)) {
										Log::info('[MORA_DIARIA][DEBUG] creando mora post-prorroga', [
											'id_asignacion_costo' => (int)$asignacion->id_asignacion_costo,
											'id_mora_a_vincular' => $moraAVincular ? (int)$moraAVincular->id_asignacion_mora : null,
											'fecha_inicio_posterior' => $fechaInicioPosterior->toDateString(),
											'fecha_fin_mora_cfg' => (string)$configuracionMora->fecha_fin,
											'fecha_calculo_fin' => $fechaCalculoFin->toDateString(),
											'dias' => (int)$diasPostProrroga,
											'monto_base' => (float)$configuracionMora->monto,
											'monto_mora' => (float)$montoMoraPostProrroga,
										]);
									}

									AsignacionMora::create([
										'id_asignacion_costo' => $asignacion->id_asignacion_costo,
										'id_asignacion_vinculada' => $moraAVincular ? $moraAVincular->id_asignacion_vinculada : null,
										'id_mora_vinculada' => $moraAVincular ? (int)$moraAVincular->id_asignacion_mora : null,
										'id_datos_mora_detalle' => $configuracionMora->id_datos_mora_detalle,
										'fecha_inicio_mora' => $fechaInicioPosterior,
										'fecha_fin_mora' => $configuracionMora->fecha_fin,
										'monto_base' => $configuracionMora->monto,
										'monto_mora' => $montoMoraPostProrroga,
										'monto_descuento' => 0,
										'estado' => 'PENDIENTE',
										'observaciones' => "Mora post-prórroga aplicada desde {$fechaInicioPosterior->format('Y-m-d')}"
									]);

									$morasCreadas++;
								} else {
									// Actualizar mora post-prórroga existente
									$fechaFinMora = Carbon::parse($moraPostProrroga->fecha_fin_mora);
									$fechaCalculo = $hoy->lt($fechaFinMora) ? $hoy : $fechaFinMora;
									$diasPostProrroga = Carbon::parse($moraPostProrroga->fecha_inicio_mora)->diffInDays($fechaCalculo) + 1;
									$montoMoraPostProrroga = $configuracionMora->monto * $diasPostProrroga;

									if (in_array($asignacion->id_asignacion_costo, $debugIds)) {
										Log::info('[MORA_DIARIA][DEBUG] actualizando mora post-prorroga', [
											'id_asignacion_costo' => (int)$asignacion->id_asignacion_costo,
											'id_asignacion_mora' => (int)$moraPostProrroga->id_asignacion_mora,
											'fecha_inicio_mora' => (string)$moraPostProrroga->fecha_inicio_mora,
											'fecha_fin_mora' => (string)$moraPostProrroga->fecha_fin_mora,
											'fecha_calculo' => $fechaCalculo->toDateString(),
											'dias' => (int)$diasPostProrroga,
											'monto_base' => (float)$configuracionMora->monto,
											'monto_mora' => (float)$montoMoraPostProrroga,
										]);
									}

									$moraPostProrroga->monto_mora = $montoMoraPostProrroga;
									$moraPostProrroga->estado = 'PENDIENTE';
									$moraPostProrroga->save();

									$morasActualizadas++;
								}
							} else {
								if (in_array($asignacion->id_asignacion_costo, $debugIds)) {
									Log::info('[MORA_DIARIA][DEBUG] post-prorroga skip: mora no es anterior a fin_prorroga', [
										'id_asignacion_costo' => (int)$asignacion->id_asignacion_costo,
										'id_asignacion_mora_actual' => (int)$asignacionMora->id_asignacion_mora,
										'mora_fecha_inicio' => (string)$asignacionMora->fecha_inicio_mora,
										'prorroga_fin' => $fechaFinProrroga->toDateString(),
									]);
								}
								// Actualizar mora normal (sin prórroga o mora ya posterior)
								if (in_array($estadoMora, ['CONGELADA_PRORROGA', 'PAUSADA_DUPLICIDAD', 'CERRADA_SIN_CUOTA', 'EN_ESPERA'])) {
									$progressBar->advance();
									continue;
								}
								$fechaFinMora = Carbon::parse($asignacionMora->fecha_fin_mora);
								$fechaCalculo = $hoy->lt($fechaFinMora) ? $hoy : $fechaFinMora;
								$diasTranscurridos = Carbon::parse($asignacionMora->fecha_inicio_mora)->diffInDays($fechaCalculo) + 1;
								$montoMoraCalculado = $configuracionMora->monto * $diasTranscurridos;

								$asignacionMora->monto_mora = $montoMoraCalculado;
								$asignacionMora->estado = 'PENDIENTE';
								$asignacionMora->save();

								$morasActualizadas++;
							}
						} else {
							// Actualizar mora existente sin prórroga
							if (in_array($estadoMora, ['CONGELADA_PRORROGA', 'PAUSADA_DUPLICIDAD', 'CERRADA_SIN_CUOTA', 'EN_ESPERA'])) {
								$progressBar->advance();
								continue;
							}
							$fechaFinMora = Carbon::parse($asignacionMora->fecha_fin_mora);
							$fechaCalculo = $hoy->lt($fechaFinMora) ? $hoy : $fechaFinMora;
							$diasTranscurridos = Carbon::parse($asignacionMora->fecha_inicio_mora)->diffInDays($fechaCalculo) + 1;
							$montoMoraCalculado = $configuracionMora->monto * $diasTranscurridos;

							$asignacionMora->monto_mora = $montoMoraCalculado;
							$asignacionMora->estado = 'PENDIENTE';
							$asignacionMora->save();

							$morasActualizadas++;
						}
					} else {
						// No existe mora, verificar si debe crear
						if ($prorrogaTerminada) {
							// Caso 2: Hubo prórroga, crear mora desde fin de prórroga
							$fechaFinProrroga = Carbon::parse($prorrogaTerminada->fecha_fin_prorroga);
							$fechaInicioPosterior = $fechaFinProrroga->copy()->addDay();
							$fechaFinMora = Carbon::parse($configuracionMora->fecha_fin);
							$fechaCalculo = $hoy->lt($fechaFinMora) ? $hoy : $fechaFinMora;
							$diasPostProrroga = $fechaInicioPosterior->diffInDays($fechaCalculo) + 1;
							$montoMoraPostProrroga = $configuracionMora->monto * $diasPostProrroga;

							$moraAnterior = AsignacionMora::where('id_asignacion_costo', $asignacion->id_asignacion_costo)
								->where('fecha_inicio_mora', '<', $fechaInicioPosterior)
								->whereIn('estado', ['PENDIENTE', 'CONGELADA_PRORROGA', 'PAUSADA_DUPLICIDAD', 'CERRADA_SIN_CUOTA', 'EN_ESPERA', 'PAGADO', 'CONDONADO'])
								->orderBy('id_asignacion_mora', 'desc')
								->first();

							if (in_array($asignacion->id_asignacion_costo, $debugIds)) {
								Log::info('[MORA_DIARIA][DEBUG] enlace mora post-prorroga (sin mora existente)', [
									'id_asignacion_costo' => (int)$asignacion->id_asignacion_costo,
									'id_mora_anterior' => $moraAnterior ? (int)$moraAnterior->id_asignacion_mora : null,
									'fecha_inicio_posterior' => $fechaInicioPosterior->toDateString(),
								]);
							}

							AsignacionMora::create([
								'id_asignacion_costo' => $asignacion->id_asignacion_costo,
								'id_mora_vinculada' => $moraAnterior ? (int)$moraAnterior->id_asignacion_mora : null,
								'id_datos_mora_detalle' => $configuracionMora->id_datos_mora_detalle,
								'fecha_inicio_mora' => $fechaInicioPosterior,
								'fecha_fin_mora' => $configuracionMora->fecha_fin,
								'monto_base' => $configuracionMora->monto,
								'monto_mora' => $montoMoraPostProrroga,
								'monto_descuento' => 0,
								'estado' => 'PENDIENTE',
								'observaciones' => "Mora post-prórroga aplicada desde {$fechaInicioPosterior->format('Y-m-d')}"
							]);

							$morasCreadas++;
						} else {
							// Caso normal: Crear nueva asignación de mora
							$fechaFinMora = Carbon::parse($configuracionMora->fecha_fin);
							$fechaCalculo = $hoy->lt($fechaFinMora) ? $hoy : $fechaFinMora;
							$diasTranscurridos = $fechaInicioMora->diffInDays($fechaCalculo) + 1;
							$montoMoraCalculado = $configuracionMora->monto * $diasTranscurridos;

							$estadoInicial = 'PENDIENTE';
							$observacionBase = 'Mora aplicada automáticamente desde ' . $fechaInicioMora->format('Y-m-d');

							$nuevaMora = AsignacionMora::create([
								'id_asignacion_costo' => $asignacion->id_asignacion_costo,
								'id_datos_mora_detalle' => $configuracionMora->id_datos_mora_detalle,
								'fecha_inicio_mora' => $fechaInicioMora,
								'fecha_fin_mora' => $configuracionMora->fecha_fin,
								'monto_base' => $configuracionMora->monto,
								'monto_mora' => $montoMoraCalculado,
								'monto_descuento' => 0,
								'estado' => $estadoInicial,
								'observaciones' => $observacionBase,
							]);

							$morasCreadas++;
						}
					}

				} catch (\Exception $e) {
					$errores++;
					Log::error("Error procesando asignación {$asignacion->id_asignacion_costo}: {$e->getMessage()}");
				}

				$progressBar->advance();
			}

			$progressBar->finish();
			$this->newLine(2);

			// Resumen
			$this->info("\n=== Resumen del Procesamiento ===");
			$this->info("Moras creadas: {$morasCreadas}");
			$this->info("Moras actualizadas: {$morasActualizadas}");
			$this->info("Moras cerradas: {$morasCerradas}");

			if ($errores > 0) {
				$this->warn("Errores encontrados: {$errores}");
			}

			$this->info("\n✓ Procesamiento completado exitosamente.");

			return 0;

		} catch (\Exception $e) {
			$this->error("\n✗ Error general: {$e->getMessage()}");
			Log::error("Error en procesamiento de mora diaria: {$e->getMessage()}", [
				'trace' => $e->getTraceAsString()
			]);
			return 1;
		}
	}

	/**
	 * Cierra las moras de cuotas que ya fueron pagadas completamente.
	 *
	 * @param Carbon $hoy
	 * @return int
	 */
	private function cerrarMorasPagadas(Carbon $hoy)
	{
		$morasCerradas = 0;

		// Buscar moras recalculables/cerrables cuyas cuotas ya están pagadas
		// IMPORTANTE: Solo cerrar moras en estado PENDIENTE para preservar el histórico de prórrogas
		// Las moras CONGELADA_PRORROGA deben mantenerse intactas como registro histórico
		$morasPendientes = AsignacionMora::where('estado', 'PENDIENTE')
			->with('asignacionCosto')
			->get();

		foreach ($morasPendientes as $mora) {
			try {
				if (!$mora->asignacionCosto || (string)$mora->asignacionCosto->estado_pago !== 'COBRADO') {
					continue;
				}

				$montoCuota = isset($mora->asignacionCosto->monto) ? (float)$mora->asignacionCosto->monto : null;
				$montoPagadoCuota = isset($mora->asignacionCosto->monto_pagado) ? (float)$mora->asignacionCosto->monto_pagado : null;
				$descuentoCuota = isset($mora->asignacionCosto->descuento) ? (float)$mora->asignacionCosto->descuento : null;
				if ($montoCuota !== null && $montoPagadoCuota !== null) {
					$netoCuota = $montoCuota;
					if ($descuentoCuota !== null) {
						$netoCuota = max(0, $netoCuota - $descuentoCuota);
					}
					if ($montoPagadoCuota + 0.0001 < $netoCuota) {
						continue;
					}
				}

				$fechaPagoStr = (string)(isset($mora->asignacionCosto->fecha_pago) ? $mora->asignacionCosto->fecha_pago : '');
				if ($fechaPagoStr === '') {
					continue;
				}

				$fechaPago = Carbon::parse($fechaPagoStr)->startOfDay();
				$fechaCorte = $fechaPago->copy();
				$inicio = !empty($mora->fecha_inicio_mora) ? Carbon::parse($mora->fecha_inicio_mora)->startOfDay() : null;
				$fin = !empty($mora->fecha_fin_mora) ? Carbon::parse($mora->fecha_fin_mora)->startOfDay() : null;
				$montoBaseDia = (float)(isset($mora->monto_base) ? $mora->monto_base : 0);
				$desc = (float)(isset($mora->monto_descuento) ? $mora->monto_descuento : 0);
				$montoPagado = (float)(isset($mora->monto_pagado) ? $mora->monto_pagado : 0);

				if (!$inicio || $montoBaseDia <= 0) {
					continue;
				}

				$fechaCalculo = $fechaCorte;
				if ($fin && $fin->lt($fechaCalculo)) {
					$fechaCalculo = $fin;
				}
				$fechaCalculo = $fechaCalculo->copy()->startOfDay();

				$dias = 0;
				if ($fechaCalculo->gte($inicio)) {
					$dias = $inicio->diffInDays($fechaCalculo) + 1;
				}
				$montoCalc = (float)$montoBaseDia * (int)$dias;
				$estadoFinal = 'CERRADA_SIN_CUOTA';

				$mora->monto_mora = $montoCalc;
				$mora->estado = $estadoFinal;
				$observacionesActuales = $mora->observaciones ? $mora->observaciones : '';
				$mora->observaciones = $observacionesActuales . " | Cierre automático por cuota COBRADO (fecha_pago={$fechaPago->format('Y-m-d')}, corte={$fechaCorte->format('Y-m-d')})";
				$mora->save();

				$morasCerradas++;
			} catch (\Throwable $e) {
				continue;
			}
		}

		return $morasCerradas;
	}

	/**
	 * Obtiene el semestre de la inscripción.
	 *
	 * @param mixed $asignacion
	 * @return string|null
	 */
	private function obtenerSemestreInscripcion($asignacion)
	{
		if (!$asignacion->inscripcion) {
			return null;
		}

		$inscripcion = $asignacion->inscripcion;

		if (isset($inscripcion->semestre)) {
			return (string) ((int) $inscripcion->semestre);
		}

		if (isset($inscripcion->nro_semestre)) {
			return (string) ((int) $inscripcion->nro_semestre);
		}

		if (isset($inscripcion->cod_curso)) {
			$sem = $this->extraerSemestreDeCurso($inscripcion->cod_curso);
			if ($sem !== null && $sem !== '') {
				return (string) ((int) $sem);
			}
		}

		return null;
	}

	/**
	 * Extrae el semestre del código de curso si es posible.
	 *
	 * @param string|null $codCurso
	 * @return string|null
	 */
	private function extraerSemestreDeCurso($codCurso)
	{
		$raw = trim((string) $codCurso);
		if ($raw === '') {
			return null;
		}

		$parts = explode('-', strtoupper($raw));
		$suffix = trim((string) end($parts));
		if ($suffix !== '') {
			$first = substr($suffix, 0, 1);
			if ($first !== false && preg_match('/^[1-9]$/', $first)) {
				return (string) $first;
			}
		}

		if (preg_match('/(\d)/', $raw, $m)) {
			return (string) ((int) $m[1]);
		}

		return null;
	}

	/**
	 * Obtiene la gestión de la inscripción.
	 *
	 * @param mixed $asignacion
	 * @return string|null
	 */
	private function obtenerGestionInscripcion($asignacion)
	{
		if (!$asignacion->inscripcion) {
			return null;
		}

		$inscripcion = $asignacion->inscripcion;

		// Obtener gestión desde la inscripción
		if (isset($inscripcion->gestion)) {
			return $inscripcion->gestion;
		}

		return null;
	}

	/**
	 * Construye una clave de grupo para controlar mora única por estudiante/gestión/cuota.
	 *
	 * @param mixed $asignacion
	 * @param string|null $gestion
	 * @return string|null
	 */
	private function buildGrupoMoraKey($asignacion, $gestion = null)
	{
		if (!$asignacion->inscripcion) {
			return null;
		}

		$codCeta = isset($asignacion->inscripcion->cod_ceta)
			? (string)$asignacion->inscripcion->cod_ceta
			: '';
		$gestionValue = $gestion !== null ? $gestion : $this->obtenerGestionInscripcion($asignacion);
		$cuota = isset($asignacion->numero_cuota)
			? (int)$asignacion->numero_cuota
			: 0;

		if ($codCeta === '' || $gestionValue === null || $gestionValue === '' || $cuota <= 0) {
			return null;
		}

		return $codCeta . '|' . (string)$gestionValue . '|' . (string)$cuota;
	}

	/**
	 * Normaliza cod_pensum para contemplar casos con prefijos (ej: 79-EEA-19 vs EEA-19).
	 *
	 * @param string|null $codPensum
	 * @return string
	 */
	private function normalizarCodPensum($codPensum)
	{
		$valor = strtoupper(trim((string)$codPensum));
		if ($valor === '') {
			return '';
		}

		if (preg_match('/^\d+-(.+)$/', $valor, $matches)) {
			return trim((string)$matches[1]);
		}

		return $valor;
	}

	/**
	 * Desactiva las prórrogas que ya vencieron.
	 *
	 * @param Carbon $hoy
	 * @return int
	 */
	private function desactivarProrrogasVencidas($hoy)
	{
		$prorrogasVencidas = ProrrogaMora::where('activo', true)
			->where('fecha_fin_prorroga', '<', $hoy)
			->get();

		$desactivadas = 0;
		foreach ($prorrogasVencidas as $prorroga) {
			$prorroga->activo = false;
			$prorroga->save();
			$desactivadas++;
		}

		return $desactivadas;
	}

	private function shouldDesactivarProrrogasVencidas()
	{
		return (bool)$this->option('desactivar-prorrogas');
	}
}
