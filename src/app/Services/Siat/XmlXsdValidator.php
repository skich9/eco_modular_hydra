<?php

namespace App\Services\Siat;

use DOMDocument;
use Illuminate\Support\Facades\Log;

class XmlXsdValidator
{
	/** @var DOMDocument */
	private $doc;
	/** @var array<int, \LibXMLError>|null */
	private $errors = null;

	public function __construct()
	{
		$this->doc = new DOMDocument('1.0', 'UTF-8');
	}

	public function validar($fileXml, $xsdPath)
	{
		if (!is_file($fileXml) || !is_file($xsdPath)) {
			Log::error('XmlXsdValidator.filesMissing', [
				'xml' => $fileXml,
				'xsd' => $xsdPath,
			]);
			return false;
		}

		libxml_use_internal_errors(true);
		$this->errors = null;

		$contents = @file_get_contents($fileXml);
		if ($contents === false || $contents === '') {
			Log::error('XmlXsdValidator.emptyXml', [ 'xml' => $fileXml ]);
			return false;
		}

		if (!$this->doc->loadXML($contents)) {
			$this->errors = libxml_get_errors();
			Log::error('XmlXsdValidator.loadXmlFailed', [ 'xml' => $fileXml ]);
			return false;
		}

		if (!$this->doc->schemaValidate($xsdPath)) {
			$this->errors = libxml_get_errors();
			Log::error('XmlXsdValidator.schemaValidateFailed', [
				'xml' => $fileXml,
				'xsd' => $xsdPath,
			]);
			return false;
		}

		// Éxito
		libxml_clear_errors();
		return true;
	}

	public function mostrarError()
	{
		if ($this->errors === null || $this->errors === []) {
			return '';
		}

		$msg = '';
		foreach ($this->errors as $error) {
			$nivel = 'Unknown';
			switch ($error->level) {
				case LIBXML_ERR_WARNING:
					$nivel = 'Warning';
					break;
				case LIBXML_ERR_ERROR:
					$nivel = 'Error';
					break;
				case LIBXML_ERR_FATAL:
					$nivel = 'Fatal Error';
					break;
			}
			$msg .= "Error {$error->code} [{$nivel}]" . PHP_EOL
				. "  Linea: {$error->line}" . PHP_EOL
				. "  Mensaje: {$error->message}" . PHP_EOL;
		}
		libxml_clear_errors();
		return $msg;
	}
}
