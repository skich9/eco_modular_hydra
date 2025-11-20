<?php

namespace App\Repositories\Sin;

use App\Services\Siat\CodesService;
use Illuminate\Support\Facades\DB;
use Exception;
use Carbon\Carbon;

class CuisRepository
{
	public function __construct(private CodesService $codes) {}

	public function getVigenteOrCreate(int $puntoVenta = 0): array
	{
		$sucursal = (int) config('sin.sucursal');

		$nowLaPaz = Carbon::now('America/La_Paz');
		$row = DB::table('sin_cuis')
			->where('codigo_punto_venta', (string) $puntoVenta)
			->where('codigo_sucursal', $sucursal)
			->where('fecha_vigencia', '>', $nowLaPaz)
			->orderByDesc('fecha_vigencia')
			->first();

		if ($row) {
			return (array) $row;
		}

		// Solicitar CUIS al SIAT
		$resp = $this->codes->cuis($puntoVenta);
		if (!isset($resp['RespuestaCuis'])) {
			throw new Exception('Respuesta CUIS inválida: ' . json_encode($resp));
		}
		$rc = $resp['RespuestaCuis'];
		$codigo = $rc['codigo'] ?? null;
		$fechaVig = $rc['fechaVigencia'] ?? null;
		if (!$codigo || !$fechaVig) {
			$mensaje = $rc['mensaje'] ?? ($rc['mensajesList']['descripcion'] ?? 'sin mensaje');
			throw new Exception('Error CUIS: ' . $mensaje);
		}

		$data = [
			'codigo_cuis' => (string) $codigo,
			'fecha_vigencia' => $this->toTimestamp($fechaVig),
			'codigo_sucursal' => $sucursal,
			'codigo_punto_venta' => (string) $puntoVenta,
		];

		// upsert por codigo_cuis
		$exists = DB::table('sin_cuis')->where('codigo_cuis', $data['codigo_cuis'])->exists();
		if ($exists) {
			DB::table('sin_cuis')
				->where('codigo_cuis', $data['codigo_cuis'])
				->update(['fecha_vigencia' => $data['fecha_vigencia']]);
		} else {
			DB::table('sin_cuis')->insert($data);
		}

		return $data;
	}

	private function toTimestamp(string $fechaVigencia): string
	{
		// Normaliza formatos con o sin zona -04:00 y con T
		$normalized = str_replace('T', ' ', str_replace('-04:00', '', $fechaVigencia));
		return date('Y-m-d H:i:s', strtotime($normalized));
	}
}
