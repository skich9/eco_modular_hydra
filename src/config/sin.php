<?php

return [
	// Base URLs y servicios
	'url' => env('SIN_URL', 'https://siatrest.impuestos.gob.bo/v2'),
	'codes_service' => env('SIN_SERVICE_CODES', 'FacturacionCodigos'),
	'sync_service' => env('SIN_SERVICE_SYNC', 'FacturacionSincronizacion'),
	'operations_service' => env('SIN_SERVICE_OPERATIONS', 'FacturacionOperaciones'),

	// Credenciales y parámetros
	'nit' => env('SIN_NIT'),
	'cod_sistema' => env('SIN_COD_SISTEMA'),
	'ambiente' => (int) env('SIN_AMBIENTE', 2),
	'modalidad' => (int) env('SIN_MODALIDAD', 1),
	'sucursal' => (int) env('SIN_SUCURSAL', 0),
	'api_key' => env('SIN_APIKEY'),

	// Facturación
	'cod_doc_sector' => (int) env('SIN_DOC_SECTOR', 11),
	'tipo_factura' => (int) env('SIN_TIPO_FACTURA', 1),

	// Operación
	'offline' => filter_var(env('SIN_OFFLINE', false), FILTER_VALIDATE_BOOL),
];
