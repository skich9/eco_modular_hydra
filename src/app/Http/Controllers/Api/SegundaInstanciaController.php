<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class SegundaInstanciaController extends Controller
{
	public function index(Request $request)
	{
		try {
			$q = DB::table('segunda_instancia');
			if ($request->filled('cod_inscrip')) {
				$q->where('cod_inscrip', (int)$request->cod_inscrip);
			}
			return response()->json(['success' => true, 'data' => $q->orderByDesc('fecha_pago')->limit(500)->get()]);
		} catch (\Throwable $e) {
			return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
		}
	}

	public function store(Request $request)
	{
		$validator = Validator::make($request->all(), [
			'cod_inscrip' => 'required|integer',
			'fecha_pago' => 'required|date',
			'monto' => 'required|numeric',
			'usuario' => 'required|integer',
			'num_instancia' => 'nullable|integer',
			'num_pago_ins' => 'nullable|integer',
			'num_factura' => 'nullable|integer',
			'num_recibo' => 'nullable|integer',
			'observaciones' => 'nullable|string|max:150',
			'materia' => 'nullable|string|max:255',
			'valido' => 'nullable|string|max:1',
		]);
		if ($validator->fails()) {
			return response()->json(['success' => false, 'message' => 'Error de validación', 'errors' => $validator->errors()], 422);
		}
		try {
			$codIns = (int)$request->cod_inscrip;
			$numInst = $request->input('num_instancia');
			if (!is_numeric($numInst)) {
				$mx = DB::table('segunda_instancia')->where('cod_inscrip', $codIns)->max('num_instancia');
				$numInst = ((int)$mx) + 1;
			}
			$numPago = $request->input('num_pago_ins');
			if (!is_numeric($numPago) || (int)$numPago <= 0) { $numPago = 1; }
			DB::table('segunda_instancia')->insert([
				'cod_inscrip' => $codIns,
				'num_instancia' => (int)$numInst,
				'num_pago_ins' => (int)$numPago,
				'num_factura' => $request->input('num_factura'),
				'num_recibo' => $request->input('num_recibo'),
				'fecha_pago' => $request->input('fecha_pago'),
				'monto' => (float)$request->input('monto'),
				'pago_completo' => (bool)$request->boolean('pago_completo', true),
				'observaciones' => $request->input('observaciones'),
				'usuario' => (int)$request->input('usuario'),
				'materia' => $request->input('materia'),
				'valido' => $request->input('valido'),
				'created_at' => now(),
				'updated_at' => now(),
			]);
			$row = DB::table('segunda_instancia')->where('cod_inscrip', $codIns)->where('num_instancia', (int)$numInst)->where('num_pago_ins', (int)$numPago)->first();
			return response()->json(['success' => true, 'data' => $row], 201);
		} catch (\Throwable $e) {
			return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
		}
	}

	public function elegibilidad(Request $request)
	{
		try {
			$codIns = (int) $request->query('cod_inscrip');
			$materia = $request->query('materia');
			$materias = $request->query('materias'); // puede venir como array o coma-separado
			if (!$codIns) {
				return response()->json(['success' => false, 'message' => 'cod_inscrip requerido'], 422);
			}
			// Normalizar entrada de materias
			$list = [];
			if (is_array($materias)) { $list = $materias; }
			elseif (is_string($materias) && trim($materias) !== '') { $list = array_map('trim', explode(',', $materias)); }
			elseif (is_string($materia) && trim($materia) !== '') { $list = [ $materia ]; }

			$result = [];
			if (!empty($list)) {
				foreach ($list as $m) {
					$sig = strtoupper(trim((string)$m));
					if ($sig === '') { continue; }
					$exists = DB::table('segunda_instancia')
						->where('cod_inscrip', $codIns)
						->where('materia', $sig)
						->where(function($q){ $q->whereNull('valido')->orWhere('valido','v'); })
						->exists();
					$result[$sig] = [ 'elegible' => !$exists, 'exists' => $exists ];
				}
				Log::info('si.elegibilidad.list', [ 'cod_inscrip' => $codIns, 'materias' => array_keys($result), 'res' => $result ]);
				return response()->json(['success' => true, 'data' => $result]);
			}

			// Caso materia única o sin materia (resumen)
			if (is_string($materia) && trim($materia) !== '') {
				$sig = strtoupper(trim((string)$materia));
				$exists = DB::table('segunda_instancia')
					->where('cod_inscrip', $codIns)
					->where('materia', $sig)
					->where(function($q){ $q->whereNull('valido')->orWhere('valido','v'); })
					->exists();
				Log::info('si.elegibilidad.one', [ 'cod_inscrip' => $codIns, 'materia' => $sig, 'exists' => $exists ]);
				return response()->json(['success' => true, 'data' => [ 'elegible' => !$exists, 'exists' => $exists ]]);
			}

			// Resumen general: lista de materias ya cobradas (para UI en rojo)
			$rows = DB::table('segunda_instancia')
				->select('materia')
				->where('cod_inscrip', $codIns)
				->where(function($q){ $q->whereNull('valido')->orWhere('valido','v'); })
				->groupBy('materia')->pluck('materia')->filter()->values()->all();
			Log::info('si.elegibilidad.summary', [ 'cod_inscrip' => $codIns, 'materias' => $rows ]);
			return response()->json(['success' => true, 'data' => [ 'bloqueadas' => $rows ]]);
		} catch (\Throwable $e) {
			return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
		}
	}
}
