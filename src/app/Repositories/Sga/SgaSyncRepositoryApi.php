<?php

namespace App\Repositories\Sga;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\SgaApiService;
use App\Models\Pensum;

/**
 * Alternativo para sincronización SGA usando API REST
 * Este repositorio es la versión segura para producción que NO usa conexiones directas a BD
 *
 * Mantiene los mismos métodos que SgaSyncRepository pero usando APIs REST
 */
class SgaSyncRepositoryApi
{
	protected $apiService;

	public function __construct()
	{
		$this->apiService = new SgaApiService();
	}

	/**
	 * Obtiene datos de estudiante usando API REST
	 *
	 * @param int $codCeta Código del estudiante
	 * @param string|null $codPensum Código del pensum (opcional)
	 * @return object|null Datos del estudiante
	 */
	public function getEstudiante($codCeta, $codPensum = null)
	{
		try {
			// Determinar la carrera según el pensum
			$carrera = $this->determinarCarrera($codCeta, $codPensum);

			// Intentar primero con la carrera determinada
			$response = $this->apiService->getEstudiante($codCeta, $carrera);

			if ($response['success'] && $response['data']) {
				return (object) $response['data'];
			}

			// Si no se encuentra, intentar con la otra carrera como fallback
			$otraCarrera = $carrera === 'EEA' ? 'MEA' : 'EEA';
			$response = $this->apiService->getEstudiante($codCeta, $otraCarrera);

			if ($response['success'] && $response['data']) {
				return (object) $response['data'];
			}

			return null;
		} catch (\Throwable $e) {
			Log::warning('SgaSyncRepositoryApi: Error al obtener estudiante', [
				'cod_ceta' => $codCeta,
				'error' => $e->getMessage()
			]);
			return null;
		}
	}

	/**
	 * Obtiene inscripciones de estudiante usando API REST
	 *
	 * @param int $codCeta Código del estudiante
	 * @param string|null $codPensum Código del pensum (opcional)
	 * @param array $filters Filtros adicionales
	 * @return \Illuminate\Support\Collection Colección de inscripciones
	 */
	public function getInscripciones($codCeta, $codPensum = null, $filters = [])
	{
		try {
			$carrera = $this->determinarCarrera($codCeta, $codPensum);

			$response = $this->apiService->getInscripciones($codCeta, $carrera, $filters);

			if ($response['success'] && $response['data']) {
				return collect($response['data']);
			}

			// Fallback a la otra carrera
			$otraCarrera = $carrera === 'EEA' ? 'MEA' : 'EEA';
			$response = $this->apiService->getInscripciones($codCeta, $otraCarrera, $filters);

			if ($response['success'] && $response['data']) {
				return collect($response['data']);
			}

			return collect([]);
		} catch (\Throwable $e) {
			Log::warning('SgaSyncRepositoryApi: Error al obtener inscripciones', [
				'cod_ceta' => $codCeta,
				'error' => $e->getMessage()
			]);
			return collect([]);
		}
	}

	/**
	 * Sincroniza estudiantes usando API REST
	 *
	 * @param string $carrera 'EEA' o 'MEA'
	 * @param bool $dryRun Si true, no escribe en BD
	 * @return array {carrera, total, inserted, updated}
	 */
	public function syncEstudiantes($carrera = 'EEA', $dryRun = false)
	{
		$carrera = strtoupper($carrera);
		$total = 0;
		$inserted = 0;
		$updated = 0;
		$page = 1;
		$perPage = 1000;

		try {
			Log::info('SgaSyncRepositoryApi: Iniciando sincronización de estudiantes', [
				'carrera' => $carrera,
				'dry_run' => $dryRun
			]);

			do {
				$response = $this->apiService->syncEstudiantes($carrera, $page, $perPage);

				if (!$response['success'] || empty($response['data'])) {
					Log::info('SgaSyncRepositoryApi: No hay más datos', ['page' => $page]);
					break;
				}

				$estudiantes = $response['data'];
				$total += count($estudiantes);

				Log::info('SgaSyncRepositoryApi: Procesando página', [
					'page' => $page,
					'count' => count($estudiantes)
				]);

				if ($dryRun) {
					$page++;
					continue;
				}

				// Procesar estudiantes
				$payload = [];
				$now = now();
				foreach ($estudiantes as $r) {
					$codCeta = (int) ($r['cod_ceta'] ?? 0);
					if ($codCeta === 0) {
						continue;
					}
					$payload[] = [
						'cod_ceta'   => $codCeta,
						'ci'         => '',
						'nombres'    => trim((string) ($r['nombres'] ?? '')),
						'ap_paterno' => trim((string) ($r['ap_paterno'] ?? '')),
						'ap_materno' => trim((string) ($r['ap_materno'] ?? '')),
						'email'      => $this->normalizeEmail($r['email'] ?? null),
						'estado'     => null,
						'created_at' => $now,
						'updated_at' => $now,
					];
				}

				if (!empty($payload)) {
					$ids = array_column($payload, 'cod_ceta');
					$existing = DB::table('estudiantes')->whereIn('cod_ceta', $ids)->pluck('cod_ceta')->all();
					$existingMap = array_fill_keys($existing, true);

					$toInsert = [];
					$toUpdate = [];
					foreach ($payload as $row) {
						if (isset($existingMap[$row['cod_ceta']])) {
							$toUpdate[] = [
								'cod_ceta'   => $row['cod_ceta'],
								'nombres'    => $row['nombres'],
								'ap_paterno' => $row['ap_paterno'],
								'ap_materno' => $row['ap_materno'],
								'email'      => $row['email'],
								'estado'     => $row['estado'],
								'updated_at' => $row['updated_at'],
							];
							$updated++;
						} else {
							$toInsert[] = $row;
							$inserted++;
						}
					}

					if (!empty($toInsert)) {
						DB::table('estudiantes')->insert($toInsert);
					}
					if (!empty($toUpdate)) {
						foreach ($toUpdate as $r) {
							DB::table('estudiantes')->where('cod_ceta', $r['cod_ceta'])->update($r);
						}
					}
				}

				$page++;

				// Si recibimos menos de perPage, es la última página
				if (count($estudiantes) < $perPage) {
					break;
				}

			} while (true);

			Log::info('SgaSyncRepositoryApi: Sincronización completada', [
				'carrera' => $carrera,
				'total' => $total,
				'inserted' => $inserted,
				'updated' => $updated
			]);

		} catch (\Throwable $e) {
			Log::error('SgaSyncRepositoryApi: Error en sincronización', [
				'carrera' => $carrera,
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString()
			]);
		}

		return compact('carrera', 'total', 'inserted', 'updated');
	}

	/**
	 * Sincroniza inscripciones usando API REST
	 *
	 * @param string $carrera 'EEA' o 'MEA'
	 * @param bool $dryRun Si true, no escribe en BD
	 * @return array {carrera, total, inserted, updated}
	 */
	public function syncInscripciones($carrera = 'EEA', $dryRun = false)
	{
		$carrera = strtoupper($carrera);
		$total = 0;
		$inserted = 0;
		$updated = 0;
		$page = 1;
		$perPage = 1000;

		try {
			Log::info('SgaSyncRepositoryApi: Iniciando sincronización de inscripciones', [
				'carrera' => $carrera,
				'dry_run' => $dryRun
			]);

			do {
				$response = $this->apiService->syncInscripciones($carrera, $page, $perPage);

				if (!$response['success'] || empty($response['data'])) {
					break;
				}

				$inscripciones = $response['data'];
				$total += count($inscripciones);

				if ($dryRun) {
					$page++;
					continue;
				}

				// Aquí procesarías las inscripciones igual que en SgaSyncRepository
				// Por ahora solo contamos

				$page++;

				if (count($inscripciones) < $perPage) {
					break;
				}

			} while (true);

		} catch (\Throwable $e) {
			Log::error('SgaSyncRepositoryApi: Error en sincronización de inscripciones', [
				'carrera' => $carrera,
				'error' => $e->getMessage()
			]);
		}

		return compact('carrera', 'total', 'inserted', 'updated');
	}

	/**
	 * Obtiene materias usando API REST
	 *
	 * @param string $codPensum Código del pensum
	 * @return \Illuminate\Support\Collection Colección de materias
	 */
	public function getMaterias($codPensum)
	{
		try {
			$carrera = $this->determinarCarreraPorPensum($codPensum);

			$response = $this->apiService->getMaterias($codPensum, $carrera);

			if ($response['success'] && $response['data']) {
				return collect($response['data']);
			}

			return collect([]);
		} catch (\Throwable $e) {
			Log::warning('SgaSyncRepositoryApi: Error al obtener materias', [
				'cod_pensum' => $codPensum,
				'error' => $e->getMessage()
			]);
			return collect([]);
		}
	}

	/**
	 * Obtiene documentos presentados usando API REST
	 *
	 * @param int $codCeta Código del estudiante
	 * @param string|null $codPensum Código del pensum
	 * @return \Illuminate\Support\Collection Colección de documentos
	 */
	public function getDocumentosPresentados($codCeta, $codPensum = null)
	{
		try {
			$carrera = $this->determinarCarrera($codCeta, $codPensum);

			$response = $this->apiService->getDocumentosPresentados($codCeta, $carrera);

			if ($response['success'] && $response['data']) {
				return collect($response['data']);
			}

			return collect([]);
		} catch (\Throwable $e) {
			Log::warning('SgaSyncRepositoryApi: Error al obtener documentos', [
				'cod_ceta' => $codCeta,
				'error' => $e->getMessage()
			]);
			return collect([]);
		}
	}

	/**
	 * Obtiene deudas usando API REST
	 *
	 * @param int $codCeta Código del estudiante
	 * @param string|null $codPensum Código del pensum
	 * @return \Illuminate\Support\Collection Colección de deudas
	 */
	public function getDeudas($codCeta, $codPensum = null)
	{
		try {
			$carrera = $this->determinarCarrera($codCeta, $codPensum);

			$response = $this->apiService->getDeudas($codCeta, $carrera);

			if ($response['success'] && $response['data']) {
				return collect($response['data']);
			}

			return collect([]);
		} catch (\Throwable $e) {
			Log::warning('SgaSyncRepositoryApi: Error al obtener deudas', [
				'cod_ceta' => $codCeta,
				'error' => $e->getMessage()
			]);
			return collect([]);
		}
	}

	/**
	 * Verifica si las APIs SGA están disponibles
	 *
	 * @return array Estado de las APIs
	 */
	public function checkApiHealth()
	{
		return [
			'EEA' => $this->apiService->isApiAvailable('EEA'),
			'MEA' => $this->apiService->isApiAvailable('MEA')
		];
	}

	// =====================================================================
	// MÉTODOS PRIVADOS HELPER
	// =====================================================================

	/**
	 * Determina la carrera según el código de estudiante y pensum
	 *
	 * @param int $codCeta Código del estudiante
	 * @param string|null $codPensum Código del pensum
	 * @return string 'EEA' o 'MEA'
	 */
	private function determinarCarrera($codCeta, $codPensum = null)
	{
		if ($codPensum) {
			return $this->determinarCarreraPorPensum($codPensum);
		}

		// Buscar inscripción más reciente
		$inscripcion = DB::table('registro_inscripcion')
			->where('cod_ceta', $codCeta)
			->orderBy('gestion', 'desc')
			->first();

		if ($inscripcion && $inscripcion->cod_pensum) {
			return $this->determinarCarreraPorPensum($inscripcion->cod_pensum);
		}

		return 'EEA'; // Default
	}

	/**
	 * Determina la carrera según el código de pensum
	 *
	 * @param string $codPensum Código del pensum
	 * @return string 'EEA' o 'MEA'
	 */
	private function determinarCarreraPorPensum($codPensum)
	{
		$pensum = Pensum::where('cod_pensum', $codPensum)->first();

		if ($pensum && $pensum->codigo_carrera) {
			$carrera = strtoupper($pensum->codigo_carrera);
			return $carrera === 'MEA' ? 'MEA' : 'EEA';
		}

		return 'EEA'; // Default
	}

	/**
	 * Normaliza email
	 *
	 * @param mixed $email Email a normalizar
	 * @return string|null Email normalizado o null
	 */
	private function normalizeEmail($email)
	{
		$email = trim((string) $email);
		if ($email === '') {
			return null;
		}
		return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
	}
}
