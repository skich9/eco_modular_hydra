<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Carrera;
use App\Models\Pensum;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class CarreraController extends Controller
{
    /**
     * Listar todas las carreras
     */
    public function index()
    {
        try {
            $carreras = Carrera::query()->get();
            return response()->json([
                'success' => true,
                'data' => $carreras,
            ]);
        } catch (\Exception $e) {
            Log::error('Error al listar carreras: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al listar carreras',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Listar pensums por código de carrera
     */
    public function pensums(string $codigo)
    {
        try {
            // Validar existencia de la carrera (opcional pero útil)
            $carrera = Carrera::where('codigo_carrera', $codigo)->first();
            if (!$carrera) {
                return response()->json([
                    'success' => false,
                    'message' => 'Carrera no encontrada',
                ], 404);
            }

            if (!Schema::hasTable('pensums')) {
                Log::warning("Tabla 'pensums' no existe; devolviendo lista vacía para codigo_carrera={$codigo}");
                return response()->json([
                    'success' => true,
                    'data' => [],
                ]);
            }

            // Consulta directa tolerante a columnas opcionales
            $allCols = DB::getSchemaBuilder()->getColumnListing('pensums');
            $want = ['cod_pensum','codigo_carrera','nombre','descripcion','cantidad_semestres','orden','nivel','estado','created_at','updated_at'];
            $selectCols = array_values(array_intersect($want, $allCols));
            if (empty($selectCols)) { $selectCols = ['cod_pensum']; }

            $q = DB::table('pensums')->where('codigo_carrera', $codigo)->select($selectCols);
            if (in_array('orden', $allCols, true)) {
                $q->orderBy('orden');
            } else {
                $q->orderBy('cod_pensum');
            }
            $pensums = $q->get();
            return response()->json([
                'success' => true,
                'data' => $pensums,
            ]);
        } catch (\Exception $e) {
            Log::error('Error al obtener pensums por carrera: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener pensums por carrera',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
