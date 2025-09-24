<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\Cuota;

class CuotaController extends Controller
{
    public function index(Request $request)
    {
        $q = Cuota::query();
        if ($request->filled('gestion')) {
            $q->where('gestion', $request->string('gestion'));
        }
        if ($request->filled('cod_pensum')) {
            $q->where('cod_pensum', $request->string('cod_pensum'));
        }
        if ($request->filled('semestre')) {
            $q->where('semestre', (string)$request->input('semestre'));
        }
        if ($request->filled('turno')) {
            $q->where('turno', $request->string('turno'));
        }
        if ($request->filled('tipo')) {
            $q->where('tipo', $request->string('tipo'));
        }
        if ($request->filled('nombre')) {
            $q->where('nombre', 'like', '%'.$request->string('nombre').'%');
        }
        $rows = $q->orderByDesc('id_cuota')->limit(500)->get();
        $data = $rows->map(function($m){
            return [
                'id_cuota' => $m->id_cuota,
                'gestion' => $m->gestion,
                'cod_pensum' => $m->cod_pensum,
                'semestre' => $m->semestre,
                'turno' => $m->turno ?? null,
                'nombre' => $m->nombre,
                'descripcion' => $m->descripcion,
                'monto' => $m->monto,
                'fecha_vencimiento' => $m->fecha_vencimiento,
                'activo' => (bool)($m->activo ?? true),
                'tipo' => $m->tipo ?? null,
            ];
        });
        return response()->json(['success' => true, 'data' => $data]);
    }
	public function updateByContext(Request $request)
	{
		$validated = $request->validate([
			'cod_pensum' => 'required|string|max:50',
			'gestion' => 'required|string|max:30',
			'semestre' => 'required',
			'monto' => 'required|numeric',
			'tipo' => 'nullable|string',
			'turno' => 'nullable|string|max:150',
			'activo' => 'nullable|boolean',
		]);

		try {
			$updated = 0;
			$rows = [];
			DB::transaction(function () use ($validated, & $updated, & $rows) {
				$q = Cuota::query()
					->where('gestion', $validated['gestion'])
					->where('cod_pensum', $validated['cod_pensum'])
					->where('semestre', (string)$validated['semestre']);
				if (Schema::hasColumn('cuotas', 'turno') && !empty($validated['turno'] ?? null)) {
					$q->where('turno', $validated['turno']);
				}
				if (Schema::hasColumn('cuotas', 'tipo') && !empty($validated['tipo'] ?? null)) {
					$q->where('tipo', $validated['tipo']);
				}
				$toUpdate = $q->get();
				foreach ($toUpdate as $m) {
					$m->monto = $validated['monto'];
					if (Schema::hasColumn('cuotas', 'activo') && array_key_exists('activo', $validated)) {
						$m->activo = (bool)$validated['activo'];
					}
					$m->save();
					$updated++;
					$rows[] = $m;
				}
			});

			return response()->json(['success' => true, 'data' => ['updated' => $updated, 'rows' => $rows]]);
		} catch (\Throwable $e) {
			return response()->json(['success' => false, 'message' => 'No se pudieron actualizar las cuotas: ' . $e->getMessage()], 500);
		}
	}

	/**
	 * Elimina cuotas por contexto (gestion, cod_pensum, semestre; opcional turno y tipo).
	 */
	public function deleteByContext(Request $request)
	{
		$validated = $request->validate([
			'cod_pensum' => 'required|string|max:50',
			'gestion' => 'required|string|max:30',
			'semestre' => 'required',
			'tipo' => 'nullable|string',
			'turno' => 'nullable|string|max:150',
		]);

		try {
			$deleted = 0;
			DB::transaction(function () use ($validated, & $deleted) {
				$q = Cuota::query()
					->where('gestion', $validated['gestion'])
					->where('cod_pensum', $validated['cod_pensum'])
					->where('semestre', (string)$validated['semestre']);
				if (Schema::hasColumn('cuotas', 'turno') && !empty($validated['turno'] ?? null)) {
					$q->where('turno', $validated['turno']);
				}
				if (Schema::hasColumn('cuotas', 'tipo') && !empty($validated['tipo'] ?? null)) {
					$q->where('tipo', $validated['tipo']);
				}
				$deleted = $q->delete();
			});

			return response()->json(['success' => true, 'data' => ['deleted' => $deleted]]);
		} catch (\Throwable $e) {
			return response()->json(['success' => false, 'message' => 'No se pudieron eliminar las cuotas: ' . $e->getMessage()], 500);
		}
		if ($request->filled('nombre')) {
			$q->where('nombre', 'like', '%'.$request->string('nombre').'%');
		}
		$rows = $q->orderByDesc('id_cuota')->limit(500)->get();
		$data = $rows->map(function($m){
			return [
				'id_cuota' => $m->id_cuota,
				'gestion' => $m->gestion,
				'cod_pensum' => $m->cod_pensum,
				'semestre' => $m->semestre,
				'turno' => $m->turno ?? null,
				'nombre' => $m->nombre,
				'descripcion' => $m->descripcion,
				'monto' => $m->monto,
				'fecha_vencimiento' => $m->fecha_vencimiento,
				'activo' => (bool)($m->activo ?? true),
				'tipo' => $m->tipo ?? null,
			];
		});
		return response()->json(['success' => true, 'data' => $data]);
	}

	public function batchStore(Request $request)
	{
		$validated = $request->validate([
			'cod_pensum' => 'required|string|max:50',
			'gestion' => 'required|string|max:30',
			'cuotas' => 'required|array|min:1',
			'cuotas.*.nombre' => 'required|string|max:100',
			'cuotas.*.descripcion' => 'nullable|string',
			'cuotas.*.semestre' => 'required', // puede venir numérico o string
			'cuotas.*.monto' => 'required|numeric',
			'cuotas.*.fecha_vencimiento' => 'required|date',
			'cuotas.*.tipo' => 'nullable|string',
			'cuotas.*.turno' => 'nullable|string|max:150',
		]);

		try {
			$created = 0; $updated = 0; $rows = [];
			DB::transaction(function () use ($validated, & $created, & $updated, & $rows) {
				foreach ($validated['cuotas'] as $q) {
					$sem = (string)($q['semestre'] ?? '');
					$nombre = (string)($q['nombre'] ?? '');
					$fv = substr((string)($q['fecha_vencimiento'] ?? ''), 0, 10);
					// Buscar si ya existe misma cuota por nombre+gestion+cod_pensum+semestre(+turno si aplica)
					$query = Cuota::query()
						->where('gestion', $validated['gestion'])
						->where('cod_pensum', $validated['cod_pensum'])
						->where('semestre', $sem)
						->where('nombre', $nombre);
					if (Schema::hasColumn('cuotas', 'turno') && array_key_exists('turno', $q)) {
						$query->where('turno', $q['turno']);
					}
					if (Schema::hasColumn('cuotas', 'tipo') && array_key_exists('tipo', $q)) {
						$query->where('tipo', $q['tipo']);
					}
					$exists = $query->first();
					if ($exists) {
						$exists->monto = $q['monto'];
						$exists->fecha_vencimiento = $fv;
						// turno/tipo/activo opcionales
						if (Schema::hasColumn('cuotas', 'turno') && array_key_exists('turno', $q)) {
							$exists->turno = $q['turno'];
						}
						if (Schema::hasColumn('cuotas', 'tipo') && array_key_exists('tipo', $q)) {
							$exists->tipo = $q['tipo'];
						}
						if (Schema::hasColumn('cuotas', 'activo')) {
							$exists->activo = array_key_exists('activo', $q) ? (bool)$q['activo'] : true;
						}
						$exists->save();
						$updated++;
						$rows[] = $exists;
					} else {
						$model = new Cuota();
						$model->gestion = $validated['gestion'];
						$model->cod_pensum = $validated['cod_pensum'];
						$model->semestre = $sem;
						$model->nombre = $nombre;
						$model->descripcion = $q['descripcion'] ?? null;
						$model->monto = $q['monto'];
						$model->fecha_vencimiento = $fv;
						if (Schema::hasColumn('cuotas', 'turno') && array_key_exists('turno', $q)) {
							$model->turno = $q['turno'];
						}
						if (Schema::hasColumn('cuotas', 'tipo') && array_key_exists('tipo', $q)) {
							$model->tipo = $q['tipo'];
						}
						if (Schema::hasColumn('cuotas', 'activo')) {
							$model->activo = array_key_exists('activo', $q) ? (bool)$q['activo'] : true;
						}
						$model->save();
						$created++;
						$rows[] = $model;
					}
				}
			});

			return response()->json([
				'success' => true,
				'data' => [
					'created' => $created,
					'updated' => $updated,
					'rows' => array_map(function($m){
						return [
							'id_cuota' => $m->id_cuota,
							'gestion' => $m->gestion ?? null,
							'cod_pensum' => $m->cod_pensum ?? null,
							'semestre' => $m->semestre ?? null,
							'turno' => $m->turno ?? null,
							'nombre' => $m->nombre,
							'monto' => $m->monto,
							'fecha_vencimiento' => $m->fecha_vencimiento,
							'tipo' => $m->tipo ?? null,
						];
					}, $rows),
				],
			]);
		} catch (\Throwable $e) {
			return response()->json([
				'success' => false,
				'message' => 'No se pudieron guardar las cuotas: ' . $e->getMessage(),
			], 500);
		}
	}
}
