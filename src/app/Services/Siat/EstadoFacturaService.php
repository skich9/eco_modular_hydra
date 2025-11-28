<?php

namespace App\Services\Siat;

use App\Repositories\Sin\CufdRepository;
use App\Repositories\Sin\CuisRepository;
use Illuminate\Support\Facades\Log;
use SoapFault;

class EstadoFacturaService
{
    /** @var CufdRepository */
    private $cufdRepo;
    /** @var CuisRepository */
    private $cuisRepo;

    public function __construct(CufdRepository $cufdRepo, CuisRepository $cuisRepo)
    {
        $this->cufdRepo = $cufdRepo;
        $this->cuisRepo = $cuisRepo;
    }

	/**
	 * Verifica el estado de una factura en el SIN.
	 * Devuelve arreglo normalizado con: success, codigoEstado, descripcion, estado, raw
	 */
	public function verificacionEstadoFactura($cuf, $puntoVenta = 0, $sucursal = null)
	{
		if ($cuf === '') {
			return [ 'success' => false, 'message' => 'CUF vacío' ];
		}

		$services = [
			(string) config('sin.servicio_operaciones', 'FacturacionOperaciones'),
			(string) config('sin.servicio_facturacion_electronica', 'ServicioFacturacionElectronica'),
		];
		try {
			// Asegurar CUIS/CUFD vigentes
			$cuisRow = $this->cuisRepo->getVigenteOrCreate($puntoVenta);
			$cuis = isset($cuisRow['codigo_cuis']) ? (string)$cuisRow['codigo_cuis'] : '';
			$cufdRow = $this->cufdRepo->getVigenteOrCreate($puntoVenta);
			$cufd = isset($cufdRow['codigo_cufd']) ? (string)$cufdRow['codigo_cufd'] : '';
			if ($sucursal === null) { $sucursal = isset($cuisRow['codigo_sucursal']) ? (int)$cuisRow['codigo_sucursal'] : (int)config('sin.sucursal'); }

			$payload = [
				'codigoAmbiente'        => (int) config('sin.ambiente'),
				'codigoDocumentoSector' => (int) config('sin.cod_doc_sector'),
				'codigoEmision'         => 1,
				'codigoModalidad'       => (int) config('sin.modalidad'),
				'codigoPuntoVenta'      => $puntoVenta,
				'codigoSistema'         => (string) config('sin.cod_sistema'),
				'codigoSucursal'        => (int) $sucursal,
				'cufd'                  => $cufd,
				'cuis'                  => $cuis,
				'nit'                   => (int) config('sin.nit'),
				'tipoFacturaDocumento'  => (int) config('sin.tipo_factura'),
				'cuf'                   => $cuf,
			];

			Log::debug('EstadoFacturaService.request', [
				'candidate_services' => $services,
				'payload' => $payload,
				'punto_venta' => (int)$puntoVenta,
				'sucursal' => (int)$sucursal,
				'cuf_len' => strlen((string)$cuf),
				'cuis' => $cuis,
				'cufd' => $cufd,
			]);

			$lastError = null;
			foreach ($services as $svc) {
				try {
					$client = SoapClientFactory::build($svc);
					$wrappers = ['SolicitudServicioVerificacionEstadoFactura', 'SolicitudVerificacionEstadoFactura'];
					$lastWrapperError = null;
					foreach ($wrappers as $wrap) {
						try {
							$arg = new \stdClass();
							$arg->{$wrap} = (object) $payload;
							$result = $client->__soapCall('verificacionEstadoFactura', [ $arg ]);
							$arr = json_decode(json_encode($result), true);
							$root = is_array($arr) ? reset($arr) : null;
							$codigoEstado = is_array($root) && isset($root['codigoEstado']) ? (int)$root['codigoEstado'] : null;
							$descripcion = is_array($root) && isset($root['mensajesList']) ? $this->firstMessage($root['mensajesList']) : null;
							$estado = $this->mapEstado($codigoEstado);
							$lastReq = method_exists($client, '__getLastRequest') ? (string)$client->__getLastRequest() : null;
							$lastResp = method_exists($client, '__getLastResponse') ? (string)$client->__getLastResponse() : null;
							Log::debug('EstadoFacturaService.response', [
								'service' => $svc,
								'wrapper' => $wrap,
								'codigoEstado' => $codigoEstado,
								'estado' => $estado,
								'descripcion' => $descripcion,
								'raw' => $arr,
							]);
							return [
								'success' => true,
								'codigoEstado' => $codigoEstado,
								'descripcion' => $descripcion,
								'estado' => $estado,
								'raw' => $arr,
								'payload' => $payload,
								'last_request' => $lastReq,
								'last_response' => $lastResp,
								'service' => $svc,
							];
						} catch (SoapFault $we) {
							$lastWrapperError = $we; continue;
						}
					}
					if ($lastWrapperError) { $lastError = $lastWrapperError; continue; }
				} catch (\Throwable $se) {
					$lastError = $se; continue;
				}
			}
			if ($lastError) { throw $lastError; }
			return [ 'success' => false, 'message' => 'No se pudo invocar verificacionEstadoFactura en ninguno de los servicios' ];
		} catch (\Throwable $e) {
			Log::error('EstadoFacturaService.verificacionEstadoFactura', [ 'services' => $services, 'error' => $e->getMessage() ]);
			return [ 'success' => false, 'message' => $e->getMessage() ];
		}
	}

	private function mapEstado($codigo)
	{
		if ($codigo === 690) return 'ACEPTADA';
		if ($codigo === 908) return 'ANULADA';
		if ($codigo === 691) return 'ANULADA'; // Código que devuelve SIAT tras anulación confirmada
		if ($codigo === 905) return 'ANULADA'; // Código de confirmación de anulación
		if ($codigo === null) return 'DESCONOCIDO';
		return 'RECHAZADA';
	}

	private function firstMessage($mensajes)
	{
		if (!$mensajes) return null;
		if (isset($mensajes['descripcion'])) return (string)$mensajes['descripcion'];
		if (isset($mensajes[0]['descripcion'])) return (string)$mensajes[0]['descripcion'];
		return null;
	}
}
