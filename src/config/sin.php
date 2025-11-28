<?php

return [
	// Base URLs y servicios
	'url' => env('SIN_URL', 'https://siatrest.impuestos.gob.bo/v2'),
	
	// Servicios SIAT - Cada uno tiene su endpoint específico
	'servicio_facturacion_electronica' => env('SIN_SERVICE_FACTURACION', 'ServicioFacturacionElectronica'),
	'servicio_operaciones' => env('SIN_SERVICE_OPERATIONS', 'FacturacionOperaciones'),
	'servicio_sincronizacion' => env('SIN_SERVICE_SYNC', 'FacturacionSincronizacion'),
	'servicio_codigos' => env('SIN_SERVICE_CODES', 'FacturacionCodigos'),
	'servicio_compras' => env('SIN_SERVICE_COMPRAS', 'ServicioRecepcionCompras'),
	
	// Alias para compatibilidad con código existente
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
	
	// Datos del emisor
	'razon_social' => env('SIN_RAZON_SOCIAL', 'INSTITUTO TECNOLOGICO DE ENSEÑANZA AUTOMOTRIZ "CETA" S.R.L.'),
	'municipio' => env('SIN_MUNICIPIO', 'COCHABAMBA'),
	'telefono' => env('SIN_TELEFONO', '4581736'),
	'actividad_economica' => env('SIN_ACTIVIDAD_ECONOMICA', '853000'),
	'codigo_producto_sin' => (int) env('SIN_CODIGO_PRODUCTO', 99100),
	'codigo_moneda' => (int) env('SIN_CODIGO_MONEDA', 1),
	'tipo_cambio' => (float) env('SIN_TIPO_CAMBIO', 1),

	// Facturación
	'cod_doc_sector' => (int) env('SIN_DOC_SECTOR', 11),
	'tipo_factura' => (int) env('SIN_TIPO_FACTURA', 1),
	'archivo_plain' => filter_var(env('SIN_ARCHIVO_PLAIN', false), FILTER_VALIDATE_BOOL),
	'qr_url' => env('SIN_QR_URL', 'https://pilotosiat.impuestos.gob.bo/consulta/QR'),

	// Operación
	'offline' => filter_var(env('SIN_OFFLINE', false), FILTER_VALIDATE_BOOL),
	// Verbosidad de logs SIAT/CFUD/PDF (request/response, SQL simulado, HTML preview)
	'verbose_log' => filter_var(env('SIN_VERBOSE_LOG', false), FILTER_VALIDATE_BOOL),
];
