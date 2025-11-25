<?php

namespace App\Services;

use Dompdf\Dompdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FacturaPdfService
{
	/**
	 * Genera el PDF de la factura. Si $anulado=true, aplica marca/etiqueta ANULADO.
	 * Retorna la ruta absoluta del PDF generado.
	 */
	public function generate($anio, $nro, $anulado = false)
	{
		$anio = (int) $anio; $nro = (int) $nro;
		$row = DB::table('factura')
			->where('anio', $anio)
			->where('nro_factura', $nro)
			->first();
		if (!$row) {
			throw new \RuntimeException('Factura no encontrada');
		}

		$detalles = [];
		try {
			if (DB::getSchemaBuilder()->hasTable('factura_detalle')) {
				$det = DB::table('factura_detalle')
					->where('anio', $anio)
					->where('nro_factura', $nro)
					->orderBy('id_detalle')
					->get();
				foreach ($det as $d) {
					$detalles[] = [
						'descripcion' => (string)($d->descripcion ?? 'Item'),
						'cantidad' => (float)($d->cantidad ?? 1),
						'precio' => (float)($d->precio_unitario ?? ($d->subtotal ?? 0)),
						'subtotal' => (float)($d->subtotal ?? 0),
					];
				}
			}
		} catch (\Throwable $e) {}

		$fecha = (string)($row->fecha_emision ?? '');
		try { if ($fecha === '') { $fecha = date('Y-m-d H:i:s'); } } catch (\Throwable $e) { $fecha = date('Y-m-d H:i:s'); }
		$cliente = (string)($row->cliente ?? ($row->razon ?? 'S/N'));
		$monto = (float)($row->monto_total ?? 0);
		$cuf = (string)($row->cuf ?? '');
		$sucursal = (int)($row->codigo_sucursal ?? 0);
		$pv = (string)($row->codigo_punto_venta ?? '0');
		$estado = (string)($row->estado ?? '');

		$water = $anulado ? '<div style="position:fixed; top:40%; left:10%; right:10%; text-align:center; font-size:64px; color:#ee0000; opacity:0.15; transform:rotate(-20deg);">ANULADO</div>' : '';
		$title = $anulado ? 'FACTURA ANULADA' : 'FACTURA COMPUTARIZADA';

		$rowsHtml = '';
		if ($detalles) {
			foreach ($detalles as $d) {
				$rowsHtml .= '<tr><td>' . htmlspecialchars($d['descripcion']) . '</td><td style="text-align:right">' . number_format($d['cantidad'], 2, '.', '') . '</td><td style="text-align:right">' . number_format($d['precio'], 2, '.', '') . '</td><td style="text-align:right">' . number_format($d['subtotal'], 2, '.', '') . '</td></tr>';
			}
		}

		$html = "<!DOCTYPE html><html lang=\"es\"><head><meta charset=\"utf-8\" /><style>
		\t@page { margin: 10mm; }
		\tbody { font-family: DejaVu Sans, sans-serif; font-size: 11pt; }
		\t.right { text-align:right; }
		\t.center { text-align:center; }
		\t.table { width:100%; border-collapse:collapse; }
		\t.table th, .table td { border:1px solid #000; padding:4px; }
		\t.small { font-size:9pt; color:#444; }
		</style><title>{$title} {$nro}/{$anio}</title></head><body>{$water}
		<h2 class=\"center\">{$title}</h2>
		<table class=\"table\" style=\"margin-top:6px\"><tr><td>
		<b>NIT:</b> " . (int)config('sin.nit') . "<br/>
		<b>Sucursal:</b> {$sucursal} &nbsp; <b>Pto Venta:</b> {$pv}<br/>
		<b>Nro Factura:</b> {$nro}/{$anio}<br/>
		<b>Fecha Emisión:</b> {$fecha}<br/>
		<b>CUF:</b> {$cuf}
		</td><td>
		<b>Cliente:</b> " . htmlspecialchars($cliente) . "<br/>
		<b>Estado:</b> " . htmlspecialchars($estado) . "<br/>
		<b>Monto Total:</b> " . number_format($monto, 2, '.', '') . " Bs.
		</td></tr></table>
		<div style=\"height:6px\"></div>
		<table class=\"table\"><thead><tr><th>Descripción</th><th style=\"width:80px\" class=\"right\">Cant.</th><th style=\"width:100px\" class=\"right\">P. Unit</th><th style=\"width:110px\" class=\"right\">Subtotal</th></tr></thead><tbody>{$rowsHtml}</tbody></table>
		<div class=\"right\" style=\"margin-top:8px; font-weight:bold\">TOTAL: " . number_format($monto, 2, '.', '') . " Bs.</div>
		<div class=\"small\" style=\"margin-top:10px\">“Este documento es la Representación Gráfica de un Documento Fiscal Digital emitido en una modalidad de facturación en línea”.</div>
		</body></html>";

		$dompdf = new Dompdf([ 'isRemoteEnabled' => true ]);
		$dompdf->loadHtml($html, 'UTF-8');
		$dompdf->setPaper('A4', 'portrait');
		$dompdf->render();
		$pdf = $dompdf->output();

		$dir = storage_path('siat_xml' . DIRECTORY_SEPARATOR . 'facturas');
		if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
		$path = $dir . DIRECTORY_SEPARATOR . $anio . '_' . $nro . '.pdf';
		@file_put_contents($path, $pdf);
		Log::info('FacturaPdfService.generate', [ 'anio' => $anio, 'nro' => $nro, 'anulado' => $anulado, 'path' => $path, 'len' => strlen($pdf) ]);
		return $path;
	}
}
