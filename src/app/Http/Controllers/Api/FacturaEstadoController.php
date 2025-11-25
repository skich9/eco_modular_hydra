<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\Siat\EstadoFacturaService;

class FacturaEstadoController extends Controller
{
    public function estado($anio, $nro, EstadoFacturaService $estadoSvc)
    {
        try {
            $row = DB::table('factura')
                ->select('anio','nro_factura','codigo_sucursal','codigo_punto_venta','cuf')
                ->where('anio', (int)$anio)
                ->where('nro_factura', (int)$nro)
                ->first();
            if (!$row) {
                return response()->json([ 'success' => false, 'message' => 'Factura no encontrada' ], 404);
            }

            $pv = isset($row->codigo_punto_venta) ? (int)$row->codigo_punto_venta : 0;
            $suc = isset($row->codigo_sucursal) ? (int)$row->codigo_sucursal : (int)config('sin.sucursal');
            $cuf = isset($row->cuf) ? (string)$row->cuf : '';

            $resp = $estadoSvc->verificacionEstadoFactura($cuf, $pv, $suc);

            // Persistir estado simple en tabla factura (opcional)
            try {
                if (!empty($resp['estado'])) {
                    DB::table('factura')
                        ->where('anio', (int)$anio)
                        ->where('nro_factura', (int)$nro)
                        ->update([
                            'estado' => (string)$resp['estado'],
                            'updated_at' => now(),
                        ]);
                }
            } catch (\Throwable $e) { /* best-effort */ }

            // Log SOAP si existe tabla
            try {
                DB::table('sin_soap_logs')->insert([
                    'service' => (string) config('sin.operations_service', 'ServicioFacturacionElectronica'),
                    'method' => 'verificacionEstadoFactura',
                    'request_xml' => isset($resp['last_request']) ? $resp['last_request'] : null,
                    'response_xml' => isset($resp['last_response']) ? $resp['last_response'] : null,
                    'success' => (int) (!empty($resp['success'])),
                    'error' => isset($resp['message']) ? $resp['message'] : null,
                    'created_at' => now(),
                ]);
            } catch (\Throwable $e) { /* best-effort */ }

            return response()->json([ 'success' => !empty($resp['success']), 'data' => $resp ]);
        } catch (\Throwable $e) {
            return response()->json([ 'success' => false, 'message' => $e->getMessage() ], 500);
        }
    }
}
