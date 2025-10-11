<?php

namespace App\Services\Siat;

use Illuminate\Support\Facades\Log;
use SoapFault;

class OperationsService
{
	/**
	 * Esqueleto para recepcion de factura computarizada (en línea)
	 * Queda listo para integrar cuando se cuente con el payload completo y credenciales.
	 */
	public function recepcionFactura(array $payload): array
	{
		// Cuando SIN_OFFLINE=true, no se debe invocar al servicio SOAP real
		if (config('sin.offline')) {
			Log::info('OperationsService.recepcionFactura OFFLINE');
			return [ 'offline' => true ];
		}

		$services = [];
		$cfg = (string) config('sin.operations_service');
		if ($cfg) $services[] = $cfg;

		$docSector = $payload['codigoDocumentoSector'] ?? null;
		$modalidad = $payload['codigoModalidad'] ?? null;

		// Sector Educativo (11): priorizar servicios específicos
		if ((int) $docSector === 11) {
			if ((int) $modalidad === 2) {
				// Variantes comunes en piloto/producción
				$services[] = 'ServicioFacturacionComputarizadaSectorEducativo';
				$services[] = 'ServicioFacturaComputarizadaSectorEducativo';
				$services[] = 'ServicioFacturacionSectorEducativo';
				$services[] = 'ServicioFacturacionSectorEducativa';
				$services[] = 'FacturacionSectorEducativo';
				$services[] = 'FacturacionSectorEducativa';
				$services[] = 'ServicioFacturaElectronicaSectorEducativo';
			} else {
				$services[] = 'ServicioFacturaElectronicaSectorEducativo';
				$services[] = 'ServicioFacturacionSectorEducativo';
				$services[] = 'ServicioFacturacionSectorEducativa';
				$services[] = 'ServicioFacturaComputarizadaSectorEducativo';
				$services[] = 'ServicioFacturacionComputarizadaSectorEducativo';
				$services[] = 'FacturacionSectorEducativo';
				$services[] = 'FacturacionSectorEducativa';
			}
		}

		// Genéricos de Compra-Venta (fallback)
		$services[] = 'ServicioFacturacionCompraVenta';
		$services[] = 'ServicioFacturaComputarizadaCompraVenta';
		$services[] = 'ServicioFacturaElectronicaCompraVenta';

		$lastError = null;
		foreach ($services as $svc) {
			try {
				Log::info('OperationsService.recepcionFactura: trying service', [ 'service' => $svc ]);
				$client = SoapClientFactory::build($svc);
				// Algunos WSDL exigen 'SolicitudServicioRecepcionFactura' y otros 'SolicitudRecepcionFactura'
				$wrappers = ['SolicitudServicioRecepcionFactura', 'SolicitudRecepcionFactura'];
				$lastWrapperError = null;
				$serviceNotAvailable = false; // 995 flag
				foreach ($wrappers as $wrap) {
					try {
						$arg = new \stdClass();
						$arg->{$wrap} = (object) $payload;
						Log::info('OperationsService.recepcionFactura: trying wrapper', [ 'service' => $svc, 'wrapper' => $wrap ]);
						$result = $client->__soapCall('recepcionFactura', [ $arg ]);
						$arr = json_decode(json_encode($result), true);
						// Detectar código 995 (servicio no disponible) y continuar con otro servicio
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
							Log::warning('OperationsService.recepcionFactura: service returned 995, trying next service', [ 'service' => $svc ]);
							$serviceNotAvailable = true;
							break; // salir de wrappers y pasar al siguiente servicio
						}
						return $arr;
					} catch (SoapFault $we) {
						$lastWrapperError = $we;
						Log::warning('OperationsService.recepcionFactura: wrapper fault', [ 'service' => $svc, 'wrapper' => $wrap, 'error' => $we->getMessage() ]);
						continue;
					}
				}
				// si el servicio retornó 995, intentar siguiente servicio
				if ($serviceNotAvailable) {
					continue;
				}
				// si ninguno de los wrappers funcionó y no hubo 995, relanzar último error
				if ($lastWrapperError) throw $lastWrapperError;
			} catch (SoapFault $e) {
				$lastError = $e;
				$msg = $e->getMessage();
				Log::warning('OperationsService.recepcionFactura: fault', [ 'service' => $svc, 'error' => $msg ]);
				// Continuar con siguiente servicio cuando:
				// - método inválido
				// - WSDL no disponible o con error de parsing
				if (
					stripos($msg, 'not a valid method') !== false ||
					stripos($msg, 'is not a valid method') !== false ||
					stripos($msg, 'Parsing WSDL') !== false ||
					stripos($msg, "Couldn't load from") !== false ||
					stripos($msg, 'HTTP') !== false
				) {
					continue;
				}
				throw $e;
			} catch (\Throwable $e) {
				$lastError = $e;
				Log::error('OperationsService.recepcionFactura: exception', [ 'service' => $svc, 'error' => $e->getMessage() ]);
			}
		}

		throw $lastError ?: new \RuntimeException('No se pudo invocar recepcionFactura en servicios conocidos');
	}
}
