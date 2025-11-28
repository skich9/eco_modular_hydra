<?php

namespace App\Services\Siat;

use Illuminate\Support\Facades\Log;
use SoapFault;

class ValidacionPaqueteService
{
	/**
	 * Valida un paquete de facturas enviado al SIN
	 * 
	 * @param string $cuis
	 * @param string $cufd
	 * @param int $codigoDocumentoSector
	 * @param int $codigoEmision
	 * @param int $tipoFacturaDocumento
	 * @param string $codigoRecepcion
	 * @param int $puntoVenta
	 * @param int $sucursal
	 * @return array
	 */
	public function validarPaquete(
		$cuis,
		$cufd,
		$codigoDocumentoSector,
		$codigoEmision,
		$tipoFacturaDocumento,
		$codigoRecepcion,
		$puntoVenta = 0,
		$sucursal = null
	) {
		if ($sucursal === null) {
			$sucursal = (int) config('sin.sucursal', 0);
		}

		$svc = (string) config('sin.operations_service', 'ServicioFacturacionElectronica');

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
				'codigoRecepcion' => (string) $codigoRecepcion
			];

			Log::info('ValidacionPaqueteService.request', [
				'service' => $svc,
				'payload' => $payload,
				'codigo_recepcion' => $codigoRecepcion
			]);

			$client = SoapClientFactory::build($svc);
			$arg = new \stdClass();
			$arg->SolicitudServicioValidacionRecepcionPaquete = (object) $payload;

			$result = $client->__soapCall('validacionRecepcionPaqueteFactura', [$arg]);
			$arr = json_decode(json_encode($result), true);

			Log::info('ValidacionPaqueteService.response', [
				'result' => $arr
			]);

			return $arr;
		} catch (SoapFault $e) {
			Log::error('ValidacionPaqueteService.soapFault', [
				'error' => $e->getMessage(),
				'faultcode' => $e->faultcode,
				'faultstring' => $e->faultstring
			]);
			throw $e;
		} catch (\Throwable $e) {
			Log::error('ValidacionPaqueteService.error', [
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString()
			]);
			throw $e;
		}
	}
}
