<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Services\ContingenciaService;

class ContingenciaController extends Controller
{
	private $contingenciaService;

	public function __construct()
	{
		$this->contingenciaService = new ContingenciaService();
	}

	/**
	 * Lista facturas en contingencia pendientes de regularizar
	 * 
	 * GET /api/contingencias
	 */
	public function lista(Request $request)
	{
		try {
			$sucursal = $request->query('sucursal');
			$puntoVenta = $request->query('punto_venta');

			$facturas = $this->contingenciaService->listarContingencias($sucursal, $puntoVenta);

			return response()->json([
				'success' => true,
				'facturas' => $facturas,
				'total' => count($facturas)
			]);
		} catch (\Throwable $e) {
			Log::error('ContingenciaController.lista', [
				'error' => $e->getMessage()
			]);

			return response()->json([
				'success' => false,
				'message' => $e->getMessage()
			], 500);
		}
	}

	/**
	 * Regulariza un grupo de facturas en contingencia
	 * 
	 * POST /api/contingencias/regularizar
	 * Body: { "facturas": [{"nro_factura": 123, "anio": 2025}, ...] }
	 */
	public function regularizar(Request $request)
	{
		try {
			$facturas = $request->input('facturas', []);

			if (empty($facturas)) {
				return response()->json([
					'success' => false,
					'message' => 'No se proporcionaron facturas para regularizar'
				], 400);
			}

			Log::info('ContingenciaController.regularizar', [
				'cantidad' => count($facturas)
			]);

			// Agrupar facturas por paquete (CUFD + Evento)
			$paquetes = $this->contingenciaService->agruparFacturasPorPaquete($facturas);

			$resultados = [];
			foreach ($paquetes as $clave => $paquete) {
				Log::info('ContingenciaController.regularizarPaquete', [
					'clave' => $clave,
					'cantidad_facturas' => count($paquete['facturas']),
					'cufd' => $paquete['cufd'],
					'codigo_evento' => $paquete['codigo_evento']
				]);

				$resultado = $this->contingenciaService->regularizarPaquete($paquete);
				$resultados[$clave] = $resultado;
			}

			// Contar éxitos y errores
			$exitosos = 0;
			$errores = 0;
			foreach ($resultados as $resultado) {
				if (isset($resultado['success']) && $resultado['success']) {
					$exitosos++;
				} else {
					$errores++;
				}
			}

			return response()->json([
				'success' => true,
				'paquetes_procesados' => count($resultados),
				'paquetes_exitosos' => $exitosos,
				'paquetes_con_error' => $errores,
				'resultados' => $resultados
			]);

		} catch (\Throwable $e) {
			Log::error('ContingenciaController.regularizar', [
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString()
			]);

			return response()->json([
				'success' => false,
				'message' => $e->getMessage()
			], 500);
		}
	}

	/**
	 * Obtiene estadísticas de contingencias
	 * 
	 * GET /api/contingencias/estadisticas
	 */
	public function estadisticas()
	{
		try {
			$facturas = $this->contingenciaService->listarContingencias();

			$total = count($facturas);
			$fueraDePlazo = 0;
			$porVencer = 0; // Menos de 12 horas restantes
			$vigentes = 0;

			foreach ($facturas as $factura) {
				if ($factura['fuera_de_plazo']) {
					$fueraDePlazo++;
				} elseif ($factura['horas_restantes'] < 12) {
					$porVencer++;
				} else {
					$vigentes++;
				}
			}

			return response()->json([
				'success' => true,
				'estadisticas' => [
					'total' => $total,
					'fuera_de_plazo' => $fueraDePlazo,
					'por_vencer' => $porVencer,
					'vigentes' => $vigentes
				]
			]);

		} catch (\Throwable $e) {
			Log::error('ContingenciaController.estadisticas', [
				'error' => $e->getMessage()
			]);

			return response()->json([
				'success' => false,
				'message' => $e->getMessage()
			], 500);
		}
	}
}
