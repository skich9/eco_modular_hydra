<?php

namespace App\Repositories\Sin;

use App\Services\Siat\CodesService;
use Illuminate\Support\Facades\DB;
use Exception;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class CufdRepository
{
	/** @var CodesService */
	private $codes;
	/** @var CuisRepository */
	private $cuisRepo;

	public function __construct(CodesService $codes, CuisRepository $cuisRepo)
	{
		$this->codes = $codes;
		$this->cuisRepo = $cuisRepo;
	}

	public function getVigenteOrCreate(int $puntoVenta = 0): array
	{
		$sucursal = (int) config('sin.sucursal');
		// Asegurar CUIS vigente
		$cuisData = $this->cuisRepo->getVigenteOrCreate($puntoVenta);
		$cuis = $cuisData['codigo_cuis'];

		$nowLaPaz = Carbon::now('America/La_Paz');
		// Log similar al SGA para rastrear consulta de CUFD vigente
		Log::error('SGA-LIKE CUFD SELECT', [
			'sql' => "SELECT codigo_cufd, codigo_control, direccion, fecha_vigencia, codigo_cuis, codigo_punto_venta, codigo_sucursal, diferencia_tiempo FROM sin_cufd WHERE codigo_cuis='".$cuis."' AND codigo_punto_venta='".$puntoVenta."' AND codigo_sucursal='".$sucursal."' AND fecha_vigencia > NOW() ORDER BY fecha_vigencia DESC LIMIT 1",
		]);
		$row = DB::table('sin_cufd')
			->where('codigo_cuis', $cuis)
			->where('codigo_punto_venta', (string) $puntoVenta)
			->where('codigo_sucursal', $sucursal)
			->where('fecha_vigencia', '>', $nowLaPaz)
			->orderByDesc('fecha_vigencia')
			->first();

		if ($row) {
			return (array) $row;
		}

		// Solicitar CUFD al SIAT
		$resp = $this->codes->cufd($cuis, $puntoVenta);
		if (!isset($resp['RespuestaCufd'])) {
			throw new Exception('Respuesta CUFD inválida: ' . json_encode($resp));
		}
		$rc = $resp['RespuestaCufd'];
		$codigo = $rc['codigo'] ?? null;
		$codigoControl = $rc['codigoControl'] ?? null;
		$direccion = $rc['direccion'] ?? null;
		$fechaVig = $rc['fechaVigencia'] ?? null;
		if (!$codigo || !$codigoControl || !$direccion || !$fechaVig) {
			$mensaje = $rc['mensajesList']['descripcion'] ?? 'sin mensaje';
			throw new Exception('Error CUFD: ' . $mensaje);
		}

		$fechaVigencia = $this->normalizeDate($fechaVig);
		[$fechaInicio, $fechaFin] = $this->calculateRange($fechaVigencia);
		$diferencia = $this->diferenciaTiempo($fechaVigencia);

		$data = [
			'codigo_cufd' => (string) $codigo,
			'codigo_control' => (string) $codigoControl,
			'direccion' => (string) $direccion,
			'fecha_vigencia' => $fechaVigencia,
			'codigo_cuis' => (string) $cuis,
			'codigo_punto_venta' => (string) $puntoVenta,
			'codigo_sucursal' => $sucursal,
			'diferencia_tiempo' => $diferencia,
			'fecha_inicio' => $fechaInicio,
			'fecha_fin' => $fechaFin,
		];

		// Log creación CUFD similar al SGA
		Log::error('SGA-LIKE Create CUFD', $data);

		// Cerrar el CUFD anterior si se superpone
		$this->closePreviousIfOverlap($puntoVenta, $sucursal, $fechaInicio);

		DB::table('sin_cufd')->insert($data);
		return $data;
	}

	private function normalizeDate(string $fecha): string
	{
		$normalized = str_replace('T', ' ', str_replace('-04:00', '', $fecha));
		return date('Y-m-d H:i:s', strtotime($normalized));
	}

	private function calculateRange(string $fechaVigencia): array
	{
		$tsVig = strtotime($fechaVigencia);
		$tsInicio = strtotime('-1 day', $tsVig);
		$fechaInicio = date('Y-m-d H:i:s', $tsInicio);
		$fechaFin = date('Y-m-d H:i:s', $tsVig);
		return [$fechaInicio, $fechaFin];
	}

	private function diferenciaTiempo(string $fechaVigencia): float
	{
		$tsVig = strtotime($fechaVigencia) - 86400; // menos 24h como en SGA
		return round($tsVig - microtime(true), 3);
	}

	private function closePreviousIfOverlap(int $puntoVenta, int $sucursal, string $fechaInicio): void
	{
		$ultimo = DB::table('sin_cufd')
			->where('codigo_punto_venta', (string) $puntoVenta)
			->where('codigo_sucursal', $sucursal)
			->orderByDesc('fecha_vigencia')
			->first();
		if ($ultimo && $ultimo->fecha_vigencia > $fechaInicio) {
			DB::table('sin_cufd')
				->where('codigo_cufd', $ultimo->codigo_cufd)
				->update(['fecha_fin' => $fechaInicio]);
		}
	}
}
