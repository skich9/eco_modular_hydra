<?php

namespace App\Http\Controllers;

use App\Models\CostoMateria;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CostoMateriaController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $costosMateria = CostoMateria::with(['materia', 'gestion', 'usuario'])->get();
        return response()->json($costosMateria);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'sigla_materia' => 'required|string|max:255',
            'gestion' => 'required|string|max:30',
            'nro_creditos' => 'required|numeric|min:0',
            'nombre_materia' => 'required|string|max:30',
            'monto_materia' => 'nullable|numeric|min:0',
            'id_usuario' => 'required|exists:usuarios,id_usuario'
        ]);

        $costoMateria = CostoMateria::create($validated);

        return response()->json($costoMateria, Response::HTTP_CREATED);
    }

    /**
     * Display the specified resource.
     */
    public function show($id, $sigla, $gestion)
    {
        $costoMateria = CostoMateria::with(['materia', 'gestion', 'usuario'])
            ->where('id_costo_materia', $id)
            ->where('sigla_materia', $sigla)
            ->where('gestion', $gestion)
            ->firstOrFail();

        return response()->json($costoMateria);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id, $sigla, $gestion)
    {
        $costoMateria = CostoMateria::where('id_costo_materia', $id)
            ->where('sigla_materia', $sigla)
            ->where('gestion', $gestion)
            ->firstOrFail();

        $validated = $request->validate([
            'nro_creditos' => 'sometimes|numeric|min:0',
            'nombre_materia' => 'sometimes|string|max:30',
            'monto_materia' => 'nullable|numeric|min:0',
            'id_usuario' => 'sometimes|exists:usuarios,id_usuario'
        ]);

        $costoMateria->update($validated);

        return response()->json($costoMateria);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id, $sigla, $gestion)
    {
        $costoMateria = CostoMateria::where('id_costo_materia', $id)
            ->where('sigla_materia', $sigla)
            ->where('gestion', $gestion)
            ->firstOrFail();

        $costoMateria->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Obtener costos por gestión y materia
     */
    public function getByGestionAndMateria($gestion, $siglaMateria)
    {
        $costos = CostoMateria::with(['materia', 'gestion', 'usuario'])
            ->where('gestion', $gestion)
            ->where('sigla_materia', $siglaMateria)
            ->get();

        return response()->json($costos);
    }

    /**
     * Obtener costos por gestión
     */
    public function getByGestion($gestion)
    {
        $costos = CostoMateria::with(['materia', 'gestion', 'usuario'])
            ->where('gestion', $gestion)
            ->get();

        return response()->json($costos);
    }
}
