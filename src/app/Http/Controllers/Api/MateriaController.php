<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Materia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

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
            $materias = Materia::with(['pensum'])->get();
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
     * Actualizar créditos en lote.
     * Body esperado: { items: [ { sigla_materia, cod_pensum, nro_creditos } ] }
     */
    public function batchUpdateCredits(Request $request)
    {
        $payload = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.sigla_materia' => 'required|string',
            'items.*.cod_pensum' => 'required|string',
            'items.*.nro_creditos' => 'required|numeric|min:0',
        ]);

        $updated = 0; $notFound = 0;
        DB::transaction(function () use ($payload, & $updated, & $notFound) {
            foreach ($payload['items'] as $it) {
                $count = Materia::where('sigla_materia', $it['sigla_materia'])
                    ->where('cod_pensum', $it['cod_pensum'])
                    ->update(['nro_creditos' => $it['nro_creditos']]);
                if ($count > 0) { $updated += $count; } else { $notFound++; }
            }
        });

        return response()->json([
            'success' => true,
            'data' => [ 'updated' => $updated, 'not_found' => $notFound ],
        ]);
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
            $input = $request->all();
            $hasActivo = Schema::hasColumn('materia', 'activo');
            // Mapear estado -> activo si aplica
            if ($hasActivo) {
                if (array_key_exists('estado', $input) && !array_key_exists('activo', $input)) {
                    $input['activo'] = filter_var($input['estado'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    unset($input['estado']);
                }
            }

            $rules = [
                'sigla_materia' => 'required|string|max:10',
                'cod_pensum' => 'required|string|max:10',
                'nombre_materia' => 'required|string|max:100',
                'nombre_material_oficial' => 'required|string|max:100',
                'nivel_materia' => 'required|string|max:50',
                'nro_creditos' => 'required|numeric|min:1',
                'orden' => 'required|integer|min:1',
                'descripcion' => 'nullable|string|max:255',
            ];
            // Requerir el campo de estado acorde a la columna existente
            if ($hasActivo) {
                $rules['activo'] = 'required|boolean';
            } else {
                $rules['estado'] = 'required|boolean';
            }

            $validator = Validator::make($input, $rules);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error de validación',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Verificar si ya existe la materia con esa sigla y pensum
            $data = $validator->validated();
            $exists = Materia::where('sigla_materia', $data['sigla_materia'])
                ->where('cod_pensum', $data['cod_pensum'])
                ->exists();

            if ($exists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ya existe una materia con esa sigla y pensum'
                ], 422);
            }

            $materia = Materia::create($data);

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
            $materia = Materia::with(['pensum'])
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

            $input = $request->all();
            $hasActivo = Schema::hasColumn('materia', 'activo');
            if ($hasActivo) {
                if (array_key_exists('estado', $input) && !array_key_exists('activo', $input)) {
                    $input['activo'] = filter_var($input['estado'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    unset($input['estado']);
                }
            }

            $rules = [
                'nombre_materia' => 'required|string|max:100',
                'nombre_material_oficial' => 'required|string|max:100',
                'nivel_materia' => 'required|string|max:50',
                'nro_creditos' => 'required|numeric|min:1',
                'orden' => 'required|integer|min:1',
                'descripcion' => 'nullable|string|max:255',
            ];
            if ($hasActivo) {
                $rules['activo'] = 'required|boolean';
            } else {
                $rules['estado'] = 'required|boolean';
            }

            $validator = Validator::make($input, $rules);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error de validación',
                    'errors' => $validator->errors()
                ], 422);
            }

            $materia->update($validator->validated());

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

            $hasActivo = Schema::hasColumn('materia', 'activo');
            if ($hasActivo) {
                $materia->activo = !$materia->estado; // usa accessor estado
            } else {
                $materia->estado = !$materia->estado;
            }
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

    /**
     * Obtener materias por código de pensum
     */
    public function getByPensum(string $codPensum)
    {
        try {
            if (!Schema::hasTable('materia')) {
                return response()->json(['success' => true, 'data' => []]);
            }

            // Consulta simple con columnas reales del esquema proporcionado
            $rows = DB::table('materia')
                ->where('cod_pensum', $codPensum)
                ->orderBy('orden')
                ->orderBy('sigla_materia')
                ->get([
                    'sigla_materia',
                    'cod_pensum',
                    'nombre_materia',
                    'nombre_material_oficial',
                    'nivel_materia',
                    'activo',
                    'orden',
                    'descripcion',
                    'nro_creditos',
                    'created_at',
                    'updated_at',
                ]);

            // Normalización para el frontend
            $data = $rows->map(function ($r) {
                $arr = (array) $r;
                $arr['estado'] = isset($arr['activo']) ? (bool)$arr['activo'] : true;
                if (!array_key_exists('nivel_materia', $arr) || $arr['nivel_materia'] === null || $arr['nivel_materia'] === '') {
                    $arr['nivel_materia'] = '1';
                }
                if (!array_key_exists('nro_creditos', $arr) || $arr['nro_creditos'] === null || $arr['nro_creditos'] === '') {
                    $arr['nro_creditos'] = 0;
                }
                if (!array_key_exists('orden', $arr) || $arr['orden'] === null || $arr['orden'] === '') {
                    $arr['orden'] = 0;
                }
                return $arr;
            })->values()->all();

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            Log::error('Error al obtener materias por pensum: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener materias por pensum',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Buscar materias por nombre o sigla
     */
    public function search(Request $request)
    {
        try {
            $term = trim((string) $request->query('term', ''));
            $query = Materia::with(['pensum']);

            if ($term !== '') {
                $query->where(function ($q) use ($term) {
                    $q->where('nombre_materia', 'like', "%{$term}%")
                      ->orWhere('sigla_materia', 'like', "%{$term}%");
                });
            }

            $materias = $query->limit(100)->get();

            return response()->json([
                'success' => true,
                'data' => $materias,
            ]);
        } catch (\Exception $e) {
            Log::error('Error al buscar materias: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al buscar materias',
            ], 500);
        }
    }
}
