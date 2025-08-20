<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use App\Models\Funcion;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class AsignacionFuncionController extends Controller
{
    /**
     * Display a listing of all function assignments.
     */
    public function index()
    {
        try {
            $asignaciones = DB::table('asignacion_funcion')
                ->join('usuarios', 'asignacion_funcion.id_usuario', '=', 'usuarios.id_usuario')
                ->join('funciones', 'asignacion_funcion.id_funcion', '=', 'funciones.id_funcion')
                ->select(
                    'asignacion_funcion.*',
                    'usuarios.nickname',
                    'usuarios.nombre as usuario_nombre',
                    'usuarios.ap_paterno',
                    'usuarios.ap_materno',
                    'funciones.nombre as funcion_nombre',
                    'funciones.descripcion as funcion_descripcion'
                )
                ->orderBy('asignacion_funcion.created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $asignaciones,
                'message' => 'Asignaciones obtenidas exitosamente'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener asignaciones: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a new function assignment.
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'id_usuario' => 'required|exists:usuarios,id_usuario',
                'id_funcion' => 'required|exists:funciones,id_funcion',
                'fecha_ini' => 'required|date',
                'fecha_fin' => 'nullable|date|after:fecha_ini',
                'usuario_asig' => 'required|string|max:255'
            ]);

            // Verificar si ya existe una asignación activa
            $asignacionExistente = DB::table('asignacion_funcion')
                ->where('id_usuario', $validated['id_usuario'])
                ->where('id_funcion', $validated['id_funcion'])
                ->whereNull('fecha_fin')
                ->first();

            if ($asignacionExistente) {
                return response()->json([
                    'success' => false,
                    'message' => 'El usuario ya tiene esta función asignada activamente'
                ], 409);
            }

            $asignacionId = DB::table('asignacion_funcion')->insertGetId([
                'id_usuario' => $validated['id_usuario'],
                'id_funcion' => $validated['id_funcion'],
                'fecha_ini' => $validated['fecha_ini'],
                'fecha_fin' => $validated['fecha_fin'],
                'usuario_asig' => $validated['usuario_asig'],
                'created_at' => now(),
                'updated_at' => now()
            ]);

            $asignacion = DB::table('asignacion_funcion')
                ->join('usuarios', 'asignacion_funcion.id_usuario', '=', 'usuarios.id_usuario')
                ->join('funciones', 'asignacion_funcion.id_funcion', '=', 'funciones.id_funcion')
                ->select(
                    'asignacion_funcion.*',
                    'usuarios.nickname',
                    'usuarios.nombre as usuario_nombre',
                    'funciones.nombre as funcion_nombre'
                )
                ->where('asignacion_funcion.id', $asignacionId)
                ->first();

            return response()->json([
                'success' => true,
                'data' => $asignacion,
                'message' => 'Función asignada exitosamente'
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
                'message' => 'Error al asignar función: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified assignment.
     */
    public function show($id)
    {
        try {
            $asignacion = DB::table('asignacion_funcion')
                ->join('usuarios', 'asignacion_funcion.id_usuario', '=', 'usuarios.id_usuario')
                ->join('funciones', 'asignacion_funcion.id_funcion', '=', 'funciones.id_funcion')
                ->select(
                    'asignacion_funcion.*',
                    'usuarios.nickname',
                    'usuarios.nombre as usuario_nombre',
                    'usuarios.ap_paterno',
                    'usuarios.ap_materno',
                    'funciones.nombre as funcion_nombre',
                    'funciones.descripcion as funcion_descripcion'
                )
                ->where('asignacion_funcion.id', $id)
                ->first();

            if (!$asignacion) {
                return response()->json([
                    'success' => false,
                    'message' => 'Asignación no encontrada'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $asignacion,
                'message' => 'Asignación obtenida exitosamente'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener asignación: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified assignment.
     */
    public function update(Request $request, $id)
    {
        try {
            $asignacion = DB::table('asignacion_funcion')->where('id', $id)->first();
            
            if (!$asignacion) {
                return response()->json([
                    'success' => false,
                    'message' => 'Asignación no encontrada'
                ], 404);
            }

            $validated = $request->validate([
                'fecha_ini' => 'sometimes|date',
                'fecha_fin' => 'nullable|date|after:fecha_ini',
                'usuario_asig' => 'sometimes|string|max:255'
            ]);

            DB::table('asignacion_funcion')
                ->where('id', $id)
                ->update(array_merge($validated, ['updated_at' => now()]));

            $asignacionActualizada = DB::table('asignacion_funcion')
                ->join('usuarios', 'asignacion_funcion.id_usuario', '=', 'usuarios.id_usuario')
                ->join('funciones', 'asignacion_funcion.id_funcion', '=', 'funciones.id_funcion')
                ->select(
                    'asignacion_funcion.*',
                    'usuarios.nickname',
                    'usuarios.nombre as usuario_nombre',
                    'funciones.nombre as funcion_nombre'
                )
                ->where('asignacion_funcion.id', $id)
                ->first();

            return response()->json([
                'success' => true,
                'data' => $asignacionActualizada,
                'message' => 'Asignación actualizada exitosamente'
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
                'message' => 'Error al actualizar asignación: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified assignment.
     */
    public function destroy($id)
    {
        try {
            $asignacion = DB::table('asignacion_funcion')->where('id', $id)->first();
            
            if (!$asignacion) {
                return response()->json([
                    'success' => false,
                    'message' => 'Asignación no encontrada'
                ], 404);
            }

            DB::table('asignacion_funcion')->where('id', $id)->delete();

            return response()->json([
                'success' => true,
                'message' => 'Asignación eliminada exitosamente'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar asignación: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Finalizar una asignación (establecer fecha_fin)
     */
    public function finalizar(Request $request, $id)
    {
        try {
            $asignacion = DB::table('asignacion_funcion')->where('id', $id)->first();
            
            if (!$asignacion) {
                return response()->json([
                    'success' => false,
                    'message' => 'Asignación no encontrada'
                ], 404);
            }

            $validated = $request->validate([
                'fecha_fin' => 'required|date|after:' . $asignacion->fecha_ini
            ]);

            DB::table('asignacion_funcion')
                ->where('id', $id)
                ->update([
                    'fecha_fin' => $validated['fecha_fin'],
                    'updated_at' => now()
                ]);

            return response()->json([
                'success' => true,
                'message' => 'Asignación finalizada exitosamente'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al finalizar asignación: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener asignaciones por usuario
     */
    public function asignacionesPorUsuario($idUsuario)
    {
        try {
            $asignaciones = DB::table('asignacion_funcion')
                ->join('funciones', 'asignacion_funcion.id_funcion', '=', 'funciones.id_funcion')
                ->select(
                    'asignacion_funcion.*',
                    'funciones.nombre as funcion_nombre',
                    'funciones.descripcion as funcion_descripcion'
                )
                ->where('asignacion_funcion.id_usuario', $idUsuario)
                ->orderBy('asignacion_funcion.fecha_ini', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $asignaciones,
                'message' => 'Asignaciones del usuario obtenidas exitosamente'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener asignaciones: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener asignaciones activas (sin fecha_fin)
     */
    public function asignacionesActivas()
    {
        try {
            $asignaciones = DB::table('asignacion_funcion')
                ->join('usuarios', 'asignacion_funcion.id_usuario', '=', 'usuarios.id_usuario')
                ->join('funciones', 'asignacion_funcion.id_funcion', '=', 'funciones.id_funcion')
                ->select(
                    'asignacion_funcion.*',
                    'usuarios.nickname',
                    'usuarios.nombre as usuario_nombre',
                    'usuarios.ap_paterno',
                    'usuarios.ap_materno',
                    'funciones.nombre as funcion_nombre',
                    'funciones.descripcion as funcion_descripcion'
                )
                ->whereNull('asignacion_funcion.fecha_fin')
                ->orderBy('asignacion_funcion.fecha_ini', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $asignaciones,
                'message' => 'Asignaciones activas obtenidas exitosamente'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener asignaciones activas: ' . $e->getMessage()
            ], 500);
        }
    }
}
