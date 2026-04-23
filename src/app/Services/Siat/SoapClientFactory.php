<?php

namespace App\Services\Siat;

use SoapClient;
use SoapFault;

use Illuminate\Support\Facades\Log;

class SoapClientFactory
{
	public static function build($service)
	{
		$url = rtrim(config('sin.url'), '/');
		$wsdl = $url . '/' . ltrim($service, '/') . '?wsdl';

        Log::info('SoapClientFactory.build: building SOAP clientxxx', [
            'wsdl' => $wsdl,
            'sinurl' => config('sin.url'),
            'service' => $service
        ]);

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

		// Pre-descarga el WSDL y sus imports localmente para evitar que SoapClient
        // intente resolver URLs externas al cargar el archivo local.
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siat_wsdl';
		if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
		$local = $dir . DIRECTORY_SEPARATOR . md5($wsdl) . '.wsdl';
        if (!file_exists($local)) {
            $xml = self::downloadWsdl($wsdl);
            // Detecta y descarga WSDLs importados, reescribiendo sus locations a rutas locales
            $xml = self::resolveWsdlImports($xml, $wsdl, $dir);
            file_put_contents($local, $xml);
        }
		return new SoapClient($local, $options);
	}

    /**
     * Descarga un WSDL remoto, decodifica gzip si es necesario y valida que sea XML.
     */
    private static function downloadWsdl(string $url): string
    {
        $ctx = stream_context_create([
            'http' => [
                'header' => "Accept: application/wsdl+xml, text/xml, */*\r\nAccept-Encoding: identity\r\nUser-Agent: PHP-SOAP\r\n",
                'timeout' => 20,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $xml = @file_get_contents($url, false, $ctx);
        if ($xml === false) {
            throw new \RuntimeException("No se pudo descargar WSDL: {$url}");
        }
        if (strpos($xml, '<?xml') !== 0 && function_exists('gzdecode')) {
            $dec = @gzdecode($xml);
            if ($dec !== false && strpos($dec, '<?xml') === 0) {
                $xml = $dec;
            }
        }
        $buf = ltrim($xml, "\xEF\xBB\xBF \t\r\n");
        if (stripos($buf, '<?xml') !== 0 && stripos($buf, '<definitions') !== 0 && stripos($buf, '<wsdl:definitions') !== 0) {
            throw new \RuntimeException("Contenido WSDL no es XML: {$url}");
        }
        return $xml;
    }

    /**
     * Busca <wsdl:import> y <import> con location relativa o absoluta dentro del XML,
     * descarga cada uno, los guarda localmente y reescribe el atributo location
     * para que apunte al archivo local (file://).
     */
    private static function resolveWsdlImports(string $xml, string $baseUrl, string $dir): string
    {
        // Extrae base URL sin query string para resolver rutas relativas
        $parsedBase = parse_url($baseUrl);
        $baseRoot = $parsedBase['scheme'] . '://' . $parsedBase['host']
            . (isset($parsedBase['port']) ? ':' . $parsedBase['port'] : '')
            . (isset($parsedBase['path']) ? dirname($parsedBase['path']) : '');

        // Encuentra todos los atributos location en imports
        $xml = preg_replace_callback(
            '/(<(?:wsdl:)?import\b[^>]*?\s)location=(["\'])([^"\']+)\2/i',
            function (array $m) use ($dir, $baseUrl, $baseRoot) {
                $location = $m[3];

                // Resuelve URL absoluta
                if (strpos($location, 'http') === 0) {
                    $importUrl = $location;
                } elseif (strpos($location, '/') === 0) {
                    $p = parse_url($baseUrl);
                    $importUrl = $p['scheme'] . '://' . $p['host']
                        . (isset($p['port']) ? ':' . $p['port'] : '')
                        . $location;
                } else {
                    // Relativa: puede ser ?wsdl=Algo o ./Algo
                    if (strpos($location, '?') === 0) {
                        $p = parse_url($baseUrl);
                        $importUrl = $p['scheme'] . '://' . $p['host']
                            . (isset($p['port']) ? ':' . $p['port'] : '')
                            . $p['path'] . $location;
                    } else {
                        $importUrl = rtrim($baseRoot, '/') . '/' . $location;
                    }
                }

                $localFile = $dir . DIRECTORY_SEPARATOR . md5($importUrl) . '.wsdl';
                if (!file_exists($localFile)) {
                    try {
                        $importXml = self::downloadWsdl($importUrl);
                        // Resuelve recursivamente imports dentro del import
                        $importXml = self::resolveWsdlImports($importXml, $importUrl, $dir);
                        file_put_contents($localFile, $importXml);
                    } catch (\Throwable $e) {
                        Log::warning('SoapClientFactory: no se pudo descargar WSDL importado', [
                            'url' => $importUrl, 'error' => $e->getMessage(),
                        ]);
                        return $m[0]; // Deja sin cambios si falla
                    }
                }

                return $m[1] . 'location=' . $m[2] . 'file://' . $localFile . $m[2];
            },
            $xml
        );

        return $xml;
    }
}
