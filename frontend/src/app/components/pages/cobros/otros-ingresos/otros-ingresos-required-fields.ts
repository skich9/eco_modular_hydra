/**
 * Validación reutilizable de campos obligatorios para el registro de otros ingresos.
 * Usar `listarCamposObligatoriosVacios` desde el componente o pruebas unitarias.
 */

export type OtrosIngresosReqField =
	| 'carrera'
	| 'gestion'
	| 'nit'
	| 'tipoPago'
	| 'tipoIngreso'
	| 'nroOrden'
	| 'rangoFechas'
	| 'bancoCuenta'
	| 'observacion'
	| 'importe'
	| 'numDeposito';

export interface OtrosIngresosReqSnapshot {
	anular: boolean;
	codigoCarrera: string;
	codPensum: string;
	gestionSel: string;
	nit: string;
	codeTipoPago: string;
	codTipoIngreso: string;
	/** Si el tipo de ingreso exige Nº orden de trabajo. */
	showOt: boolean;
	/** Ya validado (entero ≥ 1 cuando showOt). */
	nroOrdenOk: boolean;
	fechaIniIso: string;
	fechaFinIso: string;
	/** Pago no efectivo: debe elegirse cuenta bancaria. */
	esPagoEfectivo: boolean;
	ctaBanco: string;
	observacion: string;
	/** Valor numérico del importe bruto (puede ser null). */
	importe: number | null;
	/** Medio depósito / tarjeta / transferencia (muestra Nº depósito u operación). */
	showFlujoDepositoTarjeta: boolean;
	numDeposito: string;
}

function isoFechaValida(iso: string): boolean {
	return !!iso && /^\d{4}-\d{2}-\d{2}$/.test(iso);
}

/** Lista los identificadores de campos obligatorios que siguen vacíos o inválidos. */
export function listarCamposObligatoriosVacios(s: OtrosIngresosReqSnapshot): OtrosIngresosReqField[] {
	const out: OtrosIngresosReqField[] = [];
	if (!s.codigoCarrera?.trim() || !s.codPensum?.trim()) {
		out.push('carrera');
	}
	if (!(s.gestionSel || '').trim()) {
		out.push('gestion');
	}
	if (!(s.nit || '').trim()) {
		out.push('nit');
	}
	if (!s.codeTipoPago) {
		out.push('tipoPago');
	}
	if (!s.codTipoIngreso) {
		out.push('tipoIngreso');
	}
	if (s.showOt && !s.nroOrdenOk) {
		out.push('nroOrden');
	}
	if (!isoFechaValida(s.fechaIniIso) || !isoFechaValida(s.fechaFinIso)) {
		out.push('rangoFechas');
	}
	if (!s.anular && !s.esPagoEfectivo && !(s.ctaBanco || '').trim()) {
		out.push('bancoCuenta');
	}
	if (!s.anular && !(s.observacion || '').trim()) {
		out.push('observacion');
	}
	if (!s.anular) {
		const imp = Number(s.importe);
		if (!Number.isFinite(imp) || imp <= 0) {
			out.push('importe');
		}
	}
	/** Depósito, tarjeta o transferencia: mismo control (Nº depósito / transacción). */
	if (!s.anular && s.showFlujoDepositoTarjeta) {
		const num = (s.numDeposito || '').trim();
		if (num === '' || num === '0') {
			out.push('numDeposito');
		}
	}
	return out;
}
