<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Response;
use App\Services\FacturaPdfService;

class FacturaPdfController extends Controller
{
	public function pdf($anio, $nro)
	{
		try {
			$anio = (int) $anio; $nro = (int) $nro;
			$row = DB::table('factura')
				->select('estado')
				->where('anio', $anio)
				->where('nro_factura', $nro)
				->first();
			if (!$row) {
				return response()->json([ 'success' => false, 'message' => 'Factura no encontrada' ], 404);
			}
			$estado = isset($row->estado) ? (string)$row->estado : '';
			$anulado = ($estado === 'ANULADA');
			$svc = new FacturaPdfService();
			$path = $svc->generate($anio, $nro, $anulado);
			if (!is_file($path)) {
				return response()->json([ 'success' => false, 'message' => 'No se pudo generar PDF' ], 500);
			}
			return response()->file($path, [ 'Content-Type' => 'application/pdf' ]);
		} catch (\Throwable $e) {
			Log::error('FacturaPdfController.pdf', [ 'error' => $e->getMessage() ]);
			return response()->json([ 'success' => false, 'message' => $e->getMessage() ], 500);
		}
	}
}
