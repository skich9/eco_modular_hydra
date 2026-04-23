<?php

namespace App\Services;

/**
 * Logo del encabezado en PDFs Dompdf (libro diario, recepción ingresos, etc.): una sola fuente.
 * Mismo criterio que ReporteLibroDiario: `public/img/logo.png`, embebido en base64.
 *
 * @return array{html: string, width: int, height: int, padding: int}
 */
class DompdfInstitucionLogoHelper
{
    public static function logoParaEncabezadoDompdf(int $logoPad = 2): array
    {
        $logoW = 48;
        $logoH = 48;
        $html = '';
        $logoPath = public_path('img' . DIRECTORY_SEPARATOR . 'logo.png');
        if (is_file($logoPath) && is_readable($logoPath)) {
            $info = @getimagesize($logoPath);
            if ($info && isset($info[0], $info[1])) {
                $logoW = min(56, max(36, (int) $info[0]));
                $logoH = min(56, max(36, (int) $info[1]));
            }
            $logoB64 = base64_encode((string) file_get_contents($logoPath));
            $html = '<img src="data:image/png;base64,' . $logoB64 . '" width="' . $logoW . '" height="' . $logoH
                . '" style="display:block;margin:0 auto;vertical-align:middle;border:0;outline:none;" alt="Logo" />';
        }

        return [
            'html' => $html,
            'width' => $logoW,
            'height' => $logoH,
            'padding' => $logoPad,
        ];
    }
}
