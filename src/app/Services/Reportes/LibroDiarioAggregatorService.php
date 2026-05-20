<?php

namespace App\Services\Reportes;

use App\Services\Economico\OtrosIngresosGlosaComprobanteService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega el Libro Diario desde 4 fuentes (cobro, factura, recibo, otros_ingresos; sin `qr_transacciones`)
 * y devuelve items canónicos listos para la UI y el PDF.
 *
 * Reglas:
 * - Dedupe: si una factura está enlazada a un cobro, solo se incluye el cobro (evita doble conteo).
 * - Si los cobros solo llevan nº de recibo (sin nº de factura) pero la suma por recibo y cod_ceta
 *   coincide con una sola factura en el rango, esa factura no se lista en duplicado.
 * - `otros_ingresos`: se filtra por nickname del usuario (la tabla no guarda id_usuario).
 * - `valido='N'` (anulado) se excluye; `valido='A'` se preserva y se reporta como ingreso 0 opcionalmente.
 * - Las observaciones extendidas (banco/nro/fecha/correlativo) se arman en esta capa para el PDF.
 * - Resumen: `id_forma_cobro` de `formas_cobro` (B, C, D, E, L, O, T) es la letra de columna; para ids
 *   compuestos se usa el nombre o heurística de apoyo.
 */
class LibroDiarioAggregatorService
{
	/** Códigos en `cobro.cod_tipo_cobro` considerados mora/recargos para el resumen del Libro Diario. */
	private const COD_TIPO_COBRO_MORA = ['MORA', 'RECARGO', 'INTERES', 'PENALIDAD', 'NIVELACION'];

	/** Letras alineadas con `formas_cobro` y el resumen (Transferencia, Cheque, Depósito, Efectivo, Tarjeta, Otro, Traspaso). */
	private const LETRAS_RESUMEN = ['B', 'C', 'D', 'E', 'L', 'O', 'T'];

	/** @var array<string, string>|null id_forma_cobro => E|L|D|C|B|T|O */
	private ?array $mapaFormaCobroIdALetra = null;

	public function __construct(
		private readonly OtrosIngresosGlosaComprobanteService $glosaOtrosIngresos,
	) {
	}

	/**
	 * Suma subtotales de líneas de mora/multa por factura (clave "anio:nro_factura") desde `factura_detalle`.
	 *
	 * @param \Illuminate\Support\Collection<int,object>|\Illuminate\Support\Collection<int,mixed> $cobros
	 * @param \Illuminate\Support\Collection<int,object>|\Illuminate\Support\Collection<int,mixed> $facturas
	 * @return array<string,float>
	 */
	private function cargarMapaSubtotalMoraFacturaDetalle($cobros, $facturas): array
	{
		if (!Schema::hasTable('factura_detalle')) {
			return [];
		}
		$claves = [];
		foreach ($cobros as $c) {
			$nf = (int) ($c->nro_factura ?? 0);
			$an = (int) ($c->anio_cobro ?? 0);
			if ($nf > 0 && $an > 0) {
				$claves[$an . ':' . $nf] = [$an, $nf];
			}
		}
		foreach ($facturas as $f) {
			$nf = (int) ($f->nro_factura ?? 0);
			$an = (int) ($f->anio ?? 0);
			if ($nf > 0 && $an > 0) {
				$claves[$an . ':' . $nf] = [$an, $nf];
			}
		}
		if ($claves === []) {
			return [];
		}
		$codigosMora = $this->mapaCodigosInternosItemsMora();
		$out = [];
		$rows = DB::table('factura_detalle')->where(function ($q) use ($claves) {
			foreach ($claves as [$an, $nf]) {
				$q->orWhere(function ($qq) use ($an, $nf) {
					$qq->where('anio', $an)->where('nro_factura', $nf);
				});
			}
		})->get(['anio', 'nro_factura', 'descripcion', 'codigo', 'subtotal']);
		foreach ($rows as $row) {
			if (!$this->esLineaFacturaDetalleMora($row, $codigosMora)) {
				continue;
			}
			$k = (int) $row->anio . ':' . (int) $row->nro_factura;
			$out[$k] = ($out[$k] ?? 0.0) + (float) ($row->subtotal ?? 0);
		}
		return $out;
	}

	/**
	 * @param \Illuminate\Support\Collection<int,object>|\Illuminate\Support\Collection<int,mixed> $cobros
	 * @return array<string,int> clave "anio:nro_factura" => cantidad de filas cobro
	 */
	private function construirConteoCobrosPorFactura($cobros): array
	{
		$out = [];
		foreach ($cobros as $c) {
			$nf = (string) ($c->nro_factura ?? '0');
			$an = (int) ($c->anio_cobro ?? 0);
			if ($nf === '' || $nf === '0' || $an <= 0) {
				continue;
			}
			$k = $an . ':' . $nf;
			$out[$k] = ($out[$k] ?? 0) + 1;
		}
		return $out;
	}

	/**
	 * Claves "anio:nro_factura" (o solo nro) de facturas que ya se representan con líneas de cobro:
	 * directamente por nº de factura en cobro, o por suma de montos de cobros con solo recibo = una factura.
	 *
	 * @param \Illuminate\Support\Collection<int,object>|\Illuminate\Support\Collection<int,mixed> $cobros
	 * @param \Illuminate\Support\Collection<int,object>|\Illuminate\Support\Collection<int,mixed> $facturas
	 * @return array<string, bool>
	 */
	private function clavesFacturaCubiertaPorCobro($cobros, $facturas): array
	{
		$out = [];
		foreach ($cobros as $c) {
			$nroFac = (string) ($c->nro_factura ?? '0');
			if ($nroFac === '' || $nroFac === '0') {
				continue;
			}
			$anioC = (int) ($c->anio_cobro ?? 0);
			$out[$anioC > 0 ? ($anioC . ':' . $nroFac) : $nroFac] = true;
		}

		$grupos = [];
		foreach ($cobros as $c) {
			$nroFac = (string) ($c->nro_factura ?? '0');
			if ($nroFac !== '' && $nroFac !== '0') {
				continue;
			}
			$nr = (int) ($c->nro_recibo ?? 0);
			$an = (int) ($c->anio_cobro ?? 0);
			if ($nr <= 0 || $an <= 0) {
				continue;
			}
			$k = $an . ':' . $nr;
			if (!isset($grupos[$k])) {
				$grupos[$k] = [
					'sum' => 0.0,
					'cod_ceta' => null,
				];
			}
			$grupos[$k]['sum'] += (float) ($c->monto ?? 0);
			if ($grupos[$k]['cod_ceta'] === null) {
				$cc = $c->cod_ceta ?? null;
				if ($cc !== null && (int) $cc > 0) {
					$grupos[$k]['cod_ceta'] = (int) $cc;
				}
			}
		}

		foreach ($grupos as $g) {
			$sum = round($g['sum'], 2);
			$cetaC = (int) ($g['cod_ceta'] ?? 0);
			$matches = [];
			foreach ($facturas as $f) {
				$nroF = (string) ($f->nro_factura ?? '0');
				$anF = (int) ($f->anio ?? 0);
				if ($nroF === '' || $nroF === '0' || $anF <= 0) {
					continue;
				}
				$cetaF = (int) ($f->cod_ceta ?? 0);
				if ($cetaC > 0 && $cetaF > 0 && $cetaC !== $cetaF) {
					continue;
				}
				if (abs((float) ($f->monto_total ?? 0) - $sum) < 0.01) {
					$matches[] = $anF . ':' . $nroF;
				}
			}
			if (count($matches) === 1) {
				$out[$matches[0]] = true;
			}
		}

		return $out;
	}

	/** @return array<string,bool> codigo_producto_interno (string) => true */
	private function mapaCodigosInternosItemsMora(): array
	{
		if (!Schema::hasTable('items_cobro')) {
			return [];
		}
		$codigos = DB::table('items_cobro')
			->where('nombre_servicio', 'multa')
			->pluck('codigo_producto_interno');
		$map = [];
		foreach ($codigos as $c) {
			$s = trim((string) $c);
			if ($s !== '') {
				$map[$s] = true;
			}
		}
		return $map;
	}

	/**
	 * @param object $row factura_detalle row
	 * @param array<string,bool> $codigosMoraSet
	 */
	private function esLineaFacturaDetalleMora($row, array $codigosMoraSet): bool
	{
		$desc = mb_strtolower((string) ($row->descripcion ?? ''), 'UTF-8');
		foreach (['mora', 'multa', 'recargo', 'interés', 'interes', 'penalidad'] as $needle) {
			if ($needle !== '' && str_contains($desc, $needle)) {
				return true;
			}
		}
		$cod = trim((string) ($row->codigo ?? ''));
		if ($cod !== '' && isset($codigosMoraSet[$cod])) {
			return true;
		}
		return false;
	}

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

		$this->mapaFormaCobroIdALetra = null;

		$nickname = $this->resolverNicknameUsuario($idUsuario);

		$cobros = $this->cargarCobros($idUsuario, $desde, $hasta, $codigoCarrera);
		$facturas = $this->cargarFacturas($idUsuario, $desde, $hasta, $codigoCarrera);
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

		$clavesFacturaCubierta = $this->clavesFacturaCubiertaPorCobro($cobros, $facturas);

		$mapaMoraFacturaDetalle = $this->cargarMapaSubtotalMoraFacturaDetalle($cobros, $facturas);
		$conteoCobrosPorFactura = $this->construirConteoCobrosPorFactura($cobros);

		$items = [];

		foreach ($facturas as $f) {
			$nroFac = (string) ($f->nro_factura ?? '0');
			if ($nroFac === '' || $nroFac === '0') {
				continue;
			}
			$anioF = (int) ($f->anio ?? 0);
			$claveFac = $anioF > 0 ? ($anioF . ':' . $nroFac) : $nroFac;
			if (isset($clavesFacturaCubierta[$claveFac]) || ($anioF <= 0 && isset($clavesFacturaCubierta[$nroFac]))) {
				continue;
			}
			$items[] = $this->mapearFactura($f, $mapaFacturas, $mapaMoraFacturaDetalle);
		}

		foreach ($cobros as $c) {
			$items[] = $this->mapearCobro(
				$c,
				$mapaFacturas,
				$recibosMap,
				$mapaMontosFactura,
				$conteoCobrosPorFactura,
				$mapaMoraFacturaDetalle
			);
		}

		foreach ($otros as $o) {
			$items[] = $this->mapearOtroIngreso($o);
		}

		usort($items, function ($a, $b) {
			$ta = (string) ($a['tipo_doc'] ?? '');
			$tb = (string) ($b['tipo_doc'] ?? '');
			$prioF = ['F' => 0, 'R' => 1];
			$pa = $prioF[$ta] ?? 2;
			$pb = $prioF[$tb] ?? 2;
			if ($pa !== $pb) {
				return $pa <=> $pb;
			}
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
			->leftJoin('factura as f', function ($j) use ($desde, $hasta) {
				$j->on('f.nro_factura', '=', 'c.nro_factura')
				  ->on('f.anio', '=', 'c.anio_cobro')
				  ->whereDate('f.fecha_emision', '>=', $desde)
				  ->whereDate('f.fecha_emision', '<=', $hasta);
			})
			->leftJoin('recibo as r', function ($j) {
				$j->on('r.nro_recibo', '=', 'c.nro_recibo')
				  ->on('r.anio', '=', 'c.anio_cobro');
			})
			->leftJoin('cuentas_bancarias as cb', 'cb.id_cuentas_bancarias', '=', 'c.id_cuentas_bancarias')
			->leftJoin('formas_cobro as fc',       'fc.id_forma_cobro',       '=', 'c.id_forma_cobro')
			->leftJoin('inscripciones as insc',    'insc.cod_inscrip',        '=', 'c.cod_inscrip')
			->where('c.id_usuario', $idUsuario)
			->whereDate('c.fecha_cobro', '>=', $desde)
			->whereDate('c.fecha_cobro', '<=', $hasta);

		if ($codigoCarrera !== '') {
			$q->whereIn('c.cod_pensum', function ($sub) use ($codigoCarrera) {
				$sub->select('cod_pensum')->from('pensums')->where('codigo_carrera', $codigoCarrera);
			});
		}

		$descuentoCol = Schema::hasColumn('inscripciones', 'descuento_institucional')
			? 'insc.descuento_institucional'
			: DB::raw('NULL as descuento_institucional');

		$rows = $q->select(
			'c.nro_cobro',
			'c.anio_cobro',
			'c.cod_ceta',
			'c.cod_pensum',
			'c.tipo_inscripcion',
			'c.nro_factura',
			'c.nro_recibo',
			'c.monto',
			'c.observaciones',
			'c.concepto',
			'c.fecha_cobro',
			'c.id_forma_cobro',
			'c.cod_tipo_cobro',
			'c.id_cuentas_bancarias',
			DB::raw('COALESCE(r.cliente, f.cliente) as cliente'),
			DB::raw('COALESCE(r.nro_documento_cobro, f.nro_documento_cobro) as nro_documento_cobro'),
			'cb.banco as banco_cuenta_cobro',
			'fc.nombre as forma_cobro_nombre',
			'f.monto_total as factura_monto_total',
			$descuentoCol
		)->get();

		$nros = $rows
			->pluck('nro_recibo')
			->filter(fn ($v) => $v !== null && $v !== '' && $v !== 0)
			->map(fn ($v) => (string) $v)
			->unique()
			->values();

		$nfs = $rows
			->pluck('nro_factura')
			->filter(fn ($v) => $v !== null && $v !== '' && $v !== 0)
			->map(fn ($v) => (string) $v)
			->unique()
			->values();

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

		$qrByRec = [];
		if (Schema::hasTable('qr_transacciones') && $nros->count()) {
			$qrRows = DB::table('qr_transacciones')
				->whereIn('nro_recibo', $nros->all())
				->orderBy('updated_at', 'desc')
				->get(['id_qr_transaccion', 'nro_recibo', 'numeroordenoriginante']);
			foreach ($qrRows as $qr) {
				$kR = (string) ($qr->nro_recibo ?? '');
				if ($kR === '' || isset($qrByRec[$kR])) {
					continue;
				}
				$qrByRec[$kR] = $qr;
			}
		}

		$qrAliasByObs = [];
		foreach ($rows as $row) {
			$obsTmp = trim((string) ($row->observaciones ?? ''));
			if ($obsTmp === '') {
				continue;
			}
			if (preg_match('/\[\s*QR[^\]]*\]\s*alias:([^\s|]+)/i', $obsTmp, $m) === 1) {
				$aliasTmp = trim((string) ($m[1] ?? ''));
				if ($aliasTmp !== '') {
					$qrAliasByObs[$aliasTmp] = true;
				}
			}
		}

		$qrRespByAlias = [];
		if (Schema::hasTable('qr_respuestas_banco') && $qrAliasByObs !== []) {
			$qrRespAliasRows = DB::table('qr_respuestas_banco')
				->whereIn('alias', array_keys($qrAliasByObs))
				->orderBy('fecha_respuesta', 'desc')
				->get(['alias', 'numeroordenoriginante', 'fecha_respuesta']);
			foreach ($qrRespAliasRows as $resp) {
				$aliasK = trim((string) ($resp->alias ?? ''));
				if ($aliasK === '' || isset($qrRespByAlias[$aliasK])) {
					continue;
				}
				$qrRespByAlias[$aliasK] = $resp;
			}
		}

		$qrRespByTrx = [];
		$qrTrxIds = collect($qrByRec)
			->map(fn ($qr) => (int) ($qr->id_qr_transaccion ?? 0))
			->filter(fn ($id) => $id > 0)
			->unique()
			->values();
		if (Schema::hasTable('qr_respuestas_banco') && $qrTrxIds->count()) {
			$qrRespRows = DB::table('qr_respuestas_banco')
				->whereIn('id_qr_transaccion', $qrTrxIds->all())
				->orderBy('fecha_respuesta', 'desc')
				->get(['id_qr_transaccion', 'fecha_respuesta']);
			foreach ($qrRespRows as $resp) {
				$idTrx = (int) ($resp->id_qr_transaccion ?? 0);
				if ($idTrx <= 0 || isset($qrRespByTrx[$idTrx])) {
					continue;
				}
				$qrRespByTrx[$idTrx] = $resp;
			}
		}

		return $rows->map(function ($r) use ($nbByRec, $nbByFac, $qrByRec, $qrRespByTrx, $qrRespByAlias) {
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
			if ($kR !== '' && isset($qrByRec[$kR])) {
				$qrRec = $qrByRec[$kR];
				$r->qr_numero_originante = $qrRec->numeroordenoriginante ?? null;
				$idTrx = (int) ($qrRec->id_qr_transaccion ?? 0);
				if ($idTrx > 0 && isset($qrRespByTrx[$idTrx])) {
					$r->qr_fecha_respuesta = $qrRespByTrx[$idTrx]->fecha_respuesta ?? null;
				}
			}
			if (empty($r->qr_numero_originante)) {
				$obsTmp = trim((string) ($r->observaciones ?? ''));
				if ($obsTmp !== '' && preg_match('/\[\s*QR[^\]]*\]\s*alias:([^\s|]+)/i', $obsTmp, $m) === 1) {
					$aliasObs = trim((string) ($m[1] ?? ''));
					if ($aliasObs !== '' && isset($qrRespByAlias[$aliasObs])) {
						$respAlias = $qrRespByAlias[$aliasObs];
						$r->qr_numero_originante = $respAlias->numeroordenoriginante ?? null;
						$r->qr_fecha_respuesta = $respAlias->fecha_respuesta ?? ($r->qr_fecha_respuesta ?? null);
					}
				}
			}
			return $r;
		});
	}

	/**
	 * Facturas del usuario en el rango. Si `codigoCarrera` no está vacío, solo las asociadas a esa
	 * carrera vía inscripciones (cod_ceta → cod_pensum) o vía cobro con mismo nro/anio y cod_pensum.
	 *
	 * @return \Illuminate\Support\Collection<int,object>
	 */
	private function cargarFacturas(int $idUsuario, string $desde, string $hasta, string $codigoCarrera = '')
	{
		$q = DB::table('factura')
			->where('id_usuario', $idUsuario)
			->whereBetween('fecha_emision', [$desde . ' 00:00:00', $hasta . ' 23:59:59'])
			->where('nro_factura', '>', 0);

		$this->aplicarFiltroCarreraQueryFactura($q, $idUsuario, $desde, $hasta, $codigoCarrera);

		return $q->select(
			'anio', 'nro_factura', 'cliente', 'nro_documento_cobro', 'cod_ceta',
			'id_forma_cobro', 'monto_total', 'fecha_emision', 'estado'
		)->get();
	}

	/**
	 * Restringe el query de `factura` a la carrera pedida exigiendo que exista
	 * un cobro del mismo usuario/factura en el rango y con pensum de esa carrera.
	 */
	private function aplicarFiltroCarreraQueryFactura($query, int $idUsuario, string $desde, string $hasta, string $codigoCarrera): void
	{
		$codigoCarrera = trim($codigoCarrera);
		if ($codigoCarrera === '' || ! Schema::hasTable('pensums')) {
			return;
		}
		if (! Schema::hasTable('cobro')) {
			$query->whereRaw('1 = 0');
			return;
		}

		$query->whereExists(function ($ex) use ($idUsuario, $desde, $hasta, $codigoCarrera) {
			$ex->from('cobro as c')
				->whereColumn('c.nro_factura', 'factura.nro_factura')
				->where('c.id_usuario', $idUsuario)
				->whereDate('c.fecha_cobro', '>=', $desde)
				->whereDate('c.fecha_cobro', '<=', $hasta)
				->whereIn('c.cod_pensum', function ($sub) use ($codigoCarrera) {
					$sub->select('cod_pensum')->from('pensums')->where('codigo_carrera', $codigoCarrera);
				});

			if (Schema::hasColumn('cobro', 'anio_cobro') && Schema::hasColumn('factura', 'anio')) {
				$ex->whereColumn('c.anio_cobro', 'factura.anio');
			}
		});
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

		$sel = [
			'oi.id', 'oi.num_factura', 'oi.num_recibo', 'oi.nit', 'oi.razon_social',
			'oi.fecha', 'oi.monto', 'oi.concepto', 'oi.observaciones', 'oi.valido',
			'oi.code_tipo_pago', 'oi.factura_recibo', 'oi.tipo_ingreso', 'oi.cod_tipo_ingreso',
			'oi.gestion', 'oi.usuario',
			'fc.nombre as forma_cobro_nombre',
			'd.cta_banco', 'd.nro_deposito', 'd.fecha_deposito', 'd.fecha_ini', 'd.fecha_fin', 'd.nro_orden', 'd.concepto_alquiler',
		];
		if (Schema::hasColumn('otros_ingresos', 'glosa_comprobante')) {
			$sel[] = 'oi.glosa_comprobante';
		}

		return $q->select($sel)->get();
	}

	/**
	 * @param object $f
	 * @param array<string,array{razon_social:string,nit:string}> $mapaFacturas
	 * @return array<string,mixed>
	 */
	private function mapearFactura($f, array $mapaFacturas, array $mapaMoraFacturaDetalle = []): array
	{
		$nroFac = (string) ($f->nro_factura ?? '0');
		$anioF = (int) ($f->anio ?? 0);
		$claveFac = ($anioF > 0 && $nroFac !== '' && $nroFac !== '0') ? ($anioF . ':' . $nroFac) : $nroFac;
		$dc = $mapaFacturas[$claveFac] ?? ($mapaFacturas[$nroFac] ?? [
			'razon_social' => (string) ($f->cliente ?? ''),
			'nit' => (string) ($f->nro_documento_cobro ?? '0'),
		]);

		$ingreso = (float) ($f->monto_total ?? 0);
		$sumMoraDet = 0.0;
		if ($anioF > 0 && $nroFac !== '' && $nroFac !== '0') {
			$sumMoraDet = (float) ($mapaMoraFacturaDetalle[$claveFac] ?? 0.0);
		}
		$montoMora = ($sumMoraDet > 0 && $ingreso > 0) ? min($sumMoraDet, $ingreso) : 0.0;

		return [
			'numero' => 0,
			'recibo' => '0',
			'factura' => $nroFac !== '' ? $nroFac : '0',
			'concepto' => 'Factura',
			'razon' => $dc['razon_social'] ?: 'SIN DATOS',
			'nit' => $dc['nit'] ?: '0',
			'cod_ceta' => (string) ($f->cod_ceta ?? '0'),
			'hora' => $this->horaLocal((string) ($f->fecha_emision ?? '')),
			'ingreso' => $ingreso,
			'egreso' => 0.0,
			'tipo_doc' => 'F',
			'tipo_pago' => $this->mapearTipoPago((string) ($f->id_forma_cobro ?? '')),
			'observaciones' => (string) ($f->id_forma_cobro ?? 'Efectivo'),
			'es_mora' => false,
			'monto_mora' => $montoMora,
		];
	}

	/**
	 * @param object $c
	 * @param array<string,array{razon_social:string,nit:string}> $mapaFacturas
	 * @param array<string,object> $recibosMap
	 * @param array<string,float> $mapaMontosFactura
	 * @param array<string,int> $conteoCobrosPorFactura
	 * @param array<string,float> $mapaMoraFacturaDetalle
	 * @return array<string,mixed>
	 */
	private function mapearCobro(
		$c,
		array $mapaFacturas,
		array $recibosMap,
		array $mapaMontosFactura,
		array $conteoCobrosPorFactura,
		array $mapaMoraFacturaDetalle
	): array {
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
		$codTipoCobro = strtoupper(trim((string) ($c->cod_tipo_cobro ?? '')));
		$esMora = $codTipoCobro !== '' && in_array($codTipoCobro, self::COD_TIPO_COBRO_MORA, true);

		$montoMoraReporte = $esMora ? $monto : 0.0;
		if (!$esMora && $nroFac !== '' && $nroFac !== '0' && $anioC > 0) {
			$ck = $anioC . ':' . $nroFac;
			if (($conteoCobrosPorFactura[$ck] ?? 0) === 1) {
				$sumMoraDet = (float) ($mapaMoraFacturaDetalle[$ck] ?? 0.0);
				if ($sumMoraDet > 0 && $monto > 0) {
					$montoMoraReporte = min($sumMoraDet, $monto);
				}
			}
		}

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
			'es_mora' => $esMora,
			'monto_mora' => $montoMoraReporte,
		];
	}

	/**
	 * Libro Diario (columna Concepto): la glosa tipo SGA suele traer «Recibo/Fact Nro/S/N», apéndice
	 * «Depósito en cuenta…» (duplicado respecto a Observaciones) y texto duplicado de observaciones
	 * (sufijo «; obs» en OT/Fotocopi/Alquiler/Tienda o incrustado en «Varios»).
	 *
	 * @param string $obsRaw texto en BD (`otros_ingresos.observaciones`), mismo que acaba en columnas u observaciones extendidas.
	 */
	private function conceptoOtrosIngresosParaLibroDiario(string $linea, string $obsRaw): string
	{
		$linea = trim($linea);
		if ($linea === '') {
			return '';
		}
		$linea = preg_replace('/\s+Recibo:\s*\d+\.?/iu', '', $linea) ?? $linea;
		$linea = preg_replace('/\s+Fact\s*Nro:\s*\d+\.?/iu', '', $linea) ?? $linea;
		$linea = preg_replace('/\s+S\/N\.?/iu', '', $linea) ?? $linea;
		// Misma línea que agrega la glosa comprobante por forma depósito; en Libro Diario queda en columnas Observaciones / datos bancarios.
		$linea = preg_replace('/[\s;]*(Depósito|Deposito)\s+en\s+cuenta\b.*$/iu', '', $linea) ?? $linea;
		$obsRaw = trim($obsRaw);
		if ($obsRaw !== '') {
			$linea = preg_replace('/;\s*' . preg_quote($obsRaw, '/') . '\s*$/iu', '', $linea) ?? $linea;
			// «Varios»: la obs va después de «Ingreso no académico varios.» y antes de Fact/Recibo (no solo como «; obs» al final).
			$patVarios = '/Ingreso no académico varios\.\s+' . preg_quote($obsRaw, '/') . '\s+/iu';
			if (preg_match($patVarios, $linea)) {
				$linea = preg_replace($patVarios, 'Ingreso no académico varios. ', $linea) ?? $linea;
			}
		}
		$linea = trim((string) preg_replace('/\s+/u', ' ', $linea));

		return $linea;
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
		$obsRawBd = trim((string) ($o->observaciones ?? ''));

		$glosaComp = trim((string) ($o->glosa_comprobante ?? ''));
		$linea = $glosaComp !== '' ? $glosaComp : $this->glosaOtrosIngresos->construirDesdeFila($o);
		if (trim($linea) === '') {
			$linea = trim((string) ($o->concepto ?? ''));
		}
		if (trim($linea) === '') {
			$linea = trim((string) ($o->tipo_ingreso ?? 'Otros Ingresos'));
		}

		$conceptoLibro = $this->conceptoOtrosIngresosParaLibroDiario($linea, $obsRawBd);

		return [
			'numero' => 0,
			'recibo' => $tipoDoc === 'R' ? (string) ($numRec > 0 ? $numRec : '0') : '0',
			'factura' => $tipoDoc === 'F' ? (string) ($numFac > 0 ? $numFac : '0') : '0',
			'concepto' => $conceptoLibro !== '' ? $conceptoLibro : $linea,
			'razon' => (string) ($o->razon_social ?? ''),
			'nit' => (string) ($o->nit ?? '0'),
			'cod_ceta' => '0',
			'hora' => $this->horaLocal((string) ($o->fecha ?? '')),
			'ingreso' => $ingreso,
			'egreso' => 0.0,
			'tipo_doc' => $tipoDoc,
			'tipo_pago' => $tipoPago,
			'observaciones' => $obs,
			'es_mora' => false,
			'monto_mora' => 0.0,
		];
	}

	/**
	 * Mapa id_forma_cobro => letra de resumen. Caso base (catálogo con B, C, D, E, L, O, T en id): la
	 * letra es el propio id; ids largos usan el nombre o heurística.
	 *
	 * @return array<string, string> id => E|L|D|C|B|T|O
	 */
	private function mapaFormaCobroIdALetraResumen(): array
	{
		if ($this->mapaFormaCobroIdALetra !== null) {
			return $this->mapaFormaCobroIdALetra;
		}
		$this->mapaFormaCobroIdALetra = [];
		if (!Schema::hasTable('formas_cobro')) {
			return $this->mapaFormaCobroIdALetra;
		}
		$rows = DB::table('formas_cobro')->select('id_forma_cobro', 'nombre')->get();
		foreach ($rows as $row) {
			$id = trim((string) ($row->id_forma_cobro ?? ''));
			if ($id === '') {
				continue;
			}
			$this->mapaFormaCobroIdALetra[$id] = $this->letraResumenDesdeFormaCobro(
				$id,
				(string) ($row->nombre ?? '')
			);
		}
		return $this->mapaFormaCobroIdALetra;
	}

	/**
	 * 1) Id de una sola letra (catálogo estándar): es la letra del resumen.
	 * 2) Id compuesto: pistas en `nombre` alineadas con formas_cobro.
	 * 3) Fallback: heurística sobre el id.
	 */
	private function letraResumenDesdeFormaCobro(string $id, string $nombre): string
	{
		$u = strtoupper(trim($id));
		if (strlen($u) === 1 && in_array($u, self::LETRAS_RESUMEN, true)) {
			return $u;
		}
		$n = mb_strtolower($nombre, 'UTF-8');
		$n = strtr($n, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);

		if (str_contains($n, 'traspaso')) {
			return 'T';
		}
		if (str_contains($n, 'transfer') || (str_contains($n, 'qr') && (str_contains($n, 'banc') || str_contains($n, 'trans')))) {
			return 'B';
		}
		if (str_contains($n, 'cheq')) {
			return 'C';
		}
		if (str_contains($n, 'linea') && str_contains($n, 'dep')) {
			return 'L';
		}
		if (str_contains($n, 'depo') || str_contains($n, 'posit') || (str_contains($n, 'dep') && str_contains($n, 'cuenta'))) {
			return 'D';
		}
		if (str_contains($n, 'tarj')) {
			return str_contains($n, 'efectiv') ? 'O' : 'L';
		}
		if (str_contains($n, 'vales') || str_contains($n, 'otro') || (str_contains($n, 'pago') && str_contains($n, 'posterior'))) {
			return 'O';
		}
		if (str_contains($n, 'efectiv')) {
			if (str_contains($n, 'tarj') || str_contains($n, 'transf') || str_contains($n, 'cheq') || str_contains($n, 'vales') || str_contains($n, 'dep') || str_contains($n, 'qr')) {
				return 'O';
			}
			return 'E';
		}

		return $this->mapearTipoPagoHeuristicaSobreId($u);
	}

	/** Fallback cuando el id no está en el catálogo: mismas pistas anteriores sobre el string. */
	private function mapearTipoPagoHeuristicaSobreId(string $c): string
	{
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

	private function mapearTipoPago(string $codigo): string
	{
		$k = trim($codigo);
		if ($k === '') {
			return 'E';
		}
		$mapa = $this->mapaFormaCobroIdALetraResumen();
		if (isset($mapa[$k])) {
			return $mapa[$k];
		}
		$c = strtoupper($k);
		foreach ($mapa as $idCat => $letra) {
			if (strtoupper((string) $idCat) === $c) {
				return $letra;
			}
		}
		// Misma convención que `formas_cobro.id_forma_cobro` (una letra).
		if (strlen($c) === 1 && in_array($c, self::LETRAS_RESUMEN, true)) {
			return $c;
		}

		return $this->mapearTipoPagoHeuristicaSobreId($c);
	}

	private function tieneDescuentoInstitucional($c): bool
	{
		$val = $c->descuento_institucional ?? null;
		return $val !== null && (bool) $val === true;
	}

	private function armarObservacionesExtendidas($c): string
	{
		$idForma = strtoupper((string) ($c->id_forma_cobro ?? ''));
		$obsOriginal = trim((string) ($c->observaciones ?? ''));
		$sufijoDescu = $this->tieneDescuentoInstitucional($c) ? ' | Descuento Institucional activado' : '';
		$bancoQr = $this->bancoSoloNombre((string) ($c->banco_cuenta_cobro ?? ''));
		$numeroOriginanteQr = trim((string) ($c->qr_numero_originante ?? ''));
		if ($numeroOriginanteQr !== '') {
			$fechaQr = substr((string) ($c->qr_fecha_respuesta ?? ''), 0, 10);
			if ($fechaQr === '') {
				$fechaQr = substr((string) ($c->fecha_cobro ?? ''), 0, 10);
			}
			if ($fechaQr === '') {
				$fechaQr = (string) ($c->fecha_deposito ?? ($c->fecha_nota ?? ''));
			}
			$infoQr = trim((string) ($bancoQr !== '' ? $bancoQr : $this->bancoSoloNombre((string) ($c->banco_nb ?? ''))));
			if ($infoQr !== '' && $fechaQr !== '') {
				$qrInfo = "{$infoQr}-{$numeroOriginanteQr}-{$fechaQr}";
				$qrObsFormatted = "Transferencia: {$qrInfo}";
				if ($obsOriginal !== '') {
					$regexAlias = '/\[\s*QR[^\]]*\]\s*alias:[^\s|]+/i';
					$obsBase = trim((string) preg_replace($regexAlias, '', $obsOriginal, 1));
					$obsBase = trim((string) preg_replace('/^\|\s*|\s*\|$/', '', $obsBase));
					if ($obsBase !== '') {
						return "Transferencia: {$obsBase} | {$qrInfo}{$sufijoDescu}";
					}
					return $qrObsFormatted . $sufijoDescu;
				}
				return $qrObsFormatted . $sufijoDescu;
			}
		}

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
		if ($banco === '') {
			$banco = $this->bancoSoloNombre((string) ($c->banco_cuenta_cobro ?? ''));
		}
		$nroTrx   = (string) ($c->nro_transaccion ?? ($c->nro_deposito ?? ''));
		$fechaDep = (string) ($c->fecha_deposito ?? ($c->fecha_nota ?? ''));

		$infoAdicional = '';

		switch ($codigo) {
			case 'TA':
				$nroTrxTa  = $nroTrx !== '' ? $nroTrx : $obsOriginal;
				$fechaDepTa = $fechaDep !== '' ? $fechaDep : substr((string) ($c->fecha_cobro ?? ''), 0, 10);
				if ($banco !== '' && $nroTrxTa !== '' && $fechaDepTa !== '') {
					$infoAdicional = "Tarjeta: {$banco}-{$nroTrxTa}-{$fechaDepTa}";
				}
				break;
			case 'CH':
				$infoAdicional = "Cheque N°: " . ($nroTrx !== '' ? $nroTrx : 'N/A') . " - Banco: " . ($banco !== '' ? $banco : 'N/A');
				break;
			case 'DE':
				if ($banco !== '' && $nroTrx !== '' && $fechaDep !== '') {
					$infoAdicional = "Deposito: {$banco}-{$nroTrx}-{$fechaDep}";
				}
				break;
			case 'TR':
				if ($banco !== '' && $nroTrx !== '' && $fechaDep !== '') {
					$infoAdicional = "Transferencia: {$banco}-{$nroTrx}-{$fechaDep}";
				}
				break;
		}

		if ($infoAdicional !== '') {
			$base = $obsOriginal !== '' ? ($infoAdicional . ' ' . $obsOriginal) : $infoAdicional;
			return $base . $sufijoDescu;
		}

		$tipoPago = trim((string) ($c->forma_cobro_nombre ?? $idForma));
		if ($obsOriginal !== '') {
			if ($tipoPago !== '' && stripos($obsOriginal, $tipoPago) === 0) {
				return $obsOriginal . $sufijoDescu;
			}
			$base = $tipoPago !== '' ? ($tipoPago . ': ' . $obsOriginal) : $obsOriginal;
			return $base . $sufijoDescu;
		}

		return $tipoPago . $sufijoDescu;
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
			$info = "Tarjeta: {$banco}-{$nroDep}-{$fechaDep}";
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
			$resumen[$v] = [
				'factura' => 0.0,
				'recibo' => 0.0,
				'mora_factura' => 0.0,
				'mora_recibo' => 0.0,
			];
		}
		$totalFactura = 0.0;
		$totalRecibo = 0.0;
		$totalMoraFactura = 0.0;
		$totalMoraRecibo = 0.0;
		foreach ($items as $it) {
			$tp = (string) ($it['tipo_pago'] ?? 'E');
			$td = (string) ($it['tipo_doc'] ?? 'F');
			$ing = (float) ($it['ingreso'] ?? 0);
			$esMora = !empty($it['es_mora']);
			$mMoraInf = (float) ($it['monto_mora'] ?? 0);
			$mMora = $esMora ? $ing : min(max(0.0, $mMoraInf), $ing);
			$mCapital = $esMora ? 0.0 : max(0.0, $ing - $mMora);
			$key = $map[$tp] ?? 'otro';
			if ($esMora) {
				if ($td === 'F') {
					$resumen[$key]['mora_factura'] += $ing;
					$totalMoraFactura += $ing;
				} else {
					$resumen[$key]['mora_recibo'] += $ing;
					$totalMoraRecibo += $ing;
				}
			} elseif ($mMora > 0.00001) {
				if ($td === 'F') {
					$resumen[$key]['factura'] += $mCapital;
					$resumen[$key]['mora_factura'] += $mMora;
					$totalFactura += $mCapital;
					$totalMoraFactura += $mMora;
				} else {
					$resumen[$key]['recibo'] += $mCapital;
					$resumen[$key]['mora_recibo'] += $mMora;
					$totalRecibo += $mCapital;
					$totalMoraRecibo += $mMora;
				}
			} else {
				if ($td === 'F') {
					$resumen[$key]['factura'] += $ing;
					$totalFactura += $ing;
				} else {
					$resumen[$key]['recibo'] += $ing;
					$totalRecibo += $ing;
				}
			}
		}
		$resumen['total_factura'] = round($totalFactura, 2);
		$resumen['total_recibo'] = round($totalRecibo, 2);
		$resumen['total_mora_factura'] = round($totalMoraFactura, 2);
		$resumen['total_mora_recibo'] = round($totalMoraRecibo, 2);
		$ef = $resumen['efectivo'];
		$resumen['total_efectivo'] = round(
			$ef['factura'] + $ef['recibo'] + $ef['mora_factura'] + $ef['mora_recibo'],
			2
		);
		$resumen['total_general'] = round(
			$totalFactura + $totalRecibo + $totalMoraFactura + $totalMoraRecibo,
			2
		);
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
