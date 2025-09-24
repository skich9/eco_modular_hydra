<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ParametroCuota;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\Request;

class ParametroCuotaController extends Controller
{
	public function index(Request $request)
	{
		$fechaCol = Schema::hasColumn('parametros_cuota', 'fecha_vencimiento')
			? 'fecha_vencimiento'
			: (Schema::hasColumn('parametros_cuota', 'fecha_venecimiento') ? 'fecha_venecimiento' : null);

		$select = ['id_parametro_cuota', 'nombre_cuota', 'activo'];
		if ($fechaCol) { $select[] = $fechaCol; }

		$rows = ParametroCuota::query()->orderBy('nombre_cuota')->get($select);
		// Normalizar clave en la respuesta
		$items = $rows->map(function ($r) use ($fechaCol) {
			return [
				'id_parametro_cuota' => $r->id_parametro_cuota,
				'nombre_cuota'       => $r->nombre_cuota,
				'fecha_vencimiento'  => $fechaCol ? $r->{$fechaCol} : null,
				'activo'             => (bool)$r->activo,
			];
		});

		return response()->json([
			'success' => true,
			'data' => $items,
		]);
	}

	public function activos(Request $request)
	{
		$fechaCol = Schema::hasColumn('parametros_cuota', 'fecha_vencimiento')
			? 'fecha_vencimiento'
			: (Schema::hasColumn('parametros_cuota', 'fecha_venecimiento') ? 'fecha_venecimiento' : null);

		$select = ['id_parametro_cuota', 'nombre_cuota', 'activo'];
		if ($fechaCol) { $select[] = $fechaCol; }

		$rows = ParametroCuota::query()
			->where('activo', true)
			->orderBy('nombre_cuota')
			->get($select);

		$items = $rows->map(function ($r) use ($fechaCol) {
			return [
				'id_parametro_cuota' => $r->id_parametro_cuota,
				'nombre_cuota'       => $r->nombre_cuota,
				'fecha_vencimiento'  => $fechaCol ? $r->{$fechaCol} : null,
				'activo'             => (bool)$r->activo,
			];
		});

		return response()->json([
			'success' => true,
			'data' => $items,
		]);
	}

	public function store(Request $request)
	{
		$data = $request->validate([
			'nombre_cuota'       => ['required','string','max:50'],
			'fecha_vencimiento'  => ['required','date'],
			'activo'             => ['required','boolean'],
		]);

		$fechaCol = Schema::hasColumn('parametros_cuota', 'fecha_vencimiento')
			? 'fecha_vencimiento'
			: (Schema::hasColumn('parametros_cuota', 'fecha_venecimiento') ? 'fecha_venecimiento' : null);

		$item = new ParametroCuota();
		$item->nombre_cuota = $data['nombre_cuota'];
		if ($fechaCol) { $item->{$fechaCol} = $data['fecha_vencimiento']; }
		$item->activo = (bool) $data['activo'];
		$item->save();

		return response()->json([
			'success' => true,
			'data' => [
				'id_parametro_cuota' => $item->id_parametro_cuota,
				'nombre_cuota'       => $item->nombre_cuota,
				'fecha_vencimiento'  => $fechaCol ? $item->{$fechaCol} : null,
				'activo'             => (bool)$item->activo,
			]
		]);
	}

	public function update(Request $request, $id)
	{
		$data = $request->validate([
			'fecha_vencimiento'  => ['nullable','date'],
			'activo'             => ['nullable','boolean'],
		]);

		$fechaCol = Schema::hasColumn('parametros_cuota', 'fecha_vencimiento')
			? 'fecha_vencimiento'
			: (Schema::hasColumn('parametros_cuota', 'fecha_venecimiento') ? 'fecha_venecimiento' : null);

		$item = ParametroCuota::query()->findOrFail($id);
		if ($fechaCol && array_key_exists('fecha_vencimiento', $data) && $data['fecha_vencimiento'] !== null) {
			$item->{$fechaCol} = $data['fecha_vencimiento'];
		}
		if (array_key_exists('activo', $data) && $data['activo'] !== null) {
			$item->activo = (bool)$data['activo'];
		}
		$item->save();

		return response()->json([
			'success' => true,
			'data' => [
				'id_parametro_cuota' => $item->id_parametro_cuota,
				'nombre_cuota'       => $item->nombre_cuota,
				'fecha_vencimiento'  => $fechaCol ? $item->{$fechaCol} : null,
				'activo'             => (bool)$item->activo,
			]
		]);
	}
}
