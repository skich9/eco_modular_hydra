<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Carrera;
use App\Models\Pensum;
use Illuminate\Support\Facades\Log;

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

            $pensums = Pensum::where('codigo_carrera', $codigo)->get();
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
