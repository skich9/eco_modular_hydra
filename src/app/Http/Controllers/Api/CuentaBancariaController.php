<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CuentaBancaria;
use Illuminate\Http\Request;

class CuentaBancariaController extends Controller
{
    /**
     * Listado de cuentas bancarias habilitadas
     */
    public function index(Request $request)
    {
        try {
            $onlyEnabled = filter_var($request->query('only_enabled', 'true'), FILTER_VALIDATE_BOOLEAN);
            $q = CuentaBancaria::query();
            if ($onlyEnabled) {
                $q->where('estado', true);
            }
            $cuentas = $q->orderBy('banco')
                ->get(['id_cuentas_bancarias','banco','numero_cuenta','tipo_cuenta','habilitado_QR','estado']);

            return response()->json([
                'success' => true,
                'data' => $cuentas,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener cuentas bancarias: ' . $e->getMessage(),
            ], 500);
        }
    }
}
