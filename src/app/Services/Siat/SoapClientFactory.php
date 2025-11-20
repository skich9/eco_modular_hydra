<?php

namespace App\Services\Siat;

use SoapClient;
use SoapFault;

class SoapClientFactory
{
	public static function build($service)
	{
		$url = rtrim(config('sin.url'), '/');
		$wsdl = $url . '/' . ltrim($service, '/') . '?wsdl';
		$apiKey = (string) config('sin.api_key');
		// Normaliza API key quitando comillas o espacios provenientes del .env
		$apiKey = trim($apiKey, "\"' \t\n\r\0\x0B");
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
			'compression' => (\defined('SOAP_COMPRESSION_ACCEPT') ? SOAP_COMPRESSION_ACCEPT : 0) | (\defined('SOAP_COMPRESSION_GZIP') ? SOAP_COMPRESSION_GZIP : 0),
			'connection_timeout' => 20,
			'stream_context' => stream_context_create([
				'http' => [
					'header' => "apikey: {$apiKey}\r\nAccept-Encoding: identity\r\nUser-Agent: PHP-SOAP\r\n",
					'timeout' => 20,
				],
				'ssl' => [
					'verify_peer' => true,
					'verify_peer_name' => true,
				],
			]),
		];

		// Siempre pre-descarga el WSDL con header apikey y lo usa localmente
		$headers = "apikey: {$apiKey}\r\nAccept: application/wsdl+xml, text/xml, */*\r\nAccept-Encoding: identity\r\nUser-Agent: PHP-SOAP\r\n";
		$ctx = stream_context_create([
			'http' => [
				'header' => $headers,
				'timeout' => 20,
			],
			'ssl' => [
				'verify_peer' => true,
				'verify_peer_name' => true,
			],
		]);
		$xml = @file_get_contents($wsdl, false, $ctx);
		if ($xml === false) { throw new \RuntimeException('No se pudo descargar WSDL'); }
		if (strpos($xml, '<?xml') !== 0 && function_exists('gzdecode')) {
			$dec = @gzdecode($xml);
			if ($dec !== false && strpos($dec, '<?xml') === 0) { $xml = $dec; }
		}
		// Quitar BOM y espacios iniciales y validar que parezca XML/WSDL
		$buf = ltrim($xml, "\xEF\xBB\xBF \t\r\n");
		$startsXml = (stripos($buf, '<?xml') === 0);
		$startsWsdl = (stripos($buf, '<definitions') === 0) || (stripos($buf, '<wsdl:definitions') === 0);
		if (!$startsXml && !$startsWsdl) { throw new \RuntimeException('Contenido WSDL no es XML'); }
		$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siat_wsdl';
		if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
		$local = $dir . DIRECTORY_SEPARATOR . md5($wsdl) . '.wsdl';
		file_put_contents($local, $xml);
		return new SoapClient($local, $options);
	}
}
