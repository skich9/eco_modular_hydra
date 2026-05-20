<?php

namespace App\Services;

use Mpdf\Mpdf;

class LibroDiarioPdfService
{
    /**
     * Genera un PDF de Libro Diario a partir del HTML completo armado por el controlador.
     * Extrae header, footer y body del HTML para usar mPDF SetHTMLHeader/SetHTMLFooter.
     */
    public function generate(string $html, string $usuario, string $fechaCorta, ?string $fechaHoraImp = null, ?string $sufijoCarrera = null): string
    {
        // --- Extraer partes del HTML ---
        preg_match('/<style[^>]*>(.*?)<\/style>/si', $html, $cssMatch);
        $rawCss = $cssMatch[1] ?? '';
        // Eliminar @page (mPDF usa parámetros del constructor para márgenes)
        $css = preg_replace('/@page\s*\{[^}]*\}/s', '', $rawCss);

        preg_match('/<header[^>]*class="libro-header"[^>]*>(.*?)<\/header>/si', $html, $hdrMatch);
        $headerHtml = $hdrMatch[1] ?? '';

        preg_match('/<footer[^>]*class="libro-footer"[^>]*>(.*?)<\/footer>/si', $html, $ftrMatch);
        $footerHtml = $ftrMatch[1] ?? '';

        preg_match('/<main[^>]*>(.*?)<\/main>/si', $html, $mainMatch);
        $bodyHtml = $mainMatch[1] ?? $html;

        // --- Instanciar mPDF ---
        $mpdf = new Mpdf([
            'mode'          => 'utf-8',
            'format'        => 'Letter',
            'margin_left'   => 10,
            'margin_right'  => 10,
            'margin_top'    => 46,
            'margin_bottom' => 45,
            'margin_header' => 4,
            'margin_footer' => 4,
        ]);

        $mpdf->SetHTMLHeader($headerHtml);
        $mpdf->SetHTMLFooter($footerHtml);

        $mpdf->WriteHTML($css, \Mpdf\HTMLParserMode::HEADER_CSS);
        $mpdf->WriteHTML($bodyHtml, \Mpdf\HTMLParserMode::HTML_BODY);

        // --- Guardar en disco ---
        $safeUsuario = preg_replace('/[^A-Za-z0-9_\-]/', '_', $usuario ?: 'usuario');
        $safeFecha   = preg_replace('/[^0-9\-]/', '_', $fechaCorta ?: date('Y-m-d'));

        $dir = public_path('reportes' . DIRECTORY_SEPARATOR . 'libro_diario');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $sufCarr = '';
        if ($sufijoCarrera !== null && trim($sufijoCarrera) !== '') {
            $sufCarr = '_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', strtoupper(trim($sufijoCarrera)));
        }
        $filename = 'libro_diario_' . $safeUsuario . '_' . $safeFecha . $sufCarr . '.pdf';
        $path     = $dir . DIRECTORY_SEPARATOR . $filename;

        $mpdf->Output($path, 'F');

        return $path;
    }
}
