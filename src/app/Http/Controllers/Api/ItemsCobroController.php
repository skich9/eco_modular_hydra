<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ItemsCobro;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ItemsCobroController extends Controller
{
    /**
     * Obtener todos los items de cobro
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        try {
            $items = ItemsCobro::all();
            return response()->json([
                'success' => true,
                'data' => $items
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener items de cobro: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Sincronizar items de cobro desde SGA
     *
     * - No sobreescribe 'nro_creditos' ni 'costo' si ya existen en items_cobro
     *
     * Request opcional:
     * - id_parametro_economico: int (si no se envía, se intentará buscar el parámetro con nombre 'credito')
     */
    public function syncFromSin(Request $request)
    {
        try {
            // Parámetros como en sync de materias
            $sourceArg = strtolower((string) $request->input('source', 'all')); // sga_elec|sga_mec|all
            $chunk = (int) $request->input('chunk', 1000);
            $dry = (bool) $request->boolean('dry_run', false);

            $sources = [];
            switch ($sourceArg) {
                case 'sga_elec': $sources = ['sga_elec']; break;
                case 'sga_mec': $sources = ['sga_mec']; break;
                case 'all':
                default: $sources = ['sga_elec','sga_mec']; break;
            }

            // Delegar al repositorio para mantener el patrón usado con materias
            $repo = app(\App\Repositories\Sga\SgaSyncRepository::class);
            $summary = [];
            foreach ($sources as $src) {
                try {
                    $res = $repo->syncItemsCobro($src, $chunk, $dry);
                    $summary[$src] = $res;
                } catch (\Throwable $e) {
                    $summary[$src] = ['error' => $e->getMessage()];
                }
            }

            return response()->json([
                'success' => true,
                'data' => $summary,
                'message' => 'Sincronización de Items de Cobro ejecutada'
            ]);
        } catch (\Throwable $e) {
            Log::error('Error en syncFromSin: '.$e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al sincronizar items: '.$e->getMessage()
            ], 500);
        }
    }

    /**
     * Almacenar un nuevo item de cobro
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'codigo_producto_interno' => 'required|string|max:15|unique:items_cobro,codigo_producto_interno',
                'nombre_servicio' => 'required|string|max:100',
                'codigo_producto_impuesto' => 'nullable|integer',
                'unidad_medida' => 'required|integer',
                'costo' => 'nullable|numeric|min:0',
                'nro_creditos' => 'required|numeric|min:0',
                'tipo_item' => 'required|string|max:40',
                // descripcion es TEXT en DB; no aplicar max fijo
                'descripcion' => 'nullable|string',
                'estado' => 'nullable|boolean',
                'facturado' => 'required|boolean',
                // actividad_economica es VARCHAR(255) nullable y FK a sin_actividades.codigo_caeb
                // Permitimos NULL si aún no tienes el catálogo cargado.
                'actividad_economica' => [
                    'nullable',
                    'string',
                    'max:255',
                    Rule::exists('sin_actividades', 'codigo_caeb')
                ],
                'id_parametro_economico' => 'required|integer|exists:parametros_economicos,id_parametro_economico'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error de validación',
                    'errors' => $validator->errors()
                ], 422);
            }
            $data = $validator->validated();
            // Normalización ligera
            if (array_key_exists('actividad_economica', $data) && $data['actividad_economica'] !== null) {
                $data['actividad_economica'] = trim((string)$data['actividad_economica']);
            }
            $item = ItemsCobro::create($data);

            return response()->json([
                'success' => true,
                'message' => 'Item de cobro creado correctamente',
                'data' => $item
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear item de cobro: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mostrar un item de cobro específico
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        try {
            $item = ItemsCobro::find($id);

            if (!$item) {
                return response()->json([
                    'success' => false,
                    'message' => 'Item de cobro no encontrado'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $item
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener item de cobro: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Actualizar un item de cobro específico
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        try {
            $item = ItemsCobro::find($id);

            if (!$item) {
                return response()->json([
                    'success' => false,
                    'message' => 'Item de cobro no encontrado'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'codigo_producto_interno' => 'required|string|max:15|unique:items_cobro,codigo_producto_interno,' . $id . ',id_item',
                'nombre_servicio' => 'required|string|max:100',
                'codigo_producto_impuesto' => 'nullable|integer',
                'unidad_medida' => 'required|integer',
                'costo' => 'nullable|numeric|min:0',
                'nro_creditos' => 'required|numeric|min:0',
                'tipo_item' => 'required|string|max:40',
                'descripcion' => 'nullable|string',
                'estado' => 'nullable|boolean',
                'facturado' => 'required|boolean',
                'actividad_economica' => [
                    'nullable',
                    'string',
                    'max:255',
                    Rule::exists('sin_actividades', 'codigo_caeb')
                ],
                'id_parametro_economico' => 'required|integer|exists:parametros_economicos,id_parametro_economico'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error de validación',
                    'errors' => $validator->errors()
                ], 422);
            }
            $data = $validator->validated();
            if (array_key_exists('actividad_economica', $data) && $data['actividad_economica'] !== null) {
                $data['actividad_economica'] = trim((string)$data['actividad_economica']);
            }
            $item->update($data);

            return response()->json([
                'success' => true,
                'message' => 'Item de cobro actualizado correctamente',
                'data' => $item
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar item de cobro: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Eliminar un item de cobro específico
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        try {
            $item = ItemsCobro::find($id);

            if (!$item) {
                return response()->json([
                    'success' => false,
                    'message' => 'Item de cobro no encontrado'
                ], 404);
            }

            $item->delete();

            return response()->json([
                'success' => true,
                'message' => 'Item de cobro eliminado correctamente'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar item de cobro: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cambiar el estado de un item de cobro
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function toggleStatus($id)
    {
        try {
            $item = ItemsCobro::find($id);

            if (!$item) {
                return response()->json([
                    'success' => false,
                    'message' => 'Item de cobro no encontrado'
                ], 404);
            }

            $item->estado = !$item->estado;
            $item->save();

            return response()->json([
                'success' => true,
                'message' => 'Estado de item de cobro actualizado correctamente',
                'data' => $item
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al cambiar estado de item de cobro: ' . $e->getMessage()
            ], 500);
        }
    }
}
