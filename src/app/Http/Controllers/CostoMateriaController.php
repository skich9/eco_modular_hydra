<?php

namespace App\Http\Controllers;

use App\Models\CostoMateria;
use App\Models\Materia;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

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
            'cod_pensum' => 'required|string|max:50',
            'sigla_materia' => 'required|string|max:255',
            'gestion' => 'required|string|max:30',
            'valor_credito' => 'required|numeric|min:0',
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
            'cod_pensum' => 'sometimes|string|max:50',
            'valor_credito' => 'sometimes|numeric|min:0',
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

    /**
     * Inserta/actualiza en lote registros de costo_materia.
     * Body esperado:
     * {
     *   "gestion": "2/2025",
     *   "items": [
     *     { "cod_pensum": "MEA", "sigla_materia": "M-AUT100", "valor_credito": 222.22, "monto_materia": 666.66, "id_usuario": 1 }
     *   ]
     * }
     */
    public function batchUpsert(Request $request)
    {
        $data = $request->validate([
            'gestion' => 'required|string|max:30',
            'items' => 'required|array|min:1',
            'items.*.cod_pensum' => 'required|string|max:50',
            'items.*.sigla_materia' => 'required|string|max:255',
            'items.*.valor_credito' => 'required|numeric|min:0',
            'items.*.monto_materia' => 'required|numeric|min:0',
            'items.*.id_usuario' => 'required|exists:usuarios,id_usuario',
        ]);

        $created = 0; $updated = 0; $rows = [];
        DB::transaction(function () use ($data, & $created, & $updated, & $rows) {
            foreach ($data['items'] as $it) {
                $attrs = [
                    'cod_pensum' => $it['cod_pensum'],
                    'sigla_materia' => $it['sigla_materia'],
                    'gestion' => $data['gestion'],
                ];
                $vals = [
                    'valor_credito' => $it['valor_credito'],
                    'monto_materia' => $it['monto_materia'],
                    'id_usuario' => $it['id_usuario'],
                ];
                $existing = CostoMateria::where($attrs)->first();
                if ($existing) {
                    $existing->fill($vals)->save();
                    $updated++;
                    $rows[] = $existing;
                } else {
                    $model = new CostoMateria(array_merge($attrs, $vals));
                    $model->save();
                    $created++;
                    $rows[] = $model;
                }
            }
        });

        return response()->json([
            'success' => true,
            'data' => [ 'created' => $created, 'updated' => $updated, 'rows' => $rows ],
        ]);
    }

    /**
     * Genera costos por crédito para todas las materias de un pensum en una gestión dada.
     * Si existe costo previo para (sigla_materia, cod_pensum, gestion) se actualiza el monto.
     */
    public function generateByPensumGestion(Request $request)
    {
        $validated = $request->validate([
            'cod_pensum' => 'required|string|max:50',
            'gestion' => 'required|string|max:30',
            'valor_credito' => 'required|numeric|min:0',
            'id_usuario' => 'required|exists:usuarios,id_usuario',
            'semestre' => 'nullable',
        ]);

        $created = 0; $updated = 0; $rows = [];
        DB::transaction(function () use ($validated, & $created, & $updated, & $rows) {
            $materiasQ = Materia::query()
                ->where('cod_pensum', $validated['cod_pensum']);
            if (!empty($validated['semestre'] ?? null)) {
                $materiasQ->where('nivel_materia', (string)$validated['semestre']);
            }
            $materias = $materiasQ->get(['sigla_materia','cod_pensum','nro_creditos']);
            foreach ($materias as $m) {
                $monto = (float)$m->nro_creditos * (float)$validated['valor_credito'];
                $attrs = [
                    'cod_pensum' => $validated['cod_pensum'],
                    'sigla_materia' => $m->sigla_materia,
                    'gestion' => $validated['gestion'],
                ];
                $vals = [
                    'valor_credito' => $validated['valor_credito'],
                    'monto_materia' => $monto,
                    'id_usuario' => $validated['id_usuario'],
                ];
                $existing = CostoMateria::where($attrs)->first();
                if ($existing) {
                    $existing->fill($vals)->save();
                    $updated++;
                    $rows[] = $existing;
                } else {
                    $model = new CostoMateria(array_merge($attrs, $vals));
                    $model->save();
                    $created++;
                    $rows[] = $model;
                }
            }
        });

        return response()->json([
            'success' => true,
            'data' => [ 'created' => $created, 'updated' => $updated, 'rows' => $rows ],
        ]);
    }
}
