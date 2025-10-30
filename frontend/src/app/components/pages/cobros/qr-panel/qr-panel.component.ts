import { Component, Input, Output, EventEmitter, OnDestroy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormArray, FormGroup } from '@angular/forms';
import { CobrosService } from '../../../../services/cobros.service';

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
	@Input() identidad: any;
    @Output() statusChange = new EventEmitter<'pendiente' | 'procesando' | 'completado' | 'expirado' | 'cancelado'>();

	loading = false;
	alias = '';
	imageBase64 = '';
	amount = 0;
	expiresAt = '';
	status: 'pendiente' | 'procesando' | 'completado' | 'expirado' | 'cancelado' = 'pendiente';
    localOpen = false;
    zoomed = false;
    private pollHandle: any = null;
    private readonly pollMs = 4000;
    unitPriceReal: number = 0;

	constructor(private cobrosService: CobrosService) {}

	// Handlers explícitos para trazar click desde template
	onClickGenerar(): void {
		console.log('[QR-Panel] boton Generar QR click');
		// Si ya existe un alias pendiente en esta sesión, reusar y abrir modal sin generar otro
		const isFinal = this.status === 'completado' || this.status === 'cancelado' || this.status === 'expirado';
		if (this.alias && !isFinal) {
			console.log('[QR-Panel] reusar QR existente', { alias: this.alias, status: this.status });
			this.abrir();
			return;
		}
		// Intentar cargar desde sessionStorage por cod_ceta
		const cod = this.getCodCeta();
		if (this.loadSession(cod)) {
			console.log('[QR-Panel] cargado desde sessionStorage por cod_ceta', { cod_ceta: cod, alias: this.alias });
			this.abrir();
			return;
		}
		this.generar();
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
		if (!cab?.get('id_cuentas_bancarias')?.value) {
			const first = (this.cuentasBancarias || []).find((x: any) => x?.habilitado_QR === true) || (this.cuentasBancarias || [])[0];
			if (first) cab.patchValue({ id_cuentas_bancarias: first.id_cuentas_bancarias }, { emitEvent: false });
		}
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
        for (let i = 0; i < this.pagos.length; i++) {
            const g = this.pagos.at(i) as FormGroup;
            const hoy = new Date().toISOString().slice(0,10);
            const subtotal = Number(g.get('monto')?.value || 0) || 0;
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
				order: i + 1,
				observaciones: obs,
				nro_cobro: nro,
				pu_mensualidad: pu,
				tipo_documento: tipoDoc,
				medio_doc: medioDoc,
				detalle: det,
				id_forma_cobro: cab.get('id_forma_cobro')?.value,
				numero_cuota,
				turno,
				monto_saldo
			});
		}
		        const amount = Number(this.totalCobro || 0);
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
				if (!res?.success) { console.warn('[QR-Panel] initiateQr no-success'); return; }
				const d = res.data || {};
				this.alias = (d.alias || '').toString();
				this.imageBase64 = (d.qr_image_base64 || '').toString();
				this.amount = Number(d.amount || amount);
				this.expiresAt = (d.expires_at || '').toString();
				this.status = 'pendiente';
				this.statusChange.emit(this.status);
				this.saveSession(cod_ceta);
				this.startPolling();
			},
			error: (err: any) => { this.loading = false; console.error('[QR-Panel] initiateQr error', err); }
		});
	}

	anular(): void {
        if (!this.alias) return;
        const id_usuario = (this.cabecera as FormGroup).get('id_usuario')?.value;
        const cod = this.getCodCeta();
        this.loading = true;
        // Pre-check: sincronizar estado real por cod_ceta para evitar 502 si ya está pagado/cancelado
        this.cobrosService.syncQrByCodCeta({ cod_ceta: cod, id_usuario }).subscribe({
            next: (pre: any) => {
                const d = pre?.data || null;
                if (d && d.estado && ['completado','cancelado','expirado'].includes(d.estado)) {
                    this.loading = false;
                    this.status = d.estado;
                    this.statusChange.emit(this.status);
                    this.clearSession(cod);
                    this.cerrar();
                    return;
                }
                // Si no es final, intentar anular en proveedor
                this.cobrosService.disableQr(this.alias, id_usuario).subscribe({
                    next: (res: any) => {
                        this.loading = false;
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
                        this.cerrar();
                    },
                    error: (err: any) => {
                        this.loading = false;
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
                                            this.cerrar();
                                        }
                                    }
                                },
                                error: () => {}
                            });
                        }
                    }
                });
            },
            error: (e: any) => { this.loading = false; console.error('[QR-Panel] pre-sync error', e); }
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

    ngOnDestroy(): void { this.stopPolling(); }

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
			return !!this.alias && !!this.imageBase64;
		} catch { return false; }
	}

	private clearSession(cod_ceta: string): void {
		if (!cod_ceta) return;
		try { sessionStorage.removeItem(this.storageKey(cod_ceta)); } catch {}
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
