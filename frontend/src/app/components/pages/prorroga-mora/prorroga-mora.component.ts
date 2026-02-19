import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule, ReactiveFormsModule, FormBuilder, FormGroup, Validators } from '@angular/forms';
import { ProrrogaMoraService } from '../../../services/prorroga-mora.service';
import { CobrosService } from '../../../services/cobros.service';
import { AuthService } from '../../../services/auth.service';
import { ProrrogaMora } from '../../../models/prorroga-mora.model';

@Component({
	selector: 'app-prorroga-mora',
	standalone: true,
	imports: [CommonModule, FormsModule, ReactiveFormsModule],
	templateUrl: './prorroga-mora.component.html',
	styleUrls: ['./prorroga-mora.component.scss']
})
export class ProrrogaMoraComponent implements OnInit {
	searchCodCeta: string = '';
	estudianteEncontrado: any = null;
	cuotasPendientes: any[] = [];
	prorrogas: ProrrogaMora[] = [];
	prorrogaForm: FormGroup;
	showModal: boolean = false;
	currentUser: any = null;
	loading: boolean = false;
	alertMessage: string = '';
	alertType: 'success' | 'error' | 'warning' = 'success';
	showAlert: boolean = false;
	// Resumen de estudiante (similar a reimpresión)
	pensumNombre: string = '';
	studentDisplayName: string = '';
	grupos: string[] = [];
	allCuotas: any[] = [];
	// Selección de UNA cuota (1..5) y motivo
	selectedCuota: number | null = null;
	motivo: string = '';
	fechaFinProrrogaTmp: string = '';

	constructor(
		private fb: FormBuilder,
		private prorrogaService: ProrrogaMoraService,
		private cobrosService: CobrosService,
		private authService: AuthService
	) {
		this.prorrogaForm = this.fb.group({
			id_asignacion_costo: [''],
			fecha_inicio_prorroga: ['', Validators.required],
			fecha_fin_prorroga: ['']
		});
	}

	ngOnInit(): void {
		this.authService.currentUser$.subscribe(user => {
			this.currentUser = user;
		});
		this.loadProrrogas();
	}

	buscarPorCodCeta(): void {
		const code = (this.searchCodCeta || '').toString().trim();
		if (!code) {
			this.displayAlert('Ingrese el Código CETA', 'warning');
			return;
		}

		this.loading = true;
		this.cobrosService.getResumen(code).subscribe({
			next: (res: any) => this.applyResumenData(res),
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

		// Estudiante y resumen
		this.estudianteEncontrado = est;
		this.studentDisplayName = [est?.ap_paterno, est?.ap_materno, est?.nombres].filter(Boolean).join(' ').trim();
		this.pensumNombre = String(insc?.pensum?.nombre || est?.pensum?.nombre || '');
		this.grupos = (inscripciones as any[])
			.filter((i: any) => String(i?.gestion || '') === gestion)
			.map((i: any) => String(i?.cod_curso || ''))
			.filter((c: string) => !!c);

		// Cuotas: el resumen devuelve 'asignaciones', no 'cuotas'
		this.allCuotas = Array.isArray(data?.asignaciones) ? data.asignaciones : [];
		this.cuotasPendientes = this.allCuotas.filter((cuota: any) => {
			const estado = String(cuota?.estado_pago || '').toUpperCase();
			return estado === 'PENDIENTE' || estado === 'PARCIAL' || estado === '';
		});

		// Reset de selección de prórroga
		this.selectedCuota = null;
		this.motivo = '';
		this.fechaFinProrrogaTmp = '';

		this.loading = false;
	}

	private resetResumenLocal(): void {
		this.estudianteEncontrado = null;
		this.cuotasPendientes = [];
		this.pensumNombre = '';
		this.studentDisplayName = '';
		this.grupos = [];
	}

	loadCuotasPendientes(): void {
		if (!this.estudianteEncontrado) return;

		this.loading = true;
		this.cobrosService.getResumen(this.estudianteEncontrado.cod_ceta).subscribe({
			next: (res: any) => {
				if (res.success && res.data) {
					const asignaciones = res.data.asignaciones || [];
					this.cuotasPendientes = asignaciones.filter((cuota: any) => {
						const estado = String(cuota?.estado_pago || '').toUpperCase();
						return estado === 'PENDIENTE' || estado === 'PARCIAL' || estado === '';
					});
				}
				this.loading = false;
			},
			error: (err: any) => {
				console.error('Error al cargar cuotas:', err);
				this.displayAlert('Error al cargar cuotas pendientes', 'error');
				this.loading = false;
			}
		});
	}

	loadProrrogas(): void {
		this.loading = true;
		this.prorrogaService.getAll().subscribe({
			next: (res: any) => {
				if (res.success && res.data) {
					this.prorrogas = res.data;
				}
				this.loading = false;
			},
			error: (err: any) => {
				console.error('Error al cargar prórrogas:', err);
				this.loading = false;
			}
		});
	}

	openModal(cuota: any): void {
		this.prorrogaForm.patchValue({
			id_asignacion_costo: cuota.id_asignacion_costo,
			fecha_inicio_prorroga: '',
			fecha_fin_prorroga: ''
		});
		this.showModal = true;
	}

	closeModal(): void {
		this.showModal = false;
		this.prorrogaForm.reset();
	}

	saveProrroga(): void {
		if (!this.currentUser) {
			this.displayAlert('Usuario no autenticado', 'error');
			return;
		}

		const fechaFin = (this.fechaFinProrrogaTmp || '').toString().trim();

		if (!fechaFin) {
			this.displayAlert('Seleccione la fecha fin de prórroga', 'warning');
			return;
		}

		if (!this.estudianteEncontrado) {
			this.displayAlert('Busque primero un estudiante por Código CETA', 'warning');
			return;
		}

		// Validar cuota seleccionada
		if (this.selectedCuota == null) {
			this.displayAlert('Seleccione la cuota a prorrogar', 'warning');
			return;
		}

		// Validar motivo
		if (!this.motivo || this.motivo.toString().trim().length === 0) {
			this.displayAlert('Ingrese el motivo de la prórroga', 'warning');
			return;
		}

		// Buscar la asignación de la cuota seleccionada (en todas las cuotas del resumen)
		const cuotaSel = (this.allCuotas || []).find((c: any) => Number(c?.numero_cuota) === Number(this.selectedCuota));
		if (!cuotaSel) {
			this.displayAlert('La cuota seleccionada no se encuentra en el resumen del estudiante', 'warning');
			return;
		}

		// fecha_inicio_prorroga = hoy, fecha_fin_prorroga = fecha ingresada por usuario
		const hoy = new Date().toISOString().split('T')[0];

		const payload: ProrrogaMora = {
			id_asignacion_costo: cuotaSel.id_asignacion_costo,
			fecha_inicio_prorroga: hoy,
			fecha_fin_prorroga: fechaFin,
			id_usuario: this.currentUser.id_usuario,
			cod_ceta: this.estudianteEncontrado.cod_ceta,
			activo: true,
			motivo: this.motivo
		};

		this.loading = true;
		this.prorrogaService.create(payload).subscribe({
			next: (res: any) => {
				if (res?.success) {
					this.displayAlert('Prórroga creada exitosamente', 'success');
					this.loadProrrogas();
					this.loadCuotasPendientes();
					this.limpiarFormularioProrroga();
				}
				this.loading = false;
			},
			error: (err: any) => {
				console.error('Error al crear prórroga:', err);
				this.displayAlert(err?.error?.message || 'Error al crear prórroga', 'error');
				this.loading = false;
			}
		});
	}

	limpiarFormularioProrroga(): void {
		this.prorrogaForm.patchValue({ fecha_inicio_prorroga: '', fecha_fin_prorroga: '', id_asignacion_costo: '' });
		this.selectedCuota = null;
		this.motivo = '';
		this.fechaFinProrrogaTmp = '';
	}

	toggleStatus(prorroga: ProrrogaMora): void {
		if (!prorroga.id_prorroga_mora) return;

		this.prorrogaService.toggleStatus(prorroga.id_prorroga_mora).subscribe({
			next: (res: any) => {
				if (res.success) {
					this.displayAlert('Estado actualizado exitosamente', 'success');
					this.loadProrrogas();
				}
			},
			error: (err: any) => {
				console.error('Error al cambiar estado:', err);
				this.displayAlert('Error al cambiar estado', 'error');
			}
		});
	}

	limpiarBusqueda(): void {
		this.searchCodCeta = '';
		this.estudianteEncontrado = null;
		this.cuotasPendientes = [];
		this.pensumNombre = '';
		this.studentDisplayName = '';
		this.grupos = [];
	}

	displayAlert(message: string, type: 'success' | 'error' | 'warning'): void {
		this.alertMessage = message;
		this.alertType = type;
		this.showAlert = true;
		setTimeout(() => {
			this.showAlert = false;
		}, 5000);
	}

	getNombreCompleto(estudiante: any): string {
		if (!estudiante) return '';
		return `${estudiante.ap_paterno || ''} ${estudiante.ap_materno || ''} ${estudiante.nombres || ''}`.trim();
	}

	formatDate(date: string): string {
		if (!date) return '';
		return new Date(date).toLocaleDateString('es-BO');
	}
}
