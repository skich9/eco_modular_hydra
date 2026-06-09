<?php

namespace App\Services\Economico;

use App\Services\DompdfInstitucionLogoHelper;
use Carbon\Carbon;
use Illuminate\Support\Facades\View;
use Mpdf\Mpdf;

class ReporteCajaFuertePdfService
{
    private const TZ = 'America/La_Paz';

    public function generar(array $datos, string $codDocumento, string $usuario): string
    {
        $ahora = Carbon::now(self::TZ);
        Carbon::setLocale('es');
        $logoHtml = DompdfInstitucionLogoHelper::logoParaMpdf('1.5cm', '1.5cm');
        $caja     = $datos['caja'];

        $headerHtml = $this->renderHeader($caja, $codDocumento, $logoHtml);
        $footerHtml = $this->renderFooter($usuario, $ahora->format('d/m/Y'));
        $bodyHtml   = View::make('pdf.reporte_caja_fuerte', [
            'caja'                   => $caja,
            'mes'                    => $datos['mes'],
            'saldo_anterior'         => $datos['saldo_anterior'],
            'es_mes_futuro'          => $datos['es_mes_futuro'],
            'fecha_fin_mes_anterior' => $datos['fecha_fin_mes_anterior'],
            'movimientos'            => $datos['movimientos'],
            'total_ingresos'         => $datos['total_ingresos'],
            'total_egresos'          => $datos['total_egresos'],
            'saldo_final'            => $datos['saldo_final'],
        ])->render();

        $mpdf = new Mpdf([
            'mode'            => 'utf-8',
            'format'          => 'Letter',
            'margin_left'     => 8,
            'margin_right'    => 8,
            'margin_top'      => 26,  // espacio reservado para el header repetido
            'margin_bottom'   => 42,  // espacio reservado para el footer repetido
            'margin_header'   => 5,
            'margin_footer'   => 5,
        ]);

        $mpdf->SetHTMLHeader($headerHtml);
        $mpdf->SetHTMLFooter($footerHtml);

        $css = '
            * { box-sizing: border-box; }
            body { font-family: Arial, sans-serif; font-size: 9pt; color: #000; margin: 0; padding: 0; }
            .data-table { width: 100%; border-collapse: collapse; margin-top: 4px; font-family: sans-serif; font-size: 12px; }
            .data-table th { border: 1px solid #000066; padding: 3px 5px; font-size: 12px; background-color: #d9edf7; text-align: center; font-weight: bold; color: #000066; }
            .data-table td { border-width: 1px; border-color: red; border-style: dotted; padding: 3px 5px; font-size: 12px; }
            .col-num    { width: 5%;  text-align: center; }
            .col-trans  { width: 15%; white-space: nowrap; }
            .col-fecha  { width: 12%; text-align: center; white-space: nowrap; }
            .col-ref    { width: 40%; }
            .col-monto  { width: 12%; text-align: right; }
            .text-right  { text-align: right; }
            .text-center { text-align: center; }
            .row-saldo-ant td { font-weight: bold; }
            .row-subtotales td { border-width: 1px; border-color: red; border-style: dotted; font-weight: bold; background-color: #e8e8e8; }
            .row-saldo-mes td { border-width: 1px; border-color: red; border-style: dotted; font-weight: bold; background-color: #d6e4f7; }
        ';

        $mpdf->WriteHTML($css, \Mpdf\HTMLParserMode::HEADER_CSS);
        $mpdf->WriteHTML($bodyHtml, \Mpdf\HTMLParserMode::HTML_BODY);

        $dir = public_path('reportes' . DIRECTORY_SEPARATOR . 'caja-fuerte');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $safeUsuario = preg_replace('/[^A-Za-z0-9_\-]/', '_', $usuario ?: 'usuario');
        $safeCod     = preg_replace('/[^A-Za-z0-9_\-]/', '_', $codDocumento);
        $filename    = 'reporte_cf_' . $safeCod . '_' . $safeUsuario . '.pdf';
        $path        = $dir . DIRECTORY_SEPARATOR . $filename;

        $mpdf->Output($path, 'F');

        return url('reportes/caja-fuerte/' . $filename);
    }

    private function renderHeader(object $caja, string $codDocumento, string $logoHtml): string
    {
        $inst       = config('app.institucion', 'Instituto Tecnológico de Enseñanza Automotriz "CETA"');
        $nombreCaja = strtoupper($caja->nombre_caja);
        $b          = 'border-width:1px; border-color:#000066; border-style:solid;';
        $cell       = 'style="' . $b . ' color:#000066; font-weight:bold; vertical-align:middle; padding:2px 4px;"';

        return '
        <table width="100%" style="border-collapse:collapse; font-family:Arial,sans-serif; font-size:9pt;">
          <tr>
            <td rowspan="3" style="' . $b . ' width:60px; text-align:center; vertical-align:middle; padding:2px;">
              ' . $logoHtml . '
            </td>
            <td colspan="9" ' . $cell . ' style="' . $b . ' text-align:center; font-size:10pt; color:#000066; font-weight:bold; vertical-align:middle; padding:2px 4px;">' . $inst . '</td>
            <td ' . $cell . ' style="' . $b . ' text-align:right; font-size:8pt; color:#000066; font-weight:bold; white-space:nowrap; padding:2px 4px;">Nº:</td>
            <td ' . $cell . ' style="' . $b . ' text-align:center; font-size:8pt; color:#000066; font-weight:bold; white-space:nowrap; padding:2px 4px;">' . e($codDocumento) . '</td>
          </tr>
          <tr>
            <td rowspan="2" colspan="9" style="' . $b . ' text-align:center; font-size:13pt; color:#000066; font-weight:bold; vertical-align:middle; padding:2px 4px;">
              REPORTE DE INGRESOS DE ' . $nombreCaja . '
            </td>
            <td ' . $cell . ' style="' . $b . ' text-align:right; font-size:8pt; color:#000066; font-weight:bold; white-space:nowrap; padding:2px 4px;">Codigo:</td>
            <td ' . $cell . ' style="' . $b . ' text-align:center; font-size:8pt; color:#000066; font-weight:bold; white-space:nowrap; padding:2px 4px;">CJAF - 4</td>
          </tr>
          <tr>
            <td ' . $cell . ' style="' . $b . ' text-align:right; font-size:8pt; color:#000066; font-weight:bold; white-space:nowrap; padding:2px 4px;">Versión :</td>
            <td ' . $cell . ' style="' . $b . ' text-align:center; font-size:8pt; color:#000066; font-weight:bold; white-space:nowrap; padding:2px 4px;">V.0.</td>
          </tr>
        </table>';
    }

    private function renderFooter(string $usuario, string $fechaCorta): string
    {
        $bs  = 'border-width:1px; border-color:#cc0000; border-style:solid;';
        $btu = 'border-width:1px 1px 0 1px; border-color:#cc0000; border-style:solid;';
        $btb = 'border-width:0 1px 1px 1px; border-color:#cc0000; border-style:solid;';
        $hd  = 'font-weight:bold; text-align:center; background-color:#fff0f0;';

        return '
        <table width="100%" style="border-collapse:collapse; font-family:Arial,sans-serif; font-size:8pt;">
          <tr>
            <td width="10%" style="border:none;"></td>
            <td width="23%" style="' . $bs . $hd . '">RESPONSABLE</td>
            <td width="23%" style="' . $bs . $hd . '">REVISADO</td>
            <td width="22%" style="' . $bs . $hd . '">APROBADO</td>
          </tr>
          <tr>
            <td width="10%" style="' . $bs . ' text-align:right; font-weight:bold;">Firma:</td>
            <td width="23%" style="' . $bs . '" height="35px"></td>
            <td width="23%" style="' . $bs . '" height="35px"></td>
            <td width="22%" style="' . $bs . '" height="35px"></td>
          </tr>
          <tr>
            <td width="10%" style="' . $bs . ' text-align:right; font-weight:bold;">Nombre:</td>
            <td width="23%" style="' . $bs . ' text-align:center;">' . e($usuario) . '</td>
            <td width="23%" style="' . $bs . ' text-align:center;">Contabilidad</td>
            <td width="22%" style="' . $btu . ' text-align:center;">DFC</td>
          </tr>
          <tr>
            <td width="10%" style="' . $bs . ' text-align:right; font-weight:bold;">Fecha:</td>
            <td width="23%" style="' . $bs . ' text-align:center;">' . $fechaCorta . '</td>
            <td width="23%" style="' . $bs . '"></td>
            <td width="22%" style="' . $btb . '"></td>
          </tr>
        </table>
        <table width="100%" style="font-family:Arial,sans-serif; font-size:8pt; margin-top:2px;">
          <tr>
            <td style="text-align:right; color:#000066; font-weight:bold; white-space:nowrap;">Página {PAGENO} de {nb}</td>
          </tr>
        </table>';
    }
}
