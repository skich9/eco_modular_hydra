<?php

namespace App\Services\Siat;

use Illuminate\Support\Facades\Log;
use SoapFault;

class RecepcionPaqueteService
{
	/**
	 * Envía un paquete de facturas en contingencia al SIN
	 * 
	 * @param string $cuis
	 * @param string $cufd
	 * @param int $codigoDocumentoSector
	 * @param int $codigoEmision (1=En línea, 2=Contingencia)
	 * @param int $tipoFacturaDocumento (1=Factura)
	 * @param string $archivo Base64 del XML comprimido
	 * @param string $fechaEnvio
	 * @param string $hashArchivo SHA256 del archivo
	 * @param int $cantidadFacturas
	 * @param string|null $cafc Código de autorización (requerido para contingencia)
	 * @param int|null $codigoEvento Código del evento significativo
	 * @param int $puntoVenta
	 * @param int $sucursal
	 * @return array
	 */
	public function enviarPaquete(
		$cuis,
		$cufd,
		$codigoDocumentoSector,
		$codigoEmision,
		$tipoFacturaDocumento,
		$archivo,
		$fechaEnvio,
		$hashArchivo,
		$cantidadFacturas,
		$cafc = null,
		$codigoEvento = null,
		$puntoVenta = 0,
		$sucursal = null
	) {
		if ($sucursal === null) {
			$sucursal = (int) config('sin.sucursal', 0);
		}

		// Usar el servicio de FACTURACIÓN ELECTRÓNICA para recepción de paquetes
		$svc = (string) config('sin.servicio_facturacion_electronica', 'ServicioFacturacionElectronica');

		try {
			$payload = [
				'codigoAmbiente' => (int) config('sin.ambiente'),
				'codigoDocumentoSector' => (int) $codigoDocumentoSector,
				'codigoEmision' => (int) $codigoEmision,
				'codigoModalidad' => (int) config('sin.modalidad'),
				'codigoPuntoVenta' => (int) $puntoVenta,
				'codigoSistema' => (string) config('sin.cod_sistema'),
				'codigoSucursal' => (int) $sucursal,
				'cufd' => (string) $cufd,
				'cuis' => (string) $cuis,
				'nit' => (int) config('sin.nit'),
				'tipoFacturaDocumento' => (int) $tipoFacturaDocumento,
				'archivo' => (string) $archivo,
				'fechaEnvio' => (string) $fechaEnvio,
				'hashArchivo' => (string) $hashArchivo,
				'cantidadFacturas' => (int) $cantidadFacturas
			];

			if ($cafc !== null && $cafc !== '') {
				$payload['cafc'] = (string) $cafc;
			}

			// Siempre incluir codigoEvento, usar 0 si es NULL
			// El WSDL podría requerir este campo
			$payload['codigoEvento'] = ($codigoEvento !== null && $codigoEvento > 0) ? (int) $codigoEvento : 0;

			Log::info('RecepcionPaqueteService.request', [
				'service' => $svc,
				'payload' => $payload,
				'cantidad_facturas' => $cantidadFacturas
			]);

			$client = SoapClientFactory::build($svc);
			$arg = new \stdClass();
			$arg->SolicitudServicioRecepcionPaquete = (object) $payload;

			$result = $client->__soapCall('recepcionPaqueteFactura', [$arg]);
			$arr = json_decode(json_encode($result), true);

			Log::info('RecepcionPaqueteService.response', [
				'result' => $arr
			]);

			return $arr;
		} catch (SoapFault $e) {
			Log::error('RecepcionPaqueteService.soapFault', [
				'error' => $e->getMessage(),
				'faultcode' => $e->faultcode,
				'faultstring' => $e->faultstring
			]);
			throw $e;
		} catch (\Throwable $e) {
			Log::error('RecepcionPaqueteService.error', [
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString()
			]);
			throw $e;
		}
	}
}
