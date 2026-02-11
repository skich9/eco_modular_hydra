<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use App\Services\PermissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

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

			// Buscar usuario por nickname o CI
			$usuario = Usuario::with('rol')
				->where(function($query) use ($request) {
					$query->where('nickname', $request->nickname)
						  ->orWhere('ci', $request->nickname);
				})
				->where('estado', true)
				->first();

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

			// Generar token Sanctum con expiración configurable desde .env (SANCTUM_EXPIRATION en minutos)
			$expirationMinutes = (int) config('sanctum.expiration', 480);
			$expiresAt = now()->addMinutes($expirationMinutes);
			$token = $usuario->createToken(
				'auth_token',
				['*'],
				$expiresAt
			)->plainTextToken;

			$permissionService = new PermissionService();
			$funciones = $permissionService->getUserFunctions($usuario->id_usuario);

			return response()->json([
				'success' => true,
				'message' => 'Login exitoso',
				'token' => $token,
				'expires_at' => $expiresAt->toIso8601String(),
				'usuario' => [
					'id_usuario' => $usuario->id_usuario,
					'nickname' => $usuario->nickname,
					'nombre' => $usuario->nombre,
					'ap_paterno' => $usuario->ap_paterno,
					'ap_materno' => $usuario->ap_materno,
					'ci' => $usuario->ci,
					'estado' => $usuario->estado,
					'id_rol' => $usuario->id_rol,
					'nombre_completo' => $usuario->nombre . ' ' . $usuario->ap_paterno . ' ' . $usuario->ap_materno,
					'rol' => [
						'id_rol' => $usuario->rol->id_rol,
						'nombre' => $usuario->rol->nombre,
						'descripcion' => $usuario->rol->descripcion,
						'estado' => $usuario->rol->estado
					],
					'funciones' => $funciones
				]
			]);

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
		$request->user()->currentAccessToken()->delete();

		return response()->json([
			'success' => true,
			'message' => 'Sesión cerrada correctamente'
		]);
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

		$permissionService = new PermissionService();
		$funciones = $permissionService->getUserFunctions($usuario->id_usuario);

		return response()->json([
			'success' => true,
			'usuario' => [
				'id_usuario' => $usuario->id_usuario,
				'nickname' => $usuario->nickname,
				'nombre' => $usuario->nombre,
				'ap_paterno' => $usuario->ap_paterno,
				'ap_materno' => $usuario->ap_materno,
				'ci' => $usuario->ci,
				'estado' => $usuario->estado,
				'id_rol' => $usuario->id_rol,
				'nombre_completo' => $usuario->nombre . ' ' . $usuario->ap_paterno . ' ' . $usuario->ap_materno,
				'rol' => [
					'id_rol' => $usuario->rol->id_rol,
					'nombre' => $usuario->rol->nombre,
					'descripcion' => $usuario->rol->descripcion,
					'estado' => $usuario->rol->estado
				],
				'funciones' => $funciones
			]
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
}
