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
				->orderByDesc('created_at')
				->orderByDesc('codigo_sucursal')
				->orderByDesc('codigo_punto_venta')
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
						->where('codigo_sucursal', isset($factura->codigo_sucursal) ? (int)$factura->codigo_sucursal : 0)
						->where('codigo_punto_venta', isset($factura->codigo_punto_venta) ? (string)$factura->codigo_punto_venta : '0')
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

	public function pdfByCuf($cuf)
	{
		try {
			$cuf = (string)$cuf;
			$row = DB::table('factura')
				->where('cuf', $cuf)
				->orderByDesc('created_at')
				->orderByDesc('codigo_sucursal')
				->orderByDesc('codigo_punto_venta')
				->first();
			if (!$row) {
				Log::error('FacturaPdfController.pdfByCuf.notFound', [ 'cuf' => $cuf ]);
				return response()->json([ 'success' => false, 'message' => 'Factura no encontrada para el CUF dado' ], 404);
			}
			$anio = (int)$row->anio;
			$nro = (int)$row->nro_factura;
			$estado = isset($row->estado) ? (string)$row->estado : '';
			$anulado = ($estado === 'ANULADA');

			Log::info('FacturaPdfController.pdfByCuf.start', [ 'anio' => $anio, 'nro' => $nro, 'cuf' => $cuf, 'estado' => $estado, 'anulado' => $anulado ]);

			$svc = new FacturaPdfService();
			$dir = storage_path('siat_xml' . DIRECTORY_SEPARATOR . 'facturas');
			$candidateAnulado = $dir . DIRECTORY_SEPARATOR . $anio . '_' . $nro . '_ANULADO.pdf';
			if ($anulado && is_file($candidateAnulado)) {
				$path = $candidateAnulado;
			} else {
				$path = $anulado ? $svc->generateAnuladaStrict($anio, $nro)
					: $svc->generate($anio, $nro, false);
			}

			if (!is_file($path)) {
				Log::error('FacturaPdfController.pdfByCuf.fileNotFound', [ 'path' => $path ]);
				return response()->json([ 'success' => false, 'message' => 'No se pudo generar PDF' ], 500);
			}

			$fileSize = filesize($path);
			$fileExists = file_exists($path);
			$isReadable = is_readable($path);
			Log::info('FacturaPdfController.pdfByCuf.serving', [
				'path' => $path,
				'size' => $fileSize,
				'exists' => $fileExists,
				'readable' => $isReadable
			]);
			if (!$fileExists || !$isReadable) {
				return response()->json([ 'success' => false, 'message' => 'Archivo PDF no accesible' ], 500);
			}

			$filename = basename($path);
			$headers = [
				'Content-Type' => 'application/pdf',
				'Content-Disposition' => 'attachment; filename="' . $filename . '"',
				'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
				'Pragma' => 'no-cache',
				'Expires' => '0',
				'Content-Length' => (string)$fileSize,
			];
			$headers['X-Served-File'] = $filename;
			return response()->download($path, $filename, $headers);
		} catch (\Throwable $e) {
			Log::error('FacturaPdfController.pdfByCuf.exception', [ 'error' => $e->getMessage() ]);
			return response()->json([ 'success' => false, 'message' => $e->getMessage() ], 500);
		}
	}

	public function pdfAnulado($anio, $nro)
    {
        try {
            $anio = (int) $anio; $nro = (int) $nro;
            $svc = new FacturaPdfService();
            $path = $svc->generateAnuladaStrict($anio, $nro);
            if (!is_file($path) || !is_readable($path)) {
                return response()->json([ 'success' => false, 'message' => 'No se pudo generar PDF anulado' ], 500);
            }
            $filename = basename($path);
            $size = @filesize($path) ?: null;
            $headers = [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                'Pragma' => 'no-cache',
                'Expires' => '0',
            ];
            if ($size) { $headers['Content-Length'] = (string)$size; }
            $headers['X-Served-File'] = $filename;
            return response()->download($path, $filename, $headers);
        } catch (\Throwable $e) {
            Log::error('FacturaPdfController.pdfAnulado.exception', [ 'error' => $e->getMessage() ]);
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
				->orderByDesc('created_at')
				->orderByDesc('codigo_sucursal')
				->orderByDesc('codigo_punto_venta')
				->first();
			if (!$row) {
				Log::error('FacturaPdfController.pdf.notFound', [ 'anio' => $anio, 'nro' => $nro ]);
				return response()->json([ 'success' => false, 'message' => 'Factura no encontrada' ], 404);
			}
			$estado = isset($row->estado) ? (string)$row->estado : '';
			$anulado = ($estado === 'ANULADA');

			Log::info('FacturaPdfController.pdf.start', [ 'anio' => $anio, 'nro' => $nro, 'estado' => $estado, 'anulado' => $anulado ]);

			$svc = new FacturaPdfService();
			$dir = storage_path('siat_xml' . DIRECTORY_SEPARATOR . 'facturas');
			$candidateAnulado = $dir . DIRECTORY_SEPARATOR . $anio . '_' . $nro . '_ANULADO.pdf';
			if (is_file($candidateAnulado)) {
				$path = $candidateAnulado;
			} else {
				$path = $anulado ? $svc->generateAnuladaStrict($anio, $nro)
					: $svc->generate($anio, $nro, false);
			}

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

			$filename = basename($path);
			$headers = [
				'Content-Type' => 'application/pdf',
				'Content-Disposition' => 'attachment; filename="' . $filename . '"',
				'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
				'Pragma' => 'no-cache',
				'Expires' => '0',
				'Content-Length' => (string)$fileSize,
			];
			$headers['X-Served-File'] = $filename;
			return response()->download($path, $filename, $headers);
		} catch (\Throwable $e) {
			Log::error('FacturaPdfController.pdf.exception', [ 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString() ]);
			return response()->json([ 'success' => false, 'message' => $e->getMessage() ], 500);
		}
	}
}
