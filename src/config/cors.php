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

	// Permitir múltiples orígenes. Si CORS_ALLOW_ALL=true, se permite '*'.
	'allowed_origins' => env('CORS_ALLOW_ALL', false)
		? ['*']
		: array_filter(array_map('trim', explode(',', env('CORS_ALLOWED_ORIGINS', 'http://localhost:4200,http://127.0.0.1:4200,http://192.168.0.74:4200')))),

	// Patrones para facilitar acceso desde IPs de la LAN (ajusta según tu red)
	'allowed_origins_patterns' => [
		// Browser Preview del IDE y localhost con puertos variables
		'#^https?://localhost(:\d+)?$#',
		'#^https?://127\\.0\\.0\\.1(:\d+)?$#',
		// Cualquier IP de la subred 192.168.x.x con puerto variable
		'#^https?://192\\.168\\.\d{1,3}\\.\d{1,3}(:\d+)?$#',
		// IP del host principal con puerto variable
		'#^https?://192\\.168\\.0\\.74(:\d+)?$#',
	],

	'allowed_headers' => ['*'],

	'exposed_headers' => [],

	'max_age' => 0,

	// Si usas cookies/sesiones con credenciales. Si CORS_ALLOW_ALL=true, forzamos false (no se permite '*' con credenciales)
	'supports_credentials' => env('CORS_ALLOW_ALL', false) ? false : env('CORS_SUPPORTS_CREDENTIALS', true),
];
