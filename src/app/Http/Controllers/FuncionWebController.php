<?php

namespace App\Http\Controllers;

use App\Models\Funcion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;

class FuncionWebController extends Controller
{
	/**
	 * Display a listing of the resource.
	 */
	public function index()
	{
		if (!Session::has('usuario_autenticado')) {
			return redirect()->route('login');
		}

		$funciones = Funcion::withCount('usuarios')->get();
		
		return view('funciones.index', compact('funciones'));
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
				'nombre' => 'required|string|max:255|unique:funciones,nombre',
				'descripcion' => 'nullable|string|max:500',
				'estado' => 'boolean'
			]);

			$validated['estado'] = $request->has('estado') ? true : false;

			Funcion::create($validated);

			return redirect()->route('funciones.index')->with('success', 'Función creada exitosamente');
		} catch (\Exception $e) {
			return redirect()->back()->with('error', 'Error al crear función: ' . $e->getMessage());
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
			$funcion = Funcion::findOrFail($id);

			$validated = $request->validate([
				'nombre' => 'required|string|max:255|unique:funciones,nombre,' . $id . ',id_funcion',
				'descripcion' => 'nullable|string|max:500',
				'estado' => 'boolean'
			]);

			$validated['estado'] = $request->has('estado') ? true : false;

			$funcion->update($validated);

			return redirect()->route('funciones.index')->with('success', 'Función actualizada exitosamente');
		} catch (\Exception $e) {
			return redirect()->back()->with('error', 'Error al actualizar función: ' . $e->getMessage());
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
			$funcion = Funcion::findOrFail($id);
			
			// Verificar si tiene usuarios asignados
			if ($funcion->usuarios()->count() > 0) {
				return redirect()->back()->with('error', 'No se puede eliminar la función porque tiene usuarios asignados');
			}

			$funcion->delete();

			return redirect()->route('funciones.index')->with('success', 'Función eliminada exitosamente');
		} catch (\Exception $e) {
			return redirect()->back()->with('error', 'Error al eliminar función: ' . $e->getMessage());
		}
	}

	/**
	 * Get function data for editing (AJAX)
	 */
	public function show($id)
	{
		if (!Session::has('usuario_autenticado')) {
			return response()->json(['error' => 'No autorizado'], 401);
		}

		try {
			$funcion = Funcion::findOrFail($id);
			return response()->json([
				'success' => true,
				'data' => $funcion
			]);
		} catch (\Exception $e) {
			return response()->json([
				'success' => false,
				'message' => 'Función no encontrada'
			], 404);
		}
	}

	/**
	 * Toggle function status (AJAX)
	 */
	public function toggleStatus(Request $request, $id)
	{
		if (!Session::has('usuario_autenticado')) {
			return redirect()->route('login');
		}

		try {
			$funcion = Funcion::findOrFail($id);
			$funcion->update(['estado' => $request->estado]);

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
