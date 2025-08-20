<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ItemsCobro;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

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
     * Almacenar un nuevo item de cobro
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'codigo_producto_interno' => 'required|string|max:15',
                'nombre_servicio' => 'required|string|max:100',
                'codigo_producto_impuesto' => 'nullable|integer',
                'unidad_medida' => 'required|integer',
                'costo' => 'nullable|integer',
                'nro_creditos' => 'required|numeric',
                'tipo_item' => 'required|string|max:40',
                'descripcion' => 'nullable|string|max:50',
                'estado' => 'nullable|boolean',
                'facturado' => 'required|boolean',
                'actividad_economica' => 'required|string|max:255',
                'id_parametro_economico' => 'required|integer'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error de validación',
                    'errors' => $validator->errors()
                ], 422);
            }

            $item = ItemsCobro::create($request->all());

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
                'costo' => 'nullable|integer',
                'nro_creditos' => 'required|numeric',
                'tipo_item' => 'required|string|max:40',
                'descripcion' => 'nullable|string|max:50',
                'estado' => 'nullable|boolean',
                'facturado' => 'required|boolean',
                'actividad_economica' => 'required|string|max:255',
                'id_parametro_economico' => 'required|integer'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error de validación',
                    'errors' => $validator->errors()
                ], 422);
            }

            $item->update($request->all());

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
