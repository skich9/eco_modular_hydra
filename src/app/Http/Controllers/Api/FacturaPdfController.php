<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Response;
use App\Services\FacturaPdfService;

class FacturaPdfController extends Controller
{
	public function datos($anio, $nro)
	{
		try {
			$anio = (int) $anio; $nro = (int) $nro;
			$factura = DB::table('factura')
				->where('anio', $anio)
				->where('nro_factura', $nro)
				->first();
			
			if (!$factura) {
				return response()->json([ 'success' => false, 'message' => 'Factura no encontrada' ], 404);
			}
			
			$detalles = [];
			try {
				if (DB::getSchemaBuilder()->hasTable('factura_detalle')) {
					$detalles = DB::table('factura_detalle')
						->where('anio', $anio)
						->where('nro_factura', $nro)
						->orderBy('id_detalle')
						->get()
						->toArray();
				}
			} catch (\Throwable $e) {
				Log::warning('FacturaPdfController.datos.detalles', [ 'error' => $e->getMessage() ]);
			}
			
			return response()->json([
				'success' => true,
				'factura' => $factura,
				'detalles' => $detalles
			]);
		} catch (\Throwable $e) {
			Log::error('FacturaPdfController.datos', [ 'error' => $e->getMessage() ]);
			return response()->json([ 'success' => false, 'message' => $e->getMessage() ], 500);
		}
	}

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
				Log::error('FacturaPdfController.pdf.notFound', [ 'anio' => $anio, 'nro' => $nro ]);
				return response()->json([ 'success' => false, 'message' => 'Factura no encontrada' ], 404);
			}
			$estado = isset($row->estado) ? (string)$row->estado : '';
			$anulado = ($estado === 'ANULADA');
			
			Log::info('FacturaPdfController.pdf.start', [ 'anio' => $anio, 'nro' => $nro, 'estado' => $estado, 'anulado' => $anulado ]);
			
			$svc = new FacturaPdfService();
			$path = $svc->generate($anio, $nro, $anulado);
			
			if (!is_file($path)) {
				Log::error('FacturaPdfController.pdf.fileNotFound', [ 'path' => $path ]);
				return response()->json([ 'success' => false, 'message' => 'No se pudo generar PDF' ], 500);
			}
			
			$fileSize = filesize($path);
			$fileExists = file_exists($path);
			$isReadable = is_readable($path);
			
			Log::info('FacturaPdfController.pdf.serving', [
				'path' => $path,
				'size' => $fileSize,
				'exists' => $fileExists,
				'readable' => $isReadable
			]);
			
			if (!$fileExists || !$isReadable) {
				Log::error('FacturaPdfController.pdf.fileIssue', [
					'path' => $path,
					'exists' => $fileExists,
					'readable' => $isReadable
				]);
				return response()->json([ 'success' => false, 'message' => 'Archivo PDF no accesible' ], 500);
			}
			
			$suffix = $anulado ? '_ANULADO' : '';
			$filename = "factura_{$anio}_{$nro}{$suffix}.pdf";
			
			return response()->download($path, $filename, [
				'Content-Type' => 'application/pdf',
				'Content-Disposition' => 'attachment; filename="' . $filename . '"'
			]);
		} catch (\Throwable $e) {
			Log::error('FacturaPdfController.pdf.exception', [ 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString() ]);
			return response()->json([ 'success' => false, 'message' => $e->getMessage() ], 500);
		}
	}
}
