<?php

namespace App\Services\Siat;

use Illuminate\Support\Facades\Log;
use SoapFault;

class OperationsService
{
	/**
	 * Consulta de puntos de venta registrados en SIAT
	 */
	public function consultaPuntoVenta(int $codigoAmbiente, int $codigoSucursal, string $cuis): array
	{
		$client = SoapClientFactory::build(config('sin.operations_service'));

		$payload = [
			'codigoAmbiente' => $codigoAmbiente,
			'codigoSistema' => (string) config('sin.cod_sistema'),
			'codigoSucursal' => $codigoSucursal,
			'cuis' => $cuis,
			'nit' => (int) config('sin.nit'),
		];

		$arg = new \stdClass();
		$arg->SolicitudConsultaPuntoVenta = (object) $payload;

		Log::info('OperationsService.consultaPuntoVenta: request', [
			'ambiente' => $codigoAmbiente,
			'sucursal' => $codigoSucursal,
			'cuis' => $cuis
		]);

		try {
			$result = $client->__soapCall('consultaPuntoVenta', [ $arg ]);
			$arr = json_decode(json_encode($result), true);
			Log::debug('OperationsService.consultaPuntoVenta: response', [
				'hasRespuesta' => isset($arr['RespuestaConsultaPuntoVenta'])
			]);
			return $arr;
		} catch (SoapFault $e) {
			Log::error('OperationsService.consultaPuntoVenta: soap fault', [
				'error' => $e->getMessage()
			]);
			throw $e;
		} catch (\Throwable $e) {
			Log::error('OperationsService.consultaPuntoVenta: exception', [
				'error' => $e->getMessage()
			]);
			throw $e;
		}
	}

	/**
	 * Registro de un nuevo punto de venta en SIAT
	 */
	public function registroPuntoVenta(int $codigoAmbiente, int $codigoSucursal, string $cuis, int $codigoTipoPuntoVenta, string $nombrePuntoVenta, string $descripcion = ''): array
	{
		$client = SoapClientFactory::build(config('sin.operations_service'));

		$payload = [
			'codigoAmbiente' => $codigoAmbiente,
			'codigoModalidad' => (int) config('sin.modalidad'),
			'codigoSistema' => (string) config('sin.cod_sistema'),
			'codigoSucursal' => $codigoSucursal,
			'codigoTipoPuntoVenta' => $codigoTipoPuntoVenta,
			'cuis' => $cuis,
			'descripcion' => $descripcion,
			'nit' => (int) config('sin.nit'),
			'nombrePuntoVenta' => $nombrePuntoVenta,
		];

		$arg = new \stdClass();
		$arg->SolicitudRegistroPuntoVenta = (object) $payload;

		Log::info('OperationsService.registroPuntoVenta: request', [
			'ambiente' => $codigoAmbiente,
			'sucursal' => $codigoSucursal,
			'tipo' => $codigoTipoPuntoVenta,
			'nombre' => $nombrePuntoVenta
		]);

		try {
			$result = $client->__soapCall('registroPuntoVenta', [ $arg ]);
			$arr = json_decode(json_encode($result), true);
			Log::info('OperationsService.registroPuntoVenta: response', [
				'hasRespuesta' => isset($arr['RespuestaRegistroPuntoVenta'])
			]);
			return $arr;
		} catch (SoapFault $e) {
			Log::error('OperationsService.registroPuntoVenta: soap fault', [
				'error' => $e->getMessage()
			]);
			throw $e;
		} catch (\Throwable $e) {
			Log::error('OperationsService.registroPuntoVenta: exception', [
				'error' => $e->getMessage()
			]);
			throw $e;
		}
	}

	/**
	 * Cierre de punto de venta en SIAT
	 *
	 * @param int $codigoAmbiente
	 * @param int $codigoPuntoVenta
	 * @param int $codigoSucursal
	 * @param string $cuis
	 * @return array
	 */
	public function cierrePuntoVenta(int $codigoAmbiente, int $codigoPuntoVenta, int $codigoSucursal, string $cuis): array
	{
		$client = SoapClientFactory::build(config('sin.operations_service'));

		$payload = [
			'codigoAmbiente' => $codigoAmbiente,
			'codigoPuntoVenta' => $codigoPuntoVenta,
			'codigoSistema' => (string) config('sin.cod_sistema'),
			'codigoSucursal' => $codigoSucursal,
			'cuis' => $cuis,
			'nit' => (int) config('sin.nit'),
		];

		$arg = new \stdClass();
		$arg->SolicitudCierrePuntoVenta = (object) $payload;

		Log::info('OperationsService.cierrePuntoVenta: request', [
			'ambiente' => $codigoAmbiente,
			'puntoVenta' => $codigoPuntoVenta,
			'sucursal' => $codigoSucursal,
			'cuis' => $cuis
		]);

		try {
			$result = $client->__soapCall('cierrePuntoVenta', [ $arg ]);
			$arr = json_decode(json_encode($result), true);
			Log::debug('OperationsService.cierrePuntoVenta: response', [
				'hasRespuesta' => isset($arr['RespuestaCierrePuntoVenta'])
			]);
			return $arr;
		} catch (\SoapFault $e) {
			Log::error('OperationsService.cierrePuntoVenta: soap fault', [
				'error' => $e->getMessage()
			]);
			throw $e;
		} catch (\Throwable $e) {
			Log::error('OperationsService.cierrePuntoVenta: exception', [
				'error' => $e->getMessage()
			]);
			throw $e;
		}
	}

	/**
	 * Recepción de factura computarizada (en línea)
	 * Usa el servicio de FACTURACIÓN ELECTRÓNICA, no el de operaciones
	 */
	public function recepcionFactura(array $payload)
	{
		// Cuando SIN_OFFLINE=true, no se debe invocar al servicio SOAP real
		if (config('sin.offline')) {
			Log::info('OperationsService.recepcionFactura OFFLINE');
			return [ 'offline' => true ];
		}

		// Usar el servicio de FACTURACIÓN ELECTRÓNICA para recepción de facturas en línea
		$svc = (string) config('sin.servicio_facturacion_electronica', 'ServicioFacturacionElectronica');
		$lastError = null;
		try {
<<<<<<< HEAD
            Log::info('OperationsService.recepcionFactura: trying service', [ 'service' => $svc ]);
            $client = SoapClientFactory::build($svc);
            // $wrappers = ['SolicitudServicioRecepcionFactura', 'SolicitudRecepcionFactura'];
            $lastWrapperError = null;
            $serviceNotAvailable = false; // 995 flag
            // foreach ($wrappers as $wrap) {
                // try {
            $wrap = 'SolicitudServicioRecepcionFactura';
            $arg = new \stdClass();
            $arg->{$wrap} = (object) $payload;
            Log::info('OperationsService.recepcionFactura: trying wrapper', [ 'service' => $svc, 'wrapper' => $wrap ]);
            $result = $client->__soapCall('recepcionFactura', [ $arg ]);
            ////
            Log::debug('OperationsService.recepcionFactura: la respuesta de impuestos es:', [ 'result' => $result ]);
            $arr = json_decode(json_encode($result), true);
            // Detectar código 995 (servicio no disponible) === IMPORTANTE SI SALE ESTE ERROR ES PORQUE IMPUESTO CORTA EL SERVICIO
            $root = is_array($arr) ? reset($arr) : null;
            Log::info('el resultado del root es :'.print_r($root,true));
            $mensajes = is_array($root) ? ($root['mensajesList'] ?? null) : null;
            $cod995 = false;
            if ($mensajes) {
                Log::info('esta ingreando al if de mensajes :');
                if (isset($mensajes['codigo'])) {
                    $cod995 = ((int)$mensajes['codigo'] === 995);
                } elseif (is_array($mensajes)) {
                    foreach ($mensajes as $m) {
                        if (is_array($m) && isset($m['codigo']) && (int)$m['codigo'] === 995) {
                            $cod995 = true;
                            break;
                        }
                    }
                }
            }
            if ($cod995) {
                Log::warning('OperationsService.recepcionFactura: service returned 995');
                throw new ServiceNotAvailableException('Servicio de facturacion no disponible (995)');
            }

            //// verificamos si el codigo de esado es 901 o 908 se sigue procesandoi la peticion caso cont
            $codigoEstado = is_array($root) && isset($root['codigoEstado']) ? (int)$root['codigoEstado'] : null;

            if($codigoEstado === null){
                Log::warning('OperationsService.recepcionFactura: NO existe código de estado en la respuesta de impuestos, contacte con el administrador', [ 'response' => $arr ]);
                throw new UnsupportedCodigoEstadoException('Error en la recepcion de factura impuestos no devuelve un codigo de estado, codigoEstado: null');
            }

            // if($codigoEstado != 901 && $codigoEstado != 908 && $codigoEstado != 902){
            //     Log::warning('OperationsService.recepcionFactura: impuestos devuelve un codigo de estado no soportado', [ 'codigoEstado' => $codigoEstado ]);
            //     throw new UnsupportedCodigoEstadoException('Error en la recepcion de factura impuestos devuelve un codigo de excepcion no soportado, codigoEstado: '.$codigoEstado);
            // }

            // if ($codigoEstado == 902) {
            //     // se debe procesar como rechazado y no se genera la factura debe llegar hasta el front o la api
            //     // se debe actualizar la factura con el la respuesta de impuestos
            //     $arr["errorCutomMessage"] = json_encode($mensajes);
            //     return $arr;
            // }

            /// si todo esta bien se retorna el arreglo
            return $arr;
                // } catch (SoapFault $we) {
                // 	$lastWrapperError = $we;
                // 	Log::warning('OperationsService.recepcionFactura: wrapper fault', [ 'service' => $svc, 'wrapper' => $wrap, 'error' => $we->getMessage() ]);
                // 	continue;
                // }
            // }
		} catch (SoapFault $e) {
            Log::error('OperationService: problemas al consumir el servicio web de impuestos:', [ 'error' => SgaHelper::getStackTrackeException($e) , 'msg' => $e->getMessage ]);
            throw new SoapFaultException("Hubo problemas al consumir el servicio de impuestos: ".$e->getMessage());
		} catch (Exception $e) {
            Log::error('OperationService: excepcion no controlado en la recepcion de factura ', [ 'error' => SgaHelper::getStackTrackeException($e) , 'msg' => $e->getMessage ]);
			throw $e;
=======
			try {
				Log::info('OperationsService.recepcionFactura: trying service', [ 'service' => $svc ]);
				$client = SoapClientFactory::build($svc);

				$wrappers = ['SolicitudServicioRecepcionFactura', 'SolicitudRecepcionFactura'];
				$lastWrapperError = null;
				$serviceNotAvailable = false; // 995 flag
				foreach ($wrappers as $wrap) {
					try {
						$arg = new \stdClass();
						$arg->{$wrap} = (object) $payload;
						Log::info('OperationsService.recepcionFactura: trying wrapper', [ 'service' => $svc, 'wrapper' => $wrap ]);
						$result = $client->__soapCall('recepcionFactura', [ $arg ]);
						Log::info('OperationsService.recepcionFactura: wrapper result xxxxx', [ 'result' => $result ]);
						$arr = json_decode(json_encode($result), true);
						// Detectar código 995 (servicio no disponible)
						$root = is_array($arr) ? reset($arr) : null;
						$mensajes = is_array($root) ? ($root['mensajesList'] ?? null) : null;
						$cod995 = false;
						if ($mensajes) {
							if (isset($mensajes['codigo'])) {
								$cod995 = ((int)$mensajes['codigo'] === 995);
							} elseif (is_array($mensajes)) {
								foreach ($mensajes as $m) {
									if (is_array($m) && isset($m['codigo']) && (int)$m['codigo'] === 995) { $cod995 = true; break; }
								}
							}
						}
						if ($cod995) {
							Log::warning('OperationsService.recepcionFactura: service returned 995', [ 'service' => $svc ]);
							$serviceNotAvailable = true;
							break; // salir de wrappers
						}
						// Detectar rechazo 902 con error 920 (archivo invalido / formato de compresion desconocido)
						$estado = is_array($root) && isset($root['codigoEstado']) ? (int)$root['codigoEstado'] : null;
						$has920 = false;
						if ($mensajes) {
							if (isset($mensajes['codigo'])) {
								$has920 = ((int)$mensajes['codigo'] === 920);
							} elseif (is_array($mensajes)) {
								foreach ($mensajes as $m) {
									if (is_array($m) && isset($m['codigo']) && (int)$m['codigo'] === 920) { $has920 = true; break; }
								}
							}
						}
						if ($estado === 902 && $has920) {
							Log::warning('OperationsService.recepcionFactura: service returned 902/920 (archivo invalido)', [ 'service' => $svc ]);
							$serviceNotAvailable = true;
							break;
						}
						return $arr;
					} catch (SoapFault $we) {
						$lastWrapperError = $we;
						Log::warning('OperationsService.recepcionFactura: wrapper fault', [ 'service' => $svc, 'wrapper' => $wrap, 'error' => $we->getMessage() ]);
						continue;
					}
				}
				// si el servicio retornó 995 o 902/920, retornar el último resultado o lanzar error
				if ($serviceNotAvailable) {
					throw new \RuntimeException('Servicio de facturacion no disponible o archivo invalido (995/902-920)');
				}
				// si ninguno de los wrappers funcionó y no hubo 995, relanzar último error
				if ($lastWrapperError) throw $lastWrapperError;
			} catch (SoapFault $e) {
				$lastError = $e;
				$msg = $e->getMessage();
				Log::warning('OperationsService.recepcionFactura: fault', [ 'service' => $svc, 'error' => $msg ]);
				throw $e;
			} catch (\Throwable $e) {
				$lastError = $e;
				Log::error('OperationsService.recepcionFactura: exception', [ 'service' => $svc, 'error' => $e->getMessage() ]);
			}

			throw $lastError ?: new \RuntimeException('No se pudo invocar recepcionFactura');
		} catch (\Throwable $e) {
			$lastError = $e;
			Log::error('OperationsService.recepcionFactura: exception', [ 'service' => $svc, 'error' => $e->getMessage() ]);
			throw $lastError ?: new \RuntimeException('No se pudo invocar recepcionFactura');
>>>>>>> integracionesH
		}
	}
}
