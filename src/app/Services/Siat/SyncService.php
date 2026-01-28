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
        Log::debug('el resultado que llega es jjjjjj:'.print_r($result,true));
		return json_decode(json_encode($result), true);
	}

	public function actividades(string $cuis, int $puntoVenta = 0): array
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
		$result = $client->__soapCall('sincronizarActividades', [ $arg ]);
        Log::debug('el resultado que llega es kkkkk:'.print_r($result,true));
		return json_decode(json_encode($result), true);
	}

	public function leyendasFactura(string $cuis, int $puntoVenta = 0): array
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
		$result = $client->__soapCall('sincronizarListaLeyendasFactura', [ $arg ]);
        Log::debug('el resultado que llega es lllll:'.print_r($result,true));
		return json_decode(json_encode($result), true);
	}

	/**
	 * Llamada genérica para métodos de sincronización paramétrica que responden con RespuestaListaParametricas
	 * Ejemplos de $method:
	 * - sincronizarParametricaTipoPuntoVenta
	 * - sincronizarParametricaEventosSignificativos
	 * - sincronizarParametricaUnidadMedida
	 * - sincronizarParametricaTiposFactura
	 * - sincronizarParametricaTipoDocumentoSector
	 * - sincronizarParametricaMotivoAnulacion
	 * - sincronizarParametricaTipoEmision
	 */
	public function parametrica(string $method, string $cuis, int $puntoVenta = 0): array
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
		$result = $client->__soapCall($method, [ $arg ]);
        Log::debug('el resultado que llega es mmmmm:'.print_r($result,true));
		return json_decode(json_encode($result), true);
	}
}
