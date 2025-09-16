<?php

namespace App\Http\Controllers;

use App\Models\Gestion;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class GestionController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        try {
            $gestiones = Gestion::orderBy('orden', 'asc')->get();
            
            return response()->json([
                'success' => true,
                'data' => $gestiones,
                'message' => 'Lista de gestiones obtenida exitosamente'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener las gestiones: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            // Mapear estado -> activo si viene desde el frontend
            $input = $request->all();
            // Recortar 'gestion' y normalizar fechas opcionales
            if (isset($input['gestion']) && is_string($input['gestion'])) {
                $input['gestion'] = trim($input['gestion']);
            }
            if (array_key_exists('estado', $input) && !array_key_exists('activo', $input)) {
                $input['activo'] = filter_var($input['estado'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                unset($input['estado']);
            }
            foreach (['fecha_graduacion'] as $f) {
                if (array_key_exists($f, $input) && $input[$f] === '') {
                    $input[$f] = null;
                }
            }
            $validator = Validator::make($input, [
                'gestion' => 'required|string|max:30|unique:gestion,gestion',
                'fecha_ini' => 'required|date',
                'fecha_fin' => 'required|date|after_or_equal:fecha_ini',
                'orden' => 'required|integer|min:1',
                'fecha_graduacion' => 'nullable|date|after_or_equal:fecha_ini',
                'activo' => 'nullable|boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors(),
                    'message' => 'Error de validación'
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $gestion = Gestion::create($validator->validated());

            return response()->json([
                'success' => true,
                'data' => $gestion,
                'message' => 'Gestión creada exitosamente'
            ], Response::HTTP_CREATED);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear la gestión: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $gestion): JsonResponse
    {
        try {
            $gestionModel = Gestion::findOrFail($gestion);

            return response()->json([
                'success' => true,
                'data' => $gestionModel,
                'message' => 'Gestión obtenida exitosamente'
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gestión no encontrada'
            ], Response::HTTP_NOT_FOUND);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener la gestión: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $gestion): JsonResponse
    {
        try {
            $gestionModel = Gestion::findOrFail($gestion);

            $input = $request->all();
            if (array_key_exists('estado', $input) && !array_key_exists('activo', $input)) {
                $input['activo'] = filter_var($input['estado'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                unset($input['estado']);
            }
            $validator = Validator::make($input, [
                'fecha_ini' => 'sometimes|required|date',
                'fecha_fin' => 'sometimes|required|date|after_or_equal:fecha_ini',
                'orden' => 'sometimes|required|integer|min:1',
                'fecha_graduacion' => 'nullable|date|after_or_equal:fecha_ini',
                'activo' => 'nullable|boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors(),
                    'message' => 'Error de validación'
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $gestionModel->update($validator->validated());

            return response()->json([
                'success' => true,
                'data' => $gestionModel,
                'message' => 'Gestión actualizada exitosamente'
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gestión no encontrada'
            ], Response::HTTP_NOT_FOUND);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar la gestión: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $gestion): JsonResponse
    {
        try {
            $gestionModel = Gestion::findOrFail($gestion);
            $gestionModel->delete();

            return response()->json([
                'success' => true,
                'message' => 'Gestión eliminada exitosamente'
            ], Response::HTTP_OK);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gestión no encontrada'
            ], Response::HTTP_NOT_FOUND);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar la gestión: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Obtener la gestión actual (activa)
     */
    public function gestionActual(): JsonResponse
    {
        try {
            $gestionActual = Gestion::activa()->porOrden('desc')->first();

            if (!$gestionActual) {
                return response()->json([
                    'success' => false,
                    'message' => 'No hay gestión activa'
                ], Response::HTTP_NOT_FOUND);
            }

            return response()->json([
                'success' => true,
                'data' => $gestionActual,
                'message' => 'Gestión actual obtenida exitosamente'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener la gestión actual: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Obtener gestiones activas
     */
    public function gestionesActivas(): JsonResponse
    {
        try {
            $gestionesActivas = Gestion::activa()->porOrden('asc')->get();

            return response()->json([
                'success' => true,
                'data' => $gestionesActivas,
                'message' => 'Gestiones activas obtenidas exitosamente'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener las gestiones activas: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Obtener gestiones por año
     */
    public function porAnio(string $anio): JsonResponse
    {
        try {
            $gestiones = Gestion::porAnio($anio);

            return response()->json([
                'success' => true,
                'data' => $gestiones,
                'message' => "Gestiones del año {$anio} obtenidas exitosamente"
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener las gestiones por año: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Cambiar el estado de una gestión
     */
    public function cambiarEstado(Request $request, string $gestion): JsonResponse
    {
        try {
            $gestionModel = Gestion::findOrFail($gestion);

            // Aceptar tanto 'estado' como 'activo' desde el frontend
            $hasEstado = $request->has('estado');
            $hasActivo = $request->has('activo');
            if (!$hasEstado && !$hasActivo) {
                return response()->json([
                    'success' => false,
                    'errors' => ['estado' => ['El campo estado/activo es requerido']],
                    'message' => 'Error de validación'
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $nuevoEstado = $hasEstado ? $request->boolean('estado') : $request->boolean('activo');

            $gestionModel->update(['activo' => $nuevoEstado]);

            return response()->json([
                'success' => true,
                'data' => $gestionModel,
                'message' => 'Estado de la gestión actualizado exitosamente'
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gestión no encontrada'
            ], Response::HTTP_NOT_FOUND);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al cambiar el estado: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
