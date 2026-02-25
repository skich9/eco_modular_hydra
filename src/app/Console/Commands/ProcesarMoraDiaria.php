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
	protected $signature = 'mora:procesar-diaria {--force : Forzar ejecución sin confirmación}';

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
			// 1. Desactivar prórrogas vencidas
			$this->info("\n1. Desactivando prórrogas vencidas...");
			$prorrogasDesactivadas = $this->desactivarProrrogasVencidas($hoy);
			$this->info("   ✓ Prórrogas desactivadas: {$prorrogasDesactivadas}");

			// 2. Cerrar moras de cuotas que ya fueron pagadas
			$this->info("\n2. Cerrando moras de cuotas pagadas...");
			$morasCerradas = $this->cerrarMorasPagadas($hoy);
			$this->info("   ✓ Moras cerradas: {$morasCerradas}");

			// 3. Buscar asignaciones de costos pendientes o parciales
			$this->info("\n3. Buscando cuotas pendientes o parciales...");
			$asignacionesPendientes = AsignacionCostos::whereIn('estado_pago', ['PENDIENTE', 'PARCIAL', 'pendiente', 'parcial'])
				->with(['pensum', 'inscripcion'])
				->get();

			$this->info("   ✓ Cuotas encontradas: {$asignacionesPendientes->count()}");

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
					$debugIds = [223, 224, 228, 229];
					if (in_array($asignacion->id_asignacion_costo, $debugIds)) {
						Log::info("[DEBUG] Procesando asignación {$asignacion->id_asignacion_costo}");
					}

					// Obtener semestre y gestión de la inscripción
					$semestre = $this->obtenerSemestreInscripcion($asignacion);
					$gestion = $this->obtenerGestionInscripcion($asignacion);

					if (!$gestion) {
						$progressBar->advance();
						continue;
					}

					$grupoKey = $this->buildGrupoMoraKey($asignacion, $gestion);
					$idsGrupo = ($grupoKey && isset($asignacionesPorGrupo[$grupoKey]))
						? $asignacionesPorGrupo[$grupoKey]
						: [];

					$esDuplicado = count($idsGrupo) > 1;

					// Buscar configuración de mora aplicable por gestión, pensum y cuota.
					// El semestre es opcional para evitar perder coincidencias por normalización.
					$codPensumNormalizado = $this->normalizarCodPensum($codPensum);
					$pensumsBusqueda = [$codPensum];
					if ($codPensumNormalizado !== '' && $codPensumNormalizado !== $codPensum) {
						$pensumsBusqueda[] = $codPensumNormalizado;
					}

					$queryCfg = DatosMoraDetalle::whereIn('cod_pensum', array_values(array_unique($pensumsBusqueda)))
						->where('cuota', $numeroCuota)
						->where('activo', true)
						->whereHas('datosMora', function($query) use ($gestion) {
							$query->where('gestion', $gestion);
						});

					if (!empty($semestre)) {
						$queryCfg->where('semestre', $semestre);
					}

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
						$progressBar->advance();
						continue;
					}

					// Si es duplicado y ya tiene mora, marcarla como EN_ESPERA
					if (in_array($asignacion->id_asignacion_costo, $debugIds)) {
						Log::info("[DEBUG] ¿Es duplicado?: " . ($esDuplicado ? 'SÍ' : 'NO'));
					}
					if ($esDuplicado && !isset($gruposPausados[$grupoKey])) {
						AsignacionMora::whereIn('id_asignacion_costo', $idsGrupo)
							->where('estado', 'PENDIENTE')
							->update([
								'estado' => 'EN_ESPERA',
								'updated_at' => now(),
							]);
						$gruposPausados[$grupoKey] = true;
					}

					// Verificar si existe prórroga activa para esta cuota
					$prorrogaActiva = ProrrogaMora::where('id_asignacion_costo', $asignacion->id_asignacion_costo)
						->activas($hoy)
						->first();

					if (in_array($asignacion->id_asignacion_costo, $debugIds)) {
						Log::info("[DEBUG] ¿Prórroga activa?: " . ($prorrogaActiva ? 'SÍ (se salta)' : 'NO'));
					}

					// Si hay prórroga activa, no procesar mora
					if ($prorrogaActiva) {
						$progressBar->advance();
						continue;
					}

					// Verificar si la fecha de inicio de mora ya pasó
					$fechaInicioMora = Carbon::parse($configuracionMora->fecha_inicio);
					if (in_array($asignacion->id_asignacion_costo, $debugIds)) {
						Log::info("[DEBUG] Fecha inicio: {$fechaInicioMora->format('Y-m-d')}, Hoy: {$hoy->format('Y-m-d')}, ¿Inicio > Hoy?: " . ($fechaInicioMora->gt($hoy) ? 'SÍ (se salta)' : 'NO'));
					}
					if ($fechaInicioMora->gt($hoy)) {
						$progressBar->advance();
						continue;
					}

					// Verificar si ya existe asignación de mora para esta cuota
					$asignacionMora = AsignacionMora::where('id_asignacion_costo', $asignacion->id_asignacion_costo)
						->whereIn('estado', ['PENDIENTE', 'EN_ESPERA'])
						->orderBy('id_asignacion_mora', 'desc')
						->first();

					if (in_array($asignacion->id_asignacion_costo, $debugIds)) {
						Log::info("[DEBUG] ¿Mora existente?: " . ($asignacionMora ? "SÍ (ID: {$asignacionMora->id_asignacion_mora})" : 'NO (se debe crear)'));
					}

					// Verificar si hubo prórroga que ya terminó (Caso 2)
					$prorrogaTerminada = ProrrogaMora::where('id_asignacion_costo', $asignacion->id_asignacion_costo)
						->where('fecha_fin_prorroga', '<', $hoy)
						->orderBy('fecha_fin_prorroga', 'desc')
						->first();

					if ($asignacionMora) {
						// Si hay prórroga terminada, verificar si necesita crear nueva mora post-prórroga
						if ($prorrogaTerminada) {
							// Verificar si ya existe mora posterior a la prórroga
							$fechaFinProrroga = Carbon::parse($prorrogaTerminada->fecha_fin_prorroga);
							$fechaInicioPosterior = $fechaFinProrroga->copy()->addDay();

							// Verificar si la mora actual es anterior a la prórroga
							if (Carbon::parse($asignacionMora->fecha_inicio_mora)->lt($fechaFinProrroga)) {
								// Crear nueva mora post-prórroga si no existe
								$moraPostProrroga = AsignacionMora::where('id_asignacion_costo', $asignacion->id_asignacion_costo)
									->where('fecha_inicio_mora', '>=', $fechaInicioPosterior)
									->whereIn('estado', ['PENDIENTE', 'EN_ESPERA'])
									->orderBy('id_asignacion_mora', 'desc')
									->first();

								if (!$moraPostProrroga) {
									// Crear nueva mora desde el día siguiente al fin de prórroga
									$fechaFinMora = Carbon::parse($configuracionMora->fecha_fin);
									$fechaCalculo = $hoy->lt($fechaFinMora) ? $hoy : $fechaFinMora;
									$diasPostProrroga = $fechaInicioPosterior->diffInDays($fechaCalculo) + 1;
									$montoMoraPostProrroga = $configuracionMora->monto * $diasPostProrroga;

									AsignacionMora::create([
										'id_asignacion_costo' => $asignacion->id_asignacion_costo,
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

									$moraPostProrroga->monto_mora = $montoMoraPostProrroga;
									$moraPostProrroga->estado = 'PENDIENTE';
									$moraPostProrroga->save();

									$morasActualizadas++;
								}
							} else {
								// Actualizar mora normal (sin prórroga o mora ya posterior)
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

							AsignacionMora::create([
								'id_asignacion_costo' => $asignacion->id_asignacion_costo,
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

							$estadoInicial = $esDuplicado ? 'EN_ESPERA' : 'PENDIENTE';
							$observacionBase = 'Mora aplicada automáticamente desde ' . $fechaInicioMora->format('Y-m-d');
							if ($esDuplicado) {
								$observacionBase .= ' | EN_ESPERA por inscripción duplicada';
							}

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

		// Buscar moras pendientes cuyas cuotas ya están pagadas
		$morasPendientes = AsignacionMora::where('estado', 'PENDIENTE')
			->whereNull('fecha_fin_mora')
			->with('asignacionCosto')
			->get();

		foreach ($morasPendientes as $mora) {
			if ($mora->asignacionCosto && $mora->asignacionCosto->estado_pago === 'COBRADO') {
				// Cerrar la mora (NO se modifica fecha_fin_mora, solo el estado)
				$mora->estado = 'PAGADO';
				$observacionesActuales = $mora->observaciones ? $mora->observaciones : '';
				$mora->observaciones = $observacionesActuales . " | Pagada el {$hoy->format('Y-m-d')} por pago completo de cuota.";
				$mora->save();

				$morasCerradas++;
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

		if (isset($inscripcion->gestion) && is_string($inscripcion->gestion)) {
			if (preg_match('/-(\d+)/', $inscripcion->gestion, $m)) {
				return (string) ((int) $m[1]);
			}
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
		if (!$codCurso) {
			return null;
		}

		if (preg_match('/(\d{3})/', $codCurso, $m)) {
			$n = (int) $m[1];
			if ($n >= 200) {
				return '2';
			}
			return '1';
		}

		if (preg_match('/(\d)/', $codCurso, $m)) {
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
}
