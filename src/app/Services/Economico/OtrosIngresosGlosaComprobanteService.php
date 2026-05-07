<?php

namespace App\Services\Economico;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use stdClass;

/**
 * Texto de glosa de comprobante / transacción alineado con SGA
 * {@see C_transaccion::regComprobanteOtrosIngresosNoAcademicos} (tipos OT, Fotocopiadora, Alquileres, Tienda, Varios, Anulado)
 * y apéndice de depósito bancario cuando la forma de cobro es depósito.
 */
class OtrosIngresosGlosaComprobanteService
{
	/**
	 * Clase lógica de tipo (mismas etiquetas que el `switch` de SGA sobre `tipo_ingreso` string).
	 */
	public function claseTipoIngreso(?string $cod, string $nom): string
	{
		$c = strtoupper(trim((string) $cod));
		$n = mb_strtolower(trim($nom), 'UTF-8');

		if ($c === 'ANU' || str_contains($n, 'anulad')) {
			return 'Anulado';
		}
		if ($c === 'OT' || (str_contains($n, 'orden') && str_contains($n, 'trabaj'))) {
			return 'OT';
		}
		if ($c === 'FOT' || str_contains($n, 'fotocop')) {
			return 'Fotocopiadora';
		}
		if ($c === 'ALQ' || str_contains($n, 'alquiler')) {
			return 'Alquileres';
		}
		if ($c === 'TDA' || $c === 'TIE' || str_contains($n, 'tienda')) {
			return 'Tienda';
		}
		if ($c === 'VAR' || str_contains($n, 'varios')) {
			return 'Varios';
		}

		return 'Varios';
	}

	/**
	 * "Fact Nro: x" / "Recibo: y" como en SGA.
	 */
	public function facturaOReciboTexto(string $fr, int $nroFactura, int $nroRecibo): string
	{
		if (strtoupper($fr) === 'F' && $nroFactura > 0) {
			return 'Fact Nro: '.$nroFactura;
		}
		if (strtoupper($fr) === 'R' && $nroRecibo > 0) {
			return 'Recibo: '.$nroRecibo;
		}
		if ($nroFactura > 0) {
			return 'Fact Nro: '.$nroFactura;
		}
		if ($nroRecibo > 0) {
			return 'Recibo: '.$nroRecibo;
		}

		return 'S/N';
	}

	/**
	 * @param  array<string,mixed>  $input  Payload de registro (mismas claves que `OtrosIngresosService::registrar`)
	 */
	public function construirDesdeInput(
		array $input,
		string $usuarioNick,
		string $fr,
		int $numFactura,
		int $numRecibo,
		string $gestion,
	): string {
		$cod = (string) ($input['cod_tipo_ingreso'] ?? '');
		$nom = (string) ($input['tipo_ingreso_text'] ?? ($input['tipo_ingreso'] ?? ''));
		$clase = $this->claseTipoIngreso($cod, $nom);
		$docRef = $this->facturaOReciboTexto($fr, $numFactura, $numRecibo);
		$obs = trim((string) ($input['observacion'] ?? ($input['observaciones'] ?? '')));

		$fi = $this->normalizarDmyTexto($input['fecha_ini'] ?? '');
		$ff = $this->normalizarDmyTexto($input['fecha_fin'] ?? '');
		$nroOrden = (int) ($input['nro_orden'] ?? 0);
		$conceptoAlq = trim((string) ($input['concepto_alq'] ?? ''));

		$glosa = match ($clase) {
			'Anulado' => 'Anulación u origen de factura (no académico).',
			'OT' => 'Ingreso no académico Orden de Trabajo'
				.($nroOrden > 0 ? ' Nº '.$nroOrden : '')
				.'. '.$docRef.'.',
			'Fotocopiadora' => 'Fotocopiadora, entregas del '.$fi.' al '.$ff.'. '.$docRef.'.',
			'Alquileres' => 'Alquiler, periodo '
				.($conceptoAlq !== '' ? $conceptoAlq : '—')
				.', gestión '.trim($gestion).', año '.date('Y').'. '.$docRef.'.',
			'Tienda' => 'Tienda, entregas del '.$fi.' al '.$ff.'. '.$docRef.'.',
			'Varios' => 'Ingreso no académico varios. '.($obs !== '' ? $obs : 'sin detalle adicional.').' '.$docRef.'.',
			default => 'Ingreso no académico. '.$docRef.'.',
		};

		if ($clase !== 'Anulado' && $clase !== 'Varios' && $obs !== '') {
			$glosa .= '; '.$obs;
		}

		$tipoPago = (string) ($input['tipo_pago'] ?? ($input['code_tipo_pago'] ?? ''));
		$nombreFc = trim((string) ($input['__nombre_forma_cobro__'] ?? ''));
		if ($nombreFc === '') {
			$nombreFc = $this->nombreFormaCobro($tipoPago);
		}
		if (OtrosIngresosService::inferirFlujoFormaCobroOtrosIngresos($tipoPago, $nombreFc) === 'deposito') {
			$ctaRaw = (string) ($input['cta_banco'] ?? '');
			$ctaNum = $this->parseCuentaDesdeCtaBanco($ctaRaw);
			$nomBanco = $this->parseNombreBancoDesdeCtaBanco($ctaRaw);
			$fd = trim((string) ($input['fecha_deposito'] ?? ''));
			$glosa .= ' Depósito en cuenta '
				.($nomBanco !== '' ? $nomBanco : 'Banco')
				.($ctaNum !== '' ? ' - '.$ctaNum : '')
				.($fd !== '' ? ' en fecha '.$fd : '');
		}

		return trim(preg_replace('/\s+/', ' ', $glosa) ?? $glosa);
	}

	/**
	 * Reconstruye la glosa desde una fila de `otros_ingresos` (+ detalle) para libros o datos históricos.
	 */
	public function construirDesdeFila(stdClass $o): string
	{
		$fr = (string) ($o->factura_recibo ?? 'F');
		$nf = (int) ($o->num_factura ?? 0);
		$nr = (int) ($o->num_recibo ?? 0);
		$gestion = (string) ($o->gestion ?? '');
		$usuario = (string) ($o->usuario ?? '');

		$fdDep = '';
		if (isset($o->fecha_deposito) && $o->fecha_deposito) {
			if (is_string($o->fecha_deposito) && preg_match('/\d{1,2}\/\d{1,2}\/\d{4}/', (string) $o->fecha_deposito)) {
				$fdDep = trim((string) $o->fecha_deposito);
			} else {
				$fdDep = $this->fechaDetalleADmy($o->fecha_deposito);
			}
		}

		$input = [
			'cod_tipo_ingreso' => (string) ($o->cod_tipo_ingreso ?? ''),
			'tipo_ingreso_text' => (string) ($o->tipo_ingreso ?? ''),
			'observaciones' => (string) ($o->observaciones ?? ''),
			'nro_orden' => (int) ($o->nro_orden ?? 0),
			'concepto_alq' => $this->extraerAnaliticoAlquiler($o->concepto_alquiler ?? null),
			'fecha_ini' => $this->normalizarDmyTexto($o->fecha_ini ?? null),
			'fecha_fin' => $this->normalizarDmyTexto($o->fecha_fin ?? null),
			'code_tipo_pago' => (string) ($o->code_tipo_pago ?? ''),
			'tipo_pago' => (string) ($o->code_tipo_pago ?? ''),
			'cta_banco' => (string) ($o->cta_banco ?? ''),
			'fecha_deposito' => $fdDep,
		];
		$nfc = trim((string) ($o->forma_cobro_nombre ?? ''));
		if ($nfc !== '') {
			$input['__nombre_forma_cobro__'] = $nfc;
		}

		$g = $this->construirDesdeInput($input, $usuario, $fr, $nf, $nr, $gestion);

		return trim(preg_replace('/\s+/', ' ', $g) ?? $g);
	}

	/** d/m/Y o vacío */
	private function normalizarDmyTexto(mixed $v): string
	{
		if ($v === null || $v === '') {
			return '—';
		}
		if (is_string($v) && preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', trim($v))) {
			return trim($v);
		}
		try {
			$c = $v instanceof Carbon ? $v : Carbon::parse((string) $v);
			$x = $c->format('d/m/Y');
			// Evitar 01/01/1970 por defecto raro
			if (str_starts_with($x, '01/01/197')) {
				return '—';
			}

			return $x;
		} catch (\Throwable) {
			return '—';
		}
	}

	private function fechaDetalleADmy(mixed $v): string
	{
		if ($v === null || $v === '') {
			return '';
		}
		try {
			return Carbon::parse($v)->format('d/m/Y');
		} catch (\Throwable) {
			return '';
		}
	}

	private function extraerAnaliticoAlquiler(mixed $raw): string
	{
		$s = trim((string) $raw);
		if ($s === '') {
			return '';
		}
		if (preg_match('/^Alquiler mes\s+(.+)$/iu', $s, $m)) {
			return trim($m[1]);
		}
		if (preg_match('/\s*Nro\.\d+\s*$/', $s)) {
			return trim(preg_replace('/\s*Nro\.\d+\s*$/', '', $s) ?? $s);
		}

		return $s;
	}

	private function nombreFormaCobro(string $id): string
	{
		$id = trim($id);
		if ($id === '' || !Schema::hasTable('formas_cobro')) {
			return '';
		}
		$r = DB::table('formas_cobro')->where('id_forma_cobro', $id)->value('nombre');

		return (string) ($r ?? '');
	}

	private function parseNombreBancoDesdeCtaBanco(string $ctaRaw): string
	{
		$ctaRaw = trim($ctaRaw);
		if ($ctaRaw === '') {
			return '';
		}
		if (str_contains($ctaRaw, '::')) {
			return trim(explode('::', $ctaRaw, 2)[0] ?? '');
		}
		if (str_contains($ctaRaw, '-')) {
			return trim(explode('-', $ctaRaw, 2)[0] ?? '');
		}

		return '';
	}

	private function parseCuentaDesdeCtaBanco(string $ctaRaw): string
	{
		$ctaRaw = trim($ctaRaw);
		if ($ctaRaw === '') {
			return '';
		}
		if (str_contains($ctaRaw, '::')) {
			$p = explode('::', $ctaRaw, 2);

			return trim($p[1] ?? '');
		}
		if (str_contains($ctaRaw, '-')) {
			$p = explode('-', $ctaRaw, 2);

			return trim($p[1] ?? '');
		}

		return '';
	}
}
