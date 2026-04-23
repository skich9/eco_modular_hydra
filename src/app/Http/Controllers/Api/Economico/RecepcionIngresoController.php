<?php

namespace App\Http\Controllers\Api\Economico;

use App\Http\Controllers\Controller;
use App\Services\Economico\RecepcionIngresoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RecepcionIngresoController extends Controller
{
    public function __construct(
        private readonly RecepcionIngresoService $service
    ) {}

    // ─── Datos iniciales ──────────────────────────────────────────────────────

    /**
     * GET /api/economico/recepcion-ingresos/initial
     *
     * Retorna catálogos para poblar los selects del formulario:
     * carreras, actividades, usuarios de firma (misma lista en Entregue/Recibi, alineada a SGA) y códigos de libro.
     */
    public function initialData(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->service->initialData(),
        ]);
    }

    // ─── Correlativo ─────────────────────────────────────────────────────────

    /**
     * GET /api/economico/recepcion-ingresos/siguiente-num-documento
     *
     * Retorna el siguiente número y código de documento para una carrera y fecha dadas.
     * Se llama cuando el usuario selecciona carrera o cambia la fecha.
     *
     * Query params: carrera (EEA|MEA), fecha (Y-m-d)
     */
    public function siguienteNumDocumento(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'carrera' => 'required|string|in:EEA,MEA',
            'fecha'   => 'required|date',
        ]);

        $resultado = $this->service->siguienteNumDocumento($validated['carrera'], $validated['fecha']);

        return response()->json(['success' => true, 'data' => $resultado]);
    }

    // ─── Registro ─────────────────────────────────────────────────────────────

    /**
     * POST /api/economico/recepcion-ingresos/registrar
     *
     * Crea una nueva recepción de ingresos con sus detalles.
     */
    public function registrar(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'codigo_carrera'          => 'required|string|in:EEA,MEA',
            'fecha_recepcion'         => 'required|date',
            'usuario_entregue1'       => 'required|string|max:100',
            'usuario_recibi1'         => 'required|string|max:100',
            'usuario_entregue2'       => 'nullable|string|max:100',
            'usuario_recibi2'         => 'nullable|string|max:100',
            'id_actividad_economica'  => 'nullable|integer',
            'es_ingreso_libro_diario' => 'nullable|boolean',
            'observacion'             => 'nullable|string',
            'detalles'                => 'required|array|min:1',
            'detalles.*.usuario_libro'        => 'nullable|string|max:100',
            'detalles.*.cod_libro_diario'     => 'nullable|string|max:100',
            'detalles.*.fecha_inicial_libros' => 'nullable|date',
            'detalles.*.fecha_final_libros'   => 'required|date',
            'detalles.*.total_deposito'       => 'nullable|numeric|min:0',
            'detalles.*.total_traspaso'       => 'nullable|numeric|min:0',
            'detalles.*.total_recibos'        => 'nullable|numeric|min:0',
            'detalles.*.total_facturas'       => 'nullable|numeric|min:0',
            'detalles.*.total_entregado'      => 'nullable|numeric|min:0',
            'detalles.*.faltante_sobrante'    => 'nullable|numeric',
        ]);

        $usuario = auth()->user()?->usuario ?? auth()->user();

        $resultado = $this->service->registrar($validated, $usuario);

        return response()->json([
            'success' => true,
            'message' => 'Recepción registrada correctamente',
            'data'    => $resultado,
        ], 201);
    }

    // ─── Listado ─────────────────────────────────────────────────────────────

    /**
     * GET /api/economico/recepcion-ingresos
     *
     * Lista recepciones con filtros opcionales.
     * Query params: codigo_carrera, fecha_desde, fecha_hasta, id_actividad_economica, anulado, per_page
     */
    public function listar(Request $request): JsonResponse
    {
        $filtros = $request->only([
            'codigo_carrera',
            'fecha_desde',
            'fecha_hasta',
            'id_actividad_economica',
            'anulado',
            'per_page',
        ]);

        $datos = $this->service->listar($filtros);

        return response()->json(['success' => true, 'data' => $datos]);
    }

    // ─── Generar reporte ─────────────────────────────────────────────────────

    /**
     * POST /api/economico/recepcion-ingresos/generar-reporte
     *
     * Construye y retorna los datos del reporte de ingresos para el rango y
     * carrera seleccionados. El frontend genera el PDF con estos datos.
     * Valida que se hayan proporcionado carrera, actividad y rango de fechas
     * antes de procesar.
     */
    public function generarReporte(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'codigo_carrera'         => 'required|string|in:EEA,MEA',
            'fecha_desde'            => 'required|date',
            'fecha_hasta'            => 'required|date|after_or_equal:fecha_desde',
            'id_actividad_economica' => 'nullable|integer',
            'usuario_entregue1'      => 'nullable|string|max:100',
            'usuario_recibi1'        => 'nullable|string|max:100',
            'usuario_entregue2'      => 'nullable|string|max:100',
            'usuario_recibi2'        => 'nullable|string|max:100',
        ]);

        $datos = $this->service->datosParaReporte($validated);

        return response()->json(['success' => true, 'data' => $datos]);
    }

    // ─── Ver detalle ─────────────────────────────────────────────────────────

    /**
     * GET /api/economico/recepcion-ingresos/{id}
     *
     * Recupera una recepción con todos sus detalles.
     */
    public function show(int $id): JsonResponse
    {
        $recepcion = \App\Models\RecepcionIngreso::with('detalles')->find($id);

        if (!$recepcion) {
            return response()->json(['success' => false, 'message' => 'Recepción no encontrada'], 404);
        }

        return response()->json(['success' => true, 'data' => $recepcion]);
    }

    // ─── Anulación ────────────────────────────────────────────────────────────

    /**
     * POST /api/economico/recepcion-ingresos/{id}/anular
     *
     * Anula una recepción de ingresos.
     */
    public function anular(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'motivo' => 'required|string|min:5|max:500',
        ]);

        $ok = $this->service->anular($id, $validated['motivo']);

        if (!$ok) {
            return response()->json([
                'success' => false,
                'message' => 'No se pudo anular la recepción (no existe o ya está anulada)',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Recepción anulada correctamente',
        ]);
    }
}
