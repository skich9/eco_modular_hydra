<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Repositories\Sin\SyncRepository;
use App\Repositories\Sin\CuisRepository;
use App\Repositories\Sin\CufdRepository;
use App\Repositories\Sin\PuntoVentaRepository;

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
			$ambiente = (int) config('sin.ambiente');
			Log::info('SIN status: start', [ 'pv' => $pv, 'sucursal' => $sucursal, 'ambiente' => $ambiente ]);

			$cuis = $cuisRepo->getVigenteOrCreate($pv);
			$cufd = null;
			try {
				$cufd = $cufdRepo->getVigenteOrCreate2($ambiente, $sucursal, $pv);
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

	// Sincronizar puntos de venta desde SIAT
	public function syncPuntosVenta(Request $request, PuntoVentaRepository $pvRepo, CuisRepository $cuisRepo)
	{
		try {
			$codigoAmbiente = (int) $request->input('codigo_ambiente', (int) config('sin.ambiente'));
			$codigoSucursal = (int) $request->input('codigo_sucursal', (int) config('sin.sucursal'));
			$idUsuario = (int) $request->input('id_usuario', 1);
			$puntoVenta = (int) $request->input('codigo_punto_venta', 0);

			Log::info('SIN syncPuntosVenta: start', [
				'ambiente' => $codigoAmbiente,
				'sucursal' => $codigoSucursal,
				'usuario' => $idUsuario,
				'pv' => $puntoVenta
			]);

			// Obtener CUIS vigente para la consulta
			$cuisData = $cuisRepo->getVigenteOrCreate2($codigoAmbiente, $codigoSucursal, $puntoVenta);
			$cuis = $cuisData['codigo_cuis'];

			// Sincronizar puntos de venta
			$resultado = $pvRepo->sincronizarDesdeSiat($codigoAmbiente, $codigoSucursal, $cuis, $idUsuario);

			return response()->json($resultado);
		} catch (\Throwable $e) {
			Log::error('SIN syncPuntosVenta: exception', [ 'error' => $e->getMessage() ]);
			return response()->json([
				'success' => false,
				'message' => 'Error al sincronizar puntos de venta: ' . $e->getMessage(),
			], Response::HTTP_INTERNAL_SERVER_ERROR);
		}
	}

	// Listar puntos de venta locales
	public function listPuntosVenta(Request $request, PuntoVentaRepository $pvRepo)
	{
		try {
			$codigoSucursal = $request->query('codigo_sucursal');
			$codigoAmbiente = $request->query('codigo_ambiente');

			if ($codigoSucursal !== null && $codigoAmbiente !== null) {
				$puntosVenta = $pvRepo->getBySucursalAmbiente((int) $codigoSucursal, (int) $codigoAmbiente);
			} else {
				$puntosVenta = $pvRepo->getAll();
			}

			return response()->json([
				'success' => true,
				'data' => $puntosVenta,
				'message' => 'Puntos de venta obtenidos'
			]);
		} catch (\Throwable $e) {
			Log::error('SIN listPuntosVenta: exception', [ 'error' => $e->getMessage() ]);
			return response()->json([
				'success' => false,
				'message' => 'Error al listar puntos de venta: ' . $e->getMessage(),
			], Response::HTTP_INTERNAL_SERVER_ERROR);
		}
	}

	// Obtener tipos de punto de venta
	public function getTiposPuntoVenta(Request $request)
	{
		try {
			$tipos = DB::table('sin_datos_sincronizacion')
				->where('tipo', 'sincronizarParametricaTipoPuntoVenta')
				->whereIn('codigo_clasificador', [1, 2, 3, 4, 5, 6])
				->orderBy('codigo_clasificador')
				->get(['codigo_clasificador', 'descripcion']);

			return response()->json([
				'success' => true,
				'data' => $tipos,
				'message' => 'Tipos de punto de venta obtenidos'
			]);
		} catch (\Throwable $e) {
			Log::error('SIN getTiposPuntoVenta: exception', [ 'error' => $e->getMessage() ]);
			return response()->json([
				'success' => false,
				'message' => 'Error al obtener tipos de punto de venta: ' . $e->getMessage(),
			], Response::HTTP_INTERNAL_SERVER_ERROR);
		}
	}

	// Crear nuevo punto de venta en SIAT
	public function createPuntoVenta(Request $request, PuntoVentaRepository $pvRepo, CuisRepository $cuisRepo)
	{
		try {
			$codigoAmbiente = (int) $request->input('codigo_ambiente', (int) config('sin.ambiente'));
			$codigoSucursal = (int) $request->input('codigo_sucursal', (int) config('sin.sucursal'));
			$codigoTipoPuntoVenta = (int) $request->input('codigo_tipo_punto_venta');
			$nombrePuntoVenta = (string) $request->input('nombre_punto_venta');
			$descripcion = (string) $request->input('descripcion', '');
			$idUsuario = (int) $request->input('id_usuario', 1);
			$puntoVenta = (int) $request->input('codigo_punto_venta', 0);

			// Validar campos requeridos
			if (empty($nombrePuntoVenta)) {
				return response()->json([
					'success' => false,
					'message' => 'El nombre del punto de venta es requerido'
				], Response::HTTP_BAD_REQUEST);
			}

			if (empty($codigoTipoPuntoVenta)) {
				return response()->json([
					'success' => false,
					'message' => 'El tipo de punto de venta es requerido'
				], Response::HTTP_BAD_REQUEST);
			}

			Log::info('SIN createPuntoVenta: start', [
				'ambiente' => $codigoAmbiente,
				'sucursal' => $codigoSucursal,
				'tipo' => $codigoTipoPuntoVenta,
				'nombre' => $nombrePuntoVenta,
				'usuario' => $idUsuario
			]);

			// Obtener CUIS vigente para el registro
			$cuisData = $cuisRepo->getVigenteOrCreate2($codigoAmbiente, $codigoSucursal, $puntoVenta);
			$cuis = $cuisData['codigo_cuis'];

			// Registrar punto de venta en SIAT y guardar localmente
			$resultado = $pvRepo->registrarEnSiat(
				$codigoAmbiente,
				$codigoSucursal,
				$cuis,
				$codigoTipoPuntoVenta,
				$nombrePuntoVenta,
				$descripcion,
				$idUsuario
			);

			return response()->json($resultado);
		} catch (\Throwable $e) {
			Log::error('SIN createPuntoVenta: exception', [ 'error' => $e->getMessage() ]);
			return response()->json([
				'success' => false,
				'message' => 'Error al crear punto de venta: ' . $e->getMessage(),
			], Response::HTTP_INTERNAL_SERVER_ERROR);
		}
	}

	// Eliminar (cerrar) punto de venta en SIAT
	public function deletePuntoVenta(Request $request, $id, PuntoVentaRepository $pvRepo, CuisRepository $cuisRepo)
	{
		try {
			// Buscar el punto de venta en la base de datos
			$puntoVenta = DB::table('sin_punto_venta')
				->where('codigo_punto_venta', $id)
				->first();

			if (!$puntoVenta) {
				return response()->json([
					'success' => false,
					'message' => 'Punto de venta no encontrado'
				], Response::HTTP_NOT_FOUND);
			}

			$codigoAmbiente = (int) $puntoVenta->codigo_ambiente;
			$codigoSucursal = (int) $puntoVenta->sucursal;
			$codigoPuntoVenta = (int) $puntoVenta->codigo_punto_venta;

			Log::info('SIN deletePuntoVenta: start', [
				'ambiente' => $codigoAmbiente,
				'sucursal' => $codigoSucursal,
				'puntoVenta' => $codigoPuntoVenta
			]);

			// Obtener CUIS vigente del punto de venta 0 (punto de venta activo para operaciones)
			// No se puede usar el CUIS del punto de venta que se va a cerrar
			$cuisData = $cuisRepo->getVigenteOrCreate2($codigoAmbiente, $codigoSucursal, 0);
			$cuis = $cuisData['codigo_cuis'];

			// Cerrar el punto de venta en SIAT
			$resultado = $pvRepo->cerrarPuntoVenta($codigoAmbiente, $codigoPuntoVenta, $codigoSucursal, $cuis);

			if ($resultado['success']) {
				return response()->json($resultado);
			} else {
				return response()->json($resultado, Response::HTTP_BAD_REQUEST);
			}

		} catch (\Throwable $e) {
			Log::error('SIN deletePuntoVenta: exception', [ 'error' => $e->getMessage() ]);
			return response()->json([
				'success' => false,
				'message' => 'Error al eliminar punto de venta: ' . $e->getMessage(),
			], Response::HTTP_INTERNAL_SERVER_ERROR);
		}
	}
}
