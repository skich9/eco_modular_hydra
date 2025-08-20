<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ParametrosEconomicos;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ParametrosEconomicosController extends Controller
{
    /**
     * Obtener todos los parámetros económicos
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        try {
            $parametros = ParametrosEconomicos::all();
            return response()->json([
                'success' => true,
                'data' => $parametros
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener parámetros económicos: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Almacenar un nuevo parámetro económico
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'nombre' => 'required|string|max:20',
                'valor' => 'required|numeric',
                'descripcion' => 'nullable|string|max:50',
                'estado' => 'required|boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error de validación',
                    'errors' => $validator->errors()
                ], 422);
            }

            $parametro = ParametrosEconomicos::create($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Parámetro económico creado correctamente',
                'data' => $parametro
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear parámetro económico: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mostrar un parámetro económico específico
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        try {
            $parametro = ParametrosEconomicos::find($id);

            if (!$parametro) {
                return response()->json([
                    'success' => false,
                    'message' => 'Parámetro económico no encontrado'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $parametro
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener parámetro económico: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Actualizar un parámetro económico específico
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        try {
            $parametro = ParametrosEconomicos::find($id);

            if (!$parametro) {
                return response()->json([
                    'success' => false,
                    'message' => 'Parámetro económico no encontrado'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'nombre' => 'required|string|max:20',
                'valor' => 'required|numeric',
                'descripcion' => 'nullable|string|max:50',
                'estado' => 'required|boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error de validación',
                    'errors' => $validator->errors()
                ], 422);
            }

            $parametro->update($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Parámetro económico actualizado correctamente',
                'data' => $parametro
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar parámetro económico: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Eliminar un parámetro económico específico
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        try {
            $parametro = ParametrosEconomicos::find($id);

            if (!$parametro) {
                return response()->json([
                    'success' => false,
                    'message' => 'Parámetro económico no encontrado'
                ], 404);
            }

            // Verificar si hay items de cobro que usan este parámetro
            $itemsCount = \App\Models\ItemsCobro::where('id_parametro_economico', $id)->count();
            
            // Verificar si hay materias que usan este parámetro
            $materiasCount = \App\Models\Materia::where('id_parametro_economico', $id)->count();
            
            if ($itemsCount > 0 || $materiasCount > 0) {
                $dependencias = [];
                if ($itemsCount > 0) {
                    $dependencias[] = "{$itemsCount} item(s) de cobro";
                }
                if ($materiasCount > 0) {
                    $dependencias[] = "{$materiasCount} materia(s)";
                }
                
                return response()->json([
                    'success' => false,
                    'message' => 'No se puede eliminar este parámetro económico porque está siendo usado por: ' . implode(' y ', $dependencias) . '. Primero debe cambiar o eliminar esas referencias.',
                    'error_type' => 'foreign_key_constraint',
                    'dependencies' => [
                        'items_cobro' => $itemsCount,
                        'materias' => $materiasCount
                    ]
                ], 409); // 409 Conflict
            }

            $parametro->delete();

            return response()->json([
                'success' => true,
                'message' => 'Parámetro económico eliminado correctamente'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar parámetro económico: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cambiar el estado de un parámetro económico
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function toggleStatus($id)
    {
        try {
            $parametro = ParametrosEconomicos::find($id);

            if (!$parametro) {
                return response()->json([
                    'success' => false,
                    'message' => 'Parámetro económico no encontrado'
                ], 404);
            }

            $parametro->estado = !$parametro->estado;
            $parametro->save();

            return response()->json([
                'success' => true,
                'message' => 'Estado de parámetro económico actualizado correctamente',
                'data' => $parametro
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al cambiar estado de parámetro económico: ' . $e->getMessage()
            ], 500);
        }
    }
}
