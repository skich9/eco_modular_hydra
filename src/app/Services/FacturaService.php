<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FacturaService
{
	public function nextFactura(int $anio, int $sucursal, string $puntoVenta): int
	{
		$max = DB::table('factura')
			->where('anio', $anio)
			->where('codigo_sucursal', $sucursal)
			->where('codigo_punto_venta', $puntoVenta)
			->max('nro_factura');
		$next = ((int) $max) + 1;
		Log::info('FacturaService.nextFactura', [ 'anio' => $anio, 'sucursal' => $sucursal, 'pv' => $puntoVenta, 'next' => $next ]);
		return $next;
	}

	public function createComputarizada(int $anio, int $nroFactura, array $data): void
	{
		$payload = array_merge([
			'anio' => $anio,
			'nro_factura' => $nroFactura,
			'tipo' => 'C',
			'estado' => 'VIGENTE',
		], $data);
		DB::table('factura')->insert($payload);
		Log::info('FacturaService.createComputarizada', [ 'anio' => $anio, 'nro_factura' => $nroFactura ]);
	}

	public function createManual(int $anio, int $nroFactura, array $data): void
	{
		$payload = array_merge([
			'anio' => $anio,
			'nro_factura' => $nroFactura,
			'tipo' => 'M',
			'estado' => 'VIGENTE',
		], $data);
		DB::table('factura')->insert($payload);
		Log::info('FacturaService.createManual', [ 'anio' => $anio, 'nro_factura' => $nroFactura ]);
	}

	public function withinCafcRange(int $nroFactura): ?array
	{
		$row = DB::table('sin_cafc')
			->where('num_minimo', '<=', $nroFactura)
			->where('num_maximo', '>=', $nroFactura)
			->first();
		return $row ? (array) $row : null;
	}

	/**
	 * Secuencia atómica por año/sucursal/pv usando doc_counter.
	 */
	public function nextFacturaAtomic(int $anio, int $sucursal, string $puntoVenta): int
	{
		$scope = 'FACTURA:' . $anio . ':' . $sucursal . ':' . $puntoVenta;
		DB::beginTransaction();
		try {
			DB::statement(
				"INSERT INTO doc_counter (scope, last, created_at, updated_at) VALUES (?, 1, NOW(), NOW())\n"
				. "ON DUPLICATE KEY UPDATE last = LAST_INSERT_ID(last + 1), updated_at = NOW()",
				[$scope]
			);
			$row = DB::selectOne('SELECT LAST_INSERT_ID() AS id');
			DB::commit();
			$next = (int) ($row->id ?? 0);
			Log::info('FacturaService.nextFacturaAtomic', [ 'anio' => $anio, 'sucursal' => $sucursal, 'pv' => $puntoVenta, 'next' => $next ]);
			return $next;
		} catch (\Throwable $e) {
			DB::rollBack();
			Log::error('FacturaService.nextFacturaAtomic error', [ 'error' => $e->getMessage() ]);
			throw $e;
		}
	}
}
