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
			$asignacionesPendientes = AsignacionCostos::whereIn('estado_pago', ['PENDIENTE', 'PARCIAL'])
				->with(['pensum', 'inscripcion'])
				->get();

			$this->info("   ✓ Cuotas encontradas: {$asignacionesPendientes->count()}");

			if ($asignacionesPendientes->isEmpty()) {
				$this->info("\n✓ No hay cuotas pendientes para procesar.");
				return 0;
			}

			// 4. Procesar cada asignación de costo
			$this->info("\n4. Procesando cada cuota...");
			$progressBar = $this->output->createProgressBar($asignacionesPendientes->count());
			$progressBar->start();

			foreach ($asignacionesPendientes as $asignacion) {
				try {
					// Obtener datos necesarios
					$codPensum = $asignacion->cod_pensum;
					$numeroCuota = $asignacion->numero_cuota;

					// Obtener semestre y gestión de la inscripción
					$semestre = $this->obtenerSemestreInscripcion($asignacion);
					$gestion = $this->obtenerGestionInscripcion($asignacion);

					if (!$semestre || !$gestion) {
						$progressBar->advance();
						continue;
					}

					// Buscar configuración de mora aplicable por gestión, pensum, cuota y semestre
					$configuracionMora = DatosMoraDetalle::where('cod_pensum', $codPensum)
						->where('cuota', $numeroCuota)
						->where('semestre', $semestre)
						->where('activo', true)
						->whereHas('datosMora', function($query) use ($gestion) {
							$query->where('gestion', $gestion);
						})
						->with('datosMora')
						->first();

					if (!$configuracionMora) {
						$progressBar->advance();
						continue;
					}

					// Verificar si existe prórroga activa para esta cuota
					$prorrogaActiva = ProrrogaMora::where('id_asignacion_costo', $asignacion->id_asignacion_costo)
						->activas($hoy)
						->first();

					// Si hay prórroga activa, no procesar mora
					if ($prorrogaActiva) {
						$progressBar->advance();
						continue;
					}

					// Verificar si la fecha de inicio de mora ya pasó
					$fechaInicioMora = Carbon::parse($configuracionMora->fecha_inicio);
					if ($fechaInicioMora->gt($hoy)) {
						$progressBar->advance();
						continue;
					}

					// Verificar si ya existe asignación de mora para esta cuota
					$asignacionMora = AsignacionMora::where('id_asignacion_costo', $asignacion->id_asignacion_costo)
						->whereIn('estado', ['PENDIENTE'])
						->first();

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
									->where('estado', 'PENDIENTE')
									->first();

								if (!$moraPostProrroga) {
									// Crear nueva mora desde el día siguiente al fin de prórroga
									$diasPostProrroga = $fechaInicioPosterior->diffInDays($hoy) + 1;
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
									$diasPostProrroga = Carbon::parse($moraPostProrroga->fecha_inicio_mora)->diffInDays($hoy) + 1;
									$montoMoraPostProrroga = $configuracionMora->monto * $diasPostProrroga;

									$moraPostProrroga->monto_mora = $montoMoraPostProrroga;
									$moraPostProrroga->save();

									$morasActualizadas++;
								}
							} else {
								// Actualizar mora normal (sin prórroga o mora ya posterior)
								$diasTranscurridos = Carbon::parse($asignacionMora->fecha_inicio_mora)->diffInDays($hoy) + 1;
								$montoMoraCalculado = $configuracionMora->monto * $diasTranscurridos;

								$asignacionMora->monto_mora = $montoMoraCalculado;
								$asignacionMora->save();

								$morasActualizadas++;
							}
						} else {
							// Actualizar mora existente sin prórroga
							$diasTranscurridos = Carbon::parse($asignacionMora->fecha_inicio_mora)->diffInDays($hoy) + 1;
							$montoMoraCalculado = $configuracionMora->monto * $diasTranscurridos;

							$asignacionMora->monto_mora = $montoMoraCalculado;
							$asignacionMora->save();

							$morasActualizadas++;
						}
					} else {
						// No existe mora, verificar si debe crear
						if ($prorrogaTerminada) {
							// Caso 2: Hubo prórroga, crear mora desde fin de prórroga
							$fechaFinProrroga = Carbon::parse($prorrogaTerminada->fecha_fin_prorroga);
							$fechaInicioPosterior = $fechaFinProrroga->copy()->addDay();
							$diasPostProrroga = $fechaInicioPosterior->diffInDays($hoy) + 1;
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
							$diasTranscurridos = $fechaInicioMora->diffInDays($hoy) + 1;
							$montoMoraCalculado = $configuracionMora->monto * $diasTranscurridos;

							AsignacionMora::create([
								'id_asignacion_costo' => $asignacion->id_asignacion_costo,
								'id_datos_mora_detalle' => $configuracionMora->id_datos_mora_detalle,
								'fecha_inicio_mora' => $fechaInicioMora,
								'fecha_fin_mora' => $configuracionMora->fecha_fin,
								'monto_base' => $configuracionMora->monto,
								'monto_mora' => $montoMoraCalculado,
								'monto_descuento' => 0,
								'estado' => 'PENDIENTE',
								'observaciones' => "Mora aplicada automáticamente desde {$fechaInicioMora->format('Y-m-d')}"
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

		// Obtener el semestre desde la inscripción
		// Puede estar en diferentes campos según la estructura
		$inscripcion = $asignacion->inscripcion;

		// Intentar obtener semestre de diferentes campos posibles
		if (isset($inscripcion->semestre)) {
			return (string) $inscripcion->semestre;
		}

		if (isset($inscripcion->nro_semestre)) {
			return (string) $inscripcion->nro_semestre;
		}

		// Si no hay campo directo, intentar obtener del pensum o curso
		if (isset($inscripcion->cod_curso)) {
			// El código de curso puede contener información del semestre
			// Esto depende de la estructura específica del sistema
			return $this->extraerSemestreDeCurso($inscripcion->cod_curso);
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

		// Intentar extraer número de semestre del código
		// Esto es una implementación genérica que puede necesitar ajustes
		if (preg_match('/(\d+)/', $codCurso, $matches)) {
			return $matches[1];
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
