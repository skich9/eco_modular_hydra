<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * Servicio para interactuar con las APIs REST de los sistemas SGA
 * (Electrónica y Mecánica) de forma segura y centralizada.
 *
 * Este servicio es una alternativa a las conexiones directas a base de datos
 * y está diseñado para ser usado en producción cuando el sistema esté en internet.
 */
class SgaApiService
{
	/**
	 * Timeout para las peticiones HTTP (en segundos)
	 */
	const TIMEOUT = 10;

	/**
	 * Tiempo de cache para consultas (en minutos)
	 */
	const CACHE_TTL = 5;

	/**
	 * Obtiene la URL base de la API según la carrera
	 *
	 * @param string $carrera 'EEA' o 'MEA'
	 * @return string URL base de la API
	 */
	private function getApiUrl($carrera)
	{
		$carrera = strtoupper($carrera);

		if ($carrera === 'MEA') {
			return env('SGA_API_MECANICA_URL', env('SGA_API_BASE_URL'));
		}

		return env('SGA_API_BASE_URL', env('SGA_BASE_URL'));
	}

	/**
	 * Obtiene el token de autenticación para la API
	 *
	 * @param string $carrera 'EEA' o 'MEA'
	 * @return string|null Token de autenticación
	 */
	private function getApiToken($carrera)
	{
		$carrera = strtoupper($carrera);

		if ($carrera === 'MEA') {
			return env('SGA_API_MECANICA_TOKEN', env('SGA_API_TOKEN'));
		}

		return env('SGA_API_TOKEN');
	}

	/**
	 * Realiza una petición HTTP a la API SGA
	 *
	 * @param string $carrera 'EEA' o 'MEA'
	 * @param string $endpoint Endpoint de la API (ej: '/api/v1/estudiantes/123')
	 * @param string $method Método HTTP (GET, POST, etc.)
	 * @param array $data Datos a enviar (para POST, PUT, etc.)
	 * @param bool $useCache Si se debe usar cache para esta petición
	 * @return array Respuesta de la API
	 */
	private function request($carrera, $endpoint, $method = 'GET', $data = [], $useCache = true)
	{
		$baseUrl = $this->getApiUrl($carrera);
		$token = $this->getApiToken($carrera);
		$url = rtrim($baseUrl, '/') . '/' . ltrim($endpoint, '/');

		// Cache key para peticiones GET
		$cacheKey = null;
		if ($method === 'GET' && $useCache) {
			$cacheKey = 'sga_api:' . $carrera . ':' . md5($url . json_encode($data));
			$cached = Cache::get($cacheKey);
			if ($cached !== null) {
				Log::info('SgaApiService: Cache hit', ['key' => $cacheKey]);
				return $cached;
			}
		}

		try {
			Log::info('SgaApiService: Request', [
				'carrera' => $carrera,
				'method' => $method,
				'url' => $url,
				'data' => $data
			]);

			$request = Http::timeout(self::TIMEOUT);

			// Agregar token de autenticación si existe
			if ($token) {
				$request = $request->withToken($token);
			}

			// Agregar headers adicionales
			$request = $request->withHeaders([
				'Accept' => 'application/json',
				'X-Requested-From' => 'Hydra-System',
			]);

			// Realizar la petición según el método
			$methodUpper = strtoupper($method);
			switch ($methodUpper) {
				case 'GET':
					$response = $request->get($url, $data);
					break;
				case 'POST':
					$response = $request->post($url, $data);
					break;
				case 'PUT':
					$response = $request->put($url, $data);
					break;
				case 'DELETE':
					$response = $request->delete($url, $data);
					break;
				default:
					throw new \Exception("Método HTTP no soportado: {$method}");
			}

			if ($response->successful()) {
				$result = [
					'success' => true,
					'data' => $response->json('data', $response->json()),
					'message' => $response->json('message'),
					'status' => $response->status()
				];

				// Guardar en cache si es GET
				if ($method === 'GET' && $useCache && $cacheKey) {
					Cache::put($cacheKey, $result, now()->addMinutes(self::CACHE_TTL));
				}

				return $result;
			}

			Log::warning('SgaApiService: Request failed', [
				'url' => $url,
				'status' => $response->status(),
				'body' => $response->body()
			]);

			return [
				'success' => false,
				'data' => null,
				'message' => $response->json('message', 'Error en la petición a la API SGA'),
				'status' => $response->status()
			];

		} catch (\Throwable $e) {
			Log::error('SgaApiService: Exception', [
				'url' => $url,
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString()
			]);

			return [
				'success' => false,
				'data' => null,
				'message' => 'Error al conectar con la API SGA: ' . $e->getMessage(),
				'status' => 500
			];
		}
	}

	/**
	 * Obtiene datos de un estudiante por su código CETA
	 *
	 * @param int $codCeta Código del estudiante
	 * @param string $carrera 'EEA' o 'MEA'
	 * @return array Respuesta con datos del estudiante
	 */
	public function getEstudiante($codCeta, $carrera = 'EEA')
	{
		return $this->request($carrera, "/api/v1/estudiantes/{$codCeta}", 'GET');
	}

	/**
	 * Obtiene inscripciones de un estudiante
	 *
	 * @param int $codCeta Código del estudiante
	 * @param string $carrera 'EEA' o 'MEA'
	 * @param array $filters Filtros adicionales (gestion, cod_pensum, etc.)
	 * @return array Respuesta con inscripciones
	 */
	public function getInscripciones($codCeta, $carrera = 'EEA', $filters = [])
	{
		$params = array_merge(['cod_ceta' => $codCeta], $filters);
		return $this->request($carrera, "/api/v1/inscripciones", 'GET', $params);
	}

	/**
	 * Obtiene materias de un pensum
	 *
	 * @param string $codPensum Código del pensum
	 * @param string $carrera 'EEA' o 'MEA'
	 * @return array Respuesta con materias
	 */
	public function getMaterias($codPensum, $carrera = 'EEA')
	{
		return $this->request($carrera, "/api/v1/materias", 'GET', ['cod_pensum' => $codPensum]);
	}

	/**
	 * Obtiene documentos presentados por un estudiante
	 *
	 * @param int $codCeta Código del estudiante
	 * @param string $carrera 'EEA' o 'MEA'
	 * @return array Respuesta con documentos
	 */
	public function getDocumentosPresentados($codCeta, $carrera = 'EEA')
	{
		return $this->request($carrera, "/api/v1/documentos-presentados", 'GET', ['cod_ceta' => $codCeta]);
	}

	/**
	 * Obtiene deudas de un estudiante
	 *
	 * @param int $codCeta Código del estudiante
	 * @param string $carrera 'EEA' o 'MEA'
	 * @return array Respuesta con deudas
	 */
	public function getDeudas($codCeta, $carrera = 'EEA')
	{
		return $this->request($carrera, "/api/v1/deudas", 'GET', ['cod_ceta' => $codCeta]);
	}

	/**
	 * Sincroniza estudiantes (para sincronización masiva)
	 *
	 * @param string $carrera 'EEA' o 'MEA'
	 * @param int $page Página de resultados
	 * @param int $perPage Resultados por página
	 * @return array Respuesta con estudiantes
	 */
	public function syncEstudiantes($carrera = 'EEA', $page = 1, $perPage = 1000)
	{
		return $this->request($carrera, "/api/v1/sync/estudiantes", 'GET', [
			'page' => $page,
			'per_page' => $perPage
		], false); // No usar cache para sincronización
	}

	/**
	 * Sincroniza inscripciones (para sincronización masiva)
	 *
	 * @param string $carrera 'EEA' o 'MEA'
	 * @param int $page Página de resultados
	 * @param int $perPage Resultados por página
	 * @return array Respuesta con inscripciones
	 */
	public function syncInscripciones($carrera = 'EEA', $page = 1, $perPage = 1000)
	{
		return $this->request($carrera, "/api/v1/sync/inscripciones", 'GET', [
			'page' => $page,
			'per_page' => $perPage
		], false); // No usar cache para sincronización
	}

	/**
	 * Limpia el cache de la API para una carrera específica
	 *
	 * @param string|null $carrera 'EEA', 'MEA' o null para limpiar todo
	 * @return void
	 */
	public function clearCache($carrera = null)
	{
		if ($carrera) {
			$pattern = 'sga_api:' . strtoupper($carrera) . ':*';
			Log::info('SgaApiService: Clearing cache', ['pattern' => $pattern]);
			// Laravel no tiene un método nativo para borrar por patrón en todos los drivers
			// Esta es una implementación básica
			Cache::flush(); // En producción, implementar borrado por patrón
		} else {
			Cache::flush();
		}
	}

	/**
	 * Verifica si la API está disponible
	 *
	 * @param string $carrera 'EEA' o 'MEA'
	 * @return bool True si la API está disponible
	 */
	public function isApiAvailable($carrera = 'EEA')
	{
		try {
			$response = $this->request($carrera, "/api/v1/health", 'GET', [], false);
			return $response['success'] === true;
		} catch (\Throwable $e) {
			return false;
		}
	}
}
