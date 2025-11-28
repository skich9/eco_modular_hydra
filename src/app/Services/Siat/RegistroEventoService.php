<?php

namespace App\Services\Siat;

use Illuminate\Support\Facades\Log;
use App\Services\Siat\SoapClientFactory;

/**
 * Servicio para registrar eventos significativos en el SIN
 * 
 * Los eventos significativos justifican por qué las facturas se emitieron en contingencia
 * Códigos de evento:
 * 1 - CORTE DEL SERVICIO DE INTERNET
 * 2 - INACCESIBILIDAD AL SERVICIO WEB
 * 3 - INGRESO A ZONAS SIN INTERNET
 * 4 - VENTA EN LUGARES SIN INTERNET
 * 5 - VIRUS INFORMÁTICO O FALLA DE SOFTWARE
 * 6 - CAMBIO DE INFRAESTRUCTURA
 * 7 - CORTE DE SUMINISTRO DE ENERGÍA
 */
class RegistroEventoService
{
	/**
	 * Registra un evento significativo en el SIN
	 * 
	 * @param string $cuis Código CUIS vigente
	 * @param string $cufd Código CUFD vigente
	 * @param string $cufdEvento CUFD del momento del evento
	 * @param int $codigoEvento Código del evento (1-7)
	 * @param string $descripcionEvento Descripción del evento
	 * @param string $fechaInicio Fecha inicio del evento (Y-m-d\TH:i:s.v)
	 * @param string $fechaFin Fecha fin del evento (Y-m-d\TH:i:s.v)
	 * @param int $puntoVenta Código del punto de venta
	 * @param int|null $sucursal Código de sucursal
	 * @return array Respuesta del SIN con codigoRecepcionEventoSignificativo
	 */
	public function registrarEvento(
		$cuis,
		$cufd,
		$cufdEvento,
		$codigoEvento,
		$descripcionEvento,
		$fechaInicio,
		$fechaFin,
		$puntoVenta = 0,
		$sucursal = null
	) {
		if ($sucursal === null) {
			$sucursal = (int) config('sin.sucursal', 0);
		}

		// Usar el servicio de OPERACIONES (no el de facturación electrónica)
		// Este servicio maneja: registro de eventos, cierre de puntos de venta, etc.
		$svc = (string) config('sin.servicio_operaciones', 'FacturacionOperaciones');

		try {
			$payload = [
				'codigoAmbiente' => (int) config('sin.ambiente'),
				'codigoMotivoEvento' => (int) $codigoEvento,
				'codigoModalidad' => (int) config('sin.modalidad'),
				'codigoPuntoVenta' => (int) $puntoVenta,
				'codigoSistema' => (string) config('sin.cod_sistema'),
				'codigoSucursal' => (int) $sucursal,
				'cufd' => (string) $cufd,
				'cufdEvento' => (string) $cufdEvento,
				'cuis' => (string) $cuis,
				'descripcion' => (string) $descripcionEvento,
				'fechaHoraFinEvento' => (string) $fechaFin,
				'fechaHoraInicioEvento' => (string) $fechaInicio,
				'nit' => (int) config('sin.nit')
			];

			Log::info('RegistroEventoService.request', [
				'service' => $svc,
				'payload' => $payload
			]);

			$client = SoapClientFactory::build($svc);
			$arg = new \stdClass();
			$arg->SolicitudEventoSignificativo = (object) $payload;

			// Intentar con diferentes nombres de método según la versión del servicio
			try {
				$result = $client->__soapCall('registroEventoSignificativo', [$arg]);
			} catch (\SoapFault $e) {
				// Intentar nombre alternativo
				if (strpos($e->getMessage(), 'not a valid method') !== false) {
					$result = $client->__soapCall('registrarEventoSignificativo', [$arg]);
				} else {
					throw $e;
				}
			}
			$arr = json_decode(json_encode($result), true);

			Log::info('RegistroEventoService.response', [
				'result' => $arr
			]);

			return $arr;
		} catch (\SoapFault $e) {
			Log::error('RegistroEventoService.soapFault', [
				'error' => $e->getMessage(),
				'faultcode' => $e->faultcode,
				'faultstring' => $e->faultstring
			]);
			throw $e;
		} catch (\Throwable $e) {
			Log::error('RegistroEventoService.error', [
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString()
			]);
			throw $e;
		}
	}
}
