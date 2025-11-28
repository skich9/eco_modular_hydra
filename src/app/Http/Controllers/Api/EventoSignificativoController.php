<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EventoSignificativoController extends Controller
{
	/**
	 * Lista eventos significativos registrados
	 * 
	 * GET /api/eventos-significativos
	 */
	public function lista(Request $request)
	{
		try {
			$query = DB::table('sin_evento_significativo')
				->orderBy('fecha_inicio', 'desc');

			$sucursal = $request->query('sucursal');
			$puntoVenta = $request->query('punto_venta');

			if ($sucursal !== null) {
				$query->where('codigo_sucursal', $sucursal);
			}
			if ($puntoVenta !== null) {
				$query->where('codigo_punto_venta', $puntoVenta);
			}

			$eventos = $query->get();

			return response()->json([
				'success' => true,
				'eventos' => $eventos
			]);

		} catch (\Throwable $e) {
			Log::error('EventoSignificativoController.lista', [
				'error' => $e->getMessage()
			]);

			return response()->json([
				'success' => false,
				'message' => $e->getMessage()
			], 500);
		}
	}

	/**
	 * Registra un nuevo evento significativo
	 * 
	 * POST /api/eventos-significativos
	 */
	public function crear(Request $request)
	{
		try {
			$data = [
				'codigo_recepcion' => $request->input('codigo_recepcion'),
				'fecha_inicio' => $request->input('fecha_inicio'),
				'fecha_fin' => $request->input('fecha_fin'),
				'codigo_evento' => $request->input('codigo_evento'),
				'descripcion_evento' => $request->input('descripcion_evento'),
				'codigo_sucursal' => $request->input('codigo_sucursal'),
				'codigo_punto_venta' => $request->input('codigo_punto_venta'),
				'observaciones' => $request->input('observaciones'),
				'created_at' => now(),
				'updated_at' => now()
			];

			$id = DB::table('sin_evento_significativo')->insertGetId($data);

			Log::info('EventoSignificativoController.crear', [
				'id_evento' => $id,
				'codigo_recepcion' => $data['codigo_recepcion']
			]);

			return response()->json([
				'success' => true,
				'id_evento' => $id,
				'mensaje' => 'Evento significativo registrado correctamente'
			]);

		} catch (\Throwable $e) {
			Log::error('EventoSignificativoController.crear', [
				'error' => $e->getMessage()
			]);

			return response()->json([
				'success' => false,
				'message' => $e->getMessage()
			], 500);
		}
	}

	/**
	 * Busca evento significativo para una fecha específica
	 * 
	 * GET /api/eventos-significativos/buscar?fecha=2025-11-26&sucursal=0&punto_venta=0
	 */
	public function buscar(Request $request)
	{
		try {
			$fecha = $request->query('fecha');
			$sucursal = $request->query('sucursal');
			$puntoVenta = $request->query('punto_venta');

			if (!$fecha) {
				return response()->json([
					'success' => false,
					'message' => 'Fecha requerida'
				], 400);
			}

			$evento = DB::table('sin_evento_significativo')
				->where('fecha_inicio', '<=', $fecha)
				->where('fecha_fin', '>=', $fecha)
				->where('codigo_sucursal', $sucursal)
				->where('codigo_punto_venta', $puntoVenta)
				->first();

			return response()->json([
				'success' => true,
				'evento' => $evento,
				'tiene_evento' => $evento !== null
			]);

		} catch (\Throwable $e) {
			Log::error('EventoSignificativoController.buscar', [
				'error' => $e->getMessage()
			]);

			return response()->json([
				'success' => false,
				'message' => $e->getMessage()
			], 500);
		}
	}
}
