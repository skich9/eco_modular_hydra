import { Component, Input, Output, EventEmitter, OnDestroy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormArray, FormGroup } from '@angular/forms';
import { CobrosService } from '../../../../services/cobros.service';
import { WsService } from '../../../../services/ws.service';
import { Subscription } from 'rxjs';

@Component({
	selector: 'app-qr-panel',
	standalone: true,
	imports: [CommonModule],
	templateUrl: './qr-panel.component.html',
	styleUrls: ['./qr-panel.component.scss']
})
export class QrPanelComponent implements OnDestroy {
	@Input() cabecera: any;
	@Input() pagos!: FormArray;
	@Input() totalCobro: number = 0;
	@Input() isSelected: boolean = false;
	@Input() cuentasBancarias: any[] = [];
	@Input() formasCobro: any[] = [];
	@Input() identidad: any;
    @Output() statusChange = new EventEmitter<'pendiente' | 'procesando' | 'completado' | 'expirado' | 'cancelado'>();
    @Output() savedWaiting = new EventEmitter<void>();

	loading = false;
	alias = '';
	imageBase64 = '';
	amount = 0;
	expiresAt = '';
	status: 'pendiente' | 'procesando' | 'completado' | 'expirado' | 'cancelado' = 'pendiente';
    localOpen = false;
    zoomed = false;
    isCancelling: boolean = false;
    errorMsg: string = '';
    saveMsg: string = '';
    warnMsg: string = '';
    private pollHandle: any = null;
    private readonly pollMs = 4000;
    unitPriceReal: number = 0;
    private wsSub: Subscription | null = null;
    private wsAlias: string | null = null;

	constructor(private cobrosService: CobrosService, private ws: WsService) {}

	// Handlers explícitos para trazar click desde template
	async onClickGenerar(): Promise<void> {
		console.log('[QR-Panel] boton Generar QR click');
		this.errorMsg = '';
		this.saveMsg = '';
		// Si ya existe un alias pendiente en esta sesión, reusar y abrir modal sin generar otro
		const isFinal = this.status === 'completado' || this.status === 'cancelado' || this.status === 'expirado';
		if (this.alias && !isFinal) {
			console.log('[QR-Panel] reusar QR existente', { alias: this.alias, status: this.status });
			this.abrir();
			return;
		}
		// Asegurar catálogo de formas de cobro cargado (evita que no detecte filas QR por carrera)
		await this.ensureFormasCobroLoaded();
		// Intentar cargar desde sessionStorage por cod_ceta
		const cod = this.getCodCeta();
		if (this.loadSession(cod)) {
			console.log('[QR-Panel] cargado desde sessionStorage por cod_ceta', { cod_ceta: cod, alias: this.alias });
			this.abrir();
			return;
		}
		this.generar();
	}

	onClickGuardarEspera(): void {
		try {
			const cab = this.cabecera as FormGroup;
			const cod_ceta = (cab.get('cod_ceta')?.value || '').toString();
			let id_usuario = cab.get('id_usuario')?.value;
			const id_cuentas_bancarias = cab.get('id_cuentas_bancarias')?.value;
			const gestion = (cab.get('gestion')?.value || '').toString();
			const moneda = 'BOB';
			if (!id_usuario && typeof localStorage !== 'undefined') {
				try { const raw = localStorage.getItem('current_user'); if (raw) { const u = JSON.parse(raw); if (u?.id_usuario) id_usuario = u.id_usuario; } } catch {}
			}
			const items: any[] = [];
			let order = 0;
			for (let i = 0; i < this.pagos.length; i++) {
				const g = this.pagos.at(i) as FormGroup;
				const subtotal = Number(g.get('monto')?.value || 0) || 0;
				const det = (g.get('detalle')?.value || '').toString();
				const obs = (g.get('observaciones')?.value || '').toString();
				const pu = Number(g.get('pu_mensualidad')?.value || subtotal);
				const idf = g.get('id_forma_cobro')?.value;
				const nro_cuota = g.get('numero_cuota')?.value ?? g.get('nro_cuota')?.value ?? null;
				const turno = (g.get('turno')?.value || '').toString() || null;
				const monto_saldo = g.get('monto_saldo')?.value ?? null;
				items.push({
					monto: subtotal,
					order: (++order),
					detalle: det,
					observaciones: obs,
					pu_mensualidad: pu,
					id_forma_cobro: idf,
					nro_cuota,
					turno,
					monto_saldo
				});
			}
			if (items.length === 0) { this.errorMsg = 'No hay items en el lote para guardar.'; return; }
			this.loading = true;
			this.errorMsg = '';
			this.saveMsg = '';
			this.cobrosService.saveQrLote({ alias: this.alias, cod_ceta, id_usuario, id_cuentas_bancarias, moneda, gestion, items }).subscribe({
				next: (res: any) => {
					this.loading = false;
					if (!res?.success) { this.errorMsg = res?.message || 'No se pudo guardar el lote en espera.'; return; }
					this.saveMsg = 'Lote guardado en espera. Se procesará al confirmar el pago QR.';
					// Señal al padre para desbloquear "Guardar Lote" mientras el QR está pendiente
					try {
						this.savedWaiting.emit();
						const cod = this.getCodCeta();
						if (cod) { sessionStorage.setItem(this.storageKey(cod) + ':waiting_saved', '1'); }
					} catch {}
				},
				error: (e: any) => {
					this.loading = false;
					this.errorMsg = (e?.error?.message || e?.message || 'No se pudo guardar el lote en espera.').toString();
				}
			});
		} catch (e: any) { this.errorMsg = 'No se pudo guardar el lote en espera.'; this.loading = false; }
	}

	private isIdQR(id: any): boolean {
		try {
			const s = (id ?? '').toString();
			if (!s) return false;
			const list = (this.formasCobro || []) as any[];
			const match = list.find((f: any) => `${f?.id_forma_cobro}` === s || `${f?.codigo_sin}` === s);
			const raw = (match?.descripcion_sin ?? match?.nombre ?? match?.name ?? match?.descripcion ?? match?.label ?? '').toString().trim().toUpperCase();
			const nombre = raw.normalize('NFD').replace(/\p{Diacritic}/gu, '');
			if (match && nombre.includes('QR')) return true;
			// 1) Inferencia por catálogo: si existe alguna forma cuyo label empiece por 'QR ' y su segmento base coincide con el nombre actual
			try {
				const qrBases = (this.formasCobro || [])
					.filter((f: any) => {
						const r = (f?.descripcion_sin ?? f?.nombre ?? f?.name ?? f?.descripcion ?? f?.label ?? '').toString().trim().toUpperCase();
						return r.includes('QR');
					})
					.map((f: any) => {
						const r = (f?.descripcion_sin ?? f?.nombre ?? f?.name ?? f?.descripcion ?? f?.label ?? '').toString().trim().toUpperCase();
						const n = r.normalize('NFD').replace(/\p{Diacritic}/gu, '');
						const parts = n.split(/[\-–—]/).map((p: string) => p.trim()).filter((p: string) => !!p);
						const first = (parts[0] || '').replace(/^QR\s*/,'').trim();
						return first;
					})
					.filter((b: string) => !!b);
				if (qrBases.some((b: string) => b.length > 0 && (nombre === b || nombre.includes(b) || b.includes(nombre)))) return true;
			} catch {}
			// 2) Fallback por selección en cabecera (cuando la cabecera actual sea un método QR combinado)
			try {
				const cab = (this.cabecera as FormGroup);
				const cabSel = ((cab?.get('codigo_sin')?.value ?? cab?.get('id_forma_cobro')?.value) || '').toString();
				if (!cabSel) return false;
				let selMatch = list.find((f: any) => `${f?.codigo_sin}` === cabSel);
				if (!selMatch) selMatch = list.find((f: any) => `${f?.id_forma_cobro}` === cabSel);
				if (!selMatch) return false;
				const selRaw = (selMatch?.descripcion_sin ?? selMatch?.nombre ?? selMatch?.name ?? selMatch?.descripcion ?? selMatch?.label ?? '').toString().trim();
				const selNorm = selRaw.toUpperCase().normalize('NFD').replace(/\p{Diacritic}/gu, '');
				if (!selNorm.includes('QR')) return false; // solo inferir si la selección de cabecera es un "QR ..."
				// Si es combinado con guion, tomar la primera parte como segmento QR y quitar el token 'QR'
				const parts = selNorm.split(/[\-–—]/).map((p: string) => p.trim()).filter((v: string) => !!v);
				if (parts.length < 1) return false;
				const first = parts[0].replace(/^QR\s*/,'').trim();
				// Comparar el label de la forma actual con ese segmento (permite 'TRANSFERENCIA', 'TRANSFERENCIA BANCARIA', etc.)
				if (match && (first.length > 0 && (nombre === first || nombre.includes(first) || first.includes(nombre)))) return true;
				// Si no hubo match por id/codigo de la fila, intentar mapear 'first' a un ítem del catálogo y comparar por id
				const base = list.find((f: any) => {
					const r = (f?.descripcion_sin ?? f?.nombre ?? f?.name ?? f?.descripcion ?? f?.label ?? '').toString().trim().toUpperCase();
					const n = r.normalize('NFD').replace(/\p{Diacritic}/gu, '');
					return first.length > 0 && (n === first || n.includes(first) || first.includes(n));
				});
				if (base && (`${base?.id_forma_cobro}` === s || `${base?.codigo_sin}` === s)) return true;
				return false;
			} catch { return false; }
		} catch { return false; }
	}

	private ensureFormasCobroLoaded(): Promise<void> {
		try {
			if (Array.isArray(this.formasCobro) && this.formasCobro.length > 0) return Promise.resolve();
			return new Promise((resolve) => {
				this.cobrosService.getFormasCobro().subscribe({
					next: (res: any) => {
						if (res?.success && Array.isArray(res.data)) {
							this.formasCobro = (res.data || []).filter((r: any) => (!!(r?.codigo_sin || r?.id_forma_cobro)));
						}
						resolve();
					},
					error: () => resolve()
				});
			});
		} catch { return Promise.resolve(); }
	}

	onClickConsultar(): void {
		console.log('[QR-Panel] boton Consultar click', { alias: this.alias });
		this.consultar();
	}

	onClickAnular(): void {
		console.log('[QR-Panel] boton Anular click', { alias: this.alias });
		this.anular();
	}

	get statusLabel(): string {
		const m: any = { pendiente: 'Pendiente', procesando: 'Procesando', completado: 'Completado', expirado: 'Expirado', cancelado: 'Cancelado' };
		return m[this.status] || 'Pendiente';
	}

	get statusClass(): string {
		if (this.status === 'completado') return 'bg-success';
		if (this.status === 'cancelado' || this.status === 'expirado') return 'bg-secondary';
		return 'bg-warning text-dark';
	}

	get isFinalStatus(): boolean {
		return this.status === 'completado' || this.status === 'cancelado' || this.status === 'expirado';
	}

	private ensureCuentaBancaria(): void {
		const cab = this.cabecera as FormGroup;
		const current = cab?.get('id_cuentas_bancarias')?.value;
		// Detectar tipo de documento predominante en pagos (F o R)
		const doc = this.getDocTipoFromPagos();
		// Elegir cuenta preferida por tipo de doc
		const pick = this.pickCuentaByDocTipo(doc) || (this.cuentasBancarias || []).find((x: any) => x?.habilitado_QR === true) || (this.cuentasBancarias || [])[0];
		if (pick && `${current}` !== `${pick.id_cuentas_bancarias}`) {
			cab.patchValue({ id_cuentas_bancarias: pick.id_cuentas_bancarias }, { emitEvent: false });
		}
	}

	// Determina doc tipo a partir de los pagos del lote: si hay alguna F -> 'F'; en otro caso si hay R -> 'R'; fallback ''
	private getDocTipoFromPagos(): 'F' | 'R' | '' {
		try {
			let hasF = false, hasR = false;
			for (let i = 0; i < (this.pagos?.length || 0); i++) {
				const g = this.pagos.at(i) as FormGroup;
				const v = (g?.get('tipo_documento')?.value || '').toString().trim().toUpperCase();
				if (v === 'F') hasF = true; else if (v === 'R') hasR = true;
			}
			if (hasF) return 'F';
			if (hasR) return 'R';
			return '';
		} catch { return ''; }
	}

	// Selecciona cuenta por preferencia de doc tipo. Reglas:
	// - Filtra cuentas habilitado_QR === true.
	// - Si doc==='F': prioriza c.doc_tipo_preferido==='F' (case-insensitive) o c.I_R===1 o c.es_factura===true
	// - Si doc==='R': prioriza c.doc_tipo_preferido==='R' o c.I_R===0 o c.es_recibo===true
	// - Fallback: null
	private pickCuentaByDocTipo(doc: 'F' | 'R' | ''): any | null {
		try {
			if (!doc) return null;
			const list = (this.cuentasBancarias || []) as any[];
			const enabled = list.filter(x => x && (x.habilitado_QR === true || x.habilitado_qr === true));
			const norm = (s: any) => (s === undefined || s === null) ? '' : (String(s).trim().toUpperCase());
			const prefer = enabled.filter(c => {
				const tipoPref = norm(c.doc_tipo_preferido || c.qr_doc_tipo_preferido || c.pref_doc_tipo || '');
				const ir = (c.I_R !== undefined ? Number(c.I_R) : (c.i_r !== undefined ? Number(c.i_r) : NaN));
				const esFac = (c.es_factura === true);
				const esRec = (c.es_recibo === true);
				if (doc === 'F') {
					return tipoPref === 'F' || esFac || ir === 1;
				}
				if (doc === 'R') {
					return tipoPref === 'R' || esRec || ir === 0;
				}
				return false;
			});
			return prefer[0] || null;
		} catch { return null; }
	}

	abrir(): void {
        try {
            const el = document.getElementById('qrPanelModal');
            const bs = (window as any).bootstrap;
            console.log('[QR-Panel] abrir() called', { elFound: !!el, hasBootstrap: !!bs?.Modal });
            if (el && bs?.Modal) {
                const instance = (bs.Modal.getInstance(el) || new bs.Modal(el, { backdrop: 'static', keyboard: false }));
                console.log('[QR-Panel] abrir() showing modal');
                instance.show();
            } else {
                console.warn('[QR-Panel] abrir() cannot show modal: missing element or bootstrap.Modal. Using local fallback.');
                this.localOpen = true;
            }
            // Iniciar polling de estado si hay alias y no es final
            if (this.alias && !this.isFinalStatus) { this.startPolling(); }
        } catch (e) { console.error('[QR-Panel] abrir() error', e); }
    }

    setWarning(msg: string): void {
        try { this.warnMsg = (msg || '').toString(); } catch { this.warnMsg = ''; }
    }

    showExisting(alias: string, base64: string, amount: number, expiresAt: string, estado?: string): void {
        try {
            this.alias = (alias || '').toString();
            this.imageBase64 = (base64 || '').toString();
            this.amount = Number(amount || 0);
            this.expiresAt = (expiresAt || '').toString();
            const map: any = { generado: 'pendiente', procesando: 'procesando', completado: 'completado', cancelado: 'cancelado', expirado: 'expirado' };
            const estNorm = (estado || '').toString().toLowerCase();
            const next: any = map[estNorm] || 'pendiente';
            this.status = next;
            this.statusChange.emit(this.status);
            const cod = this.getCodCeta();
            if (cod) { this.saveSession(cod); }
            if (this.alias) { this.tagAliasOnQrRows(this.alias); }
            this.abrir();
        } catch (e) { console.error('[QR-Panel] showExisting() error', e); }
    }

	cerrar(): void {
        try {
            const el = document.getElementById('qrPanelModal');
            const bs = (window as any).bootstrap;
            console.log('[QR-Panel] cerrar() called', { elFound: !!el, hasBootstrap: !!bs?.Modal });
            if (el && bs?.Modal) { (bs.Modal.getInstance(el) || new bs.Modal(el)).hide(); }
            this.localOpen = false;
            this.stopPolling();
        } catch (e) { console.error('[QR-Panel] cerrar() error', e); }
    }

	generar(): void {
		if (!this.isSelected) return;
		const cab = this.cabecera as FormGroup;
		const cod_ceta = (cab.get('cod_ceta')?.value || '').toString();
		const cod_pensum = (cab.get('cod_pensum')?.value || '').toString();
		const tipo_inscripcion = (cab.get('tipo_inscripcion')?.value || 'NORMAL').toString();
		const gestion = (cab.get('gestion')?.value || '').toString();
		let id_usuario = cab.get('id_usuario')?.value;
		this.ensureCuentaBancaria();
		const id_cuentas_bancarias = cab.get('id_cuentas_bancarias')?.value;
		const detalle = 'COBRO QR';
		const moneda = 'BOB';
		const items: any[] = [];
		let firstPu = 0;
		let qrAmount = 0;
		let orderIdx = 0;
		for (let i = 0; i < this.pagos.length; i++) {
			const g = this.pagos.at(i) as FormGroup;
			const idf = g.get('id_forma_cobro')?.value;
			if (!this.isIdQR(idf)) continue; // solo filas QR
			const hoy = new Date().toISOString().slice(0,10);
			const subtotal = Number(g.get('monto')?.value || 0) || 0;
			qrAmount += subtotal;
			const nro = Number(g.get('nro_cobro')?.value || 0) || (i + 1);
			const det = (g.get('detalle')?.value || '').toString();
			const pu = Number(g.get('pu_mensualidad')?.value || subtotal);
			if (!firstPu && pu) firstPu = pu;
			const obs = (g.get('observaciones')?.value || '').toString();
			const tipoDoc = (g.get('tipo_documento')?.value || '').toString();
			const medioDoc = (g.get('medio_doc')?.value || '').toString();
			const numero_cuota = g.get('numero_cuota')?.value ?? null;
			const turno = (g.get('turno')?.value || '').toString() || null;
			const monto_saldo = g.get('monto_saldo')?.value ?? null;
			items.push({
				monto: subtotal,
				fecha_cobro: hoy,
				order: (++orderIdx),
				observaciones: obs,
				nro_cobro: nro,
				pu_mensualidad: pu,
				tipo_documento: tipoDoc,
				medio_doc: medioDoc,
				detalle: det,
				id_forma_cobro: idf,
				numero_cuota,
				turno,
				monto_saldo
			});
		}
		if (!items.length) {
			console.warn('[QR-Panel] generar(): no hay filas con método QR en el detalle. Abortando.');
			this.loading = false;
			this.status = 'pendiente';
			this.statusChange.emit(this.status);
			return;
		}
		const amount = Number(qrAmount || 0);
		this.unitPriceReal = firstPu || amount;
        // Abrir modal inmediatamente y marcar estado como procesando para dar feedback visual
        this.status = 'procesando';
        console.log('[QR-Panel] generar() start', { isSelected: this.isSelected, amount, cod_ceta, cod_pensum, id_usuario, id_cuentas_bancarias, pagosLen: this.pagos?.length ?? 0 });
        this.statusChange.emit(this.status);
        this.loading = true;
        this.abrir();
		// Fallback de id_usuario desde localStorage si no está en cabecera
		if (!id_usuario && typeof localStorage !== 'undefined') {
			try { const raw = localStorage.getItem('current_user'); if (raw) { const u = JSON.parse(raw); if (u?.id_usuario) { id_usuario = u.id_usuario; console.log('[QR-Panel] id_usuario from localStorage', id_usuario); } } } catch {}
		}
		// Validaciones mínimas: requerir solo cod_ceta, cod_pensum, amount>0
		if (!cod_ceta || !cod_pensum || amount <= 0) {
			this.loading = false;
			this.status = 'pendiente';
			this.statusChange.emit(this.status);
			console.warn('[QR-Panel] generar() early return: faltan datos', { cod_ceta, cod_pensum, amount, id_usuario, id_cuentas_bancarias });
			return;
		}
		this.cobrosService.initiateQr({ cod_ceta, cod_pensum, tipo_inscripcion, id_usuario, id_cuentas_bancarias, amount, detalle, moneda, gestion, items }).subscribe({
			next: (res: any) => {
				this.loading = false;
				console.log('[QR-Panel] iniciar respuesta', res);
				if (!res?.success) {
					console.warn('[QR-Panel] initiateQr no-success');
					this.status = 'pendiente';
					this.statusChange.emit(this.status);
					this.alias = '';
					this.imageBase64 = '';
					this.amount = 0;
					this.expiresAt = '';
					this.zoomed = false;
					this.errorMsg = (res?.message || 'No se pudo generar el QR. Intente más tarde.').toString();
					try { this.cerrar(); } catch {}
					return;
				}
				const d = res.data || {};
				this.alias = (d.alias || '').toString();
				this.imageBase64 = (d.qr_image_base64 || '').toString();
				this.amount = Number(d.amount || amount);
				this.expiresAt = (d.expires_at || '').toString();
				this.status = 'pendiente';
				this.statusChange.emit(this.status);
				this.saveSession(cod_ceta);
				if (this.alias) { this.tagAliasOnQrRows(this.alias); }
				if (this.alias) { this.setupWs(this.alias); }
				this.startPolling();
			},
			error: (err: any) => {
				this.loading = false;
				console.error('[QR-Panel] initiateQr error', err);
				this.status = 'pendiente';
				this.statusChange.emit(this.status);
				this.alias = '';
				this.imageBase64 = '';
				this.amount = 0;
				this.expiresAt = '';
				this.zoomed = false;
				const st = Number(err?.status || 0);
				const msg = (err?.error?.message || err?.message || (st ? `Error ${st}` : '') || '').toString();
				this.errorMsg = msg ? `No se pudo generar el QR. ${msg}. Intente más tarde.` : 'No se pudo generar el QR. Intente más tarde.';
				try { this.cerrar(); } catch {}
			}
		});
	}

	anular(): void {
        if (!this.alias) return;
        const id_usuario = (this.cabecera as FormGroup).get('id_usuario')?.value;
        const cod = this.getCodCeta();
        this.loading = true;
        this.isCancelling = true;
        this.errorMsg = '';
        this.saveMsg = '';
        this.warnMsg = '';
        this.imageBase64 = '';
        this.status = 'procesando';
        this.statusChange.emit(this.status);
        // Pre-check: sincronizar estado real por cod_ceta para evitar 502 si ya está pagado/cancelado
        this.cobrosService.syncQrByCodCeta({ cod_ceta: cod, id_usuario }).subscribe({
            next: (pre: any) => {
                const d = pre?.data || null;
                if (d && d.estado && ['completado','cancelado','expirado'].includes(d.estado)) {
                    this.loading = false;
                    this.isCancelling = false;
                    this.status = d.estado;
                    this.statusChange.emit(this.status);
                    this.clearSession(cod);
                    if (d.estado === 'cancelado') {
                        this.saveMsg = 'QR anulado.';
                        this.alias = '';
                        this.imageBase64 = '';
                    }
                    return;
                }
                // Si no es final, intentar anular en proveedor
                this.cobrosService.disableQr(this.alias, id_usuario).subscribe({
                    next: (res: any) => {
                        this.loading = false;
                        this.isCancelling = false;
                        console.log('[QR-Panel] disable respuesta', res);
                        if (!res?.success) return;
                        this.status = 'cancelado';
                        this.statusChange.emit(this.status);
                        this.clearSession(cod);
                        this.alias = '';
                        this.imageBase64 = '';
                        this.amount = 0;
                        this.expiresAt = '';
                        this.zoomed = false;
                        this.saveMsg = 'QR anulado.';
                        try { this.ws.disconnect(); this.teardownWs(); } catch {}
                    },
                    error: (err: any) => {
                        this.loading = false;
                        this.isCancelling = false;
                        console.error('[QR-Panel] disable error', err);
                        // Si el proveedor devuelve 502/500, re-sincronizar por cod_ceta para reflejar estado final
                        const st = Number(err?.status || 0);
                        if (st === 502 || st === 500) {
                            this.cobrosService.syncQrByCodCeta({ cod_ceta: cod, id_usuario }).subscribe({
                                next: (sx: any) => {
                                    const sd = sx?.data || null;
                                    if (sd && sd.estado) {
                                        this.status = sd.estado;
                                        this.statusChange.emit(this.status);
                                        if (['completado','cancelado','expirado'].includes(sd.estado)) {
                                            this.clearSession(cod);
                                            if (sd.estado === 'cancelado') {
                                                this.saveMsg = 'QR anulado.';
                                                this.alias = '';
                                                this.imageBase64 = '';
                                            }
                                            this.isCancelling = false;
                                        }
                                    }
                                },
                                error: () => {}
                            });
                        }
                    }
                });
            },
            error: (e: any) => { this.loading = false; this.isCancelling = false; console.error('[QR-Panel] pre-sync error', e); }
        });
    }

	consultar(): void {
        const cod = this.getCodCeta();
        if (!cod) return;
        this.loading = true;
        this.cobrosService.stateQrByCodCeta({ cod_ceta: cod }).subscribe({
            next: (res: any) => {
                this.loading = false;
                console.log('[QR-Panel] state-by-codceta', res);
                if (!res?.success) return;
                const data = res?.data || null;
                if (data && data.estado) {
                    this.status = (data.estado || '').toString() as any;
                    this.statusChange.emit(this.status);
                    if (!this.alias && data.alias) { this.alias = (data.alias || '').toString(); }
                    if (this.isFinalStatus) { this.clearSession(cod); this.stopPolling(); }
                }
            },
            error: (e: any) => { this.loading = false; console.error('[QR-Panel] state-by-codceta error', e); }
        });
    }

    private updateStatusFromPayload(payload: any): void {
        const estadoExt = ((payload?.objeto?.estadoActual) || '').toString().trim().toUpperCase();
        let nuevo: 'pendiente' | 'procesando' | 'completado' | 'expirado' | 'cancelado' = this.status;
        if (estadoExt === 'PAGADO') nuevo = 'completado';
        else if (estadoExt === 'INHABILITADO' || estadoExt === 'ERROR') nuevo = 'cancelado';
        else if (estadoExt === 'EXPIRADO') nuevo = 'expirado';
        else if (estadoExt === 'PENDIENTE') nuevo = 'procesando';
        this.status = nuevo;
        this.statusChange.emit(this.status);
        if (this.isFinalStatus) { this.stopPolling(); if (this.status === 'completado') { try { this.cerrar(); } catch {} } }
    }

    private startPolling(): void {
        this.stopPolling();
        try {
            const cod = this.getCodCeta();
            if (!cod) return;
            this.pollHandle = setInterval(() => {
                // Consulta local por cod_ceta (no golpea al proveedor)
                this.cobrosService.stateQrByCodCeta({ cod_ceta: cod }).subscribe({
                    next: (res: any) => {
                        if (!res?.success) return;
                        const data = res?.data || null;
                        if (!data) return;
                        const est = (data.estado || '').toString();
                        if (est) {
                            this.status = est as any;
                            this.statusChange.emit(this.status);
                            if (!this.alias && data.alias) { this.alias = (data.alias || '').toString(); }
                            if (this.isFinalStatus) { this.clearSession(cod); this.stopPolling(); if (this.status === 'completado') { try { this.cerrar(); } catch {} } }
                        }
                    },
                    error: () => {}
                });
            }, this.pollMs);
        } catch {}
    }

    private stopPolling(): void {
        try { if (this.pollHandle) { clearInterval(this.pollHandle); this.pollHandle = null; } } catch {}
    }

    ngOnDestroy(): void { this.stopPolling(); try { this.ws.disconnect(); this.teardownWs(); } catch {} }

	private getCodCeta(): string {
		try { return ((this.cabecera as FormGroup)?.get('cod_ceta')?.value || '').toString(); } catch { return ''; }
	}

	private storageKey(cod_ceta: string): string { return `qr_session:${cod_ceta}`; }

	private saveSession(cod_ceta: string): void {
		if (!cod_ceta) return;
		try {
			const data = { alias: this.alias, imageBase64: this.imageBase64, amount: this.amount, expiresAt: this.expiresAt };
			sessionStorage.setItem(this.storageKey(cod_ceta), JSON.stringify(data));
		} catch {}
	}

	private loadSession(cod_ceta: string): boolean {
		if (!cod_ceta) return false;
		try {
			const raw = sessionStorage.getItem(this.storageKey(cod_ceta));
			if (!raw) return false;
			const d = JSON.parse(raw);
			this.alias = (d?.alias || '').toString();
			this.imageBase64 = (d?.imageBase64 || '').toString();
			this.amount = Number(d?.amount || 0);
			this.expiresAt = (d?.expiresAt || '').toString();
			this.status = 'pendiente';
			this.statusChange.emit(this.status);
			if (this.alias) { this.tagAliasOnQrRows(this.alias); }
			if (this.alias) { this.setupWs(this.alias); }
			return !!this.alias && !!this.imageBase64;
		} catch { return false; }
	}

	private clearSession(cod_ceta: string): void {
		if (!cod_ceta) return;
		try { sessionStorage.removeItem(this.storageKey(cod_ceta)); } catch {}
	}

	private tagAliasOnQrRows(alias: string): void {
		try {
			if (!alias) return;
			for (let i = 0; i < (this.pagos?.length || 0); i++) {
				const g = this.pagos.at(i) as FormGroup;
				const idf = g?.get('id_forma_cobro')?.value;
				if (!this.isIdQR(idf)) continue;
				const cur = (g?.get('observaciones')?.value ?? '').toString();
				const marker = `alias:${alias}`;
				if (cur.includes(marker)) continue;
				const base = cur.trim();
				const val = base ? `[QR] ${marker} | ${base}` : `[QR] ${marker}`;
				(g.get('observaciones') as any)?.setValue(val, { emitEvent: false });
			}
		} catch {}
	}

	private setupWs(alias: string): void {
        try {
            if (!alias) return;
            if (this.wsAlias === alias && this.wsSub) return;
            this.teardownWs();
            this.ws.connect(alias);
            this.wsAlias = alias;
            this.wsSub = this.ws.messages$.subscribe((msg: any) => {
                try {
                    const ev = (msg?.evento || '').toString();
                    const id = (msg?.id_pago || msg?.alias || msg?.id || '').toString();
                    if (id && this.alias && id !== this.alias) return; // ignorar eventos de otros alias
                    if (ev === 'procesando_pago') {
                        this.status = 'procesando';
                        this.statusChange.emit(this.status);
                    } else if (ev === 'factura_generada') {
                        this.status = 'completado';
                        this.statusChange.emit(this.status);
                        try { this.cerrar(); } catch {}
                    }
                } catch {}
            });
        } catch {}
    }

    private teardownWs(): void {
        try { if (this.wsSub) { this.wsSub.unsubscribe(); } } catch {}
        this.wsSub = null;
        this.wsAlias = null;
    }

	// ============== UI helpers para bloque de identidad y descarga ==============
	get dataUrl(): string {
		return this.imageBase64 ? `data:image/png;base64,${this.imageBase64}` : '';
	}

	get docLabel(): string {
		const t = Number(this.identidad?.get?.('tipo_identidad')?.value ?? this.identidad?.tipo_identidad ?? 1);
		if (t === 5) return 'Nit';
		if (t === 2) return 'Cex';
		if (t === 3) return 'Pas';
		if (t === 4) return 'OD';
		return 'Ci';
	}

	get docNumero(): string {
		const v = (this.identidad?.get?.('ci')?.value ?? this.identidad?.ci ?? '').toString();
		const comp = (this.identidad?.get?.('complemento_ci')?.value ?? this.identidad?.complemento_ci ?? '').toString();
		return comp ? `${v}-${comp}` : v;
	}

	get razonSocial(): string {
		return (this.identidad?.get?.('razon_social')?.value ?? this.identidad?.razon_social ?? '').toString();
	}

	onToggleZoom(): void { this.zoomed = !this.zoomed; }

	onDescargar(): void {
		try {
			if (!this.dataUrl) return;
			const a = document.createElement('a');
			a.href = this.dataUrl;
			a.download = `qr_${this.alias || 'pago'}.png`;
			document.body.appendChild(a);
			a.click();
			document.body.removeChild(a);
		} catch (e) { console.error('[QR-Panel] descargar error', e); }
	}
}
