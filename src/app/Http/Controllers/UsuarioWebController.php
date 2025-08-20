<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use App\Models\Rol;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;

class UsuarioWebController extends Controller
{
	/**
	 * Display a listing of the resource.
	 */
	public function index()
	{
		if (!Session::has('usuario_autenticado')) {
			return redirect()->route('login');
		}

		$usuarios = Usuario::with('rol')->get();
		$roles = Rol::where('estado', true)->get();
		
		return view('usuarios.index', compact('usuarios', 'roles'));
	}

	/**
	 * Store a newly created resource in storage.
	 */
	public function store(Request $request)
	{
		if (!Session::has('usuario_autenticado')) {
			return redirect()->route('login');
		}

		try {
			$validated = $request->validate([
				'nickname' => 'required|string|max:40|unique:usuarios,nickname',
				'nombre' => 'required|string|max:30',
				'ap_paterno' => 'required|string|max:40',
				'ap_materno' => 'nullable|string|max:40',
				'contrasenia' => 'required|string|min:6',
				'ci' => 'required|string|max:25|unique:usuarios,ci',
				'estado' => 'boolean',
				'id_rol' => 'required|exists:rol,id_rol'
			]);

			// Asegurar que estado sea boolean
			$validated['estado'] = $request->has('estado') ? true : false;

			Usuario::create($validated);

			return redirect()->route('usuarios.index')->with('success', 'Usuario creado exitosamente');
		} catch (\Illuminate\Validation\ValidationException $e) {
			return redirect()->back()->withErrors($e->errors())->withInput();
		} catch (\Exception $e) {
			return redirect()->back()->with('error', 'Error al crear usuario: ' . $e->getMessage());
		}
	}

	/**
	 * Update the specified resource in storage.
	 */
	public function update(Request $request, $id)
	{
		if (!Session::has('usuario_autenticado')) {
			return redirect()->route('login');
		}

		try {
			$usuario = Usuario::findOrFail($id);

			$validated = $request->validate([
				'nickname' => 'required|string|max:40|unique:usuarios,nickname,' . $id . ',id_usuario',
				'nombre' => 'required|string|max:30',
				'ap_paterno' => 'required|string|max:40',
				'ap_materno' => 'nullable|string|max:40',
				'ci' => 'required|string|max:25|unique:usuarios,ci,' . $id . ',id_usuario',
				'estado' => 'boolean',
				'id_rol' => 'required|exists:rol,id_rol'
			]);

			// Si se proporciona nueva contraseña
			if ($request->filled('contrasenia')) {
				$request->validate([
					'contrasenia' => 'string|min:6'
				]);
				$validated['contrasenia'] = $request->contrasenia;
			}

			// Asegurar que estado sea boolean
			$validated['estado'] = $request->has('estado') ? true : false;

			$usuario->update($validated);

			return redirect()->route('usuarios.index')->with('success', 'Usuario actualizado exitosamente');
		} catch (\Illuminate\Validation\ValidationException $e) {
			return redirect()->back()->withErrors($e->errors())->withInput();
		} catch (\Exception $e) {
			return redirect()->back()->with('error', 'Error al actualizar usuario: ' . $e->getMessage());
		}
	}

	/**
	 * Remove the specified resource from storage.
	 */
	public function destroy($id)
	{
		if (!Session::has('usuario_autenticado')) {
			return redirect()->route('login');
		}

		try {
			$usuario = Usuario::findOrFail($id);
			$usuario->delete();

			return redirect()->route('usuarios.index')->with('success', 'Usuario eliminado exitosamente');
		} catch (\Exception $e) {
			return redirect()->back()->with('error', 'Error al eliminar usuario: ' . $e->getMessage());
		}
	}

	/**
	 * Get user data for editing (AJAX)
	 */
	public function show($id)
	{
		if (!Session::has('usuario_autenticado')) {
			return response()->json(['error' => 'No autorizado'], 401);
		}

		try {
			$usuario = Usuario::with('rol')->findOrFail($id);
			return response()->json([
				'success' => true,
				'data' => $usuario
			]);
		} catch (\Exception $e) {
			return response()->json([
				'success' => false,
				'message' => 'Usuario no encontrado'
			], 404);
		}
	}

	/**
	 * Toggle user status (AJAX)
	 */
	public function toggleStatus(Request $request, $id)
	{
		if (!Session::has('usuario_autenticado')) {
			return response()->json(['error' => 'No autorizado'], 401);
		}

		try {
			$usuario = Usuario::findOrFail($id);
			$usuario->update(['estado' => $request->estado]);

			return response()->json([
				'success' => true,
				'message' => 'Estado actualizado correctamente'
			]);
		} catch (\Exception $e) {
			return response()->json([
				'success' => false,
				'message' => 'Error al actualizar estado'
			], 500);
		}
	}
}
