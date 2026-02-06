<?php

namespace App\Repositories\Sin;

use App\Services\Siat\OperationsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Exception;

class PuntoVentaRepository
{
	private $operationsService;

	public function __construct(OperationsService $operationsService)
	{
		$this->operationsService = $operationsService;
	}

	/**
	 * Registra un nuevo punto de venta en SIAT y lo guarda localmente
	 *
	 * @param int $codigoAmbiente Ambiente (1=Produccion, 2=Pruebas)
	 * @param int $codigoSucursal Codigo de sucursal
	 * @param string $cuis CUIS vigente
	 * @param int $codigoTipoPuntoVenta Tipo de punto de venta
	 * @param string $nombrePuntoVenta Nombre del punto de venta
	 * @param string $descripcion Descripcion del punto de venta
	 * @param int $idUsuario ID del usuario que crea el punto de venta
	 * @return array Resultado del registro
	 */
	public function registrarEnSiat(int $codigoAmbiente, int $codigoSucursal, string $cuis, int $codigoTipoPuntoVenta, string $nombrePuntoVenta, string $descripcion, int $idUsuario): array
	{
		Log::info('PuntoVentaRepository.registrarEnSiat: iniciando', [
			'ambiente' => $codigoAmbiente,
			'sucursal' => $codigoSucursal,
			'tipo' => $codigoTipoPuntoVenta,
			'nombre' => $nombrePuntoVenta,
			'usuario' => $idUsuario
		]);

		try {
			// Registrar punto de venta en SIAT
			$response = $this->operationsService->registroPuntoVenta(
				$codigoAmbiente,
				$codigoSucursal,
				$cuis,
				$codigoTipoPuntoVenta,
				$nombrePuntoVenta,
				$descripcion
			);

			if (!isset($response['RespuestaRegistroPuntoVenta'])) {
				throw new Exception('Respuesta invalida de SIAT: ' . json_encode($response));
			}

			$respuesta = $response['RespuestaRegistroPuntoVenta'];

			// Verificar si hay error en la respuesta
			if (isset($respuesta['transaccion']) && !$respuesta['transaccion']) {
				$mensajes = $respuesta['mensajesList'] ?? [];
				$mensaje = is_array($mensajes) && isset($mensajes['descripcion'])
					? $mensajes['descripcion']
					: 'Error desconocido al registrar punto de venta';
				throw new Exception('Error SIAT: ' . $mensaje);
			}

			// Obtener el codigo del punto de venta creado
			$codigoPuntoVenta = $respuesta['codigoPuntoVenta'] ?? null;

			if (!$codigoPuntoVenta) {
				throw new Exception('SIAT no retorno codigo de punto de venta');
			}

			// Guardar en la base de datos local
			$data = [
				'codigo_punto_venta' => $codigoPuntoVenta,
				'nombre' => $nombrePuntoVenta,
				'descripcion' => $descripcion,
				'sucursal' => $codigoSucursal,
				'codigo_cuis_genera' => $cuis,
				'id_usuario_crea' => (string) $idUsuario,
				'tipo' => $codigoTipoPuntoVenta,
				'ip' => '',
				'activo' => true,
				'fecha_creacion' => Carbon::now('America/La_Paz'),
				'crear_cufd' => true,
				'autocrear_cufd' => true,
				'codigo_ambiente' => $codigoAmbiente
			];

			DB::table('sin_punto_venta')->insert($data);

			Log::info('PuntoVentaRepository.registrarEnSiat: completado', [
				'codigo' => $codigoPuntoVenta
			]);

			return [
				'success' => true,
				'codigo_punto_venta' => $codigoPuntoVenta,
				'message' => 'Punto de venta registrado exitosamente'
			];

		} catch (Exception $e) {
			Log::error('PuntoVentaRepository.registrarEnSiat: error', [
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString()
			]);

			return [
				'success' => false,
				'message' => 'Error al registrar punto de venta: ' . $e->getMessage()
			];
		}
	}

	/**
	 * Sincroniza puntos de venta desde SIAT a la base de datos local
	 *
	 * @param int $codigoAmbiente Ambiente (1=Produccion, 2=Pruebas)
	 * @param int $codigoSucursal Codigo de sucursal
	 * @param string $cuis CUIS vigente para la consulta
	 * @param int $idUsuario ID del usuario que realiza la sincronizacion
	 * @return array Resultado de la sincronizacion
	 */
	public function sincronizarDesdeSiat(int $codigoAmbiente, int $codigoSucursal, string $cuis, int $idUsuario): array
	{
		Log::info('PuntoVentaRepository.sincronizarDesdeSiat: iniciando', [
			'ambiente' => $codigoAmbiente,
			'sucursal' => $codigoSucursal,
			'cuis' => $cuis,
			'usuario' => $idUsuario
		]);

		try {
			// Consultar puntos de venta desde SIAT
			$response = $this->operationsService->consultaPuntoVenta($codigoAmbiente, $codigoSucursal, $cuis);

			if (!isset($response['RespuestaConsultaPuntoVenta'])) {
				throw new Exception('Respuesta invalida de SIAT: ' . json_encode($response));
			}

			$respuesta = $response['RespuestaConsultaPuntoVenta'];

			// Verificar si hay error en la respuesta
			if (isset($respuesta['transaccion']) && !$respuesta['transaccion']) {
				$mensaje = $respuesta['mensajesList']['descripcion'] ?? 'Error desconocido';
				throw new Exception('Error SIAT: ' . $mensaje);
			}

			// Obtener lista de puntos de venta
			$listaPuntosVenta = $respuesta['listaPuntosVentas'] ?? [];

			if (empty($listaPuntosVenta)) {
				Log::warning('PuntoVentaRepository.sincronizarDesdeSiat: no hay puntos de venta');
				return [
					'success' => true,
					'sincronizados' => 0,
					'actualizados' => 0,
					'nuevos' => 0,
					'message' => 'No hay puntos de venta registrados en SIAT'
				];
			}

			$sincronizados = 0;
			$actualizados = 0;
			$nuevos = 0;

			foreach ($listaPuntosVenta as $pvSiat) {
				$codigoPuntoVenta = $pvSiat['codigoPuntoVenta'] ?? null;
				$nombrePuntoVenta = $pvSiat['nombrePuntoVenta'] ?? null;
				$tipoPuntoVentaTexto = $pvSiat['tipoPuntoVenta'] ?? null;

				if (!$codigoPuntoVenta || !$nombrePuntoVenta) {
					Log::warning('PuntoVentaRepository.sincronizarDesdeSiat: punto de venta incompleto', [
						'data' => $pvSiat
					]);
					continue;
				}

				// Buscar el codigo de tipo punto de venta en sin_datos_sincronizacion
				$tipoCodigoClasificador = $this->obtenerCodigoTipoPuntoVenta($tipoPuntoVentaTexto);

				if (!$tipoCodigoClasificador) {
					Log::warning('PuntoVentaRepository.sincronizarDesdeSiat: tipo punto venta no encontrado', [
						'texto' => $tipoPuntoVentaTexto
					]);
					// Usar valor por defecto si no se encuentra
					$tipoCodigoClasificador = 0;
				}

				// Verificar si el punto de venta ya existe
				$existe = DB::table('sin_punto_venta')
					->where('codigo_punto_venta', $codigoPuntoVenta)
					->exists();

				$data = [
					'nombre' => $nombrePuntoVenta,
					'descripcion' => $pvSiat['descripcion'] ?? '',
					'sucursal' => $codigoSucursal,
					'codigo_cuis_genera' => $cuis,
					'id_usuario_crea' => (string) $idUsuario,
					'tipo' => $tipoCodigoClasificador,
					'ip' => '',
					'activo' => true,
					'fecha_creacion' => Carbon::now('America/La_Paz'),
					'crear_cufd' => true,
					'autocrear_cufd' => true,
					'codigo_ambiente' => $codigoAmbiente
				];

				if ($existe) {
					// Actualizar punto de venta existente
					DB::table('sin_punto_venta')
						->where('codigo_punto_venta', $codigoPuntoVenta)
						->update($data);
					$actualizados++;
					Log::info('PuntoVentaRepository.sincronizarDesdeSiat: punto de venta actualizado', [
						'codigo' => $codigoPuntoVenta
					]);
				} else {
					// Insertar nuevo punto de venta
					$data['codigo_punto_venta'] = $codigoPuntoVenta;
					DB::table('sin_punto_venta')->insert($data);
					$nuevos++;
					Log::info('PuntoVentaRepository.sincronizarDesdeSiat: punto de venta creado', [
						'codigo' => $codigoPuntoVenta
					]);
				}

				$sincronizados++;
			}

			Log::info('PuntoVentaRepository.sincronizarDesdeSiat: completado', [
				'sincronizados' => $sincronizados,
				'actualizados' => $actualizados,
				'nuevos' => $nuevos
			]);

			return [
				'success' => true,
				'sincronizados' => $sincronizados,
				'actualizados' => $actualizados,
				'nuevos' => $nuevos,
				'message' => "Sincronizacion completada: {$nuevos} nuevos, {$actualizados} actualizados"
			];

		} catch (Exception $e) {
			Log::error('PuntoVentaRepository.sincronizarDesdeSiat: error', [
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString()
			]);

			return [
				'success' => false,
				'sincronizados' => 0,
				'actualizados' => 0,
				'nuevos' => 0,
				'message' => 'Error al sincronizar: ' . $e->getMessage()
			];
		}
	}

	/**
	 * Obtiene el codigo clasificador del tipo de punto de venta desde sin_datos_sincronizacion
	 *
	 * @param string $descripcionTexto Descripcion del tipo de punto de venta (ej: "PUNTO DE VENTA CAJEROS")
	 * @return int|null Codigo clasificador o null si no se encuentra
	 */
	private function obtenerCodigoTipoPuntoVenta(string $descripcionTexto): ?int
	{
		if (empty($descripcionTexto)) {
			return null;
		}

		$row = DB::table('sin_datos_sincronizacion')
			->where('tipo', 'sincronizarParametricaTipoPuntoVenta')
			->where('descripcion', $descripcionTexto)
			->first();

		if ($row) {
			return (int) $row->codigo_clasificador;
		}

		return null;
	}

	/**
	 * Obtiene todos los puntos de venta filtrados por ambiente configurado y activos
	 *
	 * @return array Lista de puntos de venta
	 */
	public function getAll(): array
	{
		$codigoAmbiente = (int) config('sin.ambiente');
		$now = Carbon::now('America/La_Paz');

		// Primero, desactivar automáticamente las asignaciones vencidas
		DB::table('sin_punto_venta_usuario')
			->where('activo', 1)
			->where('vencimiento_asig', '<', $now)
			->update(['activo' => 0]);

		// Luego, obtener puntos de venta con asignaciones vigentes y activas
		$rows = DB::table('sin_punto_venta as pv')
			->leftJoin('sin_punto_venta_usuario as pvu', function($join) use ($now) {
				$join->on('pv.codigo_punto_venta', '=', 'pvu.codigo_punto_venta')
					->on('pv.sucursal', '=', 'pvu.codigo_sucursal')
					->on('pv.codigo_ambiente', '=', 'pvu.codigo_ambiente')
					->where('pvu.activo', '=', 1)
					->where('pvu.vencimiento_asig', '>=', $now);
			})
			->leftJoin('usuarios as u', 'pvu.id_usuario', '=', 'u.id_usuario')
			->select(
				'pv.*',
				'u.nickname as usuario_asignado',
				'pvu.vencimiento_asig as vence_en'
			)
			->where('pv.codigo_ambiente', $codigoAmbiente)
			->where('pv.activo', true)
			->orderBy('pv.codigo_punto_venta')
			->get();

		return $rows->map(function($row) {
			return (array) $row;
		})->toArray();
	}

	/**
	 * Obtiene puntos de venta filtrados por sucursal y ambiente
	 *
	 * @param int $codigoSucursal
	 * @param int $codigoAmbiente
	 * @return array
	 */
	public function getBySucursalAmbiente(int $codigoSucursal, int $codigoAmbiente): array
	{
		$rows = DB::table('sin_punto_venta')
			->where('sucursal', $codigoSucursal)
			->where('codigo_ambiente', $codigoAmbiente)
			->where('activo', true)
			->orderBy('codigo_punto_venta')
			->get();

		return $rows->map(function($row) {
			return (array) $row;
		})->toArray();
	}

	/**
	 * Cierra un punto de venta en SIAT y lo marca como inactivo localmente
	 *
	 * @param int $codigoAmbiente
	 * @param int $codigoPuntoVenta
	 * @param int $codigoSucursal
	 * @param string $cuis
	 * @return array Resultado del cierre
	 */
	public function cerrarPuntoVenta(int $codigoAmbiente, int $codigoPuntoVenta, int $codigoSucursal, string $cuis): array
	{
		Log::info('PuntoVentaRepository.cerrarPuntoVenta: iniciando', [
			'ambiente' => $codigoAmbiente,
			'puntoVenta' => $codigoPuntoVenta,
			'sucursal' => $codigoSucursal
		]);

		try {
			// Llamar al servicio SOAP para cerrar el punto de venta en SIAT
			$resultado = $this->operationsService->cierrePuntoVenta(
				$codigoAmbiente,
				$codigoPuntoVenta,
				$codigoSucursal,
				$cuis
			);

			// Verificar si el cierre fue exitoso
			if (isset($resultado['RespuestaCierrePuntoVenta'])) {
				$respuesta = $resultado['RespuestaCierrePuntoVenta'];

				if (isset($respuesta['transaccion']) && $respuesta['transaccion'] === true) {
					// Marcar el punto de venta como inactivo en la base de datos local
					DB::table('sin_punto_venta')
						->where('codigo_punto_venta', $codigoPuntoVenta)
						->where('sucursal', $codigoSucursal)
						->where('codigo_ambiente', $codigoAmbiente)
						->update([
							'activo' => false
						]);

					Log::info('PuntoVentaRepository.cerrarPuntoVenta: éxito', [
						'puntoVenta' => $codigoPuntoVenta
					]);

					return [
						'success' => true,
						'message' => 'Punto de venta cerrado exitosamente',
						'data' => $respuesta
					];
				} else {
					$mensajeError = $respuesta['mensajesList']['descripcion'] ?? 'Error desconocido al cerrar punto de venta';
					Log::warning('PuntoVentaRepository.cerrarPuntoVenta: transacción fallida', [
						'mensaje' => $mensajeError
					]);

					return [
						'success' => false,
						'message' => $mensajeError,
						'data' => $respuesta
					];
				}
			}

			return [
				'success' => false,
				'message' => 'Respuesta inválida del servicio SIAT',
				'data' => $resultado
			];

		} catch (\Throwable $e) {
			Log::error('PuntoVentaRepository.cerrarPuntoVenta: excepción', [
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString()
			]);

			return [
				'success' => false,
				'message' => 'Error al cerrar punto de venta: ' . $e->getMessage()
			];
		}
	}
}
