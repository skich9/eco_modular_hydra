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
				// Desactivar asignaciones de usuario para este punto de venta
				try {
					DB::table('sin_punto_venta_usuario')
						->where('codigo_punto_venta', $codigoPuntoVenta)
						->where('codigo_sucursal', $codigoSucursal)
						->where('codigo_ambiente', $codigoAmbiente)
						->update([
							'activo' => 0,
							'updated_at' => now()
						]);
					Log::info('SIN deletePuntoVenta: asignaciones desactivadas para punto de venta cerrado', [
						'codigo_punto_venta' => $codigoPuntoVenta,
						'codigo_sucursal' => $codigoSucursal,
						'codigo_ambiente' => $codigoAmbiente
					]);
				} catch (\Throwable $e) {
					Log::error('SIN deletePuntoVenta: error al desactivar asignaciones', [ 'error' => $e->getMessage() ]);
				}

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

	// Listar usuarios para asignar a punto de venta
	public function listUsuarios(Request $request)
	{
		try {
			$usuarios = DB::table('usuarios')
				->select('id_usuario', 'nombre', 'ap_materno', 'nickname')
				->where('estado', 1)
				->orderBy('nickname')
				->orderBy('nombre')
				->get();

			return response()->json([
				'success' => true,
				'data' => $usuarios
			]);
		} catch (\Throwable $e) {
			Log::error('SIN listUsuarios: exception', [ 'error' => $e->getMessage() ]);
			return response()->json([
				'success' => false,
				'message' => 'Error al listar usuarios: ' . $e->getMessage(),
			], Response::HTTP_INTERNAL_SERVER_ERROR);
		}
	}

	// Asignar usuario a punto de venta
	public function assignUserToPuntoVenta(Request $request)
	{
		try {
			$idUsuario = (int) $request->input('id_usuario');
			$codigoPuntoVenta = $request->input('codigo_punto_venta');
			$codigoSucursal = (int) $request->input('codigo_sucursal');
			$codigoAmbiente = (int) config('sin.ambiente');
			$vencimientoAsig = $request->input('vencimiento_asig');
			// Determinar el usuario que crea la asignación:
			// 1) Priorizar el valor enviado en el request (usuario_crea)
			// 2) Si no viene o es 0, intentar usar el usuario autenticado
			// 3) Como último recurso, usar 1 para no romper compatibilidad
			$usuarioCrea = (int) $request->input('usuario_crea');
			if ($usuarioCrea <= 0 && auth()->check()) {
				$usuarioCrea = (int) auth()->id();
			}
			if ($usuarioCrea <= 0) {
				$usuarioCrea = 1;
			}

			// Validar campos requeridos
			if (empty($idUsuario)) {
				return response()->json([
					'success' => false,
					'message' => 'El usuario es requerido'
				], Response::HTTP_BAD_REQUEST);
			}

			if (empty($codigoPuntoVenta)) {
				return response()->json([
					'success' => false,
					'message' => 'El código de punto de venta es requerido'
				], Response::HTTP_BAD_REQUEST);
			}

			if (empty($vencimientoAsig)) {
				return response()->json([
					'success' => false,
					'message' => 'La fecha de vencimiento es requerida'
				], Response::HTTP_BAD_REQUEST);
			}

			Log::info('SIN assignUserToPuntoVenta: start', [
				'usuario' => $idUsuario,
				'puntoVenta' => $codigoPuntoVenta,
				'sucursal' => $codigoSucursal,
				'ambiente' => $codigoAmbiente
			]);

			// Verificar si ya existe la asignación
			$existente = DB::table('sin_punto_venta_usuario')
				->where('id_usuario', $idUsuario)
				->where('codigo_punto_venta', $codigoPuntoVenta)
				->where('codigo_sucursal', $codigoSucursal)
				->where('codigo_ambiente', $codigoAmbiente)
				->where('activo', 1)
				->first();

			if ($existente) {
				return response()->json([
					'success' => false,
					'message' => 'El usuario ya está asignado a este punto de venta'
				], Response::HTTP_BAD_REQUEST);
			}

			// Insertar la asignación
			DB::table('sin_punto_venta_usuario')->insert([
				'id_usuario' => $idUsuario,
				'codigo_punto_venta' => $codigoPuntoVenta,
				'codigo_sucursal' => $codigoSucursal,
				'codigo_ambiente' => $codigoAmbiente,
				'vencimiento_asig' => $vencimientoAsig,
				'activo' => 1,
				'usuario_crea' => $usuarioCrea,
				'created_at' => now(),
				'updated_at' => now()
			]);

			Log::info('SIN assignUserToPuntoVenta: success');

			return response()->json([
				'success' => true,
				'message' => 'Usuario asignado exitosamente al punto de venta'
			]);

		} catch (\Throwable $e) {
			Log::error('SIN assignUserToPuntoVenta: exception', [ 'error' => $e->getMessage() ]);
			return response()->json([
				'success' => false,
				'message' => 'Error al asignar usuario: ' . $e->getMessage(),
			], Response::HTTP_INTERNAL_SERVER_ERROR);
		}
	}

	// Obtener asignación actual de un punto de venta
	public function getAsignacionPuntoVenta(Request $request, $codigoPuntoVenta)
	{
		try {
			$codigoAmbiente = (int) config('sin.ambiente');

			// Buscar el punto de venta para obtener la sucursal
			$puntoVenta = DB::table('sin_punto_venta')
				->where('codigo_punto_venta', $codigoPuntoVenta)
				->where('codigo_ambiente', $codigoAmbiente)
				->first();

			if (!$puntoVenta) {
				return response()->json([
					'success' => false,
					'message' => 'Punto de venta no encontrado'
				], Response::HTTP_NOT_FOUND);
			}

			// Buscar asignación activa y vigente (activo = 1 y sin vencer)
			$asignacion = DB::table('sin_punto_venta_usuario as pvu')
				->join('usuarios as u', 'pvu.id_usuario', '=', 'u.id_usuario')
				->where('pvu.codigo_punto_venta', $codigoPuntoVenta)
				->where('pvu.codigo_sucursal', $puntoVenta->sucursal)
				->where('pvu.codigo_ambiente', $codigoAmbiente)
				->where('pvu.activo', 1)
				->where(function ($q) {
					$q->whereNull('pvu.vencimiento_asig')
						->orWhere('pvu.vencimiento_asig', '>=', now());
				})
				->select(
					'pvu.id',
					'pvu.id_usuario',
					'pvu.codigo_punto_venta',
					'pvu.codigo_sucursal',
					'pvu.codigo_ambiente',
					'pvu.vencimiento_asig',
					'pvu.activo',
					'u.nickname',
					'u.nombre',
					'u.ap_materno'
				)
				->orderByDesc('pvu.vencimiento_asig')
				->orderByDesc('pvu.created_at')
				->first();

			if (!$asignacion) {
				return response()->json([
					'success' => true,
					'data' => null,
					'message' => 'No hay asignación para este punto de venta'
				]);
			}

			return response()->json([
				'success' => true,
				'data' => $asignacion
			]);

		} catch (\Throwable $e) {
			Log::error('SIN getAsignacionPuntoVenta: exception', [ 'error' => $e->getMessage() ]);
			return response()->json([
				'success' => false,
				'message' => 'Error al obtener asignación: ' . $e->getMessage(),
			], Response::HTTP_INTERNAL_SERVER_ERROR);
		}
	}

	// Actualizar asignación de usuario a punto de venta
	public function updateAsignacionPuntoVenta(Request $request, $id)
	{
		try {
			$vencimientoAsig = $request->input('vencimiento_asig');
			$activo = $request->input('activo', 1);

			if (empty($vencimientoAsig)) {
				return response()->json([
					'success' => false,
					'message' => 'La fecha de vencimiento es requerida'
				], Response::HTTP_BAD_REQUEST);
			}

			Log::info('SIN updateAsignacionPuntoVenta: start', [
				'id' => $id,
				'vencimiento' => $vencimientoAsig,
				'activo' => $activo
			]);

			// Verificar que existe la asignación
			$asignacion = DB::table('sin_punto_venta_usuario')->where('id', $id)->first();

			if (!$asignacion) {
				return response()->json([
					'success' => false,
					'message' => 'Asignación no encontrada'
				], Response::HTTP_NOT_FOUND);
			}

			// Actualizar la asignación
			DB::table('sin_punto_venta_usuario')
				->where('id', $id)
				->update([
					'vencimiento_asig' => $vencimientoAsig,
					'activo' => $activo ? 1 : 0,
					'updated_at' => now()
				]);

			Log::info('SIN updateAsignacionPuntoVenta: success');

			return response()->json([
				'success' => true,
				'message' => 'Asignación actualizada exitosamente'
			]);

		} catch (\Throwable $e) {
			Log::error('SIN updateAsignacionPuntoVenta: exception', [ 'error' => $e->getMessage() ]);
			return response()->json([
				'success' => false,
				'message' => 'Error al actualizar asignación: ' . $e->getMessage(),
			], Response::HTTP_INTERNAL_SERVER_ERROR);
		}
	}
}
