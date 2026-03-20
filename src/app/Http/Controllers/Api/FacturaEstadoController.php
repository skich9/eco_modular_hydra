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
            $sucursal = $request->query('sucursal');
            $fechaInicio = $request->query('fecha_inicio');
            $fechaFin = $request->query('fecha_fin');

            $q = DB::table('factura as f')
                ->leftJoin('usuarios as u', 'u.id_usuario', '=', 'f.id_usuario')
                ->select(
                    'f.anio',
                    'f.nro_factura',
                    'f.fecha_emision',
                    'f.estado',
                    'f.cuf',
                    'f.codigo_recepcion',
                    'f.codigo_sucursal',
                    // Datos adicionales para libro diario y otras UIs
                    'f.cliente',
                    'f.nro_documento_cobro',
                    'f.cod_ceta',
                    'f.id_forma_cobro',
                    'f.monto_total',
                    'u.nickname as nombre_usuario'
                );
            if ($anio) { $q->where('f.anio', (int)$anio); }
            if ($sucursal !== null && $sucursal !== '') { $q->where('f.codigo_sucursal', (int)$sucursal); }
            if ($fechaInicio) { $q->where('f.fecha_emision', '>=', $fechaInicio . ' 00:00:00'); }
            if ($fechaFin)    { $q->where('f.fecha_emision', '<=', $fechaFin    . ' 23:59:59'); }
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
                    $anioVal = (int) (isset($r->anio) ? $r->anio : 0);
                    $nroVal = (int) (isset($r->nro_factura) ? $r->nro_factura : 0);
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
                        usort($paths, function ($a, $b) {
                            $mb = @filemtime($b);
                            $ma = @filemtime($a);
                            if ($mb == $ma) { return 0; }
                            return ($mb < $ma) ? -1 : 1;
                        });
                        foreach ($paths as $p) {
                            $xml = @file_get_contents($p);
                            if (!$xml) { continue; }
                            $sx = @simplexml_load_string($xml);
                            if (!$sx) { continue; }
                            $ns = (isset($sx->cabecera) && isset($sx->cabecera->nombreRazonSocial)) ? (string)$sx->cabecera->nombreRazonSocial : null;
                            if ($ns) { $cliente = trim((string)$ns); break; }
                        }
                    }
                    }
                } catch (\Throwable $e) {}
                $nit = isset($r->nro_documento_cobro) ? (string)$r->nro_documento_cobro : '0';
                $codCeta = isset($r->cod_ceta) ? (string)$r->cod_ceta : '0';
                $idFormaCobro = isset($r->id_forma_cobro) ? (string)$r->id_forma_cobro : null;
                $montoTotal = isset($r->monto_total) ? (float)$r->monto_total : 0.0;
                $data[] = [
                    'anio' => (int) $r->anio,
                    'nro_factura' => (int) $r->nro_factura,
                    'fecha_emision' => $fecha,
                    'estado' => isset($r->estado) ? (string)$r->estado : '',
                    'cuf' => isset($r->cuf) ? (string)$r->cuf : '',
                    'codigo_recepcion' => isset($r->codigo_recepcion) ? (string)$r->codigo_recepcion : '',
                    'cliente' => $cliente,
                    'horas_restantes' => $horasRestantes,
                    // Nuevos campos opcionales (backwards compatible)
                    'nit' => $nit,
                    'cod_ceta' => $codCeta,
                    'id_forma_cobro' => $idFormaCobro,
                    'monto_total' => $montoTotal,
                    'codigo_sucursal' => isset($r->codigo_sucursal) ? (int)$r->codigo_sucursal : 0,
                    'nombre_usuario' => isset($r->nombre_usuario) ? trim((string)$r->nombre_usuario) : '',
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

    public function sucursales()
    {
        try {
            $rows = DB::table('factura')
                ->select('codigo_sucursal')
                ->distinct()
                ->orderBy('codigo_sucursal')
                ->pluck('codigo_sucursal');
            return response()->json([ 'success' => true, 'data' => $rows ]);
        } catch (\Throwable $e) {
            return response()->json([ 'success' => false, 'message' => $e->getMessage() ], 500);
        }
    }
    public function estado($anio, $nro, EstadoFacturaService $estadoSvc)
    {
        try {
            $row = DB::table('factura')
                ->select('anio','nro_factura','codigo_sucursal','codigo_punto_venta','cuf','codigo_recepcion','estado')
                ->where('anio', (int)$anio)
                ->where('nro_factura', (int)$nro)
                ->first();
            if (!$row) {
                return response()->json([ 'success' => false, 'message' => 'Factura no encontrada' ], 404);
            }

            $pv = isset($row->codigo_punto_venta) ? (int)$row->codigo_punto_venta : 0;
            $suc = isset($row->codigo_sucursal) ? (int)$row->codigo_sucursal : (int)config('sin.sucursal');
            $cuf = isset($row->cuf) ? (string)$row->cuf : '';

            Log::info('FacturaEstadoController.estado el rou que se recupera es:', [
                'anio' => $anio,
                'nro_factura' => $nro,
                'cuf' => $cuf,
                'codigo_punto_venta' => $pv,
                'codigo_sucursal' => $suc,
                'row' => $row
            ]);

            // Verificar estado local antes de llamar al SIN
            $estadoLocal = isset($row->estado) ? strtoupper(trim((string)$row->estado)) : '';

            if ($estadoLocal === 'ANULADA') {
                return response()->json([
                    'success' => true,
                    'estado'  => 'ANULADA',
                    'message' => 'Esta factura ya se encuentra anulada.',
                    'data'    => [
                        'estado'           => 'ANULADA',
                        'codigo_recepcion' => isset($row->codigo_recepcion) ? (string)$row->codigo_recepcion : '',
                    ],
                ]);
            }

            $estadosNoRegistrados = ['CONTINGENCIA', 'RECHAZADA', 'EN PROCESO'];
            if (in_array($estadoLocal, $estadosNoRegistrados)) {
                return response()->json([
                    'success' => false,
                    'estado'  => $estadoLocal,
                    'message' => 'La factura no se encuentra registrada en impuestos. Debe regularizarla primero para poder consultar su estado en el servidor de impuestos.',
                    'data'    => [
                        'estado'           => $estadoLocal,
                        'codigo_recepcion' => isset($row->codigo_recepcion) ? (string)$row->codigo_recepcion : '',
                    ],
                ], 422);
            }

            $resp = $estadoSvc->verificacionEstadoFactura($cuf, $pv, $suc);
            // $estadoNorm = is_array($resp) && isset($resp['estado']) ? (string)$resp['estado'] : null;
            // Ya no se maneja estadoNorm  // SE QUITARA DE LA LOGICA
            /****************************************************************************************************************************************************/
            /************************************ TRABAJAR AQUI Y TERMINAR LA VERIFICACION DE ESTADO ************************************************************/
            /****************************************************************************************************************************************************/
                // 'codigoEstado' => $codigoEstado,
                // 'descripcionEstado' => $descripcionEstado,
            $codigoEstado = is_array($resp) && isset($resp['codigoEstado']) ? $resp['codigoEstado'] : null;
            $descripcionEstado = is_array($resp) && isset($resp['descripcionEstado']) ? $resp['descripcionEstado'] : null;
            $codigoRecep = isset($row->codigo_recepcion) ? (string)$row->codigo_recepcion : '';

            // if ($estadoNorm === null || $estadoNorm === '' || empty($resp['success'])) {
            //     $estadoLocal = isset($row->estado) ? strtoupper(trim((string)$row->estado)) : '';
            //     // Normalizar estados locales para UI
            //     if ($estadoLocal === 'VIGENTE') {
            //         $estadoLocal = 'ACEPTADA';
            //         if ($codigoEstadoNorm === null) { $codigoEstadoNorm = 690; }
            //     }
            //     if ($estadoLocal === 'CONTINGENC' || $estadoLocal === 'CONTINGENCIA') {
            //         $estadoLocal = 'EN_PROCESO';
            //     }
            //     // Derivar estado si BD no tiene valor explícito
            //     if ($estadoLocal === '' && $codigoRecep !== '') {
            //         $estadoLocal = 'ACEPTADA';
            //         if ($codigoEstadoNorm === null) { $codigoEstadoNorm = 690; }
            //     }
            //     if ($estadoLocal === '' && $codigoRecep === '' && $cuf !== '') {
            //         $estadoLocal = 'ENVIADA';
            //     }
            //     if ($estadoLocal === '') {
            //         $estadoLocal = 'DESCONOCIDO';
            //     }
            //     if ($estadoLocal === 'ACEPTADA' && $codigoEstadoNorm === null) {
            //         $codigoEstadoNorm = 690;
            //     }

            //     $estadoNorm = $estadoNorm ?: $estadoLocal;
            //     if ($descripcionNorm === null) { $descripcionNorm = 'Estado derivado localmente'; }
            // }
            Log::debug('FacturaEstadoController.estado', [
                'anio' => $anio,
                'nro_factura' => $nro,
                'cuf' => $cuf,
                'codigo_punto_venta' => $pv,
                'codigo_sucursal' => $suc,
                'codigo_recepcion' => $codigoRecep,
                'estado_local' => $estadoLocal,
                // 'estado_norm' => $estadoNorm, // SE QUITARA DE LA LOGICA
                'codigo_estado' => $codigoEstado,
                'descripcion_estado' => $descripcionEstado,
                // 'descripcion_norm' => $descripcionNorm, // SE QUITARA DE LA LOGICA
            ]);
            $nuevoEstadoFactura = "";
            if($codigoEstado == 690 ) {
                $nuevoEstadoFactura = "VALIDADA";
                $codigoRecepcion = is_array($resp) && isset($resp['codigoRecepcion']) ? $resp['codigoRecepcion'] : null;
                DB::table('factura')
                    ->where('anio', (int)$anio)
                    ->where('nro_factura', (int)$nro)
                    ->update([
                        'estado' => $nuevoEstadoFactura,
                        'updated_at' => now(),
                        'codigo_recepcion' => $codigoRecepcion
                    ]);
            } else if($codigoEstado == 902) {
                $nuevoEstadoFactura = "RECHAZADA";
                $mensajesList = is_array($resp) && isset($resp['mensajesList']) ? $resp['mensajesList'] : null;
                DB::table('factura')
                    ->where('anio', (int)$anio)
                    ->where('nro_factura', (int)$nro)
                    ->update([
                        'estado' => $nuevoEstadoFactura,
                        'updated_at' => now(),
                        'codigo_excepcion' => json_encode($mensajesList)
                    ]);

            } else if($codigoEstado == 691) {
                $nuevoEstadoFactura = "ANULADA";
                DB::table('factura')
                    ->where('anio', (int)$anio)
                    ->where('nro_factura', (int)$nro)
                    ->update([
                        'estado' => $nuevoEstadoFactura,
                        'updated_at' => now(),
                        'codigo_recepcion' => $codigoRecep
                    ]);
            } else {
                $nuevoEstadoFactura = "CONTINGENCIA";
                DB::table('factura')
                    ->where('anio', (int)$anio)
                    ->where('nro_factura', (int)$nro)
                    ->update([
                        'estado' => $nuevoEstadoFactura,
                        'updated_at' => now(),
                        'codigo_excepcion' => json_encode($resp)
                    ]);
            }
            Log::debug('FacturaEstadoController.estado Paso la ejecucion de todos los ifs');
            // Log SOAP si existe tabla
            try {
                DB::table('sin_soap_logs')->insert([
                    'service' => isset($resp['service']) ? (string)$resp['service'] : (string) config('sin.operations_service', 'ServicioFacturacionElectronica'),
                    'method' => 'verificacionEstadoFactura',
                    'request_xml' => isset($resp['last_request']) ? $resp['last_request'] : null,
                    'response_xml' => isset($resp['last_response']) ? $resp['last_response'] : null,
                    'success' => (int) (!empty($resp['success'])),
                    'error' => isset($resp['message']) ? $resp['message'] : null,
                    'created_at' => now(),
                ]);
            } catch (\Throwable $e) { /* best-effort */ }

            $respData = is_array($resp) ? $resp : [];
            $respData['estado'] = $nuevoEstadoFactura;
            $respData['codigoEstado'] = $codigoEstado;
            $respData['descripcion'] = $descripcionEstado;
            $respData['codigo_recepcion'] = $codigoRecep;

            $ok = !empty($resp['success']) || $codigoEstado !== null;
            $respData['success'] = $ok;
            if ($ok && empty($resp['success'])) { $respData['message'] = null; }

            return response()->json([
                'success' => $ok,
                'estado' => $nuevoEstadoFactura,
                'codigoEstado' => $codigoEstado,
                'descripcion' => $descripcionEstado,
                'codigo_recepcion' => $codigoRecep,
                'data' => $respData,
            ]);
        } catch (\Throwable $e) {
            return response()->json([ 'success' => false, 'message' => $e->getMessage() ], 500);
        }
    }
}
