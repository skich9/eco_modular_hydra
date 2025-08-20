<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use App\Models\Rol;
use App\Models\Funcion;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UsuarioController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        try {
            $usuarios = Usuario::with(['rol', 'funciones'])->get();
            return response()->json([
                'success' => true,
                'data' => $usuarios,
                'message' => 'Usuarios obtenidos exitosamente'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener usuarios: ' . $e->getMessage()
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
                'nickname' => 'required|string|max:255|unique:usuarios,nickname',
                'nombre' => 'required|string|max:255',
                'ap_paterno' => 'required|string|max:255',
                'ap_materno' => 'nullable|string|max:255',
                'contrasenia' => 'required|string|min:6',
                'ci' => 'required|string|unique:usuarios,ci',
                'estado' => 'required|boolean',
                'id_rol' => 'required|exists:rol,id_rol'
            ]);

            $usuario = Usuario::create($validated);
            $usuario->load(['rol', 'funciones']);

            return response()->json([
                'success' => true,
                'data' => $usuario,
                'message' => 'Usuario creado exitosamente'
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
                'message' => 'Error al crear usuario: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        try {
            $usuario = Usuario::with(['rol', 'funciones'])->find($id);
            
            if (!$usuario) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no encontrado'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $usuario,
                'message' => 'Usuario obtenido exitosamente'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener usuario: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        try {
            $usuario = Usuario::find($id);
            
            if (!$usuario) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no encontrado'
                ], 404);
            }

            $validated = $request->validate([
                'nickname' => ['sometimes', 'string', 'max:255', Rule::unique('usuarios')->ignore($id, 'id_usuario')],
                'nombre' => 'sometimes|string|max:255',
                'ap_paterno' => 'sometimes|string|max:255',
                'ap_materno' => 'nullable|string|max:255',
                'contrasenia' => 'sometimes|string|min:6',
                'ci' => ['sometimes', 'string', Rule::unique('usuarios')->ignore($id, 'id_usuario')],
                'estado' => 'sometimes|boolean',
                'id_rol' => 'sometimes|exists:rol,id_rol'
            ]);

            $usuario->update($validated);
            $usuario->load(['rol', 'funciones']);

            return response()->json([
                'success' => true,
                'data' => $usuario,
                'message' => 'Usuario actualizado exitosamente'
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
                'message' => 'Error al actualizar usuario: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        try {
            $usuario = Usuario::find($id);
            
            if (!$usuario) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no encontrado'
                ], 404);
            }

            $usuario->delete();

            return response()->json([
                'success' => true,
                'message' => 'Usuario eliminado exitosamente'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar usuario: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Asignar funciones a un usuario
     */
    public function asignarFunciones(Request $request, $id)
    {
        try {
            $usuario = Usuario::find($id);
            
            if (!$usuario) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no encontrado'
                ], 404);
            }

            $validated = $request->validate([
                'funciones' => 'required|array',
                'funciones.*.id_funcion' => 'required|exists:funciones,id_funcion',
                'funciones.*.fecha_ini' => 'required|date',
                'funciones.*.fecha_fin' => 'nullable|date|after:funciones.*.fecha_ini',
                'funciones.*.usuario_asig' => 'required|string|max:255'
            ]);

            // Sincronizar funciones con datos pivot
            $funcionesData = [];
            foreach ($validated['funciones'] as $funcion) {
                $funcionesData[$funcion['id_funcion']] = [
                    'fecha_ini' => $funcion['fecha_ini'],
                    'fecha_fin' => $funcion['fecha_fin'] ?? null,
                    'usuario_asig' => $funcion['usuario_asig']
                ];
            }

            $usuario->funciones()->sync($funcionesData);
            $usuario->load(['rol', 'funciones']);

            return response()->json([
                'success' => true,
                'data' => $usuario,
                'message' => 'Funciones asignadas exitosamente'
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
                'message' => 'Error al asignar funciones: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener usuarios por rol
     */
    public function usuariosPorRol($idRol)
    {
        try {
            $usuarios = Usuario::with(['rol', 'funciones'])
                              ->where('id_rol', $idRol)
                              ->get();

            return response()->json([
                'success' => true,
                'data' => $usuarios,
                'message' => 'Usuarios obtenidos exitosamente'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener usuarios: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cambiar estado del usuario
     */
    public function cambiarEstado(Request $request, $id)
    {
        try {
            $usuario = Usuario::find($id);
            
            if (!$usuario) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no encontrado'
                ], 404);
            }

            $validated = $request->validate([
                'estado' => 'required|boolean'
            ]);

            $usuario->update(['estado' => $validated['estado']]);

            return response()->json([
                'success' => true,
                'data' => $usuario,
                'message' => 'Estado del usuario actualizado exitosamente'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al cambiar estado: ' . $e->getMessage()
            ], 500);
        }
    }
}
