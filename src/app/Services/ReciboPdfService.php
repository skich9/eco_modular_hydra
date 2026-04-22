<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Dompdf\Dompdf;
use App\Services\NotaTraspasoPdfService;

class ReciboPdfService
{
    private function numToLiteral(float $monto): string
    {
        $entero = (int) floor($monto);
        $centavos = (int) round(($monto - $entero) * 100);

        $u = ['', 'uno', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve', 'diez', 'once', 'doce', 'trece', 'catorce', 'quince', 'dieciséis', 'diecisiete', 'dieciocho', 'diecinueve'];
        $d = ['', 'diez', 'veinte', 'treinta', 'cuarenta', 'cincuenta', 'sesenta', 'setenta', 'ochenta', 'noventa'];
        $c = ['', 'cien', 'doscientos', 'trescientos', 'cuatrocientos', 'quinientos', 'seiscientos', 'setecientos', 'ochocientos', 'novecientos'];

        $toWords2 = function (int $n) use ($u, $d): string {
            if ($n < 20) return $u[$n];
            $tens = intdiv($n, 10); $ones = $n % 10;
            if ($tens === 2 && $ones > 0) return 'veinti' . $u[$ones];
            $sep = $ones ? ' y ' : '';
            return $d[$tens] . ($sep ? $sep . $u[$ones] : '');
        };

        $toWords3 = function (int $n) use ($c, $toWords2): string {
            if ($n === 0) return '';
            if ($n === 100) return 'cien';
            $hund = intdiv($n, 100); $rest = $n % 100;
            $pref = $hund ? ($hund === 1 ? 'ciento' : $c[$hund]) : '';
            $mid = $rest ? ($pref ? ' ' : '') . $toWords2($rest) : '';
            return trim($pref . $mid);
        };

        $toWords = function (int $n) use ($toWords3): string {
            if ($n === 0) return 'cero';
            $parts = [];
            $millones = intdiv($n, 1000000); $n %= 1000000;
            $miles = intdiv($n, 1000); $n %= 1000;
            if ($millones) $parts[] = ($millones === 1 ? 'un millón' : $toWords3($millones) . ' millones');
            if ($miles) $parts[] = ($miles === 1 ? 'mil' : $toWords3($miles) . ' mil');
            if ($n) $parts[] = $toWords3($n);
            return trim(implode(' ', $parts));
        };

        $literal = strtoupper($toWords($entero)) . ' ' . str_pad((string)$centavos, 2, '0', STR_PAD_LEFT) . '/100 Bs.';
        return $literal;
    }

	private function mapMesFromCuota(?string $gestion, ?int $numeroCuota): ?string
	{
		if (!$numeroCuota || $numeroCuota <= 0) { return null; }
		$gestion = trim((string) $gestion);
		$meses = [];
		if (strpos($gestion, '1/') === 0) {
			$meses = [1 => 'Febrero', 2 => 'Marzo', 3 => 'Abril', 4 => 'Mayo', 5 => 'Junio'];
		} elseif (strpos($gestion, '2/') === 0) {
			$meses = [1 => 'Julio', 2 => 'Agosto', 3 => 'Septiembre', 4 => 'Octubre', 5 => 'Noviembre'];
		}
		return $meses[$numeroCuota] ?? null;
	}

	private function extractMesFromText(?string $text): ?string
	{
		$text = strtoupper(trim((string) $text));
		if ($text === '') { return null; }
		$map = [
			'ENERO' => 'Enero',
			'FEBRERO' => 'Febrero',
			'FEB' => 'Febrero',
			'FEB.' => 'Febrero',
			'FEBR' => 'Febrero',
			'FEBR.' => 'Febrero',
			'MARZO' => 'Marzo',
			'MAR' => 'Marzo',
			'MAR.' => 'Marzo',
			'ABRIL' => 'Abril',
			'ABR' => 'Abril',
			'ABR.' => 'Abril',
			'MAYO' => 'Mayo',
			'MAY' => 'Mayo',
			'MAY.' => 'Mayo',
			'JUNIO' => 'Junio',
			'JUN' => 'Junio',
			'JUN.' => 'Junio',
			'JULIO' => 'Julio',
			'JUL' => 'Julio',
			'JUL.' => 'Julio',
			'AGOSTO' => 'Agosto',
			'AGO' => 'Agosto',
			'AGO.' => 'Agosto',
			'SEPTIEMBRE' => 'Septiembre',
			'SEP' => 'Septiembre',
			'SEP.' => 'Septiembre',
			'SETIEMBRE' => 'Septiembre',
			'OCTUBRE' => 'Octubre',
			'OCT' => 'Octubre',
			'OCT.' => 'Octubre',
			'NOVIEMBRE' => 'Noviembre',
			'NOV' => 'Noviembre',
			'NOV.' => 'Noviembre',
			'DICIEMBRE' => 'Diciembre',
			'DIC' => 'Diciembre',
			'DIC.' => 'Diciembre',
		];
		foreach ($map as $k => $v) {
			if (preg_match('/\\b' . preg_quote($k, '/') . '\\b/i', $text)) { return $v; }
		}
		return null;
	}

	private function resolveCarreraNombre(?string $codPensum): string
	{
		$codPensum = trim((string) $codPensum);
		if ($codPensum === '') { return ''; }
		try {
			$row = DB::table('pensums')
				->leftJoin('carrera', 'carrera.codigo_carrera', '=', 'pensums.codigo_carrera')
				->where('pensums.cod_pensum', $codPensum)
				->select([
					'carrera.nombre as carrera_nombre',
					'pensums.nombre as pensum_nombre'
				])
				->first();
			if ($row) {
				$nom = trim((string) ($row->carrera_nombre ?? ''));
				if ($nom !== '') { return $nom; }
				$nom = trim((string) ($row->pensum_nombre ?? ''));
				if ($nom !== '') { return $nom; }
			}
		} catch (\Throwable $e) {
			return '';
		}
		return '';
	}

	private function buildDetalleConMontos(array $cobros): string
	{
		$lines = [];
		$gestion = null;
		try {
			if (isset($cobros[0]) && isset($cobros[0]->gestion)) {
				$gestion = (string) $cobros[0]->gestion;
			}
		} catch (\Throwable $e) { $gestion = null; }

		foreach ($cobros as $c) {
			$c = (object) $c;
			$monto = (float) ($c->monto ?? 0);
			$montoFmt = number_format($monto, 2, '.', '');
			$obs = trim((string) ($c->observaciones ?? ''));
			$isArr = stripos($obs, 'ARRASTRE') !== false;
			$codTipoCobro = strtoupper(trim((string) ($c->cod_tipo_cobro ?? '')));

			if ($obs !== '' && preg_match('/\[\s*REZAGADO\s*\]\s*(.+)$/i', $obs, $m)) {
				$desc = trim((string) $m[1]);
				if ($desc !== '') { $lines[] = $desc . ' Bs' . $montoFmt; }
				continue;
			}

			if (!empty($c->id_item)) {
				try {
					$it = DB::table('items_cobro')->where('id_item', $c->id_item)->first();
					$desc = ($it && isset($it->nombre_servicio)) ? (string) $it->nombre_servicio : ('Item ' . $c->id_item);
				} catch (\Throwable $e) {
					$desc = 'Item ' . $c->id_item;
				}
				$desc = trim((string) $desc);
				if ($desc !== '') { $lines[] = $desc . ' Bs' . $montoFmt; }
				continue;
			}

			$numCuota = null;
			try {
				if (!empty($c->id_asignacion_costo)) {
					$asig = DB::table('asignacion_costos')->where('id_asignacion_costo', $c->id_asignacion_costo)->first();
					if ($asig && isset($asig->numero_cuota)) { $numCuota = (int) $asig->numero_cuota; }
				} elseif (!empty($c->id_cuota)) {
					$asig = DB::table('asignacion_costos')->where('id_cuota_template', $c->id_cuota)->first();
					if ($asig && isset($asig->numero_cuota)) { $numCuota = (int) $asig->numero_cuota; }
				}
			} catch (\Throwable $e) { $numCuota = null; }

			$mes = $this->mapMesFromCuota($gestion, $numCuota);
			if (!$mes && ($codTipoCobro === 'NIVELACION' || $codTipoCobro === 'MORA' || $codTipoCobro === 'ARRASTRE')) {
				$concepto = '';
				try { $concepto = trim((string) ($c->concepto ?? '')); } catch (\Throwable $e) { $concepto = ''; }
				$mes = $this->extractMesFromText($obs . ' ' . $concepto);
			}
			if ($mes) {
				if ($codTipoCobro === 'ARRASTRE') {
					$desc = 'Nivelación ' . $mes;
				} elseif ($codTipoCobro === 'NIVELACION' || $codTipoCobro === 'MORA') {
					$desc = 'Mens. ' . $mes . ' Niv';
				} else {
					$desc = 'Mens. ' . $mes;
					if ($isArr) { $desc .= ' Arr.'; }
				}
				$lines[] = $desc . ' Bs' . $montoFmt;
				continue;
			}

			if ($codTipoCobro === 'ARRASTRE') {
				$desc = 'Nivelación' . ($numCuota ? (' - Cuota ' . $numCuota) : '');
			} elseif ($codTipoCobro === 'NIVELACION' || $codTipoCobro === 'MORA') {
				$desc = 'Mensualidad' . ($numCuota ? (' - Cuota ' . $numCuota) : '') . ' Niv';
			} else {
				$desc = ($isArr ? 'Mensualidad (Arrastre)' : 'Mensualidad') . ($numCuota ? (' - Cuota ' . $numCuota) : '');
			}
			$lines[] = trim($desc) . ' Bs' . $montoFmt;
		}

		$lines = array_values(array_filter(array_unique(array_map(function ($s) {
			return trim((string) $s);
		}, $lines))));
		return implode(', ', $lines);
	}

	private function fechaLiteral(\DateTimeInterface $dt): string
	{
		$meses = [
			1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril', 5 => 'mayo', 6 => 'junio',
			7 => 'julio', 8 => 'agosto', 9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
		];
		try {
			$dia = (int) $dt->format('d');
			$mes = (int) $dt->format('n');
			$anio = (string) $dt->format('Y');
			$mesTxt = $meses[$mes] ?? $dt->format('m');
			return $dia . ' de ' . $mesTxt . ' de ' . $anio;
		} catch (\Throwable $e) {
			return '';
		}
	}

	public function buildPdf(int $anio, int $nroRecibo): string
	{
		$recibo = DB::table('recibo')
			->where('anio', (int) $anio)
			->where('nro_recibo', (int) $nroRecibo)
			->first();
		if (!$recibo) {
			throw new \RuntimeException('Recibo no encontrado');
		}

		$cobros = [];
		try {
			$cobros = DB::table('cobro')
				->where('nro_recibo', (int) $nroRecibo)
				->where('anio_cobro', (int) $anio)
				->orderBy('nro_cobro')
				->get()
				->all();
		} catch (\Throwable $e) {
			$cobros = [];
		}
		if (!$cobros) {
			try {
				$cobros = DB::table('cobro')
					->where('nro_recibo', (int) $nroRecibo)
					->orderByDesc('anio_cobro')
					->orderBy('nro_cobro')
					->get()
					->all();
			} catch (\Throwable $e) {
				$cobros = [];
			}
		}

		$est = null;
		try {
			$codCeta = (int) ($recibo->cod_ceta ?? 0);
			if ($codCeta > 0) {
				$est = DB::table('estudiantes')->where('cod_ceta', $codCeta)->first();
			}
		} catch (\Throwable $e) {
			$est = null;
		}

		$formaId = '';
		$formaNombre = '';
		try {
			$raw = '';
			if (isset($recibo->id_forma_cobro) && $recibo->id_forma_cobro) {
				$raw = (string) $recibo->id_forma_cobro;
			} elseif (!empty($cobros) && isset($cobros[0]->id_forma_cobro)) {
				$raw = (string) $cobros[0]->id_forma_cobro;
			}
			$formaId = strtoupper(trim($raw));
			if ($formaId !== '') {
				$forma = DB::table('formas_cobro')->where('id_forma_cobro', $formaId)->first();
				$formaNombre = strtoupper(trim((string) ($forma->nombre ?? $forma->descripcion ?? $forma->label ?? '')));
				if ($formaNombre === '') {
					$formaNombre = $formaId;
				}
			}
		} catch (\Throwable $e) {
			$formaId = '';
			$formaNombre = '';
		}

		// ── TRASPASO: delegar a NotaTraspasoPdfService ──
		$isTraspaso = ($formaId === 'T' || strpos($formaNombre, 'TRASPASO') !== false);
		if ($isTraspaso) {
			/** @var NotaTraspasoPdfService $ntSvc */
			$ntSvc = app(NotaTraspasoPdfService::class);
			return $ntSvc->buildPdfByRecibo((int) $anio, (int) $nroRecibo);
		}

		$formaCode = $formaId;
		$isEfectivo = ($formaCode === 'E');
		$isBancario = in_array($formaCode, ['B','C','D','L','O'], true);
		if (!$isEfectivo && !$isBancario && $formaNombre !== '') {
			if (strpos($formaNombre, 'EFECTIVO') !== false) { $isEfectivo = true; }
			elseif (strpos($formaNombre, 'TARJETA') !== false) { $isBancario = true; $formaCode = 'L'; }
			elseif (strpos($formaNombre, 'DEPOS') !== false) { $isBancario = true; $formaCode = 'D'; }
			elseif (strpos($formaNombre, 'TRANSFER') !== false) { $isBancario = true; $formaCode = 'B'; }
			elseif (strpos($formaNombre, 'CHEQUE') !== false) { $isBancario = true; $formaCode = 'C'; }
			else { $isBancario = true; }
		}

		$extras = [];
		try {
			$extras['fecha_dt'] = new \DateTime((string) ($recibo->created_at ?? 'now'), new \DateTimeZone('America/La_Paz'));
		} catch (\Throwable $e) {
			$extras['fecha_dt'] = new \DateTime('now', new \DateTimeZone('America/La_Paz'));
		}
		try {
			$codPensum = '';
			if (!empty($cobros) && isset($cobros[0]) && isset($cobros[0]->cod_pensum)) {
				$codPensum = (string) $cobros[0]->cod_pensum;
			} elseif (isset($recibo->cod_pensum)) {
				$codPensum = (string) $recibo->cod_pensum;
			} elseif ($est && isset($est->cod_pensum)) {
				$codPensum = (string) $est->cod_pensum;
			}
			$extras['carrera'] = $this->resolveCarreraNombre($codPensum);
		} catch (\Throwable $e) {
			$extras['carrera'] = '';
		}
		try {
			$logoPath = public_path('img/logo.png');
			if (is_string($logoPath) && $logoPath !== '' && file_exists($logoPath)) {
				$raw = null;
				try { $raw = file_get_contents($logoPath); } catch (\Throwable $e) { $raw = null; }
				if (is_string($raw) && $raw !== '') {
					$extras['logo'] = 'data:image/png;base64,' . base64_encode($raw);
				} else {
					$logoPathNorm = str_replace('\\', '/', $logoPath);
					$extras['logo'] = 'file:///' . ltrim($logoPathNorm, '/');
				}
			}
		} catch (\Throwable $e) {
		}

		$isReposicion = false;
		try {
			foreach ($cobros as $c) {
				$c = (object) $c;
				if (!empty($c->reposicion_factura)) { $isReposicion = true; break; }
			}
		} catch (\Throwable $e) {
			$isReposicion = false;
		}

		// Correlativo de notas (para N° E-<correlativo>)
		try {
			$extras['e_numero'] = (string) ($recibo->nro_recibo ?? '');
			if ($isEfectivo) {
				$nr = DB::table('nota_reposicion')
					->where('nro_recibo', (int) $nroRecibo)
					->orderByDesc('fecha_nota')
					->orderByDesc('correlativo')
					->first();
				if ($nr && isset($nr->correlativo)) {
					$extras['nota_reposicion'] = $nr;
					$extras['e_numero'] = (string) $nr->correlativo;
				}
			} elseif ($isBancario) {
				$nb = DB::table('nota_bancaria')
					->where('nro_recibo', (int) $nroRecibo)
					->orderByDesc('fecha_nota')
					->orderByDesc('correlativo')
					->first();
				if ($nb && isset($nb->correlativo)) {
					$extras['nota'] = $nb;
					$extras['e_numero'] = (string) $nb->correlativo;
				}
			}
		} catch (\Throwable $e) {
			$extras['e_numero'] = (string) ($recibo->nro_recibo ?? '');
		}

		// Banco destino (cuentas_bancarias.banco) para notas bancarias
		try {
			if ($isBancario) {
				$idCuenta = null;
				if (!empty($cobros) && isset($cobros[0]) && isset($cobros[0]->id_cuentas_bancarias) && $cobros[0]->id_cuentas_bancarias) {
					$idCuenta = (int) $cobros[0]->id_cuentas_bancarias;
				}
				if ($idCuenta) {
					$cb = DB::table('cuentas_bancarias')->where('id_cuentas_bancarias', $idCuenta)->first();
					if ($cb && isset($cb->banco)) {
						$extras['dest'] = (object) [ 'banco' => (string) $cb->banco ];
					}
				}
				if (!isset($extras['dest']) && isset($extras['nota']) && is_object($extras['nota']) && isset($extras['nota']->banco) && (string) $extras['nota']->banco !== '') {
					$b = trim((string) $extras['nota']->banco);
					$pos = strpos($b, ' - ');
					if ($pos !== false) { $b = trim(substr($b, 0, $pos)); }
					$extras['dest'] = (object) [ 'banco' => $b ];
				}
			}
		} catch (\Throwable $e) {
		}

		$html = '';
		if ($isReposicion) {
			$html = $this->renderHtml($recibo, $cobros, $est, $extras);
		} elseif ($formaNombre === 'COMBINADO' || count(array_unique(array_map(function ($x) {
			return (string) ((is_object($x) && isset($x->id_forma_cobro)) ? $x->id_forma_cobro : '');
		}, $cobros))) > 1) {
			$html = $this->renderHtmlCombinado($recibo, $cobros, $est, $extras);
		} elseif (strpos($formaNombre, 'TARJETA') !== false || $formaCode === 'L') {
			$html = $this->renderHtmlTarjeta($recibo, $cobros, $est, $extras);
		} elseif (strpos($formaNombre, 'TRANSFER') !== false || $formaCode === 'B') {
			$html = $this->renderHtmlTransferencia($recibo, $cobros, $est, $extras);
		} elseif (strpos($formaNombre, 'DEPOS') !== false || $formaCode === 'D') {
			$html = $this->renderHtmlDeposito($recibo, $cobros, $est, $extras);
		} elseif (strpos($formaNombre, 'CHEQUE') !== false || $formaCode === 'C') {
			$html = $this->renderHtmlCheque($recibo, $cobros, $est, $extras);
		} elseif ($isBancario) {
			$html = $this->renderHtmlOtro($recibo, $cobros, $est, $extras);
		} else {
			$html = $this->renderHtml($recibo, $cobros, $est, $extras);
		}

		$dompdf = new Dompdf([
			'isRemoteEnabled' => true,
			'isHtml5ParserEnabled' => true,
			'isPhpEnabled' => true,
		]);
		$dompdf->loadHtml($html, 'UTF-8');
		$dompdf->setPaper([8.5 * 72, 5.5 * 72]);
		$dompdf->render();
		$pdf = $dompdf->output();
		if (empty($pdf)) {
			throw new \RuntimeException('PDF generado está vacío');
		}
		return $pdf;
	}

	public function buildNotaBancariaPdfByFactura(int $anio, int $nroFactura): string
	{
		$nb = DB::table('nota_bancaria')
			->where('anio_deposito', (int) $anio)
			->where('nro_factura', (string) $nroFactura)
			->orderByDesc('fecha_nota')
			->orderByDesc('correlativo')
			->first();
		if (!$nb) {
			$nb = DB::table('nota_bancaria')
				->where('nro_factura', (string) $nroFactura)
				->orderByDesc('fecha_nota')
				->orderByDesc('correlativo')
				->first();
		}
		if (!$nb) {
			throw new \RuntimeException('Nota bancaria no encontrada');
		}

		$factura = DB::table('factura')
			->where('anio', (int) $anio)
			->where('nro_factura', (int) $nroFactura)
			->orderByDesc('created_at')
			->first();
		if (!$factura) {
			throw new \RuntimeException('Factura no encontrada');
		}

		$cobros = [];
		try {
			$cobros = DB::table('cobro')
				->where('anio_cobro', (int) $anio)
				->where('nro_factura', (int) $nroFactura)
				->orderBy('nro_cobro')
				->get()
				->all();
		} catch (\Throwable $e) {
			$cobros = [];
		}
		if (!$cobros) {
			try {
				$cobros = DB::table('cobro')
					->where('nro_factura', (int) $nroFactura)
					->orderByDesc('anio_cobro')
					->orderBy('nro_cobro')
					->get()
					->all();
			} catch (\Throwable $e) {
				$cobros = [];
			}
		}

		$reciboVirtual = (object) [
			'anio' => (int) $anio,
			'nro_recibo' => 0,
			'nro_factura' => (int) $nroFactura,
			'cod_ceta' => (int) ($factura->cod_ceta ?? 0),
			'id_usuario' => (int) ($factura->id_usuario ?? 0),
			'id_forma_cobro' => (string) ($factura->id_forma_cobro ?? ''),
			'monto_total' => (float) ($factura->monto_total ?? 0),
			'created_at' => (string) ($factura->created_at ?? (string) ($nb->fecha_nota ?? 'now')),
		];

		$est = null;
		try {
			$codCeta = (int) ($reciboVirtual->cod_ceta ?? 0);
			if ($codCeta > 0) {
				$est = DB::table('estudiantes')->where('cod_ceta', $codCeta)->first();
			}
		} catch (\Throwable $e) {
			$est = null;
		}

		$formaId = strtoupper(trim((string) ($reciboVirtual->id_forma_cobro ?? '')));
		$formaNombre = '';
		try {
			if ($formaId !== '') {
				$forma = DB::table('formas_cobro')->where('id_forma_cobro', $formaId)->first();
				$formaNombre = strtoupper(trim((string) ($forma->nombre ?? $forma->descripcion ?? $forma->label ?? '')));
				if ($formaNombre === '') {
					$formaNombre = $formaId;
				}
			}
		} catch (\Throwable $e) {
			$formaNombre = $formaId;
		}

		$extras = [];
		try {
			$extras['fecha_dt'] = new \DateTime((string) ($nb->fecha_nota ?? $reciboVirtual->created_at ?? 'now'), new \DateTimeZone('America/La_Paz'));
		} catch (\Throwable $e) {
			$extras['fecha_dt'] = new \DateTime('now', new \DateTimeZone('America/La_Paz'));
		}
		try {
			$codPensum = '';
			if (!empty($cobros) && isset($cobros[0]) && isset($cobros[0]->cod_pensum)) {
				$codPensum = (string) $cobros[0]->cod_pensum;
			} elseif ($est && isset($est->cod_pensum)) {
				$codPensum = (string) $est->cod_pensum;
			}
			$extras['carrera'] = $this->resolveCarreraNombre($codPensum);
		} catch (\Throwable $e) {
			$extras['carrera'] = '';
		}
		try {
			$logoPath = public_path('img/logo.png');
			if (is_string($logoPath) && $logoPath !== '' && file_exists($logoPath)) {
				$raw = null;
				try { $raw = file_get_contents($logoPath); } catch (\Throwable $e) { $raw = null; }
				if (is_string($raw) && $raw !== '') {
					$extras['logo'] = 'data:image/png;base64,' . base64_encode($raw);
				}
			}
		} catch (\Throwable $e) {}

		$extras['nota'] = $nb;
		$extras['e_numero'] = (string) ($nb->correlativo ?? $nroFactura);
		try {
			$b = trim((string) ($nb->banco ?? ''));
			if ($b !== '') {
				$pos = strpos($b, ' - ');
				if ($pos !== false) { $b = trim(substr($b, 0, $pos)); }
				$extras['dest'] = (object) [ 'banco' => $b ];
			}
		} catch (\Throwable $e) {}

		$html = '';
		$formaCode = $formaId;
		if ($formaNombre === 'COMBINADO' || count(array_unique(array_map(function ($x) {
			return (string) ((is_object($x) && isset($x->id_forma_cobro)) ? $x->id_forma_cobro : '');
		}, $cobros))) > 1) {
			$html = $this->renderHtmlCombinado($reciboVirtual, $cobros, $est, $extras);
		} elseif (strpos($formaNombre, 'TARJETA') !== false || $formaCode === 'L') {
			$html = $this->renderHtmlTarjeta($reciboVirtual, $cobros, $est, $extras);
		} elseif (strpos($formaNombre, 'TRANSFER') !== false || $formaCode === 'B') {
			$html = $this->renderHtmlTransferencia($reciboVirtual, $cobros, $est, $extras);
		} elseif (strpos($formaNombre, 'DEPOS') !== false || $formaCode === 'D') {
			$html = $this->renderHtmlDeposito($reciboVirtual, $cobros, $est, $extras);
		} elseif (strpos($formaNombre, 'CHEQUE') !== false || $formaCode === 'C') {
			$html = $this->renderHtmlCheque($reciboVirtual, $cobros, $est, $extras);
		} else {
			$html = $this->renderHtmlOtro($reciboVirtual, $cobros, $est, $extras);
		}

		$dompdf = new Dompdf([
			'isRemoteEnabled' => true,
			'isHtml5ParserEnabled' => true,
			'isPhpEnabled' => true,
		]);
		$dompdf->loadHtml($html, 'UTF-8');
		$dompdf->setPaper([8.5 * 72, 5.5 * 72]);
		$dompdf->render();
		$pdf = $dompdf->output();
		if (empty($pdf)) {
			throw new \RuntimeException('PDF generado está vacío');
		}
		return $pdf;
	}

    private function renderHtmlTarjeta(object $recibo, array $cobros, ?object $est, array $extras = []): string
    {
        $fechaDT = isset($extras['fecha_dt']) && $extras['fecha_dt'] instanceof \DateTimeInterface ? $extras['fecha_dt'] : new \DateTime('now', new \DateTimeZone('America/La_Paz'));
        $fechaLiteral = $this->fechaLiteral($fechaDT);
        $total = (float)($recibo->monto_total ?? 0);
        $totalFmt = number_format($total, 2, '.', '');
        $literal = $this->numToLiteral($total);
        $nombre = '';
        if ($est) {
            $nombre = trim(implode(' ', array_filter([
                $est->nombres ?? '',
                $est->ap_paterno ?? '',
                $est->ap_materno ?? ''
            ])));
        }
        $usuario = null; $usuarioNombre = '';
        if (isset($recibo->id_usuario)) {
            $usuario = DB::table('usuarios')->where('id_usuario', $recibo->id_usuario)->first();
            $usuarioNombre = (string) ($usuario->usuario ?? ($usuario->nombre ?? ''));
        }
        $detalles = []; $observaciones = [];
        foreach ($cobros as $c) {
            $c = (object)$c;
            $obs = trim((string)($c->observaciones ?? ''));
            $isArr = stripos($obs, 'ARRASTRE') !== false;
            $obsClean = $obs !== ''
                ? trim(preg_replace('/\|?\s*\[\s*REZAGADO\s*\]\s*.+$/i', '', preg_replace('/\[\s*ARRASTRE\s*\]/i', '', $obs)))
                : '';
            if ($obsClean !== '') $observaciones[] = $obsClean;
            // Rezagado: si hay marcador, usarlo directamente como detalle y continuar
            if ($obs !== '' && preg_match('/\[\s*REZAGADO\s*\]\s*(.+)$/i', $obs, $m)) {
                $detalles[] = trim($m[1]);
                continue;
            }
            if (!empty($c->id_item)) {
                try {
                    $it = DB::table('items_cobro')->where('id_item', $c->id_item)->first();
                    $detalles[] = ($it && isset($it->nombre_servicio)) ? (string)$it->nombre_servicio : ('Item ' . $c->id_item);
                } catch (\Throwable $e) { $detalles[] = 'Item ' . $c->id_item; }
            } else {
                $numCuota = null;
                try {
                    if (!empty($c->id_asignacion_costo)) {
                        $asig = DB::table('asignacion_costos')->where('id_asignacion_costo', $c->id_asignacion_costo)->first();
                        if ($asig && isset($asig->numero_cuota)) $numCuota = (int)$asig->numero_cuota;
                    } elseif (!empty($c->id_cuota)) {
                        $asig = DB::table('asignacion_costos')->where('id_cuota_template', $c->id_cuota)->first();
                        if ($asig && isset($asig->numero_cuota)) $numCuota = (int)$asig->numero_cuota;
                    }
                } catch (\Throwable $e) {}
                $lblBase = ($isArr) ? 'Mensualidad (Arrastre)' : 'Mensualidad';
                $lbl = $lblBase . ($numCuota ? (' - Cuota ' . $numCuota) : '');
                $detalles[] = $lbl;
            }
        }
        $detalle = $this->buildDetalleConMontos($cobros);
        $obsLinea = implode(' | ', array_unique(array_filter($observaciones)));
        $carrera = (string)($extras['carrera'] ?? '');
        $logo = (string)($extras['logo'] ?? '');
        $logoHtml = $logo ? ('<img src="' . $logo . '" width="60" height="60" />') : '';
        $nb = isset($extras['nota']) ? $extras['nota'] : null;
        $dest = isset($extras['dest']) ? $extras['dest'] : null;
        $bancoDest = $dest ? ((string)($dest->banco ?? '')) : '';
        $fechaDep = $nb ? ((string)($nb->fecha_deposito ?? '')) : '';
        $codCobro = (string)($extras['cod_cobro'] ?? '');
        $docLine = 'F- ' . ((string)($recibo->nro_factura ?? '0')) . ', R- ' . ((string)((int)($recibo->nro_recibo ?? 0)));

        $html = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8" />
    <style>
        @page { size: 8.5in 5.5in; margin: 3mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 8.7pt; line-height: 1.12; }
        .right { text-align:right; }
        .small { font-size: 8pt; color: #333; }
        .tabla { width:100%; border-collapse: collapse; page-break-inside: avoid; }
        .tabla th, .tabla td { border: 1px solid #000; padding: 2px; }
        .sinborde td { border: none; }
        .label { background:#C8C8C8; font-weight:bold; }
        .separador { border-bottom:2px dotted #000; margin: 4px 0; }
    </style>
    <title>Recibo {$recibo->nro_recibo}/{$recibo->anio}</title>
    </head>
    <body>
        <table class="sinborde" style="width:100%">
            <tr>
                <td style="width:20%; text-align:center; vertical-align:top">{$logoHtml}</td>
                <td style="width:80%; text-align:center; vertical-align:top">
                    <div style="font-size:13pt; color:black; font-weight:bold;">Instituto Tecnológico de Enseñanza Automotriz CETA S.R.L.</div>
                    <div style="font-size:11pt; color:black; font-weight:bold;">Carrera: {$carrera}</div>
                    <div style="font-size:12pt; color:black; font-weight:bold; border-top:2px solid #000; margin-top:3px; padding-top:3px;">NOTA PAGO CON TARJETA</div>
                </td>
            </tr>
        </table>
        <table class="sinborde" style="width:100%; margin-top:2px">
            <tr>
                <td style="width:60%"></td>
                <td class="right" style="width:40%">
                    N° E-{$extras['e_numero']}<br>
                    {$fechaLiteral}
                </td>
            </tr>
        </table>
        <table class="tabla" style="margin-top:4px">
            <tr>
                <td class="label" style="width:20%">&nbsp;Estudiante:</td>
                <td style="width:36%">{$nombre}</td>
                <td class="label" style="width:20%">&nbsp;Banco Dest:</td>
                <td style="width:24%">{$bancoDest}</td>
            </tr>
            <tr>
                <td class="label">&nbsp;Cod. CETA:</td>
                <td>{$recibo->cod_ceta}</td>
                <td class="label">&nbsp;Fecha Cobro:</td>
                <td>{$fechaDT->format('d/m/Y')}</td>
            </tr>
        </table>
        <table class="sinborde" style="width:100%; margin-top:3px">
            <tr>
                <td style="width:70%">
                    <div><span style="font-weight:bold">MONTO:</span></div>
                    <div><span style="font-weight:bold">Literal:</span> {$literal}</div>
                    <div><span style="font-weight:bold">Detalle:</span> {$detalle}</div>
                    <div><span style="font-weight:bold">Obs.:</span> {$obsLinea}</div>
                    <div><span style="font-weight:bold">Doc.:</span> {$docLine}</div>
                </td>
                <td class="right" style="width:30%; vertical-align:top"><div style="font-weight:bold">{$totalFmt}</div></td>
            </tr>
        </table>
        <table class="sinborde" style="width:100%; margin-top:2px">
            <tr>
                <td style="width:60%"></td>
                <td style="width:40%" class="small">{$usuarioNombre} - Firma:</td>
            </tr>
        </table>
        <div class="separador"></div>
        <table class="sinborde" style="width:100%; margin-top:2px">
            <tr>
                <td style="width:60%; vertical-align:top">
                    <div><span style="font-weight:bold">Estudiante:</span> {$nombre}</div>
                    <div><span style="font-weight:bold">Código CETA:</span> {$recibo->cod_ceta}</div>
                    <div><span style="font-weight:bold">Detalle:</span> {$detalle}</div>
                </td>
                <td class="right" style="width:40%">
                    N° E-{$extras['e_numero']}<br>
                    {$fechaLiteral}
                    <div><span style="font-weight:bold">Banco Dest:</span> {$bancoDest}</div>
                </td>
            </tr>
        </table>
        <div class="right" style="margin-top:6px; font-weight:bold">Bs. {$totalFmt}</div>
        <table class="sinborde" style="width:100%; margin-top:3px">
            <tr>
                <td class="small" style="border-top:1px solid #000; padding-top:3px">&nbsp;</td>
                <td class="small right" style="border-top:1px solid #000; padding-top:3px">usuario: {$usuarioNombre}</td>
            </tr>
        </table>
    </body>
    </html>
HTML;
        return $html;
    }
    private function renderHtmlTransferencia(object $recibo, array $cobros, ?object $est, array $extras = []): string
    {
        $fechaDT = isset($extras['fecha_dt']) && $extras['fecha_dt'] instanceof \DateTimeInterface ? $extras['fecha_dt'] : new \DateTime('now', new \DateTimeZone('America/La_Paz'));
        $fechaLiteral = $this->fechaLiteral($fechaDT);
        $total = (float)($recibo->monto_total ?? 0);
        $totalFmt = number_format($total, 2, '.', '');
        $literal = $this->numToLiteral($total);
        $nombre = '';
        if ($est) {
            $nombre = trim(implode(' ', array_filter([
                $est->nombres ?? '',
                $est->ap_paterno ?? '',
                $est->ap_materno ?? ''
            ])));
        }
        $usuario = null; $usuarioNombre = '';
        if (isset($recibo->id_usuario)) {
            $usuario = DB::table('usuarios')->where('id_usuario', $recibo->id_usuario)->first();
            $usuarioNombre = (string) ($usuario->usuario ?? ($usuario->nombre ?? ''));
        }
        $detalles = []; $observaciones = [];
        foreach ($cobros as $c) {
            $c = (object)$c;
            $obs = trim((string)($c->observaciones ?? ''));
            $isArr = stripos($obs, 'ARRASTRE') !== false;
            $obsClean = $obs !== ''
                ? trim(preg_replace('/\|?\s*\[\s*REZAGADO\s*\]\s*.+$/i', '', preg_replace('/\[\s*ARRASTRE\s*\]/i', '', $obs)))
                : '';
            if ($obsClean !== '') $observaciones[] = $obsClean;
            if ($obs !== '' && preg_match('/\[\s*REZAGADO\s*\]\s*(.+)$/i', $obs, $m)) {
                $detalles[] = trim($m[1]);
            } else {
                // detectar etiqueta
                if (!empty($c->id_item)) {
                    try { $it = DB::table('items_cobro')->where('id_item', $c->id_item)->first(); $detalles[] = ($it && isset($it->nombre_servicio)) ? (string)$it->nombre_servicio : ('Item ' . $c->id_item); }
                    catch (\Throwable $e) { $detalles[] = 'Item ' . $c->id_item; }
                } else {
                    $numCuota = null;
                    try {
                        if (!empty($c->id_asignacion_costo)) {
                            $asig = DB::table('asignacion_costos')->where('id_asignacion_costo', $c->id_asignacion_costo)->first();
                            if ($asig && isset($asig->numero_cuota)) $numCuota = (int)$asig->numero_cuota;
                        } elseif (!empty($c->id_cuota)) {
                            $asig = DB::table('asignacion_costos')->where('id_cuota_template', $c->id_cuota)->first();
                            if ($asig && isset($asig->numero_cuota)) $numCuota = (int)$asig->numero_cuota;
                        }
                    } catch (\Throwable $e) {}
                    $detalles[] = (($isArr ? 'Mensualidad (Arrastre)' : 'Mensualidad') . ($numCuota ? (' - Cuota ' . $numCuota) : ''));
                }
            }
            if ($obs !== '' && preg_match('/\[\s*REZAGADO\s*\]\s*(.+)$/i', $obs, $m)) {
                $detalles[] = trim($m[1]);
                continue;
            }
            if (!empty($c->id_item)) {
                try {
                    $it = DB::table('items_cobro')->where('id_item', $c->id_item)->first();
                    $detalles[] = ($it && isset($it->nombre_servicio)) ? (string)$it->nombre_servicio : ('Item ' . $c->id_item);
                } catch (\Throwable $e) { $detalles[] = 'Item ' . $c->id_item; }
            } else {
                $numCuota = null;
                try {
                    if (!empty($c->id_asignacion_costo)) {
                        $asig = DB::table('asignacion_costos')->where('id_asignacion_costo', $c->id_asignacion_costo)->first();
                        if ($asig && isset($asig->numero_cuota)) $numCuota = (int)$asig->numero_cuota;
                    } elseif (!empty($c->id_cuota)) {
                        $asig = DB::table('asignacion_costos')->where('id_cuota_template', $c->id_cuota)->first();
                        if ($asig && isset($asig->numero_cuota)) $numCuota = (int)$asig->numero_cuota;
                    }
                } catch (\Throwable $e) {}
                $detalles[] = (($isArr ? 'Mensualidad (Arrastre)' : 'Mensualidad') . ($numCuota ? (' - Cuota ' . $numCuota) : ''));
            }
        }
        $detalle = $this->buildDetalleConMontos($cobros);
        $obsLinea = implode(' | ', array_unique(array_filter($observaciones)));
        $carrera = (string)($extras['carrera'] ?? '');
        $logo = (string)($extras['logo'] ?? '');
        $logoHtml = $logo ? ('<img src="' . $logo . '" width="60" height="60" />') : '';
        $nb = isset($extras['nota']) ? $extras['nota'] : null;
        $dest = isset($extras['dest']) ? $extras['dest'] : null;
        $bancoDest = $dest ? ((string)($dest->banco ?? '')) : '';
        $fechaTrans = $nb ? ((string)($nb->fecha_deposito ?? '')) : '';
        $nroTrans = $nb ? ((string)($nb->nro_transaccion ?? '')) : '';
        try {
            $qrRow = DB::table('qr_transacciones')
                ->where('anio_recibo', (int)$recibo->anio)
                ->where('nro_recibo', (int)$recibo->nro_recibo)
                ->orderByDesc('updated_at')
                ->first();
            if ($qrRow && isset($qrRow->numeroordenoriginante) && $qrRow->numeroordenoriginante !== null && $qrRow->numeroordenoriginante !== '') {
                $nroTrans = (string)$qrRow->numeroordenoriginante;
            }
        } catch (\Throwable $e) {}
        $fechaTransFmt = $fechaTrans !== '' ? $fechaTrans : $fechaDT->format('d/m/Y');
        $docLine = 'F- ' . ((string)($recibo->nro_factura ?? '0')) . ', R- ' . ((string)($recibo->nro_recibo ?? ''));

        $html = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8" />
    <style>
        @page { size: 8.5in 5.5in; margin: 3mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 8.7pt; line-height: 1.12; }
        .right { text-align:right; }
        .small { font-size: 8pt; color: #333; }
        .tabla { width:100%; border-collapse: collapse; page-break-inside: avoid; }
        .tabla th, .tabla td { border: 1px solid #000; padding: 2px; }
        .sinborde td { border: none; }
        .label { background:#C8C8C8; font-weight:bold; }
        .separador { border-bottom:2px dotted #000; margin: 4px 0; }
    </style>
    <title>Recibo {$recibo->nro_recibo}/{$recibo->anio}</title>
    </head>
    <body>
        <table class="sinborde" style="width:100%">
            <tr>
                <td style="width:20%; text-align:center; vertical-align:top">{$logoHtml}</td>
                <td style="width:80%; text-align:center; vertical-align:top">
                    <div style="font-size:13pt; color:black; font-weight:bold;">Instituto Tecnológico de Enseñanza Automotriz CETA S.R.L.</div>
                    <div style="font-size:11pt; color:black; font-weight:bold;">Carrera: {$carrera}</div>
                    <div style="font-size:12pt; color:black; font-weight:bold; border-top:2px solid #000; margin-top:3px; padding-top:3px;">NOTA DE TRANSFERENCIA</div>
                </td>
            </tr>
        </table>
        <table class="sinborde" style="width:100%; margin-top:2px">
            <tr>
                <td style="width:60%"></td>
                <td class="right" style="width:40%">
                    N° E-{$extras['e_numero']}<br>
                    {$fechaLiteral}
                </td>
            </tr>
        </table>
        <table class="tabla" style="margin-top:4px">
            <colgroup>
                <col style="width:18%" />
                <col style="width:34%" />
                <col style="width:16%" />
                <col style="width:20%" />
                <col style="width:6%" />
                <col style="width:6%" />
            </colgroup>
            <tr>
                <td class="label">&nbsp;Estudiante:</td>
                <td>{$nombre}</td>
                <td class="label">&nbsp;Banco Dest:</td>
                <td colspan="3">{$bancoDest}</td>
            </tr>
            <tr>
                <td class="label">&nbsp;Cod. CETA:</td>
                <td>{$recibo->cod_ceta}</td>
                <td class="label">&nbsp;Fecha Trans.:</td>
                <td>{$fechaTransFmt}</td>
                <td class="label" style="white-space:nowrap">&nbsp;N° Trans.:</td>
                <td>{$nroTrans}</td>
            </tr>
        </table>
        <table class="sinborde" style="width:100%; margin-top:8px">
            <tr>
                <td style="width:70%">
                    <div><span style="font-weight:bold">MONTO:</span></div>
                    <div><span style="font-weight:bold">Literal:</span>{$literal}</div>
                    <div><span style="font-weight:bold">Detalle:</span>{$detalle}</div>
                    <div><span style="font-weight:bold">Obs.:</span> {$obsLinea}</div>
                    <div><span style="font-weight:bold">Doc.:</span>{$docLine}</div>
                </td>
                <td class="right" style="width:30%; vertical-align:top"><div style="font-weight:bold">{$totalFmt}</div></td>
            </tr>
        </table>
        <table class="sinborde" style="width:100%; margin-top:8px">
            <tr>
                <td style="width:60%"></td>
                <td style="width:40%" class="small">{$usuarioNombre} - Firma:</td>
            </tr>
        </table>
        <div class="separador" style="margin-top:6px"></div>
        <div style="text-align:center; margin-top:6px">Carrera: {$carrera}</div>
        <table class="sinborde" style="width:100%; margin-top:10px">
            <tr>
                <td style="width:60%; vertical-align:top">
                    <div><span style="font-weight:bold">Estudiante:</span> {$nombre}</div>
                    <div><span style="font-weight:bold">Código CETA:</span> {$recibo->cod_ceta}</div>
                    <div><span style="font-weight:bold">MONTO:</span></div>
                    <div><span style="font-weight:bold">Detalle:</span>{$detalle}</div>
                </td>
                <td class="right" style="width:40%">
                    <div style="font-weight:bold">TRANSFERENCIA</div>
                    N° E-{$extras['e_numero']}<br>
                    {$fechaLiteral}
                    <div><span style="font-weight:bold">Banco Dest:</span> {$bancoDest}</div>
                    <div><span style="font-weight:bold">Fecha Trans.:</span> {$fechaTransFmt}</div>
                    <div><span style="font-weight:bold">N° Trans.:</span> {$nroTrans}</div>
                </td>
            </tr>
        </table>
        <div class="right" style="margin-top:6px; font-weight:bold">Bs. {$totalFmt}</div>
        <table class="sinborde" style="width:100%; margin-top:8px">
            <tr>
                <td class="small" style="border-top:1px solid #000; padding-top:3px">&nbsp;</td>
                <td class="small right" style="border-top:1px solid #000; padding-top:3px">usuario: {$usuarioNombre}</td>
            </tr>
        </table>
    </body>
    </html>
HTML;
        return $html;
    }
    private function renderHtmlDeposito(object $recibo, array $cobros, ?object $est, array $extras = []): string
    {
        $fechaDT = isset($extras['fecha_dt']) && $extras['fecha_dt'] instanceof \DateTimeInterface ? $extras['fecha_dt'] : new \DateTime('now', new \DateTimeZone('America/La_Paz'));
        $fechaLiteral = $this->fechaLiteral($fechaDT);
        $total = (float)($recibo->monto_total ?? 0);
        $totalFmt = number_format($total, 2, '.', '');
        $literal = $this->numToLiteral($total);
        $nombre = '';
        if ($est) {
            $nombre = trim(implode(' ', array_filter([
                $est->nombres ?? '',
                $est->ap_paterno ?? '',
                $est->ap_materno ?? ''
            ])));
        }
        $usuario = null; $usuarioNombre = '';
        if (isset($recibo->id_usuario)) {
            $usuario = DB::table('usuarios')->where('id_usuario', $recibo->id_usuario)->first();
            $usuarioNombre = (string) ($usuario->usuario ?? ($usuario->nombre ?? ''));
        }
        $detalles = []; $observaciones = [];
        foreach ($cobros as $c) {
            $c = (object)$c;
            $obs = trim((string)($c->observaciones ?? ''));
            $isArr = stripos($obs, 'ARRASTRE') !== false;
            $obsClean = $obs !== ''
                ? trim(preg_replace('/\|?\s*\[\s*REZAGADO\s*\]\s*.+$/i', '', preg_replace('/\[\s*ARRASTRE\s*\]/i', '', $obs)))
                : '';
            if ($obsClean !== '') $observaciones[] = $obsClean;
            if ($obs !== '' && preg_match('/\[\s*REZAGADO\s*\]\s*(.+)$/i', $obs, $m)) {
                $detalles[] = trim($m[1]);
                continue;
            }
            if (!empty($c->id_item)) {
                try {
                    $it = DB::table('items_cobro')->where('id_item', $c->id_item)->first();
                    $detalles[] = ($it && isset($it->nombre_servicio)) ? (string)$it->nombre_servicio : ('Item ' . $c->id_item);
                } catch (\Throwable $e) { $detalles[] = 'Item ' . $c->id_item; }
            } else {
                $numCuota = null;
                try {
                    if (!empty($c->id_asignacion_costo)) {
                        $asig = DB::table('asignacion_costos')->where('id_asignacion_costo', $c->id_asignacion_costo)->first();
                        if ($asig && isset($asig->numero_cuota)) $numCuota = (int)$asig->numero_cuota;
                    } elseif (!empty($c->id_cuota)) {
                        $asig = DB::table('asignacion_costos')->where('id_cuota_template', $c->id_cuota)->first();
                        if ($asig && isset($asig->numero_cuota)) $numCuota = (int)$asig->numero_cuota;
                    }
                } catch (\Throwable $e) {}
                $detalles[] = (($isArr ? 'Mensualidad (Arrastre)' : 'Mensualidad') . ($numCuota ? (' - Cuota ' . $numCuota) : ''));
            }
        }
        $detalle = $this->buildDetalleConMontos($cobros);
        $obsLinea = implode(' | ', array_unique(array_filter($observaciones)));
        $carrera = (string)($extras['carrera'] ?? '');
        $logo = (string)($extras['logo'] ?? '');
        $logoHtml = $logo ? ('<img src="' . $logo . '" width="60" height="60" />') : '';
        $nb = isset($extras['nota']) ? $extras['nota'] : null;
        $dest = isset($extras['dest']) ? $extras['dest'] : null;
        $bancoDest = $dest ? ((string)($dest->banco ?? '')) : '';
        $fechaDep = $nb ? ((string)($nb->fecha_deposito ?? '')) : '';
        $nroTrans = $nb ? ((string)($nb->nro_transaccion ?? '')) : '';
        $fechaDepFmt = $fechaDep !== '' ? $fechaDep : $fechaDT->format('d/m/Y');
        $docLine = 'F- ' . ((string)($recibo->nro_factura ?? '0')) . ', R- ' . ((string)($recibo->nro_recibo ?? ''));

        $html = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8" />
    <style>
        @page { size: 8.5in 5.5in; margin: 3mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 8.7pt; line-height: 1.12; }
        .right { text-align:right; }
        .small { font-size: 8pt; color: #333; }
        .tabla { width:100%; border-collapse: collapse; page-break-inside: avoid; }
        .tabla th, .tabla td { border: 1px solid #000; padding: 2px; }
        .sinborde td { border: none; }
        .label { background:#C8C8C8; font-weight:bold; }
        .separador { border-bottom:2px dotted #000; margin: 4px 0; }
    </style>
    <title>Recibo {$recibo->nro_recibo}/{$recibo->anio}</title>
    </head>
    <body>
        <table class="sinborde" style="width:100%">
            <tr>
                <td style="width:20%; text-align:center; vertical-align:top">{$logoHtml}</td>
                <td style="width:80%; text-align:center; vertical-align:top">
                    <div style="font-size:13pt; color:black; font-weight:bold;">Instituto Tecnológico de Enseñanza Automotriz CETA S.R.L.</div>
                    <div style="font-size:11pt; color:black; font-weight:bold;">Carrera: {$carrera}</div>
                    <div style="font-size:12pt; color:black; font-weight:bold; border-top:2px solid #000; margin-top:3px; padding-top:3px;">NOTA DE DEPOSITO</div>
                </td>
            </tr>
        </table>
        <table class="sinborde" style="width:100%; margin-top:2px">
            <tr>
                <td style="width:60%"></td>
                <td class="right" style="width:40%">
                    N° E-{$extras['e_numero']}<br>
                    {$fechaLiteral}
                </td>
            </tr>
        </table>
        <table class="tabla" style="margin-top:4px">
            <colgroup>
                <col style="width:18%" />
                <col style="width:34%" />
                <col style="width:16%" />
                <col style="width:20%" />
                <col style="width:6%" />
                <col style="width:6%" />
            </colgroup>
            <tr>
                <td class="label">&nbsp;Estudiante:</td>
                <td>{$nombre}</td>
                <td class="label">&nbsp;Banco:</td>
                <td colspan="3">{$bancoDest}</td>
            </tr>
            <tr>
                <td class="label">&nbsp;Cod. CETA:</td>
                <td>{$recibo->cod_ceta}</td>
                <td class="label">&nbsp;Fecha Dep.:</td>
                <td>{$fechaDepFmt}</td>
                <td class="label" style="white-space:nowrap">&nbsp;N° Trans.:</td>
                <td>{$nroTrans}</td>
            </tr>
        </table>
        <table class="sinborde" style="width:100%; margin-top:8px">
            <tr>
                <td style="width:70%">
                    <div><span style="font-weight:bold">MONTO:</span></div>
                    <div><span style="font-weight:bold">Literal:</span>{$literal}</div>
                    <div><span style="font-weight:bold">Detalle:</span>{$detalle}</div>
                    <div><span style="font-weight:bold">Obs.:</span>{$obsLinea}</div>
                    <div><span style="font-weight:bold">Doc.:</span>{$docLine}</div>
                </td>
                <td class="right" style="width:30%; vertical-align:top"><div style="font-weight:bold">{$totalFmt}</div></td>
            </tr>
        </table>

		<table class="sinborde" style="width:100%; margin-top:8px">
			<tr>
				<td style="width:60%"></td>
				<td style="width:40%" class="small">{$usuarioNombre} - Firma:</td>
			</tr>
		</table>
		<div class="separador" style="margin-top:6px"></div>
		<div style="text-align:center; margin-top:6px">Carrera: {$carrera}</div>
		<table class="sinborde" style="width:100%; margin-top:10px">
			<tr>
				<td style="width:60%; vertical-align:top">
					<div><span style="font-weight:bold">Estudiante:</span> {$nombre}</div>
					<div><span style="font-weight:bold">Código CETA:</span> {$recibo->cod_ceta}</div>
					<div><span style="font-weight:bold">MONTO:</span></div>
					<div><span style="font-weight:bold">Detalle:</span>{$detalle}</div>
				</td>
				<td class="right" style="width:40%">
					<div style="font-weight:bold">DEPÓSITO EN CUENTA</div>
					N° E-{$extras['e_numero']}<br>
					{$fechaLiteral}
					<div><span style="font-weight:bold">Banco:</span> {$bancoDest}</div>
					<div><span style="font-weight:bold">Fecha Depósito:</span> {$fechaDepFmt}</div>
					<div><span style="font-weight:bold">N° Trans.:</span> {$nroTrans}</div>
				</td>
			</tr>
		</table>
		<div class="right" style="margin-top:6px; font-weight:bold">Bs. {$totalFmt}</div>
		<table class="sinborde" style="width:100%; margin-top:8px">
			<tr>
				<td class="small" style="border-top:1px solid #000; padding-top:3px">&nbsp;</td>
				<td class="small right" style="border-top:1px solid #000; padding-top:3px">usuario: {$usuarioNombre}</td>
			</tr>
		</table>
    </body>
    </html>
HTML;
        return $html;
    }
    private function renderHtmlCheque(object $recibo, array $cobros, ?object $est, array $extras = []): string
    {
        $fechaDT = isset($extras['fecha_dt']) && $extras['fecha_dt'] instanceof \DateTimeInterface ? $extras['fecha_dt'] : new \DateTime('now', new \DateTimeZone('America/La_Paz'));
        $fechaLiteral = $this->fechaLiteral($fechaDT);
        $total = (float)($recibo->monto_total ?? 0);
        $totalFmt = number_format($total, 2, '.', '');
        $literal = $this->numToLiteral($total);
        $nombre = '';
        if ($est) {
            $nombre = trim(implode(' ', array_filter([
                $est->nombres ?? '',
                $est->ap_paterno ?? '',
                $est->ap_materno ?? ''
            ])));
        }
        $usuario = null; $usuarioNombre = '';
        if (isset($recibo->id_usuario)) {
            $usuario = DB::table('usuarios')->where('id_usuario', $recibo->id_usuario)->first();
            $usuarioNombre = (string) ($usuario->usuario ?? ($usuario->nombre ?? ''));
        }
        $detalles = []; $observaciones = [];
        foreach ($cobros as $c) {
            $c = (object)$c;
            $obs = trim((string)($c->observaciones ?? ''));
            $isArr = stripos($obs, 'ARRASTRE') !== false;
            $obsClean = $obs !== ''
                ? trim(preg_replace('/\|?\s*\[\s*REZAGADO\s*\]\s*.+$/i', '', preg_replace('/\[\s*ARRASTRE\s*\]/i', '', $obs)))
                : '';
            if ($obsClean !== '') $observaciones[] = $obsClean;
            if ($obs !== '' && preg_match('/\[\s*REZAGADO\s*\]\s*(.+)$/i', $obs, $m)) {
                $detalles[] = trim($m[1]);
                continue;
            }
            if (!empty($c->id_item)) {
                try {
                    $it = DB::table('items_cobro')->where('id_item', $c->id_item)->first();
                    $detalles[] = ($it && isset($it->nombre_servicio)) ? (string)$it->nombre_servicio : ('Item ' . $c->id_item);
                } catch (\Throwable $e) { $detalles[] = 'Item ' . $c->id_item; }
            } else {
                $numCuota = null;
                try {
                    if (!empty($c->id_asignacion_costo)) {
                        $asig = DB::table('asignacion_costos')->where('id_asignacion_costo', $c->id_asignacion_costo)->first();
                        if ($asig && isset($asig->numero_cuota)) $numCuota = (int)$asig->numero_cuota;
                    } elseif (!empty($c->id_cuota)) {
                        $asig = DB::table('asignacion_costos')->where('id_cuota_template', $c->id_cuota)->first();
                        if ($asig && isset($asig->numero_cuota)) $numCuota = (int)$asig->numero_cuota;
                    }
                } catch (\Throwable $e) {}
                $detalles[] = (($isArr ? 'Mensualidad (Arrastre)' : 'Mensualidad') . ($numCuota ? (' - Cuota ' . $numCuota) : ''));
            }
        }
        $detalle = $this->buildDetalleConMontos($cobros);
        $obsLinea = implode(' | ', array_unique(array_filter($observaciones)));
        $carrera = (string)($extras['carrera'] ?? '');
        $logo = (string)($extras['logo'] ?? '');
        $logoHtml = $logo ? ('<img src="' . $logo . '" width="60" height="60" />') : '';
        $nb = isset($extras['nota']) ? $extras['nota'] : null;
        $dest = isset($extras['dest']) ? $extras['dest'] : null;
        $bancoDest = $dest ? ((string)($dest->banco ?? '')) : '';
        $nroCheque = $nb ? ((string)($nb->nro_transaccion ?? '')) : '';
        $docLine = 'F- ' . ((string)($recibo->nro_factura ?? '0')) . ', R- ' . ((string)($recibo->nro_recibo ?? ''));

        $html = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8" />
    <style>
        @page { size: 8.5in 5.5in; margin: 3mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 8.7pt; line-height: 1.12; }
        .right { text-align:right; }
        .small { font-size: 8pt; color: #333; }
        .tabla { width:100%; border-collapse: collapse; page-break-inside: avoid; }
        .tabla th, .tabla td { border: 1px solid #000; padding: 2px; }
        .sinborde td { border: none; }
        .label { background:#C8C8C8; font-weight:bold; }
        .separador { border-bottom:2px dotted #000; margin: 4px 0; }
    </style>
    <title>Recibo {$recibo->nro_recibo}/{$recibo->anio}</title>
    </head>
    <body>
        <table class="sinborde" style="width:100%">
            <tr>
                <td style="width:20%; text-align:center; vertical-align:top">{$logoHtml}</td>
                <td style="width:80%; text-align:center; vertical-align:top">
                    <div style="font-size:13pt; color:black; font-weight:bold;">Instituto Tecnológico de Enseñanza Automotriz CETA S.R.L.</div>
                    <div style="font-size:11pt; color:black; font-weight:bold;">Carrera: {$carrera}</div>
                    <div style="font-size:12pt; color:black; font-weight:bold; border-top:2px solid #000; margin-top:3px; padding-top:3px;">NOTA PAGO CON CHEQUE</div>
                </td>
            </tr>
        </table>
        <table class="sinborde" style="width:100%; margin-top:2px">
            <tr>
                <td style="width:60%"></td>
                <td class="right" style="width:40%">
                    N° E-{$extras['e_numero']}<br>
                    {$fechaLiteral}
                </td>
            </tr>
        </table>
        <table class="tabla" style="margin-top:4px">
            <tr>
                <td class="label" style="width:20%">&nbsp;Estudiante:</td>
                <td style="width:36%">{$nombre}</td>
                <td class="label" style="width:20%">&nbsp;Banco:</td>
                <td style="width:24%">{$bancoDest}</td>
            </tr>
            <tr>
                <td class="label">&nbsp;Cod. CETA:</td>
                <td>{$recibo->cod_ceta}</td>
                <td class="label">&nbsp;Fecha Cobro:</td>
                <td>{$fechaDT->format('d/m/Y')}</td>
            </tr>
            <tr>
                <td class="label">&nbsp;N° Cheque:</td>
                <td colspan="3">{$nroCheque}</td>
            </tr>
        </table>
        <table class="sinborde" style="width:100%; margin-top:3px">
            <tr>
                <td style="width:70%">
                    <div><span style="font-weight:bold">MONTO:</span></div>
                    <div><span style="font-weight:bold">Literal:</span> {$literal}</div>
                    <div><span style="font-weight:bold">Detalle:</span> {$detalle}</div>
                    <div><span style="font-weight:bold">Obs.:</span> {$obsLinea}</div>
                    <div><span style="font-weight:bold">Doc.:</span> {$docLine}</div>
                </td>
                <td class="right" style="width:30%; vertical-align:top"><div style="font-weight:bold">{$totalFmt}</div></td>
            </tr>
        </table>
        <table class="sinborde" style="width:100%; margin-top:2px">
            <tr>
                <td style="width:60%"></td>
                <td style="width:40%" class="small">{$usuarioNombre} - Firma:</td>
            </tr>
        </table>
        <div class="separador"></div>
        <table class="sinborde" style="width:100%; margin-top:2px">
            <tr>
                <td style="width:60%; vertical-align:top">
                    <div><span style="font-weight:bold">Estudiante:</span> {$nombre}</div>
                    <div><span style="font-weight:bold">Código CETA:</span> {$recibo->cod_ceta}</div>
                    <div><span style="font-weight:bold">Detalle:</span> {$detalle}</div>
                </td>
                <td class="right" style="width:40%">
                    <div style="font-weight:bold">PAGO CON CHEQUE</div>
                    N° E-{$extras['e_numero']}<br>
                    {$fechaLiteral}
                    <div><span style="font-weight:bold">Banco:</span> {$bancoDest}</div>
                    <div><span style="font-weight:bold">Fecha Cobro:</span> {$fechaDT->format('d/m/Y')}</div>
                    <div><span style="font-weight:bold">N° Cheque:</span> {$nroCheque}</div>
                </td>
            </tr>
        </table>
        <div class="right" style="margin-top:6px; font-weight:bold">Bs. {$totalFmt}</div>
        <table class="sinborde" style="width:100%; margin-top:3px">
            <tr>
                <td class="small" style="border-top:1px solid #000; padding-top:3px">&nbsp;</td>
                <td class="small right" style="border-top:1px solid #000; padding-top:3px">usuario: {$usuarioNombre}</td>
            </tr>
        </table>
    </body>
    </html>
HTML;
        return $html;
    }
    private function renderHtmlOtro(object $recibo, array $cobros, ?object $est, array $extras = []): string
    {
        $fechaDT = new \DateTime('now', new \DateTimeZone('America/La_Paz'));
        $fechaLiteral = $this->fechaLiteral($fechaDT);
        $total = (float)($recibo->monto_total ?? 0);
        $totalFmt = number_format($total, 2, '.', '');
        $literal = $this->numToLiteral($total);
        $nombre = '';
        if ($est) {
            $nombre = trim(implode(' ', array_filter([
                $est->nombres ?? '',
                $est->ap_paterno ?? '',
                $est->ap_materno ?? ''
            ])));
        }
        // Usuario para firma/footer
        $usuario = null; $usuarioNombre = '';
        if (isset($recibo->id_usuario)) {
            $usuario = DB::table('usuarios')->where('id_usuario', $recibo->id_usuario)->first();
            $usuarioNombre = (string) ($usuario->usuario ?? ($usuario->nombre ?? ''));
        }
        // Detalles y observaciones
        $detalles = []; $observaciones = [];
        foreach ($cobros as $c) {
            $c = (object)$c;
            $obs = trim((string)($c->observaciones ?? ''));
            $isArr = stripos($obs, 'ARRASTRE') !== false;
            $obsClean = $obs !== '' ? trim(preg_replace('/\|?\s*\[\s*REZAGADO\s*\]\s*.+$/i', '', preg_replace('/\[\s*ARRASTRE\s*\]/i', '', $obs))) : '';
            if ($obsClean !== '') $observaciones[] = $obsClean;
            if ($obs !== '' && preg_match('/\[\s*REZAGADO\s*\]\s*(.+)$/i', $obs, $m)) {
                $detalles[] = trim($m[1]);
                continue;
            }
            if (!empty($c->id_item)) {
                try {
                    $it = DB::table('items_cobro')->where('id_item', $c->id_item)->first();
                    $detalles[] = $it ? ((string)($it->descripcion ?? $it->nombre ?? 'Item ' . $c->id_item)) : ('Item ' . $c->id_item);
                } catch (\Throwable $e) { $detalles[] = 'Item ' . $c->id_item; }
            } else {
                $numCuota = null;
                try {
                    if (!empty($c->id_asignacion_costo)) {
                        $asig = DB::table('asignacion_costos')->where('id_asignacion_costo', $c->id_asignacion_costo)->first();
                        if ($asig && isset($asig->numero_cuota)) $numCuota = (int)$asig->numero_cuota;
                    } elseif (!empty($c->id_cuota)) {
                        $asig = DB::table('asignacion_costos')->where('id_cuota_template', $c->id_cuota)->first();
                        if ($asig && isset($asig->numero_cuota)) $numCuota = (int)$asig->numero_cuota;
                    }
                } catch (\Throwable $e) {}
                $lblBase = ($isArr) ? 'Mensualidad (Arrastre)' : 'Mensualidad';
                $lbl = $lblBase . ($numCuota ? (' - Cuota ' . $numCuota) : '');
                $detalles[] = $lbl;
            }
        }
        $detalle = $this->buildDetalleConMontos($cobros);
        $obsLinea = implode(' | ', array_unique(array_filter($observaciones)));
        $carrera = (string)($extras['carrera'] ?? '');
        $logo = (string)($extras['logo'] ?? '');
        $logoHtml = $logo ? ('<img src="' . $logo . '" width="60" height="60" />') : '';
        $docLine = 'F- ' . ((string)($recibo->nro_factura ?? '0')) . ', R- ' . ((string)($recibo->nro_recibo ?? ''));

        $html = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8" />
    <style>
        @page { size: 8.5in 5.5in; margin: 3mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 8.7pt; line-height: 1.12; }
        .right { text-align:right; }
        .small { font-size: 8pt; color: #333; }
        .tabla { width:100%; border-collapse: collapse; page-break-inside: avoid; }
        .tabla th, .tabla td { border: 1px solid #000; padding: 2px; }
        .sinborde td { border: none; }
        .label { background:#C8C8C8; font-weight:bold; }
        .separador { border-bottom:2px dotted #000; margin: 4px 0; }
    </style>
    <title>Recibo {$recibo->nro_recibo}/{$recibo->anio}</title>
    </head>
    <body>
        <table class="sinborde" style="width:100%">
            <tr>
                <td style="width:20%; text-align:center; vertical-align:top">{$logoHtml}</td>
                <td style="width:80%; text-align:center; vertical-align:top">
                    <div style="font-size:13pt; color:black; font-weight:bold;">Instituto Tecnológico de Enseñanza Automotriz CETA S.R.L.</div>
                    <div style="font-size:11pt; color:black; font-weight:bold;">Carrera: {$carrera}</div>
                    <div style="font-size:12pt; color:#1E2768; font-weight:bold; border-top:2px solid #000; margin-top:3px; padding-top:3px;">NOTA OTRO TIPO DE PAGO</div>
                </td>
            </tr>
        </table>
        <table class="sinborde" style="width:100%; margin-top:2px">
            <tr>
                <td style="width:60%"></td>
                <td class="right" style="width:40%">
                    N° E-{$extras['e_numero']}<br>
                    {$fechaLiteral}
                </td>
            </tr>
        </table>
        <table class="tabla" style="margin-top:4px">
            <tr>
                <td class="label" style="width:20%">&nbsp;Estudiante:</td>
                <td style="width:60%">{$nombre}</td>
                <td class="right" style="width:20%; font-weight:bold">{$totalFmt}</td>
            </tr>
            <tr>
                <td class="label">&nbsp;Código CETA:</td>
                <td colspan="2">{$recibo->cod_ceta}</td>
            </tr>
        </table>
        <table class="sinborde" style="width:100%; margin-top:3px">
            <tr>
                <td style="width:70%">
                    <div><span style="font-weight:bold; color:#0B2161">MONTO:</span></div>
                    <div><span style="font-weight:bold; color:#0B2161">Literal:</span> {$literal}</div>
                    <div><span style="font-weight:bold; color:#0B2161">Detalle:</span> {$detalle}</div>
                    <div><span style="font-weight:bold; color:#0B2161">Observacion:</span> {$obsLinea}</div>
                    <div><span style="font-weight:bold; color:#0B2161">{$docLine}</span></div>
                </td>
                <td class="right" style="width:30%; vertical-align:top"><div style="font-weight:bold">{$totalFmt}</div></td>
            </tr>
        </table>
        <table class="sinborde" style="width:100%; margin-top:2px">
            <tr>
                <td style="width:60%"></td>
                <td style="width:40%" class="small">{$usuarioNombre} - Firma:</td>
            </tr>
        </table>
        <div class="separador"></div>
        <div style="text-align:center; margin-top:6px">Carrera: {$carrera}</div>
        <div class="titulo" style="text-align:center; font-weight:bold; margin-top:6px">NOTA OTRO TIPO DE PAGO</div>
        <table class="sinborde" style="width:100%; margin-top:8px">
            <tr>
                <td style="width:60%; vertical-align:top">
                    <div><span style="font-weight:bold">Estudiante:</span> {$nombre}</div>
                    <div><span style="font-weight:bold">Código CETA:</span> {$recibo->cod_ceta}</div>
                    <div><span style="font-weight:bold">Detalle:</span> {$detalle}</div>
                </td>
                <td class="right" style="width:40%">
                    N° E-{$extras['e_numero']}<br>
                    {$fechaLiteral}
                </td>
            </tr>
        </table>
        <div class="right" style="margin-top:6px; font-weight:bold">Bs. {$totalFmt}</div>
        <table class="sinborde" style="width:100%; margin-top:3px">
            <tr>
                <td class="small" style="border-top:1px solid #000; padding-top:3px">&nbsp;</td>
                <td class="small right" style="border-top:1px solid #000; padding-top:3px">usuario: {$usuarioNombre}</td>
            </tr>
        </table>
    </body>
    </html>
HTML;
        return $html;
    }
    private function renderHtmlCombinado(object $recibo, array $cobros, ?object $est, array $extras = []): string
    {
        $fechaDT = new \DateTime('now', new \DateTimeZone('America/La_Paz'));
        $fechaLiteral = $this->fechaLiteral($fechaDT);
        $total = (float)($recibo->monto_total ?? 0);
        $totalFmt = number_format($total, 2, '.', '');
        $literal = $this->numToLiteral($total);
        $nombre = '';
        if ($est) {
            $nombre = trim(implode(' ', array_filter([
                $est->nombres ?? '',
                $est->ap_paterno ?? '',
                $est->ap_materno ?? ''
            ])));
        }
        // Usuario para firma/footer
        $usuario = null; $usuarioNombre = '';
        if (isset($recibo->id_usuario)) {
            $usuario = DB::table('usuarios')->where('id_usuario', $recibo->id_usuario)->first();
            $usuarioNombre = (string) ($usuario->usuario ?? ($usuario->nombre ?? ''));
        }
        // Totales por categoría de método
        $totales = [
            'EFECTIVO' => 0.0,
            'TARJETA' => 0.0,
            'CHEQUE' => 0.0,
            'DEPOSITO' => 0.0,
            'TRANSFERENCIA' => 0.0,
            'OTRO' => 0.0,
        ];
        $detalles = []; $observaciones = [];
        foreach ($cobros as $c) {
            $c = (object)$c;
            $obs = trim((string)($c->observaciones ?? ''));
            $isArr = stripos($obs, 'ARRASTRE') !== false;
            $obsClean = $obs !== '' ? trim(preg_replace('/\|?\s*\[\s*REZAGADO\s*\]\s*.+$/i', '', preg_replace('/\[\s*ARRASTRE\s*\]/i', '', $obs))) : '';
            if ($obsClean !== '') $observaciones[] = $obsClean;
            // detectar etiqueta
            if (!empty($c->id_item)) {
                try { $it = DB::table('items_cobro')->where('id_item', $c->id_item)->first(); $detalles[] = ($it && isset($it->nombre_servicio)) ? (string)$it->nombre_servicio : ('Item ' . $c->id_item); }
                catch (\Throwable $e) { $detalles[] = 'Item ' . $c->id_item; }
            } else {
                $numCuota = null;
                try {
                    if (!empty($c->id_asignacion_costo)) {
                        $asig = DB::table('asignacion_costos')->where('id_asignacion_costo', $c->id_asignacion_costo)->first();
                        if ($asig && isset($asig->numero_cuota)) $numCuota = (int)$asig->numero_cuota;
                    } elseif (!empty($c->id_cuota)) {
                        $asig = DB::table('asignacion_costos')->where('id_cuota_template', $c->id_cuota)->first();
                        if ($asig && isset($asig->numero_cuota)) $numCuota = (int)$asig->numero_cuota;
                    }
                } catch (\Throwable $e) {}
                $detalles[] = (($isArr ? 'Mensualidad (Arrastre)' : 'Mensualidad') . ($numCuota ? (' - Cuota ' . $numCuota) : ''));
            }
            // clasificar forma en categoría específica
            $forma = null;
            try { $forma = DB::table('formas_cobro')->where('id_forma_cobro', $c->id_forma_cobro)->first(); } catch (\Throwable $e) {}
            $raw = strtoupper(trim((string)($forma->nombre ?? $forma->descripcion ?? $forma->label ?? '')));
            $norm = iconv('UTF-8','ASCII//TRANSLIT',$raw);
            $code = strtoupper((string)($forma->id_forma_cobro ?? ''));
            $cat = 'OTRO';
            if (strpos($norm,'EFECTIVO') !== false) { $cat = 'EFECTIVO'; }
            elseif (strpos($norm,'TARJETA') !== false) { $cat = 'TARJETA'; }
            elseif (strpos($norm,'CHEQUE') !== false) { $cat = 'CHEQUE'; }
            elseif (strpos($norm,'DEPOSITO') !== false) { $cat = 'DEPOSITO'; }
            elseif (strpos($norm,'TRANSFER') !== false) { $cat = 'TRANSFERENCIA'; }
            elseif ($code === 'O') { $cat = 'OTRO'; }
            $totales[$cat] += (float)($c->monto ?? 0);
        }
        $detalle = $this->buildDetalleConMontos($cobros);
        $obsLinea = implode(' | ', array_unique(array_filter($observaciones)));
        $carrera = (string)($extras['carrera'] ?? '');
        $logo = (string)($extras['logo'] ?? '');
        $logoHtml = $logo ? ('<img src="' . $logo . '" width="60" height="60" />') : '';
        // Construir filas dinámicas Método/Monto
        $order = ['EFECTIVO','TARJETA','CHEQUE','DEPOSITO','TRANSFERENCIA','OTRO'];
        $rows = '';
        foreach ($order as $k) {
            $val = (float)($totales[$k] ?? 0);
            if ($val > 0) {
                $rows .= '<tr><td>'.$k.'</td><td class="right">'.number_format($val,2,'.','').'</td></tr>';
            }
        }

        $html = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8" />
    <style>
        @page { size: 8.5in 5.5in; margin: 3mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 8.7pt; line-height: 1.12; }
        .right { text-align:right; }
        .small { font-size: 8pt; color: #333; }
        .tabla { width:100%; border-collapse: collapse; page-break-inside: avoid; }
        .tabla th, .tabla td { border: 1px solid #000; padding: 2px; }
        .sinborde td { border: none; }
        .label { background:#C8C8C8; font-weight:bold; }
        .separador { border-bottom:2px dotted #000; margin: 4px 0; }
    </style>
    <title>Recibo {$recibo->nro_recibo}/{$recibo->anio}</title>
    </head>
    <body>
        <table class="sinborde" style="width:100%">
            <tr>
                <td style="width:20%; text-align:center; vertical-align:top">{$logoHtml}</td>
                <td style="width:80%; text-align:center; vertical-align:top">
                    <div style="font-size:13pt; color:black; font-weight:bold;">Instituto Tecnológico de Enseñanza Automotriz CETA S.R.L.</div>
                    <div style="font-size:11pt; color:black; font-weight:bold;">Carrera: {$carrera}</div>
                    <div style="font-size:12pt; color:#1E2768; font-weight:bold; border-top:2px solid #000; margin-top:3px; padding-top:3px;">NOTA PAGO COMBINADO</div>
                </td>
            </tr>
        </table>
        <table class="sinborde" style="width:100%; margin-top:2px">
            <tr>
                <td style="width:60%"></td>
                <td class="right" style="width:40%">
                    N° E-{$extras['e_numero']}<br>
                    {$fechaLiteral}
                </td>
            </tr>
        </table>
        <table class="tabla" style="margin-top:4px">
            <tr>
                <td class="label" style="width:20%">&nbsp;Estudiante:</td>
                <td style="width:60%">{$nombre}</td>
                <td class="right" style="width:20%; font-weight:bold">{$totalFmt}</td>
            </tr>
            <tr>
                <td class="label">&nbsp;Código CETA:</td>
                <td colspan="2">{$recibo->cod_ceta}</td>
            </tr>
        </table>
        <table class="sinborde" style="width:100%; margin-top:3px">
            <tr>
                <td style="width:70%">
                    <div><span style="font-weight:bold; color:#0B2161">MONTO:</span></div>
                    <div><span style="font-weight:bold; color:#0B2161">Literal:</span> {$literal}</div>
                    <div><span style="font-weight:bold; color:#0B2161">Detalle:</span> {$detalle}</div>
                    <div><span style="font-weight:bold; color:#0B2161">Observacion:</span> {$obsLinea}</div>
                </td>
                <td class="right" style="width:30%; vertical-align:top"><div style="font-weight:bold">{$totalFmt}</div></td>
            </tr>
        </table>
        <div class="separador"></div>
        <table class="tabla" style="margin-top:4px">
            <thead>
                <tr><th>MÉTODO</th><th class="right">MONTO</th></tr>
            </thead>
            <tbody>
                {$rows}
            </tbody>
        </table>
        <div class="right" style="margin-top:6px; font-weight:bold">Bs. {$totalFmt}</div>
        <table class="sinborde" style="width:100%; margin-top:3px">
            <tr>
                <td class="small" style="border-top:1px solid #000; padding-top:3px">&nbsp;</td>
                <td class="small right" style="border-top:1px solid #000; padding-top:3px">usuario: {$usuarioNombre}</td>
            </tr>
        </table>
    </body>
    </html>
HTML;
	return $html;
}

	private function renderHtml(
		object $recibo,
		array $cobros,
		?object $est,
		array $extras = []
	): string
	{
		$fechaDT = new \DateTime('now', new \DateTimeZone('America/La_Paz'));
		$fechaLiteral = $this->fechaLiteral($fechaDT);
		$total = (float)($recibo->monto_total ?? 0);
		$totalFmt = number_format($total, 2, '.', '');
		$literal = $this->numToLiteral($total);
		$nombre = '';
		if ($est) {
			$nombre = trim(implode(' ', array_filter([
				$est->nombres ?? '',
				$est->ap_paterno ?? '',
				$est->ap_materno ?? ''
			])));
		}
		// Datos de usuario para footer
		$usuario = null;
		$usuarioNombre = '';
		if (isset($recibo->id_usuario)) {
			$usuario = DB::table('usuarios')->where('id_usuario', $recibo->id_usuario)->first();
			$usuarioNombre = (string) ($usuario->usuario ?? ($usuario->nombre ?? ''));
		}
		// Construir observaciones (texto libre)
		$observaciones = [];
		foreach ($cobros as $c) {
			$c = (object) $c;
			$obs = trim((string) ($c->observaciones ?? ''));
			$obsClean = $obs !== ''
				? trim(preg_replace('/\|?\s*\[\s*REZAGADO\s*\]\s*.+$/i', '', preg_replace('/\[\s*ARRASTRE\s*\]/i', '', $obs)))
				: '';
			if ($obsClean !== '') {
				$observaciones[] = $obsClean;
			}
		}
		$detalle = $this->buildDetalleConMontos($cobros);
		$obsLinea = implode(' | ', array_unique(array_filter($observaciones)));

		$carrera = (string)($extras['carrera'] ?? '');
		$logo = (string)($extras['logo'] ?? '');
		$logoHtml = $logo ? ('<img src="' . $logo . '" width="60" height="60" />') : '';

    $html = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8" />
    <style>
        @page { size: 8.5in 5.5in; margin: 3mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 8.7pt; line-height: 1.12; }
        .encabezado { text-align:center; font-weight:bold; }
        .titulo { color:#1E2768; font-size: 10.5pt; font-weight:bold; margin-top: 0; text-align:center; }
        .right { text-align:right; }
        .small { font-size: 8pt; color: #333; }
        .tabla { width:100%; border-collapse: collapse; page-break-inside: avoid; }
        .tabla tr, .tabla td, .tabla th { page-break-inside: avoid; }
        .tabla th, .tabla td { border: 1px solid #000; padding: 2px; }
        .sinborde td { border: none; }
        .label { background:#C8C8C8; font-weight:bold; }
        .firma { border-top:1px solid #000; padding-top:3px; }
        .separador { border-bottom:2px dotted #000; margin: 4px 0; }
    </style>
    <title>Recibo {$recibo->nro_recibo}/{$recibo->anio}</title>
    </head>
    <body>
        <table class="sinborde" style="width:100%">
            <tr>
                <td style="width:20%; text-align:center; vertical-align:top">{$logoHtml}</td>
                <td style="width:80%; text-align:center; vertical-align:top">
                    <div style="font-size:13pt; color:black; font-weight:bold;">Instituto Tecnológico de Enseñanza Automotriz CETA S.R.L.</div>
                    <div style="font-size:11pt; color:black; font-weight:bold; border-bottom:2px solid #000; padding-bottom:3px;">Carrera: {$carrera}</div>
                </td>
            </tr>
        </table>
        <div class="titulo">NOTA DE REPOSICIÓN</div>
        <table class="sinborde" style="width:100%; margin-top:2px">
            <tr>
                <td style="width:60%"></td>
                <td class="right" style="width:40%">
                    ING-1<br>
                    N° E-{$extras['e_numero']}<br>
                    {$fechaLiteral}
                </td>
            </tr>
        </table>

        <table class="tabla" style="margin-top:4px">
            <tr>
                <td class="label" style="width:20%">&nbsp;Estudiante:</td>
                <td style="width:80%">{$nombre}</td>
            </tr>
            <tr>
                <td class="label">&nbsp;Código CETA:</td>
                <td>{$recibo->cod_ceta}</td>
            </tr>
        </table>

        <table class="sinborde" style="width:100%; margin-top:3px">
            <tr>
                <td style="width:70%">
                    <div style="color:#0B2161; font-weight:bold">MONTO:</div>
                    <div><span style="color:#0B2161; font-weight:bold">Literal:</span> {$literal}</div>
                    <div><span style="color:#0B2161; font-weight:bold">Detalle:</span> {$detalle}</div>
                    <div><span style="color:#0B2161; font-weight:bold">Observación:</span> {$obsLinea}</div>
                    <div><span style="color:#0B2161; font-weight:bold">Recibo:</span> {$recibo->nro_recibo}</div>
                </td>
                <td class="right" style="width:30%; vertical-align:top"><div style="font-weight:bold">{$totalFmt}</div></td>
            </tr>
        </table>

        <table class="sinborde" style="width:100%; margin-top:2px">
            <tr>
                <td style="width:60%"></td>
                <td style="width:40%" class="firma">{$usuarioNombre} - Firma:</td>
            </tr>
        </table>
        <div class="separador"></div>

        <div class="right" style="margin-top:6px; font-weight:bold">Bs. {$totalFmt}</div>

        <table class="sinborde" style="width:100%; margin-top:3px">
            <tr>
                <td style="width:60%; vertical-align:top">
                    <div><span style="font-weight:bold">Estudiante:</span> {$nombre}</div>
                    <div><span style="font-weight:bold">Código CETA:</span> {$recibo->cod_ceta}</div>
                    <div><span style="font-weight:bold">Detalle:</span> {$detalle}</div>
                    <div><span style="font-weight:bold">Observación:</span> {$obsLinea}</div>
                </td>
                <td class="right" style="width:40%">
                    N° E-{$extras['e_numero']}<br>
                    {$fechaLiteral}
                </td>
            </tr>
        </table>

        <table class="sinborde" style="width:100%; margin-top:3px">
            <tr>
                <td class="small" style="border-top:1px solid #000; padding-top:3px">&nbsp;</td>
                <td class="small right" style="border-top:1px solid #000; padding-top:3px">usuario: {$usuarioNombre}</td>
            </tr>
        </table>
    </body>
    </html>
HTML;
        return $html;
    }
}
