<?php

namespace App\Services;

use Dompdf\Dompdf;

class LibroDiarioPdfService
{
    /** Misma posición y tamaño que el diseño del Libro Diario (A4 retrato). */
    private const PAGE_NUM_X = 470;

    private const PAGE_NUM_Y = 812;

    private const PAGE_NUM_FONT_PT = 7.5;

    /**
     * Genera un PDF de Libro Diario a partir de HTML ya armado.
     * El pie de página (fecha, tabla Responsable/Recibido/Revisado/Aprobado)
     * se incluye en el HTML con position: fixed para mostrarse en cada hoja.
     * La numeración "Página X de N" se pinta en end_document para que quede por encima
     * de todo el contenido (incluida la última página con totales).
     * Devuelve la ruta absoluta del archivo generado.
     */
    public function generate(string $html, string $usuario, string $fechaCorta, ?string $fechaHoraImp = null): string
    {
        $dompdf = new Dompdf([
            'isRemoteEnabled' => true,
            'isHtml5ParserEnabled' => true,
            'isPhpEnabled' => true,
        ]);
        $dompdf->loadHtml($html, 'UTF-8');

        $dompdf->setPaper('A4', 'portrait');

        $dompdf->setCallbacks([
            [
                'event' => 'end_document',
                'f' => function (int $pageNumber, int $pageCount, $canvas, $fontMetrics): void {
                    $font = $fontMetrics->getFont('DejaVu Sans', 'normal');
                    $label = 'Página ' . $pageNumber . ' de ' . $pageCount;
                    $canvas->text(
                        self::PAGE_NUM_X,
                        self::PAGE_NUM_Y,
                        $label,
                        $font,
                        self::PAGE_NUM_FONT_PT,
                        [0, 0, 0]
                    );
                },
            ],
        ]);

        $dompdf->render();

        $output = $dompdf->output();

        $safeUsuario = preg_replace('/[^A-Za-z0-9_\-]/', '_', $usuario ?: 'usuario');
        $safeFecha = preg_replace('/[^0-9\-]/', '_', $fechaCorta ?: date('Y-m-d'));

        // Guardar dentro de public/reportes/libro_diario para servir directamente
        $dir = public_path('reportes' . DIRECTORY_SEPARATOR . 'libro_diario');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $filename = 'libro_diario_' . $safeUsuario . '_' . $safeFecha . '.pdf';
        $path = $dir . DIRECTORY_SEPARATOR . $filename;

        file_put_contents($path, $output);

        return $path;
    }
}

