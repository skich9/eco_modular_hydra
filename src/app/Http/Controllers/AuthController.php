<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
	/**
	 * Mostrar formulario de login
	 */
	public function showLogin()
	{
		// Si ya está autenticado, redirigir al dashboard
		if (Session::has('usuario_autenticado')) {
			return redirect()->route('dashboard');
		}
		
		return view('auth.login');
	}

	/**
	 * Procesar login
	 */
	public function login(Request $request)
	{
		try {
			$validator = Validator::make($request->all(), [
				'email' => 'required|string',
				'password' => 'required|string'
			], [
				'email.required' => 'El correo electrónico es obligatorio',
				'password.required' => 'La contraseña es obligatoria'
			]);

			if ($validator->fails()) {
				return back()->withErrors($validator)->withInput();
			}

			// Buscar usuario por nickname o CI (usando email como campo genérico)
			$usuario = Usuario::with('rol')
				->where(function($query) use ($request) {
					$query->where('nickname', $request->email)
						  ->orWhere('ci', $request->email);
				})
				->where('estado', true)
				->first();

			if (!$usuario) {
				return back()->withErrors([
					'email' => 'Las credenciales no coinciden con nuestros registros.'
				])->withInput();
			}

			// Verificar contraseña
			if (!Hash::check($request->password, $usuario->contrasenia)) {
				return back()->withErrors([
					'email' => 'Las credenciales no coinciden con nuestros registros.'
				])->withInput();
			}

			// Verificar que el rol esté activo
			if (!$usuario->rol || !$usuario->rol->estado) {
				return back()->withErrors([
					'email' => 'Su rol no está activo. Contacte al administrador.'
				])->withInput();
			}

			// Crear sesión
			Session::put('usuario_autenticado', [
				'id' => $usuario->id_usuario,
				'nickname' => $usuario->nickname,
				'nombre_completo' => $usuario->nombre . ' ' . $usuario->ap_paterno . ' ' . $usuario->ap_materno,
				'rol_id' => $usuario->id_rol,
				'rol_nombre' => $usuario->rol->nombre,
				'ci' => $usuario->ci
			]);

			return redirect()->route('dashboard')->with('success', 'Bienvenido al sistema');

		} catch (\Exception $e) {
			return back()->withErrors([
				'email' => 'Error en el sistema. Intente nuevamente.'
			])->withInput();
		}
	}

	/**
	 * Cerrar sesión
	 */
	public function logout()
	{
		Session::forget('usuario_autenticado');
		Session::flush();
		
		return redirect()->route('login')->with('success', 'Sesión cerrada correctamente');
	}

	/**
	 * Mostrar dashboard
	 */
	public function dashboard()
	{
		if (!Session::has('usuario_autenticado')) {
			return redirect()->route('login');
		}

		$usuario = Session::get('usuario_autenticado');
		
		// Obtener datos adicionales del usuario si es necesario
		$usuarioCompleto = Usuario::with(['rol', 'funciones'])->find($usuario['id']);
		
		return view('dashboard', compact('usuario', 'usuarioCompleto'));
	}

	/**
	 * Mostrar formulario de cambio de contraseña
	 */
	public function showChangePassword()
	{
		if (!Session::has('usuario_autenticado')) {
			return redirect()->route('login');
		}

		return view('auth.change-password');
	}

	/**
	 * Cambiar contraseña
	 */
	public function changePassword(Request $request)
	{
		if (!Session::has('usuario_autenticado')) {
			return redirect()->route('login');
		}

		try {
			$validator = Validator::make($request->all(), [
				'current_password' => 'required|string',
				'new_password' => 'required|string|min:6|confirmed'
			], [
				'current_password.required' => 'La contraseña actual es obligatoria',
				'new_password.required' => 'La nueva contraseña es obligatoria',
				'new_password.min' => 'La nueva contraseña debe tener al menos 6 caracteres',
				'new_password.confirmed' => 'La confirmación de contraseña no coincide'
			]);

			if ($validator->fails()) {
				return back()->withErrors($validator);
			}

			$usuarioSession = Session::get('usuario_autenticado');
			$usuario = Usuario::find($usuarioSession['id']);

			// Verificar contraseña actual
			if (!Hash::check($request->current_password, $usuario->contrasenia)) {
				return back()->withErrors([
					'current_password' => 'La contraseña actual no es correcta'
				]);
			}

			// Actualizar contraseña
			$usuario->update([
				'contrasenia' => $request->new_password // El mutator se encarga del hash
			]);

			return back()->with('success', 'Contraseña actualizada correctamente');

		} catch (\Exception $e) {
			return back()->withErrors([
				'current_password' => 'Error al actualizar la contraseña'
			]);
		}
	}
}
