<?php

namespace App\Services\Economico;

/**
 * Literal de montos en bolivianos, alineado a SGA {@see num_to_letras}.
 */
final class BolivianosALetras
{
    public static function monto(float $m): string
    {
        $entero = (int) floor($m);
        $cent = (int) round(($m - $entero) * 100);

        return mb_strtoupper(trim(self::enteroALetrasEs($entero)), 'UTF-8')
            . ' '
            . str_pad((string) $cent, 2, '0', STR_PAD_LEFT)
            . '/100 Bs.';
    }

    private static function enteroALetrasEs(int $n): string
    {
        if ($n === 0) {
            return 'cero';
        }
        if ($n < 0) {
            return 'menos ' . self::enteroALetrasEs(-$n);
        }
        $unidades = ['', 'uno', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve'];
        $decenas = ['', '', 'veinte', 'treinta', 'cuarenta', 'cincuenta', 'sesenta', 'setenta', 'ochenta', 'noventa'];
        $especiales = [10 => 'diez', 11 => 'once', 12 => 'doce', 13 => 'trece', 14 => 'catorce', 15 => 'quince'];
        if ($n < 10) {
            return $unidades[$n];
        }
        if ($n < 16 && isset($especiales[$n])) {
            return $especiales[$n];
        }
        if ($n < 100) {
            $d = (int) floor($n / 10);
            $u = $n % 10;
            if ($d === 1) {
                return $especiales[$n] ?? 'dieci' . $unidades[$u];
            }
            if ($d === 2 && $u === 0) {
                return 'veinte';
            }
            if ($d === 2) {
                return 'veinti' . $unidades[$u];
            }

            return trim($decenas[$d] . ($u ? ' y ' . $unidades[$u] : ''));
        }
        if ($n < 1000) {
            $c = (int) floor($n / 100);
            $rest = $n % 100;
            $pref = $c === 1 ? 'cien' : ($c === 5 ? 'quinientos' : ($c === 7 ? 'setecientos' : ($c === 9 ? 'novecientos' : $unidades[$c] . 'cientos')));
            if ($c === 1 && $rest > 0) {
                $pref = 'ciento';
            }

            return trim($pref . ($rest ? ' ' . self::enteroALetrasEs($rest) : ''));
        }
        if ($n < 1_000_000) {
            $mil = (int) floor($n / 1000);
            $rest = $n % 1000;
            $txtMil = $mil === 1 ? 'mil' : self::enteroALetrasEs($mil) . ' mil';

            return trim($txtMil . ($rest ? ' ' . self::enteroALetrasEs($rest) : ''));
        }

        return number_format($n, 0, '', '');
    }
}
