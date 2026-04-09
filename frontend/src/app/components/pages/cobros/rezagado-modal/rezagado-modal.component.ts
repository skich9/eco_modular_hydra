import { Component, EventEmitter, Input, OnInit, Output } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators, FormsModule } from '@angular/forms';
import { forkJoin, of } from 'rxjs';
import { catchError } from 'rxjs/operators';
import { CobrosService } from '../../../../services/cobros.service';
import { MateriaService } from '../../../../services/materia.service';

@Component({
	selector: 'app-rezagado-modal',
	standalone: true,
	imports: [CommonModule, ReactiveFormsModule, FormsModule],
	templateUrl: './rezagado-modal.component.html',
	styleUrls: ['./rezagado-modal.component.scss']
})
export class RezagadoModalComponent implements OnInit {
	@Input() formasCobro: any[] = [];
	@Input() cuentasBancarias: any[] = [];
	@Input() baseNro = 1;
	@Input() defaultMetodoPago: string = '';
	@Input() resumen: any = null;
	/** Gestión elegida en cobros (cabecera / búsqueda); filtra materias al kardex de esa gestión. */
	@Input() gestionContexto: string = '';
	@Input() costoRezagado: number | null = null;
	@Output() addRezagados = new EventEmitter<any>();

	form: FormGroup;
	materias: Array<{ sigla: string; nombre: string; tipo: string; selected: boolean }> = [];
	readonly FEE_REZAGADO = 30; // fallback local
	modalAlertMessage: string = '';
	modalAlertType: 'success' | 'error' | 'warning' = 'warning';

	constructor(private fb: FormBuilder, private cobrosService: CobrosService, private materiaService: MateriaService) {
		this.form = this.fb.group({
			metodo_pago: ['', [Validators.required]],
			computarizada: ['COMPUTARIZADA', [Validators.required]],
			comprobante: ['RECIBO', [Validators.required]],
			nro_factura: [''],
			nro_recibo: [''],
			periodo: [1, [Validators.required]], // 1,2,3
			justificativo: [false],
			observaciones: [''],
			// Bancario / Tarjeta / Transferencia / Depósito / Cheque / QR
			id_cuentas_bancarias: [''],
			banco_origen: [''],
			fecha_deposito: [''],
			nro_deposito: [''],
			tarjeta_first4: [''],
			tarjeta_last4: ['']
		});
	}

	private updateComprobanteValidators(): void {
		const nroFac = this.form.get('nro_factura');
		const nroRec = this.form.get('nro_recibo');
		// Numeración autogenerada por backend: no requerir, limpiar siempre
		nroFac?.clearValidators();
		nroRec?.clearValidators();
		nroFac?.setValue(null, { emitEvent: false });
		nroRec?.setValue(null, { emitEvent: false });
		nroFac?.updateValueAndValidity({ emitEvent: false });
		nroRec?.updateValueAndValidity({ emitEvent: false });
	}

	ngOnInit(): void {
		this.loadMaterias();
		if (this.defaultMetodoPago) {
			this.form.patchValue({ metodo_pago: this.defaultMetodoPago }, { emitEvent: false });
		}
		this.form.get('metodo_pago')?.valueChanges.subscribe(() => this.updateBancarioValidators());
		this.updateBancarioValidators();
		// Validadores de comprobante
		this.form.get('comprobante')?.valueChanges.subscribe(() => this.updateComprobanteValidators());
		this.updateComprobanteValidators();
		this.form.get('periodo')?.valueChanges.subscribe(() => this.clearSelectionOnPaidRezagados());
	}

	get feeRezagado(): number {
		const n = Number(this.costoRezagado);
		return Number.isFinite(n) && n > 0 ? n : this.FEE_REZAGADO;
	}

	get costoMateria(): number {
		const conJustificativo = this.form.get('justificativo')?.value === true;
		return conJustificativo ? 0 : this.feeRezagado;
	}

	/** Gestión mostrada y usada para cargar materias (prioriza la seleccionada en cobros). */
	get gestionMostrada(): string {
		return (this.gestionContexto || this.resumen?.gestion || '').toString();
	}

	/** Inscripción que coincide con la gestión actual (para pensum/tipo en cabecera). */
	get inscripcionParaGestion(): any | null {
		const g = (this.gestionContexto || this.resumen?.gestion || '').toString().trim();
		const list = Array.isArray(this.resumen?.inscripciones) ? this.resumen.inscripciones : [];
		if (g && list.length) {
			const x = list.find((i: any) => `${i?.gestion ?? ''}`.trim() === g);
			if (x) return x;
		}
		return this.resumen?.inscripcion ?? null;
	}

	/**
	 * Claves `SIGLA|1|2|3` según `resumen.boleta_rezagados` (tabla rezagados: materia = sigla, parcial = período).
	 */
	private getRezagadoPagadoKeys(): Set<string> {
		const rows = Array.isArray(this.resumen?.boleta_rezagados) ? this.resumen.boleta_rezagados : [];
		const set = new Set<string>();
		for (const r of rows) {
			const sigla = (r?.nombre_materia ?? r?.sigla_materia ?? r?.materia ?? '')
				.toString()
				.trim()
				.toUpperCase();
			const p = `${r?.parcial ?? ''}`.trim();
			if (sigla && (p === '1' || p === '2' || p === '3')) {
				set.add(`${sigla}|${p}`);
			}
		}
		return set;
	}

	/** True si ya existe rezagado pagado para esa sigla y el período elegido en el formulario (1–3). */
	isRezagadoPagadoParaPeriodo(sigla: string): boolean {
		const periodo = Number(this.form.get('periodo')?.value ?? 1);
		if (periodo < 1 || periodo > 3) return false;
		const key = `${sigla.toString().trim().toUpperCase()}|${periodo}`;
		return this.getRezagadoPagadoKeys().has(key);
	}

	private clearSelectionOnPaidRezagados(): void {
		for (const m of this.materias) {
			if (this.isRezagadoPagadoParaPeriodo(m.sigla)) {
				m.selected = false;
			}
		}
	}

	private loadMaterias(): void {
		try {
			const est = this.resumen?.estudiante || {};
			const gSel = (this.gestionContexto || this.resumen?.gestion || '').toString().trim();

			const insPreferida = (() => {
				const principal = this.resumen?.inscripcion || null;
				const list = Array.isArray(this.resumen?.inscripciones) ? (this.resumen?.inscripciones || []) : [];
				if (gSel) {
					const byG = list.filter((x: any) => `${x?.gestion ?? ''}`.trim() === gSel);
					if (byG.length) {
						return byG.slice().sort((a: any, b: any) => Number(b?.cod_inscrip || 0) - Number(a?.cod_inscrip || 0))[0];
					}
					if (principal && `${principal?.gestion ?? ''}`.trim() === gSel) {
						return principal;
					}
				}
				if (principal) return principal;
				if (!list.length) return {} as any;
				const byGestion = list.filter((x: any) => `${x?.gestion || ''}` === (this.resumen?.gestion || '').toString());
				const src = byGestion.length ? byGestion : list;
				return src.slice().sort((a: any, b: any) => Number(b?.cod_inscrip || 0) - Number(a?.cod_inscrip || 0))[0] || {};
			})();
			const ins = insPreferida || {};

			const cod_ceta = est?.cod_ceta || '';
			const cod_pensum = ins?.cod_pensum || est?.cod_pensum || '';

			if (!cod_ceta || !cod_pensum) {
				this.materias = [];
				return;
			}

			// Misma gestión que el selector de cobros; el API filtra kardex por inscripciones de esa gestión.
			const gestion = (this.gestionContexto || this.resumen?.gestion || ins?.gestion || '').toString();
			const params: { cod_ceta: string | number; cod_pensum: string; gestion?: string; cod_inscrip?: string | number } = {
				cod_ceta,
				cod_pensum,
				gestion
			};
			if (ins?.cod_inscrip != null && ins?.cod_inscrip !== '') {
				params.cod_inscrip = ins.cod_inscrip;
			}

			forkJoin({
				pensum: this.materiaService.getByPensum(String(cod_pensum)).pipe(
					catchError(() => of({ success: false as const, data: [] as any[] }))
				),
				kardex: this.cobrosService.getKardexMaterias(params).pipe(
					catchError(() => of({ success: false as const, data: [] as any[] }))
				)
			}).subscribe({
				next: ({ pensum, kardex }) => {
					const nombreBySigla = new Map<string, string>();
					if (pensum?.success && Array.isArray(pensum.data)) {
						for (const m of pensum.data) {
							const sigU = (m?.sigla_materia ?? '').toString().trim().toUpperCase();
							if (sigU) {
								nombreBySigla.set(sigU, (m?.nombre_materia || m?.sigla_materia || '').toString());
							}
						}
					}

					const kRows = kardex?.success && Array.isArray(kardex.data) ? kardex.data : [];
					// Solo materias del kardex en la gestión indicada (no todo el pensum).
					if (kRows.length > 0) {
						this.materias = kRows.map((r: any) => {
							const sig = (r?.sigla_materia || '').toString().trim();
							const sigU = sig.toUpperCase();
							const nombre = (r?.nombre_materia || nombreBySigla.get(sigU) || sig).toString();
							return {
								sigla: sig,
								nombre,
								tipo: (r?.tipo_incripcion || 'NORMAL').toString(),
								selected: false
							};
						});
					} else {
						this.materias = [];
					}
					this.clearSelectionOnPaidRezagados();
				},
				error: () => {
					this.materias = [];
				}
			});
		} catch {
			this.materias = [];
		}
	}

	private getFieldLabel(name: string): string {
		const map: Record<string, string> = {
			metodo_pago: 'Método de Pago',
			comprobante: 'Comprobante',
			id_cuentas_bancarias: 'Cuenta destino',
			fecha_deposito: 'Fecha depósito',
			nro_deposito: 'Num. depósito',
			banco_origen: 'Banco Origen',
			tarjeta_first4: 'Nº Tarjeta (4 primeros)',
			tarjeta_last4: 'Nº Tarjeta (4 últimos)'
		};
		return map[name] || name;
	}

	private collectMissingFieldsForMetodo(): string[] {
		const out: string[] = [];
		const addIfMissing = (n: string) => {
			const c = this.form.get(n);
			if (!c) return;
			c.updateValueAndValidity({ emitEvent: false });
			const v = (c.value ?? '').toString().trim();
			const invalid = !v || c.invalid;
			if (invalid) {
				try { c.markAsTouched(); } catch {}
				out.push(this.getFieldLabel(n));
			}
		};
		const metodo = (this.form.get('metodo_pago')?.value || '').toString();
		if (!metodo) addIfMissing('metodo_pago');
		const comp = (this.form.get('comprobante')?.value || '').toString().toUpperCase();
		if (!(comp === 'RECIBO' || comp === 'FACTURA')) out.push(this.getFieldLabel('comprobante'));
		if (this.isTarjeta) {
			['id_cuentas_bancarias','fecha_deposito','nro_deposito','banco_origen','tarjeta_first4','tarjeta_last4'].forEach(addIfMissing);
		} else if (this.isTransferencia) {
			['id_cuentas_bancarias','fecha_deposito','nro_deposito','banco_origen'].forEach(addIfMissing);
		} else if (this.isCheque || this.isDeposito) {
			['id_cuentas_bancarias','fecha_deposito','nro_deposito'].forEach(addIfMissing);
		}
		return out;
	}

	get isTarjeta(): boolean {
		const match = this.getSelectedForma();
		const nombre = (match?.descripcion_sin ?? match?.nombre ?? match?.name ?? match?.descripcion ?? match?.label ?? '').toString().trim().toUpperCase();
		return nombre.includes('TARJETA');
	}

	get isCheque(): boolean {
		const match = this.getSelectedForma();
		const nombre = (match?.descripcion_sin ?? match?.nombre ?? match?.name ?? match?.descripcion ?? match?.label ?? '').toString().trim().toUpperCase();
		return nombre.includes('CHEQUE');
	}

	get isDeposito(): boolean {
		const match = this.getSelectedForma();
		const raw = (match?.descripcion_sin ?? match?.nombre ?? match?.name ?? match?.descripcion ?? match?.label ?? '').toString().trim().toUpperCase();
		const nombre = raw.normalize('NFD').replace(/\p{Diacritic}/gu, '');
		return nombre.includes('DEPOSITO');
	}

	get isTransferencia(): boolean {
		const match = this.getSelectedForma();
		const raw = (match?.descripcion_sin ?? match?.nombre ?? match?.name ?? match?.descripcion ?? match?.label ?? '').toString().trim().toUpperCase();
		const nombre = raw.normalize('NFD').replace(/\p{Diacritic}/gu, '');
		return nombre.includes('TRANSFER');
	}

	get isQR(): boolean {
		const match = this.getSelectedForma();
		const raw = (match?.descripcion_sin ?? match?.nombre ?? match?.name ?? match?.descripcion ?? match?.label ?? '').toString().trim().toUpperCase();
		const nombre = raw.normalize('NFD').replace(/\p{Diacritic}/gu, '');
		return nombre.includes('QR');
	}

	get isOtro(): boolean {
		const match = this.getSelectedForma();
		const raw = (match?.descripcion_sin ?? match?.nombre ?? match?.name ?? match?.descripcion ?? match?.label ?? '').toString().trim().toUpperCase();
		const nombre = raw.normalize('NFD').replace(/\p{Diacritic}/gu, '');
		if (!nombre) return false;
		if (nombre.includes('TRANSFER') || nombre.includes('TARJETA') || nombre.includes('CHEQUE') || nombre.includes('DEPOSITO') || nombre.includes('QR')) return false;
		return nombre.includes('OTRO') || nombre.includes('VALES') || nombre.includes('PAGO POSTERIOR');
	}

	get showBancarioBlock(): boolean {
		if (this.isOtro) return false;
		if (this.isQR) return false;
		return this.isCheque || this.isDeposito || this.isTransferencia || this.isTarjeta;
	}

	get cuentasBancariasFiltradas(): any[] {
		const comprobante = (this.form.get('comprobante')?.value || '').toString().toUpperCase();

		if (!comprobante || (comprobante !== 'RECIBO' && comprobante !== 'FACTURA')) {
			return this.cuentasBancarias || [];
		}

		// I_R = true → Recibo, I_R = false → Factura
		const esRecibo = comprobante === 'RECIBO';

		return (this.cuentasBancarias || []).filter((cuenta: any) => {
			const i_r = cuenta?.I_R;
			return esRecibo ? i_r === true : i_r === false;
		});
	}

	private getSelectedForma(): any | null {
		const val = (this.form.get('metodo_pago')?.value || '').toString();
		if (!val) return null;
		const list = (this.formasCobro || []) as any[];
		let match = list.find((f: any) => `${f?.codigo_sin}` === val);
		if (!match) match = list.find((f: any) => `${f?.id_forma_cobro}` === val);
		return match || null;
	}

	labelForma(f: any): string {
		const raw = (f?.descripcion_sin ?? f?.nombre ?? f?.name ?? f?.descripcion ?? f?.label ?? '').toString().trim();
		if (raw) return raw;
		const code = Number(f?.codigo_sin);
		if (code === 1) return 'Efectivo';
		if (code === 2) return 'Tarjeta';
		if (code === 3) return 'Cheque';
		if (code === 4) return 'Depósito';
		if (code === 5) return 'Transferencia';
		return (f?.nombre || 'Otro');
	}

	private updateBancarioValidators(): void {
		const enableTarjeta = this.isTarjeta;
		const enableCheque = this.isCheque;
		const enableDeposito = this.isDeposito;
		const enableTransfer = this.isTransferencia;
		const enableQR = this.isQR;
		const idCuentaCtrl = this.form.get('id_cuentas_bancarias');
		const first4Ctrl = this.form.get('tarjeta_first4');
		const last4Ctrl = this.form.get('tarjeta_last4');
		const fechaDepCtrl = this.form.get('fecha_deposito');
		const nroDepCtrl = this.form.get('nro_deposito');
		const bancoOrigenCtrl = this.form.get('banco_origen');

		// Validadores generales (excluye QR)
		if (enableTarjeta || enableCheque || enableDeposito || enableTransfer) {
			idCuentaCtrl?.setValidators([Validators.required]);
			fechaDepCtrl?.setValidators([Validators.required]);
			nroDepCtrl?.setValidators([Validators.required]);
		} else {
			idCuentaCtrl?.clearValidators();
			fechaDepCtrl?.clearValidators();
			nroDepCtrl?.clearValidators();
		}
		if (enableTarjeta) {
			first4Ctrl?.setValidators([Validators.required, Validators.pattern(/^\d{4}$/)]);
			last4Ctrl?.setValidators([Validators.required, Validators.pattern(/^\d{4}$/)]);
			bancoOrigenCtrl?.setValidators([Validators.required]);
		} else if (enableTransfer) {
			bancoOrigenCtrl?.setValidators([Validators.required]);
			first4Ctrl?.clearValidators();
			last4Ctrl?.clearValidators();
		} else {
			first4Ctrl?.clearValidators();
			last4Ctrl?.clearValidators();
			bancoOrigenCtrl?.clearValidators();
		}


		// Comportamiento específico para QR: deshabilitar y limpiar campos bancarios
		if (enableQR) {
			if (!idCuentaCtrl?.value) {
				try {
					const list = (this.cuentasBancarias || []) as any[];
					const first = list.find((c: any) => c?.habilitado_QR === true) || list[0];
					if (first) idCuentaCtrl?.setValue(first.id_cuentas_bancarias, { emitEvent: false });
				} catch {}
			}
			fechaDepCtrl?.setValue('', { emitEvent: false });
			nroDepCtrl?.setValue('', { emitEvent: false });
			bancoOrigenCtrl?.setValue('', { emitEvent: false });
			fechaDepCtrl?.disable({ emitEvent: false });
			nroDepCtrl?.disable({ emitEvent: false });
			bancoOrigenCtrl?.disable({ emitEvent: false });
			first4Ctrl?.disable({ emitEvent: false });
			last4Ctrl?.disable({ emitEvent: false });
		} else {
			fechaDepCtrl?.enable({ emitEvent: false });
			nroDepCtrl?.enable({ emitEvent: false });
			bancoOrigenCtrl?.enable({ emitEvent: false });
			first4Ctrl?.enable({ emitEvent: false });
			last4Ctrl?.enable({ emitEvent: false });
		}

		idCuentaCtrl?.updateValueAndValidity({ emitEvent: false });
		first4Ctrl?.updateValueAndValidity({ emitEvent: false });
		last4Ctrl?.updateValueAndValidity({ emitEvent: false });
		fechaDepCtrl?.updateValueAndValidity({ emitEvent: false });
		nroDepCtrl?.updateValueAndValidity({ emitEvent: false });
		bancoOrigenCtrl?.updateValueAndValidity({ emitEvent: false });
	}

	get total(): number {
		const count = (this.materias || []).filter(
			m => m.selected && !this.isRezagadoPagadoParaPeriodo(m.sigla)
		).length;
		return count * this.costoMateria;
	}

	open(): void {
		const modalEl = document.getElementById('rezagadoModal');
		const bs = (window as any).bootstrap;
		if (modalEl && bs?.Modal) {
			// Defaults
			this.form.patchValue({ computarizada: 'COMPUTARIZADA', periodo: 1, justificativo: false }, { emitEvent: false });
            // refrescar materias cada vez que se abre (por si cambió el resumen)
            this.loadMaterias();
			const modal = new bs.Modal(modalEl);
			modal.show();
		}
	}

	addAndClose(): void {
		if (!this.form.valid) {
			try { this.form.markAllAsTouched(); } catch {}
			const missing = this.collectMissingFieldsForMetodo();
			this.modalAlertMessage = missing.length ? `Complete los siguientes campos: ${missing.join(', ')}.` : 'Complete los campos obligatorios.';
			this.modalAlertType = 'warning';
			return;
		}
		const compSelRaw = (this.form.get('comprobante')?.value || '').toString().toUpperCase();
		const tipo_documento = compSelRaw === 'FACTURA' ? 'F' : (compSelRaw === 'RECIBO' ? 'R' : '');
		const medio_doc = (this.form.get('computarizada')?.value === 'MANUAL') ? 'M' : 'C';
		const hoy = new Date().toISOString().slice(0, 10);
		const periodo = Number(this.form.get('periodo')?.value || 1);
		let nro = this.baseNro || 1;
		const conJustificativo = this.form.get('justificativo')?.value === true;
		const montoRezagado = conJustificativo ? 0 : this.feeRezagado;
		const pagos = (this.materias || [])
			.filter(m => m.selected && !this.isRezagadoPagadoParaPeriodo(m.sigla))
			.map(m => {
			const detalle = `Rezagado - ${m.sigla} ${m.nombre} - ${periodo}er P.`;
			const payload: any = {
				id_forma_cobro: this.form.get('metodo_pago')?.value || null,
				nro_cobro: nro++,
				monto: montoRezagado,
				fecha_cobro: hoy,
				observaciones: (() => {
					const base = (this.form.get('observaciones')?.value || '').toString().trim();
					const marker = `[REZAGADO] ${detalle}`;
					const justif = conJustificativo ? ' [CON JUSTIFICATIVO]' : '';
					return base ? `${base} | ${marker}${justif}` : `${marker}${justif}`;
				})(),
				pu_mensualidad: montoRezagado,
				cantidad: 1,
				detalle,
				// doc/medio
				tipo_documento,
				medio_doc,
				comprobante: compSelRaw || 'NINGUNO',
				computarizada: this.form.get('computarizada')?.value,
				// bancarias
				id_cuentas_bancarias: this.form.get('id_cuentas_bancarias')?.value || null,
				banco_origen: this.form.get('banco_origen')?.value || null,
				fecha_deposito: this.form.get('fecha_deposito')?.value || null,
				nro_deposito: this.form.get('nro_deposito')?.value || null,
				tarjeta_first4: this.form.get('tarjeta_first4')?.value || null,
				tarjeta_last4: this.form.get('tarjeta_last4')?.value || null,
				// opcionales
				descuento: null,
				nro_factura: compSelRaw === 'FACTURA' ? (this.form.get('nro_factura')?.value || null) : null,
				nro_recibo: compSelRaw === 'RECIBO' ? (this.form.get('nro_recibo')?.value || null) : null,
			};
			return payload;
		});

		if (this.showBancarioBlock) {
			this.addRezagados.emit({ pagos, cabecera: { id_cuentas_bancarias: this.form.get('id_cuentas_bancarias')?.value || null } });
		} else {
			this.addRezagados.emit(pagos);
		}

		const modalEl = document.getElementById('rezagadoModal');
		const bs = (window as any).bootstrap;
		if (modalEl && bs?.Modal) {
			const instance = bs.Modal.getInstance(modalEl) || new bs.Modal(modalEl);
			instance.hide();
		}
		if (this.isTarjeta || this.isCheque || this.isDeposito || this.isTransferencia) {
			this.resetTarjetaFields();
		}
	}

	private resetTarjetaFields(): void {
		const names = ['banco_origen','tarjeta_first4','tarjeta_last4','id_cuentas_bancarias','fecha_deposito','nro_deposito'];
		for (const n of names) {
			const c = this.form.get(n);
			if (!c) continue;
			c.setValue('', { emitEvent: false });
			c.markAsPristine();
			c.markAsUntouched();
			c.updateValueAndValidity({ emitEvent: false });
		}
	}
}
