<?php

namespace App\Services\Siat;

use SoapFault;
use Illuminate\Support\Facades\Log;

class CodesService
{
	public function cuis(int $puntoVenta = 0): array
	{
		$client = SoapClientFactory::build(config('sin.codes_service'));
		$payload = [
			'codigoAmbiente'    => (int) config('sin.ambiente'),
			'codigoModalidad'   => (int) config('sin.modalidad'),
			'codigoPuntoVenta'  => $puntoVenta,
			'codigoSistema'     => (string) config('sin.cod_sistema'),
			'codigoSucursal'    => (int) config('sin.sucursal'),
			'nit'               => (int) config('sin.nit'),
		];
		$arg = new \stdClass();
		$arg->SolicitudCuis = (object) $payload;
		Log::info('CodesService.cuis: request', [ 'pv' => $puntoVenta, 'sucursal' => $payload['codigoSucursal'] ]);
		try {
			$result = $client->__soapCall('cuis', [ $arg ]);
			$arr = json_decode(json_encode($result), true);
			Log::debug('CodesService.cuis: response', [ 'hasRespuesta' => isset($arr['RespuestaCuis']) ]);
			return $arr;
		} catch (SoapFault $e) {
			Log::error('CodesService.cuis: soap fault', [ 'pv' => $puntoVenta, 'error' => $e->getMessage() ]);
			throw $e;
		} catch (\Throwable $e) {
			Log::error('CodesService.cuis: exception', [ 'pv' => $puntoVenta, 'error' => $e->getMessage() ]);
			throw $e;
		}
	}

	// public function cufd(string $cuis, int $puntoVenta = 0): array
	// {

	// 	$client = SoapClientFactory::build(config('sin.codes_service'));
	// 	$payload = [
	// 		'codigoAmbiente'    => (int) config('sin.ambiente'),
	// 		'codigoModalidad'   => (int) config('sin.modalidad'),
	// 		'codigoPuntoVenta'  => $puntoVenta,
	// 		'codigoSistema'     => (string) config('sin.cod_sistema'),
	// 		'codigoSucursal'    => (int) config('sin.sucursal'),
	// 		'cuis'              => (string) $cuis,
	// 		'nit'               => (int) config('sin.nit'),
	// 	];
	// 	$arg = new \stdClass();
	// 	$arg->SolicitudCufd = (object) $payload;
	// 	Log::info('CodesService.cufd: request', [ 'pv' => $puntoVenta, 'sucursal' => $payload['codigoSucursal'] ]);
	// 	try {
	// 		$result = $client->__soapCall('cufd', [ $arg ]);
	// 		$arr = json_decode(json_encode($result), true);
	// 		Log::debug('CodesService.cufd: response', [ 'hasRespuesta' => isset($arr['RespuestaCufd']) ]);
	// 		return $arr;
	// 	} catch (SoapFault $e) {
	// 		Log::error('CodesService.cufd: soap fault', [ 'pv' => $puntoVenta, 'error' => $e->getMessage() ]);
	// 		throw $e;
	// 	} catch (\Throwable $e) {
	// 		Log::error('CodesService.cufd: exception', [ 'pv' => $puntoVenta, 'error' => $e->getMessage() ]);
	// 		throw $e;
	// 	}
	// }

    public function cuis2(int $codigo_ambiente, int $codigo_sucursal,int $puntoVenta = 0): array
	{
        Log::info('Se esta generando un nuevo cufd tomar en');
		$client = SoapClientFactory::build(config('sin.codes_service'));
		$payload = [
			'codigoAmbiente'    => $codigo_ambiente,
			'codigoModalidad'   => (int) config('sin.modalidad'),
			'codigoPuntoVenta'  => $puntoVenta,
			'codigoSistema'     => (string) config('sin.cod_sistema'),
			'codigoSucursal'    => $codigo_sucursal,
			'nit'               => (int) config('sin.nit'),
		];
		$arg = new \stdClass();
		$arg->SolicitudCuis = (object) $payload;
		Log::info('CodesService.cuis: request', [ 'pv' => $puntoVenta, 'sucursal' => $payload['codigoSucursal'] ]);
		try {
			$result = $client->__soapCall('cuis', [ $arg ]);
			$arr = json_decode(json_encode($result), true);
			Log::debug('CodesService.cuis: response', [ 'hasRespuesta' => isset($arr['RespuestaCuis']) ]);
			return $arr;
		} catch (SoapFault $e) {
			Log::error('CodesService.cuis: soap fault', [ 'pv' => $puntoVenta, 'error' => $e->getMessage() ]);
			throw $e;
		} catch (\Throwable $e) {
			Log::error('CodesService.cuis: exception', [ 'pv' => $puntoVenta, 'error' => $e->getMessage() ]);
			throw $e;
		}
	}

    public function cufd2(int $codigoAmbiente,int $codigoSucursal,string $cuis, int $puntoVenta): array
	{
        Log::info('CodesService.cufd2 zzzz: iniciando la recuperacion del CUFD del SIN para el punto de venta '.$puntoVenta.' y sucursal '.$codigoSucursal);
		$client = SoapClientFactory::build(config('sin.codes_service'));
		$payload = [
			'codigoAmbiente'    => $codigoAmbiente,
			'codigoModalidad'   => (int) config('sin.modalidad'),
			'codigoPuntoVenta'  => $puntoVenta,
			'codigoSistema'     => (string) config('sin.cod_sistema'),
			'codigoSucursal'    => $codigoSucursal,
			'cuis'              => (string) $cuis,
			'nit'               => (int) config('sin.nit'),
		];
		$arg = new \stdClass();
		$arg->SolicitudCufd = (object) $payload;
		Log::info('CodesService.cufd: request', [ 'pv' => $puntoVenta, 'sucursal' => $payload['codigoSucursal'] ]);
		try {
			$result = $client->__soapCall('cufd', [ $arg ]);
            Log::info('CodesService.cufd2: el resultado de la recuperacion del CUFD es:'.print_r($result,true));
			$arr = json_decode(json_encode($result), true);
			Log::debug('CodesService.cufd: response', [ 'hasRespuesta' => isset($arr['RespuestaCufd']) ]);
			return $arr;
		} catch (SoapFault $e) {
			Log::error('CodesService.cufd: soap fault', [ 'pv' => $puntoVenta, 'error' => $e->getMessage() ]);
			throw $e;
		} catch (\Throwable $e) {
			Log::error('CodesService.cufd: exception', [ 'pv' => $puntoVenta, 'error' => $e->getMessage() ]);
			throw $e;
		}
	}
}
