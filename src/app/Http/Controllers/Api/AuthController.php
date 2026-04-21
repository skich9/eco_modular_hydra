<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Rol;
use App\Models\Usuario;
use App\Services\PermissionService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AuthController extends Controller
{
	/**
	 * Login API para Angular
	 */
	public function login(Request $request)
	{
		try {
			$validator = Validator::make($request->all(), [
				'nickname' => 'required|string',
				'contrasenia' => 'required|string'
			], [
				'nickname.required' => 'El usuario es obligatorio',
				'contrasenia.required' => 'La contraseña es obligatoria'
			]);

			if ($validator->fails()) {
				return response()->json([
					'success' => false,
					'message' => 'Datos de entrada inválidos',
					'errors' => $validator->errors()
				], 422);
			}

			// nickname: comparación exacta (sensible a mayúsculas/minúsculas) vía BINARY frente a collations _ci.
			// ci: comparación exacta al valor enviado en el mismo campo de login.
			$inputLogin = trim((string) $request->nickname);

			$candidatos = Usuario::with('rol')
				->where('estado', true)
				->where(function ($query) use ($inputLogin) {
					$query->whereRaw('BINARY `usuarios`.`nickname` = ?', [$inputLogin])
						->orWhere('ci', $inputLogin);
				})
				->get();

			$usuario = $candidatos->first(function ($u) use ($inputLogin) {
				$ci = isset($u->ci) ? (string) $u->ci : '';
				return (string) $u->nickname === $inputLogin
					|| $ci === $inputLogin;
			});

			if (!$usuario) {
				return response()->json([
					'success' => false,
					'message' => 'Las credenciales no coinciden con nuestros registros.'
				], 401);
			}

			// Verificar contraseña
			if (!Hash::check($request->contrasenia, $usuario->contrasenia)) {
				return response()->json([
					'success' => false,
					'message' => 'Las credenciales no coinciden con nuestros registros.'
				], 401);
			}

			// Verificar que el rol esté activo
			if (!$usuario->rol || !$usuario->rol->estado) {
				return response()->json([
					'success' => false,
					'message' => 'Su rol no está activo. Contacte al administrador.'
				], 403);
			}

			return $this->issueTokenResponse($usuario, 'Login exitoso', 'auth_token');

		} catch (\Exception $e) {
			return response()->json([
				'success' => false,
				'message' => 'Error interno del servidor'
			], 500);
		}
	}

	/**
	 * Logout API
	 */
	public function logout(Request $request)
	{
		$usuario = $request->user();
		$nickname = $usuario ? (string) $usuario->nickname : '';

		// Eliminar el token Sanctum actual
		$request->user()->currentAccessToken()->delete();

		// Notificar a SGA para limpiar el sidecar SSO del usuario (fire-and-forget)
		$this->notifySgaLogout($nickname);

		return response()->json([
			'success' => true,
			'message' => 'Sesión cerrada correctamente'
		]);
	}

	/**
	 * Llama al endpoint POST /login/api_logout_sync de SGA para que limpie
	 * el token SSO local del usuario. La llamada es fire-and-forget:
	 * si SGA no responde o devuelve error, el logout en Económico ya ocurrió
	 * y solo se registra el fallo en logs sin afectar al usuario.
	 */
	private function notifySgaLogout(string $nickname)
	{
		$syncUrl = (string) config('sso.logout_sync_url', '');
		$syncTokenRaw = (string) config('sso.logout_sync_token', '');
		$syncToken = trim($syncTokenRaw);
		$ssoSharedSecret = trim((string) config('sso.shared_secret', ''));
		$extraTokens = config('sso.logout_sync_extra_tokens', []);
		if (!is_array($extraTokens)) {
			$extraTokens = [];
		}
		$tokenCandidates = [];
		if ($syncToken !== '') {
			$tokenCandidates[] = $syncToken;
		}
		if ($ssoSharedSecret !== '' && !in_array($ssoSharedSecret, $tokenCandidates, true)) {
			$tokenCandidates[] = $ssoSharedSecret;
		}
		foreach ($extraTokens as $extraToken) {
			$extraToken = trim((string) $extraToken);
			if ($extraToken !== '' && !in_array($extraToken, $tokenCandidates, true)) {
				$tokenCandidates[] = $extraToken;
			}
		}
		if (empty($tokenCandidates)) {
			$tokenCandidates = [''];
		}
		$timeout = (int) config('sso.logout_sync_timeout', 5);
		$fallbackHosts = config('sso.logout_sync_fallback_hosts', ['host.docker.internal']);
		if (!is_array($fallbackHosts)) {
			$fallbackHosts = ['host.docker.internal'];
		}
		$urlParts = parse_url($syncUrl);
		$host = isset($urlParts['host']) ? strtolower((string) $urlParts['host']) : '';
		$isLocalhostHost = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
		$attemptUrls = [$syncUrl];
		if ($isLocalhostHost && !empty($fallbackHosts)) {
			foreach ($fallbackHosts as $fallbackHost) {
				$fallbackHost = trim((string) $fallbackHost);
				if ($fallbackHost === '' || strtolower($fallbackHost) === $host) {
					continue;
				}

				$attemptUrl = $this->replaceUrlHost($syncUrl, $fallbackHost);
				if ($attemptUrl !== '' && !in_array($attemptUrl, $attemptUrls, true)) {
					$attemptUrls[] = $attemptUrl;
				}
			}
		}
		$syncContext = [
			'nickname' => $nickname,
			'sync_url' => $syncUrl,
			'scheme' => isset($urlParts['scheme']) ? $urlParts['scheme'] : null,
			'host' => isset($urlParts['host']) ? $urlParts['host'] : null,
			'port' => isset($urlParts['port']) ? $urlParts['port'] : null,
			'path' => isset($urlParts['path']) ? $urlParts['path'] : null,
			'timeout_seconds' => $timeout,
			'has_sync_token' => $syncToken !== '',
			'sync_token_length' => strlen($syncToken),
			'sync_token_was_trimmed' => $syncTokenRaw !== $syncToken,
			'sync_token_fingerprint' => $syncToken !== '' ? substr(hash('sha256', $syncToken), 0, 12) : null,
			'token_candidates_count' => count($tokenCandidates),
			'token_candidate_fingerprints' => array_map(function ($t) {
				return $t !== '' ? substr(hash('sha256', $t), 0, 12) : null;
			}, $tokenCandidates),
			'is_localhost_host' => $isLocalhostHost,
			'fallback_hosts' => $fallbackHosts,
			'attempt_urls' => $attemptUrls,
		];

		if ($syncUrl === '' || $timeout <= 0) {
			Log::info('[SSO Logout Sync] Omitido por configuración.', $syncContext + [
				'reason' => $syncUrl === '' ? 'sync_url_empty' : 'timeout_disabled',
			]);
			return;
		}

		if ($nickname === '') {
			Log::warning('[SSO Logout Sync] Nickname vacío, no se puede notificar a SGA.', $syncContext);
			return;
		}

		if ($syncToken === '') {
			Log::warning('[SSO Logout Sync] Token vacío. SGA puede rechazar la llamada.', $syncContext);
		}

		Log::info('[SSO Logout Sync] Iniciando llamada a SGA.', $syncContext + [
			'method' => 'POST',
			'endpoint_expected' => '/login/api_logout_sync',
			'payload_keys' => ['nickname'],
			'total_attempts' => count($attemptUrls),
		]);

		$lastError = null;
		$lastHttpError = null;
		$attemptIndex = 0;
		foreach ($attemptUrls as $attemptUrl) {
			$attemptParts = parse_url($attemptUrl);
			$attemptHost = isset($attemptParts['host']) ? $attemptParts['host'] : null;
			foreach ($tokenCandidates as $tokenCandidateIndex => $tokenCandidate) {
				$attemptIndex++;
				$tokenFingerprint = $tokenCandidate !== '' ? substr(hash('sha256', $tokenCandidate), 0, 12) : null;
				try {
					Log::info('[SSO Logout Sync] Intentando endpoint.', [
						'nickname' => $nickname,
						'attempt' => $attemptIndex,
						'total_attempts' => count($attemptUrls) * count($tokenCandidates),
						'attempt_url' => $attemptUrl,
						'attempt_host' => $attemptHost,
						'token_candidate_index' => $tokenCandidateIndex,
						'token_fingerprint' => $tokenFingerprint,
					]);

					$requestPayload = [
						'nickname' => $nickname,
						'token' => $tokenCandidate,
						'sga_token' => $tokenCandidate,
						'sso_secret' => $tokenCandidate,
						'origen' => 'economico_logout_sync',
					];

					$requestHeaders = [
						'X-SGA-Token' => $tokenCandidate,
						'X-SSO-Secret' => $tokenCandidate,
						'X-Webhook-Token' => $tokenCandidate,
						'Authorization' => 'Bearer ' . $tokenCandidate,
						'Accept' => 'application/json,text/html;q=0.9,*/*;q=0.8',
					];

					$startedAt = microtime(true);
					$response = Http::timeout($timeout)
						->withHeaders($requestHeaders)
						->withQueryParameters([
							'nickname' => $nickname,
						])
						->post($attemptUrl, $requestPayload);
					$elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);
					$responseBody = (string) $response->body();
					$responseJson = null;
					try {
						$responseJson = $response->json();
					} catch (\Throwable $ignoreJsonError) {
						$responseJson = null;
					}

					// Compatibilidad CI3: si devuelve missing_nickname, reintentar como form-urlencoded
					$mustRetryAsForm = false;
					if (!$response->successful()) {
						if (is_array($responseJson) && isset($responseJson['msg']) && $responseJson['msg'] === 'missing_nickname') {
							$mustRetryAsForm = true;
						} elseif (strpos($responseBody, 'missing_nickname') !== false) {
							$mustRetryAsForm = true;
						}
					}

					if ($mustRetryAsForm) {
						Log::info('[SSO Logout Sync] Reintentando con form-urlencoded por missing_nickname.', [
							'nickname' => $nickname,
							'attempt' => $attemptIndex,
							'attempt_url' => $attemptUrl,
							'token_fingerprint' => $tokenFingerprint,
						]);

						$startedAt = microtime(true);
						$response = Http::asForm()
						->timeout($timeout)
							->withHeaders($requestHeaders)
							->withQueryParameters([
								'nickname' => $nickname,
							])
							->post($attemptUrl, $requestPayload);
						$elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);
						$responseBody = (string) $response->body();
						$responseJson = null;
						try {
							$responseJson = $response->json();
						} catch (\Throwable $ignoreJsonError) {
							$responseJson = null;
						}
					}

					if ($response->successful()) {
						$responseOk = (is_array($responseJson) && isset($responseJson['ok'])) ? $responseJson['ok'] : null;
						$responseMsg = (is_array($responseJson) && isset($responseJson['msg'])) ? $responseJson['msg'] : null;
						Log::info('[SSO Logout Sync] SGA notificado correctamente.', [
							'nickname' => $nickname,
							'attempt' => $attemptIndex,
							'attempt_url' => $attemptUrl,
							'token_fingerprint' => $tokenFingerprint,
							'status' => $response->status(),
							'elapsed_ms' => $elapsedMs,
							'response_ok' => $responseOk,
							'response_msg' => $responseMsg,
						]);
						return;
					}

					Log::warning('[SSO Logout Sync] SGA respondió con error HTTP.', [
						'nickname' => $nickname,
						'attempt' => $attemptIndex,
						'attempt_url' => $attemptUrl,
						'token_fingerprint' => $tokenFingerprint,
						'status' => $response->status(),
						'elapsed_ms' => $elapsedMs,
						'response_json' => $responseJson,
						'body' => mb_substr($responseBody, 0, 600),
						'note' => 'Se continuará con otros tokens/hosts fallback si existen.',
					]);

					$lastHttpError = [
						'attempt' => $attemptIndex,
						'attempt_url' => $attemptUrl,
						'token_fingerprint' => $tokenFingerprint,
						'status' => $response->status(),
						'body' => mb_substr($responseBody, 0, 600),
						'response_json' => $responseJson,
					];
					continue;
				} catch (ConnectionException $e) {
					$lastError = $e;
					Log::error('[SSO Logout Sync] Error de conexión en intento.', [
						'nickname' => $nickname,
						'attempt' => $attemptIndex,
						'total_attempts' => count($attemptUrls) * count($tokenCandidates),
						'attempt_url' => $attemptUrl,
						'token_fingerprint' => $tokenFingerprint,
						'error' => $e->getMessage(),
					]);
					continue;
				} catch (\Throwable $e) {
					Log::error('[SSO Logout Sync] Excepción no controlada en intento.', [
						'nickname' => $nickname,
						'attempt' => $attemptIndex,
						'attempt_url' => $attemptUrl,
						'token_fingerprint' => $tokenFingerprint,
						'exception_class' => get_class($e),
						'error' => $e->getMessage(),
					]);
					$lastError = $e;
					continue;
				}
			}
		}

		// Nunca bloquear el logout del usuario por fallos de conexión con SGA
		Log::error('[SSO Logout Sync] Ningún intento logró sincronizar logout con SGA.', [
			'nickname' => $nickname,
			'total_attempts' => count($attemptUrls) * count($tokenCandidates),
			'attempt_urls' => $attemptUrls,
			'token_candidate_fingerprints' => array_map(function ($t) {
				return $t !== '' ? substr(hash('sha256', $t), 0, 12) : null;
			}, $tokenCandidates),
			'last_error' => $lastError ? $lastError->getMessage() : null,
			'last_http_error' => $lastHttpError,
			'diagnostic_hint' => $isLocalhostHost
				? 'Económico corre en Docker: configure SSO_SGA_LOGOUT_SYNC_URL con host.docker.internal o agregue fallback hosts válidos.'
				: 'Verifique que el host y puerto de SGA sean accesibles desde el contenedor de Económico.',
		]);
	}

	private function replaceUrlHost($url, $newHost)
	{
		$parts = parse_url((string) $url);
		if (!is_array($parts)) {
			return '';
		}

		$scheme = isset($parts['scheme']) ? $parts['scheme'] : 'http';
		$port = isset($parts['port']) ? ':' . $parts['port'] : '';
		$path = isset($parts['path']) ? $parts['path'] : '';
		$query = isset($parts['query']) ? '?' . $parts['query'] : '';
		$fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

		if ($path === '') {
			$path = '/';
		}

		return $scheme . '://' . $newHost . $port . $path . $query . $fragment;
	}

	/**
	 * Genera un token Sanctum para un usuario autenticado en SGA.
	 */
	public function exchangeSsoToken(Request $request)
	{
		if (!config('sso.enabled', false)) {
			return response()->json([
				'success' => false,
				'message' => 'Integración SSO deshabilitada.'
			], 403);
		}

		$sharedSecret = (string) config('sso.shared_secret', '');
		if ($sharedSecret === '') {
			Log::error('SSO exchange rechazado: shared secret no configurado.');
			return response()->json([
				'success' => false,
				'message' => 'Configuración SSO incompleta.'
			], 500);
		}

		if (!$this->hasValidSsoSecret($request, $sharedSecret)) {
			Log::warning('SSO exchange rechazado: secreto inválido.', [
				'ip' => $request->ip(),
				'origen' => $request->input('origen'),
			]);

			return response()->json([
				'success' => false,
				'message' => 'No autorizado para solicitar tokens SSO.'
			], 401);
		}

		if (!$this->hasValidSsoTimestamp($request)) {
			Log::warning('SSO exchange rechazado: timestamp inválido o expirado.', [
				'ip' => $request->ip(),
				'timestamp' => $request->header('X-SSO-Timestamp'),
			]);

			return response()->json([
				'success' => false,
				'message' => 'Solicitud SSO expirada o inválida.'
			], 401);
		}

		if (!$this->isAllowedSsoIp($request)) {
			Log::warning('SSO exchange rechazado: IP no permitida.', [
				'ip' => $request->ip(),
			]);

			return response()->json([
				'success' => false,
				'message' => 'IP de origen no permitida para SSO.'
			], 403);
		}

		$validator = Validator::make($request->all(), [
			'nickname' => 'nullable|string|max:40|required_without:ci',
			'ci' => 'nullable|string|max:25|required_without:nickname',
			'nombre' => 'nullable|string|max:30',
			'ap_paterno' => 'nullable|string|max:40',
			'ap_materno' => 'nullable|string|max:40',
			'origen' => 'required|string|max:50',
			'id_usuario_sga' => 'nullable|string|max:50',
			'cod_ceta' => 'nullable|string|max:30',
			'password' => 'nullable|string|size:32',
		], [
			'nickname.required_without' => 'Debe enviar nickname o ci.',
			'ci.required_without' => 'Debe enviar nickname o ci.',
			'origen.required' => 'El origen de la autenticación es obligatorio.',
			'password.size' => 'El hash MD5 de contraseña debe tener 32 caracteres.',
		]);

		if ($validator->fails()) {
			return response()->json([
				'success' => false,
				'message' => 'Datos de entrada inválidos',
				'errors' => $validator->errors(),
			], 422);
		}

		try {
			$passwordRecibido = strtolower(trim((string) $request->input('password', '')));
			$passwordFueEnviado = $passwordRecibido !== '';

			// Si el flag require_password_md5 está activo y NO se envió password: rechazar
			if ($this->requiresSsoPasswordMd5() && !$passwordFueEnviado) {
				Log::warning('SSO exchange rechazado: password MD5 requerido pero no fue enviado.', [
					'ip' => $request->ip(),
					'origen' => $request->input('origen'),
					'nickname' => $request->input('nickname'),
				]);
				return response()->json([
					'success' => false,
					'message' => 'Debe enviar un hash MD5 de contraseña válido para SSO.',
				], 422);
			}

			// Si se envió password pero no tiene formato MD5 válido (32 hex): rechazar
			if ($passwordFueEnviado && !preg_match('/^[a-f0-9]{32}$/', $passwordRecibido)) {
				Log::warning('SSO exchange rechazado: password enviado pero no tiene formato MD5 válido.', [
					'ip' => $request->ip(),
					'origen' => $request->input('origen'),
					'nickname' => $request->input('nickname'),
					'password_len' => strlen($passwordRecibido),
				]);
				return response()->json([
					'success' => false,
					'message' => 'El hash de contraseña enviado no tiene formato MD5 válido (32 caracteres hex).',
				], 422);
			}

			$usuario = $this->findSsoUser($request);

			if (!$usuario) {
				// Si se envió password, no autoaprovisionar: no se puede validar contra algo que no existe
				if ($passwordFueEnviado || !config('sso.auto_provision', true)) {
					Log::warning('SSO exchange rechazado: usuario no encontrado.', [
						'ip' => $request->ip(),
						'origen' => $request->input('origen'),
						'nickname' => $request->input('nickname'),
						'ci' => $request->input('ci'),
						'password_fue_enviado' => $passwordFueEnviado,
					]);
					return response()->json([
						'success' => false,
						'message' => 'El usuario no existe en el sistema Económico.',
					], 404);
				}

				$usuario = $this->provisionSsoUser($request);
			}

			$usuario->load('rol');

			if (!$usuario->estado) {
				return response()->json([
					'success' => false,
					'message' => 'El usuario está inactivo en Económico.'
				], 403);
			}

			if (!$usuario->rol || !$usuario->rol->estado) {
				return response()->json([
					'success' => false,
					'message' => 'El rol del usuario no está activo en Económico.'
				], 403);
			}

			// Validar contraseña si fue enviada (siempre, independiente del flag require_password_md5)
			if ($passwordFueEnviado) {
				$validationResult = $this->validateSsoPasswordMd5($passwordRecibido, $usuario);
				if (!$validationResult['ok']) {
					Log::warning('SSO exchange RECHAZADO: contraseña MD5 no coincide.', [
						'ip' => $request->ip(),
						'usuario' => $usuario->nickname,
						'origen' => $request->input('origen'),
						'motivo' => $validationResult['reason'],
						'stored_format' => $validationResult['stored_format'],
					]);
					return response()->json([
						'success' => false,
						'message' => 'La contraseña SSO no coincide con la registrada en el sistema Económico.',
					], 401);
				}
				Log::info('SSO contraseña validada correctamente.', [
					'usuario' => $usuario->nickname,
					'metodo' => $validationResult['reason'],
					'stored_format' => $validationResult['stored_format'],
				]);
			}

			$codCeta = (string) $request->input('cod_ceta', '');
			// Encode cod_ceta in token name so /api/verify can return it later
			// Format: "sso_token" or "sso_token|{cod_ceta}" if provided
			$tokenName = $codCeta !== '' ? 'sso_token|' . $codCeta : 'sso_token';

			Log::info('SSO exchange exitoso.', [
				'ip' => $request->ip(),
				'usuario' => $usuario->nickname,
				'origen' => $request->input('origen'),
				'id_usuario_sga' => $request->input('id_usuario_sga'),
				'cod_ceta' => $codCeta ?: null,
				'password_validado' => $passwordFueEnviado,
			]);

			return $this->issueTokenResponse($usuario, 'Token SSO generado correctamente', $tokenName);
		} catch (\Throwable $e) {
			Log::error('Error en exchange SSO: ' . $e->getMessage(), [
				'ip' => $request->ip(),
				'origen' => $request->input('origen'),
			]);

			return response()->json([
				'success' => false,
				'message' => 'Error al generar token SSO: ' . $e->getMessage(),
			], 500);
		}
	}

	/**
	 * Verificar token
	 */
	public function verify(Request $request)
	{
		$usuario = $request->user();

		if (!$usuario || !$usuario->estado) {
			return response()->json([
				'success' => false,
				'message' => 'Token inválido o usuario inactivo'
			], 401);
		}

		$usuario->load('rol');

		// Extract cod_ceta encoded in token name (format: "sso_token|{cod_ceta}")
		$codCeta = null;
		$tokenName = (string) $request->user()->currentAccessToken()->name;
		if (str_starts_with($tokenName, 'sso_token|')) {
			$parts = explode('|', $tokenName, 2);
			$codCeta = isset($parts[1]) && $parts[1] !== '' ? $parts[1] : null;
		}

		$payload = [
			'success' => true,
			'usuario' => $this->buildUserPayload($usuario),
		];

		if ($codCeta !== null) {
			$payload['cod_ceta'] = $codCeta;
		}

		return response()->json($payload);
	}

	/**
	 * Refresh token - Extender la expiración del token actual
	 */
	public function refreshToken(Request $request)
	{
		$usuario = $request->user();

		if (!$usuario || !$usuario->estado) {
			return response()->json([
				'success' => false,
				'message' => 'Token inválido o usuario inactivo'
			], 401);
		}

		// Obtener el token actual
		$currentToken = $request->user()->currentAccessToken();

		if (!$currentToken) {
			return response()->json([
				'success' => false,
				'message' => 'No se pudo obtener el token actual'
			], 401);
		}

		// Obtener minutos de refresh desde configuración
		$refreshMinutes = (int) config('sanctum.refresh_minutes', 10);

		// Calcular nueva fecha de expiración: SIEMPRE desde ahora + refresh_minutes
		// Esto asegura que cada actividad extienda el token por el tiempo configurado
		$newExpiresAt = now()->addMinutes($refreshMinutes);

		// Actualizar la fecha de expiración del token
		$currentToken->expires_at = $newExpiresAt;
		$currentToken->save();

		return response()->json([
			'success' => true,
			'message' => 'Token actualizado correctamente',
			'expires_at' => $newExpiresAt->toIso8601String()
		]);
	}

    /**
     * Cambiar contraseña (API)
     */
    public function changePassword(Request $request)
    {
        $usuario = $request->user();

        if (!$usuario || !$usuario->estado) {
            return response()->json([
                'success' => false,
                'message' => 'Usuario no autorizado'
            ], 401);
        }

        // Validación de campos
        $validator = Validator::make($request->all(), [
            'contrasenia_actual' => 'required|string',
            'contrasenia_nueva' => 'required|string|min:6|confirmed',
        ], [
            'contrasenia_actual.required' => 'La contraseña actual es obligatoria',
            'contrasenia_nueva.required' => 'La nueva contraseña es obligatoria',
            'contrasenia_nueva.min' => 'La nueva contraseña debe tener al menos 6 caracteres',
            'contrasenia_nueva.confirmed' => 'La confirmación de contraseña no coincide',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Datos de entrada inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        // Verificar contraseña actual
        if (!Hash::check($request->contrasenia_actual, $usuario->contrasenia)) {
            return response()->json([
                'success' => false,
                'message' => 'La contraseña actual no es correcta',
                'errors' => [ 'contrasenia_actual' => ['La contraseña actual no es correcta'] ]
            ], 422);
        }

        // Actualizar contraseña (mutator aplica hash)
        $usuario->update([
            'contrasenia' => $request->contrasenia_nueva
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Contraseña actualizada correctamente'
        ]);
    }

	private function issueTokenResponse(Usuario $usuario, $message, $tokenName)
	{
		$usuario->loadMissing('rol');

		$expirationMinutes = (int) config('sanctum.expiration', 480);
		$expiresAt = now()->addMinutes($expirationMinutes);
		$token = $usuario->createToken($tokenName, ['*'], $expiresAt)->plainTextToken;

		return response()->json([
			'success' => true,
			'message' => $message,
			'token' => $token,
			'expires_at' => $expiresAt->toIso8601String(),
			'usuario' => $this->buildUserPayload($usuario),
		]);
	}

	private function buildUserPayload(Usuario $usuario)
	{
		$permissionService = new PermissionService();
		$funciones = $permissionService->getUserFunctions($usuario->id_usuario);

		return [
			'id_usuario' => $usuario->id_usuario,
			'nickname' => $usuario->nickname,
			'nombre' => $usuario->nombre,
			'ap_paterno' => $usuario->ap_paterno,
			'ap_materno' => $usuario->ap_materno,
			'ci' => $usuario->ci,
			'estado' => $usuario->estado,
			'id_rol' => $usuario->id_rol,
			'apoyoCobranzas' => (bool) $usuario->apoyoCobranzas,
			'nombre_completo' => trim($usuario->nombre . ' ' . $usuario->ap_paterno . ' ' . $usuario->ap_materno),
			'rol' => [
				'id_rol' => $usuario->rol->id_rol,
				'nombre' => $usuario->rol->nombre,
				'descripcion' => $usuario->rol->descripcion,
				'estado' => $usuario->rol->estado,
			],
			'funciones' => $funciones,
		];
	}

	private function hasValidSsoSecret(Request $request, $sharedSecret)
	{
		$bearerToken = (string) $request->bearerToken();
		$headerSecret = (string) $request->header('X-SSO-Secret', '');
		$headerSecretB64 = (string) $request->header('X-SSO-Secret-B64', '');
		$authorization = trim((string) $request->header('Authorization', ''));
		$rawAuthorization = '';
		if ($authorization !== '' && stripos($authorization, 'Bearer ') !== 0) {
			$rawAuthorization = $authorization;
		}

		$decodedBearerToken = '';
		if ($bearerToken !== '' && stripos($bearerToken, 'b64:') === 0) {
			$decodedBearerToken = (string) base64_decode(substr($bearerToken, 4), true);
		}

		$decodedRawAuthorization = '';
		if ($rawAuthorization !== '' && stripos($rawAuthorization, 'b64:') === 0) {
			$decodedRawAuthorization = (string) base64_decode(substr($rawAuthorization, 4), true);
		}

		$decodedHeaderSecretB64 = '';
		if ($headerSecretB64 !== '') {
			$decodedHeaderSecretB64 = (string) base64_decode($headerSecretB64, true);
		}

		return ($bearerToken !== '' && hash_equals($sharedSecret, $bearerToken))
			|| ($rawAuthorization !== '' && hash_equals($sharedSecret, $rawAuthorization))
			|| ($headerSecret !== '' && hash_equals($sharedSecret, $headerSecret))
			|| ($decodedBearerToken !== '' && hash_equals($sharedSecret, $decodedBearerToken))
			|| ($decodedRawAuthorization !== '' && hash_equals($sharedSecret, $decodedRawAuthorization))
			|| ($decodedHeaderSecretB64 !== '' && hash_equals($sharedSecret, $decodedHeaderSecretB64));
	}

	private function hasValidSsoTimestamp(Request $request)
	{
		$timestamp = (string) $request->header('X-SSO-Timestamp', '');
		if ($timestamp === '' || !ctype_digit($timestamp)) {
			return false;
		}

		$ttlSeconds = max((int) config('sso.ttl_seconds', 300), 1);
		return abs(now()->timestamp - (int) $timestamp) <= $ttlSeconds;
	}

	private function isAllowedSsoIp(Request $request)
	{
		$allowedIps = config('sso.allowed_ips', []);
		if (empty($allowedIps)) {
			return true;
		}

		return in_array((string) $request->ip(), $allowedIps, true);
	}

	private function findSsoUser(Request $request)
	{
		$nickname = trim((string) $request->input('nickname', ''));
		$ci = trim((string) $request->input('ci', ''));

		if ($nickname !== '') {
			$usuario = Usuario::with('rol')
				->whereRaw('BINARY `usuarios`.`nickname` = ?', [$nickname])
				->first();

			if ($usuario) {
				return $usuario;
			}
		}

		if ($ci !== '') {
			return Usuario::with('rol')
				->where('ci', $ci)
				->first();
		}

		return null;
	}

	private function requiresSsoPasswordMd5()
	{
		return (bool) config('sso.require_password_md5', false);
	}

	/**
	 * Compara el MD5 enviado por SGA contra la contraseña almacenada en Económico.
	 *
	 * SGA envía: md5($plaintext) → string de 32 chars hex.
	 * Económico guarda: Hash::make($input) → bcrypt del input original.
	 *
	 * Caso A → stored es MD5 legado (32 hex): comparación directa.
	 *   Aplica si el sync original guardó el MD5 directamente sin pasar por Hash::make().
	 *
	 * Caso B → stored es bcrypt y el input original fue el mismo MD5:
	 *   Hash::check(md5_recibido, bcrypt_stored) → TRUE si stored = Hash::make(md5).
	 *   Aplica si Económico guardó la contraseña ya hasheada en MD5 (ej: contrasenia = Hash::make(md5($pwd))).
	 *
	 * Caso C → no coincide por ninguna vía: los sistemas tienen contraseñas distintas
	 *   o fueron hasheadas con inputs diferentes. Token rechazado.
	 */
	private function validateSsoPasswordMd5(string $passwordMd5, Usuario $usuario)
	{
		// getRawOriginal evita que el mutador setContraseniaAttribute altere el valor leído
		$storedPassword = trim((string) $usuario->getRawOriginal('contrasenia'));

		if ($storedPassword === '') {
			return ['ok' => false, 'reason' => 'stored_password_empty', 'stored_format' => 'empty'];
		}

		// Detectar el formato del hash almacenado para el log
		$storedFormat = 'unknown';
		if (preg_match('/^\$2[ayb]\$/', $storedPassword)) {
			$storedFormat = 'bcrypt';
		} elseif (preg_match('/^[a-f0-9]{32}$/i', $storedPassword)) {
			$storedFormat = 'md5_legacy';
		} elseif (preg_match('/^\$argon/', $storedPassword)) {
			$storedFormat = 'argon';
		}

		// Caso A: contraseña almacenada es MD5 legado → comparar directamente
		if ($storedFormat === 'md5_legacy') {
			$ok = hash_equals(strtolower($storedPassword), $passwordMd5);
			return ['ok' => $ok, 'reason' => $ok ? 'md5_legacy_match' : 'md5_legacy_mismatch', 'stored_format' => $storedFormat];
		}

		// Caso B: contraseña almacenada es bcrypt/argon → verificar si el input original era el MD5
		// Esto funciona si Económico almacenó Hash::make(md5($pwd)) → bcrypt(md5($pwd))
		if (Hash::check($passwordMd5, $storedPassword)) {
			return ['ok' => true, 'reason' => 'bcrypt_of_md5_match', 'stored_format' => $storedFormat];
		}

		// Caso C: no coincide. Posibles causas:
		// - Económico almacenó Hash::make($plaintext) pero SGA envía md5($plaintext)
		//   → Para que funcione, Económico debe guardar Hash::make(md5($plaintext))
		// - Las contraseñas en SGA y Económico son diferentes
		return ['ok' => false, 'reason' => 'no_match_incompatible_format', 'stored_format' => $storedFormat];
	}

	private function provisionSsoUser(Request $request)
	{
		$nickname = trim((string) $request->input('nickname', ''));
		$ci = trim((string) $request->input('ci', ''));
		$nombre = trim((string) $request->input('nombre', ''));
		$apPaterno = trim((string) $request->input('ap_paterno', ''));
		$apMaterno = trim((string) $request->input('ap_materno', ''));

		if ($nickname === '' && $ci === '') {
			throw new \InvalidArgumentException('No se pudo autoprovisionar el usuario sin nickname o CI.');
		}

		if ($nombre === '') {
			throw new \InvalidArgumentException('No se pudo autoprovisionar el usuario sin nombre.');
		}

		$defaultRoleId = $this->resolveDefaultSsoRoleId();
		$rol = Rol::find($defaultRoleId);
		if (!$rol || !$rol->estado) {
			throw new \RuntimeException('El rol por defecto para SSO no existe o está inactivo.');
		}

		$usuario = Usuario::create([
			'nickname' => Str::limit($nickname !== '' ? $nickname : $ci, 40, ''),
			'nombre' => Str::limit($nombre, 30, ''),
			'ap_paterno' => Str::limit($apPaterno, 40, ''),
			'ap_materno' => Str::limit($apMaterno, 40, ''),
			'ci' => Str::limit($ci !== '' ? $ci : $nickname, 25, ''),
			'contrasenia' => Str::random(32),
			'estado' => true,
			'id_rol' => $rol->id_rol,
			'apoyoCobranzas' => false,
		]);

		(new PermissionService())->copyRoleFunctionsToUser($usuario->id_usuario, $rol->id_rol, false, null);

		return $usuario->load('rol');
	}

	private function resolveDefaultSsoRoleId()
	{
		$configuredRoleId = (int) config('sso.default_role_id', 0);
		if ($configuredRoleId > 0) {
			return $configuredRoleId;
		}

		$secretariaRoleId = Rol::where('nombre', 'Secretaria')->value('id_rol');
		return (int) ($secretariaRoleId ? $secretariaRoleId : 2);
	}
}
