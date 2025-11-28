<?php

namespace App\Services\Siat;

use Illuminate\Support\Facades\Log;
use SoapFault;

class OperationsService
{
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
		}
	}
}
