<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SinActividadController extends Controller
{
    /**
     * Lista actividades económicas (sin_actividades) con búsqueda opcional.
     */
    public function index(Request $request)
    {
        try {
            $q = trim((string)($request->query('q', '')));
            $limit = (int)($request->query('limit', 50));
            if ($limit <= 0 || $limit > 200) { $limit = 50; }

            $query = DB::table('sin_actividades')->select('codigo_caeb', 'descripcion');
            if ($q !== '') {
                $query->where(function ($w) use ($q) {
                    $w->where('codigo_caeb', 'like', "%$q%")
                      ->orWhere('descripcion', 'like', "%$q%");
                });
            }
            $rows = $query->orderBy('codigo_caeb')->limit($limit)->get();

            return response()->json([
                'success' => true,
                'data' => $rows,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al listar actividades: ' . $e->getMessage(),
            ], 500);
        }
    }
}
