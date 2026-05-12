<?php

namespace App\Http\Controllers\Api\Economico;

use App\Http\Controllers\Controller;
use App\Models\CajaActividad;
use App\Models\EgresoCajaFuerte;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EgresoCajaFuerteController extends Controller
{
    public function initial(): JsonResponse
    {
        $cajas = CajaActividad::orderBy('orden')->orderBy('id_caja_actividad')
            ->get(['id_caja_actividad', 'nombre_caja', 'prefijo']);

        $egresos = EgresoCajaFuerte::with('caja:id_caja_actividad,nombre_caja,prefijo')
            ->orderByDesc('fecha_egreso')
            ->orderByDesc('codigo_egreso')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => [
                'cajas'   => $cajas,
                'egresos' => $egresos,
            ],
        ]);
    }

    public function registrar(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'correlativo'       => 'required|string|max:50|unique:egresos_caja_fuerte,correlativo',
            'id_caja_actividad' => 'required|integer|exists:cajas_actividad,id_caja_actividad',
            'fecha_egreso'      => 'required|date',
            'monto'             => 'required|numeric|min:0.01',
            'descripcion'       => 'required|string|max:255',
            'observacion'       => 'nullable|string',
        ]);

        $egreso = EgresoCajaFuerte::create([
            ...$validated,
            'usuario' => Auth::id(),
            'anular'  => false,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Egreso registrado correctamente.',
            'data'    => $egreso->load('caja:id_caja_actividad,nombre_caja,prefijo'),
        ], 201);
    }

    public function editar(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'codigo_egreso'     => 'required|integer|exists:egresos_caja_fuerte,codigo_egreso',
            'correlativo'       => 'required|string|max:50',
            'id_caja_actividad' => 'required|integer|exists:cajas_actividad,id_caja_actividad',
            'fecha_egreso'      => 'required|date',
            'monto'             => 'required|numeric|min:0.01',
            'descripcion'       => 'required|string|max:255',
            'observacion'       => 'nullable|string',
        ]);

        $egreso = EgresoCajaFuerte::findOrFail($validated['codigo_egreso']);

        // Validar unicidad del correlativo excluyendo el registro actual
        $duplicate = EgresoCajaFuerte::where('correlativo', $validated['correlativo'])
            ->where('codigo_egreso', '!=', $egreso->codigo_egreso)
            ->exists();

        if ($duplicate) {
            return response()->json([
                'success' => false,
                'message' => 'El correlativo ya está en uso por otro egreso.',
            ], 422);
        }

        $egreso->update([
            'correlativo'       => $validated['correlativo'],
            'id_caja_actividad' => $validated['id_caja_actividad'],
            'fecha_egreso'      => $validated['fecha_egreso'],
            'monto'             => $validated['monto'],
            'descripcion'       => $validated['descripcion'],
            'observacion'       => $validated['observacion'] ?? null,
            'usuario_modifica'  => Auth::id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Egreso actualizado correctamente.',
            'data'    => $egreso->load('caja:id_caja_actividad,nombre_caja,prefijo'),
        ]);
    }

    public function eliminar(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'codigo_egreso'    => 'required|integer|exists:egresos_caja_fuerte,codigo_egreso',
            'motivo_anulacion' => 'required|string|max:255',
        ]);

        $egreso = EgresoCajaFuerte::findOrFail($validated['codigo_egreso']);
        $egreso->update([
            'anular'            => true,
            'motivo_anulacion'  => $validated['motivo_anulacion'],
            'usuario_modifica'  => Auth::id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Egreso eliminado correctamente.',
        ]);
    }
}
