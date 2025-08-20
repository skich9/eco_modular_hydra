<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ParametrosEconomicos;
use App\Models\ItemsCobro;
use App\Models\Materia;
use App\Models\Pensum;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class ParametrosSistemaController extends Controller
{
    /**
     * Muestra la vista principal de parámetros del sistema
     */
    public function index()
    {
        // Obtener pensums para el selector de materias
        $pensums = Pensum::all();
        
        return view('configuracion.parametros_sistema.index', compact('pensums'));
    }
    
    /**
     * API: Obtener todos los parámetros económicos
     */
    public function getParametrosEconomicos()
    {
        try {
            $parametros = ParametrosEconomicos::all();
            return response()->json([
                'success' => true,
                'data' => $parametros
            ]);
        } catch (\Exception $e) {
            Log::error('Error al obtener parámetros económicos: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener parámetros económicos',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * API: Almacenar un nuevo parámetro económico
     */
    public function storeParametroEconomico(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'nombre' => 'required|string|max:20',
                'valor' => 'required|string',
                'descripcion' => 'nullable|string|max:50',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error de validación',
                    'errors' => $validator->errors()
                ], 422);
            }
            
            $parametro = ParametrosEconomicos::create([
                'nombre' => $request->nombre,
                'valor' => $request->valor,
                'descripcion' => $request->descripcion,
                'estado' => $request->estado ?? 1,
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Parámetro económico creado correctamente',
                'data' => $parametro
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error al crear parámetro económico: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al crear parámetro económico',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * API: Mostrar un parámetro económico específico
     */
    public function showParametroEconomico($id)
    {
        try {
            $parametro = ParametrosEconomicos::findOrFail($id);
            return response()->json([
                'success' => true,
                'data' => $parametro
            ]);
        } catch (\Exception $e) {
            Log::error('Error al obtener parámetro económico: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener parámetro económico',
                'error' => $e->getMessage()
            ], 404);
        }
    }
    
    /**
     * API: Actualizar un parámetro económico específico
     */
    public function updateParametroEconomico(Request $request, $id)
    {
        try {
            $validator = Validator::make($request->all(), [
                'nombre' => 'required|string|max:20',
                'valor' => 'required|string',
                'descripcion' => 'nullable|string|max:50',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error de validación',
                    'errors' => $validator->errors()
                ], 422);
            }
            
            $parametro = ParametrosEconomicos::findOrFail($id);
            $parametro->update([
                'nombre' => $request->nombre,
                'valor' => $request->valor,
                'descripcion' => $request->descripcion,
                'estado' => $request->estado ?? $parametro->estado,
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Parámetro económico actualizado correctamente',
                'data' => $parametro
            ]);
        } catch (\Exception $e) {
            Log::error('Error al actualizar parámetro económico: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar parámetro económico',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * API: Eliminar un parámetro económico específico
     */
    public function destroyParametroEconomico($id)
    {
        try {
            $parametro = ParametrosEconomicos::findOrFail($id);
            $parametro->delete();
            
            return response()->json([
                'success' => true,
                'message' => 'Parámetro económico eliminado correctamente'
            ]);
        } catch (\Exception $e) {
            Log::error('Error al eliminar parámetro económico: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar parámetro económico',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * API: Cambiar el estado de un parámetro económico
     */
    public function toggleStatusParametroEconomico($id)
    {
        try {
            $parametro = ParametrosEconomicos::findOrFail($id);
            $parametro->estado = !$parametro->estado;
            $parametro->save();
            
            return response()->json([
                'success' => true,
                'message' => 'Estado actualizado correctamente',
                'data' => $parametro
            ]);
        } catch (\Exception $e) {
            Log::error('Error al cambiar estado del parámetro económico: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al cambiar estado',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * API: Obtener todos los items de cobro
     */
    public function getItemsCobro()
    {
        try {
            $items = ItemsCobro::all();
            return response()->json([
                'success' => true,
                'data' => $items
            ]);
        } catch (\Exception $e) {
            Log::error('Error al obtener items de cobro: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener items de cobro',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * API: Almacenar un nuevo item de cobro
     */
    public function storeItemCobro(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'nombre_servicio' => 'required|string|max:255',
                'codigo_producto_interno' => 'required|string|max:50',
                'codigo_producto_impuesto' => 'nullable|string|max:20',
                'unidad_medida' => 'required|integer|min:1',
                'nro_creditos' => 'required|numeric|min:0',
                'costo' => 'nullable|numeric|min:0',
                'facturado' => 'required|integer|in:0,1',
                'actividad_economica' => 'required|string|max:50',
                'id_parametro_economico' => 'required|integer|exists:parametros_economicos,id_parametro_economico',
                'tipo_item' => 'required|string|max:20',
                'descripcion' => 'nullable|string|max:500',
                'estado' => 'required|integer|in:0,1',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error de validación',
                    'errors' => $validator->errors()
                ], 422);
            }
            
            $item = ItemsCobro::create([
                'nombre_servicio' => $request->nombre_servicio,
                'codigo_producto_interno' => $request->codigo_producto_interno,
                'codigo_producto_impuesto' => $request->codigo_producto_impuesto ?? null,
                'unidad_medida' => $request->unidad_medida ?? 'UNIDAD',
                'nro_creditos' => $request->nro_creditos ?? 0,
                'costo' => $request->costo,
                'facturado' => $request->facturado ?? 1,
                'actividad_economica' => $request->actividad_economica ?? null,
                'descripcion' => $request->descripcion,
                'tipo_item' => $request->tipo_item ?? 'REGULAR',
                'estado' => $request->estado ?? 1,
                'id_parametro_economico' => $request->id_parametro_economico ?? null,
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Item de cobro creado correctamente',
                'data' => $item
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error al crear item de cobro: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al crear item de cobro',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * API: Mostrar un item de cobro específico
     */
    public function showItemCobro($id)
    {
        try {
            $item = ItemsCobro::findOrFail($id);
            return response()->json([
                'success' => true,
                'data' => $item
            ]);
        } catch (\Exception $e) {
            Log::error('Error al obtener item de cobro: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener item de cobro',
                'error' => $e->getMessage()
            ], 404);
        }
    }
    
    /**
     * API: Actualizar un item de cobro específico
     */
    public function updateItemCobro(Request $request, $id)
    {
        try {
            $validator = Validator::make($request->all(), [
                'nombre_servicio' => 'required|string',
                'codigo_producto_interno' => 'required|string',
                'costo' => 'required|numeric',
                'descripcion' => 'nullable|string',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error de validación',
                    'errors' => $validator->errors()
                ], 422);
            }
            
            $item = ItemsCobro::findOrFail($id);
            $item->update([
                'nombre_servicio' => $request->nombre_servicio,
                'codigo_producto_interno' => $request->codigo_producto_interno,
                'codigo_producto_impuesto' => $request->codigo_producto_impuesto ?? $item->codigo_producto_impuesto,
                'unidad_medida' => $request->unidad_medida ?? $item->unidad_medida,
                'nro_creditos' => $request->nro_creditos ?? $item->nro_creditos,
                'costo' => $request->costo,
                'facturado' => $request->facturado ?? $item->facturado,
                'actividad_economica' => $request->actividad_economica ?? $item->actividad_economica,
                'descripcion' => $request->descripcion,
                'tipo_item' => $request->tipo_item ?? $item->tipo_item,
                'estado' => $request->estado ?? $item->estado,
                'id_parametro_economico' => $request->id_parametro_economico ?? $item->id_parametro_economico,
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Item de cobro actualizado correctamente',
                'data' => $item
            ]);
        } catch (\Exception $e) {
            Log::error('Error al actualizar item de cobro: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar item de cobro',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * API: Eliminar un item de cobro específico
     */
    public function destroyItemCobro($id)
    {
        try {
            $item = ItemsCobro::findOrFail($id);
            $item->delete();
            
            return response()->json([
                'success' => true,
                'message' => 'Item de cobro eliminado correctamente'
            ]);
        } catch (\Exception $e) {
            Log::error('Error al eliminar item de cobro: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar item de cobro',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * API: Cambiar el estado de un item de cobro
     */
    public function toggleStatusItemCobro($id)
    {
        try {
            $item = ItemsCobro::findOrFail($id);
            $item->estado = !$item->estado;
            $item->save();
            
            return response()->json([
                'success' => true,
                'message' => 'Estado actualizado correctamente',
                'data' => $item
            ]);
        } catch (\Exception $e) {
            Log::error('Error al cambiar estado del item de cobro: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al cambiar estado',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * API: Obtener todas las materias
     */
    public function getMaterias()
    {
        try {
            $materias = Materia::all();
            return response()->json([
                'success' => true,
                'data' => $materias
            ]);
        } catch (\Exception $e) {
            Log::error('Error al obtener materias: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener materias',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * API: Almacenar una nueva materia
     */
    public function storeMateria(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'sigla_materia' => 'required|string',
                'cod_pensum' => 'required|string',
                'nombre_materia' => 'required|string',
                'nro_creditos' => 'required|integer',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error de validación',
                    'errors' => $validator->errors()
                ], 422);
            }
            
            // Verificar si ya existe la materia con la misma sigla y pensum
            $existente = Materia::where('sigla_materia', $request->sigla_materia)
                ->where('cod_pensum', $request->cod_pensum)
                ->first();
                
            if ($existente) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ya existe una materia con esta sigla y pensum',
                ], 422);
            }
            
            $materia = new Materia([
                'sigla_materia' => $request->sigla_materia,
                'cod_pensum' => $request->cod_pensum,
                'nombre_materia' => $request->nombre_materia,
                'nombre_material_oficial' => $request->nombre_material_oficial ?? $request->nombre_materia,
                'estado' => $request->estado ?? 1,
                'orden' => $request->orden ?? 0,
                'descripcion' => $request->descripcion,
                'id_parametro_economico' => $request->id_parametro_economico ?? null,
                'nro_creditos' => $request->nro_creditos,
            ]);
            
            $materia->save();
            
            return response()->json([
                'success' => true,
                'message' => 'Materia creada correctamente',
                'data' => $materia
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error al crear materia: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al crear materia',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * API: Mostrar una materia específica
     */
    public function showMateria($sigla, $codPensum)
    {
        try {
            $materia = Materia::where('sigla_materia', $sigla)
                ->where('cod_pensum', $codPensum)
                ->firstOrFail();
                
            return response()->json([
                'success' => true,
                'data' => $materia
            ]);
        } catch (\Exception $e) {
            Log::error('Error al obtener materia: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener materia',
                'error' => $e->getMessage()
            ], 404);
        }
    }
    
    /**
     * API: Actualizar una materia específica
     */
    public function updateMateria(Request $request, $sigla, $codPensum)
    {
        try {
            $validator = Validator::make($request->all(), [
                'nombre_materia' => 'required|string',
                'nro_creditos' => 'required|integer',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error de validación',
                    'errors' => $validator->errors()
                ], 422);
            }
            
            $materia = Materia::where('sigla_materia', $sigla)
                ->where('cod_pensum', $codPensum)
                ->firstOrFail();
                
            $materia->update([
                'nombre_materia' => $request->nombre_materia,
                'nombre_material_oficial' => $request->nombre_material_oficial ?? $materia->nombre_material_oficial,
                'estado' => $request->estado ?? $materia->estado,
                'orden' => $request->orden ?? $materia->orden,
                'descripcion' => $request->descripcion,
                'id_parametro_economico' => $request->id_parametro_economico ?? $materia->id_parametro_economico,
                'nro_creditos' => $request->nro_creditos,
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Materia actualizada correctamente',
                'data' => $materia
            ]);
        } catch (\Exception $e) {
            Log::error('Error al actualizar materia: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar materia',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * API: Eliminar una materia específica
     */
    public function destroyMateria($sigla, $codPensum)
    {
        try {
            $materia = Materia::where('sigla_materia', $sigla)
                ->where('cod_pensum', $codPensum)
                ->firstOrFail();
                
            $materia->delete();
            
            return response()->json([
                'success' => true,
                'message' => 'Materia eliminada correctamente'
            ]);
        } catch (\Exception $e) {
            Log::error('Error al eliminar materia: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar materia',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * API: Cambiar el estado de una materia
     */
    public function toggleStatusMateria($sigla, $codPensum)
    {
        try {
            $materia = Materia::where('sigla_materia', $sigla)
                ->where('cod_pensum', $codPensum)
                ->firstOrFail();
                
            $materia->estado = !$materia->estado;
            $materia->save();
            
            return response()->json([
                'success' => true,
                'message' => 'Estado actualizado correctamente',
                'data' => $materia
            ]);
        } catch (\Exception $e) {
            Log::error('Error al cambiar estado de la materia: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al cambiar estado',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
