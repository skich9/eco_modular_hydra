import { AfterViewInit, Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { HttpClient } from '@angular/common/http';
import { firstValueFrom } from 'rxjs';
import { OtrosIngresosService } from '../../../../services/otros-ingresos.service';
import { CarreraService } from '../../../../services/carrera.service';
import { Carrera } from '../../../../models/carrera.model';
import { Pensum } from '../../../../models/materia.model';
import { environment } from '../../../../../environments/environment';

@Component({
	selector: 'app-otros-ingresos',
	standalone: true,
	imports: [CommonModule, FormsModule],
	templateUrl: './otros-ingresos.component.html',
	styleUrls: ['./otros-ingresos.component.scss'],
})
export class OtrosIngresosComponent implements OnInit, AfterViewInit {
	/** Equivalente al catálogo SGA «Anulado» (requiere `OtrosIngresosCatalogosSeeder`). */
	private static readonly COD_TIPO_ANULADO = 'ANU';
	private static readonly COD_TIPO_FOTOCOPIADORA = 'FOT';
	private static readonly COD_TIPO_ALQUILER = 'ALQ';
	private static readonly COD_TIPO_TIENDA = 'TDA';
	private static readonly COD_TIPO_ORDEN_TRABAJO = 'OT';
	private static readonly COD_TIPO_VARIOS = 'VAR';
	private static readonly MSG_NRO_ORDEN_OBLIGATORIO = 'Debe ingresar un número de orden de trabajo (obligatorio).';
	facturaRecibo: 'F' | 'R' = 'R';
	medioDoc: 'E' | 'M' = 'E';
	/** Carrera elegida por el usuario (cobros no ligados a estudiantes / pensum). */
	carreras: Carrera[] = [];
	codigoCarrera = '';
	/** Pensum técnico resuelto desde la carrera (columna `cod_pensum` en BD). */
	codPensum = '';
	gestiones: Array<{ gestion: string }> = [];
	gestionCobro: string | null = null;
	gestionSel = '';
	tiposIngreso: Array<{ cod_tipo_ingreso: string; nom_tipo_ingreso: string }> = [];
	codTipoIngreso = '';

	/** Respaldo si `GET initial` no trae `formas_cobro` (misma semántica SGA: E, D, L, B). */
	private static readonly TIPOS_PAGO_FALLBACK: Array<{ code: string; label: string; flujo: string }> = [
		{ code: 'D', label: 'Depósito', flujo: 'deposito' },
		{ code: 'E', label: 'Efectivo', flujo: 'efectivo' },
		{ code: 'L', label: 'Tarjeta', flujo: 'tarjeta' },
		{ code: 'B', label: 'Transferencia', flujo: 'transferencia' },
	];

	/** Catálogo desde BD `formas_cobro` (`code` = id_forma_cobro, `flujo` = reglas de UI). */
	tiposPagoOtrosIngresos: Array<{ code: string; label: string; flujo: string }> = [
		...OtrosIngresosComponent.TIPOS_PAGO_FALLBACK,
	];

	/** Tipo de ingreso fijo cuando el pago es depósito (final del LADO 1). */
	readonly tiposIngresoDeposito: Array<{ cod: string; nom: string }> = [
		{ cod: 'FOT', nom: 'Ingreso por fotocopiadora' },
		{ cod: 'ALQ', nom: 'Ingreso por alquileres' },
		{ cod: 'TDA', nom: 'Ingreso por tienda' },
		{ cod: 'OT', nom: 'Ingreso por Orden de trabajo' },
		{ cod: 'VAR', nom: 'Ingresos varios' },
		{ cod: 'ANU', nom: 'Ingreso anulado' },
	];
	cuentas: any[] = [];
	codeTipoPago = '';
	ctaBanco = '';
	numDeposito = '0';
	/** yyyy-mm-dd para input type="date" (depósito). */
	fechaDepositoIso = '';

	nit = '';
	razonSocial = '';
	anular = false;
	numFactura: number | null = 0;
	numRecibo: number | null = 0;
	importe: number | null = 0;
	descuento: number | null = 0;
	importeNeto = 0;
	observacion = '';

	/** Rango Del → Al (yyyy-mm-dd), como en SGA. */
	fechaIniIso = '';
	fechaFinIso = '';
	periodoAlq = 'Enero';
	nroOrden: number | null = null;
	/** Mensaje de validación bajo Nº orden de trabajo (tipo OT). */
	nroOrdenError = '';

	meses = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

	alertMsg = '';
	alertOk = true;
	loading = false;

	showResumen = false;
	resumenCarrera = '';
	resumenGestion = '';
	resumenCliente = '';
	resumenNit = '';
	resumenMonto = 0;
	resumenNumRecibo: number | null = null;

	/** Al marcar «anular», se restaura al desmarcar. */
	private tipoIngresoAntesAnular = '';
	private codeTipoPagoAntesAnular = '';
	/** Evita borrar `codTipoIngreso` al cambiar entre medios que no son depósito/tarjeta/transferencia. */
	private pagoEraFlujoDepositoTarjeta = false;

	constructor(
		private readonly svc: OtrosIngresosService,
		private readonly carreraService: CarreraService,
		private readonly http: HttpClient,
		private readonly router: Router
	) {}

	ngOnInit(): void {
		this.resetFechasPermitidas();
		this.cargarCatalogos();
	}

	ngAfterViewInit(): void {
		queueMicrotask(() => this.clampTodasLasFechas());
	}

	get fechaIniDmyVisible(): string {
		return this.dmyFromIso(this.fechaIniIso);
	}

	get fechaFinDmyVisible(): string {
		return this.dmyFromIso(this.fechaFinIso);
	}

	/**
	 * Fuerza fechas dentro de [min, max]: corrige autocompletado del navegador o valores pegados (p. ej. 2026).
	 */
	clampTodasLasFechas(): void {
		const max = this.maxFechaIso;
		const min = this.minFechaIso;
		const clamp = (v: string, required: boolean): string => {
			if (!v || !/^\d{4}-\d{2}-\d{2}$/.test(v)) {
				return required ? max : '';
			}
			if (v > max) {
				return max;
			}
			if (v < min) {
				return min;
			}
			return v;
		};
		this.fechaDepositoIso = clamp(this.fechaDepositoIso, false);
		this.fechaIniIso = clamp(this.fechaIniIso, true);
		this.fechaFinIso = clamp(this.fechaFinIso, true);
		if (this.showFlujoDepositoTarjeta) {
			const fd = this.fechaDepositoIso;
			if (fd && /^\d{4}-\d{2}-\d{2}$/.test(fd)) {
				// Depósito/tarjeta/transferencia + fotocopiadora o tienda: el rango Del/Al es independiente de la fecha de depósito.
				if (!this.esDepositoFotocopiadoraOTienda) {
					this.fechaFinIso = fd;
					this.fechaIniIso = fd;
				}
			}
		} else if (this.esPagoEfectivo && !this.esTipoIngresoConRangoDelAlEnEfectivo()) {
			this.fechaIniIso = this.fechaFinIso;
		} else if (this.fechaIniIso > this.fechaFinIso) {
			this.fechaIniIso = this.fechaFinIso;
		}
	}

	/** Último día seleccionable: hoy (sin mañana ni posteriores). */
	get maxFechaIso(): string {
		return this.toIsoLocal(new Date());
	}

	readonly minFechaIso = '1990-01-01';

	private toIsoLocal(d: Date): string {
		const y = d.getFullYear();
		const m = String(d.getMonth() + 1).padStart(2, '0');
		const day = String(d.getDate()).padStart(2, '0');
		return `${y}-${m}-${day}`;
	}

	private hoyIso(): string {
		return this.toIsoLocal(new Date());
	}

	private dmyFromIso(iso: string): string {
		if (!iso || !/^\d{4}-\d{2}-\d{2}$/.test(iso)) {
			return '';
		}
		const [y, m, d] = iso.split('-');
		return `${d}/${m}/${y}`;
	}

	private resetFechasPermitidas(): void {
		const a = this.hoyIso();
		this.fechaIniIso = a;
		this.fechaFinIso = a;
		this.fechaDepositoIso = a;
		this.clampTodasLasFechas();
	}

	private fechaIsoPermitida(iso: string): boolean {
		return !!iso && iso <= this.maxFechaIso && iso >= this.minFechaIso;
	}

	get showAlquiler(): boolean {
		return this.codTipoIngreso === OtrosIngresosComponent.COD_TIPO_ALQUILER;
	}

	get showOt(): boolean {
		return this.codTipoIngreso === OtrosIngresosComponent.COD_TIPO_ORDEN_TRABAJO;
	}

	/** Clasificación del medio de pago según catálogo `formas_cobro` + compatibilidad códigos SGA. */
	private get formaFlujoPago(): string {
		const row = this.tiposPagoOtrosIngresos.find((x) => x.code === this.codeTipoPago);
		if (row?.flujo) {
			return String(row.flujo).toLowerCase();
		}
		return OtrosIngresosComponent.mapLegacyCodigoTipoPagoFlujo(this.codeTipoPago);
	}

	/** Alineado con backend: traspaso no entra en el combo de otros ingresos. */
	private static esTraspasoExcluidoDelCombo(code: string, label: string): boolean {
		const c = (code || '').toUpperCase();
		const l = (label || '').toUpperCase();
		if (c.includes('TRASP')) {
			return true;
		}
		return /TRASPAS/i.test(l);
	}

	private static mapLegacyCodigoTipoPagoFlujo(code: string): string {
		const c = (code || '').toUpperCase();
		if (c === 'E' || c === 'EF') {
			return 'efectivo';
		}
		if (c === 'D' || c.includes('DEP')) {
			return 'deposito';
		}
		if (c === 'L' || c === 'TC') {
			return 'tarjeta';
		}
		if (c === 'B' || c === 'T' || c === 'TR' || c === 'QR') {
			return 'transferencia';
		}
		if (c.includes('TRANSFER')) {
			return 'transferencia';
		}
		return 'otro';
	}

	private primerCodigoFormaEfectivo(): string {
		const row = this.tiposPagoOtrosIngresos.find((t) => t.flujo === 'efectivo');
		return row?.code ?? 'E';
	}

	get showDeposito(): boolean {
		if (this.formaFlujoPago === 'deposito') {
			return true;
		}
		const c = (this.codeTipoPago || '').toUpperCase();
		return c.includes('DEP');
	}

	get showTarjeta(): boolean {
		return this.formaFlujoPago === 'tarjeta';
	}

	get showTransferencia(): boolean {
		return this.formaFlujoPago === 'transferencia';
	}

	/** Misma UI y reglas que depósito: catálogo tipo ingreso depósito, Nº/fecha depósito, rango, bloqueos, etc. (también tarjeta y transferencia). */
	get showFlujoDepositoTarjeta(): boolean {
		return this.showDeposito || this.showTarjeta || this.showTransferencia;
	}

	get esPagoEfectivo(): boolean {
		return this.formaFlujoPago === 'efectivo';
	}

	onTipoPagoChange(): void {
		if (!this.anular && this.esPagoEfectivo) {
			this.ctaBanco = '';
		}
		if (this.showFlujoDepositoTarjeta) {
			this.codTipoIngreso = '';
		} else if (this.pagoEraFlujoDepositoTarjeta) {
			this.codTipoIngreso = '';
		}
		this.pagoEraFlujoDepositoTarjeta = this.showFlujoDepositoTarjeta;
		this.clampTodasLasFechas();
	}

	/** Cliente, montos, rango Del/Al y documento fiscal (salvo reglas SGA de anulación). */
	get tipoFlujoNormalActivo(): boolean {
		return !this.anular && !!this.codTipoIngreso;
	}

	get fechaRangoEditable(): boolean {
		return this.tipoFlujoNormalActivo;
	}

	/** Fotocopiadora o Tienda: con efectivo se muestra rango Del/Al editable (inputs nativos). */
	private esTipoIngresoConRangoDelAlEnEfectivo(): boolean {
		const c = this.codTipoIngreso;
		return (
			c === OtrosIngresosComponent.COD_TIPO_FOTOCOPIADORA || c === OtrosIngresosComponent.COD_TIPO_TIENDA
		);
	}

	get esEfectivoRangoFechasNativo(): boolean {
		return this.esPagoEfectivo && this.esTipoIngresoConRangoDelAlEnEfectivo();
	}

	/** Depósito/tarjeta/transferencia + fotocopiadora o tienda: rango Del/Al con datepickers nativos (independiente de fecha depósito en clamp). */
	get esDepositoFotocopiadoraOTienda(): boolean {
		return this.showFlujoDepositoTarjeta && this.esTipoIngresoConRangoDelAlEnEfectivo();
	}

	/** Fila Del/Al con inputs nativos (efectivo FOT/TDA o depósito FOT/TDA). */
	get esRangoFechasNativoRow(): boolean {
		return this.esEfectivoRangoFechasNativo || this.esDepositoFotocopiadoraOTienda;
	}

	/** Tope para «Del»: no puede ser posterior a «Al» ni a hoy. */
	get fechaRangoDelMaxIso(): string {
		const cap = this.maxFechaIso;
		if (!this.fechaFinIso || !/^\d{4}-\d{2}-\d{2}$/.test(this.fechaFinIso)) {
			return cap;
		}
		return this.fechaFinIso <= cap ? this.fechaFinIso : cap;
	}

	/** Piso para «Al»: no puede ser anterior a «Del». */
	get fechaRangoAlMinIso(): string {
		const floor = this.minFechaIso;
		if (!this.fechaIniIso || !/^\d{4}-\d{2}-\d{2}$/.test(this.fechaIniIso)) {
			return floor;
		}
		return this.fechaIniIso >= floor ? this.fechaIniIso : floor;
	}

	/**
	 * Rango Del/Al: sin efectivo siempre salvo depósito/tarjeta/transferencia (salvo ese flujo+fotocopiadora/tienda);
	 * con efectivo solo si tipo es fotocopiadora o tienda.
	 */
	get mostrarRangoFechas(): boolean {
		if (this.showFlujoDepositoTarjeta) {
			return this.esDepositoFotocopiadoraOTienda;
		}
		if (!this.esPagoEfectivo) {
			return true;
		}
		return this.esTipoIngresoConRangoDelAlEnEfectivo();
	}

	/**
	 * Depósito/tarjeta/transferencia: banco/cuenta y Nº depósito habilitados al elegir el medio (sin esperar tipo ingreso).
	 * Otros medios: cuenta tras tipo de ingreso operativo.
	 */
	get camposPagoSecundariosBloqueados(): boolean {
		if (this.anular) {
			return true;
		}
		if (this.showFlujoDepositoTarjeta) {
			return false;
		}
		return !this.tipoFlujoNormalActivo;
	}

	/** NIT y razón: solo se bloquean con «Marque para anular». */
	get nitRazonBloqueados(): boolean {
		return this.anular;
	}

	/**
	 * Importe / descuento: con efectivo quedan editables aunque aún no se haya elegido tipo de ingreso;
	 * con otros medios siguen bloqueados hasta completar ese paso. Anulación siempre bloquea.
	 */
	get importeDescuentoBloqueados(): boolean {
		if (this.anular) {
			return true;
		}
		if (this.esPagoEfectivo) {
			return false;
		}
		return !this.tipoFlujoNormalActivo;
	}

	/**
	 * Réplica de reglas SGA: factura/recibo readonly según Factura/Recibo, Computarizado/Manual y anular.
	 * Factura + Manual: Nº factura editable sin esperar tipo de ingreso (misma idea que Recibo + Manual).
	 */
	get facturaInputReadonly(): boolean {
		if (this.anular) {
			return this.facturaRecibo !== 'F';
		}
		if (this.facturaRecibo === 'R') {
			return true;
		}
		if (this.medioDoc === 'M') {
			return false;
		}
		if (!this.codTipoIngreso) {
			return true;
		}
		// Factura + Computarizado
		return true;
	}

	get reciboInputReadonly(): boolean {
		if (this.anular) {
			return this.facturaRecibo !== 'R';
		}
		if (this.facturaRecibo === 'F') {
			return true;
		}
		// Recibo + Manual: Nº recibo editable sin esperar tipo de ingreso (SGA).
		if (this.medioDoc === 'M') {
			return false;
		}
		if (!this.codTipoIngreso) {
			return true;
		}
		// Recibo + Computarizado
		return true;
	}

	/**
	 * Con «Marque para anular» solo se lista Anulado; en flujo normal se incluye también en el catálogo.
	 */
	get tiposIngresoVisibles(): Array<{ cod_tipo_ingreso: string; nom_tipo_ingreso: string }> {
		const anu = OtrosIngresosComponent.COD_TIPO_ANULADO;
		if (this.anular) {
			return this.tiposIngreso.filter((t) => t.cod_tipo_ingreso === anu);
		}
		return this.tiposIngreso;
	}

	sincronizarEstadoDocumentos(): void {
		this.clampTodasLasFechas();
	}

	onFacturaReciboMedioChange(): void {
		this.sincronizarEstadoDocumentos();
		this.refrescarCorrelativoRecibo();
		this.refrescarCorrelativoFactura();
	}

	onAnularChange(): void {
		if (this.anular) {
			this.tipoIngresoAntesAnular =
				this.codTipoIngreso === OtrosIngresosComponent.COD_TIPO_ANULADO ? '' : this.codTipoIngreso;
			this.codeTipoPagoAntesAnular = this.codeTipoPago;
			this.codTipoIngreso = OtrosIngresosComponent.COD_TIPO_ANULADO;
			this.codeTipoPago = this.primerCodigoFormaEfectivo();
			this.razonSocial = 'ANULADO';
			this.nit = '0';
			this.importe = 0;
			this.descuento = 0;
			this.calcNeto();
		} else {
			this.codTipoIngreso = this.tipoIngresoAntesAnular;
			this.tipoIngresoAntesAnular = '';
			this.codeTipoPago = this.codeTipoPagoAntesAnular || '';
			this.codeTipoPagoAntesAnular = '';
			if (this.codTipoIngreso === OtrosIngresosComponent.COD_TIPO_ANULADO) {
				this.codTipoIngreso = '';
			}
			this.razonSocial = '';
			this.nit = '';
		}
		this.sincronizarEstadoDocumentos();
		this.refrescarCorrelativoRecibo();
		this.refrescarCorrelativoFactura();
	}

	/**
	 * Rellena el Nº recibo correlativo (MAX+1 en BD) cuando el documento es Recibo y el campo iría en 0
	 * (p. ej. Recibo + Computarizado, solo lectura hasta completar el flujo).
	 */
	private refrescarCorrelativoRecibo(): void {
		if (this.anular || this.facturaRecibo !== 'R') {
			return;
		}
		if (this.medioDoc === 'M' && this.numRecibo != null && this.numRecibo > 0) {
			return;
		}
		this.svc.getSiguienteNumRecibo().subscribe({
			next: (r) => {
				const n = r?.siguiente;
				if (typeof n === 'number' && n > 0) {
					this.numRecibo = n;
				}
			},
			error: () => {},
		});
	}

	/**
	 * Nº factura correlativo (`otros_ingresos.num_factura`): Computarizado = sugerido de BD; Manual = no pisa si el usuario ya ingresó valor.
	 */
	private refrescarCorrelativoFactura(): void {
		if (this.anular || this.facturaRecibo !== 'F') {
			return;
		}
		if (this.medioDoc === 'M' && this.numFactura != null && this.numFactura > 0) {
			return;
		}
		this.svc.getSiguienteNumFactura().subscribe({
			next: (r) => {
				const n = r?.siguiente;
				if (typeof n === 'number' && n > 0) {
					this.numFactura = n;
				}
			},
			error: () => {},
		});
	}

	cargarCatalogos(): void {
		this.carreraService.getAll().subscribe({
			next: (r) => {
				const list = r?.data ?? [];
				this.carreras = list.filter((c) => c.estado !== false);
			},
			error: () => this.toast('No se pudieron cargar las carreras.', false),
		});

		this.svc.getInitial().subscribe({
			next: (res: any) => {
				const d = res?.data ?? res;
				this.gestiones = d.gestiones ?? [];
				this.gestionCobro = d.gestion_cobro ?? null;
				this.tiposIngreso = d.tipos_ingreso ?? [];
				const fc = d.formas_cobro as Array<{ code: string; label: string; flujo?: string }> | undefined;
				if (fc?.length) {
					const mapped = fc
						.filter((x) => !OtrosIngresosComponent.esTraspasoExcluidoDelCombo(x.code, x.label))
						.map((x) => ({
							code: x.code,
							label: x.label,
							flujo: (x.flujo ?? OtrosIngresosComponent.mapLegacyCodigoTipoPagoFlujo(x.code)).toLowerCase(),
						}));
					this.tiposPagoOtrosIngresos = mapped.length
						? mapped
						: [...OtrosIngresosComponent.TIPOS_PAGO_FALLBACK];
				} else {
					this.tiposPagoOtrosIngresos = [...OtrosIngresosComponent.TIPOS_PAGO_FALLBACK];
				}
				const codAnu = OtrosIngresosComponent.COD_TIPO_ANULADO;
				if (!this.tiposIngreso.some((t) => t.cod_tipo_ingreso === codAnu)) {
					this.tiposIngreso = [
						...this.tiposIngreso,
						{ cod_tipo_ingreso: codAnu, nom_tipo_ingreso: 'Anulado' },
					];
				}
				if (typeof d.siguiente_num_recibo === 'number' && d.siguiente_num_recibo > 0 && this.facturaRecibo === 'R' && !this.anular) {
					this.numRecibo = d.siguiente_num_recibo;
				}
				if (typeof d.siguiente_num_factura === 'number' && d.siguiente_num_factura > 0 && this.facturaRecibo === 'F' && !this.anular) {
					this.numFactura = d.siguiente_num_factura;
				}
				if (this.gestionCobro) {
					this.gestionSel = this.gestionCobro;
				} else if (this.gestiones.length) {
					this.gestionSel = this.gestiones[0].gestion;
				}
				if (!this.gestiones.length || !this.tiposIngreso.length) {
					const faltan: string[] = [];
					if (!this.gestiones.length) {
						faltan.push('gestiones activas');
					}
					if (!this.tiposIngreso.length) {
						faltan.push('tipo_otro_ingreso (catálogo)');
					}
					this.toast('Faltan datos para el formulario: ' + faltan.join(', ') + '.', false);
				}
			},
			error: () => this.toast('No se pudieron cargar los datos iniciales (¿sesión / API?).', false),
		});

		this.http.get<any>(`${environment.apiUrl}/cuentas-bancarias`).subscribe({
			next: (r) => {
				this.cuentas = r?.data ?? [];
			},
			error: () => {
				this.cuentas = [];
			},
		});
	}

	/** Sufijo numérico final de `cod_pensum` (p. ej. EEA-26 → 26). */
	private static numSuffixCodPensum(cod: string): number {
		const m = /-(\d+)$/.exec((cod || '').trim());
		return m ? parseInt(m[1], 10) : 0;
	}

	/**
	 * Pensum más actual de la carrera: entre activos (si hay), el de mayor sufijo en código (EEA-26 > EEA-19);
	 * empate: mayor `orden`; luego más reciente por fechas del registro.
	 */
	private pickMostCurrentPensum(pensums: Pensum[]): string | null {
		if (!pensums?.length) {
			return null;
		}
		const active = pensums.filter(
			(p) => p.estado !== false && (p as { activo?: boolean }).activo !== false,
		);
		const pool = active.length ? active : [...pensums];
		const ts = (p: Pensum) =>
			(p as { updated_at?: string; created_at?: string }).updated_at ||
			(p as { created_at?: string }).created_at ||
			'';
		const best = pool
			.map((p) => ({
				p,
				n: OtrosIngresosComponent.numSuffixCodPensum(p.cod_pensum),
				orden: p.orden ?? 0,
				t: ts(p),
			}))
			.sort((a, b) => {
				if (b.n !== a.n) {
					return b.n - a.n;
				}
				if (b.orden !== a.orden) {
					return b.orden - a.orden;
				}
				return b.t.localeCompare(a.t);
			})[0];
		return best?.p.cod_pensum ?? null;
	}

	onCarreraChange(): void {
		this.codPensum = '';
		if (!this.codigoCarrera) {
			return;
		}
		this.carreraService.getPensums(this.codigoCarrera).subscribe({
			next: (res) => {
				const pensums = res?.data ?? [];
				const cod = this.pickMostCurrentPensum(pensums);
				if (!cod) {
					this.toast('La carrera no tiene pensum configurado. Revise la tabla pensums.', false);
					return;
				}
				this.codPensum = cod;
			},
			error: () => this.toast('No se pudieron obtener pensums de la carrera.', false),
		});
	}

	onTipoIngresoChange(): void {
		this.nroOrdenError = '';
		if (this.codTipoIngreso === OtrosIngresosComponent.COD_TIPO_ORDEN_TRABAJO) {
			this.nroOrden = null;
		}
		this.clampTodasLasFechas();
		this.sincronizarEstadoDocumentos();
		this.refrescarCorrelativoRecibo();
		this.refrescarCorrelativoFactura();
	}

	/** Obligatorio con tipo OT: entero ≥ 1 (campo no vacío). */
	private nroOrdenTieneValorValido(): boolean {
		if (!this.showOt) {
			return true;
		}
		const v = this.nroOrden;
		if (v === null || v === undefined) {
			return false;
		}
		const n = Number(v);
		if (String(v).trim() === '' || Number.isNaN(n)) {
			return false;
		}
		return Number.isFinite(n) && n >= 1 && Number.isInteger(n);
	}

	onNroOrdenInput(): void {
		if (this.nroOrdenTieneValorValido()) {
			this.nroOrdenError = '';
		}
	}

	onNroOrdenBlur(): void {
		if (!this.showOt || !this.tipoFlujoNormalActivo) {
			return;
		}
		if (this.nroOrdenTieneValorValido()) {
			this.nroOrdenError = '';
		} else {
			this.nroOrdenError = OtrosIngresosComponent.MSG_NRO_ORDEN_OBLIGATORIO;
		}
	}

	/** Valor del `<option>`: separador `::` para que el backend tome bien el Nº cuenta aunque el nombre del banco lleve guiones. */
	cuentaValor(c: any): string {
		return `${c.banco}::${c.numero_cuenta}`;
	}

	calcNeto(): void {
		const bruto = Number(this.importe) || 0;
		const desc = Number(this.descuento) || 0;
		this.importeNeto = Math.max(0, bruto - desc);
	}

	buscarRazonSocial(): void {
		const n = (this.nit || '').trim();
		if (!n) {
			this.toast('Ingrese un NIT o documento.', false);
			return;
		}
		this.http.get<any>(`${environment.apiUrl}/razon-social/search`, { params: { numero: n } }).subscribe({
			next: (r) => {
				const data = r?.data;
				if (data?.razon_social) {
					this.razonSocial = data.razon_social;
					this.toast('Razón social encontrada.', true);
				} else {
					this.toast('No hay razón social registrada; puede ingresarla manualmente.', false);
				}
			},
			error: () => this.toast('Error al buscar razón social.', false),
		});
	}

	private toast(msg: string, ok: boolean): void {
		this.alertMsg = msg;
		this.alertOk = ok;
		setTimeout(() => (this.alertMsg = ''), 5000);
	}

	private nombreTipoSeleccionado(): string {
		if (this.showFlujoDepositoTarjeta) {
			const d = this.tiposIngresoDeposito.find((x) => x.cod === this.codTipoIngreso);
			if (d) {
				return d.nom;
			}
		}
		if (this.codTipoIngreso === OtrosIngresosComponent.COD_TIPO_ANULADO) {
			return 'Anulado';
		}
		const t = this.tiposIngreso.find((x) => x.cod_tipo_ingreso === this.codTipoIngreso);
		return t?.nom_tipo_ingreso ?? '';
	}

	async registrar(): Promise<void> {
		this.alertMsg = '';
		this.nroOrdenError = '';
		if (!this.codigoCarrera || !this.codPensum || !this.gestionSel) {
			this.toast('Seleccione carrera y gestión.', false);
			return;
		}
		if (!(this.nit || '').trim()) {
			this.toast('El documento (NIT) es obligatorio.', false);
			return;
		}
		if (!this.codTipoIngreso) {
			this.toast('Seleccione el tipo de ingreso.', false);
			return;
		}
		if (!this.codeTipoPago) {
			this.toast('Seleccione el tipo de pago.', false);
			return;
		}
		if (!this.nroOrdenTieneValorValido()) {
			this.nroOrdenError = OtrosIngresosComponent.MSG_NRO_ORDEN_OBLIGATORIO;
			this.toast(this.nroOrdenError, false);
			return;
		}
		if (!this.fechaIsoPermitida(this.fechaIniIso) || !this.fechaIsoPermitida(this.fechaFinIso)) {
			this.toast(
				'Las fechas Del / Al no pueden ser futuras. La más tardía permitida es ' + this.dmyFromIso(this.maxFechaIso) + '.',
				false,
			);
			return;
		}
		if (this.fechaIniIso > this.fechaFinIso) {
			this.toast('“Del” no puede ser posterior a “Al”.', false);
			return;
		}
		if (this.showFlujoDepositoTarjeta && this.fechaDepositoIso && !this.fechaIsoPermitida(this.fechaDepositoIso)) {
			this.toast('La fecha de depósito no puede ser futura.', false);
			return;
		}
		this.calcNeto();
		const nf = Number(this.numFactura) || 0;
		const nr = Number(this.numRecibo) || 0;
		if (this.facturaRecibo === 'F' && nf > 0) {
			try {
				const p = await firstValueFrom(
					this.svc.perteneceDirectiva(nf, '', this.gestionSel, this.codPensum),
				);
				if (p !== 'exito') {
					this.toast('El número de factura no está en el rango de la directiva (' + p + ').', false);
					return;
				}
				const fe = await firstValueFrom(this.svc.facturaExiste(nf, ''));
				if (fe !== 'exito') {
					const detalle = String(fe)
						.replace(/<[^>]+>/g, ' ')
						.replace(/\s+/g, ' ')
						.trim();
					this.toast(detalle || 'Conflicto de numeración de factura.', false);
					return;
				}
			} catch {
				this.toast('Error al validar factura.', false);
				return;
			}
		}

		const conceptoAlq = this.showAlquiler ? this.periodoAlq : '';

		// Fecha principal en API = día «Al» (fin del rango Del–Al).
		const payload: Record<string, unknown> = {
			fecha: this.dmyFromIso(this.fechaFinIso),
			cod_pensum: this.codPensum,
			codigo_carrera: this.codigoCarrera || null,
			nombre_carrera:
				this.carreras.find((c) => c.codigo_carrera === this.codigoCarrera)?.nombre ?? null,
			gestion: this.gestionSel,
			nit: this.nit.trim(),
			razon_social: this.razonSocial.trim() || null,
			num_factura: this.facturaRecibo === 'F' ? nf : 0,
			num_recibo: this.facturaRecibo === 'R' ? nr : 0,
			autorizacion: '',
			monto: this.importeNeto,
			subtotal: Number(this.importe) || 0,
			descuento: Number(this.descuento) || 0,
			valido:
				(this.anular || this.codTipoIngreso === OtrosIngresosComponent.COD_TIPO_ANULADO) ? 'A' : 'S',
			tipo_ingreso_text: this.nombreTipoSeleccionado(),
			cod_tipo_ingreso: this.codTipoIngreso,
			tipo_pago: this.codeTipoPago,
			observacion: this.observacion,
			factura_recibo: this.facturaRecibo,
			computarizada: this.medioDoc === 'E',
			cta_banco: this.ctaBanco || '',
			nro_deposito: this.numDeposito,
			fecha_deposito: this.showFlujoDepositoTarjeta ? this.dmyFromIso(this.fechaDepositoIso) : '',
			fecha_ini: this.dmyFromIso(this.fechaIniIso),
			fecha_fin: this.dmyFromIso(this.fechaFinIso),
			nro_orden: Number(this.nroOrden) || 0,
			concepto_alq: conceptoAlq,
		};

		this.loading = true;
		this.svc.registrar(payload).subscribe({
			next: (res: { num_recibo?: number; num_factura?: number; message?: string; url?: string | null }) => {
				this.loading = false;
				this.resumenCarrera =
					this.carreras.find((c) => c.codigo_carrera === this.codigoCarrera)?.nombre ?? this.codigoCarrera;
				this.resumenGestion = this.gestionSel;
				this.resumenCliente = this.razonSocial;
				this.resumenNit = this.nit;
				this.resumenMonto = this.importeNeto;
				this.resumenNumRecibo =
					this.facturaRecibo === 'R' ? (res?.num_recibo ?? this.numRecibo) : (res?.num_factura ?? this.numFactura);
				this.showResumen = true;
				if (res?.url) {
					window.open(String(res.url), '_blank', 'noopener,noreferrer');
				}
				this.toast('Registro guardado correctamente.', true);
				this.refrescarCorrelativoRecibo();
				this.refrescarCorrelativoFactura();
			},
			error: (err) => {
				this.loading = false;
				const e = err?.error;
				let m = e?.message ?? 'No se pudo registrar.';
				if (e?.errors && typeof e.errors === 'object') {
					const first = Object.values(e.errors)[0];
					if (Array.isArray(first) && first[0]) {
						m = String(first[0]);
					}
				}
				this.toast(m, false);
			},
		});
	}

	limpiar(): void {
		this.nit = '';
		this.razonSocial = '';
		this.anular = false;
		this.tipoIngresoAntesAnular = '';
		this.codeTipoPagoAntesAnular = '';
		this.numFactura = 0;
		this.numRecibo = 0;
		this.importe = 0;
		this.descuento = 0;
		this.importeNeto = 0;
		this.observacion = '';
		this.codTipoIngreso = '';
		this.codeTipoPago = '';
		this.ctaBanco = '';
		this.numDeposito = '0';
		this.fechaDepositoIso = '';
		this.nroOrden = null;
		this.nroOrdenError = '';
		this.resetFechasPermitidas();
		this.codigoCarrera = '';
		this.codPensum = '';
		this.alertMsg = '';
		this.sincronizarEstadoDocumentos();
		this.refrescarCorrelativoRecibo();
		this.refrescarCorrelativoFactura();
	}

	cerrarResumen(): void {
		this.showResumen = false;
		this.limpiar();
	}

	cancelar(): void {
		this.router.navigate(['/dashboard']);
	}
}
