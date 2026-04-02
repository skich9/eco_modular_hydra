<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RazonSocial;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RazonSocialController extends Controller
{
	private const TIPOS = [
		1 => 'CI',
		2 => 'CEX',
		3 => 'PAS',
		4 => 'OD',
		5 => 'NIT',
	];

	public function search(Request $request)
	{
		$numero = trim((string) $request->query('numero', ''));

		if ($numero === '') {
			return response()->json([
				'success' => false,
				'message' => 'El número de documento es requerido',
				'data' => null,
			]);
		}

		// Desde ahora el campo 'tipo' se maneja como 'cliente' de forma fija
		$tipo = 'cliente';

		/** @var RazonSocial|null $reg */
		$reg = RazonSocial::query()->where('tipo', $tipo)->where('nit', $numero)->first();
		$match = 'exacto';

		if (!$reg) {
			$reg = RazonSocial::query()->where('tipo', $tipo)->whereRaw('TRIM(nit) = ?', [trim($numero)])->first();
			if ($reg) {
				$match = 'trim';
			}
		}

		if (!$reg && strlen($numero) >= 2) {
			$escaped = addcslashes($numero, '%_\\');
			$reg = RazonSocial::query()
				->where('tipo', $tipo)
				->where('nit', 'like', '%'.$escaped.'%')
				->orderByRaw('LENGTH(nit) asc')
				->first();
			if ($reg) {
				$match = 'similar';
			}
		}

		return response()->json([
			'success' => true,
			'data' => $reg,
			'match' => $reg ? $match : null,
		]);
	}

	public function store(Request $request)
	{
		$data = $request->validate([
			'razon_social' => ['nullable', 'string'],
			'nit' => ['required', 'string', 'max:50'],
			'tipo_id' => ['required', 'integer', 'in:1,2,3,4,5'],
			'complemento' => ['nullable', 'string', 'max:10'],
		]);

		// Guardar con tipo fijo 'cliente'
		$tipo = 'cliente';

		// Si ya existe el registro para el mismo NIT, validar que no se intente cambiar el tipo de identidad
		$existente = RazonSocial::where('nit', $data['nit'])->where('tipo', $tipo)->first();
		if (
			Schema::hasColumn('razon_social', 'id_tipo_doc_identidad')
			&& $existente
			&& (int) $existente->id_tipo_doc_identidad !== (int) $data['tipo_id']
		) {
			$tipoExistente = self::TIPOS[(int) $existente->id_tipo_doc_identidad] ?? 'DESCONOCIDO';
			return response()->json([
				'success' => false,
				'message' => "Estos dígitos ya fueron guardados en el tipo de identidad: {$tipoExistente}",
			], 422);
		}

		$now = now();
		$values = [
			'razon_social' => $data['razon_social'] ?? null,
		];
		if (Schema::hasColumn('razon_social', 'updated_at')) {
			$values['updated_at'] = $now;
		}
		if (Schema::hasColumn('razon_social', 'id_tipo_doc_identidad')) {
			$values['id_tipo_doc_identidad'] = (int) $data['tipo_id'];
		}
		if (Schema::hasColumn('razon_social', 'complemento')) {
			$values['complemento'] = $data['complemento'] ?? null;
		}

		$key = ['nit' => $data['nit'], 'tipo' => $tipo];
		$exists = DB::table('razon_social')->where($key)->exists();
		if (!$exists && Schema::hasColumn('razon_social', 'created_at')) {
			$values['created_at'] = $now;
		}

		DB::table('razon_social')->updateOrInsert($key, $values);

		$rs = RazonSocial::where('nit', $data['nit'])->where('tipo', $tipo)->first();

		return response()->json([
			'success' => true,
			'data' => $rs,
			'message' => 'Razón social guardada',
		]);
	}
}
