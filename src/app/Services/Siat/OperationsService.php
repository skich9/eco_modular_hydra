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
		}
	}
}
