<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EstudianteController extends Controller
{
	public function search(Request $request)
	{
		$apPat = trim((string)$request->input('ap_paterno', ''));
		$apMat = trim((string)$request->input('ap_materno', ''));
		$nombres = trim((string)$request->input('nombres', ''));
		$ci = trim((string)$request->input('ci', ''));
		$page = max(1, (int) $request->input('page', 1));
		$perPage = min(100, max(5, (int) $request->input('per_page', 20)));

		try {
			// Subquery robusta: por cod_ceta, preferir numero_doc de Carnet/CI/Cédula; si no hay, usar cualquier numero_doc no vacío
			$dpAgg = DB::table('doc_presentados as d')
				->select(
					'd.cod_ceta',
					DB::raw("MAX(CASE WHEN (UPPER(d.nombre_doc) LIKE 'CARNET%' OR UPPER(d.nombre_doc) LIKE 'CI %' OR UPPER(d.nombre_doc) LIKE 'CÉDULA%' OR UPPER(d.nombre_doc) LIKE 'CEDULA%') THEN NULLIF(d.numero_doc,'') END) as ci_doc"),
					DB::raw("MAX(NULLIF(d.numero_doc,'')) as any_doc")
				)
				->groupBy('d.cod_ceta');

			$q = DB::table('estudiantes')
				->leftJoinSub($dpAgg, 'dp', function($join){ $join->on('dp.cod_ceta', '=', 'estudiantes.cod_ceta'); });
			// Normalizar a 'Primera mayúscula + resto minúsculas'
			$fmt = function($s){ $s = trim((string)$s); if ($s==='') return $s; $ls = mb_strtolower($s, 'UTF-8'); return mb_strtoupper(mb_substr($ls,0,1,'UTF-8'),'UTF-8') . mb_substr($ls,1,null,'UTF-8'); };
			if ($apPat !== '') { $apPat = $fmt($apPat); $q->where('ap_paterno', 'like', "$apPat%"); }
			if ($apMat !== '') { $apMat = $fmt($apMat); $q->where('ap_materno', 'like', "$apMat%"); }
			if ($nombres !== '') { $nombres = $fmt($nombres); $q->where('nombres', 'like', "$nombres%"); }
			if ($ci !== '') {
				$q->where(function($w) use ($ci){
					$w->where('estudiantes.ci', 'like', "%$ci%")
					  ->orWhere('dp.ci_doc', 'like', "%$ci%")
					  ->orWhere('dp.any_doc', 'like', "%$ci%");
				});
			}
			// Clonar para total
			$totalQuery = $q;
			$total = $totalQuery->count();
			$rows = $q->select(
				'estudiantes.cod_ceta',
				'estudiantes.nombres',
				'estudiantes.ap_paterno',
				'estudiantes.ap_materno',
				'estudiantes.carrera',
				'estudiantes.resolucion',
				'estudiantes.gestion',
				'estudiantes.grupos',
				'estudiantes.descuento',
				'estudiantes.observaciones',
				DB::raw("COALESCE(NULLIF(dp.ci_doc,''), NULLIF(dp.any_doc,''), NULLIF(estudiantes.ci,'')) as ci")
			)
				->orderBy('estudiantes.ap_paterno')
				->orderBy('estudiantes.ap_materno')
				->orderBy('estudiantes.nombres')
				->offset(($page - 1) * $perPage)
				->limit($perPage)
				->get();
			return response()->json([
				'success' => true,
				'data' => $rows,
				'meta' => [
					'page' => $page,
					'per_page' => $perPage,
					'total' => $total,
					'last_page' => (int) ceil(max(1, $total) / max(1, $perPage)),
				]
			]);
		} catch (\Throwable $e) {
			return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
		}
	}
}
