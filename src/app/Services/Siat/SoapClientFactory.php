<?php

namespace App\Services\Siat;

use SoapClient;
use SoapFault;

class SoapClientFactory
{
	public static function build(string $service): SoapClient
	{
		$url = rtrim(config('sin.url'), '/');
		$wsdl = $url . '/' . ltrim($service, '/') . '?wsdl';
		$apiKey = (string) config('sin.api_key');
		if ($apiKey === '') {
			throw new \RuntimeException('SIN_APIKEY no configurado en .env');
		}

		// Fallback por si la extensión SOAP no define la constante
		if (!\defined('WSDL_CACHE_NONE')) {
			\define('WSDL_CACHE_NONE', 0);
		}

		$options = [
			'trace' => true,
			'exceptions' => true,
			'cache_wsdl' => WSDL_CACHE_NONE,
			'stream_context' => stream_context_create([
				'http' => [
					'header' => "apikey: {$apiKey}\r\n",
				],
			]),
		];

		return new SoapClient($wsdl, $options);
	}
}
