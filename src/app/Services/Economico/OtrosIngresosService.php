<?php

namespace App\Services\Economico;

use App\Models\Gestion;
use App\Models\OtroIngreso;
use App\Models\OtroIngresoDetalle;
use App\Models\ParametrosEconomicos;
use App\Models\Pensum;
use App\Models\TipoOtroIngreso;
use App\Models\Usuario;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class OtrosIngresosService
{
	/** Zona horaria de negocio (Bolivia, UTC−4); alinea `fecha`/`hora` con el reloj local aunque `APP_TIMEZONE` falle. */
	private const TZ_NEGOCIO = 'America/La_Paz';

	public function __construct(
		private readonly NotaOtrosIngresosPdfService $notaOtrosIngresosPdfService,
	) {
	}

	/** Evita guardar en UTC cuando el negocio es en Bolivia (desfase típico +4 h). */
	private function timezoneNegocio(): string
	{
		$tz = config('app.timezone', self::TZ_NEGOCIO);
		if (!is_string($tz) || $tz === '' || $tz === 'UTC') {
			return self::TZ_NEGOCIO;
		}

		return $tz;
	}

	private function ahoraNegocio(): Carbon
	{
		return Carbon::now($this->timezoneNegocio());
	}

	public function getGestionCobroValor(): ?string
	{
		if (!Schema::hasTable('parametros_economicos')) {
			return null;
		}

		$q = ParametrosEconomicos::query();
		if (Schema::hasColumn('parametros_economicos', 'estado')) {
			$q->where('estado', true);
		}

		if (Schema::hasColumn('parametros_economicos', 'nombre')) {
			$row = (clone $q)->where('nombre', 'gestion_cobro')->first();
			if ($row) {
				return $row->valor;
			}
		}

		// Esquema tipo SGA: columna `parametro`
		if (Schema::hasColumn('parametros_economicos', 'parametro')) {
			$raw = DB::table('parametros_economicos')->where('parametro', 'gestion_cobro')->first();

			return $raw?->valor !== null && $raw?->valor !== '' ? (string) $raw->valor : null;
		}

		return null;
	}

	public function listPensumsConCarrera(): array
	{
		if (!Schema::hasTable('pensums')) {
			return [];
		}

		$q = Pensum::query()->with('carrera');
		if (Schema::hasColumn('pensums', 'activo')) {
			$q->where('activo', true);
		}
		$rows = $q->orderBy('codigo_carrera')->orderBy('orden')->get();

		return $rows->map(function (Pensum $p) {
			$nombreCarrera = $p->carrera?->nombre ?? $p->codigo_carrera;
			return [
				'cod_pensum' => $p->cod_pensum,
				'nombre_carrera' => $nombreCarrera,
				'nombre_pensum' => $p->nombre,
				'label' => $nombreCarrera . ' (' . $p->cod_pensum . ')',
			];
		})->values()->all();
	}

	public function listGestionesActivas(): array
	{
		$q = Gestion::query()->orderByDesc('fecha_ini');
		if (Schema::hasColumn('gestion', 'activo')) {
			$q->where('activo', true);
		}
		$cols = ['gestion', 'fecha_ini', 'fecha_fin'];
		if (Schema::hasColumn('gestion', 'activo')) {
			$cols[] = 'activo';
		}
		return $q->get($cols)->values()->all();
	}

	public function listTiposIngreso(): array
	{
		if (!Schema::hasTable('tipo_otro_ingreso')) {
			return [];
		}
		return TipoOtroIngreso::query()
			->orderBy('nom_tipo_ingreso')
			->get(['id', 'cod_tipo_ingreso', 'nom_tipo_ingreso', 'descripcion_tipo_ingreso'])
			->values()
			->all();
	}

	/**
	 * Formas de cobro para el combo «Tipo de pago» (otros ingresos).
	 * Solo entran las que aplican a este formulario: efectivo, depósito, tarjeta y transferencia (según `flujo`).
	 *
	 * @return array<int, array{code: string, label: string, flujo: string}>
	 */
	public function listFormasCobroParaOtrosIngresos(): array
	{
		if (!Schema::hasTable('formas_cobro')) {
			return [];
		}

		$permitidos = ['efectivo', 'deposito', 'tarjeta', 'transferencia'];

		return DB::table('formas_cobro')
			->select('id_forma_cobro', 'nombre')
			->orderBy('nombre')
			->get()
			->map(function ($r) {
				$id = (string) $r->id_forma_cobro;
				$flujo = $this->mapFormaCobroFlujoOtrosIngresos($id, (string) $r->nombre);

				return [
					'code' => $id,
					'label' => (string) $r->nombre,
					'flujo' => $flujo,
				];
			})
			->filter(function (array $row) use ($permitidos) {
				if ($this->excluirFormaTraspasoDelComboOtrosIngresos($row['code'], $row['label'])) {
					return false;
				}

				return in_array($row['flujo'], $permitidos, true);
			})
			->values()
			->all();
	}

	/**
	 * Clasificación para reglas del formulario otros ingresos (SGA + códigos en seed + heurística por nombre).
	 * Público estático para reutilizar en generación de PDF (misma semántica que el combo «Tipo de pago»).
	 */
	public static function inferirFlujoFormaCobroOtrosIngresos(string $idFormaCobro, ?string $nombre = null): string
	{
		$id = strtoupper(trim($idFormaCobro));
		$nom = mb_strtoupper(trim((string) $nombre));

		$byId = match (true) {
			in_array($id, ['E', 'EF'], true) => 'efectivo',
			$id === 'D' || str_contains($id, 'DEP') => 'deposito',
			in_array($id, ['L', 'TC'], true) => 'tarjeta',
			in_array($id, ['B', 'T', 'TR'], true) => 'transferencia',
			$id === 'QR' => 'transferencia',
			str_contains($id, 'TRANSFER') => 'transferencia',
			default => null,
		};
		if ($byId !== null) {
			return $byId;
		}
		if ($nom !== '') {
			if (preg_match('/EFECTIV|CASH/i', $nom) === 1) {
				return 'efectivo';
			}
			if (preg_match('/DEP[ÓO]SIT|DEPOSIT/i', $nom) === 1) {
				return 'deposito';
			}
			if (preg_match('/TARJETA|CR[ÉE]DIT|VISA|MASTER|D[ÉE]BIT/i', $nom) === 1) {
				return 'tarjeta';
			}
			if (preg_match('/TRANSFER|QR|BANC(ARIA|ARIO)?/i', $nom) === 1) {
				return 'transferencia';
			}
		}

		return 'otro';
	}

	private function mapFormaCobroFlujoOtrosIngresos(string $idFormaCobro, ?string $nombre = null): string
	{
		return self::inferirFlujoFormaCobroOtrosIngresos($idFormaCobro, $nombre);
	}

	/**
	 * Traspaso no se ofrece en el combo de otros ingresos (solo efectivo, depósito, tarjeta, transferencia).
	 */
	private function excluirFormaTraspasoDelComboOtrosIngresos(string $idFormaCobro, string $nombre): bool
	{
		$id = mb_strtoupper(trim($idFormaCobro));
		$nom = mb_strtoupper(trim($nombre));
		if (str_contains($id, 'TRASP')) {
			return true;
		}
		if ($nom !== '' && preg_match('/TRASPAS/i', $nom) === 1) {
			return true;
		}

		return false;
	}

	public function getAutorizaciones(string $codPensum): array
	{
		if (!Schema::hasTable('eco_directiva_gestion')) {
			return [];
		}

		return DB::table('eco_directiva_gestion')
			->where('activo', true)
			->where(function ($q) use ($codPensum) {
				$q->where('cod_pensum', $codPensum)->orWhere('cod_pensum', '');
			})
			->orderBy('gestion')
			->orderBy('numero_aut')
			->get(['numero_aut', 'tipo_facturacion'])
			->unique('numero_aut')
			->values()
			->map(fn ($r) => [
				'numero_aut' => $r->numero_aut,
				'tipo_facturacion' => $r->tipo_facturacion,
			])
			->all();
	}

	/**
	 * Lectura de `eco_directiva_gestion` (opcional). El flujo de otros ingresos replica al SGA usando solo pensum;
	 * este método queda disponible por si más adelante se expone una pantalla de administración que sí filtre por gestión.
	 */
	public function getDirectivasPorGestion(string $gestion): array
	{
		$gestion = trim($gestion);
		if ($gestion === '' || !Schema::hasTable('eco_directiva_gestion')) {
			return [];
		}

		return DB::table('eco_directiva_gestion')
			->where('gestion', $gestion)
			->where('activo', true)
			->orderBy('numero_aut')
			->get([
				'numero_aut',
				'tipo_facturacion',
				'descripcion',
				'num_fact_ini',
				'num_fact_fin',
			])
			->map(function ($r) {
				$ini = $r->num_fact_ini !== null ? (int) $r->num_fact_ini : null;
				$fin = $r->num_fact_fin !== null ? (int) $r->num_fact_fin : null;
				$label = $r->descripcion
					? (string) $r->descripcion
					: $r->numero_aut . ($ini !== null && $fin !== null ? ' · fact. ' . $ini . '–' . $fin : '');
				return [
					'numero_aut' => $r->numero_aut,
					'tipo_facturacion' => $r->tipo_facturacion,
					'label' => $label,
				];
			})
			->values()
			->all();
	}

	/**
	 * Directivas activas para la gestión y pensum (`cod_pensum` vacío en BD = aplica a todos los pensums).
	 *
	 * @return array<int, array{numero_aut: string, tipo_facturacion: ?string, label: string}>
	 */
	public function listDirectivasParaSelector(string $gestion, ?string $codPensum): array
	{
		$gestion = trim($gestion);
		if ($gestion === '' || !Schema::hasTable('eco_directiva_gestion')) {
			return [];
		}
		if ($codPensum === null || $codPensum === '') {
			return [];
		}

		$rows = DB::table('eco_directiva_gestion')
			->where('gestion', $gestion)
			->where('activo', true)
			->where(function ($q) use ($codPensum) {
				$q->where('cod_pensum', $codPensum)->orWhere('cod_pensum', '');
			})
			->orderBy('numero_aut')
			->get(['numero_aut', 'tipo_facturacion']);

		return $rows->map(function ($r) {
			$na = $r->numero_aut;
			$tf = $r->tipo_facturacion;
			return [
				'numero_aut' => $na,
				'tipo_facturacion' => $tf,
				'label' => $na . ($tf ? ' · ' . $tf : ''),
			];
		})->values()->all();
	}

	/** Autorización permitida si no hay directivas para ese contexto, o si coincide con alguna fila activa. */
	public function autorizacionPermitidaParaGestion(string $gestion, string $autorizacion, ?string $codPensum): bool
	{
		$autorizacion = trim($autorizacion);
		if ($autorizacion === '') {
			return true;
		}
		$opts = $this->listDirectivasParaSelector($gestion, $codPensum);
		if ($opts === []) {
			return true;
		}
		foreach ($opts as $o) {
			if ($o['numero_aut'] === $autorizacion) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Valida correlativo de factura contra rangos en `eco_directiva_gestion` (respuesta compatible con el SGA: exito | fuera_de_rango | no_activo).
	 */
	public function perteneceRangoDirectiva(int $factura, string $autorizacion, ?string $gestion = null, ?string $codPensum = null): string
	{
		if ($autorizacion === '') {
			return 'exito';
		}
		if (!Schema::hasTable('eco_directiva_gestion')) {
			return 'exito';
		}

		$q = DB::table('eco_directiva_gestion')
			->where('activo', true)
			->where('numero_aut', $autorizacion);
		$gestion = $gestion !== null ? trim($gestion) : '';
		if ($gestion !== '') {
			$q->where('gestion', $gestion);
		}
		if ($codPensum !== null && $codPensum !== '') {
			$q->where(function ($w) use ($codPensum) {
				$w->where('cod_pensum', $codPensum)->orWhere('cod_pensum', '');
			});
		}
		$rows = $q->get(['num_fact_ini', 'num_fact_fin']);
		if ($rows->isEmpty()) {
			return 'no_activo';
		}

		foreach ($rows as $row) {
			if ($row->num_fact_ini === null && $row->num_fact_fin === null) {
				return 'exito';
			}
			$ini = (int) $row->num_fact_ini;
			$fin = (int) $row->num_fact_fin;
			if ($factura >= $ini && $factura <= $fin) {
				return 'exito';
			}
		}

		return 'fuera_de_rango';
	}

	/**
	 * @return string HTML lista de conflictos o la cadena "exito"
	 */
	public function facturaExiste(int $factura, string $autorizacion): string
	{
		if ($factura <= 0) {
			return 'exito';
		}

		$messages = [];

		$q = OtroIngreso::query()->where('num_factura', $factura);
		if ($autorizacion !== '') {
			$q->where('autorizacion', $autorizacion);
		}
		if ($q->exists()) {
			$messages[] = '<li>El número de factura ya está asignado al registro de OTROS INGRESOS.</li>';
		}

		if (Schema::hasTable('cobro')) {
			$hasCobro = DB::table('cobro')->where('nro_factura', $factura)->exists();
			if ($hasCobro) {
				$messages[] = '<li>El número de factura ya está asignado a un COBRO registrado.</li>';
			}
		}

		if (Schema::hasTable('segunda_instancia')) {
			$has = DB::table('segunda_instancia')->where('num_factura', $factura)->exists();
			if ($has) {
				$messages[] = '<li>El número de factura ya está asignado a una SEGUNDA INSTANCIA.</li>';
			}
		}

		if (Schema::hasTable('rezagados')) {
			$has = DB::table('rezagados')->where('num_factura', $factura)->exists();
			if ($has) {
				$messages[] = '<li>El número de factura ya está asignado a un REZAGADO.</li>';
			}
		}

		return $messages === [] ? 'exito' : implode('', $messages);
	}

	/**
	 * Siguiente Nº de recibo correlativo para `otros_ingresos` (vista previa; sin bloqueo).
	 * Si no hay registros con número > 0, devuelve 1.
	 */
	public function siguienteNumeroReciboOtrosIngresos(): int
	{
		if (!Schema::hasTable('otros_ingresos')) {
			return 1;
		}

		$max = DB::table('otros_ingresos')->max('num_recibo');

		return $max !== null && (int) $max > 0 ? (int) $max + 1 : 1;
	}

	/**
	 * Siguiente correlativo con bloqueo (usar dentro de transacción activa).
	 */
	private function siguienteNumeroReciboOtrosIngresosBloqueado(): int
	{
		$max = DB::table('otros_ingresos')->lockForUpdate()->max('num_recibo');

		return $max !== null && (int) $max > 0 ? (int) $max + 1 : 1;
	}

	/**
	 * Máximo número de factura ya usado en el ecosistema económico (mismas fuentes que {@see facturaExiste}).
	 * Así el correlativo sugerido no choca con cobros u otros módulos que comparten la numeración.
	 *
	 * @param  bool  $lock  Si true, usar dentro de transacción (evita carreras con altas concurrentes).
	 */
	private function maxNumeroFacturaGlobal(bool $lock = false): int
	{
		$m = 0;
		$maxTabla = function (string $tabla, string $columna) use (&$m, $lock): void {
			if (!Schema::hasTable($tabla)) {
				return;
			}
			$q = DB::table($tabla);
			if ($lock) {
				$q->lockForUpdate();
			}
			$v = $q->max($columna);
			$m = max($m, (int) ($v ?? 0));
		};

		$maxTabla('otros_ingresos', 'num_factura');
		$maxTabla('cobro', 'nro_factura');
		$maxTabla('segunda_instancia', 'num_factura');
		$maxTabla('rezagados', 'num_factura');

		return $m;
	}

	/**
	 * Siguiente Nº de factura correlativo (vista previa; sin bloqueo).
	 * Debe alinearse con {@see facturaExiste} para no sugerir un número ya tomado en `cobro` u otras tablas.
	 */
	public function siguienteNumeroFacturaOtrosIngresos(): int
	{
		if (!Schema::hasTable('otros_ingresos')) {
			return 1;
		}

		$max = $this->maxNumeroFacturaGlobal(false);

		return $max > 0 ? $max + 1 : 1;
	}

	private function siguienteNumeroFacturaOtrosIngresosBloqueado(): int
	{
		$max = $this->maxNumeroFacturaGlobal(true);

		return $max > 0 ? $max + 1 : 1;
	}

	/**
	 * @param  array<string,mixed>  $input
	 */
	public function registrar(array $input, Usuario $usuario): array
	{
		$this->validarFechasOtrosIngresoAlRegistrar($input);

		$fecha = $this->parseFechaHora($input['fecha'] ?? null);
		$valido = $input['valido'] ?? 'S';
		$numFactura = (int) ($input['num_factura'] ?? 0);
		$numRecibo = (int) ($input['num_recibo'] ?? 0);
		$nit = trim((string) ($input['nit'] ?? ''));
		$monto = (float) ($input['monto'] ?? 0);
		$subtotal = (float) ($input['subtotal'] ?? $monto);
		$descuento = (float) ($input['descuento'] ?? 0);

		$tipoIngresoText = (string) ($input['tipo_ingreso_text'] ?? '');
		$codTipo = $input['cod_tipo_ingreso'] ?? null;
		$codTipoStr = (string) ($codTipo ?? '');
		$nroOrden = (int) ($input['nro_orden'] ?? 0);
		$ordenSuffix = '';
		if ($codTipoStr === 'OT' || str_contains($tipoIngresoText, 'Ordenes de Trabajo')) {
			$ordenSuffix = $nroOrden > 0 ? ' Nro.' . $nroOrden : '';
		}
		$concepto = ($tipoIngresoText . $ordenSuffix);

		$codigoCarrera = isset($input['codigo_carrera']) ? trim((string) $input['codigo_carrera']) : '';
		$codigoCarrera = $codigoCarrera === '' ? null : $codigoCarrera;

		$head = [
			'num_factura' => $numFactura,
			'num_recibo' => $numRecibo,
			'nit' => $nit,
			'fecha' => $fecha,
			'razon_social' => $input['razon_social'] ?? null,
			'autorizacion' => (string) ($input['autorizacion'] ?? ''),
			'codigo_control' => $input['codigo_control'] ?? null,
			'monto' => $monto,
			'valido' => $valido,
			'usuario' => $usuario->nickname ?? (string) $usuario->id_usuario,
			'concepto' => $concepto,
			'observaciones' => $input['observacion'] ?? null,
			'cod_pensum' => (string) ($input['cod_pensum'] ?? ''),
			'codigo_carrera' => $codigoCarrera,
			'gestion' => (string) ($input['gestion'] ?? ''),
			'subtotal' => $subtotal,
			'descuento' => $descuento,
			'code_tipo_pago' => $input['tipo_pago'] ?? null,
			'tipo_ingreso' => $tipoIngresoText,
			'cod_tipo_ingreso' => $codTipo,
			'factura_recibo' => $input['factura_recibo'] ?? null,
			'es_computarizada' => filter_var($input['computarizada'] ?? false, FILTER_VALIDATE_BOOLEAN),
		];

		if (!Schema::hasColumn('otros_ingresos', 'codigo_carrera')) {
			unset($head['codigo_carrera']);
		}

		$result = DB::transaction(function () use (&$head, $input, $valido, $codTipoStr, $tipoIngresoText) {
			$fr = (string) ($head['factura_recibo'] ?? 'F');
			$v = (string) ($head['valido'] ?? 'S');
			if ($fr === 'R' && $v !== 'A' && (int) ($head['num_recibo'] ?? 0) <= 0) {
				$head['num_recibo'] = $this->siguienteNumeroReciboOtrosIngresosBloqueado();
			}
			if ($fr === 'F' && $v !== 'A' && (int) ($head['num_factura'] ?? 0) <= 0) {
				$head['num_factura'] = $this->siguienteNumeroFacturaOtrosIngresosBloqueado();
			}
			// Un solo tipo de documento por registro: no persistir el correlativo del otro.
			if ($fr === 'F') {
				$head['num_recibo'] = 0;
			} elseif ($fr === 'R') {
				$head['num_factura'] = 0;
			}

			/** @var OtroIngreso $oi */
			$oi = OtroIngreso::query()->create($head);

			if ($valido !== 'A') {
				$ctaRaw = (string) ($input['cta_banco'] ?? '');
				$ctaBanco = trim($ctaRaw);
				if (str_contains($ctaRaw, '::')) {
					$parts = explode('::', $ctaRaw, 2);
					$ctaBanco = trim($parts[1] ?? '');
				} elseif (str_contains($ctaRaw, '-')) {
					// Compatibilidad con valores antiguos `banco-numero_cuenta`
					$parts = explode('-', $ctaRaw, 2);
					$ctaBanco = trim($parts[1] ?? '');
				}
				$nroDep = $input['nro_deposito'] ?? null;
				$nroDep = ($nroDep === '' || $nroDep === null) ? null : (string) $nroDep;
				$fechaDep = $this->parseDateOrNull($input['fecha_deposito'] ?? null);
				$fechaIni = $this->parseDateOrNull($input['fecha_ini'] ?? null);
				$fechaFin = $this->parseDateOrNull($input['fecha_fin'] ?? null);
				$nroOrdenVal = (int) ($input['nro_orden'] ?? 0);
				$nroOrdenVal = $nroOrdenVal === 0 ? null : $nroOrdenVal;
				$conceptoAlq = null;
				if ($codTipoStr === 'ALQ' || $tipoIngresoText === 'Alquileres') {
					$conceptoAlq = trim((string) ($input['concepto_alq'] ?? '')) . ($nroOrdenVal ? ' Nro.' . $nroOrdenVal : '');
				}

				OtroIngresoDetalle::query()->create([
					'otro_ingreso_id' => $oi->id,
					'cta_banco' => $ctaBanco !== '' ? $ctaBanco : null,
					'nro_deposito' => $nroDep,
					'fecha_deposito' => $fechaDep,
					'fecha_ini' => $fechaIni,
					'fecha_fin' => $fechaFin,
					'nro_orden' => $nroOrdenVal,
					'concepto_alquiler' => $conceptoAlq,
				]);
			}

			return [
				'message' => 'exito',
				'url' => null,
				'id' => $oi->id,
				'num_recibo' => $oi->num_recibo,
				'num_factura' => $oi->num_factura,
			];
		});

		$oi = OtroIngreso::query()->find($result['id'] ?? null);
		if ($oi) {
			$pdf = $this->notaOtrosIngresosPdfService->procesarTrasRegistro($oi, $input, $usuario);
			$result['message'] = $pdf['message'];
			$result['url'] = $pdf['url'];
		}

		return $result;
	}

	public function buscarDocumento(string $documento): array
	{
		$doc = trim($documento);
		if ($doc === '') {
			return [];
		}
		return OtroIngreso::query()
			->where('num_factura', $doc)
			->orWhere('num_recibo', $doc)
			->orderBy('fecha')
			->get()
			->values()
			->all();
	}

	public function eliminarPorId(int $id): bool
	{
		$row = OtroIngreso::query()->find($id);
		if (!$row) {
			return false;
		}
		return (bool) $row->delete();
	}

	/**
	 * @param  array<string,mixed>  $data
	 */
	public function actualizarPorId(int $id, array $data): bool
	{
		$row = OtroIngreso::query()->find($id);
		if (!$row) {
			return false;
		}
		$fechaStr = trim((string) ($data['fecha'] ?? ''));
		if ($fechaStr !== '') {
			$this->assertDmyNoFutura($fechaStr, 'fecha');
		}
		$hora = $this->ahoraNegocio()->format('H:i:s');
		$fecha = $this->parseFechaSolo($data['fecha'] ?? null);
		if ($fecha) {
			$fecha = $fecha . ' ' . $hora;
		} else {
			$fecha = $row->fecha;
		}

		$fr = (string) ($row->factura_recibo ?? 'F');

		$fill = [
			'razon_social' => $data['razon_social'] ?? $row->razon_social,
			'nit' => (string) ($data['nit'] ?? $row->nit),
			'autorizacion' => (string) ($data['autorizacion'] ?? $row->autorizacion),
			'fecha' => $fecha,
			'monto' => (float) ($data['monto'] ?? $row->monto),
			'valido' => (string) ($data['valido'] ?? $row->valido),
			'concepto' => $data['concepto'] ?? $row->concepto,
			'cod_pensum' => (string) ($data['cod_pensum'] ?? $row->cod_pensum),
			'gestion' => (string) ($data['gestion'] ?? $row->gestion),
			'subtotal' => (float) ($data['subtotal'] ?? $row->subtotal),
			'descuento' => (float) ($data['descuento'] ?? $row->descuento),
			'observaciones' => $data['observaciones'] ?? $row->observaciones,
		];
		if ($fr === 'F') {
			$fill['num_factura'] = (int) ($data['num_factura'] ?? $row->num_factura);
			$fill['num_recibo'] = 0;
		} elseif ($fr === 'R') {
			$fill['num_recibo'] = (int) ($data['num_recibo'] ?? $row->num_recibo);
			$fill['num_factura'] = 0;
		} else {
			$fill['num_factura'] = (int) ($data['num_factura'] ?? $row->num_factura);
			$fill['num_recibo'] = (int) ($data['num_recibo'] ?? $row->num_recibo);
		}
		if (Schema::hasColumn('otros_ingresos', 'codigo_carrera')) {
			$cc = isset($data['codigo_carrera']) ? trim((string) $data['codigo_carrera']) : '';
			$fill['codigo_carrera'] = $cc === '' ? null : $cc;
		}
		$row->fill($fill);

		return $row->save();
	}

	private function parseFechaHora(?string $dmy): Carbon
	{
		if (!$dmy) {
			return $this->ahoraNegocio();
		}
		$dmy = trim($dmy);
		$tzUse = $this->timezoneNegocio();
		$ts = Carbon::createFromFormat('d/m/Y', $dmy, $tzUse);

		return $ts->setTimeFromTimeString($this->ahoraNegocio()->format('H:i:s'));
	}

	private function parseFechaSolo(?string $dmy): ?string
	{
		if (!$dmy) {
			return null;
		}
		try {
			return Carbon::createFromFormat('d/m/Y', trim($dmy))->format('Y-m-d');
		} catch (\Throwable) {
			return null;
		}
	}

	private function parseDateOrNull(?string $dmy): ?string
	{
		if (!$dmy || trim($dmy) === '') {
			return null;
		}
		try {
			return Carbon::createFromFormat('d/m/Y', trim($dmy))->format('Y-m-d');
		} catch (\Throwable) {
			return null;
		}
	}

	/** Fecha máxima permitida: hoy (sin fechas futuras), alineado al uso en SGA. */
	private function assertDmyNoFutura(string $dmy, string $attribute): void
	{
		$dmy = trim($dmy);
		if ($dmy === '') {
			return;
		}
		$tzUse = $this->timezoneNegocio();
		try {
			$c = Carbon::createFromFormat('d/m/Y', $dmy, $tzUse)->startOfDay();
		} catch (\Throwable) {
			throw ValidationException::withMessages([
				$attribute => ['Formato de fecha inválido (día/mes/año).'],
			]);
		}
		$tope = Carbon::now($tzUse)->startOfDay();
		if ($c->gt($tope)) {
			throw ValidationException::withMessages([
				$attribute => ['No se permiten fechas futuras. La fecha más tardía permitida es '.$tope->format('d/m/Y').'.'],
			]);
		}
	}

	/** @param  array<string,mixed>  $input */
	private function validarFechasOtrosIngresoAlRegistrar(array $input): void
	{
		$f = trim((string) ($input['fecha'] ?? ''));
		if ($f === '') {
			throw ValidationException::withMessages([
				'fecha' => ['La fecha es obligatoria.'],
			]);
		}
		$this->assertDmyNoFutura($f, 'fecha');
		foreach (['fecha_deposito', 'fecha_ini', 'fecha_fin'] as $attr) {
			$v = trim((string) ($input[$attr] ?? ''));
			if ($v !== '') {
				$this->assertDmyNoFutura($v, $attr);
			}
		}
	}
}
