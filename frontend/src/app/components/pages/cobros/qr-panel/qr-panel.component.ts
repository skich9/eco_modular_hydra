import { Component, Input } from '@angular/core';
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
export class QrPanelComponent {
	@Input() cabecera: any;
	@Input() pagos!: FormArray;
	@Input() totalCobro: number = 0;
	@Input() isSelected: boolean = false;
	@Input() cuentasBancarias: any[] = [];

	loading = false;
	alias = '';
	imageBase64 = '';
	amount = 0;
	expiresAt = '';
	status: 'pendiente' | 'procesando' | 'completado' | 'expirado' | 'cancelado' = 'pendiente';

	constructor(private cobrosService: CobrosService) {}

	get statusLabel(): string {
		const m: any = { pendiente: 'Pendiente', procesando: 'Procesando', completado: 'Completado', expirado: 'Expirado', cancelado: 'Cancelado' };
		return m[this.status] || 'Pendiente';
	}

	get statusClass(): string {
		if (this.status === 'completado') return 'bg-success';
		if (this.status === 'cancelado' || this.status === 'expirado') return 'bg-secondary';
		return 'bg-warning text-dark';
	}

	private ensureCuentaBancaria(): void {
		const cab = this.cabecera as FormGroup;
		if (!cab?.get('id_cuentas_bancarias')?.value) {
			const first = (this.cuentasBancarias || []).find((x: any) => x?.habilitado_QR === true) || (this.cuentasBancarias || [])[0];
			if (first) cab.patchValue({ id_cuentas_bancarias: first.id_cuentas_bancarias }, { emitEvent: false });
		}
	}

	abrir(): void {
		try { const el = document.getElementById('qrPanelModal'); const bs = (window as any).bootstrap; if (el && bs?.Modal) { (bs.Modal.getInstance(el) || new bs.Modal(el, { backdrop: 'static', keyboard: false })).show(); } } catch {}
	}

	cerrar(): void {
		try { const el = document.getElementById('qrPanelModal'); const bs = (window as any).bootstrap; if (el && bs?.Modal) { (bs.Modal.getInstance(el) || new bs.Modal(el)).hide(); } } catch {}
	}

	generar(): void {
		if (!this.isSelected) return;
		const cab = this.cabecera as FormGroup;
		const cod_ceta = (cab.get('cod_ceta')?.value || '').toString();
		const cod_pensum = (cab.get('cod_pensum')?.value || '').toString();
		const tipo_inscripcion = (cab.get('tipo_inscripcion')?.value || 'NORMAL').toString();
		const id_usuario = cab.get('id_usuario')?.value;
		this.ensureCuentaBancaria();
		const id_cuentas_bancarias = cab.get('id_cuentas_bancarias')?.value;
		const detalle = 'COBRO QR';
		const moneda = 'BOB';
		const items: any[] = [];
		for (let i = 0; i < this.pagos.length; i++) {
			const g = this.pagos.at(i) as FormGroup;
			const hoy = new Date().toISOString().slice(0,10);
			const subtotal = Number(g.get('monto')?.value || 0) || 0;
			const nro = Number(g.get('nro_cobro')?.value || 0) || (i + 1);
			const det = (g.get('detalle')?.value || '').toString();
			const pu = Number(g.get('pu_mensualidad')?.value || subtotal);
			const obs = (g.get('observaciones')?.value || '').toString();
			const tipoDoc = (g.get('tipo_documento')?.value || '').toString();
			const medioDoc = (g.get('medio_doc')?.value || '').toString();
			items.push({ monto: subtotal, fecha_cobro: hoy, order: i + 1, observaciones: obs, nro_cobro: nro, pu_mensualidad: pu, tipo_documento: tipoDoc, medio_doc: medioDoc, detalle: det, id_forma_cobro: cab.get('id_forma_cobro')?.value });
		}
		const amount = Number(this.totalCobro || 0);
		if (!cod_ceta || !cod_pensum || !id_usuario || !id_cuentas_bancarias || amount <= 0) return;
		this.loading = true;
		this.cobrosService.initiateQr({ cod_ceta, cod_pensum, tipo_inscripcion, id_usuario, id_cuentas_bancarias, amount, detalle, moneda, items }).subscribe({
			next: (res: any) => {
				this.loading = false;
				if (!res?.success) return;
				const d = res.data || {};
				this.alias = (d.alias || '').toString();
				this.imageBase64 = (d.qr_image_base64 || '').toString();
				this.amount = Number(d.amount || amount);
				this.expiresAt = (d.expires_at || '').toString();
				this.status = 'pendiente';
				this.abrir();
			},
			error: (err: any) => { this.loading = false; }
		});
	}

	anular(): void {
		if (!this.alias) return;
		const id_usuario = (this.cabecera as FormGroup).get('id_usuario')?.value;
		this.loading = true;
		this.cobrosService.disableQr(this.alias, id_usuario).subscribe({
			next: (res: any) => {
				this.loading = false;
				if (!res?.success) return;
				this.status = 'cancelado';
				this.cerrar();
			},
			error: (err: any) => { this.loading = false; }
		});
	}

	consultar(): void {
		if (!this.alias) return;
		this.loading = true;
		this.cobrosService.statusQr(this.alias).subscribe({
			next: (res: any) => {
				this.loading = false;
				if (!res?.success) return;
				const payload = res?.data || {};
				const estadoExt = ((payload?.objeto?.estadoActual) || '').toString().trim().toUpperCase();
				let nuevo: 'pendiente' | 'procesando' | 'completado' | 'expirado' | 'cancelado' = this.status;
				if (estadoExt === 'PAGADO') nuevo = 'completado';
				else if (estadoExt === 'INHABILITADO' || estadoExt === 'ERROR') nuevo = 'cancelado';
				else if (estadoExt === 'EXPIRADO') nuevo = 'expirado';
				else if (estadoExt === 'PENDIENTE') nuevo = 'procesando';
				this.status = nuevo;
			},
			error: () => { this.loading = false; }
		});
	}
}
