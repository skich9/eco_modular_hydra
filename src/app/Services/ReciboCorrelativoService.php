<?php

namespace App\Services;

use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Correlativo numérico de 8 cifras para recibos nuevos: YY + MM + secuencia (4 dígitos, ceros a la izquierda).
 * Ej.: abril 2026, secuencia 46 → 26040046.
 *
 * Los valores históricos cortos (p. ej. 46) se mantienen; no entran en el rango mensual y no compiten.
 */
final class ReciboCorrelativoService
{
	private const TZ = 'America/La_Paz';

	public function prefijoYymmDesdeFecha(DateTimeInterface|string $fecha): int
	{
		$c = $this->carbonDesdeFecha($fecha);

		return ((int) $c->format('y')) * 100 + (int) $c->format('n');
	}

	/**
	 * @return array{0:int,1:int} [min inclusive, max inclusive]
	 */
	public function rangoParaPrefijo(int $prefijoYymm): array
	{
		$base = $prefijoYymm * 10_000;

		return [$base + 1, $base + 9999];
	}

	/**
	 * Siguiente correlativo en transacción (uso en altas). Evita duplicados concurrentes.
	 */
	public function siguienteCorrelativoExtendidoAtomico(DateTimeInterface|string $fecha): int
	{
		$prefijo = $this->prefijoYymmDesdeFecha($fecha);
		[$min, $max] = $this->rangoParaPrefijo($prefijo);
		$scope = 'REC_YXMM:' . $prefijo;

		return (int) DB::transaction(function () use ($scope, $min, $max, $prefijo) {
			DB::table('doc_counter')->insertOrIgnore([
				'scope' => $scope,
				'last' => 0,
				'created_at' => now(),
				'updated_at' => now(),
			]);

			$row = DB::table('doc_counter')->where('scope', $scope)->lockForUpdate()->first();
			$maxDb = $this->maxCorrelativoEnRango($min, $max);
			$counterLast = (int) ($row->last ?? 0);
			$next = max($min, $maxDb + 1, $counterLast + 1);
			if ($next > $max) {
				Log::error('ReciboCorrelativoService: agotado correlativo mensual', [
					'prefijo_yymm' => $prefijo,
					'min' => $min,
					'max' => $max,
				]);
				throw new \RuntimeException('Correlativo de recibo agotado para el mes (máx. 9999 por período).');
			}

			DB::table('doc_counter')->where('scope', $scope)->update([
				'last' => $next,
				'updated_at' => now(),
			]);

			Log::info('ReciboCorrelativoService.siguienteCorrelativoExtendidoAtomico', [
				'prefijo_yymm' => $prefijo,
				'next' => $next,
			]);

			return $next;
		});
	}

	/**
	 * Vista previa del siguiente número (sin consumir); para formularios / initialData.
	 */
	public function vistaPreviaSiguienteExtendido(?DateTimeInterface $fecha = null): int
	{
		$ref = $fecha ?? Carbon::now(self::TZ);
		$prefijo = $this->prefijoYymmDesdeFecha($ref);
		[$min, $max] = $this->rangoParaPrefijo($prefijo);
		$scope = 'REC_YXMM:' . $prefijo;
		$row = DB::table('doc_counter')->where('scope', $scope)->first();
		$counterLast = (int) ($row->last ?? 0);
		$maxDb = $this->maxCorrelativoEnRango($min, $max);

		return max($min, $maxDb + 1, $counterLast + 1);
	}

	private function carbonDesdeFecha(DateTimeInterface|string $fecha): Carbon
	{
		if ($fecha instanceof DateTimeInterface) {
			return Carbon::instance($fecha)->timezone(self::TZ);
		}
		$s = trim((string) $fecha);
		if ($s === '') {
			return Carbon::now(self::TZ);
		}

		return Carbon::parse($s, self::TZ)->timezone(self::TZ);
	}

	private function maxCorrelativoEnRango(int $min, int $max): int
	{
		$m = 0;
		$tablas = [
			['recibo', 'nro_recibo'],
			['cobro', 'nro_recibo'],
			['otros_ingresos', 'num_recibo'],
			['segunda_instancia', 'num_recibo'],
			['rezagados', 'num_recibo'],
		];
		foreach ($tablas as [$tabla, $col]) {
			if (! Schema::hasTable($tabla) || ! Schema::hasColumn($tabla, $col)) {
				continue;
			}
			$v = (int) (DB::table($tabla)->whereBetween($col, [$min, $max])->max($col) ?? 0);
			$m = max($m, $v);
		}

		return $m;
	}
}
