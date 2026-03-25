import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { CobrosService } from '../../../services/cobros.service';
import { AuthService } from '../../../services/auth.service';
import { DescuentoMoraService } from '../../../services/descuento-mora.service';
import { SoloNumerosDirective } from '../../../directives/solo-numeros.directive';

@Component({
	selector: 'app-descuento-mora',
	standalone: true,
	imports: [CommonModule, FormsModule, SoloNumerosDirective],
	templateUrl: './descuento-mora.component.html',
	styleUrls: ['./descuento-mora.component.scss']
})
export class DescuentoMoraComponent implements OnInit {
	searchCodCeta = '';
	estudianteEncontrado: any = null;
	pensumNombre = '';
	gestionSeleccionada: string = '';
	codPensumSeleccionado: string = '';
	gestionesDisponibles: string[] = [];
	pensumsDisponibles: Array<{ cod_pensum: string; nombre: string }> = [];
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
	) { }

	ngOnInit(): void {
		this.authService.currentUser$.subscribe(user => {
			this.currentUser = user;
		});
		this.loadDescuentos();
	}

	toggleDescuento(d: any): void {
		if (!d || !d.id_descuento_mora) return;

		if (d.activo != 1 && d.activo !== true) {

			let existeOtroActivo = false;
			const tabla = this.descuentosTabla || [];

			for (let i = 0; i < tabla.length; i++) {
				let fila = tabla[i];

				if (fila.id_asignacion_mora === d.id_asignacion_mora && fila.id_descuento_mora !== d.id_descuento_mora) {

					if (fila.activo === true) {
						existeOtroActivo = true;
						break;
					}
				}
			}

			if (existeOtroActivo) {
				const confirmar = window.confirm('Ya existe un descuento activo en esta cuota. ¿Desea reemplazarlo?');
				if (!confirmar) {
					return;
				}
			}
		}


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

	private handleResumenSinInformacion(): void {
		this.resetResumenLocal();
		this.displayAlert('No se encontró el registro o información, contactarse con el administrador', 'warning');
		this.loading = false;
	}

	private resumenTieneInformacion(data: any): boolean {
		if (!data) {
			return false;
		}
		const est = data?.estudiante;
		const hasEst = !!est && !!(est?.cod_ceta || est?.id_estudiante || est?.nombres || est?.nombre);
		const hasInsc = Array.isArray(data?.inscripciones) && data.inscripciones.length > 0;
		return hasEst || hasInsc;
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
				this.handleResumenSinInformacion();
				this.loading = false;
			}
		});
	}

	onGestionChange(): void {
		this.reloadResumenWithFilters();
	}

	onPensumChange(): void {
		this.reloadResumenWithFilters();
	}

	private reloadResumenWithFilters(): void {
		const code = (this.searchCodCeta || '').toString().trim();
		if (!code) {
			return;
		}

		this.loading = true;
		const gestion = (this.gestionSeleccionada || '').toString().trim();
		const codPensum = (this.codPensumSeleccionado || '').toString().trim();
		this.cobrosService.getResumen(code, gestion || undefined, codPensum || undefined).subscribe({
			next: (res: any) => {
				this.applyResumenData(res);
				this.loading = false;
			},
			error: (err: any) => {
				console.error('Error al recargar resumen:', err);
				this.handleResumenSinInformacion();
			}
		});
	}

	private applyResumenData(res: any): void {
		const data = res?.data || {};
		if (!res?.success || !this.resumenTieneInformacion(data)) {
			this.handleResumenSinInformacion();
			return;
		}
		const est = data?.estudiante || {};
		const insc = data?.inscripcion || null;
		const inscripciones = Array.isArray(data?.inscripciones) ? data.inscripciones : [];
		const gestion = String(data?.gestion || '');

		this.estudianteEncontrado = est;
		this.studentDisplayName = [est?.ap_paterno, est?.ap_materno, est?.nombres].filter(Boolean).join(' ').trim();

		// Listas para selector (gestión/pensum)
		const gestiones = (inscripciones as any[])
			.map((i: any) => String(i?.gestion || '').trim())
			.filter((g: string) => !!g);
		this.gestionesDisponibles = Array.from(new Set(gestiones)).sort((a: string, b: string) => b.localeCompare(a));

		const pensumsTmp = (inscripciones as any[])
			.map((i: any) => ({
				cod_pensum: String(i?.cod_pensum || i?.pensum?.cod_pensum || '').trim(),
				nombre: String(i?.pensum?.nombre || i?.cod_pensum || '').trim(),
			}))
			.filter((p: any) => !!p.cod_pensum);
		const pensumMap = new Map<string, { cod_pensum: string; nombre: string }>();
		pensumsTmp.forEach((p: any) => {
			if (!pensumMap.has(p.cod_pensum)) {
				pensumMap.set(p.cod_pensum, { cod_pensum: p.cod_pensum, nombre: p.nombre || p.cod_pensum });
			}
		});
		this.pensumsDisponibles = Array.from(pensumMap.values()).sort((a, b) => a.cod_pensum.localeCompare(b.cod_pensum));

		if (!this.gestionSeleccionada) {
			this.gestionSeleccionada = gestion;
		}
		if (!this.codPensumSeleccionado) {
			this.codPensumSeleccionado = String(insc?.cod_pensum || insc?.pensum?.cod_pensum || '');
		}
		this.pensumNombre = String(insc?.pensum?.nombre || est?.pensum?.nombre || '');
		if (this.codPensumSeleccionado) {
			const found = this.pensumsDisponibles.find(p => p.cod_pensum === this.codPensumSeleccionado);
			if (found) {
				this.pensumNombre = found.nombre;
			}
		}
		if (this.gestionSeleccionada && this.gestionesDisponibles.length > 0 && !this.gestionesDisponibles.includes(this.gestionSeleccionada)) {
			this.gestionSeleccionada = this.gestionesDisponibles[0] || this.gestionSeleccionada;
		}
		const gestionToUse = String(this.gestionSeleccionada || gestion);
		const codPensumToUse = String(this.codPensumSeleccionado || '');
		const gruposTmp = (inscripciones as any[])
			.filter((i: any) => {
				const gestionI = String(i?.gestion || '');
				const codPensumI = String(i?.cod_pensum || i?.pensum?.cod_pensum || '');
				if (gestionI !== gestionToUse) {
					return false;
				}
				if (codPensumToUse && codPensumI !== codPensumToUse) {
					return false;
				}
				return true;
			})
			.map((i: any) => String(i?.cod_curso || '').trim())
			.filter((c: string) => !!c);
		this.grupos = Array.from(new Set(gruposTmp));

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
		this.gestionSeleccionada = '';
		this.codPensumSeleccionado = '';
		this.gestionesDisponibles = [];
		this.pensumsDisponibles = [];
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

		// Validar que el valor no supere el monto de la mora usando un bucle 'for' básico
		const listaDescuentos = this.descuentosMora || [];
		
		for (let i = 0; i < listaDescuentos.length; i++) {
			const fila = listaDescuentos[i];
			const descuentoIngresado = Number(fila.monto_descuento || 0);
			const limiteMora = Number(fila.monto_mora || 0);
			
			if (descuentoIngresado > limiteMora) {
				this.displayAlert(`El descuento para la Cuota ${fila.numero_cuota} (Bs. ${descuentoIngresado}) no puede ser mayor a la mora actual (Bs. ${limiteMora}).`, 'warning');
				return;
			}
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
