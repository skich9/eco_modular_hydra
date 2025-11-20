<?php

namespace App\Services\Siat;

use Carbon\Carbon;

class CufGenerator
{
	public function generate(
		int $nit,
		string $fechaEmision, // fecha de emisión (puede venir en 'Y-m-d H:i:s.u' o 'Y-m-d\TH:i:s.000')
		int $codigoSucursal,
		int $codigoModalidad,
		int $codigoTipoEmision,
		int $codigoTipoFactura,
		int $codigoDocumentoSector,
		int $numeroFactura,
		int $codigoPuntoVenta
	): array {
		$nitStr = $this->leftPad((string) $nit, 13);
		// Normalizar fecha a zona America/La_Paz y construir YmdHis000
		try {
			if (strpos($fechaEmision, 'T') !== false) {
				// Posible formato 'Y-m-d\\TH:i:s.000'
				if (preg_match('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}\\.000$/', $fechaEmision)) {
					$fecha = Carbon::createFromFormat('Y-m-d\\TH:i:s.000', $fechaEmision, 'America/La_Paz');
				} else {
					$fecha = Carbon::parse($fechaEmision, 'America/La_Paz');
				}
			} else {
				// Posible formato 'Y-m-d H:i:s.u'
				if (preg_match('/^\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}\\.\\d+$/', $fechaEmision)) {
					$fecha = Carbon::createFromFormat('Y-m-d H:i:s.u', $fechaEmision, 'America/La_Paz');
				} else {
					$fecha = Carbon::parse($fechaEmision, 'America/La_Paz');
				}
			}
		} catch (\Throwable $e) {
			$fecha = Carbon::now('America/La_Paz');
		}
		$baseFecha = $fecha->format('YmdHis');
		$fechaStr = $baseFecha . '000'; // 17

		$sucursalStr = $this->leftPad((string) $codigoSucursal, 4);
		$modalidadStr = $this->leftPad((string) $codigoModalidad, 1);
		$tipoEmisionStr = $this->leftPad((string) $codigoTipoEmision, 1);
		$tipoFacturaStr = $this->leftPad((string) $codigoTipoFactura, 1);
		$docSectorStr = $this->leftPad((string) $codigoDocumentoSector, 2);
		$nroFactStr = $this->leftPad((string) $numeroFactura, 10);
		$pvStr = $this->leftPad((string) $codigoPuntoVenta, 4);

		$cadena53 = $nitStr . $fechaStr . $sucursalStr . $modalidadStr . $tipoEmisionStr . $tipoFacturaStr . $docSectorStr . $nroFactStr . $pvStr;
		$dv = $this->mod11($cadena53);
		$cadena54 = $cadena53 . (string) $dv; // 54 dígitos con DV
		// Estándar SIAT/SGA: HEX de los 54 dígitos (incluye DV)
		$cufHex = $this->decStringToHex($cadena54);

		return [
			'cuf' => strtoupper($cufHex),
			'dv' => $dv,
			'decimal' => $cadena54,
			'componentes' => [
				'nit' => $nitStr,
				'fecha' => $fechaStr,
				'sucursal' => $sucursalStr,
				'modalidad' => $modalidadStr,
				'tipo_emision' => $tipoEmisionStr,
				'tipo_factura' => $tipoFacturaStr,
				'doc_sector' => $docSectorStr,
				'numero_factura' => $nroFactStr,
				'punto_venta' => $pvStr,
			],
		];
	}

	private function leftPad(string $val, int $len): string
	{
		$val = preg_replace('/\D/', '', $val) ?? '';
		if (strlen($val) > $len) {
			$val = substr($val, -$len);
		}
		return str_pad($val, $len, '0', STR_PAD_LEFT);
	}

	private function mod11(string $digits): int
	{
		$weights = [2,3,4,5,6,7,8,9];
		$sum = 0;
		$wIdx = 0;
		for ($i = strlen($digits) - 1; $i >= 0; $i--) {
			$digit = (int) $digits[$i];
			$sum += $digit * $weights[$wIdx];
			$wIdx = ($wIdx + 1) % count($weights);
		}
		$mod = $sum % 11;
		$dv = $mod;
		if ($dv === 10) return 1;
		if ($dv === 11) return 0;
		return $dv;
	}

	private function decStringToHex(string $decimal): string
	{
		$hex = '';
		$digits = $decimal;
		$map = '0123456789ABCDEF';
		while ($digits !== '' && $digits !== '0') {
			$carry = 0;
			$out = '';
			$len = strlen($digits);
			for ($i = 0; $i < $len; $i++) {
				$cur = $carry * 10 + (int) $digits[$i];
				$quot = intdiv($cur, 16);
				$carry = $cur % 16;
				if (!($out === '' && $quot === 0)) {
					$out .= (string) $quot;
				}
			}
			$hex = $map[$carry] . $hex;
			$digits = $out === '' ? '0' : $out;
		}
		return $hex === '' ? '0' : $hex;
	}
}
