<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Numeración RD-[CARRERA]-[MM]-[NNN] para libro diario de ingresos.
 * NNN: correlativo por carrera y mes (se reinicia en 001 cada mes; mínimo 3 dígitos con ceros a la izquierda).
 */
class LibroDiarioIdentificadorHelper
{
    /**
     * Extrae el correlativo numérico final de un código RD-XXX-MM-NNN…
     */
    public static function extraerCorrelativoNumericoDeCodigoRd(?string $codigo): int
    {
        if ($codigo === null || $codigo === '') {
            return 0;
        }
        $codigo = trim($codigo);
        if (preg_match('/RD-[^-]+-(\d{2})-(\d+)$/i', $codigo, $m)) {
            return (int) $m[2];
        }

        return 0;
    }

    /**
     * Formato NNN: al menos 3 dígitos (1→001, 123→123, 1000→1000).
     */
    public static function formatearCorrelativoMin3Digitos(int $n): string
    {
        $n = max(0, $n);

        return str_pad((string) $n, 3, '0', STR_PAD_LEFT);
    }

    public static function construirCodigoRd(string $codigoCarrera, string $mesDosDigitos, int $correlativo): string
    {
        $car = $codigoCarrera !== '' ? $codigoCarrera : 'S/N';
        $mes = preg_match('/^\d{2}$/', $mesDosDigitos) ? $mesDosDigitos : date('m');
        $pad = self::formatearCorrelativoMin3Digitos($correlativo);

        return 'RD-' . $car . '-' . $mes . '-' . $pad;
    }

    /**
     * Mayor correlativo NNN ya usado para la misma carrera y el mismo mes (según codigo_rd guardado).
     * Así cada mes reinicia en 001 (p. ej. RD-EEA-05-001 tras RD-EEA-04-099).
     */
    public static function maxCorrelativoRegistradoParaMes(string $codigoCarrera, string $mesDosDigitos): int
    {
        if (! Schema::hasTable('libro_diario_cierre')) {
            return 0;
        }

        $car = $codigoCarrera !== '' ? $codigoCarrera : 'S/N';
        $mes = preg_match('/^\d{2}$/', $mesDosDigitos) ? $mesDosDigitos : date('m');
        $maxParsed = 0;

        try {
            $pattern = '/^RD-' . preg_quote($car, '/') . '-' . preg_quote($mes, '/') . '-(\d+)$/i';
            $codes = DB::table('libro_diario_cierre')->whereNotNull('codigo_rd')->pluck('codigo_rd');
            foreach ($codes as $c) {
                if (preg_match($pattern, trim((string) $c), $m)) {
                    $maxParsed = max($maxParsed, (int) $m[1]);
                }
            }
        } catch (\Throwable $e) {
        }

        return $maxParsed;
    }

    /**
     * Debe llamarse dentro de una transacción DB; bloquea la tabla para evitar duplicados concurrentes.
     *
     * @return array{correlativo:int, correlativo_padded:string, codigo_rd:string}
     */
    public static function reservarSiguienteIdentificador(string $fechaYmd, ?string $codigoCarrera): array
    {
        DB::table('libro_diario_cierre')->orderByDesc('id')->limit(1)->lockForUpdate()->first();

        $mes = strlen($fechaYmd) >= 10 ? substr($fechaYmd, 5, 2) : date('m');
        $car = $codigoCarrera !== null && $codigoCarrera !== '' ? substr(trim($codigoCarrera), 0, 50) : 'S/N';

        $siguiente = self::maxCorrelativoRegistradoParaMes($car, $mes) + 1;
        $maxIntentos = 500;

        for ($i = 0; $i < $maxIntentos; $i++) {
            $pad = self::formatearCorrelativoMin3Digitos($siguiente);
            $codigoRd = 'RD-' . $car . '-' . $mes . '-' . $pad;

            $exists = DB::table('libro_diario_cierre')->where('codigo_rd', $codigoRd)->exists();
            if (! $exists) {
                return [
                    'correlativo' => $siguiente,
                    'correlativo_padded' => $pad,
                    'codigo_rd' => $codigoRd,
                ];
            }
            $siguiente++;
        }

        throw new \RuntimeException('No se pudo generar un codigo_rd único para libro_diario_cierre');
    }
}
