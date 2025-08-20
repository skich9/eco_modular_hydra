<?php

namespace App\Http\Controllers;

use App\Models\Rol;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;

class RolWebController extends Controller
{
	/**
	 * Display a listing of the resource.
	 */
	public function index()
	{
		if (!Session::has('usuario_autenticado')) {
			return redirect()->route('login');
		}

		$roles = Rol::withCount('usuarios')->get();
		
		return view('roles.index', compact('roles'));
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
				'nombre' => 'required|string|max:255|unique:rol,nombre',
				'descripcion' => 'nullable|string|max:500',
				'estado' => 'boolean'
			]);

			$validated['estado'] = $request->has('estado') ? true : false;

			Rol::create($validated);

			return redirect()->route('roles.index')->with('success', 'Rol creado exitosamente');
		} catch (\Exception $e) {
			return redirect()->back()->with('error', 'Error al crear rol: ' . $e->getMessage());
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
			$rol = Rol::findOrFail($id);

			$validated = $request->validate([
				'nombre' => 'required|string|max:255|unique:rol,nombre,' . $id . ',id_rol',
				'descripcion' => 'nullable|string|max:500',
				'estado' => 'boolean'
			]);

			$validated['estado'] = $request->has('estado') ? true : false;

			$rol->update($validated);

			return redirect()->route('roles.index')->with('success', 'Rol actualizado exitosamente');
		} catch (\Exception $e) {
			return redirect()->back()->with('error', 'Error al actualizar rol: ' . $e->getMessage());
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
			$rol = Rol::findOrFail($id);
			
			// Verificar si tiene usuarios asignados
			if ($rol->usuarios()->count() > 0) {
				return redirect()->back()->with('error', 'No se puede eliminar el rol porque tiene usuarios asignados');
			}

			$rol->delete();

			return redirect()->route('roles.index')->with('success', 'Rol eliminado exitosamente');
		} catch (\Exception $e) {
			return redirect()->back()->with('error', 'Error al eliminar rol: ' . $e->getMessage());
		}
	}

	/**
	 * Get role data for editing (AJAX)
	 */
	public function show($id)
	{
		if (!Session::has('usuario_autenticado')) {
			return response()->json(['error' => 'No autorizado'], 401);
		}

		try {
			$rol = Rol::findOrFail($id);
			return response()->json([
				'success' => true,
				'data' => $rol
			]);
		} catch (\Exception $e) {
			return response()->json([
				'success' => false,
				'message' => 'Rol no encontrado'
			], 404);
		}
	}

	/**
	 * Toggle role status (AJAX)
	 */
	public function toggleStatus(Request $request, $id)
	{
		if (!Session::has('usuario_autenticado')) {
			return response()->json(['error' => 'No autorizado'], 401);
		}

		try {
			$rol = Rol::findOrFail($id);
			$rol->update(['estado' => $request->estado]);

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
