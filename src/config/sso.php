<?php

return [
	'enabled' => env('SSO_ENABLED', false),
	'shared_secret' => env('SSO_SHARED_SECRET'),
	'ttl_seconds' => (int) env('SSO_TTL_SECONDS', 300),
	'require_password_md5' => env('SSO_REQUIRE_PASSWORD_MD5', false),
	'allowed_ips' => array_values(array_filter(array_map(function ($ip) {
		return trim($ip);
	}, explode(',', (string) env('SSO_ALLOWED_IPS', ''))))),
	'default_role_id' => env('SSO_DEFAULT_ROLE_ID'),
	'auto_provision' => env('SSO_AUTO_PROVISION', true),

	// Logout sincronizado: cuando el usuario cierra sesión en Económico,
	// se notifica a SGA para limpiar su token SSO local.
	// Endpoint SGA: POST /login/api_logout_sync
	'logout_sync_url' => env('SSO_SGA_LOGOUT_SYNC_URL', ''),
	'logout_sync_token' => env('SGA_WEBHOOK_TOKEN', ''),
	'logout_sync_timeout' => (int) env('SSO_LOGOUT_SYNC_TIMEOUT', 5),
	// Hosts alternativos cuando la URL usa localhost y Económico corre en Docker.
	'logout_sync_fallback_hosts' => array_values(array_filter(array_map(function ($host) {
		return trim($host);
	}, explode(',', (string) env('SSO_LOGOUT_SYNC_FALLBACK_HOSTS', 'host.docker.internal'))))),
];