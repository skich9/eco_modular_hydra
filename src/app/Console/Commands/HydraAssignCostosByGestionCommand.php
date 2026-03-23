<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use App\Jobs\AssignCostoSemestralFromInscripcion;

class HydraAssignCostosByGestionCommand extends Command
{
	protected $signature = 'hydra:assign-costos
		{gestion : Gestion a procesar (ej: 2/2025)}
		{--chunk=200 : Tamaño del chunk}
		{--dry-run : No crea asignaciones, solo muestra conteos}
		{--sync : Ejecuta el job en el proceso actual (sin cola)}
		{--carrera= : Filtrar por inscripciones.carrera (opcional)}';

	protected $description = 'Asigna costos (asignacion_costos) para inscripciones de una gestion específica reutilizando AssignCostoSemestralFromInscripcion';

	public function handle()
	{
		$gestion = trim((string) $this->argument('gestion'));
		$chunk = (int) $this->option('chunk');
		$dry = (bool) $this->option('dry-run');
		$sync = (bool) $this->option('sync');
		$carrera = $this->option('carrera');
		$carrera = $carrera !== null && trim((string)$carrera) !== '' ? trim((string)$carrera) : null;

		if ($gestion === '') {
			$this->error('Gestion requerida');
			return self::FAILURE;
		}
		if (!Schema::hasTable('inscripciones') || !Schema::hasTable('asignacion_costos')) {
			$this->error('Tablas requeridas no existen: inscripciones / asignacion_costos');
			return self::FAILURE;
		}

		$base = DB::table('inscripciones')->where('gestion', $gestion);
		if ($carrera !== null && Schema::hasColumn('inscripciones', 'carrera')) {
			$base->where('carrera', $carrera);
		}

		$total = (int) $base->count();
		$this->info("Encontradas inscripciones: {$total} (gestion={$gestion})");

		if ($dry) {
			$pend = (int) DB::table('inscripciones as i')
				->leftJoin('asignacion_costos as a', 'a.cod_inscrip', '=', 'i.cod_inscrip')
				->where('i.gestion', $gestion)
				->when($carrera !== null && Schema::hasColumn('inscripciones', 'carrera'), function ($q) use ($carrera) {
					$q->where('i.carrera', $carrera);
				})
				->whereNull('a.cod_inscrip')
				->count();
			$this->info("Pendientes (sin asignacion_costos): {$pend}");
			return self::SUCCESS;
		}

		$dispatched = 0;
		$skippedExisting = 0;
		$errors = 0;
		$processed = 0;
		$errorLogLeft = 25;

		DB::table('inscripciones')
			->select('cod_inscrip','cod_pensum','gestion','cod_curso','tipo_inscripcion')
			->where('gestion', $gestion)
			->when($carrera !== null && Schema::hasColumn('inscripciones', 'carrera'), function ($q) use ($carrera) {
				$q->where('carrera', $carrera);
			})
			->orderBy('cod_inscrip')
			->chunk($chunk, function ($rows) use (&$dispatched, &$skippedExisting, &$errors, &$processed, &$errorLogLeft, $sync, $gestion) {
				foreach ($rows as $r) {
					try {
						$processed++;
						if ($processed % 500 === 0) {
							try {
								Log::info('hydra:assign-costos progress', [
									'gestion' => $gestion,
									'processed' => $processed,
									'dispatched' => $dispatched,
									'skipped_existing' => $skippedExisting,
									'errors' => $errors,
								]);
							} catch (\Throwable $e) { /* no-op */ }
						}
						$exists = DB::table('asignacion_costos')->where('cod_inscrip', $r->cod_inscrip)->exists();
						if ($exists) {
							$skippedExisting++;
							continue;
						}

						$payload = [
							'cod_inscrip' => (int) $r->cod_inscrip,
							'cod_pensum' => (string) (isset($r->cod_pensum) ? $r->cod_pensum : ''),
							'gestion' => (string) (isset($r->gestion) ? $r->gestion : ''),
							'cod_curso' => (string) (isset($r->cod_curso) ? $r->cod_curso : ''),
							'tipo_inscripcion' => (string) (isset($r->tipo_inscripcion) ? $r->tipo_inscripcion : 'NORMAL'),
						];

						if ($sync) {
							$job = new AssignCostoSemestralFromInscripcion($payload);
							$job->handle();
						} else {
							AssignCostoSemestralFromInscripcion::dispatch($payload);
						}
						$dispatched++;
					} catch (\Throwable $e) {
						$errors++;
						try {
							if ($errorLogLeft > 0) {
								$errorLogLeft--;
								Log::error('hydra:assign-costos error', [
									'gestion' => $gestion,
									'cod_inscrip' => isset($r->cod_inscrip) ? (int) $r->cod_inscrip : null,
									'cod_pensum' => isset($r->cod_pensum) ? (string) $r->cod_pensum : null,
									'cod_curso' => isset($r->cod_curso) ? (string) $r->cod_curso : null,
									'tipo_inscripcion' => isset($r->tipo_inscripcion) ? (string) $r->tipo_inscripcion : null,
									'sync' => $sync,
									'error' => $e->getMessage(),
								]);
							}
						} catch (\Throwable $e2) { /* no-op */ }
					}
				}
			});

		$this->info("Procesadas: {$total}");
		$this->info("Encoladas/Ejecutadas: {$dispatched}");
		$this->info("Saltadas (ya existían): {$skippedExisting}");
		$this->info("Errores: {$errors}");

		return $errors > 0 ? self::FAILURE : self::SUCCESS;
	}
}
