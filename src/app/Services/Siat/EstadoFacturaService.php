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
	public function verificacionEstadoFactura($cuf, $puntoVenta = 0, $sucursal)
	{
        $codigoAmbiente = (int) config('sin.ambiente');
		if ($cuf === '') {
			return [ 'success' => false, 'message' => 'CUF vacío' ];
		}
        $svc = (string) config('sin.servicio_facturacion_electronica', 'ServicioFacturacionElectronica');
		try {
			// Asegurar CUIS/CUFD vigentes
			$cuisRow = $this->cuisRepo->getVigenteOrCreate2($codigoAmbiente, $sucursal, $puntoVenta);
			$cuis = isset($cuisRow['codigo_cuis']) ? (string)$cuisRow['codigo_cuis'] : '';
			$cufdRow = $this->cufdRepo->getVigenteOrCreate2($codigoAmbiente, $sucursal, $puntoVenta);
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
				'payload' => $payload,
				'punto_venta' => (int)$puntoVenta,
				'sucursal' => (int)$sucursal,
				'cuf_len' => strlen((string)$cuf),
				'cuis' => $cuis,
				'cufd' => $cufd,
			]);
			$lastError = null;

            $client = SoapClientFactory::build($svc);
            $wrap = 'SolicitudServicioVerificacionEstadoFactura';
            $lastWrapperError = null;

            $arg = new \stdClass();
            $arg->{$wrap} = (object) $payload;
            $result = $client->__soapCall('verificacionEstadoFactura', [ $arg ]);
            Log::debug('el resultado que llega es eeeee:'.print_r($result,true));
            $arr = json_decode(json_encode($result), true);
            $root = is_array($arr) ? reset($arr) : null;
            $codigoEstado = is_array($root) && isset($root['codigoEstado']) ? (int)$root['codigoEstado'] : null;
            $descripcionEstado = is_array($root) && isset($root['codigoDescripcion']) ? (string)$root['codigoDescripcion'] : null;
            // $descripcion = is_array($root) && isset($root['mensajesList']) ? $this->firstMessage($root['mensajesList']) : null;
            $mensajesList = is_array($root) && isset($root['mensajesList']) ? $root['mensajesList'] : null;
            // YA NO SE VA A MAPEAR EL ESTADO, SE DEJA TAL CUAL VIENE DEL SERVICIO
            // $estado = $this->mapEstado($codigoEstado);
            $lastReq = method_exists($client, '__getLastRequest') ? (string)$client->__getLastRequest() : null;
            $lastResp = method_exists($client, '__getLastResponse') ? (string)$client->__getLastResponse() : null;
            Log::debug('EstadoFacturaService.response', [
                'service' => $svc,
                'wrapper' => $wrap,
                'codigoEstado' => $codigoEstado,
                'descripcionEstado' => $descripcionEstado,
                // 'estado' => $estado,
                // 'descripcion' => $descripcion,
                'mensajesList' => $mensajesList,
                'raw' => $arr,
            ]);
            return [
                'success' => true,
                'codigoEstado' => $codigoEstado,
                'descripcionEstado' => $descripcionEstado,
                // 'estado' => $estado,
                // 'descripcion' => $descripcion,
                'mensajesList' => $mensajesList,
                'raw' => $arr,

                'payload' => $payload,          // REVISAR SI SE UTILIZA EN ALGUN LUGAR
                'last_request' => $lastReq,     // 'last_response' => $lastResp,   // REVISAR SI SE UTILIZA EN ALGUN LUGAR
                'last_response' => $lastResp,   // REVISAR SI SE UTILIZA EN ALGUN LUGAR
                'service' => $svc,              // REVISAR SI SE UTILIZA EN ALGUN LUGAR
            ];
            // if ($lastWrapperError) {
            //     $lastError = $lastWrapperError;
            // }
			// if ($lastError) {
            //     throw $lastError;
            // }
			// return [ 'success' => false, 'message' => 'No se pudo invocar verificacionEstadoFactura en ninguno de los servicios' ];
		} catch (\Throwable $e) {
			Log::error('EstadoFacturaService.verificacionEstadoFactura', [ 'service' => $svc, 'error' => $e->getMessage() ]);
			return [ 'success' => false, 'message' => $e->getMessage() ];
		}
	}
	// private function mapEstado($codigo)
	// {
    //         if ($codigo === 690) return 'ACEPTADA';
    //         if ($codigo === 908) return 'ANULADA';
    //         if ($codigo === 691) return 'ANULADA'; // Código que devuelve SIAT tras anulación confirmada
    //         if ($codigo === 905) return 'ANULADA'; // Código de confirmación de anulación
    //         if ($codigo === null) return 'RECHAZADA';
    //         return 'RECHAZADA';
	// }
	private function firstMessage($mensajes)
	{
		if (!$mensajes) return null;
		if (isset($mensajes['descripcion'])) return (string)$mensajes['descripcion'];
		if (isset($mensajes[0]['descripcion'])) return (string)$mensajes[0]['descripcion'];
		return null;
	}
}
