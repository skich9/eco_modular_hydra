<?php

namespace App\Services;

use Dompdf\Dompdf;

class LibroDiarioPdfService
{
    /** 10 mm (SGA mgl/mgr) — mismo criterio que @page 10mm en ReporteLibroDiarioController. */
    private const MARGIN_H_MM = 10.0;

    /**
     * Desde el borde inferior de la hoja (letter) a la baseline del texto, en línea con la fila "Fecha y Hora" / pie SGA
     * (mPDF mgb 45 mm de zona inferior; fino: 30–36 pt suele alinear con DejaVu 9pt bold).
     */
    private const PAGE_NUM_BASELINE_FROM_BOTTOM_PT = 32.0;

    /**
     * Genera un PDF de Libro Diario a partir de HTML ya armado.
     * El pie (fecha, firmas) va en el HTML; "Página X de N" se pinta vía end_document
     * con el mismo criterio que .libro-footer (DejaVu Sans bold 9pt, #000066), alineado
     * a la derecha con la fila "Fecha y Hora de Impresión" (1 cm de margen).
     */
    /**
     * @param  string|null  $sufijoCarrera  p. ej. EEA, MEA — evita que dos PDFs el mismo día pisen el mismo archivo.
     */
    public function generate(string $html, string $usuario, string $fechaCorta, ?string $fechaHoraImp = null, ?string $sufijoCarrera = null): string
    {
        $dompdf = new Dompdf([
            'isRemoteEnabled' => true,
            'isHtml5ParserEnabled' => true,
            'isPhpEnabled' => true,
        ]);
        $dompdf->loadHtml($html, 'UTF-8');

        $dompdf->setPaper('letter', 'portrait');

        $colorPie = [0.0, 0.0, 102 / 255];

        $dompdf->setCallbacks([
            [
                'event' => 'end_document',
                'f' => function (int $pageNumber, int $pageCount, $canvas, $fontMetrics) use ($colorPie): void {
                    $text = 'Página ' . $pageNumber . ' de ' . $pageCount;
                    $font = $fontMetrics->getFont('DejaVu Sans', 'bold');
                    $size = 9.0;
                    $w = $fontMetrics->getTextWidth($text, $font, $size);
                    $pageW = $canvas->get_width();
                    $pageH = $canvas->get_height();
                    $marginHpt = self::MARGIN_H_MM * 2.834645669;
                    $x = $pageW - $marginHpt - $w;
                    $y = $pageH - self::PAGE_NUM_BASELINE_FROM_BOTTOM_PT;
                    $canvas->text($x, $y, $text, $font, $size, $colorPie);
                },
            ],
        ]);

        $dompdf->render();

        $output = $dompdf->output();

        $safeUsuario = preg_replace('/[^A-Za-z0-9_\-]/', '_', $usuario ?: 'usuario');
        $safeFecha = preg_replace('/[^0-9\-]/', '_', $fechaCorta ?: date('Y-m-d'));

        $dir = public_path('reportes' . DIRECTORY_SEPARATOR . 'libro_diario');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $sufCarr = '';
        if ($sufijoCarrera !== null && trim($sufijoCarrera) !== '') {
            $sufCarr = '_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', strtoupper(trim($sufijoCarrera)));
        }
        $filename = 'libro_diario_' . $safeUsuario . '_' . $safeFecha . $sufCarr . '.pdf';
        $path = $dir . DIRECTORY_SEPARATOR . $filename;

        file_put_contents($path, $output);

        return $path;
    }
}
