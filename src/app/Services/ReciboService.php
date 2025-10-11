<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReciboService
{
	public function nextRecibo(int $anio): int
	{
		$max = DB::table('recibo')->where('anio', $anio)->max('nro_recibo');
		$next = ((int) $max) + 1;
		Log::info('ReciboService.nextRecibo', [ 'anio' => $anio, 'next' => $next ]);
		return $next;
	}

	/**
	 * Secuencia atómica por año usando doc_counter.
	 * Evita carreras concurrentes al asignar números de recibo.
	 */
	public function nextReciboAtomic(int $anio): int
	{
		$scope = 'RECIBO:' . $anio;
		DB::beginTransaction();
		try {
			// Insertar scope si no existe, o incrementar y devolver con LAST_INSERT_ID
			DB::statement(
				"INSERT INTO doc_counter (scope, last, created_at, updated_at) VALUES (?, 1, NOW(), NOW())\n"
				. "ON DUPLICATE KEY UPDATE last = LAST_INSERT_ID(last + 1), updated_at = NOW()",
				[$scope]
			);
			$row = DB::selectOne('SELECT LAST_INSERT_ID() AS id');
			DB::commit();
			$next = (int) ($row->id ?? 0);
			Log::info('ReciboService.nextReciboAtomic', [ 'anio' => $anio, 'next' => $next ]);
			return $next;
		} catch (\Throwable $e) {
			DB::rollBack();
			Log::error('ReciboService.nextReciboAtomic error', [ 'error' => $e->getMessage() ]);
			throw $e;
		}
	}

	public function exists(int $anio, int $nroRecibo): bool
	{
		return DB::table('recibo')
			->where('anio', $anio)
			->where('nro_recibo', $nroRecibo)
			->exists();
	}

	public function create(int $anio, int $nroRecibo, array $data): void
	{
		$payload = array_merge([
			'anio' => $anio,
			'nro_recibo' => $nroRecibo,
			'estado' => 'VIGENTE',
			'codigo_doc_sector' => null,
		], $data);
		DB::table('recibo')->insert($payload);
		Log::info('ReciboService.create', [ 'anio' => $anio, 'nro_recibo' => $nroRecibo, 'monto_total' => $payload['monto_total'] ?? null ]);
	}
}
