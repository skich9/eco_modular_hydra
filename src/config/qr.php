<?php

return [
	'api_key' => env('QR_API_KEY'),
	'api_key_servicio' => env('QR_API_KEY_SERVICIO'),
	'username' => env('QR_USER_NAME'),
	'password' => env('QR_PASSWORD'),
	'url_auth' => env('QR_URL_AUTH'),
	'url_transfer' => env('QR_URL_TRANSFER'),
	'environment' => env('QR_ENVIRONMENT', 'development'),
	'force_dummy' => env('QR_FORCE_DUMMY', false),
	'callback_base' => env('QR_CALLBACK_BASE', env('APP_URL')), 
	'socket_url' => env('QR_SOCKET_URL'),
	'accounts' => array_filter(array_map('trim', explode(',', env('QR_ACCOUNTS', '')))),
    'callback_basic_user' => env('QR_CB_BASIC_USER'),
    'callback_basic_pass' => env('QR_CB_BASIC_PASS'),
    'forma_cobro_id' => env('QR_FORMA_COBRO_ID', 'B'),

    // HTTP client options
    'http_timeout' => env('QR_HTTP_TIMEOUT', 30),
    'http_connect_timeout' => env('QR_HTTP_CONNECT_TIMEOUT', 15),
    'http_verify_ssl' => env('QR_HTTP_VERIFY_SSL', true),
    'http_force_ipv4' => env('QR_HTTP_FORCE_IPV4', false),
    'http_proxy' => env('QR_HTTP_PROXY'),
];
