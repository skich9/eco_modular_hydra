import { Component, EventEmitter, Input, OnInit, Output, OnChanges, SimpleChanges } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators, FormsModule } from '@angular/forms';
import { CobrosService } from '../../../../services/cobros.service';

@Component({
	selector: 'app-recuperacion-modal',
	standalone: true,
	imports: [CommonModule, ReactiveFormsModule, FormsModule],
	templateUrl: './recuperacion-modal.component.html',
	styleUrls: ['./recuperacion-modal.component.scss']
})
export class RecuperacionModalComponent implements OnInit, OnChanges {
	@Input() formasCobro: any[] = [];
	@Input() cuentasBancarias: any[] = [];
	@Input() baseNro = 1;
	@Input() defaultMetodoPago: string = '';
	@Input() resumen: any = null;
    @Input() costoRecuperacion: number | null = null;
    @Output() addRecuperacion = new EventEmitter<any>();

	form: FormGroup;
	materias: Array<{ sigla: string; nombre: string; tipo: string; prom: number; costo: number; elegible: boolean; motivos?: string[]; pagada?: boolean; selected: boolean }>=[];
	elegibilidad: any = null;
    autorizaciones: any = null;
    canMulti: boolean = false;
	readonly FEE_FALLBACK = 50; // fallback local
	modalAlertMessage: string = '';
	modalAlertType: 'success' | 'error' | 'warning' = 'warning';

	constructor(private fb: FormBuilder, private cobrosService: CobrosService) {
		this.form = this.fb.group({
			metodo_pago: ['', [Validators.required]],
			computarizada: ['COMPUTARIZADA', [Validators.required]],
			comprobante: ['RECIBO', [Validators.required]],
			nro_factura: [''],
			nro_recibo: [''],
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

	ngOnInit(): void {
		if (this.defaultMetodoPago) {
			this.form.patchValue({ metodo_pago: this.defaultMetodoPago }, { emitEvent: false });
		}
		this.form.get('metodo_pago')?.valueChanges.subscribe(() => this.updateBancarioValidators());
		this.updateBancarioValidators();
		this.form.get('comprobante')?.valueChanges.subscribe(() => this.updateComprobanteValidators());
		this.updateComprobanteValidators();
        this.loadMateriasElegibles();
	}

    ngOnChanges(changes: SimpleChanges): void {
        if (changes['resumen'] && !changes['resumen'].firstChange) {
            this.loadMateriasElegibles();
        }
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

	get feeRecuperacion(): number {
		const n = Number(this.costoRecuperacion);
		return Number.isFinite(n) && n > 0 ? n : this.FEE_FALLBACK;
	}

	private toNumber(v: any): number {
		try {
			if (v === null || v === undefined) return 0;
			if (typeof v === 'number') return isFinite(v) ? v : 0;
			let s = String(v).trim();
			if (!s) return 0;
			const hasComma = s.includes(',');
			const hasDot = s.includes('.');
			if (hasComma && hasDot) {
				// Asumir '.' miles, ',' decimal
				s = s.replace(/\./g, '').replace(',', '.');
			} else if (hasComma && !hasDot) {
				s = s.replace(',', '.');
			}
			s = s.replace(/[^0-9.\-]/g, '');
			const n = parseFloat(s);
			return isNaN(n) ? 0 : n;
		} catch { return 0; }
	}

    private loadMateriasElegibles(): void {
        try {
            const est = this.resumen?.estudiante || {};
            const ins = this.resumen?.inscripcion || this.resumen?.inscripciones?.[0] || {};
            const cod_ceta = est?.cod_ceta || '';
            const cod_pensum = ins?.cod_pensum || est?.cod_pensum || '';
            const gestion = (this.resumen?.gestion || ins?.gestion || '').toString();
            const cod_inscrip = ins?.cod_inscrip || '';
            if (!cod_ceta || !cod_pensum) { this.materias = []; this.elegibilidad = null; return; }
            this.cobrosService.getRecuperacionElegibilidad({ cod_ceta, cod_pensum, gestion }).subscribe({
                next: (res) => {
                    const data = res?.data || res || {};
                    const materias = Array.isArray(data?.materias) ? data.materias : [];
                    this.elegibilidad = data;
                    let mapped = materias.map((m: any) => {
                        const sigla = (m?.sigla_materia || m?.sigla || m?.cod_materia || '').toString();
                        const nombre = (m?.nombre || m?.nombre_materia || m?.materia || '').toString();
                        const tipo = (m?.kardex || m?.tipo || m?.tipo_inscripcion || 'NORMAL').toString();
                        const prom = Number(m?.examen_final ?? m?.promedio ?? 0) || 0;
                        const costo = this.toNumber(m?.costo_instancia ?? m?.costo ?? this.feeRecuperacion) || this.feeRecuperacion;
                        const elegible = !!(m?.elegible ?? true);
                        const motivos = Array.isArray(m?.motivos_bloqueo) ? m?.motivos_bloqueo : [];
                        // Detección robusta de pago realizado (normalizar acentos/caso)
                        const norm = (s: any) => (s === undefined || s === null) ? '' : (String(s).normalize('NFD').replace(/\p{Diacritic}/gu, '').toUpperCase());
                        const estado = norm((m?.estado_pago ?? m?.estado ?? ''));
                        const motivosText = norm((motivos || []).join(' '));
                        const paidKeywords = ['PAGADO','PAGADA','YA PAG','CANCELADO','CANCELADA','YA CANCEL','YA REGISTRAD'];
                        const hasPaidWord = paidKeywords.some(k => estado.includes(k) || motivosText.includes(k));
                        const pagada = !!(m?.pagada === true || hasPaidWord);
                        return { sigla, nombre, tipo, prom, costo, elegible, motivos, pagada, selected: false };
                    });
                    const proceedAuth = () => {
                        // Consultar autorizaciones para marcar pagadas con mayor precisión
                        this.cobrosService.getRecuperacionAutorizaciones({ cod_ceta, cod_pensum }).subscribe({
                            next: (authRes) => {
                                const aData = authRes?.data || authRes || {};
                                const paidSiglas = new Set<string>();
                                try {
                                    const collect = (obj: any) => {
                                        if (!obj) return;
                                        // Arrays de siglas
                                        for (const k of Object.keys(obj)) {
                                            const v = (obj as any)[k];
                                            const key = String(k).toLowerCase();
                                            if (Array.isArray(v)) {
                                                if (key.includes('pagad') || key.includes('cancel')) {
                                                    v.forEach((s: any) => { const sig = String(s?.sigla || s?.sigla_materia || s).toUpperCase(); if (sig) paidSiglas.add(sig); });
                                                } else {
                                                    v.forEach((item: any) => {
                                                        const sig = String(item?.sigla || item?.sigla_materia || '').toUpperCase();
                                                        const st = String(item?.estado || item?.estado_pago || '').toUpperCase();
                                                        const flag = (item?.pagada === true) || st.includes('PAGAD') || st.includes('CANCEL');
                                                        if (sig && flag) paidSiglas.add(sig);
                                                    });
                                                }
                                            } else if (typeof v === 'object') {
                                                collect(v);
                                            }
                                        }
                                    };
                                    collect(aData);
                                } catch {}
                                // Marcar pagadas por sigla
                                for (const it of mapped) {
                                    const sig = String(it.sigla || '').toUpperCase();
                                    if (sig && paidSiglas.has(sig)) it.pagada = true;
                                }
                                // Filtro: remover aprobadas (prom >= 60) y pagadas
                                let finalList = mapped.filter((m: any) => Number(m.prom || 0) < 60);
                                finalList = finalList.filter((m: any) => !m.pagada);
                                this.materias = finalList;
                                // Autorizaciones
                                const auth = (aData?.autorizaciones || aData || {}) as any;
                                this.autorizaciones = auth;
                                this.canMulti = !!(auth?.permitir_multiple || auth?.multi || auth?.habilita_multiple);
                            },
                            error: () => {
                                // Sin autorizaciones: aplicar filtros locales y continuar
                                let finalList = mapped.filter((m: any) => Number(m.prom || 0) < 60);
                                finalList = finalList.filter((m: any) => !m.pagada);
                                this.materias = finalList;
                                const auth = (data?.autorizaciones || {}) as any;
                                this.autorizaciones = auth;
                                this.canMulti = !!(auth?.permitir_multiple || auth?.multi || auth?.habilita_multiple);
                            }
                        });
                    };
                    // Verificar pagos locales en segunda_instancia por cod_inscrip
                    if (cod_inscrip) {
                        const siglas = mapped.map((m: any) => String(m.sigla || '').toUpperCase()).filter(Boolean);
                        this.cobrosService.getSegundaInstElegibilidad({ cod_inscrip, materias: siglas }).subscribe({
                            next: (e2) => {
                                const mapRes = e2?.data || {};
                                for (const it of mapped) {
                                    const key = String(it.sigla || '').toUpperCase();
                                    const st = (mapRes as any)[key];
                                    if (st && (st.exists === true || st.elegible === false)) it.pagada = true;
                                }
                            },
                            error: () => {},
                            complete: () => proceedAuth()
                        });
                    } else {
                        proceedAuth();
                    }
                },
                error: () => { this.materias = []; this.elegibilidad = null; }
            });
        } catch { this.materias = []; this.elegibilidad = null; }
    }

    onToggle(m: any): void {
        try {
            if (!m) return;
            // Si no está autorizado para múltiple selección, permitir solo 1 marcada
            if (!this.canMulti && m.selected) {
                for (const it of (this.materias || [])) {
                    if (it !== m) it.selected = false;
                }
            }
        } catch {}
    }

    onCheckChange(m: any, ev: Event): void {
        try {
            const input = ev?.target as HTMLInputElement;
            const checked = !!input?.checked;
            if (!m?.elegible || !!m?.pagada) { if (input) input.checked = false; m.selected = false; return; }
            m.selected = checked;
            // Aplicar regla de selección simple si no hay autorización
            if (checked && !this.canMulti) {
                for (const it of (this.materias || [])) {
                    if (it !== m) it.selected = false;
                }
            }
        } catch {}
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
		// QR no debe mostrar bloque bancario
		if (this.isQR) return false;
		return this.isTarjeta || this.isCheque || this.isDeposito || this.isTransferencia;
	}

	// Filtrar cuentas bancarias según tipo de comprobante (Recibo/Factura)
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
		return (this.materias || []).filter(m => m.selected).reduce((acc, m) => acc + (this.toNumber(m.costo) || this.feeRecuperacion), 0);
	}

	addAndClose(): void {
		if ((this.materias || []).filter(m => m.selected).length === 0) {
			this.modalAlertMessage = 'Seleccione al menos una materia para registrar la Prueba de Recuperación.';
			this.modalAlertType = 'warning';
			return;
		}
		const hasMetodo = !!this.form.get('metodo_pago')?.value;
		if (!hasMetodo) {
			this.modalAlertMessage = 'Seleccione un método de pago.';
			this.modalAlertType = 'warning';
			return;
		}
		if (!this.form.valid) {
			try { this.form.markAllAsTouched(); } catch {}
			const missing = this.collectMissingFieldsForMetodo();
			this.modalAlertMessage = missing.length ? `Complete los siguientes campos: ${missing.join(', ')}.` : 'Complete los campos obligatorios del método de pago.';
			this.modalAlertType = 'warning';
			return;
		}
		const compSelRaw = (this.form.get('comprobante')?.value || '').toString().toUpperCase();
		const tipo_documento = compSelRaw === 'FACTURA' ? 'F' : (compSelRaw === 'RECIBO' ? 'R' : '');
		const medio_doc = (this.form.get('computarizada')?.value === 'MANUAL') ? 'M' : 'C';
		const hoy = new Date().toISOString().slice(0, 10);
		let nro = this.baseNro || 1;
		const pagos = (this.materias || []).filter(m => m.selected).map(m => {
			const detalle = `Prueba de Recuperación - ${m.sigla} ${m.nombre}`;
			const payload: any = {
				id_forma_cobro: this.form.get('metodo_pago')?.value || null,
				nro_cobro: nro++,
				monto: this.toNumber(m.costo) || this.feeRecuperacion,
				fecha_cobro: hoy,
				observaciones: (() => {
					const base = (this.form.get('observaciones')?.value || '').toString().trim();
					const marker = `[PRUEBA DE RECUPERACIÓN] ${detalle}`;
					return base ? `${base} | ${marker}` : marker;
				})(),
				pu_mensualidad: this.toNumber(m.costo) || this.feeRecuperacion,
				cantidad: 1,
				detalle,
				tipo_documento,
				medio_doc,
				comprobante: compSelRaw || 'NINGUNO',
				computarizada: this.form.get('computarizada')?.value,
				id_cuentas_bancarias: this.form.get('id_cuentas_bancarias')?.value || null,
				banco_origen: this.form.get('banco_origen')?.value || null,
				fecha_deposito: this.form.get('fecha_deposito')?.value || null,
				nro_deposito: this.form.get('nro_deposito')?.value || null,
				tarjeta_first4: this.form.get('tarjeta_first4')?.value || null,
				tarjeta_last4: this.form.get('tarjeta_last4')?.value || null,
				descuento: null,
				nro_factura: compSelRaw === 'FACTURA' ? (this.form.get('nro_factura')?.value || null) : null,
				nro_recibo: compSelRaw === 'RECIBO' ? (this.form.get('nro_recibo')?.value || null) : null,
			};
			return payload;
		});

		if (this.showBancarioBlock) {
			this.addRecuperacion.emit({ pagos, cabecera: { id_cuentas_bancarias: this.form.get('id_cuentas_bancarias')?.value || null } });
		} else {
			this.addRecuperacion.emit(pagos);
		}

		const modalEl = document.getElementById('recuperacionModal');
		const bs = (window as any).bootstrap;
		if (modalEl && bs?.Modal) {
			const instance = bs.Modal.getInstance(modalEl) || new bs.Modal(modalEl);
			instance.hide();
		}
		this.modalAlertMessage = '';
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
}
