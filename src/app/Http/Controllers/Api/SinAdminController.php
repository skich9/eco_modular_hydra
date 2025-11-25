<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use App\Repositories\Sin\SyncRepository;
use App\Repositories\Sin\CuisRepository;
use App\Repositories\Sin\CufdRepository;

class SinAdminController extends Controller
{
	// S1: Sincronizar todas las paramétricas estándar
	public function syncAll(Request $request, SyncRepository $sync)
	{
		try {
			$pv = (int) $request->input('codigo_punto_venta', 0);
			Log::info('SIN syncAll: start', [ 'pv' => $pv ]);
			$summary = $sync->syncAllParametricas($pv);
			return response()->json([
				'success' => true,
				'data' => $summary,
				'message' => 'Sincronización de paramétricas completada'
			]);
		} catch (\Throwable $e) {
			Log::error('SIN syncAll: exception', [ 'error' => $e->getMessage() ]);
			return response()->json([
				'success' => false,
				'message' => 'Error al sincronizar paramétricas: ' . $e->getMessage(),
			], Response::HTTP_INTERNAL_SERVER_ERROR);
		}
	}

	// S1: Sincronizar leyendas de factura
	public function syncLeyendas(Request $request, SyncRepository $sync)
	{
		try {
			$pv = (int) $request->input('codigo_punto_venta', 0);
			Log::info('SIN syncLeyendas: start', [ 'pv' => $pv ]);
			$res = $sync->syncLeyendasFactura($pv);
			return response()->json([
				'success' => true,
				'data' => $res,
				'message' => 'Sincronización de leyendas completada'
			]);
		} catch (\Throwable $e) {
			Log::error('SIN syncLeyendas: exception', [ 'error' => $e->getMessage() ]);
			return response()->json([
				'success' => false,
				'message' => 'Error al sincronizar leyendas: ' . $e->getMessage(),
			], Response::HTTP_INTERNAL_SERVER_ERROR);
		}
	}

	// S1: Sincronizar mapeo de métodos de pago SIN -> sin_forma_cobro
	public function syncMetodoPago(Request $request, SyncRepository $sync)
	{
		try {
			$pv = (int) $request->input('codigo_punto_venta', 0);
			Log::info('SIN syncMetodoPago: start', [ 'pv' => $pv ]);
			$res = $sync->syncTipoMetodoPago($pv);
			return response()->json([
				'success' => true,
				'data' => $res,
				'message' => 'Sincronización de métodos de pago completada'
			]);
		} catch (\Throwable $e) {
			Log::error('SIN syncMetodoPago: exception', [ 'error' => $e->getMessage() ]);
			return response()->json([
				'success' => false,
				'message' => 'Error al sincronizar métodos de pago: ' . $e->getMessage(),
			], Response::HTTP_INTERNAL_SERVER_ERROR);
		}
	}

	// S2: Estado de CUIS/CUFD vigentes
	public function status(Request $request, CuisRepository $cuisRepo, CufdRepository $cufdRepo)
	{
		try {
			$pv = (int) $request->query('codigo_punto_venta', 0);
			$sucursal = (int) $request->query('codigo_sucursal', (int) config('sin.sucursal'));
			Log::info('SIN status: start', [ 'pv' => $pv, 'sucursal' => $sucursal ]);

			$cuis = $cuisRepo->getVigenteOrCreate($pv);
			$cufd = null;
			try {
				$cufd = $cufdRepo->getVigenteOrCreate($pv);
			} catch (\Throwable $e) {
				Log::warning('SIN status: CUFD lookup failed', [ 'pv' => $pv, 'error' => $e->getMessage() ]);
			}

			return response()->json([
				'success' => true,
				'data' => [
					'cuis' => $cuis,
					'cufd' => $cufd,
					'codigo_sucursal' => $sucursal,
					'codigo_punto_venta' => $pv,
				],
				'message' => 'Estado SIAT obtenido'
			]);
		} catch (\Throwable $e) {
			Log::error('SIN status: exception', [ 'error' => $e->getMessage() ]);
			return response()->json([
				'success' => false,
				'message' => 'Error al obtener estado SIAT: ' . $e->getMessage(),
			], Response::HTTP_INTERNAL_SERVER_ERROR);
		}
	}

	// Exponer URL base del QR del SIN desde backend (.env)
	public function qrUrl(Request $request)
	{
		try {
			$url = (string) config('sin.qr_url');
			return response()->json([
				'success' => true,
				'data' => [ 'url' => $url ],
			]);
		} catch (\Throwable $e) {
			return response()->json([
				'success' => false,
				'message' => $e->getMessage(),
			], 500);
		}
	}
}
