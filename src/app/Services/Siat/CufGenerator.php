<?php

namespace App\Services\Siat;

use Carbon\Carbon;

class CufGenerator
{
	public function generate(
		int $nit,
		string $fechaEmision, // ISO date string
		int $codigoSucursal,
		int $codigoModalidad,
		int $codigoTipoEmision,
		int $codigoTipoFactura,
		int $codigoDocumentoSector,
		int $numeroFactura,
		int $codigoPuntoVenta
	): array {
		$nitStr = $this->leftPad((string) $nit, 13);
		$fecha = Carbon::parse($fechaEmision);
		$baseFecha = $fecha->format('YmdHis');
		$millis = (int) floor(((int) $fecha->format('u')) / 1000);
		$fechaStr = $baseFecha . str_pad((string)$millis, 3, '0', STR_PAD_LEFT); // 17
		$fechaStr = substr($fechaStr, 0, 17);

		$sucursalStr = $this->leftPad((string) $codigoSucursal, 4);
		$modalidadStr = $this->leftPad((string) $codigoModalidad, 1);
		$tipoEmisionStr = $this->leftPad((string) $codigoTipoEmision, 1);
		$tipoFacturaStr = $this->leftPad((string) $codigoTipoFactura, 1);
		$docSectorStr = $this->leftPad((string) $codigoDocumentoSector, 2);
		$nroFactStr = $this->leftPad((string) $numeroFactura, 10);
		$pvStr = $this->leftPad((string) $codigoPuntoVenta, 4);

		$cadena53 = $nitStr . $fechaStr . $sucursalStr . $modalidadStr . $tipoEmisionStr . $tipoFacturaStr . $docSectorStr . $nroFactStr . $pvStr;
		$dv = $this->mod11($cadena53);
		$cadena54 = $cadena53 . (string) $dv;
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
		$dv = 11 - $mod;
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
