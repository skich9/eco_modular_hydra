<?php

namespace App\Services\Siat;

use DOMDocument;
use Illuminate\Support\Facades\Log;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;

class FirmaDigital
{
	public function __construct()
	{
	}

	/**
	 * Firma una factura XML replicando la lógica del SGA.
	 *
	 * $nombreFactura: identificador sin extensión (ej: CUF o numero_factura)
	 * $xml: contenido XML sin firma.
	 *
	 * Retorna array con rutas:
	 *   ['origen' => path_xml_sin_firma, 'firmado' => path_xml_firmado]
	 */
	public function firmarFactura($nombreFactura, $xml)
	{
		$baseStorage = storage_path('siat_xml');
		$origenDir = $baseStorage . DIRECTORY_SEPARATOR . 'xmls';
		$firmadoDir = $baseStorage . DIRECTORY_SEPARATOR . 'firmado';

		if (!is_dir($origenDir)) {
			@mkdir($origenDir, 0775, true);
		}
		if (!is_dir($firmadoDir)) {
			@mkdir($firmadoDir, 0775, true);
		}

		$origenPath = $origenDir . DIRECTORY_SEPARATOR . $nombreFactura . '.xml';
		$firmadoPath = $firmadoDir . DIRECTORY_SEPARATOR . $nombreFactura . '.xml';

		try {
			// 1) Guardar XML sin firma en origen
			file_put_contents($origenPath, $xml);

			$doc = new DOMDocument();
			$doc->preserveWhiteSpace = false;
			$doc->formatOutput = true;
			$doc->load($origenPath);

			// 2) Rutas a llaves y certificados
			// En tu proyecto están en: base_path('certificados')
			$rutaKeys = base_path('certificados');
			$privateKeyPath = $rutaKeys . DIRECTORY_SEPARATOR . 'private.pem';
			$certPath = $rutaKeys . DIRECTORY_SEPARATOR . 'mycertificado.pem';

			// 3) Crear objeto de firma
			$objDSig = new XMLSecurityDSig("");
			$objDSig->setCanonicalMethod(XMLSecurityDSig::EXC_C14N);

			// 4) Agregar referencia al documento completo
			$objDSig->addReference(
				$doc,
				XMLSecurityDSig::SHA256,
				['http://www.w3.org/2000/09/xmldsig#enveloped-signature'],
				['force_uri' => true]
			);

			// 5) Cargar clave privada (RSA SHA256)
			$objKey = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'private']);
			$objKey->loadKey($privateKeyPath, true);

			// 6) Firmar
			$objDSig->sign($objKey);

			// 7) Adjuntar certificado X.509
			$certData = @file_get_contents($certPath);
			if ($certData !== false) {
				$objDSig->add509Cert($certData);
			}

			// 8) Insertar firma en el XML (enveloped) y guardar en carpeta firmado
			$objDSig->appendSignature($doc->documentElement);
			$doc->save($firmadoPath);

			Log::debug('FirmaDigital.firmarFactura', [
				'nombre' => $nombreFactura,
				'origen' => $origenPath,
				'firmado' => $firmadoPath,
			]);
		} catch (\Throwable $e) {
			Log::error('FirmaDigital.firmarFactura error', [
				'nombre' => $nombreFactura,
				'error' => $e->getMessage(),
			]);
		}

		return [
			'origen' => $origenPath,
			'firmado' => $firmadoPath,
		];
	}

	/**
	 * Verifica la firma digital de un XML firmado usando el certificado embebido.
	 *
	 * $xmlPath: ruta absoluta al XML firmado.
	 *
	 * Retorna true si la firma es válida, false en caso contrario.
	 */
	public function verificarFirma($xmlPath)
	{
		$result = false;
		try {
			if (!is_file($xmlPath)) {
				Log::warning('FirmaDigital.verificarFirma archivo no existe', [ 'xml' => $xmlPath ]);
				return false;
			}
			$doc = new DOMDocument();
			$doc->preserveWhiteSpace = false;
			$doc->formatOutput = false;
			if (!$doc->load($xmlPath)) {
				Log::warning('FirmaDigital.verificarFirma no pudo cargar XML', [ 'xml' => $xmlPath ]);
				return false;
			}
			$objDSig = new XMLSecurityDSig();
			$signatureNode = $objDSig->locateSignature($doc);
			if (!$signatureNode) {
				Log::warning('FirmaDigital.verificarFirma sin nodo Signature', [ 'xml' => $xmlPath ]);
				return false;
			}
			try {
				$objDSig->canonicalizeSignedInfo();
			} catch (\Throwable $e) {
				Log::warning('FirmaDigital.verificarFirma canonicalize error', [ 'xml' => $xmlPath, 'error' => $e->getMessage() ]);
			}
			// Usar siempre el certificado local (mycertificado.pem) para verificar
			$rutaKeys = base_path('certificados');
			$certPath = $rutaKeys . DIRECTORY_SEPARATOR . 'mycertificado.pem';
			if (!is_file($certPath)) {
				Log::warning('FirmaDigital.verificarFirma cert no encontrado', [ 'cert' => $certPath ]);
				return false;
			}
			$key = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'public']);
			$key->loadKey($certPath, true, true);
			$vr = $objDSig->verify($key);
			$result = ($vr === 1);
			Log::debug('FirmaDigital.verificarFirma resultado', [
				'xml' => $xmlPath,
				'ok' => $result,
				'raw' => $vr,
			]);
		} catch (\Throwable $e) {
			Log::error('FirmaDigital.verificarFirma error', [ 'xml' => $xmlPath, 'error' => $e->getMessage() ]);
		}
		return $result;
	}
}
