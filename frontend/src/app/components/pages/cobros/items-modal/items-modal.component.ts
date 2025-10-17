import { Component, EventEmitter, Input, OnChanges, OnInit, Output, SimpleChanges } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators, FormsModule } from '@angular/forms';
import { ItemsCobroService } from '../../../../services/items-cobro.service';

@Component({
	selector: 'app-items-modal',
	standalone: true,
	imports: [CommonModule, ReactiveFormsModule, FormsModule],
	templateUrl: './items-modal.component.html',
	styleUrls: ['./items-modal.component.scss']
})
export class ItemsModalComponent implements OnInit, OnChanges {
	@Input() formasCobro: any[] = [];
	@Input() cuentasBancarias: any[] = [];
	@Input() baseNro = 1;
	@Input() defaultMetodoPago: string = '';
	@Input() resumen: any = null;
	@Output() addItem = new EventEmitter<any>();

	items: any[] = [];
	form: FormGroup;
	search: string = '';
	comboOpen = false;
	submitError: string = '';

	constructor(private fb: FormBuilder, private itemsService: ItemsCobroService) {
		this.form = this.fb.group({
			metodo_pago: ['', [Validators.required]],
			computarizada: ['COMPUTARIZADA', [Validators.required]], // COMPUTARIZADA | MANUAL
			comprobante: ['RECIBO', [Validators.required]], // RECIBO | FACTURA
			nro_factura: [''],
			nro_recibo: [''],
			id_item: ['', [Validators.required]],
			precio: [{ value: 0, disabled: true }],
			cantidad: [1, [Validators.required, Validators.min(1)]],
			costo_total: [{ value: 0, disabled: true }],
			observaciones: [''],
			// Bancario / Tarjeta
			id_cuentas_bancarias: [''],
			banco_origen: [''],
			fecha_deposito: [''],
			nro_deposito: [''],
			tarjeta_first4: [''],
			tarjeta_last4: ['']
		});
	}

	ngOnChanges(changes: SimpleChanges): void {
		if (changes['defaultMetodoPago']) {
			const v = (this.defaultMetodoPago || '').toString();
			if (v) {
				this.form.patchValue({ metodo_pago: v }, { emitEvent: false });
				this.updateBancarioValidators();
			}
		}
	}

	ngOnInit(): void {
		this.itemsService.getAll().subscribe({
			next: (res) => {
				if (res.success) {
					const src = res.data || [];
					this.items = src.slice().sort((a: any, b: any) => Number(a?.id_item || 0) - Number(b?.id_item || 0));
				}
			},
			error: () => { this.items = []; }
		});
		// Recalcular precio/costo
		this.form.get('id_item')?.valueChanges.subscribe((v) => this.onItemChange(v));
		this.form.get('cantidad')?.valueChanges.subscribe(() => this.recalcTotal());
		this.form.get('metodo_pago')?.valueChanges.subscribe(() => this.updateBancarioValidators());
		this.form.get('comprobante')?.valueChanges.subscribe(() => this.updateDocValidators());
		this.updateBancarioValidators();
		this.updateDocValidators();
	}

	private onItemChange(val: any): void {
		const it = (this.items || []).find(i => `${i?.id_item}` === `${val}`);
		const precio = Number(it?.costo || 0);
		this.form.get('precio')?.setValue(precio, { emitEvent: false });
		this.recalcTotal();
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
		return this.isTarjeta || this.isCheque || this.isDeposito || this.isTransferencia || this.isQR;
	}

	private getSelectedForma(): any | null {
		const val = (this.form.get('metodo_pago')?.value || '').toString();
		if (!val) return null;
		const list = (this.formasCobro || []) as any[];
		let match = list.find((f: any) => `${f?.codigo_sin}` === val);
		if (!match) match = list.find((f: any) => `${f?.id_forma_cobro}` === val);
		return match || null;
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

		if (enableTarjeta || enableCheque || enableDeposito || enableTransfer || enableQR) {
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

		idCuentaCtrl?.updateValueAndValidity({ emitEvent: false });
		first4Ctrl?.updateValueAndValidity({ emitEvent: false });
		last4Ctrl?.updateValueAndValidity({ emitEvent: false });
		fechaDepCtrl?.updateValueAndValidity({ emitEvent: false });
		nroDepCtrl?.updateValueAndValidity({ emitEvent: false });
		bancoOrigenCtrl?.updateValueAndValidity({ emitEvent: false });
	}

	private updateDocValidators(): void {
		// En el modal de items NO exigimos número de factura/recibo; el backend puede asignarlos si corresponde
		const nfac = this.form.get('nro_factura');
		const nrec = this.form.get('nro_recibo');
		nfac?.clearValidators();
		nrec?.clearValidators();
		nfac?.updateValueAndValidity({ emitEvent: false });
		nrec?.updateValueAndValidity({ emitEvent: false });
	}

	recalcTotal(): void {
		const precio = Number(this.form.get('precio')?.value || 0);
		const cant = Math.max(0, Number(this.form.get('cantidad')?.value || 0));
		const total = precio * cant;
		this.form.get('costo_total')?.setValue(total, { emitEvent: false });
	}

	get filteredItems(): any[] {
		const q = (this.search || '').toString().trim().toUpperCase();
		if (!q) return this.items || [];
		return (this.items || []).filter((it: any) => {
			const d = (it?.nombre_servicio || it?.descripcion || '').toString().toUpperCase();
			return d.includes(q);
		});
	}

	onComboInputFocus(): void {
		this.comboOpen = true;
	}

	onComboInputBlur(): void {
		// pequeño timeout para permitir click en opción
		setTimeout(() => { this.comboOpen = false; }, 150);
	}

	selectComboItem(it: any): void {
		if (!it) return;
		this.form.patchValue({ id_item: it.id_item }, { emitEvent: true });
		this.search = it.nombre_servicio || '';
		this.comboOpen = false;
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

	open(): void {
		const modalEl = document.getElementById('itemsModal');
		const bs = (window as any).bootstrap;
		if (modalEl && bs?.Modal) {
			// Siempre por defecto Computarizada al abrir
			this.form.patchValue({ computarizada: 'COMPUTARIZADA' }, { emitEvent: false });
			const modal = new bs.Modal(modalEl);
			modal.show();
		}
	}

	private selectedItem(): any | null {
		const id = (this.form.get('id_item')?.value || '').toString();
		if (!id) return null;
		return (this.items || []).find((i: any) => `${i?.id_item}` === id) || null;
	}

	addAndClose(): void {
		if (!this.form.valid) {
			try {
				this.form.markAllAsTouched();
				const invalids: string[] = [];
				Object.keys(this.form.controls).forEach(k => { if (this.form.get(k)?.invalid) invalids.push(k); });
				this.submitError = `Complete los campos requeridos: ${invalids.join(', ')}`;
				console.warn('[ItemsModal] Form inválido', { invalids });
			} catch { this.submitError = 'Complete los campos requeridos.'; }
			return;
		}
		const item = this.selectedItem();
		const qty = Math.max(1, Number(this.form.get('cantidad')?.value || 1));
		const precio = Number(this.form.get('precio')?.value || 0);
		const subtotal = Number(this.form.get('costo_total')?.value || (precio * qty));
		const compSelRaw = (this.form.get('comprobante')?.value || '').toString().toUpperCase();
		const tipo_documento = compSelRaw === 'FACTURA' ? 'F' : (compSelRaw === 'RECIBO' ? 'R' : '');
		const medio_doc = (this.form.get('computarizada')?.value === 'MANUAL') ? 'M' : 'C';
		const hoy = new Date().toISOString().slice(0, 10);
		const payload: any = {
			id_forma_cobro: this.form.get('metodo_pago')?.value || null,
			nro_cobro: this.baseNro || 1,
			monto: subtotal,
			fecha_cobro: hoy,
			observaciones: (this.form.get('observaciones')?.value || '').toString().trim(),
			pu_mensualidad: precio,
			cantidad: qty,
			id_item: item?.id_item ?? null,
			detalle: (item?.nombre_servicio || '').toString(),
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

		// Emitir igual que mensualidad-modal: { pagos: [...], cabecera? } o array simple
		if (this.showBancarioBlock) {
			this.addItem.emit({ pagos: [payload], cabecera: { id_cuentas_bancarias: this.form.get('id_cuentas_bancarias')?.value || null } });
		} else {
			this.addItem.emit([payload]);
		}
		this.submitError = '';

		// Cerrar modal
		const modalEl = document.getElementById('itemsModal');
		const bs = (window as any).bootstrap;
		if (modalEl && bs?.Modal) {
			const instance = bs.Modal.getInstance(modalEl) || new bs.Modal(modalEl);
			instance.hide();
		}
	}
}
