<?php

namespace App\Services\Reportes;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega el Libro Diario desde 5 fuentes (cobro, factura, qr_transacciones, recibo, otros_ingresos)
 * y devuelve items canónicos listos para la UI y el PDF.
 *
 * Reglas:
 * - Dedupe: si una factura está enlazada a un cobro, solo se incluye el cobro (evita doble conteo).
 * - `otros_ingresos`: se filtra por nickname del usuario (la tabla no guarda id_usuario).
 * - `valido='N'` (anulado) se excluye; `valido='A'` se preserva y se reporta como ingreso 0 opcionalmente.
 * - Las observaciones extendidas (banco/nro/fecha/correlativo) se arman en esta capa para el PDF.
 */
class LibroDiarioAggregatorService
{
	/**
	 * @param array{id_usuario?:int|string,fecha_inicio?:string,fecha_fin?:string,codigo_carrera?:string,usuario_display?:string} $filters
	 * @return array{datos:array<int,array<string,mixed>>,totales:array{ingresos:float,egresos:float},usuario_info:array{nombre:string,hora_apertura:string,hora_cierre:string},resumen:array<string,mixed>}
	 */
	public function build(array $filters): array
	{
		$idUsuario = (int) ($filters['id_usuario'] ?? 0);
		$desde = $this->normFecha($filters['fecha_inicio'] ?? '');
		$hasta = $this->normFecha($filters['fecha_fin'] ?? ($filters['fecha_inicio'] ?? ''));
		$codigoCarrera = trim((string) ($filters['codigo_carrera'] ?? ''));

		if ($idUsuario <= 0 || $desde === '' || $hasta === '') {
			return $this->respuestaVacia((string) ($filters['usuario_display'] ?? ''));
		}

		$nickname = $this->resolverNicknameUsuario($idUsuario);

		$cobros = $this->cargarCobros($idUsuario, $desde, $hasta, $codigoCarrera);
		$facturas = $this->cargarFacturas($idUsuario, $desde, $hasta);
		$qrs = $this->cargarQrs($idUsuario, $desde, $hasta, $codigoCarrera);
		$recibosMap = $this->cargarRecibosMap($cobros);
		$otros = $this->cargarOtrosIngresos($nickname, $desde, $hasta, $codigoCarrera);

		$mapaFacturas = [];
		$mapaMontosFactura = [];
		foreach ($facturas as $f) {
			$nro = (string) ($f->nro_factura ?? '');
			if ($nro === '' || $nro === '0') {
				continue;
			}
			$anioF = (int) ($f->anio ?? 0);
			$claveFac = $anioF > 0 ? ($anioF . ':' . $nro) : $nro;
			$mapaFacturas[$claveFac] = [
				'razon_social' => (string) ($f->cliente ?? ''),
				'nit' => (string) ($f->nro_documento_cobro ?? ($f->nit ?? '0')),
			];
			$monto = (float) ($f->monto_total ?? 0);
			if ($monto > 0) {
				$mapaMontosFactura[$claveFac] = $monto;
			}
		}

		$facturasConCobro = [];
		foreach ($cobros as $c) {
			$nroFac = (string) ($c->nro_factura ?? '0');
			if ($nroFac !== '' && $nroFac !== '0') {
				$anioC = (int) ($c->anio_cobro ?? 0);
				$facturasConCobro[$anioC > 0 ? ($anioC . ':' . $nroFac) : $nroFac] = true;
			}
		}

		$items = [];

		foreach ($facturas as $f) {
			$nroFac = (string) ($f->nro_factura ?? '0');
			if ($nroFac === '' || $nroFac === '0') {
				continue;
			}
			$anioF = (int) ($f->anio ?? 0);
			$claveFac = $anioF > 0 ? ($anioF . ':' . $nroFac) : $nroFac;
			if (isset($facturasConCobro[$claveFac]) || ($anioF <= 0 && isset($facturasConCobro[$nroFac]))) {
				continue;
			}
			$items[] = $this->mapearFactura($f, $mapaFacturas);
		}

		foreach ($cobros as $c) {
			$items[] = $this->mapearCobro($c, $mapaFacturas, $recibosMap, $mapaMontosFactura);
		}

		foreach ($qrs as $q) {
			$items[] = $this->mapearQr($q, $mapaFacturas);
		}

		foreach ($otros as $o) {
			$items[] = $this->mapearOtroIngreso($o);
		}

		usort($items, function ($a, $b) {
			$ha = (string) ($a['hora'] ?? '');
			$hb = (string) ($b['hora'] ?? '');
			if ($ha !== '' && $hb !== '') {
				return strcmp($ha, $hb);
			}
			if ($ha === '' && $hb !== '') {
				return 1;
			}
			if ($ha !== '' && $hb === '') {
				return -1;
			}
			return 0;
		});

		$numero = 0;
		$totalIngresos = 0.0;
		$totalEgresos = 0.0;
		foreach ($items as $i => $it) {
			$numero++;
			$items[$i]['numero'] = $numero;
			$totalIngresos += (float) $it['ingreso'];
			$totalEgresos += (float) $it['egreso'];
		}

		$horaApertura = '08:00:00';
		$horasValidas = array_values(array_filter(array_map(function ($x) {
			return trim((string) ($x['hora'] ?? ''));
		}, $items), function ($h) {
			return $h !== '';
		}));
		if (!empty($horasValidas)) {
			sort($horasValidas);
			$ha = $horasValidas[0];
			$horaApertura = preg_match('/^\d{1,2}:\d{2}$/', $ha) ? $ha . ':00' : $ha;
		}

		$nombreUsuario = (string) ($filters['usuario_display'] ?? $nickname);

		return [
			'datos' => $items,
			'totales' => [
				'ingresos' => round($totalIngresos, 2),
				'egresos' => round($totalEgresos, 2),
			],
			'usuario_info' => [
				'nombre' => $nombreUsuario,
				'hora_apertura' => $horaApertura,
				'hora_cierre' => '',
			],
			'resumen' => $this->construirResumenMetodosPago($items),
		];
	}

	private function resolverNicknameUsuario(int $idUsuario): string
	{
		if ($idUsuario <= 0) {
			return '';
		}
		$row = DB::table('usuarios')->where('id_usuario', $idUsuario)->first();
		if (!$row) {
			return '';
		}
		return (string) ($row->nickname ?? '');
	}

	/** @return \Illuminate\Support\Collection<int,object> */
	private function cargarCobros(int $idUsuario, string $desde, string $hasta, string $codigoCarrera)
	{
		// `recibo` y `factura` usan PK lógica (anio, nro_*). Unir solo por nro_* duplica filas
		// cuando el mismo correlativo existe en distintos años (caso típico del libro diario).
		$q = DB::table('cobro as c')
			->leftJoin('factura as f', function ($j) {
				$j->on('f.nro_factura', '=', 'c.nro_factura')
					->on('f.anio', '=', 'c.anio_cobro');
			})
			->leftJoin('recibo as r', function ($j) {
				$j->on('r.nro_recibo', '=', 'c.nro_recibo')
					->on('r.anio', '=', 'c.anio_cobro');
			})
			->leftJoin('formas_cobro as fc', 'fc.id_forma_cobro', '=', 'c.id_forma_cobro')
			->where('c.id_usuario', $idUsuario)
			->whereDate('c.fecha_cobro', '>=', $desde)
			->whereDate('c.fecha_cobro', '<=', $hasta);

		if ($codigoCarrera !== '') {
			$q->whereIn('c.cod_pensum', function ($sub) use ($codigoCarrera) {
				$sub->select('cod_pensum')->from('pensums')->where('codigo_carrera', $codigoCarrera);
			});
		}

		$rows = $q->select(
			'c.nro_cobro', 'c.anio_cobro', 'c.cod_ceta', 'c.cod_pensum', 'c.tipo_inscripcion',
			'c.nro_factura', 'c.nro_recibo', 'c.monto',
			'c.observaciones', 'c.concepto', 'c.fecha_cobro', 'c.id_forma_cobro',
			DB::raw('COALESCE(r.cliente, f.cliente) as cliente'),
			DB::raw('COALESCE(r.nro_documento_cobro, f.nro_documento_cobro) as nro_documento_cobro'),
			'fc.nombre as forma_cobro_nombre',
			'f.monto_total as factura_monto_total'
		)->get();

		$nros = $rows->pluck('nro_recibo')->filter(fn ($v) => $v !== null && $v !== '' && $v !== 0)->map(fn ($v) => (string) $v)->unique()->values();
		$nfs = $rows->pluck('nro_factura')->filter(fn ($v) => $v !== null && $v !== '' && $v !== 0)->map(fn ($v) => (string) $v)->unique()->values();

		$nbByRec = [];
		$nbByFac = [];
		if (Schema::hasTable('nota_bancaria') && ($nros->count() || $nfs->count())) {
			$nbRows = DB::table('nota_bancaria')
				->when($nros->count(), fn ($qq) => $qq->whereIn('nro_recibo', $nros->all()))
				->when($nfs->count(), fn ($qq) => $qq->orWhereIn('nro_factura', $nfs->all()))
				->orderBy('fecha_nota', 'desc')
				->get();
			foreach ($nbRows as $nb) {
				$kR = (string) ($nb->nro_recibo ?? '');
				if ($kR !== '' && !isset($nbByRec[$kR])) {
					$nbByRec[$kR] = $nb;
				}
				$kF = (string) ($nb->nro_factura ?? '');
				if ($kF !== '' && !isset($nbByFac[$kF])) {
					$nbByFac[$kF] = $nb;
				}
			}
		}

		return $rows->map(function ($r) use ($nbByRec, $nbByFac) {
			$kR = (string) ($r->nro_recibo ?? '');
			$kF = (string) ($r->nro_factura ?? '');
			$nb = null;
			if ($kR !== '' && isset($nbByRec[$kR])) {
				$nb = $nbByRec[$kR];
			} elseif ($kF !== '' && isset($nbByFac[$kF])) {
				$nb = $nbByFac[$kF];
			}
			if ($nb) {
				$r->banco_nb = $nb->banco ?? null;
				$r->nro_transaccion = $nb->nro_transaccion ?? null;
				$r->fecha_deposito = $nb->fecha_deposito ?? null;
				$r->fecha_nota = $nb->fecha_nota ?? null;
				$r->correlativo_nb = $nb->correlativo ?? null;
			}
			return $r;
		});
	}

	/** @return \Illuminate\Support\Collection<int,object> */
	private function cargarFacturas(int $idUsuario, string $desde, string $hasta)
	{
		return DB::table('factura')
			->where('id_usuario', $idUsuario)
			->whereBetween('fecha_emision', [$desde . ' 00:00:00', $hasta . ' 23:59:59'])
			->where('nro_factura', '>', 0)
			->select(
				'anio', 'nro_factura', 'cliente', 'nro_documento_cobro', 'cod_ceta',
				'id_forma_cobro', 'monto_total', 'fecha_emision', 'estado'
			)
			->get();
	}

	/** @return \Illuminate\Support\Collection<int,object> */
	private function cargarQrs(int $idUsuario, string $desde, string $hasta, string $codigoCarrera)
	{
		$q = DB::table('qr_transacciones')
			->where('id_usuario', $idUsuario)
			->whereDate('fecha_generacion', '>=', $desde)
			->whereDate('fecha_generacion', '<=', $hasta);

		if ($codigoCarrera !== '') {
			$q->whereIn('cod_pensum', function ($sub) use ($codigoCarrera) {
				$sub->select('cod_pensum')->from('pensums')->where('codigo_carrera', $codigoCarrera);
			});
		}

		return $q->get();
	}

	/**
	 * Mapa recibo por par año+número (evita mezclar correlativos de distintos ejercicios).
	 *
	 * @return array<string,object> clave "anio:nro_recibo"
	 */
	private function cargarRecibosMap($cobros): array
	{
		$uniq = [];
		foreach ($cobros as $c) {
			$nr = $c->nro_recibo ?? null;
			$an = $c->anio_cobro ?? null;
			if ($nr === null || $nr === '' || (int) $nr === 0 || $an === null || (int) $an === 0) {
				continue;
			}
			$uniq[(int) $an . ':' . (int) $nr] = [(int) $an, (int) $nr];
		}
		if ($uniq === []) {
			return [];
		}
		$pairs = array_values($uniq);
		$rows = DB::table('recibo')->where(function ($qq) use ($pairs) {
			foreach ($pairs as [$anio, $nro]) {
				$qq->orWhere(function ($q2) use ($anio, $nro) {
					$q2->where('anio', $anio)->where('nro_recibo', $nro);
				});
			}
		})->select('nro_recibo', 'anio', 'cliente', 'nro_documento_cobro')->get();

		$map = [];
		foreach ($rows as $row) {
			$map[(int) $row->anio . ':' . (int) $row->nro_recibo] = $row;
		}
		return $map;
	}

	/** @return \Illuminate\Support\Collection<int,object> */
	private function cargarOtrosIngresos(string $nickname, string $desde, string $hasta, string $codigoCarrera)
	{
		if (!Schema::hasTable('otros_ingresos')) {
			return collect();
		}
		if ($nickname === '') {
			return collect();
		}

		$q = DB::table('otros_ingresos as oi')
			->leftJoin('otros_ingresos_detalle as d', 'd.otro_ingreso_id', '=', 'oi.id')
			->leftJoin('formas_cobro as fc', 'fc.id_forma_cobro', '=', 'oi.code_tipo_pago')
			->whereRaw('LOWER(TRIM(oi.usuario)) = ?', [mb_strtolower(trim($nickname))])
			->whereDate('oi.fecha', '>=', $desde)
			->whereDate('oi.fecha', '<=', $hasta)
			->whereIn('oi.valido', ['S', 'A']);

		if ($codigoCarrera !== '' && Schema::hasColumn('otros_ingresos', 'codigo_carrera')) {
			$q->where('oi.codigo_carrera', $codigoCarrera);
		}

		return $q->select(
			'oi.id', 'oi.num_factura', 'oi.num_recibo', 'oi.nit', 'oi.razon_social',
			'oi.fecha', 'oi.monto', 'oi.concepto', 'oi.observaciones', 'oi.valido',
			'oi.code_tipo_pago', 'oi.factura_recibo', 'oi.tipo_ingreso',
			'fc.nombre as forma_cobro_nombre',
			'd.cta_banco', 'd.nro_deposito', 'd.fecha_deposito'
		)->get();
	}

	/**
	 * @param object $f
	 * @param array<string,array{razon_social:string,nit:string}> $mapaFacturas
	 * @return array<string,mixed>
	 */
	private function mapearFactura($f, array $mapaFacturas): array
	{
		$nroFac = (string) ($f->nro_factura ?? '0');
		$anioF = (int) ($f->anio ?? 0);
		$claveFac = ($anioF > 0 && $nroFac !== '' && $nroFac !== '0') ? ($anioF . ':' . $nroFac) : $nroFac;
		$dc = $mapaFacturas[$claveFac] ?? ($mapaFacturas[$nroFac] ?? [
			'razon_social' => (string) ($f->cliente ?? ''),
			'nit' => (string) ($f->nro_documento_cobro ?? '0'),
		]);

		return [
			'numero' => 0,
			'recibo' => '0',
			'factura' => $nroFac !== '' ? $nroFac : '0',
			'concepto' => 'Factura',
			'razon' => $dc['razon_social'] ?: 'SIN DATOS',
			'nit' => $dc['nit'] ?: '0',
			'cod_ceta' => (string) ($f->cod_ceta ?? '0'),
			'hora' => $this->horaLocal((string) ($f->fecha_emision ?? '')),
			'ingreso' => (float) ($f->monto_total ?? 0),
			'egreso' => 0.0,
			'tipo_doc' => 'F',
			'tipo_pago' => $this->mapearTipoPago((string) ($f->id_forma_cobro ?? '')),
			'observaciones' => (string) ($f->id_forma_cobro ?? 'Efectivo'),
		];
	}

	/**
	 * @param object $c
	 * @param array<string,array{razon_social:string,nit:string}> $mapaFacturas
	 * @param array<string,object> $recibosMap
	 * @param array<string,float> $mapaMontosFactura
	 * @return array<string,mixed>
	 */
	private function mapearCobro($c, array $mapaFacturas, array $recibosMap, array $mapaMontosFactura): array
	{
		$nroFac = (string) ($c->nro_factura ?? '0');
		$nroRec = (string) ($c->nro_recibo ?? '0');
		$anioC = (int) ($c->anio_cobro ?? 0);
		$claveFac = ($nroFac !== '' && $nroFac !== '0' && $anioC > 0) ? ($anioC . ':' . $nroFac) : $nroFac;
		$claveRec = ($nroRec !== '' && $nroRec !== '0' && $anioC > 0) ? ($anioC . ':' . $nroRec) : $nroRec;

		$razon = '';
		$nit = '';
		if ($nroFac !== '' && $nroFac !== '0') {
			if (isset($mapaFacturas[$claveFac])) {
				$razon = $mapaFacturas[$claveFac]['razon_social'];
				$nit = $mapaFacturas[$claveFac]['nit'];
			} elseif (isset($mapaFacturas[$nroFac])) {
				$razon = $mapaFacturas[$nroFac]['razon_social'];
				$nit = $mapaFacturas[$nroFac]['nit'];
			}
		}
		if ($razon === '' && $nroRec !== '' && $nroRec !== '0') {
			if (isset($recibosMap[$claveRec])) {
				$razon = (string) ($recibosMap[$claveRec]->cliente ?? '');
				$nit = (string) ($recibosMap[$claveRec]->nro_documento_cobro ?? '');
			} elseif (isset($recibosMap[$nroRec])) {
				$razon = (string) ($recibosMap[$nroRec]->cliente ?? '');
				$nit = (string) ($recibosMap[$nroRec]->nro_documento_cobro ?? '');
			}
		}
		if ($razon === '') {
			$razon = (string) ($c->cliente ?? '');
			$nit = (string) ($c->nro_documento_cobro ?? '');
		}

		$monto = (float) ($c->monto ?? 0);
		if ($monto <= 0 && $nroFac !== '' && $nroFac !== '0') {
			if (isset($mapaMontosFactura[$claveFac])) {
				$monto = (float) $mapaMontosFactura[$claveFac];
			} elseif (isset($mapaMontosFactura[$nroFac])) {
				$monto = (float) $mapaMontosFactura[$nroFac];
			}
		}
		if ($monto <= 0) {
			$monto = (float) ($c->factura_monto_total ?? 0);
		}

		$idForma = (string) ($c->id_forma_cobro ?? '');
		$tipoDoc = ($nroFac !== '' && $nroFac !== '0') ? 'F' : 'R';

		return [
			'numero' => 0,
			'recibo' => $nroRec !== '' ? $nroRec : '0',
			'factura' => $nroFac !== '' ? $nroFac : '0',
			'concepto' => (string) ($c->concepto ?? ($c->observaciones ?? 'Cobro')),
			'razon' => $razon,
			'nit' => $nit,
			'cod_ceta' => (string) ($c->cod_ceta ?? '0'),
			'hora' => $this->horaLocal((string) ($c->fecha_cobro ?? '')),
			'ingreso' => $monto,
			'egreso' => 0.0,
			'tipo_doc' => $tipoDoc,
			'tipo_pago' => $this->mapearTipoPago($idForma),
			'observaciones' => $this->armarObservacionesExtendidas($c),
		];
	}

	/**
	 * @param object $q
	 * @param array<string,array{razon_social:string,nit:string}> $mapaFacturas
	 * @return array<string,mixed>
	 */
	private function mapearQr($q, array $mapaFacturas): array
	{
		$nroFac = (string) ($q->nro_factura ?? '0');
		$anioQr = 0;
		if (!empty($q->fecha_generacion)) {
			try {
				$anioQr = (int) (new \DateTime((string) $q->fecha_generacion))->format('Y');
			} catch (\Throwable $e) {
				$anioQr = 0;
			}
		}
		$claveFac = ($anioQr > 0 && $nroFac !== '' && $nroFac !== '0') ? ($anioQr . ':' . $nroFac) : $nroFac;
		$dc = null;
		if ($nroFac !== '' && $nroFac !== '0') {
			if (isset($mapaFacturas[$claveFac])) {
				$dc = $mapaFacturas[$claveFac];
			} elseif (isset($mapaFacturas[$nroFac])) {
				$dc = $mapaFacturas[$nroFac];
			}
		}
		if ($dc === null) {
			$dc = [
				'razon_social' => (string) ($q->cliente ?? ($q->nombre_cliente ?? 'SIN DATOS')),
				'nit' => (string) ($q->nro_documento_cobro ?? ($q->nit ?? '0')),
			];
		}

		$metodo = strtoupper((string) ($q->metodo_pago ?? ''));
		$tipoPago = $metodo === 'TARJETA' ? 'L' : 'E';

		return [
			'numero' => 0,
			'recibo' => (string) ($q->id_qr_transaccion ?? '0'),
			'factura' => $nroFac !== '' ? $nroFac : '0',
			'concepto' => (string) ($q->detalle_glosa ?? 'Transacción QR'),
			'razon' => $dc['razon_social'] ?: 'SIN DATOS',
			'nit' => $dc['nit'] ?: '0',
			'cod_ceta' => (string) ($q->cod_ceta ?? '0'),
			'hora' => $this->horaLocal((string) ($q->fecha_generacion ?? '')),
			'ingreso' => (float) ($q->monto_total ?? ($q->monto ?? 0)),
			'egreso' => 0.0,
			'tipo_doc' => 'F',
			'tipo_pago' => $tipoPago,
			'observaciones' => $metodo !== '' ? $metodo : 'Efectivo',
		];
	}

	/**
	 * @param object $o
	 * @return array<string,mixed>
	 */
	private function mapearOtroIngreso($o): array
	{
		$fr = strtoupper((string) ($o->factura_recibo ?? 'F'));
		$tipoDoc = $fr === 'R' ? 'R' : 'F';
		$numFac = (int) ($o->num_factura ?? 0);
		$numRec = (int) ($o->num_recibo ?? 0);

		$ingreso = strtoupper((string) ($o->valido ?? 'S')) === 'A' ? 0.0 : (float) ($o->monto ?? 0);

		$codForma = (string) ($o->code_tipo_pago ?? '');
		$tipoPago = $this->mapearTipoPago($codForma);

		$obs = $this->armarObservacionesOtroIngreso($o);

		$concepto = trim((string) ($o->concepto ?? ''));
		if ($concepto === '') {
			$concepto = trim((string) ($o->tipo_ingreso ?? 'Otros Ingresos'));
		}

		return [
			'numero' => 0,
			'recibo' => $tipoDoc === 'R' ? (string) ($numRec > 0 ? $numRec : '0') : '0',
			'factura' => $tipoDoc === 'F' ? (string) ($numFac > 0 ? $numFac : '0') : '0',
			'concepto' => $concepto,
			'razon' => (string) ($o->razon_social ?? ''),
			'nit' => (string) ($o->nit ?? '0'),
			'cod_ceta' => '0',
			'hora' => $this->horaLocal((string) ($o->fecha ?? '')),
			'ingreso' => $ingreso,
			'egreso' => 0.0,
			'tipo_doc' => $tipoDoc,
			'tipo_pago' => $tipoPago,
			'observaciones' => $obs,
		];
	}

	private function mapearTipoPago(string $codigo): string
	{
		$c = strtoupper(trim($codigo));
		if ($c === '') {
			return 'E';
		}
		if ($c === 'E' || $c === 'EF' || str_contains($c, 'EFECT')) {
			return 'E';
		}
		if ($c === 'L' || $c === 'TA' || $c === 'TC' || str_contains($c, 'TARJ')) {
			return 'L';
		}
		if ($c === 'D' || $c === 'DE' || str_contains($c, 'DEPOS') || str_contains($c, 'DEPÓS')) {
			return 'D';
		}
		if ($c === 'C' || $c === 'CH' || str_contains($c, 'CHEQ')) {
			return 'C';
		}
		if ($c === 'B' || $c === 'TR' || str_contains($c, 'TRANS') || str_contains($c, 'BANC')) {
			return 'B';
		}
		if ($c === 'T' || str_contains($c, 'TRASP')) {
			return 'T';
		}
		return 'O';
	}

	private function armarObservacionesExtendidas($c): string
	{
		$idForma = strtoupper((string) ($c->id_forma_cobro ?? ''));
		$obsOriginal = trim((string) ($c->observaciones ?? ''));

		$codigo = $idForma;
		switch ($codigo) {
			case 'E':
				$codigo = 'EF';
				break;
			case 'T':
				$codigo = 'TA';
				break;
			case 'D':
				$codigo = 'DE';
				break;
			case 'C':
				$codigo = 'CH';
				break;
			case 'L':
			case 'TC':
				$codigo = 'TA';
				break;
			case 'B':
				$codigo = 'TR';
				break;
		}

		$banco = $this->bancoSoloNombre((string) ($c->banco_nb ?? ''));
		$nroTrx = (string) ($c->nro_transaccion ?? ($c->nro_deposito ?? ''));
		$fechaDep = (string) ($c->fecha_deposito ?? ($c->fecha_nota ?? ''));
		$correlativo = (string) ($c->correlativo_nb ?? '');

		$infoAdicional = '';

		switch ($codigo) {
			case 'TA':
				if ($banco !== '' && $nroTrx !== '' && $fechaDep !== '') {
					$infoAdicional = "Tarjeta: {$banco}-{$nroTrx}-{$fechaDep} NL:0";
				}
				break;
			case 'CH':
				$infoAdicional = "Cheque N°: " . ($nroTrx !== '' ? $nroTrx : 'N/A') . " - Banco: " . ($banco !== '' ? $banco : 'N/A');
				break;
			case 'DE':
				if ($banco !== '' && $nroTrx !== '' && $fechaDep !== '') {
					$cn = $correlativo !== '' ? preg_replace('/^N[BD][:\s]*/i', '', $correlativo) : '';
					$infoAdicional = $cn !== ''
						? "Deposito: {$banco}-{$nroTrx}-{$fechaDep} ND:{$cn}"
						: "Deposito: {$banco}-{$nroTrx}-{$fechaDep}";
				}
				break;
			case 'TR':
				if ($banco !== '' && $nroTrx !== '' && $fechaDep !== '') {
					$cn = $correlativo !== '' ? preg_replace('/^NB[:\s]*/i', '', $correlativo) : '';
					$infoAdicional = $cn !== ''
						? "Transferencia: {$banco}-{$nroTrx}-{$fechaDep} NB:{$cn}"
						: "Transferencia: {$banco}-{$nroTrx}-{$fechaDep}";
				}
				break;
		}

		if ($infoAdicional !== '') {
			return $obsOriginal !== '' ? ($infoAdicional . ' ' . $obsOriginal) : $infoAdicional;
		}

		$tipoPago = trim((string) ($c->forma_cobro_nombre ?? $idForma));
		if ($obsOriginal !== '') {
			if ($tipoPago !== '' && stripos($obsOriginal, $tipoPago) === 0) {
				return $obsOriginal;
			}
			return $tipoPago !== '' ? ($tipoPago . ': ' . $obsOriginal) : $obsOriginal;
		}

		return $tipoPago;
	}

	private function armarObservacionesOtroIngreso($o): string
	{
		$codForma = strtoupper((string) ($o->code_tipo_pago ?? ''));
		$nombreForma = trim((string) ($o->forma_cobro_nombre ?? ''));
		$obs = trim((string) ($o->observaciones ?? ''));
		$banco = $this->bancoSoloNombre((string) ($o->cta_banco ?? ''));
		$nroDep = (string) ($o->nro_deposito ?? '');
		$fechaDep = (string) ($o->fecha_deposito ?? '');

		if (($codForma === 'DE' || $codForma === 'D') && $banco !== '' && $nroDep !== '' && $fechaDep !== '') {
			$info = "Deposito: {$banco}-{$nroDep}-{$fechaDep}";
			return $obs !== '' ? ($info . ' ' . $obs) : $info;
		}
		if (($codForma === 'TR' || $codForma === 'B') && $banco !== '' && $nroDep !== '' && $fechaDep !== '') {
			$info = "Transferencia: {$banco}-{$nroDep}-{$fechaDep}";
			return $obs !== '' ? ($info . ' ' . $obs) : $info;
		}
		if (($codForma === 'TA' || $codForma === 'L' || $codForma === 'TC') && $banco !== '' && $nroDep !== '' && $fechaDep !== '') {
			$info = "Tarjeta: {$banco}-{$nroDep}-{$fechaDep} NL:0";
			return $obs !== '' ? ($info . ' ' . $obs) : $info;
		}

		$etiqueta = $nombreForma !== '' ? $nombreForma : $codForma;
		if ($obs !== '') {
			if ($etiqueta !== '' && stripos($obs, $etiqueta) === 0) {
				return $obs;
			}
			return $etiqueta !== '' ? ($etiqueta . ': ' . $obs) : $obs;
		}
		return $etiqueta !== '' ? $etiqueta : 'Otros Ingresos';
	}

	private function bancoSoloNombre(string $raw): string
	{
		$raw = trim($raw);
		if ($raw === '') {
			return '';
		}
		$partes = explode(' - ', $raw);
		return trim($partes[0] ?? $raw);
	}

	/**
	 * @param array<int,array<string,mixed>> $items
	 * @return array<string,mixed>
	 */
	private function construirResumenMetodosPago(array $items): array
	{
		$resumen = [];
		$map = ['E' => 'efectivo', 'L' => 'tarjeta', 'D' => 'deposito', 'C' => 'cheque', 'B' => 'transferencia', 'T' => 'traspaso', 'O' => 'otro'];
		foreach ($map as $k => $v) {
			$resumen[$v] = ['factura' => 0.0, 'recibo' => 0.0];
		}
		$totalFactura = 0.0;
		$totalRecibo = 0.0;
		$totalEfectivo = 0.0;
		foreach ($items as $it) {
			$tp = (string) ($it['tipo_pago'] ?? 'E');
			$td = (string) ($it['tipo_doc'] ?? 'F');
			$ing = (float) ($it['ingreso'] ?? 0);
			$key = $map[$tp] ?? 'otro';
			if ($td === 'F') {
				$resumen[$key]['factura'] += $ing;
				$totalFactura += $ing;
			} else {
				$resumen[$key]['recibo'] += $ing;
				$totalRecibo += $ing;
			}
			if ($tp === 'E') {
				$totalEfectivo += $ing;
			}
		}
		$resumen['total_factura'] = round($totalFactura, 2);
		$resumen['total_recibo'] = round($totalRecibo, 2);
		$resumen['total_efectivo'] = round($totalEfectivo, 2);
		$resumen['total_general'] = round($totalFactura + $totalRecibo, 2);
		return $resumen;
	}

	private function normFecha(string $v): string
	{
		$v = trim($v);
		if ($v === '') {
			return '';
		}
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
			return $v;
		}
		if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $v, $m)) {
			return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
		}
		try {
			return (new \DateTime($v))->format('Y-m-d');
		} catch (\Throwable $e) {
			return '';
		}
	}

	private function horaLocal(string $raw): string
	{
		$raw = trim($raw);
		if ($raw === '') {
			return '';
		}
		try {
			$dt = new \DateTime($raw);
			return $dt->format('H:i:s');
		} catch (\Throwable $e) {
			if (preg_match('/(\d{1,2}):(\d{2}):(\d{2})/', $raw, $m)) {
				return sprintf('%02d:%s:%s', (int) $m[1], $m[2], $m[3]);
			}
			return '';
		}
	}

	/** @return array{datos:array<int,array<string,mixed>>,totales:array{ingresos:float,egresos:float},usuario_info:array{nombre:string,hora_apertura:string,hora_cierre:string},resumen:array<string,mixed>} */
	private function respuestaVacia(string $nombre): array
	{
		return [
			'datos' => [],
			'totales' => ['ingresos' => 0.0, 'egresos' => 0.0],
			'usuario_info' => ['nombre' => $nombre, 'hora_apertura' => '', 'hora_cierre' => ''],
			'resumen' => $this->construirResumenMetodosPago([]),
		];
	}
}
