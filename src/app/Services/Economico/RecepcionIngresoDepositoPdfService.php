<?php

namespace App\Services\Economico;

use Dompdf\Dompdf;

/**
 * Generación de PDF para «Recepción de ingresos diarios para depósitos» (form. ING-4).
 */
class RecepcionIngresoDepositoPdfService
{
    public function generate(string $html, string $slug, string $fechaCorta): string
    {
        $dompdf = new Dompdf([
            'isRemoteEnabled' => true,
            'isHtml5ParserEnabled' => true,
            'isPhpEnabled' => true,
        ]);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');

        $dompdf->render();
        $output = $dompdf->output();

        $safeSlug = preg_replace('/[^A-Za-z0-9_\-]/', '_', $slug ?: 'recepcion');
        $safeFecha = preg_replace('/[^0-9\-]/', '_', $fechaCorta ?: date('Y-m-d'));

        $dir = public_path('reportes' . DIRECTORY_SEPARATOR . 'recepcion_ingreso_deposito');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $filename = 'recepcion_ingreso_' . $safeSlug . '_' . $safeFecha . '.pdf';
        $path = $dir . DIRECTORY_SEPARATOR . $filename;
        file_put_contents($path, $output);

        return $path;
    }
}
