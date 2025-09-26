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
            'turno' => 'nullable|string|max:150',
            'id_usuario' => 'required|exists:usuarios,id_usuario'
        ]);

        $costoMateria = CostoMateria::create($validated);
        return response()->json($costoMateria, Response::HTTP_CREATED);
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
     * Obtener costos por gestión
     * @param string $gestion
     * @return \Illuminate\Http\Response
     */
    public function getByGestion($gestion)
    {
        $costos = CostoMateria::with(['materia', 'gestion', 'usuario'])
            ->where('gestion', $gestion)
            ->get();
        
        return response()->json($costos);
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
     * Inserta/actualiza en lote registros de costo_materia.
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
            'items.*.turno' => 'nullable|string|max:150',
            'items.*.id_usuario' => 'required|exists:usuarios,id_usuario',
        ]);

        $created = 0; $updated = 0; $rows = [];
        DB::transaction(function () use ($data, & $created, & $updated, & $rows) {
            foreach ($data['items'] as $it) {
                $turnoIn = strtoupper(trim((string)($it['turno'] ?? '')));
                $turnos = [];
                if ($turnoIn === 'TODOS') {
                    $turnos = ['MANANA', 'TARDE', 'NOCHE'];
                } elseif ($turnoIn !== '') {
                    $turnos = [$turnoIn];
                } else {
                    $turnos = [null];
                }

                foreach ($turnos as $t) {
                    $vals = [
                        'valor_credito' => $it['valor_credito'],
                        'monto_materia' => $it['monto_materia'],
                        'turno' => $t,
                        'id_usuario' => $it['id_usuario'],
                    ];

                    $q = CostoMateria::where('cod_pensum', $it['cod_pensum'])
                        ->where('sigla_materia', $it['sigla_materia'])
                        ->where('gestion', $data['gestion']);
                    if ($t === null) { $q->whereNull('turno'); } else { $q->where('turno', $t); }
                    $existing = $q->first();

                    if ($existing) {
                        $existing->fill($vals)->save();
                        $updated++;
                        $rows[] = $existing;
                    } else {
                        $model = new CostoMateria(array_merge([
                            'cod_pensum' => $it['cod_pensum'],
                            'sigla_materia' => $it['sigla_materia'],
                            'gestion' => $data['gestion'],
                        ], $vals));
                        $model->save();
                        $created++;
                        $rows[] = $model;
                    }
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
     * Si existe costo previo para (sigla_materia, cod_pensum, gestion, turno) se actualiza el monto.
     */
    public function generateByPensumGestion(Request $request)
    {
        $validated = $request->validate([
            'cod_pensum' => 'required|string|max:50',
            'gestion' => 'required|string|max:30',
            'valor_credito' => 'required|numeric|min:0',
            'id_usuario' => 'required|exists:usuarios,id_usuario',
            'semestre' => 'nullable',
            'turno' => 'nullable|string|max:150',
        ]);

        $created = 0; $updated = 0; $rows = [];
        DB::transaction(function () use ($validated, & $created, & $updated, & $rows) {
            $materiasQ = Materia::query()
                ->where('cod_pensum', $validated['cod_pensum']);
            if (!empty($validated['semestre'] ?? null)) {
                $materiasQ->where('nivel_materia', (string)$validated['semestre']);
            }
            $materias = $materiasQ->get(['sigla_materia','cod_pensum','nro_creditos']);
            $turnoIn = strtoupper(trim((string)($validated['turno'] ?? '')));
            $turnosBase = [];
            if ($turnoIn === 'TODOS') {
                $turnosBase = ['MANANA', 'TARDE', 'NOCHE'];
            } elseif ($turnoIn !== '') {
                $turnosBase = [$turnoIn];
            } else {
                $turnosBase = [null];
            }

            foreach ($materias as $m) {
                $monto = (float)$m->nro_creditos * (float)$validated['valor_credito'];
                foreach ($turnosBase as $t) {
                    $vals = [
                        'valor_credito' => $validated['valor_credito'],
                        'monto_materia' => $monto,
                        'turno' => $t,
                        'id_usuario' => $validated['id_usuario'],
                    ];
                    $q = CostoMateria::where('cod_pensum', $m->cod_pensum)
                        ->where('sigla_materia', $m->sigla_materia)
                        ->where('gestion', $validated['gestion']);
                    if ($t === null) { $q->whereNull('turno'); } else { $q->where('turno', $t); }
                    $existing = $q->first();
                    if ($existing) {
                        $existing->fill($vals)->save();
                        $updated++;
                        $rows[] = $existing;
                    } else {
                        $model = new CostoMateria(array_merge([
                            'cod_pensum' => $m->cod_pensum,
                            'sigla_materia' => $m->sigla_materia,
                            'gestion' => $validated['gestion'],
                        ], $vals));
                        $model->save();
                        $created++;
                        $rows[] = $model;
                    }
                }
            }
        });

        return response()->json([
            'success' => true,
            'data' => [ 'created' => $created, 'updated' => $updated, 'rows' => $rows ],
        ]);
    }
}
