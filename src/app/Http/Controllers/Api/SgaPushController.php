<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SgaPushCobro;
use App\Services\Sga\SgaPushService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SgaPushController extends Controller
{
    protected $pushService;

    public function __construct(SgaPushService $pushService)
    {
        $this->pushService = $pushService;
    }

    /**
     * Lista cobros pendientes de sincronización
     */
    public function index()
    {
        try {
            $pendientes = SgaPushCobro::pendientes()
                ->orderBy('created_at', 'desc')
                ->get();

            // Formatear para la UI
            $data = $pendientes->map(function ($p) {
                $payload = $p->payload;
                
                $isMultiple = isset($payload['pagos']);
                $primerPago = null;
                if ($isMultiple) {
                    $pagos = (array) $payload['pagos'];
                    $primerPago = reset($pagos);
                }

                $doc = '';
                if ($isMultiple && $primerPago) {
                    if (!empty($primerPago['num_factura'])) {
                        $doc = 'F-' . $primerPago['num_factura'];
                    } elseif (!empty($primerPago['num_comprobante'])) {
                        $doc = 'R-' . $primerPago['num_comprobante'];
                    }
                } else {
                    if (!empty($payload['num_factura'])) {
                        $doc = 'F-' . $payload['num_factura'];
                    } elseif (!empty($payload['num_comprobante'])) {
                        $doc = 'R-' . $payload['num_comprobante'];
                    }
                }

                $estudiante = '';
                if ($isMultiple && $primerPago) {
                    $estudiante = $p->cod_ceta . ' - ' . ($primerPago['razon'] ?? 'N/A');
                } else {
                    $estudiante = $p->cod_ceta . ' - ' . ($payload['razon'] ?? 'N/A');
                }

                $concepto = '';
                if ($isMultiple) {
                    $conceptos = [];
                    foreach ((array) $payload['pagos'] as $pago) {
                        if (!empty($pago['concepto'])) {
                            $conceptos[] = $pago['concepto'];
                        }
                    }
                    $concepto = implode(', ', array_unique($conceptos));
                    if (empty($concepto)) {
                        $concepto = 'Cobro Múltiple';
                    }
                } else {
                    $concepto = $payload['concepto'] ?? 'Cobro';
                }

                return [
                    'id'         => $p->id,
                    'documento'  => $doc,
                    'estudiante' => $estudiante,
                    'concepto'   => $concepto,
                    'mensaje'    => $p->ultimo_error ?: 'Error desconocido',
                    'intentos'   => $p->intentos,
                    'fecha'      => $p->created_at->format('Y-m-d H:i'),
                    'multiple'   => $isMultiple,
                ];
            });

            return response()->json([
                'success' => true,
                'data'    => $data,
                'count'   => $data->count()
            ]);
        } catch (\Throwable $e) {
            Log::error('Error en SgaPushController@index: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener pendientes: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtiene el detalle estructurado de los pagos en un cobro
     */
    public function show($id)
    {
        try {
            $registro = SgaPushCobro::findOrFail($id);
            $payload = $registro->payload;
            
            $pagos = [];
            if (isset($payload['pagos'])) {
                // Es cobro múltiple (batch)
                foreach ((array) $payload['pagos'] as $pago) {
                    $pagos[] = $this->formatPagoDetail($pago);
                }
            } else {
                // Es cobro único
                $pagos[] = $this->formatPagoDetail($payload);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $registro->id,
                    'cobro_uid' => $registro->cobro_uid,
                    'destino_conn' => $registro->destino_conn,
                    'destino_tabla' => $registro->destino_tabla,
                    'intentos' => $registro->intentos,
                    'ultimo_error' => $registro->ultimo_error,
                    'pagos' => $pagos
                ]
            ]);
        } catch (\Throwable $e) {
            Log::error('Error en SgaPushController@show: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener detalle: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Formatea los datos individuales de un pago para el detalle de la interfaz
     */
    private function formatPagoDetail($pago)
    {
        $doc = '';
        if (!empty($pago['num_factura'])) {
            $doc = 'F-' . $pago['num_factura'];
        } elseif (!empty($pago['num_comprobante'])) {
            $doc = 'R-' . $pago['num_comprobante'];
        }

        return [
            'documento' => $doc,
            'cuota' => $pago['num_cuota'] ?? 'N/A',
            'monto' => $pago['monto'] ?? 0,
            'descuento' => $pago['descuento'] ?? 0,
            'concepto' => $pago['concepto'] ?? 'Cobro',
            'fecha_pago' => isset($pago['fecha_pago']) ? substr($pago['fecha_pago'], 0, 10) : 'N/A',
        ];
    }

    /**
     * Reintenta un cobro específico
     */
    public function retry($id)
    {
        try {
            $registro = SgaPushCobro::findOrFail($id);
            
            if ($registro->sincronizado) {
                return response()->json([
                    'success' => true,
                    'message' => 'Ya estaba sincronizado'
                ]);
            }

            // Determinar endpoint basado en destino_tabla y si es batch (múltiple)
            $endpoint = '/api/sync/pago';
            if (isset($registro->payload['pagos'])) {
                $endpoint = '/api/sync/pagos';
            } elseif ($registro->destino_tabla === 'pago_multa') {
                $endpoint = '/api/sync/pago_multa';
            } elseif ($registro->destino_tabla === 'matricula') {
                $endpoint = '/api/sync/matricula';
            }

            $success = $this->pushService->enviarAlSga(
                $registro,
                $registro->destino_conn,
                $endpoint,
                $registro->payload
            );

            if ($success) {
                return response()->json([
                    'success' => true,
                    'message' => 'Sincronizado correctamente'
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => $registro->ultimo_error ?: 'Error al sincronizar'
            ], 400);

        } catch (\Throwable $e) {
            Log::error('Error en SgaPushController@retry: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error interno: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reintenta todos los pendientes
     */
    public function retryAll()
    {
        try {
            $pendientes = SgaPushCobro::pendientes()->get();
            $results = [
                'total'   => $pendientes->count(),
                'success' => 0,
                'failed'  => 0,
            ];

            foreach ($pendientes as $p) {
                // Determinar endpoint basado en destino_tabla y si es batch (múltiple)
                $endpoint = '/api/sync/pago';
                if (isset($p->payload['pagos'])) {
                    $endpoint = '/api/sync/pagos';
                } elseif ($p->destino_tabla === 'pago_multa') {
                    $endpoint = '/api/sync/pago_multa';
                } elseif ($p->destino_tabla === 'matricula') {
                    $endpoint = '/api/sync/matricula';
                }

                $ok = $this->pushService->enviarAlSga(
                    $p,
                    $p->destino_conn,
                    $endpoint,
                    $p->payload
                );

                if ($ok) {
                    $results['success']++;
                } else {
                    $results['failed']++;
                }
            }

            return response()->json([
                'success' => true,
                'data'    => $results,
                'message' => "Procesados {$results['total']} registros: {$results['success']} exitosos, {$results['failed']} fallidos."
            ]);
        } catch (\Throwable $e) {
            Log::error('Error en SgaPushController@retryAll: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al procesar reintentos: ' . $e->getMessage()
            ], 500);
        }
    }
}
