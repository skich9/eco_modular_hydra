import { Component, EventEmitter, Input, OnInit, Output } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators, FormsModule } from '@angular/forms';

@Component({
	selector: 'app-reincorporacion-modal',
	standalone: true,
	imports: [CommonModule, ReactiveFormsModule, FormsModule],
	templateUrl: './reincorporacion-modal.component.html',
	styleUrls: ['./reincorporacion-modal.component.scss']
})
export class ReincorporacionModalComponent implements OnInit {
	@Input() formasCobro: any[] = [];
	@Input() cuentasBancarias: any[] = [];
	@Input() baseNro = 1;
	@Input() defaultMetodoPago: string = '';
	@Input() resumen: any = null;
	@Input() costoReincorporacion: number = 0;
	@Output() addReincorporacion = new EventEmitter<any>();

	form: FormGroup;
	modalAlertMessage: string = '';
	modalAlertType: 'success' | 'error' | 'warning' = 'warning';

	constructor(private fb: FormBuilder) {
		this.form = this.fb.group({
			metodo_pago: ['', [Validators.required]],
			computarizada: ['COMPUTARIZADA', [Validators.required]],
			comprobante: ['RECIBO', [Validators.required]],
			nro_factura: [''],
			nro_recibo: [''],
			pago_parcial: [false],
			monto_parcial: [0, [Validators.min(0)]],
			observaciones: [''],
			fecha_cobro: [new Date().toISOString().slice(0, 10), Validators.required],
			id_cuentas_bancarias: [''],
			banco_origen: [''],
			fecha_deposito: [''],
			nro_deposito: [''],
			tarjeta_first4: [''],
			tarjeta_last4: ['']
		});
	}

	ngOnInit(): void {
		if (this.defaultMetodoPago) {
			this.form.patchValue({ metodo_pago: this.defaultMetodoPago }, { emitEvent: false });
		}
		this.form.get('metodo_pago')?.valueChanges.subscribe(() => this.updateBancarioValidators());
		this.form.get('pago_parcial')?.valueChanges.subscribe((parcial: boolean) => {
			const ctrl = this.form.get('monto_parcial');
			if (parcial) {
				ctrl?.setValidators([Validators.required, Validators.min(0.01), Validators.max(this.costoReincorporacion)]);
				ctrl?.enable({ emitEvent: false });
			} else {
				ctrl?.clearValidators();
				ctrl?.setValue(0, { emitEvent: false });
				ctrl?.disable({ emitEvent: false });
			}
			ctrl?.updateValueAndValidity({ emitEvent: false });
		});
		this.updateBancarioValidators();
		this.updateComprobanteValidators();
		this.form.get('comprobante')?.valueChanges.subscribe(() => this.updateComprobanteValidators());
	}

	private updateComprobanteValidators(): void {
		const nroFac = this.form.get('nro_factura');
		const nroRec = this.form.get('nro_recibo');
		nroFac?.clearValidators();
		nroRec?.clearValidators();
		nroFac?.setValue(null, { emitEvent: false });
		nroRec?.setValue(null, { emitEvent: false });
		nroFac?.updateValueAndValidity({ emitEvent: false });
		nroRec?.updateValueAndValidity({ emitEvent: false });
	}

	get total(): number {
		const parcial = this.form.get('pago_parcial')?.value;
		if (parcial) {
			return Number(this.form.get('monto_parcial')?.value || 0);
		}
		return this.costoReincorporacion;
	}

	get isTarjeta(): boolean {
		return (this.form.get('metodo_pago')?.value || '').toString().toUpperCase() === 'TARJETA';
	}

	get isDeposito(): boolean {
		return (this.form.get('metodo_pago')?.value || '').toString().toUpperCase() === 'DEPOSITO';
	}

	get isTransferencia(): boolean {
		return (this.form.get('metodo_pago')?.value || '').toString().toUpperCase() === 'TRANSFERENCIA';
	}

	get showBancarioBlock(): boolean {
		return this.isTarjeta || this.isDeposito || this.isTransferencia;
	}

	labelForma(f: any): string {
		return f?.nombre || f?.descripcion || f?.id_forma_cobro || '';
	}

	private updateBancarioValidators(): void {
		const metodo = (this.form.get('metodo_pago')?.value || '').toString().toUpperCase();
		const needsBanco = ['TARJETA', 'DEPOSITO', 'TRANSFERENCIA'].includes(metodo);
		const needsTarjeta = metodo === 'TARJETA';
		const ctrlCuenta = this.form.get('id_cuentas_bancarias');
		const ctrlFecha = this.form.get('fecha_deposito');
		const ctrlNro = this.form.get('nro_deposito');
		const ctrlBanco = this.form.get('banco_origen');
		const ctrlFirst4 = this.form.get('tarjeta_first4');
		const ctrlLast4 = this.form.get('tarjeta_last4');
		if (needsBanco) {
			ctrlCuenta?.setValidators([Validators.required]);
			ctrlFecha?.setValidators([Validators.required]);
			ctrlNro?.setValidators([Validators.required]);
			ctrlBanco?.setValidators([Validators.required]);
		} else {
			ctrlCuenta?.clearValidators();
			ctrlFecha?.clearValidators();
			ctrlNro?.clearValidators();
			ctrlBanco?.clearValidators();
		}
		if (needsTarjeta) {
			ctrlFirst4?.setValidators([Validators.required, Validators.minLength(4), Validators.maxLength(4)]);
			ctrlLast4?.setValidators([Validators.required, Validators.minLength(4), Validators.maxLength(4)]);
		} else {
			ctrlFirst4?.clearValidators();
			ctrlLast4?.clearValidators();
		}
		[ctrlCuenta, ctrlFecha, ctrlNro, ctrlBanco, ctrlFirst4, ctrlLast4].forEach(c => c?.updateValueAndValidity({ emitEvent: false }));
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
			tarjeta_last4: 'Nº Tarjeta (4 últimos)',
			monto_parcial: 'Saldo a pagar'
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
			addIfMissing('id_cuentas_bancarias');
			addIfMissing('fecha_deposito');
			addIfMissing('nro_deposito');
			addIfMissing('banco_origen');
			addIfMissing('tarjeta_first4');
			addIfMissing('tarjeta_last4');
		} else if (this.isDeposito || this.isTransferencia) {
			addIfMissing('id_cuentas_bancarias');
			addIfMissing('fecha_deposito');
			addIfMissing('nro_deposito');
			addIfMissing('banco_origen');
		}
		if (this.form.get('pago_parcial')?.value) {
			addIfMissing('monto_parcial');
		}
		return out;
	}

	addAndClose(): void {
		this.modalAlertMessage = '';
		const missing = this.collectMissingFieldsForMetodo();
		if (missing.length) {
			this.modalAlertMessage = `Faltan campos: ${missing.join(', ')}`;
			this.modalAlertType = 'warning';
			return;
		}
		const monto = this.total;
		if (monto <= 0) {
			this.modalAlertMessage = 'El monto debe ser mayor a 0';
			this.modalAlertType = 'warning';
			return;
		}
		const metodo = (this.form.get('metodo_pago')?.value || '').toString();
		const comp = (this.form.get('comprobante')?.value || '').toString().toUpperCase();
		const tipoDoc = comp === 'FACTURA' ? 'F' : 'R';
		const computarizada = (this.form.get('computarizada')?.value || 'COMPUTARIZADA').toString();
		const medio = computarizada === 'MANUAL' ? 'M' : 'C';
		const obs = (this.form.get('observaciones')?.value || '').toString();
		const obsConMarca = obs ? `[REINCORPORACIÓN] ${obs}` : '[REINCORPORACIÓN]';
		const pago = {
			monto,
			pu_mensualidad: monto,
			fecha_cobro: this.form.get('fecha_cobro')?.value || new Date().toISOString().slice(0, 10),
			observaciones: obsConMarca,
			id_forma_cobro: metodo,
			tipo_documento: tipoDoc,
			medio_doc: medio,
			comprobante: comp,
			computarizada: computarizada,
			detalle: 'Reincorporación',
			cantidad: 1,
			descuento: 0,
			nro_cobro: this.baseNro,
			id_cuota: null,
			id_asignacion_costo: null,
			id_item: null,
			pago_parcial: false,
			id_cuentas_bancarias: this.form.get('id_cuentas_bancarias')?.value || null,
			banco_origen: this.form.get('banco_origen')?.value || null,
			fecha_deposito: this.form.get('fecha_deposito')?.value || null,
			nro_deposito: this.form.get('nro_deposito')?.value || null,
			tarjeta_first4: this.form.get('tarjeta_first4')?.value || null,
			tarjeta_last4: this.form.get('tarjeta_last4')?.value || null
		};
		this.addReincorporacion.emit([pago]);
		try {
			const modalEl = document.getElementById('reincorporacionModal');
			const bs = (window as any).bootstrap;
			if (modalEl && bs?.Modal) {
				const modal = bs.Modal.getInstance(modalEl);
				if (modal) modal.hide();
			}
		} catch {}
		this.form.reset({
			metodo_pago: this.defaultMetodoPago || '',
			computarizada: 'COMPUTARIZADA',
			comprobante: 'RECIBO',
			pago_parcial: false,
			monto_parcial: 0,
			fecha_cobro: new Date().toISOString().slice(0, 10)
		});
		this.modalAlertMessage = '';
	}
}
