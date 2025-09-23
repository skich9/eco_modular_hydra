<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CostoSemestral;
use Illuminate\Http\Request;

class CostoSemestralController extends Controller
{
	public function byPensum(Request $request, string $codPensum)
	{
		$gestion = $request->query('gestion');

		$query = CostoSemestral::query()
			->where('cod_pensum', $codPensum)
			->when($gestion, function($q) use ($gestion) { $q->where('gestion', $gestion); })
			->orderBy('semestre')
			->orderBy('turno');

		$rows = $query->get(['id_costo_semestral','cod_pensum','gestion','semestre','monto_semestre','tipo_costo','turno']);

		return response()->json([
			'success' => true,
			'data' => $rows,
		]);
	}

	public function batchStore(Request $request)
	{
		$validated = $request->validate([
			'cod_pensum' => 'required|string',
			'gestion' => 'required|string',
			'costo_fijo' => 'nullable|integer',
			'valor_credito' => 'nullable|numeric',
			'rows' => 'required|array|min:1',
			'rows.*.semestre' => 'required|integer',
			'rows.*.tipo_costo' => 'required|string',
			'rows.*.monto_semestre' => 'required|numeric',
			'rows.*.turno' => 'required|string',
		]);

		$userId = optional($request->user())->id ?? $request->input('id_usuario');
		$created = 0; $updated = 0;
		foreach ($validated['rows'] as $row) {
			$attrs = [
				'cod_pensum' => $validated['cod_pensum'],
				'gestion' => $validated['gestion'],
				'semestre' => $row['semestre'],
				'tipo_costo' => $row['tipo_costo'],
				'turno' => $row['turno'],
			];
			$vals = [
				'monto_semestre' => $row['monto_semestre'],
				'costo_fijo' => $request->input('costo_fijo', 1),
				'valor_credito' => $request->input('valor_credito', 0),
			];
			if ($userId) { $vals['id_usuario'] = $userId; }

			$existing = CostoSemestral::where($attrs)->first();
			if ($existing) {
				$existing->fill($vals)->save();
				$updated++;
			} else {
				$model = new CostoSemestral(array_merge($attrs, $vals));
				$model->save();
				$created++;
			}
		}

		return response()->json([
			'success' => true,
			'data' => [ 'created' => $created, 'updated' => $updated ],
		]);
	}

	public function update(Request $request, int $id)
	{
		$validated = $request->validate([
			'monto_semestre' => 'required|numeric',
			'tipo_costo' => 'nullable|string',
			'turno' => 'nullable|string',
		]);

		$model = CostoSemestral::query()->findOrFail($id);
		$model->monto_semestre = $validated['monto_semestre'];
		if (array_key_exists('tipo_costo', $validated) && $validated['tipo_costo'] !== null) {
			$model->tipo_costo = $validated['tipo_costo'];
		}
		if (array_key_exists('turno', $validated) && $validated['turno'] !== null) {
			$model->turno = $validated['turno'];
		}
		$model->save();

		return response()->json([
			'success' => true,
			'data' => $model->only(['id_costo_semestral','cod_pensum','gestion','semestre','monto_semestre','tipo_costo','turno'])
		]);
	}

	public function destroy(int $id)
	{
		$model = CostoSemestral::query()->findOrFail($id);
		$model->delete();

		return response()->json([
			'success' => true,
			'data' => ['id' => $id]
		]);
	}
}
