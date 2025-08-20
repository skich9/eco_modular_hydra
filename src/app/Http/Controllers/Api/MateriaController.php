<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Materia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class MateriaController extends Controller
{
    /**
     * Obtener todas las materias
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        try {
            $materias = Materia::with('parametroEconomico')->get();
            return response()->json([
                'success' => true,
                'data' => $materias
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener materias: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Almacenar una nueva materia
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'sigla_materia' => 'required|string|max:10',
                'cod_pensum' => 'required|string|max:10',
                'nombre_materia' => 'required|string|max:100',
                'nombre_material_oficial' => 'required|string|max:100',
                'nro_creditos' => 'required|integer|min:1',
                'orden' => 'required|integer|min:1',
                'id_parametro_economico' => 'required|integer|exists:parametros_economicos,id_parametro_economico',
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

            // Verificar si ya existe la materia con esa sigla y pensum
            $exists = Materia::where('sigla_materia', $request->sigla_materia)
                ->where('cod_pensum', $request->cod_pensum)
                ->exists();

            if ($exists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ya existe una materia con esa sigla y pensum'
                ], 422);
            }

            $materia = Materia::create($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Materia creada correctamente',
                'data' => $materia
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear materia: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mostrar una materia específica
     *
     * @param  string  $sigla
     * @param  string  $pensum
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($sigla, $pensum)
    {
        try {
            $materia = Materia::with('parametroEconomico')
                ->where('sigla_materia', $sigla)
                ->where('cod_pensum', $pensum)
                ->first();

            if (!$materia) {
                return response()->json([
                    'success' => false,
                    'message' => 'Materia no encontrada'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $materia
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener materia: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Actualizar una materia específica
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $sigla
     * @param  string  $pensum
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $sigla, $pensum)
    {
        try {
            $materia = Materia::where('sigla_materia', $sigla)
                ->where('cod_pensum', $pensum)
                ->first();

            if (!$materia) {
                return response()->json([
                    'success' => false,
                    'message' => 'Materia no encontrada'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'nombre_materia' => 'required|string|max:100',
                'nombre_material_oficial' => 'required|string|max:100',
                'nro_creditos' => 'required|integer|min:1',
                'orden' => 'required|integer|min:1',
                'id_parametro_economico' => 'required|integer|exists:parametros_economicos,id_parametro_economico',
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

            $materia->update($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Materia actualizada correctamente',
                'data' => $materia
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar materia: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Eliminar una materia específica
     *
     * @param  string  $sigla
     * @param  string  $pensum
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($sigla, $pensum)
    {
        try {
            $materia = Materia::where('sigla_materia', $sigla)
                ->where('cod_pensum', $pensum)
                ->first();

            if (!$materia) {
                return response()->json([
                    'success' => false,
                    'message' => 'Materia no encontrada'
                ], 404);
            }

            $materia->delete();

            return response()->json([
                'success' => true,
                'message' => 'Materia eliminada correctamente'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar materia: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cambiar el estado de una materia
     *
     * @param  string  $sigla
     * @param  string  $pensum
     * @return \Illuminate\Http\JsonResponse
     */
    public function toggleStatus($sigla, $pensum)
    {
        try {
            $materia = Materia::where('sigla_materia', $sigla)
                ->where('cod_pensum', $pensum)
                ->first();

            if (!$materia) {
                return response()->json([
                    'success' => false,
                    'message' => 'Materia no encontrada'
                ], 404);
            }

            $materia->estado = !$materia->estado;
            $materia->save();

            return response()->json([
                'success' => true,
                'message' => 'Estado de materia actualizado correctamente',
                'data' => $materia
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al cambiar estado de materia: ' . $e->getMessage()
            ], 500);
        }
    }
}
