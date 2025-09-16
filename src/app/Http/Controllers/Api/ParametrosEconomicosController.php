<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ParametrosEconomicos;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\QueryException;

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
                'valor' => 'required|string|max:255',
                'descripcion' => 'nullable|string|max:255',
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
            $nombre = request()->query('nombre');
            if ($nombre !== null) {
                $parametro = ParametrosEconomicos::where('id_parametro_economico', $id)
                    ->where('nombre', $nombre)
                    ->first();
            } else {
                $parametro = ParametrosEconomicos::find($id);
            }

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
            $nombre = $request->query('nombre');
            if ($nombre !== null) {
                $parametro = ParametrosEconomicos::where('id_parametro_economico', $id)
                    ->where('nombre', $nombre)
                    ->first();
            } else {
                $parametro = ParametrosEconomicos::find($id);
            }

            if (!$parametro) {
                return response()->json([
                    'success' => false,
                    'message' => 'Parámetro económico no encontrado'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'nombre' => 'required|string|max:20',
                'valor' => 'required|string|max:255',
                'descripcion' => 'nullable|string|max:255',
                'estado' => 'required|boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error de validación',
                    'errors' => $validator->errors()
                ], 422);
            }

            if ($nombre !== null) {
                ParametrosEconomicos::where('id_parametro_economico', $id)
                    ->where('nombre', $nombre)
                    ->update($request->all());

                // Si el nombre cambió, recargar usando el nuevo valor
                $nombreActual = $request->input('nombre', $nombre);
                $parametro = ParametrosEconomicos::where('id_parametro_economico', $id)
                    ->where('nombre', $nombreActual)
                    ->first();
            } else {
                $parametro->update($request->all());
            }

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
            $nombre = request()->query('nombre');
            if ($nombre !== null) {
                $parametro = ParametrosEconomicos::where('id_parametro_economico', $id)
                    ->where('nombre', $nombre)
                    ->first();
            } else {
                $parametro = ParametrosEconomicos::find($id);
            }

            if (!$parametro) {
                return response()->json([
                    'success' => false,
                    'message' => 'Parámetro económico no encontrado'
                ], 404);
            }

            // Verificar si hay items de cobro que usan este parámetro
            $itemsCount = \App\Models\ItemsCobro::where('id_parametro_economico', $id)->count();

            // Verificar si hay materias que usan este parámetro (solo si existe la columna)
            $materiasCount = 0;
            if (Schema::hasTable('materia') && Schema::hasColumn('materia', 'id_parametro_economico')) {
                $materiasCount = \App\Models\Materia::where('id_parametro_economico', $id)->count();
            }

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

            try {
                if ($nombre !== null) {
                    ParametrosEconomicos::where('id_parametro_economico', $id)
                        ->where('nombre', $nombre)
                        ->delete();
                } else {
                    $parametro->delete();
                }
            } catch (QueryException $qe) {
                // Manejo defensivo: si la BD lanza violación de FK, responder 409 con mensaje claro
                $sqlState = $qe->getCode(); // MySQL 23000; driverCode 1451
                return response()->json([
                    'success' => false,
                    'message' => 'No se puede eliminar este parámetro económico porque tiene registros relacionados.',
                    'error_type' => 'foreign_key_constraint',
                    'sql_state' => $sqlState,
                ], 409);
            }

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
            $nombre = request()->query('nombre');
            if ($nombre !== null) {
                $parametro = ParametrosEconomicos::where('id_parametro_economico', $id)
                    ->where('nombre', $nombre)
                    ->first();
            } else {
                $parametro = ParametrosEconomicos::find($id);
            }

            if (!$parametro) {
                return response()->json([
                    'success' => false,
                    'message' => 'Parámetro económico no encontrado'
                ], 404);
            }

            $nuevoEstado = !$parametro->estado;

            if ($nombre !== null) {
                ParametrosEconomicos::where('id_parametro_economico', $id)
                    ->where('nombre', $nombre)
                    ->update(['estado' => $nuevoEstado]);
                $parametro->estado = $nuevoEstado;
            } else {
                $parametro->estado = $nuevoEstado;
                $parametro->save();
            }

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
