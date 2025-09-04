<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RazonSocial;
use Illuminate\Http\Request;

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
		$tipoId = (int) $request->query('tipo_id', 1);

		if ($numero === '') {
			return response()->json([
				'success' => false,
				'message' => 'El número de documento es requerido',
				'data' => null,
			]);
		}

		// Desde ahora el campo 'tipo' se maneja como 'cliente' de forma fija
		$tipo = 'cliente';
		$reg = RazonSocial::where('nit', $numero)->where('tipo', $tipo)->first();

		return response()->json([
			'success' => true,
			'data' => $reg,
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

		$rs = RazonSocial::updateOrCreate(
			['nit' => $data['nit'], 'tipo' => $tipo],
			[
				'razon_social' => $data['razon_social'] ?? null,
				'id_tipo_doc_identidad' => $data['tipo_id'],
				'complemento' => $data['complemento'] ?? null,
			]
		);

		return response()->json([
			'success' => true,
			'data' => $rs,
			'message' => 'Razón social guardada',
		]);
	}
}
