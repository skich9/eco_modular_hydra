<?php

namespace App\Http\Controllers;

use App\Models\Funcion;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class FuncionController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        try {
            $funciones = Funcion::withCount('usuarios')->get();
            return response()->json([
                'success' => true,
                'data' => $funciones,
                'message' => 'Funciones obtenidas exitosamente'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener funciones: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'nombre' => 'required|string|max:255|unique:funciones,nombre',
                'descripcion' => 'nullable|string|max:500',
                'estado' => 'required|boolean'
            ]);

            $funcion = Funcion::create($validated);

            return response()->json([
                'success' => true,
                'data' => $funcion,
                'message' => 'Función creada exitosamente'
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear función: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        try {
            $funcion = Funcion::with(['usuarios' => function($query) {
                $query->select('usuarios.id_usuario', 'nickname', 'nombre', 'ap_paterno', 'ap_materno', 'estado')
                      ->withPivot('fecha_ini', 'fecha_fin', 'usuario_asig');
            }])->find($id);
            
            if (!$funcion) {
                return response()->json([
                    'success' => false,
                    'message' => 'Función no encontrada'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $funcion,
                'message' => 'Función obtenida exitosamente'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener función: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        try {
            $funcion = Funcion::find($id);
            
            if (!$funcion) {
                return response()->json([
                    'success' => false,
                    'message' => 'Función no encontrada'
                ], 404);
            }

            $validated = $request->validate([
                'nombre' => ['sometimes', 'string', 'max:255', Rule::unique('funciones')->ignore($id, 'id_funcion')],
                'descripcion' => 'nullable|string|max:500',
                'estado' => 'sometimes|boolean'
            ]);

            $funcion->update($validated);

            return response()->json([
                'success' => true,
                'data' => $funcion,
                'message' => 'Función actualizada exitosamente'
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar función: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        try {
            $funcion = Funcion::find($id);
            
            if (!$funcion) {
                return response()->json([
                    'success' => false,
                    'message' => 'Función no encontrada'
                ], 404);
            }

            // Verificar si la función tiene usuarios asignados
            $usuariosCount = $funcion->usuarios()->count();
            if ($usuariosCount > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se puede eliminar la función porque tiene usuarios asignados'
                ], 409);
            }

            $funcion->delete();

            return response()->json([
                'success' => true,
                'message' => 'Función eliminada exitosamente'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar función: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cambiar estado de la función
     */
    public function cambiarEstado(Request $request, $id)
    {
        try {
            $funcion = Funcion::find($id);
            
            if (!$funcion) {
                return response()->json([
                    'success' => false,
                    'message' => 'Función no encontrada'
                ], 404);
            }

            $validated = $request->validate([
                'estado' => 'required|boolean'
            ]);

            $funcion->update(['estado' => $validated['estado']]);

            return response()->json([
                'success' => true,
                'data' => $funcion,
                'message' => 'Estado de la función actualizado exitosamente'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al cambiar estado: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener funciones activas
     */
    public function funcionesActivas()
    {
        try {
            $funciones = Funcion::where('estado', true)->get();
            
            return response()->json([
                'success' => true,
                'data' => $funciones,
                'message' => 'Funciones activas obtenidas exitosamente'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener funciones activas: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener usuarios asignados a una función
     */
    public function usuariosAsignados($id)
    {
        try {
            $funcion = Funcion::with(['usuarios' => function($query) {
                $query->select('usuarios.id_usuario', 'nickname', 'nombre', 'ap_paterno', 'ap_materno', 'estado')
                      ->withPivot('fecha_ini', 'fecha_fin', 'usuario_asig', 'created_at', 'updated_at');
            }])->find($id);
            
            if (!$funcion) {
                return response()->json([
                    'success' => false,
                    'message' => 'Función no encontrada'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $funcion->usuarios,
                'message' => 'Usuarios asignados obtenidos exitosamente'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener usuarios asignados: ' . $e->getMessage()
            ], 500);
        }
    }
}
