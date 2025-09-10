<?php

namespace App\Services\Siat;

use SoapFault;

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
		$result = $client->__soapCall('cuis', [ $arg ]);
		return json_decode(json_encode($result), true);
	}

	public function cufd(string $cuis, int $puntoVenta = 0): array
	{
		$client = SoapClientFactory::build(config('sin.codes_service'));

		$payload = [
			'codigoAmbiente'    => (int) config('sin.ambiente'),
			'codigoModalidad'   => (int) config('sin.modalidad'),
			'codigoPuntoVenta'  => $puntoVenta,
			'codigoSistema'     => (string) config('sin.cod_sistema'),
			'codigoSucursal'    => (int) config('sin.sucursal'),
			'cuis'              => (string) $cuis,
			'nit'               => (int) config('sin.nit'),
		];

		$arg = new \stdClass();
		$arg->SolicitudCufd = (object) $payload;
		$result = $client->__soapCall('cufd', [ $arg ]);
		return json_decode(json_encode($result), true);
	}
}
