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

			\Log::info('[KARDEX MATERIAS DEBUG] Parámetros recibidos:', [
				'cod_ceta' => $codCeta,
				'cod_pensum' => $codPensum,
				'cod_inscrip' => $codInscrip,
				'gestion' => $gestion,
				'tipo_incripcion' => $tipoInscripcion
			]);

			// Requerimos al menos cod_ceta y cod_pensum
			if (!$codCeta || !$codPensum) {
				\Log::warning('[KARDEX MATERIAS DEBUG] Parámetros insuficientes');
				return response()->json([
					'success' => false,
					'data' => [],
					'message' => 'Parámetros insuficientes (cod_ceta y cod_pensum son requeridos)'
				], 422);
			}

			// Si viene gestión, obtener todas las inscripciones de esa gestión
			$inscripcionesGestion = [];
			if (!empty($gestion)) {
				$inscripcionesGestion = DB::table('inscripciones')
					->where('cod_ceta', $codCeta)
					->where('cod_pensum', $codPensum)
					->where('gestion', $gestion)
					->pluck('cod_inscrip')
					->toArray();
				\Log::info('[KARDEX MATERIAS DEBUG] Inscripciones de la gestión:', ['inscripciones' => $inscripcionesGestion]);
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

			// Si hay inscripciones de la gestión, filtrar por ellas (NORMAL + ARRASTRE)
			if (!empty($inscripcionesGestion)) {
				$q->whereIn('k.cod_inscrip', $inscripcionesGestion);
				\Log::info('[KARDEX MATERIAS DEBUG] Filtrando por inscripciones de gestión:', ['count' => count($inscripcionesGestion)]);
			} elseif (!empty($codInscrip)) {
				// Si no hay gestión pero sí cod_inscrip específico, usar ese
				$q->where('k.cod_inscrip', $codInscrip);
				\Log::info('[KARDEX MATERIAS DEBUG] Filtrando por cod_inscrip específico:', ['cod_inscrip' => $codInscrip]);
			}

			// Filtrar por tipo solo si fue proporcionado explícitamente
			if (!empty($tipoInscripcion)) {
				$q->where('k.tipo_incripcion', $tipoInscripcion);
				\Log::info('[KARDEX MATERIAS DEBUG] Filtrando por tipo_incripcion:', ['tipo_incripcion' => $tipoInscripcion]);
			}

			\Log::info('[KARDEX MATERIAS DEBUG] SQL Query:', ['sql' => $q->toSql(), 'bindings' => $q->getBindings()]);

			$rows = $q->orderBy('k.cod_kardex')->get();
			\Log::info('[KARDEX MATERIAS DEBUG] Resultados obtenidos:', [
				'total_rows' => $rows->count(),
				'rows' => $rows->toArray()
			]);

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

			\Log::info('[KARDEX MATERIAS DEBUG] Resultado final:', [
				'total_unique' => count($unique),
				'unique' => $unique
			]);

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
