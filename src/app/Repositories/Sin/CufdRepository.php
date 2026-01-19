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

	// public function getVigenteOrCreate($puntoVenta = 0, $forceNew = false)
	// {
    //     Log::info('CufdRepository.getVigenteOrCreate: puntoVenta='.$puntoVenta.', forceNew='.($forceNew ? 'true' : 'false'));
	// 	$sucursal = (int) config('sin.sucursal');
	// 	// Asegurar CUIS vigente
	// 	$cuisData = $this->cuisRepo->getVigenteOrCreate($puntoVenta);
	// 	$cuis = $cuisData['codigo_cuis'];

	// 	// Si forceNew es true, siempre solicitar uno nuevo al SIN
	// 	if (!$forceNew) {
	// 		$nowLaPaz = Carbon::now('America/La_Paz');
	// 		// Log similar al SGA para rastrear consulta de CUFD

    //         /********************************************************************************************************************/
    //         /**************************** AQUI SE INGRESA PARA REALIZAS LA MODIFICACION *****************************************/
    //         /********************************************************************************************************************/
	// 		Log::debug('SGA-LIKE CUFD SELECT', [
	// 			'sql' => "SELECT codigo_cufd, codigo_control, direccion, fecha_vigencia, codigo_cuis, codigo_punto_venta, codigo_sucursal, diferencia_tiempo FROM sin_cufd WHERE codigo_cuis='".$cuis."' AND codigo_punto_venta='".$puntoVenta."' AND codigo_sucursal='".$sucursal."' AND fecha_vigencia > NOW() ORDER BY fecha_vigencia DESC LIMIT 1",
	// 		]);
	// 		$row = DB::table('sin_cufd')
	// 			->where('codigo_cuis', $cuis)
	// 			->where('codigo_punto_venta', (string) $puntoVenta)
	// 			->where('codigo_sucursal', $sucursal)
	// 			->where('fecha_vigencia', '>', $nowLaPaz)
	// 			->orderByDesc('fecha_vigencia')
	// 			->first();

	// 		if ($row) {
	// 			return (array) $row;
	// 		}
	// 	} else {
	// 		Log::warning('CufdRepository: forceNew=true, solicitando CUFD fresco al SIN');
	// 	}

	// 	// Solicitar CUFD al SIAT
	// 	$resp = $this->codes->cufd($cuis, $puntoVenta);
	// 	if (!isset($resp['RespuestaCufd'])) {
	// 		throw new Exception('Respuesta CUFD inválida: ' . json_encode($resp));
	// 	}
	// 	$rc = $resp['RespuestaCufd'];
	// 	$codigo = isset($rc['codigo']) ? $rc['codigo'] : null;
	// 	$codigoControl = isset($rc['codigoControl']) ? $rc['codigoControl'] : null;
	// 	$direccion = isset($rc['direccion']) ? $rc['direccion'] : null;
	// 	$fechaVig = isset($rc['fechaVigencia']) ? $rc['fechaVigencia'] : null;
	// 	if (!$codigo || !$codigoControl || !$direccion || !$fechaVig) {
	// 		$mensaje = (isset($rc['mensajesList']) && isset($rc['mensajesList']['descripcion'])) ? $rc['mensajesList']['descripcion'] : 'sin mensaje';
	// 		throw new Exception('Error CUFD: ' . $mensaje);
	// 	}

	// 	$fechaVigencia = $this->normalizeDate($fechaVig);
	// 	list($fechaInicio, $fechaFin) = $this->calculateRange($fechaVigencia);
	// 	$diferencia = $this->diferenciaTiempo($fechaVigencia);

	// 	$data = [
	// 		'codigo_cufd' => (string) $codigo,
	// 		'codigo_control' => (string) $codigoControl,
	// 		'direccion' => (string) $direccion,
	// 		'fecha_vigencia' => $fechaVigencia,
	// 		'codigo_cuis' => (string) $cuis,
	// 		'codigo_punto_venta' => (string) $puntoVenta,
	// 		'codigo_sucursal' => $sucursal,
	// 		'diferencia_tiempo' => $diferencia,
	// 		'fecha_inicio' => $fechaInicio,
	// 		'fecha_fin' => $fechaFin,
	// 	];

	// 	// Log creación CUFD similar al SGA
	// 	Log::debug('SGA-LIKE Create CUFD', $data);

	// 	// Cerrar el CUFD anterior si se superpone
	// 	$this->closePreviousIfOverlap($puntoVenta, $sucursal, $fechaInicio);

	// 	DB::table('sin_cufd')->insert($data);
	// 	return $data;
	// }

	private function normalizeDate($fecha)
    {
        $normalized = str_replace('T', ' ', str_replace('-04:00', '', $fecha));
        return date('Y-m-d H:i:s', strtotime($normalized));
    }

    private function calculateRange($fechaVigencia)
    {
        $tsVig = strtotime($fechaVigencia);
        $tsInicio = strtotime('-1 day', $tsVig);
        $fechaInicio = date('Y-m-d H:i:s', $tsInicio);
        $fechaFin = date('Y-m-d H:i:s', $tsVig);
        return [$fechaInicio, $fechaFin];
    }

    private function diferenciaTiempo($fechaVigencia)
    {
        $tsVig = strtotime($fechaVigencia) - 86400; // menos 24h como en SGA
        return round($tsVig - microtime(true), 3);
    }

    private function closePreviousIfOverlap($puntoVenta, $sucursal, $fechaInicio)
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

    public function getVigenteOrCreate2($codigoAmbiente,$codigoSucursal,$puntoVenta, $forceNew = false)
	{
        Log::info('CufdRepository.getVigenteOrCreate2: codigoAmbiente='.$codigoAmbiente.', codigoSucursal='.$codigoSucursal.', puntoVenta='.$puntoVenta.', forceNew='.($forceNew ? 'true' : 'false'));
		// Asegurar CUIS vigente
		$cuisData = $this->cuisRepo->getVigenteOrCreate2($codigoAmbiente,$codigoSucursal,$puntoVenta);
		$cuis = $cuisData['codigo_cuis'];

        // if (!$forceNew) {
		// 	$nowLaPaz = Carbon::now('America/La_Paz');
		// 	// Log similar al SGA para rastrear consulta de CUFD
		// 	Log::debug('SGA-LIKE CUFD SELECT', [
		// 		'sql' => "SELECT codigo_cufd, codigo_control, direccion, fecha_vigencia, codigo_cuis, codigo_punto_venta, codigo_sucursal, diferencia_tiempo FROM sin_cufd WHERE codigo_cuis='".$cuis."' AND codigo_punto_venta='".$puntoVenta."' AND codigo_sucursal='".$sucursal."' AND fecha_vigencia > NOW() ORDER BY fecha_vigencia DESC LIMIT 1",
		// 	]);
		// 	$row = DB::table('sin_cufd')
		// 		->where('codigo_cuis', $cuis)
		// 		->where('codigo_punto_venta', (string) $puntoVenta)
		// 		->where('codigo_sucursal', $codigoSucursal)
		// 		->where('fecha_vigencia', '>', $nowLaPaz)
		// 		->orderByDesc('fecha_vigencia')
		// 		->first();

		// 	if ($row) {
		// 		return (array) $row;
		// 	}
		// } else {
		// 	Log::warning('CufdRepository: forceNew=true, solicitando CUFD fresco al SIN');
		// }
        $nowLaPaz = Carbon::now('America/La_Paz');

        // primero se debe recuperar de la base de datos si existe un CUFD vigente
        $row = DB::table('sin_cufd')
            ->where('codigo_ambiente', $codigoAmbiente)
            ->where('codigo_sucursal', $codigoSucursal)
            ->where('codigo_punto_venta', (string) $puntoVenta)
            ->where('codigo_cuis', $cuis)
            ->where('fecha_vigencia', '>', $nowLaPaz)
            ->orderByDesc('fecha_vigencia')
            ->first();

        if ($row) {
            return (array) $row;
        }

        Log::debug('Esta llamando a la ejecucion del cufd2 del servicio de codesService');
		// Solicitar CUFD al SIAT
		$resp = $this->codes->cufd2($codigoAmbiente,$codigoSucursal,$cuis, $puntoVenta);
		if (!isset($resp['RespuestaCufd'])) {
			throw new Exception('Respuesta CUFD inválida: ' . json_encode($resp));
		}



		$rc = $resp['RespuestaCufd'];
		$codigo = isset($rc['codigo']) ? $rc['codigo'] : null;
		$codigoControl = isset($rc['codigoControl']) ? $rc['codigoControl'] : null;
		$direccion = isset($rc['direccion']) ? $rc['direccion'] : null;
		$fechaVig = isset($rc['fechaVigencia']) ? $rc['fechaVigencia'] : null;
		if (!$codigo || !$codigoControl || !$direccion || !$fechaVig) {
			$mensaje = (isset($rc['mensajesList']) && isset($rc['mensajesList']['descripcion'])) ? $rc['mensajesList']['descripcion'] : 'sin mensaje';
			throw new Exception('Error CUFD: ' . $mensaje);
		}

		$fechaVigencia = $this->normalizeDate($fechaVig);
		list($fechaInicio, $fechaFin) = $this->calculateRange($fechaVigencia);
		$diferencia = $this->diferenciaTiempo($fechaVigencia);

		$data = [
			'codigo_cufd' => (string) $codigo,
			'codigo_control' => (string) $codigoControl,
			'direccion' => (string) $direccion,
			'fecha_vigencia' => $fechaVigencia,
			'codigo_cuis' => (string) $cuis,
			'codigo_punto_venta' => (string) $puntoVenta,
			'codigo_sucursal' => $codigoSucursal,
			'diferencia_tiempo' => $diferencia,
			'fecha_inicio' => $fechaInicio,
			'fecha_fin' => $fechaFin,
		];

		// Log creación CUFD similar al SGA
		Log::debug('SGA-LIKE Create CUFD', $data);

		// Cerrar el CUFD anterior si se superpone
		$this->closePreviousIfOverlap($puntoVenta, $codigoSucursal, $fechaInicio);

		DB::table('sin_cufd')->insert($data);
		return $data;
	}
}
