import { Component, EventEmitter, Input, OnInit, Output } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators, FormsModule } from '@angular/forms';
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
    @Input() costoRezagado: number | null = null;
	@Output() addRezagados = new EventEmitter<any>();

	form: FormGroup;
	materias: Array<{ sigla: string; nombre: string; tipo: string; selected: boolean }>=[];
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
	}

	get feeRezagado(): number {
		const n = Number(this.costoRezagado);
		return Number.isFinite(n) && n > 0 ? n : this.FEE_REZAGADO;
	}

	private loadMaterias(): void {
		try {
			const est = this.resumen?.estudiante || {};
			// Seleccionar inscripción preferente: misma gestión si existe, luego mayor cod_inscrip
			const insPreferida = (() => {
				const principal = this.resumen?.inscripcion || null;
				const list = Array.isArray(this.resumen?.inscripciones) ? (this.resumen?.inscripciones || []) : [];
				if (principal) return principal;
				if (!list.length) return {} as any;
				const gestion = (this.resumen?.gestion || '').toString();
				const byGestion = list.filter((x: any) => `${x?.gestion || ''}` === gestion);
				const src = byGestion.length ? byGestion : list;
				return src.slice().sort((a: any, b: any) => Number(b?.cod_inscrip || 0) - Number(a?.cod_inscrip || 0))[0] || {};
			})();
			const ins = insPreferida || {};
			const cod_ceta = est?.cod_ceta || '';
			const cod_pensum = ins?.cod_pensum || est?.cod_pensum || '';
			const cod_inscrip = ins?.cod_inscrip ?? ins?.cod_inscripcion ?? ins?.id_inscripcion ?? ins?.id ?? null;
			const tipo_incripcion = (ins?.tipo_inscripcion ?? ins?.tipo_incripcion ?? null);
			if (!cod_ceta || !cod_pensum) { this.materias = []; return; }
			const gestion = (this.resumen?.gestion || ins?.gestion || '').toString();
			this.cobrosService.getKardexMaterias({ cod_ceta, cod_pensum, cod_inscrip: cod_inscrip ?? undefined, tipo_incripcion: tipo_incripcion ?? undefined, tipo_inscripcion: tipo_incripcion ?? undefined, gestion }).subscribe({
				next: (res) => {
					if (res?.success) {
						this.materias = (res.data || []).map((r: any) => ({
							sigla: r?.sigla_materia || '',
							nombre: r?.nombre_materia || '',
							tipo: (r?.tipo_incripcion || 'NORMAL').toString(),
							selected: false
						}));
					} else {
						this.materias = [];
					}
				},
				error: () => { this.materias = []; }
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

	private loadMateriasFallbackPensum(pensum: string): void {
		this.materias = [];
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
		const count = (this.materias || []).filter(m => m.selected).length;
		return count * this.feeRezagado;
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
		const pagos = (this.materias || []).filter(m => m.selected).map(m => {
			const detalle = `Rezagado - ${m.sigla} ${m.nombre} - ${periodo}er P.`;
			const payload: any = {
				id_forma_cobro: this.form.get('metodo_pago')?.value || null,
				nro_cobro: nro++,
				monto: this.feeRezagado,
				fecha_cobro: hoy,
				observaciones: (() => {
					const base = (this.form.get('observaciones')?.value || '').toString().trim();
					const marker = `[REZAGADO] ${detalle}`;
					return base ? `${base} | ${marker}` : marker;
				})(),
				pu_mensualidad: this.feeRezagado,
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
