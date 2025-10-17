<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Models\Rezagado;

class RezagadoController extends Controller
{
    public function index(Request $request)
    {
        try {
            $q = Rezagado::query();
            if ($request->filled('cod_inscrip')) {
                $q->where('cod_inscrip', (int)$request->cod_inscrip);
            }
            if ($request->filled('num_rezagado')) {
                $q->where('num_rezagado', (int)$request->num_rezagado);
            }
            $rows = $q->orderBy('cod_inscrip')->orderBy('num_rezagado')->orderBy('num_pago_rezagado')->get();
            return response()->json([
                'success' => true,
                'data' => $rows,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al listar rezagados',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'cod_inscrip' => 'required|integer',
            'num_rezagado' => 'required|integer',
            'num_pago_rezagado' => 'required|integer',
            'num_factura' => 'nullable|integer',
            'num_recibo' => 'nullable|integer',
            'fecha_pago' => 'required|date',
            'monto' => 'required|numeric',
            'pago_completo' => 'required|boolean',
            'observaciones' => 'nullable|string|max:150',
            'usuario' => 'required|integer',
            'materia' => 'nullable|string|max:255',
            'parcial' => 'nullable|string|max:1',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors' => $validator->errors(),
            ], 422);
        }
        try {
            $data = $validator->validated();
            $created = Rezagado::create($data);
            return response()->json([
                'success' => true,
                'message' => 'Rezagado creado',
                'data' => $created,
            ], 201);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear rezagado',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show($cod_inscrip, $num_rezagado, $num_pago_rezagado)
    {
        try {
            $row = Rezagado::where('cod_inscrip', (int)$cod_inscrip)
                ->where('num_rezagado', (int)$num_rezagado)
                ->where('num_pago_rezagado', (int)$num_pago_rezagado)
                ->first();
            if (!$row) {
                return response()->json([
                    'success' => false,
                    'message' => 'Rezagado no encontrado'
                ], 404);
            }
            return response()->json([
                'success' => true,
                'data' => $row,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener rezagado',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request, $cod_inscrip, $num_rezagado, $num_pago_rezagado)
    {
        try {
            $row = Rezagado::where('cod_inscrip', (int)$cod_inscrip)
                ->where('num_rezagado', (int)$num_rezagado)
                ->where('num_pago_rezagado', (int)$num_pago_rezagado)
                ->first();
            if (!$row) {
                return response()->json([
                    'success' => false,
                    'message' => 'Rezagado no encontrado'
                ], 404);
            }
            $validator = Validator::make($request->all(), [
                'num_factura' => 'nullable|integer',
                'num_recibo' => 'nullable|integer',
                'fecha_pago' => 'sometimes|date',
                'monto' => 'sometimes|numeric',
                'pago_completo' => 'sometimes|boolean',
                'observaciones' => 'nullable|string|max:150',
                'usuario' => 'sometimes|integer',
                'materia' => 'nullable|string|max:255',
                'parcial' => 'nullable|string|max:1',
            ]);
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error de validación',
                    'errors' => $validator->errors(),
                ], 422);
            }
            $row->update($validator->validated());
            return response()->json([
                'success' => true,
                'message' => 'Rezagado actualizado',
                'data' => $row->fresh(),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar rezagado',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy($cod_inscrip, $num_rezagado, $num_pago_rezagado)
    {
        try {
            $row = Rezagado::where('cod_inscrip', (int)$cod_inscrip)
                ->where('num_rezagado', (int)$num_rezagado)
                ->where('num_pago_rezagado', (int)$num_pago_rezagado)
                ->first();
            if (!$row) {
                return response()->json([
                    'success' => false,
                    'message' => 'Rezagado no encontrado'
                ], 404);
            }
            $row->delete();
            return response()->json([
                'success' => true,
                'message' => 'Rezagado eliminado'
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar rezagado',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
