import { Component, EventEmitter, Input, OnChanges, OnInit, Output, SimpleChanges } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators, FormsModule } from '@angular/forms';
import { ClickLockDirective } from '../../../../directives/click-lock.directive';

@Component({
	selector: 'app-mora-modal',
	standalone: true,
	imports: [CommonModule, ReactiveFormsModule, FormsModule, ClickLockDirective],
	templateUrl: './mora-modal.component.html',
	styleUrls: ['./mora-modal.component.scss']
})
export class MoraModalComponent implements OnInit, OnChanges {
	@Input() resumen: any = null;
	@Input() morasPendientes: any[] = [];
	@Input() formasCobro: any[] = [];
	@Input() cuentasBancarias: any[] = [];
	@Input() defaultMetodoPago: string = '';
	@Input() baseNro = 1;

	@Output() addPagos = new EventEmitter<any>();

	form: FormGroup;
	modalAlertMessage = '';
	modalAlertType: 'success' | 'error' | 'warning' = 'warning';

	constructor(private fb: FormBuilder) {
		this.form = this.fb.group({
			metodo_pago: ['', [Validators.required]],
			cantidad: [1, [Validators.min(1)]],
			costo_total: [{ value: 0, disabled: true }],
			descuento: [{ value: 0, disabled: true }],
			observaciones: [''],
			comprobante: ['RECIBO', [Validators.required]],
			computarizada: ['COMPUTARIZADA'],
			pago_parcial: [false],
			monto_parcial: [{ value: 0, disabled: true }],
			id_cuentas_bancarias: [''],
			banco_origen: [''],
			tarjeta_first4: [''],
			tarjeta_last4: [''],
			fecha_deposito: [''],
			nro_deposito: ['']
		});
	}

	ngOnInit(): void {
		this.setupFormListeners();
		this.recalcTotal();
	}

	ngOnChanges(changes: SimpleChanges): void {
		if (changes['morasPendientes'] || changes['resumen']) {
			this.recalcTotal();
		}
		if (changes['defaultMetodoPago'] && this.defaultMetodoPago) {
			this.form.patchValue({ metodo_pago: this.defaultMetodoPago }, { emitEvent: false });
		}
	}

	private setupFormListeners(): void {
		this.form.get('cantidad')?.valueChanges.subscribe(() => this.recalcTotal());
		this.form.get('pago_parcial')?.valueChanges.subscribe((val) => {
			if (val) {
				this.form.get('monto_parcial')?.enable();
				this.form.get('cantidad')?.disable();
			} else {
				this.form.get('monto_parcial')?.disable();
				this.form.get('monto_parcial')?.setValue(0);
				this.form.get('cantidad')?.enable();
			}
			this.recalcTotal();
		});
		this.form.get('monto_parcial')?.valueChanges.subscribe(() => {
			this.validateMontoParcial();
			this.recalcTotal();
		});
		this.form.get('descuento')?.valueChanges.subscribe(() => this.recalcTotal());
	}

	get totalMorasPendientes(): number {
		if (!this.morasPendientes || this.morasPendientes.length === 0) return 0;
		return this.morasPendientes.reduce((total, mora) => {
			const monto = Number(mora?.monto_mora || 0);
			const descuento = Number(mora?.monto_descuento || 0);
			return total + Math.max(0, monto - descuento);
		}, 0);
	}

	get precioUnitario(): number {
		if (!this.morasPendientes || this.morasPendientes.length === 0) return 0;
		return Number(this.morasPendientes[0]?.monto_base || 0);
	}

	get puPorMora(): number {
		if (!this.morasPendientes || this.morasPendientes.length === 0) return 0;
		return this.totalMorasPendientes / this.morasPendientes.length;
	}

	get getParcialMax(): number {
		if (!this.morasPendientes || this.morasPendientes.length === 0) return 0;
		return this.totalMorasPendientes;
	}

	private validateMontoParcial(): void {
		const monto = Number(this.form.get('monto_parcial')?.value || 0);
		const max = this.getParcialMax;
		if (monto > max) {
			this.form.get('monto_parcial')?.setErrors({ max: true });
		} else {
			this.form.get('monto_parcial')?.setErrors(null);
		}
	}

	get diasMora(): number {
		if (!this.morasPendientes || this.morasPendientes.length === 0) return 0;
		const mora = this.morasPendientes[0];
		if (!mora?.monto_base || !mora?.monto_mora) return 0;
		const montoBase = Number(mora.monto_base);
		const montoMora = Number(mora.monto_mora);
		if (montoBase === 0) return 0;
		return Math.round(montoMora / montoBase);
	}

	getCantidadOptions(): Array<{ value: number; label: string }> {
		const cant = this.morasPendientes?.length || 0;
		const options: Array<{ value: number; label: string }> = [];
		for (let i = 1; i <= cant; i++) {
			const mora = this.morasPendientes[i - 1];
			const numeroCuota = Number(mora?.numero_cuota || 0);
			const mesNombre = this.getMesNombreByCuota(numeroCuota);
			const label = mesNombre ? `${i} - ${mesNombre}` : `${i} mora(s)`;
			options.push({ value: i, label });
		}
		return options;
	}

	private getMesNombreByCuota(numeroCuota: number): string | null {
		try {
			const gestion = (this.resumen?.gestion || '').toString();
			const months = this.getGestionMonths(gestion);
			const idx = Number(numeroCuota) - 1;
			if (idx >= 0 && idx < months.length) return this.monthName(months[idx]);
			return null;
		} catch {
			return null;
		}
	}

	private getGestionMonths(gestion: string): number[] {
		try {
			const parts = (gestion || '').split('/');
			const sem = parseInt(parts[0] || '0', 10);
			if (sem === 1) return [2, 3, 4, 5, 6];
			if (sem === 2) return [7, 8, 9, 10, 11];
			return [];
		} catch {
			return [];
		}
	}

	private monthName(n: number): string {
		const names = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
		return names[n] || String(n);
	}

	private recalcTotal(): void {
		let total = 0;
		const isParcial = this.form.get('pago_parcial')?.value;
		const descuento = Number(this.form.get('descuento')?.value || 0);

		if (isParcial) {
			total = Number(this.form.get('monto_parcial')?.value || 0);
		} else {
			const cant = Math.max(0, Number(this.form.get('cantidad')?.value || 0));
			// Sumar monto_mora de las primeras 'cant' moras
			for (let i = 0; i < cant && i < this.morasPendientes.length; i++) {
				const mora = this.morasPendientes[i];
				const montoMora = Number(mora?.monto_mora || 0);
				const montoDesc = Number(mora?.monto_descuento || 0);
				total += Math.max(0, montoMora - montoDesc);
			}
		}

		total = Math.max(0, total - descuento);
		this.form.get('costo_total')?.setValue(total, { emitEvent: false });
	}

	get isTarjeta(): boolean {
		const f = this.getSelectedForma();
		const code = Number(f?.codigo_sin || 0);
		return code === 2;
	}

	get isCheque(): boolean {
		const f = this.getSelectedForma();
		const code = Number(f?.codigo_sin || 0);
		return code === 3;
	}

	get isDeposito(): boolean {
		const f = this.getSelectedForma();
		const code = Number(f?.codigo_sin || 0);
		return code === 4;
	}

	get isTransferencia(): boolean {
		const f = this.getSelectedForma();
		const code = Number(f?.codigo_sin || 0);
		return code === 5;
	}

	get isQR(): boolean {
		const f = this.getSelectedForma();
		const raw = (f?.descripcion_sin ?? f?.nombre ?? '').toString().trim().toUpperCase();
		return raw.includes('QR');
	}

	get showBancarioBlock(): boolean {
		return this.isCheque || this.isDeposito || this.isTransferencia || this.isQR;
	}

	get cuentasBancariasFiltradas(): any[] {
		return this.cuentasBancarias || [];
	}

	get facturaDeshabilitada(): boolean {
		return false;
	}

	private getSelectedForma(): any {
		const id = this.form.get('metodo_pago')?.value;
		return this.formasCobro.find(f => f.id_forma_cobro === id) || null;
	}

	labelForma(f: any): string {
		return f?.nombre || f?.descripcion_sin || f?.label || '';
	}

	addAndClose(): void {
		if (this.form.invalid) {
			this.modalAlertMessage = 'Complete todos los campos requeridos';
			this.modalAlertType = 'error';
			return;
		}

		const cant = Math.max(1, Number(this.form.get('cantidad')?.value || 1));
		const isParcial = this.form.get('pago_parcial')?.value;
		const montoParcial = isParcial ? Number(this.form.get('monto_parcial')?.value || 0) : 0;
		const descuento = Number(this.form.get('descuento')?.value || 0);
		const hoy = new Date().toISOString().slice(0, 10);
		const compSel = this.form.get('comprobante')?.value || 'RECIBO';
		const tipo_documento = compSel === 'FACTURA' ? 'F' : 'R';
		const medio_doc = this.form.get('computarizada')?.value === 'COMPUTARIZADA' ? 'C' : 'M';

		const pagos: any[] = [];
		const firstMora = (this.morasPendientes && this.morasPendientes.length > 0) ? this.morasPendientes[0] : null;
		const firstMoraId = firstMora ? Number(firstMora?.id_asignacion_mora || 0) : 0;
		const firstMoraAsignId = firstMora ? Number(firstMora?.id_asignacion_costo || 0) : 0;

		if (isParcial && montoParcial > 0) {
			pagos.push({
				id_forma_cobro: this.form.get('metodo_pago')?.value || null,
				nro_cobro: this.baseNro || 1,
				monto: montoParcial,
				fecha_cobro: hoy,
				observaciones: this.form.get('observaciones')?.value || '',
				pu_mensualidad: this.puPorMora,
				detalle: 'Pago Parcial de Mora',
				tipo_pago: 'MORA',
				cod_tipo_cobro: 'MORA',
				id_asignacion_mora: firstMoraId || null,
				id_asignacion_costo: firstMoraAsignId || null,
				tipo_documento,
				medio_doc,
				comprobante: compSel,
				computarizada: this.form.get('computarizada')?.value,
				id_cuentas_bancarias: this.form.get('id_cuentas_bancarias')?.value || null,
				banco_origen: this.form.get('banco_origen')?.value || null,
				fecha_deposito: this.form.get('fecha_deposito')?.value || null,
				nro_deposito: this.form.get('nro_deposito')?.value || null,
				tarjeta_first4: this.form.get('tarjeta_first4')?.value || null,
				tarjeta_last4: this.form.get('tarjeta_last4')?.value || null,
				descuento: descuento
			});
		} else {
			for (let i = 0; i < cant; i++) {
				const mora = this.morasPendientes[i];
				const numeroCuota = Number(mora?.numero_cuota || 0);
				const mesNombre = this.getMesNombreByCuota(numeroCuota);
				const detalle = mesNombre ? `Pago de Mora - ${mesNombre}` : `Pago de Mora ${i + 1}`;

				const montoMora = Number(mora?.monto_mora || 0);
				const montoDesc = Number(mora?.monto_descuento || 0);
				const montoNeto = Math.max(0, montoMora - montoDesc);

				pagos.push({
					id_forma_cobro: this.form.get('metodo_pago')?.value || null,
					nro_cobro: (this.baseNro || 1) + i,
					monto: montoNeto,
					fecha_cobro: hoy,
					observaciones: this.form.get('observaciones')?.value || '',
					pu_mensualidad: Number(mora?.monto_base || 0),
					detalle,
					tipo_pago: 'MORA',
					cod_tipo_cobro: 'MORA',
					id_asignacion_mora: Number(mora?.id_asignacion_mora || 0) || null,
					id_asignacion_costo: Number(mora?.id_asignacion_costo || 0) || null,
					tipo_documento,
					medio_doc,
					comprobante: compSel,
					computarizada: this.form.get('computarizada')?.value,
					id_cuentas_bancarias: this.form.get('id_cuentas_bancarias')?.value || null,
					banco_origen: this.form.get('banco_origen')?.value || null,
					fecha_deposito: this.form.get('fecha_deposito')?.value || null,
					nro_deposito: this.form.get('nro_deposito')?.value || null,
					tarjeta_first4: this.form.get('tarjeta_first4')?.value || null,
					tarjeta_last4: this.form.get('tarjeta_last4')?.value || null,
					descuento: i === 0 ? descuento : 0
				});
			}
		}

		if (this.isTarjeta || this.isCheque || this.isDeposito || this.isTransferencia || this.isQR) {
			const header = { id_cuentas_bancarias: this.form.get('id_cuentas_bancarias')?.value };
			this.addPagos.emit({ pagos, cabecera: header });
		} else {
			this.addPagos.emit(pagos);
		}

		const modalEl = document.getElementById('moraModal');
		const bs = (window as any).bootstrap;
		if (modalEl && bs?.Modal) {
			const instance = bs.Modal.getInstance(modalEl) || new bs.Modal(modalEl);
			instance.hide();
		}
		this.modalAlertMessage = '';
	}
}
