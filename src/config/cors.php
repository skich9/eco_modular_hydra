<?php

return [
	/*
	|--------------------------------------------------------------------------
	| Cross-Origin Resource Sharing (CORS) Configuration
	|--------------------------------------------------------------------------
	|
	| Here you may configure your settings for cross-origin resource sharing
	| or "CORS". This determines what cross-origin operations may execute
	| in web browsers. You are free to adjust these settings as needed.
	|
	| To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
	|
	*/

	'paths' => ['api/*', 'sanctum/csrf-cookie'],

	'allowed_methods' => ['*'],

	// Permitir múltiples orígenes. Puede configurarse vía .env: CORS_ALLOWED_ORIGINS="http://localhost:4200,http://127.0.0.1:4200,http://192.168.0.74:4200"
	'allowed_origins' => array_filter(array_map('trim', explode(',', env('CORS_ALLOWED_ORIGINS', 'http://localhost:4200,http://127.0.0.1:4200,http://192.168.0.74:4200')))),

	// Patrones para facilitar acceso desde IPs de la LAN (ajusta según tu red)
	'allowed_origins_patterns' => [
		'#^http://192\\.168\\.\d{1,3}\\.\d{1,3}(:\d+)?$#',
	],

	'allowed_headers' => ['*'],

	'exposed_headers' => [],

	'max_age' => 0,

	// Si usas cookies/sesiones/autenticación con credenciales en CORS
	'supports_credentials' => true,
];
