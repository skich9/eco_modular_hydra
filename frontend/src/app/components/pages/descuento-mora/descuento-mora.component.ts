import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { CobrosService } from '../../../services/cobros.service';
import { AuthService } from '../../../services/auth.service';
import { DescuentoMoraService } from '../../../services/descuento-mora.service';

@Component({
	selector: 'app-descuento-mora',
	standalone: true,
	imports: [CommonModule, FormsModule],
	templateUrl: './descuento-mora.component.html',
	styleUrls: ['./descuento-mora.component.scss']
})
export class DescuentoMoraComponent implements OnInit {
	searchCodCeta = '';
	estudianteEncontrado: any = null;
	pensumNombre = '';
	studentDisplayName = '';
	grupos: string[] = [];

	morasPendientes: any[] = [];
	descuentosMora: any[] = [];
	motivo = '';
	buscadorTabla = '';

	currentUser: any = null;
	loading = false;
	alertMessage = '';
	alertType: 'success' | 'error' | 'warning' = 'success';
	showAlert = false;

	constructor(
		private cobrosService: CobrosService,
		private authService: AuthService,
		private descuentoMoraService: DescuentoMoraService
	) {}

	ngOnInit(): void {
		this.authService.currentUser$.subscribe(user => {
			this.currentUser = user;
		});
		this.loadDescuentos();
	}

	toggleDescuento(d: any): void {
		if (!d || !d.id_descuento_mora) return;

		this.loading = true;
		this.descuentoMoraService.toggleStatus(d.id_descuento_mora).subscribe({
			next: (res: any) => {
				if (res?.success) {
					this.displayAlert('Estado actualizado', 'success');
					this.loadDescuentos();
					if (this.estudianteEncontrado) {
						this.buscarPorCodCeta();
					}
				}
				this.loading = false;
			},
			error: (err: any) => {
				console.error('Error al actualizar estado:', err);
				this.displayAlert(err?.error?.message || 'Error al actualizar estado', 'error');
				this.loading = false;
			}
		});
	}

	buscarPorCodCeta(): void {
		const code = (this.searchCodCeta || '').toString().trim();
		if (!code) {
			this.displayAlert('Ingrese el Código CETA', 'warning');
			return;
		}

		this.loading = true;
		this.cobrosService.getResumen(code).subscribe({
			next: (res: any) => {
				this.applyResumenData(res);
				this.loading = false;
			},
			error: (err: any) => {
				console.error('Error al buscar por Código CETA:', err);
				this.resetResumenLocal();
				this.loading = false;
			}
		});
	}

	private applyResumenData(res: any): void {
		const data = res?.data || {};
		const est = data?.estudiante || {};
		const insc = data?.inscripcion || null;
		const inscripciones = Array.isArray(data?.inscripciones) ? data.inscripciones : [];
		const gestion = String(data?.gestion || '');

		this.estudianteEncontrado = est;
		this.studentDisplayName = [est?.ap_paterno, est?.ap_materno, est?.nombres].filter(Boolean).join(' ').trim();
		this.pensumNombre = String(insc?.pensum?.nombre || est?.pensum?.nombre || '');

		this.grupos = (inscripciones as any[])
			.filter((i: any) => String(i?.gestion || '') === gestion)
			.map((i: any) => String(i?.cod_curso || ''))
			.filter((c: string) => !!c);

		this.morasPendientes = Array.isArray(data?.moras_pendientes) ? data.moras_pendientes : [];
		this.morasPendientes = (this.morasPendientes || []).slice().sort((a: any, b: any) => Number(a?.numero_cuota || 0) - Number(b?.numero_cuota || 0));

		this.descuentosMora = (this.morasPendientes || []).map((m: any) => ({
			id_asignacion_mora: m?.id_asignacion_mora,
			numero_cuota: Number(m?.numero_cuota || 0),
			monto_mora: Number(m?.monto_mora || 0),
			monto_descuento_actual: Number(m?.monto_descuento || 0),
			monto_descuento: 0
		}));

		this.motivo = '';
	}

	private resetResumenLocal(): void {
		this.estudianteEncontrado = null;
		this.pensumNombre = '';
		this.studentDisplayName = '';
		this.grupos = [];
		this.morasPendientes = [];
		this.descuentosMora = [];
		this.motivo = '';
	}

	loadDescuentos(): void {
		this.loading = true;
		this.descuentoMoraService.getAll().subscribe({
			next: (res: any) => {
				this.descuentosTabla = (res?.data || []);
				this.loading = false;
			},
			error: (err: any) => {
				console.error('Error al cargar descuentos de mora:', err);
				this.loading = false;
			}
		});
	}

	descuentosTabla: any[] = [];

	get descuentosTablaFiltrados(): any[] {
		const q = (this.buscadorTabla || '').toString().trim().toLowerCase();
		if (!q) return this.descuentosTabla;
		return (this.descuentosTabla || []).filter((d: any) => {
			const est = d?.asignacion_mora?.asignacion_costo?.inscripcion?.estudiante;
			const nombre = `${est?.ap_paterno || ''} ${est?.ap_materno || ''} ${est?.nombres || ''}`.toLowerCase();
			const cod = String(est?.cod_ceta || d?.asignacion_mora?.asignacion_costo?.inscripcion?.cod_ceta || '').toLowerCase();
			const cuota = String(d?.asignacion_mora?.asignacion_costo?.numero_cuota || '').toLowerCase();
			return nombre.includes(q) || cod.includes(q) || cuota.includes(q) || String(d?.observaciones || '').toLowerCase().includes(q);
		});
	}

	registrarDescuento(): void {
		if (!this.estudianteEncontrado) {
			this.displayAlert('Busque primero un estudiante por Código CETA', 'warning');
			return;
		}

		const motivo = (this.motivo || '').toString().trim();
		if (!motivo) {
			this.displayAlert('Ingrese el motivo del descuento', 'warning');
			return;
		}

		const rows = (this.descuentosMora || [])
			.filter((r: any) => Number(r?.monto_descuento || 0) > 0)
			.map((r: any) => ({
				id_asignacion_mora: r.id_asignacion_mora,
				porcentaje: false,
				monto_descuento: Number(r.monto_descuento || 0)
			}));

		if (!rows.length) {
			this.displayAlert('Ingrese al menos un monto de descuento', 'warning');
			return;
		}

		this.loading = true;
		this.descuentoMoraService.createMultiple(rows, motivo).subscribe({
			next: (res: any) => {
				if (res?.success) {
					this.displayAlert('Descuento(s) registrado(s) exitosamente', 'success');
					this.loadDescuentos();
					this.buscarPorCodCeta();
					this.limpiarFormulario();
				}
				this.loading = false;
			},
			error: (err: any) => {
				console.error('Error al registrar descuentos de mora:', err);
				this.displayAlert(err?.error?.message || 'Error al registrar descuento', 'error');
				this.loading = false;
			}
		});
	}

	limpiarFormulario(): void {
		this.descuentosMora = (this.descuentosMora || []).map((r: any) => ({
			...r,
			monto_descuento: 0
		}));
		this.motivo = '';
	}

	cancelar(): void {
		this.searchCodCeta = '';
		this.resetResumenLocal();
	}

	displayAlert(message: string, type: 'success' | 'error' | 'warning'): void {
		this.alertMessage = message;
		this.alertType = type;
		this.showAlert = true;
		setTimeout(() => {
			this.showAlert = false;
		}, 5000);
	}
}
