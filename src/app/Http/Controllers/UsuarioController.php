<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use App\Models\Rol;
use App\Models\Funcion;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

use App\Services\PermissionService;

class UsuarioController extends Controller
{
    protected $permissionService;

    public function __construct(PermissionService $permissionService)
    {
        $this->permissionService = $permissionService;
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        try {
            $authUserId = auth('sanctum')->id();
            $authUser = $authUserId ? Usuario::with('rol')->find((int) $authUserId) : null;
            if (!$authUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'No autenticado'
                ], 401);
            }

            $rolNombre = strtolower((string) optional($authUser->rol)->nombre);
            $esAdmin = str_contains($rolNombre, 'admin') || strtolower((string) $authUser->nickname) === 'admin';

            $query = Usuario::with(['rol', 'funciones'])
                ->where('estado', 1);

            if (!$esAdmin) {
                $query->where('id_usuario', (int) $authUser->id_usuario);
            }

            $usuarios = $query
                ->orderBy('nombre')
                ->orderBy('ap_paterno')
                ->orderBy('ap_materno')
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
            
            // Sincronizar funciones del rol inicial
            $this->permissionService->copyRoleFunctionsToUser(
                $usuario->id_usuario,
                $validated['id_rol'],
                true
            );

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

            $oldRolId = $usuario->id_rol;
            $usuario->update($validated);

            // Si el rol cambió, sincronizar funciones
            if (isset($validated['id_rol']) && $validated['id_rol'] != $oldRolId) {
                $this->permissionService->copyRoleFunctionsToUser(
                    $usuario->id_usuario,
                    $validated['id_rol'],
                    true
                );
            }

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
                'funciones.*.fecha_fin' => 'nullable|date|after:funciones.*.fecha_ini'
            ]);

            // Sincronizar funciones con datos pivot
            $funcionesData = [];
            foreach ($validated['funciones'] as $funcion) {
                $funcionesData[$funcion['id_funcion']] = [
                    'fecha_ini' => $funcion['fecha_ini'],
                    'fecha_fin' => $funcion['fecha_fin'] ?? null
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

            // Si viene 'estado' se valida y usa; si no, se hace toggle automático
            if ($request->has('estado')) {
                $validated = $request->validate([
                    'estado' => 'required|boolean'
                ]);
                $usuario->estado = (bool) $validated['estado'];
            } else {
                $usuario->estado = !$usuario->estado;
            }

            $usuario->save();

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

    /**
     * Buscar usuarios por término parcial en varios campos
     */
    public function search(Request $request)
    {
        try {
            $term = trim((string) $request->query('term', ''));

            $query = Usuario::with(['rol', 'funciones']);
            if ($term !== '') {
                $query->where(function ($q) use ($term) {
                    $q->where('nickname', 'like', "%{$term}%")
                      ->orWhere('nombre', 'like', "%{$term}%")
                      ->orWhere('ap_paterno', 'like', "%{$term}%")
                      ->orWhere('ap_materno', 'like', "%{$term}%")
                      ->orWhere('ci', 'like', "%{$term}%");
                });
            }

            $usuarios = $query->get();

            return response()->json([
                'success' => true,
                'data' => $usuarios,
                'message' => 'Usuarios obtenidos exitosamente'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al buscar usuarios: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Resetear la contraseña de un usuario (para administradores)
     */
    public function resetPassword(Request $request, $id)
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
                'contrasenia' => 'required|string|min:6'
            ]);

            // El mutator setContraseniaAttribute se encarga del hash
            $usuario->contrasenia = $validated['contrasenia'];
            $usuario->save();

            return response()->json([
                'success' => true,
                'message' => 'Contraseña actualizada exitosamente'
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
                'message' => 'Error al actualizar contraseña: ' . $e->getMessage()
            ], 500);
        }
    }
}
