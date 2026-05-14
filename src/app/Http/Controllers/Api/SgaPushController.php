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
                $doc = '';
                if (!empty($payload['num_factura'])) {
                    $doc = 'F-' . $payload['num_factura'];
                } elseif (!empty($payload['num_comprobante'])) {
                    $doc = 'R-' . $payload['num_comprobante'];
                }

                return [
                    'id'         => $p->id,
                    'documento'  => $doc,
                    'estudiante' => $p->cod_ceta . ' - ' . ($payload['razon'] ?? 'N/A'),
                    'concepto'   => $payload['concepto'] ?? 'Cobro',
                    'mensaje'    => $p->ultimo_error ?: 'Error desconocido',
                    'intentos'   => $p->intentos,
                    'fecha'      => $p->created_at->format('Y-m-d H:i'),
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

            // Determinar endpoint basado en destino_tabla
            $endpoint = '/api/sync/pago';
            if ($registro->destino_tabla === 'pago_multa') {
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
                $endpoint = '/api/sync/pago';
                if ($p->destino_tabla === 'pago_multa') {
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
