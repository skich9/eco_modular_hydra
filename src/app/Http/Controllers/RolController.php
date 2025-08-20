<?php

namespace App\Http\Controllers;

use App\Models\Rol;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class RolController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        try {
            $roles = Rol::withCount('usuarios')->get();
            return response()->json([
                'success' => true,
                'data' => $roles,
                'message' => 'Roles obtenidos exitosamente'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener roles: ' . $e->getMessage()
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
                'nombre' => 'required|string|max:255|unique:rol,nombre',
                'descripcion' => 'nullable|string|max:500',
                'estado' => 'required|boolean'
            ]);

            $rol = Rol::create($validated);

            return response()->json([
                'success' => true,
                'data' => $rol,
                'message' => 'Rol creado exitosamente'
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
                'message' => 'Error al crear rol: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        try {
            $rol = Rol::with(['usuarios' => function($query) {
                $query->select('id_usuario', 'nickname', 'nombre', 'ap_paterno', 'ap_materno', 'estado', 'id_rol');
            }])->find($id);
            
            if (!$rol) {
                return response()->json([
                    'success' => false,
                    'message' => 'Rol no encontrado'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $rol,
                'message' => 'Rol obtenido exitosamente'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener rol: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        try {
            $rol = Rol::find($id);
            
            if (!$rol) {
                return response()->json([
                    'success' => false,
                    'message' => 'Rol no encontrado'
                ], 404);
            }

            $validated = $request->validate([
                'nombre' => ['sometimes', 'string', 'max:255', Rule::unique('rol')->ignore($id, 'id_rol')],
                'descripcion' => 'nullable|string|max:500',
                'estado' => 'sometimes|boolean'
            ]);

            $rol->update($validated);

            return response()->json([
                'success' => true,
                'data' => $rol,
                'message' => 'Rol actualizado exitosamente'
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
                'message' => 'Error al actualizar rol: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        try {
            $rol = Rol::find($id);
            
            if (!$rol) {
                return response()->json([
                    'success' => false,
                    'message' => 'Rol no encontrado'
                ], 404);
            }

            // Verificar si el rol tiene usuarios asignados
            $usuariosCount = Usuario::where('id_rol', $id)->count();
            if ($usuariosCount > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se puede eliminar el rol porque tiene usuarios asignados'
                ], 409);
            }

            $rol->delete();

            return response()->json([
                'success' => true,
                'message' => 'Rol eliminado exitosamente'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar rol: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cambiar estado del rol
     */
    public function cambiarEstado(Request $request, $id)
    {
        try {
            $rol = Rol::find($id);
            
            if (!$rol) {
                return response()->json([
                    'success' => false,
                    'message' => 'Rol no encontrado'
                ], 404);
            }

            $validated = $request->validate([
                'estado' => 'required|boolean'
            ]);

            $rol->update(['estado' => $validated['estado']]);

            return response()->json([
                'success' => true,
                'data' => $rol,
                'message' => 'Estado del rol actualizado exitosamente'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al cambiar estado: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener roles activos
     */
    public function rolesActivos()
    {
        try {
            $roles = Rol::where('estado', true)->get();
            
            return response()->json([
                'success' => true,
                'data' => $roles,
                'message' => 'Roles activos obtenidos exitosamente'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener roles activos: ' . $e->getMessage()
            ], 500);
        }
    }
}
