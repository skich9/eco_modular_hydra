import { AfterViewInit, Component, OnDestroy, OnInit } from '@angular/core';
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
import {
	listarCamposObligatoriosVacios,
	OtrosIngresosReqField,
	OtrosIngresosReqSnapshot,
} from './otros-ingresos-required-fields';
import { saveBlobAsFile } from '../../../../utils/pdf.helpers';

@Component({
	selector: 'app-otros-ingresos',
	standalone: true,
	imports: [CommonModule, FormsModule],
	templateUrl: './otros-ingresos.component.html',
	styleUrls: ['./otros-ingresos.component.scss'],
})
export class OtrosIngresosComponent implements OnInit, AfterViewInit, OnDestroy {
	/** Equivalente al catálogo SGA «Anulado» (requiere `OtrosIngresosCatalogosSeeder`). */
	private static readonly COD_TIPO_ANULADO = 'ANU';
	private static readonly COD_TIPO_FOTOCOPIADORA = 'FOT';
	private static readonly COD_TIPO_ALQUILER = 'ALQ';
	private static readonly COD_TIPO_TIENDA = 'TDA';
	private static readonly COD_TIPO_ORDEN_TRABAJO = 'OT';
	private static readonly COD_TIPO_VARIOS = 'VAR';
	private static readonly MSG_NRO_ORDEN_OBLIGATORIO = 'Debe ingresar un número de orden de trabajo (obligatorio).';
	/** Factura + computarizado: flujo no implementado (UI bloqueada hasta cambiar documento o medio). */
	static readonly MSG_FACTURA_COMPUTARIZADO_NO_DISPONIBLE =
		'La emisión de facturas por medio computarizado aún no está desarrollada.';

	static readonly MSG_CAMPO_OBLIGATORIO = 'Campo obligatorio';

	/** Texto para mensajes bajo campos obligatorios (plantilla). */
	readonly msgCampoObligatorio = OtrosIngresosComponent.MSG_CAMPO_OBLIGATORIO;

	/** Errores de obligatoriedad mostrados en labels (tras intento de registro o sincronización en vivo). */
	reqErrors: Partial<Record<OtrosIngresosReqField, boolean>> = {};

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
	/** Prefijo Linkser para Nº de transacción (tarjeta); el backend también normaliza. */
	readonly linkserNroTransaccionPrefix = 'linkser ';

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
	/** Solo el dato tras «linkser » cuando el tipo de pago es tarjeta. */
	nroTransaccionTarjetaSufijo = '';
	/** Para detectar cambio de medio (p. ej. tarjeta ↔ depósito) en `onTipoPagoChange`. */
	private prevCodeTipoPago = '';
	/** yyyy-mm-dd para input type="date" (depósito). */
	fechaDepositoIso = '';

	nit = '';
	razonSocial = '';
	anular = false;
	numFactura: number | null = 0;
	/** Mensaje plano si `factura-existe` devuelve conflicto (factura manual). */
	numFacturaDuplicadoError = '';
	numRecibo: number | null = null;
	/** Mensaje plano si `recibo-existe` devuelve conflicto (recibo manual). */
	numReciboDuplicadoError = '';
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

	/** Modal «Editar datos de facturación» (copia local antes de confirmar). */
	showModalFacturacion = false;
	nitModal = '';
	razonModal = '';

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

	/** Evita relanzar búsqueda automática al asignar NIT/razón desde la respuesta del API. */
	private nitProgrammatic = false;

	/** Invalida respuestas HTTP obsoletas si el usuario cambió el documento antes de que lleguen. */
	private nitBusquedaSeq = 0;

	private nitBuscarDebounceTimer: ReturnType<typeof setTimeout> | null = null;

	private static readonly NIT_BUSCAR_DEBOUNCE_MS = 450;

	private numFacturaValidacionSeq = 0;
	private numFacturaDebounceTimer: ReturnType<typeof setTimeout> | null = null;
	private static readonly NUM_FACTURA_VALIDAR_DEBOUNCE_MS = 400;

	private numReciboValidacionSeq = 0;
	private numReciboDebounceTimer: ReturnType<typeof setTimeout> | null = null;
	private static readonly NUM_RECIBO_VALIDAR_DEBOUNCE_MS = 400;

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
		this.sincronizarReqErrorsTrasEdicion();
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

	/** Factura en flujo usable (manual): validar Nº contra BD al escribir. */
	get esFacturaManualValidable(): boolean {
		return this.facturaRecibo === 'F' && !this.bloqueoFacturaComputarizado && this.medioDoc === 'M';
	}

	/** Recibo en flujo usable (manual): validar Nº contra BD al escribir. */
	get esReciboManualValidable(): boolean {
		return this.facturaRecibo === 'R' && !this.bloqueoFacturaComputarizado && this.medioDoc === 'M';
	}

	onTipoPagoChange(): void {
		const prevFlujo = this.flujoTipoPagoDesdeCode(this.prevCodeTipoPago);
		if (!this.anular && this.esPagoEfectivo) {
			this.ctaBanco = '';
		}
		if (this.showFlujoDepositoTarjeta) {
			this.codTipoIngreso = '';
		} else if (this.pagoEraFlujoDepositoTarjeta) {
			this.codTipoIngreso = '';
		}
		if (this.showTarjeta && prevFlujo !== 'tarjeta') {
			this.nroTransaccionTarjetaSufijo = '';
		}
		if (prevFlujo === 'tarjeta' && !this.showTarjeta && this.showFlujoDepositoTarjeta) {
			this.numDeposito = '0';
		}
		this.pagoEraFlujoDepositoTarjeta = this.showFlujoDepositoTarjeta;
		this.prevCodeTipoPago = this.codeTipoPago;
		this.clampTodasLasFechas();
	}

	private flujoTipoPagoDesdeCode(code: string): string {
		if (!code) {
			return '';
		}
		const row = this.tiposPagoOtrosIngresos.find((x) => x.code === code);
		if (row) {
			return row.flujo;
		}
		return OtrosIngresosComponent.mapLegacyCodigoTipoPagoFlujo(code);
	}

	/** Valor enviado en `nro_deposito` cuando el medio es tarjeta (prefijo Linkser + sufijo). */
	private nroDepositoEnvio(): string {
		if (this.showTarjeta) {
			const s = (this.nroTransaccionTarjetaSufijo || '').trim();
			return s === '' ? '' : this.linkserNroTransaccionPrefix + s;
		}
		return this.numDeposito;
	}

	/**
	 * Factura + medio computarizado: combinación no disponible; el formulario queda inoperativo
	 * hasta que el usuario elija otro documento o emisión manual (ver `bloqueoFacturaComputarizado`).
	 */
	static esFacturaComputarizadaNoDisponible(facturaRecibo: 'F' | 'R', medioDoc: 'E' | 'M'): boolean {
		return facturaRecibo === 'F' && medioDoc === 'E';
	}

	get bloqueoFacturaComputarizado(): boolean {
		return OtrosIngresosComponent.esFacturaComputarizadaNoDisponible(this.facturaRecibo, this.medioDoc);
	}

	/** Cliente, montos, rango Del/Al y documento fiscal (salvo reglas SGA de anulación). */
	get tipoFlujoNormalActivo(): boolean {
		return !this.anular && !!this.codTipoIngreso && !this.bloqueoFacturaComputarizado;
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
		if (this.bloqueoFacturaComputarizado) {
			return true;
		}
		if (this.anular) {
			return true;
		}
		if (this.showFlujoDepositoTarjeta) {
			return false;
		}
		return !this.tipoFlujoNormalActivo;
	}

	/** NIT y razón: «Marque para anular» o bloqueo por factura computarizada no disponible. */
	get nitRazonBloqueados(): boolean {
		return this.anular || this.bloqueoFacturaComputarizado;
	}

	/**
	 * Importe / descuento: con efectivo quedan editables aunque aún no se haya elegido tipo de ingreso;
	 * con otros medios siguen bloqueados hasta completar ese paso. Anulación siempre bloquea.
	 */
	get importeDescuentoBloqueados(): boolean {
		if (this.bloqueoFacturaComputarizado) {
			return true;
		}
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
		if (this.bloqueoFacturaComputarizado) {
			return true;
		}
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
		if (this.bloqueoFacturaComputarizado) {
			return true;
		}
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
		if (this.bloqueoFacturaComputarizado) {
			this.toast(OtrosIngresosComponent.MSG_FACTURA_COMPUTARIZADO_NO_DISPONIBLE, false);
		}
		if (this.facturaRecibo !== 'F') {
			this.numFacturaDuplicadoError = '';
			this.clearNumFacturaDebounce();
		} else if (!this.bloqueoFacturaComputarizado) {
			// Factura + manual: mostrar 0 (no correlativo de BD; el servidor asigna si se envía 0).
			this.numFactura = 0;
			this.numFacturaDuplicadoError = '';
			this.clearNumFacturaDebounce();
		}
		if (this.facturaRecibo !== 'R') {
			this.numReciboDuplicadoError = '';
			this.clearNumReciboDebounce();
		}
		// Recibo + manual: vacío. Recibo + computarizado: siempre 0 en pantalla (el correlativo lo asigna el servidor al guardar).
		if (this.facturaRecibo === 'R' && !this.bloqueoFacturaComputarizado) {
			this.numRecibo = this.medioDoc === 'M' ? null : 0;
		}
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
	 * Recibo computarizado: mostrar siempre 0 (no precargar correlativo desde BD; {@see OtrosIngresosService::registrar} asigna).
	 * Recibo manual: no tocar (el usuario ingresa el número).
	 */
	private refrescarCorrelativoRecibo(): void {
		if (this.anular || this.facturaRecibo !== 'R') {
			return;
		}
		if (this.medioDoc === 'M') {
			return;
		}
		this.numRecibo = 0;
	}

	/**
	 * Factura manual: mostrar 0 (no precargar correlativo desde BD; {@see OtrosIngresosService::registrar} asigna si num_factura ≤ 0).
	 * Factura + computarizado: flujo bloqueado en UI; tampoco precargar correlativo.
	 */
	private refrescarCorrelativoFactura(): void {
		if (this.anular || this.facturaRecibo !== 'F') {
			return;
		}
		if (this.medioDoc === 'M') {
			if (this.numFactura == null) {
				this.numFactura = 0;
			}
			return;
		}
		this.numFactura = 0;
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
				// Nº recibo computarizado: no mostrar correlativo de BD; se sincroniza con refrescarCorrelativoRecibo (→ 0).
				// Factura manual: el Nº lo ingresa el usuario (no precarga correlativo).
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
				this.refrescarCorrelativoRecibo();
				this.refrescarCorrelativoFactura();
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

	/** NIT / documento: solo dígitos y guion. */
	private static sanitizeNitDocumentoInput(raw: string): string {
		return (raw ?? '').replace(/[^0-9\-]/g, '');
	}

	/** Razón social: solo letras (incl. acentos/ñ) y espacios. */
	private static sanitizeRazonSocialSoloLetras(raw: string): string {
		return (raw ?? '').replace(/[^\p{L}\s]/gu, '');
	}

	private static sanitizeSoloDigitos(raw: string): string {
		return (raw ?? '').replace(/\D/g, '');
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
			this.sincronizarReqErrorsTrasEdicion();
			return;
		}
		this.carreraService.getPensums(this.codigoCarrera).subscribe({
			next: (res) => {
				const pensums = res?.data ?? [];
				const cod = this.pickMostCurrentPensum(pensums);
				if (!cod) {
					this.toast('La carrera no tiene pensum configurado. Revise la tabla pensums.', false);
					this.sincronizarReqErrorsTrasEdicion();
					return;
				}
				this.codPensum = cod;
				this.sincronizarReqErrorsTrasEdicion();
			},
			error: () => {
				this.toast('No se pudieron obtener pensums de la carrera.', false);
				this.sincronizarReqErrorsTrasEdicion();
			},
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
		this.sincronizarReqErrorsTrasEdicion();
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
		this.sincronizarReqErrorsTrasEdicion();
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
		this.sincronizarReqErrorsTrasEdicion();
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

	/** Importe + recálculo neto + validación reactiva de obligatorios. */
	onImporteChange(): void {
		this.calcNeto();
		this.sincronizarReqErrorsTrasEdicion();
	}

	ngOnDestroy(): void {
		this.clearNitBuscarDebounce();
		this.clearNumFacturaDebounce();
		this.clearNumReciboDebounce();
	}

	onNumFacturaModelChange(): void {
		this.numFacturaDuplicadoError = '';
		if (!this.esFacturaManualValidable) {
			return;
		}
		const nf = Number(this.numFactura) || 0;
		if (nf <= 0) {
			return;
		}
		this.clearNumFacturaDebounce();
		this.numFacturaDebounceTimer = setTimeout(() => {
			this.numFacturaDebounceTimer = null;
			void this.validarNumFacturaDuplicado(false);
		}, OtrosIngresosComponent.NUM_FACTURA_VALIDAR_DEBOUNCE_MS);
	}

	onNumFacturaBlur(): void {
		this.clearNumFacturaDebounce();
		void this.validarNumFacturaDuplicado(true);
	}

	private clearNumFacturaDebounce(): void {
		if (this.numFacturaDebounceTimer != null) {
			clearTimeout(this.numFacturaDebounceTimer);
			this.numFacturaDebounceTimer = null;
		}
	}

	/**
	 * Comprueba si el Nº de factura ya existe en otros ingresos / cobro / etc. (`POST .../factura-existe`).
	 * @param mostrarToast Si es true y hay duplicado, muestra además un toast (además del mensaje bajo el campo).
	 */
	private async validarNumFacturaDuplicado(mostrarToast = false): Promise<void> {
		if (!this.esFacturaManualValidable) {
			this.numFacturaDuplicadoError = '';
			return;
		}
		const nf = Number(this.numFactura) || 0;
		if (nf <= 0) {
			this.numFacturaDuplicadoError = '';
			return;
		}
		const seq = ++this.numFacturaValidacionSeq;
		try {
			const fe = await firstValueFrom(this.svc.facturaExiste(nf, ''));
			if (seq !== this.numFacturaValidacionSeq) {
				return;
			}
			if (fe === 'exito') {
				this.numFacturaDuplicadoError = '';
				return;
			}
			const msg = OtrosIngresosComponent.htmlListaToPlainText(fe);
			this.numFacturaDuplicadoError = msg;
			if (mostrarToast && msg) {
				this.toast('El número de factura ya existe en el sistema: ' + msg, false);
			}
		} catch {
			if (seq !== this.numFacturaValidacionSeq) {
				return;
			}
			const err = 'No se pudo comprobar si el número de factura ya existe.';
			this.numFacturaDuplicadoError = err;
			if (mostrarToast) {
				this.toast(err, false);
			}
		}
	}

	onNumReciboModelChange(): void {
		this.numReciboDuplicadoError = '';
		if (!this.esReciboManualValidable) {
			return;
		}
		const nr = Number(this.numRecibo) || 0;
		if (nr <= 0) {
			return;
		}
		this.clearNumReciboDebounce();
		this.numReciboDebounceTimer = setTimeout(() => {
			this.numReciboDebounceTimer = null;
			void this.validarNumReciboDuplicado(false);
		}, OtrosIngresosComponent.NUM_RECIBO_VALIDAR_DEBOUNCE_MS);
	}

	onNumReciboBlur(): void {
		this.clearNumReciboDebounce();
		void this.validarNumReciboDuplicado(true);
	}

	private clearNumReciboDebounce(): void {
		if (this.numReciboDebounceTimer != null) {
			clearTimeout(this.numReciboDebounceTimer);
			this.numReciboDebounceTimer = null;
		}
	}

	/**
	 * Comprueba si el Nº de recibo ya existe en otros ingresos / cobro / etc. (`POST .../recibo-existe`).
	 */
	private async validarNumReciboDuplicado(mostrarToast = false): Promise<void> {
		if (!this.esReciboManualValidable) {
			this.numReciboDuplicadoError = '';
			return;
		}
		const nr = Number(this.numRecibo) || 0;
		if (nr <= 0) {
			this.numReciboDuplicadoError = '';
			return;
		}
		const seq = ++this.numReciboValidacionSeq;
		try {
			const re = await firstValueFrom(this.svc.reciboExiste(nr));
			if (seq !== this.numReciboValidacionSeq) {
				return;
			}
			if (re === 'exito') {
				this.numReciboDuplicadoError = '';
				return;
			}
			const msg = OtrosIngresosComponent.htmlListaToPlainText(re);
			this.numReciboDuplicadoError = msg;
			if (mostrarToast && msg) {
				this.toast('El número de recibo ya existe en el sistema: ' + msg, false);
			}
		} catch {
			if (seq !== this.numReciboValidacionSeq) {
				return;
			}
			const err = 'No se pudo comprobar si el número de recibo ya existe.';
			this.numReciboDuplicadoError = err;
			if (mostrarToast) {
				this.toast(err, false);
			}
		}
	}

	private static htmlListaToPlainText(html: string): string {
		return String(html)
			.replace(/<[^>]+>/g, ' ')
			.replace(/\s+/g, ' ')
			.trim();
	}

	private clearNitBuscarDebounce(): void {
		if (this.nitBuscarDebounceTimer != null) {
			clearTimeout(this.nitBuscarDebounceTimer);
			this.nitBuscarDebounceTimer = null;
		}
	}

	onNitDocumentoNgModelChange(value: string): void {
		const s = OtrosIngresosComponent.sanitizeNitDocumentoInput(value ?? '');
		if (s !== this.nit) {
			this.nit = s;
		}
		this.onNitValueChange();
	}

	/** Bloquea teclas que no sean dígitos o guion (el pegado lo filtra `sanitizeNitDocumentoInput`). */
	onNitDocumentoKeydown(ev: KeyboardEvent): void {
		if (this.nitRazonBloqueados) {
			return;
		}
		if (ev.ctrlKey || ev.metaKey || ev.altKey) {
			return;
		}
		if (ev.key.length !== 1) {
			return;
		}
		if (!/[0-9\-]/.test(ev.key)) {
			ev.preventDefault();
		}
	}

	onNitModalDocumentoChange(value: string): void {
		const s = OtrosIngresosComponent.sanitizeNitDocumentoInput(value ?? '');
		if (s !== this.nitModal) {
			this.nitModal = s;
		}
	}

	/**
	 * Tras escribir en NIT/documento: consulta catálogo con debounce (sin toasts ni POST a catálogo).
	 * Si se borra el documento, se limpia la razón social para no dejar datos del cliente anterior.
	 */
	onNitValueChange(): void {
		try {
			this.clearNitBuscarDebounce();
			const trimmed = (this.nit || '').trim();
			if (!trimmed) {
				if (!this.nitRazonBloqueados) {
					this.razonSocial = '';
					this.nitProgrammatic = false;
				}
				return;
			}
			if (this.nitRazonBloqueados || this.nitProgrammatic) {
				return;
			}
			this.nitBuscarDebounceTimer = setTimeout(() => {
				this.nitBuscarDebounceTimer = null;
				if (this.nitProgrammatic || this.nitRazonBloqueados) {
					return;
				}
				if (!(this.nit || '').trim()) {
					return;
				}
				this.buscarRazonSocial(true);
			}, OtrosIngresosComponent.NIT_BUSCAR_DEBOUNCE_MS);
		} finally {
			this.sincronizarReqErrorsTrasEdicion();
		}
	}

	/** Razón social: solo letras y espacios; mayúsculas en UI y envío. */
	onRazonSocialInput(value: string): void {
		const u = OtrosIngresosComponent.sanitizeRazonSocialSoloLetras(value ?? '').toUpperCase();
		if (this.razonSocial !== u) {
			this.razonSocial = u;
		}
	}

	onRazonModalInput(value: string): void {
		const u = OtrosIngresosComponent.sanitizeRazonSocialSoloLetras(value ?? '').toUpperCase();
		if (this.razonModal !== u) {
			this.razonModal = u;
		}
	}

	/** Nº transacción (tarjeta): solo dígitos. */
	onNroTransaccionTarjetaSufijoChange(value: string): void {
		const s = OtrosIngresosComponent.sanitizeSoloDigitos(value ?? '');
		if (s !== this.nroTransaccionTarjetaSufijo) {
			this.nroTransaccionTarjetaSufijo = s;
		}
		this.sincronizarReqErrorsTrasEdicion();
	}

	/** Nº depósito (depósito/transferencia): solo dígitos; tarjeta usa otro campo. */
	onNumDepositoChange(value: string): void {
		const s = OtrosIngresosComponent.sanitizeSoloDigitos(value ?? '');
		if (s !== this.numDeposito) {
			this.numDeposito = s;
		}
		this.sincronizarReqErrorsTrasEdicion();
	}

	onNroTransaccionSoloDigitosKeydown(ev: KeyboardEvent): void {
		if (this.camposPagoSecundariosBloqueados) {
			return;
		}
		if (ev.ctrlKey || ev.metaKey || ev.altKey) {
			return;
		}
		if (ev.key.length !== 1) {
			return;
		}
		if (!/[0-9]/.test(ev.key)) {
			ev.preventDefault();
		}
	}

	/** Solo dígitos en Nº depósito / Nº transacción (transferencia). */
	onNumDepositoKeydown(ev: KeyboardEvent): void {
		this.onNroTransaccionSoloDigitosKeydown(ev);
	}

	abrirModalFacturacion(): void {
		if (this.nitRazonBloqueados) {
			return;
		}
		this.nitModal = this.nit;
		this.razonModal = OtrosIngresosComponent.sanitizeRazonSocialSoloLetras(this.razonSocial ?? '').toUpperCase();
		this.showModalFacturacion = true;
	}

	cerrarModalFacturacion(): void {
		this.showModalFacturacion = false;
	}

	confirmarModalFacturacion(): void {
		const n = OtrosIngresosComponent.sanitizeNitDocumentoInput((this.nitModal ?? '').trim());
		const rs = OtrosIngresosComponent.sanitizeRazonSocialSoloLetras(this.razonModal ?? '')
			.trim()
			.toUpperCase();
		if (!n) {
			this.toast('Ingrese el documento (NIT) para guardar en catálogo.', false);
			return;
		}
		this.persistirRazonSocialCatalogo(n, rs, {
			successMessage: 'Datos de facturación actualizados en catálogo.',
			onSuccess: () => {
				this.nit = n;
				this.razonSocial = rs;
				this.showModalFacturacion = false;
				this.nitProgrammatic = true;
				queueMicrotask(() => {
					this.nitProgrammatic = false;
				});
			},
		});
	}

	onNitDocumentoEnter(ev: Event): void {
		if (this.nitRazonBloqueados) {
			return;
		}
		ev.preventDefault();
		this.clearNitBuscarDebounce();
		this.buscarRazonSocial(false);
	}

	/**
	 * Consulta `razon_social` por documento (exacto, trim o parcial).
	 * @param silent Si es true (búsqueda automática): sin toasts ni POST al catálogo; solo rellena si hay coincidencia.
	 */
	buscarRazonSocial(silent = false): void {
		const n = OtrosIngresosComponent.sanitizeNitDocumentoInput((this.nit || '').trim());
		if (!n) {
			if (!silent) {
				this.toast('Ingrese un NIT o documento.', false);
			}
			return;
		}
		const mySeq = ++this.nitBusquedaSeq;
		this.http.get<any>(`${environment.apiUrl}/razon-social/search`, { params: { numero: n } }).subscribe({
			next: (r) => {
				if (mySeq !== this.nitBusquedaSeq) {
					return;
				}
				if (OtrosIngresosComponent.sanitizeNitDocumentoInput((this.nit || '').trim()) !== n) {
					return;
				}
				if (this.nitRazonBloqueados) {
					return;
				}
				const data = r?.data;
				const match = r?.match as string | undefined;
				const rsManual = (this.razonSocial || '').trim();

				if (data && String(data.razon_social ?? '').trim() !== '') {
					this.nitProgrammatic = true;
					if (data.nit != null && String(data.nit).trim() !== '') {
						this.nit = OtrosIngresosComponent.sanitizeNitDocumentoInput(String(data.nit).trim());
					}
					this.razonSocial = OtrosIngresosComponent.sanitizeRazonSocialSoloLetras(
						String(data.razon_social),
					)
						.trim()
						.toUpperCase();
					queueMicrotask(() => {
						this.nitProgrammatic = false;
					});
					if (!silent) {
						const msg =
							match === 'similar'
								? 'Razón social encontrada (coincidencia aproximada por documento).'
								: 'Razón social encontrada.';
						this.toast(msg, true);
					}
					return;
				}

				if (silent) {
					this.razonSocial = '';
				}

				if (!silent) {
					if (data && rsManual) {
						const nitCat = OtrosIngresosComponent.sanitizeNitDocumentoInput(
							String(data.nit ?? n).trim(),
						);
						this.persistirRazonSocialCatalogo(nitCat, rsManual);
						return;
					}

					if (!data && rsManual) {
						this.persistirRazonSocialCatalogo(n, rsManual);
						return;
					}

					this.toast('No hay razón social registrada; puede ingresarla manualmente.', false);
				}
			},
			error: () => {
				if (!silent) {
					this.toast('Error al buscar razón social.', false);
				}
			},
		});
	}

	/**
	 * POST `/razon-social` (tipo documento NIT = 5).
	 * @param options.onSuccess Solo si la API responde éxito (p. ej. cerrar modal tras confirmar edición).
	 */
	private persistirRazonSocialCatalogo(
		nit: string,
		razon: string,
		options?: { successMessage?: string; onSuccess?: () => void },
	): void {
		this.http
			.post<{ success?: boolean; message?: string }>(`${environment.apiUrl}/razon-social`, {
				nit,
				razon_social: razon,
				tipo_id: 5,
			})
			.subscribe({
				next: (res) => {
					if (res?.success) {
						this.toast(options?.successMessage ?? 'Datos guardados en el catálogo de cliente.', true);
						options?.onSuccess?.();
					} else {
						this.toast(res?.message ?? 'No se pudo guardar en catálogo.', false);
					}
				},
				error: (err) => {
					const m = err?.error?.message ?? 'No se pudo guardar en catálogo.';
					this.toast(String(m), false);
				},
			});
	}

	private toast(msg: string, ok: boolean): void {
		this.alertMsg = msg;
		this.alertOk = ok;
		setTimeout(() => (this.alertMsg = ''), 5000);
	}

	/** Descarga automática del PDF de la nota; si falla la petición (p. ej. CORS), abre la URL firmada. */
	private descargarNotaPdfTrasRegistro(url: string): void {
		const nombre = OtrosIngresosComponent.nombreArchivoDesdeUrlNotaPdf(url);
		this.svc.downloadNotaPdfSignedUrl(url).subscribe({
			next: (blob) => saveBlobAsFile(blob, nombre),
			error: () => {
				window.open(url, '_blank', 'noopener,noreferrer');
			},
		});
	}

	private static nombreArchivoDesdeUrlNotaPdf(url: string): string {
		try {
			const u = new URL(url);
			const seg = u.pathname.split('/').filter(Boolean).pop();
			if (seg && /\.pdf$/i.test(seg)) {
				return seg;
			}
		} catch {
			/* ignore */
		}
		return 'nota_otros_ingresos.pdf';
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

	private snapshotReq(): OtrosIngresosReqSnapshot {
		return {
			anular: this.anular,
			codigoCarrera: this.codigoCarrera,
			codPensum: this.codPensum,
			gestionSel: this.gestionSel,
			nit: this.nit,
			codeTipoPago: this.codeTipoPago,
			codTipoIngreso: this.codTipoIngreso,
			showOt: this.showOt,
			nroOrdenOk: this.nroOrdenTieneValorValido(),
			fechaIniIso: this.fechaIniIso,
			fechaFinIso: this.fechaFinIso,
			esPagoEfectivo: this.esPagoEfectivo,
			ctaBanco: this.ctaBanco,
			observacion: this.observacion,
			importe: this.importe,
			showFlujoDepositoTarjeta: this.showFlujoDepositoTarjeta,
			numDeposito: this.showTarjeta ? this.nroTransaccionTarjetaSufijo : this.numDeposito,
		};
	}

	/** Ejecuta validación de obligatorios al intentar registrar; rellena `reqErrors` si falla. */
	private aplicarValidacionObligatoriosSubmit(): boolean {
		const vacios = listarCamposObligatoriosVacios(this.snapshotReq());
		this.reqErrors = Object.fromEntries(vacios.map((k) => [k, true])) as Partial<
			Record<OtrosIngresosReqField, boolean>
		>;
		return vacios.length === 0;
	}

	/**
	 * Quita marcas de error en campos que ya cumplen (validación en vivo tras un intento fallido).
	 */
	sincronizarReqErrorsTrasEdicion(): void {
		if (Object.keys(this.reqErrors).length === 0) {
			return;
		}
		const vacios = new Set(listarCamposObligatoriosVacios(this.snapshotReq()));
		const next = { ...this.reqErrors };
		let changed = false;
		for (const k of Object.keys(next) as OtrosIngresosReqField[]) {
			if (!vacios.has(k)) {
				delete next[k];
				changed = true;
			}
		}
		if (changed) {
			this.reqErrors = next;
		}
	}

	async registrar(): Promise<void> {
		this.alertMsg = '';
		this.nroOrdenError = '';
		if (this.bloqueoFacturaComputarizado) {
			this.toast(OtrosIngresosComponent.MSG_FACTURA_COMPUTARIZADO_NO_DISPONIBLE, false);
			return;
		}
		if (!this.aplicarValidacionObligatoriosSubmit()) {
			this.toast('Complete los campos obligatorios marcados.', false);
			return;
		}
		this.reqErrors = {};
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
				await this.validarNumFacturaDuplicado(false);
				if (this.numFacturaDuplicadoError) {
					this.toast(
						'No se puede registrar: ' + this.numFacturaDuplicadoError,
						false,
					);
					return;
				}
			} catch {
				this.toast('Error al validar factura.', false);
				return;
			}
		}
		if (this.facturaRecibo === 'R' && nr > 0) {
			try {
				await this.validarNumReciboDuplicado(false);
				if (this.numReciboDuplicadoError) {
					this.toast('No se puede registrar: ' + this.numReciboDuplicadoError, false);
					return;
				}
			} catch {
				this.toast('Error al validar recibo.', false);
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
			nit: OtrosIngresosComponent.sanitizeNitDocumentoInput(this.nit.trim()),
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
			nro_deposito: this.nroDepositoEnvio(),
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
				this.reqErrors = {};
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
					this.descargarNotaPdfTrasRegistro(String(res.url));
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
		this.clearNitBuscarDebounce();
		this.nit = '';
		this.razonSocial = '';
		this.anular = false;
		this.tipoIngresoAntesAnular = '';
		this.codeTipoPagoAntesAnular = '';
		this.numFactura = 0;
		this.numFacturaDuplicadoError = '';
		this.clearNumFacturaDebounce();
		this.numReciboDuplicadoError = '';
		this.clearNumReciboDebounce();
		this.numRecibo = null;
		this.importe = 0;
		this.descuento = 0;
		this.importeNeto = 0;
		this.observacion = '';
		this.codTipoIngreso = '';
		this.codeTipoPago = '';
		this.ctaBanco = '';
		this.numDeposito = '0';
		this.nroTransaccionTarjetaSufijo = '';
		this.prevCodeTipoPago = '';
		this.fechaDepositoIso = '';
		this.nroOrden = null;
		this.nroOrdenError = '';
		this.resetFechasPermitidas();
		this.codigoCarrera = '';
		this.codPensum = '';
		this.alertMsg = '';
		this.reqErrors = {};
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
