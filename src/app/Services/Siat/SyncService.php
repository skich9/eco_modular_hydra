<?php

namespace App\Services\Siat;

class SyncService
{
	public function tipoDocumentoIdentidad(string $cuis, int $puntoVenta = 0): array
	{
		$client = SoapClientFactory::build(config('sin.sync_service'));

		$payload = [
			'codigoAmbiente'   => (int) config('sin.ambiente'),
			'codigoPuntoVenta' => $puntoVenta,
			'codigoSistema'    => (string) config('sin.cod_sistema'),
			'codigoSucursal'   => (int) config('sin.sucursal'),
			'cuis'             => (string) $cuis,
			'nit'              => (int) config('sin.nit'),
		];

		$arg = new \stdClass();
		$arg->SolicitudSincronizacion = (object) $payload;
		$result = $client->__soapCall('sincronizarParametricaTipoDocumentoIdentidad', [ $arg ]);
		return json_decode(json_encode($result), true);
	}
}
