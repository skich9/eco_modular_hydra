<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class KardexNotasController extends Controller
{
	/**
	 * GET /api/kardex-notas/materias
	 * Params: cod_ceta (required), cod_pensum (required), cod_inscrip (optional), tipo_incripcion (optional)
	 * Returns materias asociadas al estudiante según kardex_notas.
	 */
	public function materias(Request $request)
	{
		try {
			$codCeta = $request->query('cod_ceta');
			$codPensum = $request->query('cod_pensum');
			$codInscrip = $request->query('cod_inscrip');
			$gestion = $request->query('gestion');
			// soportar ambas variantes (BD tiene columna tipo_incripcion)
			$tipoInscripcion = $request->query('tipo_incripcion');
			if ($tipoInscripcion === null || $tipoInscripcion === '') {
				$tipoInscripcion = $request->query('tipo_inscripcion');
			}

			// Requerimos al menos cod_ceta y cod_pensum
			if (!$codCeta || !$codPensum) {
				return response()->json([
					'success' => false,
					'data' => [],
					'message' => 'Parámetros insuficientes (cod_ceta y cod_pensum son requeridos)'
				], 422);
			}

			// Si no viene cod_inscrip, intentar resolver por gestión; si no hay gestión, usar el máximo
			if (!$codInscrip) {
				if (!empty($gestion)) {
					$codInscrip = DB::table('inscripciones')
						->where('cod_ceta', $codCeta)
						->where('cod_pensum', $codPensum)
						->where('gestion', $gestion)
						->max('cod_inscrip');
				}
				if (!$codInscrip) {
					$codInscrip = DB::table('kardex_notas')
						->where('cod_ceta', $codCeta)
						->where('cod_pensum', $codPensum)
						->max('cod_inscrip');
				}
			}

			$q = DB::table('kardex_notas as k')
				->leftJoin('materia as m', function ($j) {
					$j->on('m.cod_pensum', '=', 'k.cod_pensum')
					  ->on('m.sigla_materia', '=', 'k.sigla_materia');
				})
				->select(
					'k.sigla_materia',
					DB::raw('m.nombre_materia as nombre_materia'),
					'k.tipo_incripcion',
					'k.cod_kardex'
				)
				->where('k.cod_ceta', $codCeta)
				->where('k.cod_pensum', $codPensum);

			// Filtrado por inscripción si la tenemos
			if (!empty($codInscrip)) {
				$q->where('k.cod_inscrip', $codInscrip);
			}
			// Filtrar por tipo si fue proporcionado
			if (!empty($tipoInscripcion)) {
				$q->where('k.tipo_incripcion', $tipoInscripcion);
			}

			$rows = $q->orderBy('k.cod_kardex')->get();

			// Distinct por sigla_materia manteniendo la primera ocurrencia
			$unique = [];
			$seen = [];
			foreach ($rows as $r) {
				$key = $r->sigla_materia;
				if (!isset($seen[$key])) {
					$unique[] = [
						'sigla_materia' => $r->sigla_materia,
						'nombre_materia' => $r->nombre_materia ?: $r->sigla_materia,
						'tipo_incripcion' => $r->tipo_incripcion,
						'cod_kardex' => $r->cod_kardex,
					];
					$seen[$key] = true;
				}
			}

			return response()->json([
				'success' => true,
				'data' => $unique,
			]);
		} catch (\Throwable $e) {
			return response()->json([
				'success' => false,
				'message' => 'Error al obtener materias de kardex',
				'error' => $e->getMessage(),
			], 500);
		}
	}
}
