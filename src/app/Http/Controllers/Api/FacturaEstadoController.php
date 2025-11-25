<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\Siat\EstadoFacturaService;

class FacturaEstadoController extends Controller
{
    public function lista(Request $request)
    {
        try {
            $page = (int) ($request->query('page', 1));
            if ($page < 1) $page = 1;
            $perPage = (int) ($request->query('per_page', 10));
            if ($perPage < 1) $perPage = 10; if ($perPage > 100) $perPage = 100;
            $anio = $request->query('anio');

            $q = DB::table('factura as f')
                ->select('f.anio','f.nro_factura','f.fecha_emision','f.estado','f.cuf','f.codigo_recepcion','f.cliente');
            if ($anio) { $q->where('f.anio', (int)$anio); }
            $q->orderBy('f.anio', 'desc')->orderBy('f.nro_factura', 'desc');

            $total = $q->count();
            $rows = $q->forPage($page, $perPage)->get();

            $data = [];
            foreach ($rows as $r) {
                $fecha = isset($r->fecha_emision) ? (string)$r->fecha_emision : '';
                $ts = 0;
                try { $ts = strtotime(str_replace(' ', 'T', $fecha)); } catch (\Throwable $e) { $ts = 0; }
                $diffHours = 0;
                if ($ts && $ts > 0) {
                    $diffHours = (int) floor((time() - $ts) / 3600);
                }
                $horasRestantes = max(0, 48 - $diffHours);
                $cliente = isset($r->cliente) ? trim((string)$r->cliente) : '';
                try {
                    if ($cliente === '') {
                    $anioVal = (int) ($r->anio ?? 0);
                    $nroVal = (int) ($r->nro_factura ?? 0);
                    $cufVal = isset($r->cuf) ? (string)$r->cuf : '';
                    $safeCuf = $cufVal !== '' ? preg_replace('/[^A-Za-z0-9]/', '', $cufVal) : '';

                    $paths = [];
                    $literals = [
                        $safeCuf ? storage_path('siat_xml' . DIRECTORY_SEPARATOR . 'firmado' . DIRECTORY_SEPARATOR . $safeCuf . '.xml') : null,
                        $safeCuf ? storage_path('siat_xml' . DIRECTORY_SEPARATOR . 'xmls' . DIRECTORY_SEPARATOR . $safeCuf . '.xml') : null,
                        $safeCuf ? storage_path('siat_xml' . DIRECTORY_SEPARATOR . 'index' . DIRECTORY_SEPARATOR . $safeCuf . '.xml') : null,
                        storage_path('siat_xml' . DIRECTORY_SEPARATOR . 'firmado' . DIRECTORY_SEPARATOR . $nroVal . '.xml'),
                        storage_path('siat_xml' . DIRECTORY_SEPARATOR . 'xmls' . DIRECTORY_SEPARATOR . $nroVal . '.xml'),
                        storage_path('siat_xml' . DIRECTORY_SEPARATOR . 'index' . DIRECTORY_SEPARATOR . $anioVal . '_' . $nroVal . '.xml'),
                        storage_path('guias_sga' . DIRECTORY_SEPARATOR . 'firmado' . DIRECTORY_SEPARATOR . $anioVal . '_' . $nroVal . '.xml'),
                        storage_path('guias_sga' . DIRECTORY_SEPARATOR . $anioVal . '_' . $nroVal . '.xml'),
                    ];
                    foreach ($literals as $p) { if ($p && is_file($p)) { $paths[] = $p; } }

                    if ($safeCuf) {
                        $g = glob(storage_path('siat_xml' . DIRECTORY_SEPARATOR . 'factura_*_' . $safeCuf . '_*_*.xml')) ?: [];
                        foreach ($g as $p) { if (is_file($p)) { $paths[] = $p; } }
                    }
                    if (!$paths) {
                        $g2 = glob(storage_path('siat_xml' . DIRECTORY_SEPARATOR . '*_' . $nroVal . '_*.xml')) ?: [];
                        foreach ($g2 as $p) { if (is_file($p)) { $paths[] = $p; } }
                    }

                    if ($cliente === '' && $paths) {
                        usort($paths, function ($a, $b) { return filemtime($b) <=> filemtime($a); });
                        foreach ($paths as $p) {
                            $xml = @file_get_contents($p);
                            if (!$xml) { continue; }
                            $sx = @simplexml_load_string($xml);
                            if (!$sx) { continue; }
                            $ns = $sx->cabecera->nombreRazonSocial ?? null;
                            if ($ns) { $cliente = trim((string)$ns); break; }
                        }
                    }
                    }
                } catch (\Throwable $e) {}
                $data[] = [
                    'anio' => (int) $r->anio,
                    'nro_factura' => (int) $r->nro_factura,
                    'fecha_emision' => $fecha,
                    'estado' => isset($r->estado) ? (string)$r->estado : '',
                    'cuf' => isset($r->cuf) ? (string)$r->cuf : '',
                    'codigo_recepcion' => isset($r->codigo_recepcion) ? (string)$r->codigo_recepcion : '',
                    'cliente' => $cliente,
                    'horas_restantes' => $horasRestantes,
                ];
            }

            $meta = [
                'page' => $page,
                'per_page' => $perPage,
                'total' => (int) $total,
                'last_page' => (int) ceil($total / $perPage)
            ];

            return response()->json([ 'success' => true, 'data' => $data, 'meta' => $meta ]);
        } catch (\Throwable $e) {
            return response()->json([ 'success' => false, 'message' => $e->getMessage() ], 500);
        }
    }

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
