<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\Siat\EstadoFacturaService;
use App\Services\Siat\AnulacionFacturaService;
use App\Services\FacturaPdfService;

class FacturaAnulacionController extends Controller
{
	/** @var EstadoFacturaService */
	private $estadoSvc;
	/** @var AnulacionFacturaService */
	private $anulacionSvc;

	public function __construct(EstadoFacturaService $estadoSvc, AnulacionFacturaService $anulacionSvc)
	{
		$this->estadoSvc = $estadoSvc;
		$this->anulacionSvc = $anulacionSvc;
	}

	public function anular($anio, $nro, Request $request)
	{
		try {
			$anio = (int) $anio;
			$nro = (int) $nro;
			$codigoMotivo = 1;
			$v1 = $request->input('codigo_motivo');
			$v2 = $request->input('codigoMotivo');
			if ($v1 !== null && $v1 !== '') { $codigoMotivo = (int) $v1; }
			elseif ($v2 !== null && $v2 !== '') { $codigoMotivo = (int) $v2; }

			$row = DB::table('factura')
				->where('anio', $anio)
				->where('nro_factura', $nro)
				->first();
			if (!$row) {
				return response()->json([ 'success' => false, 'message' => 'Factura no encontrada' ], 404);
			}

			$cuf = isset($row->cuf) ? (string)$row->cuf : '';
			$pv = isset($row->codigo_punto_venta) ? (int)$row->codigo_punto_venta : 0;
			$sucursal = isset($row->codigo_sucursal) ? (int)$row->codigo_sucursal : (int) config('sin.sucursal');
			if ($cuf === '') {
				return response()->json([ 'success' => false, 'message' => 'Factura sin CUF' ], 400);
			}

			Log::info('FacturaAnulacionController.begin', [
				'anio' => $anio,
				'nro' => $nro,
				'cuf' => $cuf,
				'punto_venta' => $pv,
				'sucursal' => $sucursal,
				'motivo' => $codigoMotivo,
			]);

			// 1) Verificar estado actual en SIN
			$pre = $this->estadoSvc->verificacionEstadoFactura($cuf, $pv, $sucursal);
			$preEstado = (is_array($pre) && isset($pre['estado'])) ? $pre['estado'] : null;
			Log::info('FacturaAnulacionController.preEstado', [
				'codigoEstado' => (is_array($pre) && isset($pre['codigoEstado'])) ? $pre['codigoEstado'] : null,
				'estado' => $preEstado,
				'descripcion' => (is_array($pre) && isset($pre['descripcion'])) ? $pre['descripcion'] : null,
			]);

			if ($preEstado === 'ANULADA') {
				return response()->json([
					'success' => true,
					'pre_estado' => $preEstado,
					'post_estado' => $preEstado,
					'message' => 'La factura ya se encuentra anulada.',
					'pre' => $pre,
				]);
			}

			// 2) Enviar solicitud de anulación
			Log::info('FacturaAnulacionController.callAnulacion', [ 'cuf' => $cuf, 'motivo' => $codigoMotivo ]);
			$anu = $this->anulacionSvc->anular($cuf, $codigoMotivo, $pv, $sucursal);
			Log::info('FacturaAnulacionController.anulacionResp', [
				'codigoEstado' => isset($anu['codigoEstado']) ? $anu['codigoEstado'] : null,
				'success' => !empty($anu['success']),
			]);

			// 3) Verificar nuevamente estado
			$post = $this->estadoSvc->verificacionEstadoFactura($cuf, $pv, $sucursal);
			$postEstado = (is_array($post) && isset($post['estado'])) ? $post['estado'] : null;
			$intentos = 0;
			$hist = [];
			$hist[] = [ 'codigoEstado' => (is_array($post) && isset($post['codigoEstado'])) ? $post['codigoEstado'] : null, 'estado' => $postEstado, 'descripcion' => (is_array($post) && isset($post['descripcion'])) ? $post['descripcion'] : null ];
			while ($postEstado === 'EN_PROCESO' && $intentos < 4) {
				$intentos++;
				usleep(800000); // 0.8s
				$re = $this->estadoSvc->verificacionEstadoFactura($cuf, $pv, $sucursal);
				$reEstado = (is_array($re) && isset($re['estado'])) ? $re['estado'] : null;
				$hist[] = [ 'codigoEstado' => (is_array($re) && isset($re['codigoEstado'])) ? $re['codigoEstado'] : null, 'estado' => $reEstado, 'descripcion' => (is_array($re) && isset($re['descripcion'])) ? $re['descripcion'] : null ];
				Log::info('FacturaAnulacionController.postRetry', [ 'try' => $intentos, 'estado' => $reEstado, 'codigoEstado' => (is_array($re) && isset($re['codigoEstado'])) ? $re['codigoEstado'] : null ]);
				$post = $re;
				$postEstado = $reEstado;
			}

			// 4) Actualizar estado en BD si aplica
			if ($postEstado === 'ANULADA') {
				DB::table('factura')
					->where('anio', $anio)
					->where('nro_factura', $nro)
					->where('codigo_sucursal', $sucursal)
					->where('codigo_punto_venta', (string) $pv)
					->update(['estado' => 'ANULADA']);

				// Generar y reemplazar PDF ANULADO
				try {
					$pdf = new FacturaPdfService();
					$pdfPath = $pdf->generate($anio, $nro, true);
				} catch (\Throwable $e) {
					Log::warning('FacturaAnulacionController.pdfAnulado.fail', [ 'error' => $e->getMessage() ]);
					$pdfPath = null;
				}
			}

			return response()->json([
				'success' => true,
				'pre_estado' => $preEstado,
				'post_estado' => $postEstado,
				'motivo' => $codigoMotivo,
				'anulacion' => $anu,
				'pre' => $pre,
				'post' => $post,
				'intentos' => $intentos,
				'historial' => $hist,
				'pdf_anulado' => isset($pdfPath) ? $pdfPath : null,
			]);
		} catch (\Throwable $e) {
			Log::error('FacturaAnulacionController.anular', [ 'error' => $e->getMessage() ]);
			return response()->json([ 'success' => false, 'message' => $e->getMessage() ], 500);
		}
	}
}
