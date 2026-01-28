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
	 * Obtiene todos los puntos de venta de la base de datos local
	 * 
	 * @return array Lista de puntos de venta
	 */
	public function getAll(): array
	{
		$rows = DB::table('sin_punto_venta')
			->orderBy('codigo_punto_venta')
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
}
