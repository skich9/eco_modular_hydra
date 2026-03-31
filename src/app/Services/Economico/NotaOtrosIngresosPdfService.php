<?php

namespace App\Services\Economico;

use App\Models\OtroIngreso;
use App\Models\Usuario;
use Carbon\Carbon;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Réplica del flujo SGA tras registrar otros ingresos: nota de reposición (efectivo+recibo),
 * nota depósito o nota tarjeta (PDF) según tipo de pago.
 */
class NotaOtrosIngresosPdfService
{
	/** Medio oficio (SGA mPDF `MEDIAOFICIO`): 612.28×467.72 pt (~216×165 mm). */
	private const PAPER_MEDIO_OFICIO_PT = [0.0, 0.0, 612.28, 467.72];

	/** Sans (Arial) + cuerpo 11pt; interlineado compacto 1.0 en PDF. */
	private const PDF_FONT_STACK = 'Arial, Helvetica, \'DejaVu Sans\', sans-serif';

	/**
	 * @param  array<string,mixed>  $input  Payload original del registro
	 * @return array{message: string, url: ?string}
	 */
	public function procesarTrasRegistro(OtroIngreso $oi, array $input, Usuario $usuario): array
	{
		$out = ['message' => 'exito', 'url' => null];
		$valido = (string) ($oi->valido ?? 'S');
		if ($valido === 'A') {
			return $out;
		}
		$fr = (string) ($oi->factura_recibo ?? 'F');
		if ($fr === '') {
			$fr = 'F';
		}
		if (!in_array($fr, ['R', 'F'], true)) {
			return $out;
		}

		// Mismo valor que el registro: el payload trae el id completo. La columna `code_tipo_pago` llegó a ser VARCHAR(10)
		// y truncaba `id_forma_cobro`; priorizamos el body del request y caemos al modelo si hiciera falta.
		$fcId = trim((string) ($input['tipo_pago'] ?? ''));
		if ($fcId === '') {
			$fcId = trim((string) ($oi->code_tipo_pago ?? ''));
		}
		$nombreFc = '';
		if ($fcId !== '' && Schema::hasTable('formas_cobro')) {
			$rfc = DB::table('formas_cobro')->where('id_forma_cobro', $fcId)->first();
			$nombreFc = $rfc ? (string) ($rfc->nombre ?? '') : '';
		}
		$flujo = OtrosIngresosService::inferirFlujoFormaCobroOtrosIngresos($fcId, $nombreFc);
		$sga = match ($flujo) {
			'efectivo' => 'E',
			'deposito' => 'D',
			'tarjeta' => 'L',
			default => null,
		};
		if ($sga === null) {
			return $out;
		}

		$detalle = $this->armarDetalleAdministrativo($input, $oi);
		$carreraInfo = $this->carreraDesdePensum((string) $oi->cod_pensum);
		$prefijo = $carreraInfo['prefijo'];
		$nombreCarreraUi = trim((string) ($input['nombre_carrera'] ?? ''));
		if ($nombreCarreraUi === '') {
			$nombreCarreraUi = $carreraInfo['nombre'];
		}
		$nombreCarrera = $nombreCarreraUi;
		$anio2 = (int) Carbon::parse($oi->fecha)->format('y');

		$fechaNota = Carbon::now();
		$monto = (float) $oi->monto;
		$razon = (string) ($oi->razon_social ?? '');
		$tipoTexto = (string) ($oi->tipo_ingreso ?? '');
		$obs = (string) ($oi->observaciones ?? '');
		$numDoc = $fr === 'F' ? (string) ($oi->num_factura ?? '') : (string) ($oi->num_recibo ?? '');
		$etiquetaDoc = $fr === 'F' ? 'Factura' : 'Recibo';
		$usuarioNick = $usuario->nickname ?? (string) $usuario->id_usuario;

		$correlativoNum = DB::transaction(function () use ($sga, $usuarioNick, $monto, $detalle, $fechaNota, $razon, $prefijo, $anio2, $numDoc, $tipoTexto, $obs) {
			$n = $this->siguienteCorrelativoDocCounterNotaReposicion();
			if ($sga === 'E') {
				$this->insertarNotaReposicionSiAplica($n, $usuarioNick, $monto, $detalle, $fechaNota, $razon, $prefijo, $anio2, $numDoc, $tipoTexto, $obs);
			}

			return $n;
		});
		$correlativoPadded = str_pad((string) $correlativoNum, 5, '0', STR_PAD_LEFT);
		$correlativoDisplay = $prefijo.'-'.$anio2.'-'.$correlativoPadded;

		$ctaRaw = (string) ($input['cta_banco'] ?? '');
		$bancoNombre = '';
		if (str_contains($ctaRaw, '::')) {
			$bancoNombre = trim(explode('::', $ctaRaw, 2)[0] ?? '');
		} elseif (str_contains($ctaRaw, '-')) {
			$bancoNombre = trim(explode('-', $ctaRaw, 2)[0] ?? '');
		}
		$nroDep = (string) ($input['nro_deposito'] ?? '');
		$fechaDepStr = trim((string) ($input['fecha_deposito'] ?? ''));

		if ($sga === 'E') {
			$html = $this->htmlNotaReposicion($usuarioNick, $correlativoDisplay, $fechaNota, $razon, $tipoTexto, $nombreCarrera, $monto, $detalle, $obs, $etiquetaDoc, $numDoc);
			$url = $this->guardarPdf($correlativoDisplay.'_nota_reposicion', $html);
			if ($url) {
				$out['url'] = $url;
				$out['message'] = 'Reposicion';
			}
		} elseif ($sga === 'D') {
			$html = $this->htmlNotaDepositoTarjeta(
				$usuarioNick,
				$correlativoDisplay,
				$fechaNota,
				$razon,
				$tipoTexto,
				$nombreCarrera,
				$monto,
				$detalle,
				$obs,
				$etiquetaDoc,
				$numDoc,
				'Nota de Deposito - Otros Ingresos',
				$bancoNombre,
				$nroDep,
				$fechaDepStr
			);
			$url = $this->guardarPdf($correlativoDisplay.'_nota_deposito', $html);
			if ($url) {
				$out['url'] = $url;
				$out['message'] = 'Deposito';
			}
		} elseif ($sga === 'L') {
			$html = $this->htmlNotaDepositoTarjeta(
				$usuarioNick,
				$correlativoDisplay,
				$fechaNota,
				$razon,
				$tipoTexto,
				$nombreCarrera,
				$monto,
				$detalle,
				$obs,
				$etiquetaDoc,
				$numDoc,
				'Nota Cobros con Tarjeta - Otros Ingresos',
				$bancoNombre,
				$nroDep,
				$fechaDepStr
			);
			$url = $this->guardarPdf($correlativoDisplay.'_nota_tarjeta', $html);
			if ($url) {
				$out['url'] = $url;
				$out['message'] = 'Tarjeta';
			}
		}

		return $out;
	}

	/**
	 * @return array{prefijo: string, nombre: string}
	 */
	private function carreraDesdePensum(string $codPensum): array
	{
		$prefijo = 'E';
		$nombre = '';
		if ($codPensum === '' || !Schema::hasTable('pensums') || !Schema::hasTable('carreras')) {
			return ['prefijo' => $prefijo, 'nombre' => $nombre];
		}
		$row = DB::table('pensums')
			->leftJoin('carreras', 'pensums.codigo_carrera', '=', 'carreras.codigo_carrera')
			->where('pensums.cod_pensum', $codPensum)
			->select('carreras.codigo_carrera', 'carreras.nombre')
			->first();
		if ($row) {
			$cod = (string) ($row->codigo_carrera ?? '');
			$nombre = (string) ($row->nombre ?? '');
			if ($cod !== '') {
				$prefijo = strtoupper(mb_substr($cod, 0, 1));
			}
		}

		return ['prefijo' => $prefijo, 'nombre' => $nombre];
	}

	/**
	 * Mismo patrón que {@see CobroController}: secuencia atómica para correlativo de nota (tabla `nota_reposicion`).
	 */
	private function siguienteCorrelativoDocCounterNotaReposicion(): int
	{
		if (!Schema::hasTable('doc_counter')) {
			return $this->siguienteCorrelativoFallbackNotaReposicion();
		}
		try {
			DB::statement(
				"INSERT INTO doc_counter (scope, last, created_at, updated_at) VALUES (?, 1, NOW(), NOW())\n"
				.'ON DUPLICATE KEY UPDATE last = LAST_INSERT_ID(last + 1), updated_at = NOW()',
				['NOTA_REPOSICION']
			);
			$rowNr = DB::selectOne('SELECT LAST_INSERT_ID() AS id');
			$n = (int) ($rowNr->id ?? 0);

			return $n > 0 ? $n : 1;
		} catch (\Throwable) {
			return $this->siguienteCorrelativoFallbackNotaReposicion();
		}
	}

	private function siguienteCorrelativoFallbackNotaReposicion(): int
	{
		if (!Schema::hasTable('nota_reposicion')) {
			return 1;
		}
		$max = (int) (DB::table('nota_reposicion')->max('correlativo') ?? 0);

		return $max > 0 ? $max + 1 : 1;
	}

	/**
	 * @param  array<string,mixed>  $input
	 */
	private function armarDetalleAdministrativo(array $input, OtroIngreso $oi): string
	{
		$cod = (string) ($oi->cod_tipo_ingreso ?? '');
		$tipoTexto = (string) ($oi->tipo_ingreso ?? '');
		$fechaIni = $this->parseDmy((string) ($input['fecha_ini'] ?? ''));
		$fechaFin = $this->parseDmy((string) ($input['fecha_fin'] ?? ''));
		$nroOrden = (int) ($input['nro_orden'] ?? 0);
		$conceptoAlq = trim((string) ($input['concepto_alq'] ?? ''));

		if ($cod === 'FOT' || str_contains($tipoTexto, 'fotocop')) {
			return "Entrega ventas Fotocopiadora del {$fechaIni} al {$fechaFin}";
		}
		if ($cod === 'ALQ' || str_contains($tipoTexto, 'Alquiler')) {
			return 'Alquiler mes '.$conceptoAlq;
		}
		if ($cod === 'TDA' || str_contains($tipoTexto, 'tienda')) {
			return "Entrega ventas tienda del {$fechaIni} al {$fechaFin}";
		}
		if ($cod === 'OT') {
			return 'Ingreso Orden de Trabajo Nº '.$nroOrden;
		}

		return $tipoTexto !== '' ? $tipoTexto : 'Otros ingresos';
	}

	private function parseDmy(string $dmy): string
	{
		$dmy = trim($dmy);
		if ($dmy === '') {
			return '';
		}
		try {
			return Carbon::createFromFormat('d/m/Y', $dmy)->format('d/m/Y');
		} catch (\Throwable) {
			return $dmy;
		}
	}

	private function insertarNotaReposicionSiAplica(
		int $correlativoNum,
		string $usuarioNick,
		float $monto,
		string $detalle,
		Carbon $fechaNota,
		string $razon,
		string $prefijo,
		int $anio2,
		string $numRecibo,
		string $tipoIngreso,
		string $observaciones,
	): void {
		if (!Schema::hasTable('nota_reposicion')) {
			return;
		}

		$row = [
			'correlativo' => $correlativoNum,
			'usuario' => $usuarioNick,
			'cod_ceta' => null,
			'monto' => $monto,
			'concepto_adm' => $detalle,
			'fecha_nota' => $fechaNota->format('Y-m-d H:i:s'),
			'concepto_est' => $razon,
			'observaciones' => $observaciones !== '' ? $observaciones : null,
			'prefijo_carrera' => $prefijo,
			'anulado' => false,
			'anio_reposicion' => $anio2,
			'nro_recibo' => $numRecibo !== '' ? $numRecibo : null,
			'tipo_ingreso' => $tipoIngreso !== '' ? $tipoIngreso : null,
		];
		if (Schema::hasColumn('nota_reposicion', 'cont')) {
			$row['cont'] = 2;
		}
		try {
			DB::table('nota_reposicion')->insert($row);
		} catch (\Throwable) {
			// Esquema distinto (PK / columnas): no bloquear el PDF
		}
	}

	private function htmlNotaReposicion(
		string $usuario,
		string $correlativoDisplay,
		Carbon $fechaNota,
		string $razon,
		string $tipoIngreso,
		string $carrera,
		float $monto,
		string $detalle,
		string $obs,
		string $etiquetaDoc,
		string $numDoc,
	): string {
		$fechaTxt = $this->formatoFechaLarga($fechaNota);
		$literal = $this->montoALetras($monto);
		$inst = (string) (config('economico.institucion_nombre') ?: 'Instituto Tecnológico de Enseñanza Automotriz CETA S.R.L.');

		$numDocShow = $numDoc !== '' ? $numDoc : 'S/N';

		return $this->envolverHtml($inst, $carrera, 'Nota de pago en efectivo - Otros Ingresos', $correlativoDisplay, $fechaTxt, '
			<tr>
				<td colspan="2" style="font-size:11pt;color:#000;font-weight:bold;text-align:right;border:1px solid #000;background:#C8C8C8;">
					<label>Nombre:</label>
				</td>
				<td colspan="4" style="font-size:11pt;color:#000;font-weight:normal;text-align:left;border:1px solid #000;">
					<label>'.e($razon).'</label>
				</td>
			</tr>
			<tr>
				<td colspan="2" style="font-size:11pt;color:#000;font-weight:bold;text-align:right;border:1px solid #000;background:#C8C8C8;">
					<label>Otros Ingresos:</label>
				</td>
				<td colspan="4" style="font-size:11pt;color:#000;font-weight:normal;text-align:left;border:1px solid #000;">
					<label>'.e($tipoIngreso).'</label>
				</td>
			</tr>
			<tr>
				<td colspan="2" style="font-size:11pt;color:#0B2161;font-weight:bold;text-align:right;padding:8px 0;">
					<label>MONTO:</label>
				</td>
				<td colspan="4" style="font-size:11pt;color:#000;font-weight:bold;text-align:right;padding:8px 0;">
					<label>'.number_format($monto, 2, '.', '').'</label>
				</td>
			</tr>
			<tr>
				<td colspan="2" style="font-size:11pt;color:#0B2161;font-weight:bold;text-align:right;padding:0 0 8px 0;">
					<label>Literal: </label>
				</td>
				<td colspan="4" style="font-size:11pt;color:#000;text-align:left;padding:0 0 8px 0;">
					<label>'.e($literal).'</label>
				</td>
			</tr>
			<tr>
				<td colspan="2" style="font-size:11pt;color:#0B2161;font-weight:bold;text-align:right;padding:0 0 8px 0;">
					<label>Detalle: </label>
				</td>
				<td colspan="4" style="font-size:11pt;color:#000;text-align:left;padding:0 0 8px 0;">
					<label>'.e($detalle).'</label>
				</td>
			</tr>
			<tr>
				<td colspan="2" style="font-size:11pt;color:#0B2161;font-weight:bold;text-align:right;padding:0 0 8px 0;">
					<label>Observacion: </label>
				</td>
				<td colspan="4" style="font-size:11pt;color:#000;text-align:left;padding:0 0 8px 0;">
					<label>'.e($obs).'</label>
				</td>
			</tr>
			<tr>
				<td colspan="2" style="font-size:11pt;color:#0B2161;font-weight:bold;text-align:right;padding:0 0 8px 0;">
					<label>'.e($etiquetaDoc).': </label>
				</td>
				<td colspan="4" style="font-size:11pt;color:#000;text-align:left;padding:0 0 8px 0;">
					<label>'.e($numDocShow).'</label>
				</td>
			</tr>
			<tr>
				<td style="border-bottom:2px dotted #000;"></td>
				<td style="border-bottom:2px dotted #000;"></td>
				<td style="border-bottom:2px dotted #000;"></td>
				<td style="border-bottom:2px dotted #000;"></td>
				<td style="border-bottom:2px dotted #000;"></td>
				<td width="40%" style="font-size:11pt;color:#000;font-weight:bold;text-align:left;padding:8px 0;border-bottom:2px dotted #000;border-top:1px solid #000;border-left:1px solid #000;">'.e($usuario).' - Firma: </td>
			</tr>
		', $usuario);
	}

	private function htmlNotaDepositoTarjeta(
		string $usuario,
		string $correlativoDisplay,
		Carbon $fechaNota,
		string $razon,
		string $tipoIngreso,
		string $carrera,
		float $monto,
		string $detalle,
		string $obs,
		string $etiquetaDoc,
		string $numDoc,
		string $titulo,
		string $banco,
		string $nroTrans,
		string $fechaDep,
	): string {
		$fechaTxt = $this->formatoFechaLarga($fechaNota);
		$literal = $this->montoALetras($monto);
		$inst = (string) (config('economico.institucion_nombre') ?: 'Instituto Tecnológico de Enseñanza Automotriz CETA S.R.L.');
		$fechaDepShow = $fechaDep;

		return $this->envolverHtml($inst, $carrera, $titulo, $correlativoDisplay, $fechaTxt, '
			<tr>
				<td style="font-size:11pt;border:1px solid #000;background:#C8C8C8;font-weight:bold;padding:6px;width:15%;">Nombre:</td>
				<td style="font-size:11pt;border:1px solid #000;padding:6px;width:35%;">'.e($razon).'</td>
				<td style="font-size:11pt;border:1px solid #000;background:#C8C8C8;font-weight:bold;padding:6px;width:15%;">Banco:</td>
				<td style="font-size:11pt;border:1px solid #000;padding:6px;width:35%;">'.e($banco).'</td>
			</tr>
			<tr>
				<td style="font-size:11pt;border:1px solid #000;background:#C8C8C8;font-weight:bold;padding:6px;">Otros Ingresos:</td>
				<td style="font-size:11pt;border:1px solid #000;padding:6px;">'.e($tipoIngreso).'</td>
				<td style="font-size:11pt;border:1px solid #000;background:#C8C8C8;font-weight:bold;padding:6px;">Fecha:</td>
				<td style="font-size:11pt;border:1px solid #000;padding:6px;">'.e($fechaDepShow).'</td>
			</tr>
			<tr>
				<td style="font-size:11pt;border:1px solid #000;background:#C8C8C8;font-weight:bold;padding:6px;">Nro. Trans:</td>
				<td colspan="3" style="font-size:11pt;border:1px solid #000;padding:6px;">'.e($nroTrans).'</td>
			</tr>
			<tr>
				<td style="font-size:11pt;color:#0B2161;font-weight:bold;text-align:right;padding:8px 0;">MONTO:</td>
				<td colspan="3" style="font-size:11pt;color:#000;font-weight:bold;text-align:right;padding:8px 0;">'.number_format($monto, 2, '.', '').'</td>
			</tr>
			<tr>
				<td style="font-size:11pt;color:#0B2161;font-weight:bold;text-align:right;padding:0 0 8px 0;vertical-align:top;">Literal: </td>
				<td colspan="3" style="font-size:11pt;color:#000;text-align:left;padding:0 0 8px 0;">'.e($literal).'</td>
			</tr>
			<tr>
				<td style="font-size:11pt;color:#0B2161;font-weight:bold;text-align:right;padding:0 0 8px 0;vertical-align:top;">Detalle: </td>
				<td colspan="3" style="font-size:11pt;color:#000;text-align:left;padding:0 0 8px 0;">'.e($detalle).'</td>
			</tr>
			<tr>
				<td style="font-size:11pt;color:#0B2161;font-weight:bold;text-align:right;padding:0 0 8px 0;vertical-align:top;">Observacion: </td>
				<td colspan="3" style="font-size:11pt;color:#000;text-align:left;padding:0 0 8px 0;">'.e($obs).'</td>
			</tr>
			<tr>
				<td style="font-size:11pt;color:#0B2161;font-weight:bold;text-align:right;padding:0 0 8px 0;">'.e($etiquetaDoc).': </td>
				<td colspan="3" style="font-size:11pt;color:#000;text-align:left;padding:0 0 8px 0;">'.e($numDoc !== '' ? $numDoc : 'S/N').'</td>
			</tr>
			<tr>
				<td style="border-bottom:2px dotted #000;"></td>
				<td style="border-bottom:2px dotted #000;"></td>
				<td style="border-bottom:2px dotted #000;"></td>
				<td width="40%" style="font-size:11pt;color:#000;font-weight:bold;text-align:left;padding:8px 0;border-bottom:2px dotted #000;border-top:1px solid #000;border-left:1px solid #000;">'.e($usuario).' - Firma: </td>
			</tr>
		', $usuario);
	}

	/**
	 * Misma plantilla que SGA {@see generar_nota_reposicion_otros_ingresos}: logo, encabezado institucional, pie de página.
	 */
	private function envolverHtml(string $inst, string $carrera, string $tituloDoc, string $nro, string $fechaTxt, string $bodyRows, string $usuario): string
	{
		$tituloEsc = e($tituloDoc);
		$logo = $this->logoHtmlBloque();

		$ff = self::PDF_FONT_STACK;

		return '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="font-family:'.$ff.';font-size:11pt;line-height:1;margin:0;">
		<table width="100%" style="vertical-align:top;border-collapse:collapse;margin-bottom:0;font-family:'.$ff.';font-size:11pt;">
			<tr>
				<td width="20%" rowspan="2" style="text-align:center;vertical-align:top;">'.$logo.'</td>
				<td width="80%" style="text-align:center;border-bottom:2px solid #000;padding:4px 8px 10px 8px;font-family:'.$ff.';">
					<div style="font-size:14pt;color:#000;font-weight:bold;white-space:nowrap;">'.e($inst).'</div>
					<div style="font-size:14pt;color:#000;font-weight:bold;margin-top:4px;">Carrera: '.e($carrera).'</div>
				</td>
			</tr>
			<tr>
				<td style="font-size:11pt;color:#000;font-weight:normal;font-family:'.$ff.';text-align:right;padding:8px 0 12px 0;line-height:1;">
					<span style="font-size:16pt;font-weight:bold;color:#1E2768;line-height:1;"> '.$tituloEsc.' </span>
					<br><br>
					<label>ING-7<br></label>
					<label>Nº '.e($nro).'<br></label>
					<label>'.e($fechaTxt).'<br><br></label>
				</td>
			</tr>
		</table>
		<div style="font-family:'.$ff.';font-size:11pt;line-height:1;">
		<table width="100%" style="border-collapse:collapse;vertical-align:top;font-family:'.$ff.';font-size:11pt;">'.$bodyRows.'
		</table>
		</div>
		<table width="100%" style="border-collapse:collapse;margin-top:8px;font-family:'.$ff.';">
			<tr>
				<td colspan="3" style="text-align:right;color:#000;font-size:9pt;line-height:1;padding:0 0 8px 0;border-top:1px solid #000;padding-top:8px;">
					Solo para fines informativos
					&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
					&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
					&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
					&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
					&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
					&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
					usuario: '.e($usuario).'
				</td>
			</tr>
		</table>
		</body></html>';
	}

	/**
	 * Logo para la nota: config → `frontend/public/images/logo1.png` (por defecto, alineado al SGA), u otros candidatos; si no hay archivo, marcador CETA.
	 */
	private function logoHtmlBloque(): string
	{
		$full = $this->resolverRutaLogoNotaPdf();
		if ($full !== null) {
			$ext = strtolower((string) pathinfo($full, PATHINFO_EXTENSION));
			$mime = match ($ext) {
				'png' => 'image/png',
				'jpg', 'jpeg' => 'image/jpeg',
				'gif' => 'image/gif',
				'webp' => 'image/webp',
				default => 'image/png',
			};
			$data = base64_encode((string) file_get_contents($full));

			return '<img src="data:'.$mime.';base64,'.$data.'" width="110" height="110" alt="" style="display:block;margin:0 auto;object-fit:contain;" />';
		}

		return '<div style="width:110px;height:110px;margin:0 auto;border:2px solid #333;border-radius:50%;text-align:center;padding-top:42px;box-sizing:border-box;font-size:12px;font-weight:bold;">CETA</div>';
	}

	/** Directorio `frontend/public/images` respecto a la raíz del monorepo (hermano de `src/`). */
	private function rutaDirFrontendPublicImages(): string
	{
		return dirname(base_path()).DIRECTORY_SEPARATOR.'frontend'.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'images';
	}

	/**
	 * Prioridad: config → `frontend/public/images/logo1.png` → `public/images/logo1.png` (API sin monorepo) →
	 * `public/images/logo-nota.png` → otros nombres en `frontend/public/images` → primer imagen en esa carpeta.
	 */
	private function resolverRutaLogoNotaPdf(): ?string
	{
		$cfg = trim((string) config('economico.nota_pdf_logo_path', ''));
		if ($cfg !== '') {
			if (is_readable($cfg)) {
				return $cfg;
			}
			$underPublic = public_path($cfg);
			if (is_readable($underPublic)) {
				return $underPublic;
			}
		}

		$frontendImages = $this->rutaDirFrontendPublicImages();
		$logo1Frontend = $frontendImages.DIRECTORY_SEPARATOR.'logo1.png';
		if (is_readable($logo1Frontend)) {
			return $logo1Frontend;
		}

		// Mismo nombre en el `public` del API (en muchos despliegues no existe carpeta `frontend/` junto a `src/`).
		$logo1Public = public_path('images/logo1.png');
		if (is_readable($logo1Public)) {
			return $logo1Public;
		}

		$defPublic = public_path('images/logo-nota.png');
		if (is_readable($defPublic)) {
			return $defPublic;
		}

		if (!is_dir($frontendImages)) {
			return null;
		}
		foreach (['logo-ceta.png', 'logo.png', 'ceta.png', 'logo-ceta.jpg', 'logo.jpg'] as $name) {
			$p = $frontendImages.DIRECTORY_SEPARATOR.$name;
			if (is_readable($p)) {
				return $p;
			}
		}
		foreach (['*.png', '*.jpg', '*.jpeg', '*.gif', '*.webp'] as $pat) {
			$any = glob($frontendImages.DIRECTORY_SEPARATOR.$pat) ?: [];
			if ($any !== []) {
				return $any[0];
			}
		}

		return null;
	}

	private function formatoFechaLarga(Carbon $d): string
	{
		$dias = ['lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];
		$meses = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
		$w = $dias[$d->dayOfWeekIso === 7 ? 6 : $d->dayOfWeekIso - 1] ?? '';
		if ($w !== '') {
			$w = mb_strtoupper(mb_substr($w, 0, 1), 'UTF-8').mb_substr($w, 1);
		}
		$m = $meses[(int) $d->format('n')] ?? $d->format('m');

		return $w.', '.$d->format('d').' de '.$m.' de '.$d->format('Y');
	}

	/** Mismo criterio que SGA {@see num_to_letras} con moneda Bs.: letras en mayúsculas y `00/100 Bs.` */
	private function montoALetras(float $m): string
	{
		$entero = (int) floor($m);
		$cent = (int) round(($m - $entero) * 100);

		return mb_strtoupper(trim($this->enteroALetrasEs($entero)), 'UTF-8').' '.str_pad((string) $cent, 2, '0', STR_PAD_LEFT).'/100 Bs.';
	}

	private function enteroALetrasEs(int $n): string
	{
		if ($n === 0) {
			return 'cero';
		}
		if ($n < 0) {
			return 'menos '.$this->enteroALetrasEs(-$n);
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
				return ($especiales[$n] ?? 'dieci'.$unidades[$u]);
			}
			if ($d === 2 && $u === 0) {
				return 'veinte';
			}
			if ($d === 2) {
				return 'veinti'.$unidades[$u];
			}

			return trim($decenas[$d].($u ? ' y '.$unidades[$u] : ''));
		}
		if ($n < 1000) {
			$c = (int) floor($n / 100);
			$rest = $n % 100;
			$pref = $c === 1 ? 'cien' : ($c === 5 ? 'quinientos' : ($c === 7 ? 'setecientos' : ($c === 9 ? 'novecientos' : $unidades[$c].'cientos')));
			if ($c === 1 && $rest > 0) {
				$pref = 'ciento';
			}

			return trim($pref.($rest ? ' '.$this->enteroALetrasEs($rest) : ''));
		}
		if ($n < 1000000) {
			$mil = (int) floor($n / 1000);
			$rest = $n % 1000;
			$txtMil = $mil === 1 ? 'mil' : $this->enteroALetrasEs($mil).' mil';

			return trim($txtMil.($rest ? ' '.$this->enteroALetrasEs($rest) : ''));
		}

		return number_format($n, 0, '', '');
	}

	private function guardarPdf(string $basename, string $html): ?string
	{
		try {
			$options = new Options();
			$options->set('isRemoteEnabled', false);
			$options->set('defaultFont', 'DejaVu Sans');
			$dompdf = new Dompdf($options);
			$dompdf->loadHtml($html, 'UTF-8');
			$dompdf->setPaper(self::PAPER_MEDIO_OFICIO_PT, 'portrait');
			$dompdf->render();
			$dir = 'notas-otros-ingresos';
			$name = Str::slug($basename, '_').'.pdf';
			$path = $dir.'/'.$name;
			Storage::disk('public')->put($path, $dompdf->output());

			return URL::signedRoute(
				'economico.otros-ingresos.nota-pdf',
				['filename' => $name],
				now()->addMinutes(120),
				absolute: true
			);
		} catch (\Throwable $e) {
			try {
				Log::warning('nota_otros_ingresos_pdf: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);
			} catch (\Throwable) {
			}

			return null;
		}
	}
}
