<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ActividadEconomicaController extends Controller
{
    public function index()
    {
        $actividades = DB::table('actividades_economicas')
            ->orderBy('orden')
            ->orderBy('id_actividad_economica')
            ->get();
            
        return response()->json([
            'success' => true,
            'data' => $actividades
        ]);
    }
}
