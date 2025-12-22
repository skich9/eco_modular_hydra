import { Component, OnInit, ElementRef, ViewChild } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, FormGroup, Validators, ReactiveFormsModule, FormsModule } from '@angular/forms';
import { ParametrosGeneralesService } from '../../../services/parametros-generales.service';
import { GestionService } from '../../../services/gestion.service';
import { ParametroGeneral } from '../../../models/parametro-general.model';
import { Gestion } from '../../../models/gestion.model';

@Component({
	selector: 'app-configuraciones-generales',
	standalone: true,
	imports: [CommonModule, ReactiveFormsModule, FormsModule],
	templateUrl: './configuraciones-generales.component.html',
	styleUrls: ['./configuraciones-generales.component.scss']
})
export class ConfiguracionesGeneralesComponent implements OnInit {
	// Tab actual
	activeTab: 'pg' | 'gestion' = 'pg';

	// Datos
	parametros: ParametroGeneral[] = [];
	gestiones: Gestion[] = [];

	// Búsqueda
	searchPG = '';
	searchG = '';

	// Formularios
	pgForm: FormGroup;
	gForm: FormGroup;

	// Estado UI
	loading = false;
	alertMessage = '';
	alertType: 'success' | 'error' | 'warning' = 'success';
	// Ordenamiento (Gestiones)
	sortGestionDir: 'asc' | 'desc' = 'desc';
	// Errores de servidor en modal Gestión
	gFormServerErrors: string[] = [];

	// Ref del cuerpo del modal de Gestiones para enfocar alertas
	@ViewChild('gModalBody') gModalBody!: ElementRef<HTMLDivElement>;

	// Modales
	showPgModal = false;
	showGModal = false;
	showDeleteModal = false;
	deleteType: 'pg' | 'g' | '' = '';
	deleteTarget: any = null;

	// Edición
	editingPG: ParametroGeneral | null = null;
	editingG: Gestion | null = null;

	constructor(
		private fb: FormBuilder,
		private pgService: ParametrosGeneralesService,
		private gService: GestionService
	) {
		this.pgForm = this.fb.group({
			id_parametros_generales: [null],
			nombre: ['', [Validators.required, Validators.maxLength(150)]],
			valor: ['', [Validators.maxLength(255)]],
			estado: [true]
		});

		this.gForm = this.fb.group({
			gestion: ['', [Validators.required, Validators.maxLength(30)]],
			fecha_ini: ['', Validators.required],
			fecha_fin: ['', Validators.required],
			orden: [1, [Validators.required, Validators.min(1)]],
			fecha_graduacion: [''],
			estado: [true]
		});
	}

	ngOnInit(): void {
		this.loadAll();
	}

	// Carga inicial
	loadAll(): void {
		this.loading = true;
		this.loadParametros();
		this.loadGestiones();
	}

	loadParametros(): void {
		this.pgService.getAll().subscribe({
			next: (res) => {
				if (res.success) this.parametros = res.data;
				else this.showAlert('Error al cargar parámetros generales', 'error');
			},
			error: (err) => {
				console.error('PG getAll:', err);
				this.showAlert('No se pudo cargar parámetros generales', 'error');
			}
		});
	}

	loadGestiones(): void {
		this.gService.getAll().subscribe({
			next: (res) => {
				if (res.success) this.gestiones = res.data;
				else this.showAlert('Error al cargar gestiones', 'error');
				this.loading = false;
			},
			error: (err) => {
				console.error('G getAll:', err);
				this.showAlert('No se pudo cargar gestiones', 'error');
				this.loading = false;
			}
		});
	}

	// Filtros
	get filteredPG(): ParametroGeneral[] {
		const t = (this.searchPG || '').toLowerCase().trim();
		if (!t) return this.parametros;
		return this.parametros.filter(p =>
			p.nombre.toLowerCase().includes(t) ||
			(p.valor && p.valor.toLowerCase().includes(t))
		);
	}

	get filteredGestiones(): Gestion[] {
		const t = (this.searchG || '').toLowerCase().trim();
		let list = [...(this.gestiones || [])];
		if (t) {
			list = list.filter(g =>
				g.gestion.toLowerCase().includes(t) ||
				(g.fecha_ini && g.fecha_ini.toLowerCase().includes(t)) ||
				(g.fecha_fin && g.fecha_fin.toLowerCase().includes(t))
			);
		}
		list.sort((a, b) => {
			const ao = Number(a.orden || 0);
			const bo = Number(b.orden || 0);
			const cmp = ao === bo ? 0 : (ao < bo ? -1 : 1);
			return this.sortGestionDir === 'asc' ? cmp : -cmp;
		});
		return list;
	}

	// Toggle de orden
	toggleSortGestion(): void {
		this.sortGestionDir = this.sortGestionDir === 'asc' ? 'desc' : 'asc';
	}

	// Tabs
	setTab(tab: 'pg' | 'gestion'): void { this.activeTab = tab; }

	// Modales PG
	openNewPG(): void {
		this.editingPG = null;
		this.pgForm.reset({ estado: true });
		this.showPgModal = true;
	}

	openEditPG(p: ParametroGeneral): void {
		this.editingPG = p;
		this.pgForm.patchValue(p);
		this.showPgModal = true;
	}

	// Modales Gestión
	openNewG(): void {
		this.editingG = null;
		// Calcular siguiente orden automáticamente: último orden + 1
		const maxOrden = Math.max(0, ...((this.gestiones || []).map(g => Number((g as any)?.orden) || 0)));
		const nextOrden = maxOrden + 1;
		this.gForm.reset({ estado: true, orden: nextOrden });
		// En creación, el orden se autogenera y no debe editarse
		this.gForm.get('orden')?.disable({ emitEvent: false });
		this.gForm.get('gestion')?.enable(); // permitir ingresar clave en creación
		this.gFormServerErrors = [];
		this.alertMessage = '';
		this.showGModal = true;
	}

	openEditG(g: Gestion): void {
		this.editingG = g;
		this.gForm.patchValue(g);
		this.gForm.get('gestion')?.disable(); // clave primaria inmutable en edición
		// En edición: el orden no es editable, mantener deshabilitado
		this.gForm.get('orden')?.disable({ emitEvent: false });
		this.gFormServerErrors = [];
		this.alertMessage = '';
		// Limpiar errores previos del control gestion
		this.gForm.get('gestion')?.setErrors(null);
		this.gForm.get('gestion')?.updateValueAndValidity({ onlySelf: true, emitEvent: false });
		this.showGModal = true;
	}

	closeModals(): void {
		this.showPgModal = false;
		this.showGModal = false;
		this.showDeleteModal = false;
		this.deleteType = '';
		this.deleteTarget = null;
		this.editingPG = null;
		this.editingG = null;
	}

	// Guardar PG
	savePG(): void {
		if (!this.pgForm.valid) return;
		const data = this.pgForm.value as ParametroGeneral;
		if (this.editingPG && this.editingPG.id_parametros_generales) {
			this.pgService.update(this.editingPG.id_parametros_generales, data).subscribe({
				next: (res) => {
					if (res.success) {
						this.loadParametros();
						this.closeModals();
						this.showAlert('Parámetro actualizado', 'success');
					}
				},
				error: (err) => {
					console.error('PG update:', err);
					this.showAlert('No se pudo actualizar el parámetro', 'error');
				}
			});
		} else {
			this.pgService.create(data).subscribe({
				next: (res) => {
					if (res.success) {
						this.loadParametros();
						this.closeModals();
						this.showAlert('Parámetro creado', 'success');
					}
				},
				error: (err) => {
					console.error('PG create:', err);
					this.showAlert('No se pudo crear el parámetro', 'error');
				}
			});
		}
	}

	// Guardar Gestión
	saveG(): void {
		if (!this.gForm.valid) return;
		// limpiar mensajes previos del modal
		this.gFormServerErrors = [];
		const data = this.gForm.getRawValue() as Gestion; // incluye campos deshabilitados
		// Trim de clave gestión
		data.gestion = (data.gestion || '').trim();
		// Normalizar payload: fechas opcionales null y orden numérico
		const payload: Gestion = {
			...data,
			fecha_graduacion: data.fecha_graduacion ? data.fecha_graduacion : undefined,
			orden: typeof data.orden === 'string' ? parseInt(data.orden as unknown as string, 10) : data.orden
		};
		// Pre-validaciones en cliente
		if (!this.editingG && !payload.gestion) {
			const c = this.gForm.get('gestion');
			c?.setErrors({ ...(c?.errors || {}), required: true });
			c?.markAsTouched();
			this.gFormServerErrors = ['El campo Gestión es requerido.'];
			this.alertMessage = '';
			this.scrollToGFormErrors();
			return;
		}
		if (!payload.fecha_ini || !payload.fecha_fin) {
			if (!payload.fecha_ini) {
				const ci = this.gForm.get('fecha_ini');
				ci?.setErrors({ ...(ci?.errors || {}), required: true });
				ci?.markAsTouched();
			}
			if (!payload.fecha_fin) {
				const cf = this.gForm.get('fecha_fin');
				cf?.setErrors({ ...(cf?.errors || {}), required: true });
				cf?.markAsTouched();
			}
			this.gFormServerErrors = ['Las fechas de inicio y fin son requeridas.'];
			this.alertMessage = '';
			this.scrollToGFormErrors();
			return;
		}
		if (new Date(payload.fecha_ini) > new Date(payload.fecha_fin)) {
			const cf = this.gForm.get('fecha_fin');
			cf?.setErrors({ ...(cf?.errors || {}), backend: true, message: 'La fecha fin debe ser mayor o igual a la fecha inicio.' });
			cf?.markAsTouched();
			this.gFormServerErrors = ['La fecha fin debe ser mayor o igual a la fecha inicio.'];
			this.alertMessage = '';
			this.scrollToGFormErrors();
			return;
		}
		// Validación opcional: fecha de graduación si existe debe ser >= fecha_ini
		if (payload.fecha_graduacion) {
			const fg = new Date(payload.fecha_graduacion);
			const fi = new Date(payload.fecha_ini);
			if (fg < fi) {
				const cgr = this.gForm.get('fecha_graduacion');
				cgr?.setErrors({ ...(cgr?.errors || {}), backend: true, message: 'La fecha de graduación debe ser mayor o igual a la fecha inicio.' });
				cgr?.markAsTouched();
				this.gFormServerErrors = ['La fecha de graduación debe ser mayor o igual a la fecha inicio.'];
				this.alertMessage = '';
				this.scrollToGFormErrors();
				return;
			}
		}
		if (payload.orden == null || Number.isNaN(payload.orden) || payload.orden < 1) {
			const co = this.gForm.get('orden');
			co?.setErrors({ ...(co?.errors || {}), backend: true, message: 'El campo Orden debe ser un número mayor o igual a 1.' });
			co?.markAsTouched();
			this.gFormServerErrors = ['El campo Orden debe ser un número mayor o igual a 1.'];
			this.alertMessage = '';
			this.scrollToGFormErrors();
			return;
		}
		if (this.editingG) {
			this.gService.update(this.editingG.gestion, payload).subscribe({
				next: (res) => {
					if (res.success) {
						this.loadGestiones();
						this.closeModals();
						this.showAlert('Gestión actualizada', 'success');
					}
				},
				error: (err) => {
					console.error('G update:', err);
					if (err?.status === 422 && err?.error?.errors) {
						this.applyBackendErrorsToGForm(err.error.errors);
						this.gFormServerErrors = this.flattenBackendErrors(err.error.errors);
						this.alertMessage = '';
						this.scrollToGFormErrors();
					} else {
						this.showAlert('No se pudo actualizar la gestión', 'error');
					}
				}
			});
		} else {
			// Evitar duplicado obvio desde el cliente
			const existe = (this.gestiones || []).some(g => (g.gestion || '').toLowerCase() === payload.gestion.toLowerCase());
			if (existe) {
				const cg = this.gForm.get('gestion');
				cg?.setErrors({ ...(cg?.errors || {}), backend: true, message: 'La gestión ya existe. Ingrese un valor diferente.' });
				cg?.markAsTouched();
				this.gFormServerErrors = ['La gestión ya existe. Ingrese un valor diferente.'];
				this.alertMessage = '';
				this.scrollToGFormErrors();
				return;
			}
			this.gService.create(payload).subscribe({
				next: (res) => {
					if (res.success) {
						this.loadGestiones();
						this.closeModals();
						this.showAlert('Gestión creada', 'success');
					}
				},
				error: (err) => {
					console.error('G create:', err);
					if (err?.status === 422 && err?.error?.errors) {
						this.applyBackendErrorsToGForm(err.error.errors);
						this.gFormServerErrors = this.flattenBackendErrors(err.error.errors);
						this.alertMessage = '';
						this.scrollToGFormErrors();
					} else {
						this.showAlert('No se pudo crear la gestión', 'error');
					}
				}
			});
		}
	}

	// Eliminar
	confirmDelete(target: any, type: 'pg' | 'g'): void {
		this.deleteTarget = target;
		this.deleteType = type;
		this.showDeleteModal = true;
	}

	cancelDelete(): void {
		this.showDeleteModal = false;
		this.deleteTarget = null;
		this.deleteType = '';
	}

	doDelete(): void {
		if (this.deleteType === 'pg') {
			const p = this.deleteTarget as ParametroGeneral;
			this.pgService.delete(p.id_parametros_generales as number).subscribe({
				next: (res) => {
					if (res.success) {
						this.loadParametros();
						this.cancelDelete();
						this.showAlert('Parámetro eliminado', 'success');
					}
				},
				error: (err) => {
					console.error('PG delete:', err);
					this.showAlert('No se pudo eliminar el parámetro', 'error');
				}
			});
		}
		if (this.deleteType === 'g') {
			const g = this.deleteTarget as Gestion;
			this.gService.delete(g.gestion).subscribe({
				next: (res) => {
					if (res.success) {
						this.loadGestiones();
						this.cancelDelete();
						this.showAlert('Gestión eliminada', 'success');
					}
				},
				error: (err) => {
					console.error('G delete:', err);
					this.showAlert('No se pudo eliminar la gestión', 'error');
				}
			});
		}
	}

	// Toggle estado
	togglePG(p: ParametroGeneral): void {
		if (!p.id_parametros_generales) return;
		this.pgService.toggleStatus(p.id_parametros_generales).subscribe({
			next: (res) => {
				if (res.success) {
					this.loadParametros();
					this.showAlert('Estado actualizado', 'success');
				}
			},
			error: (err) => {
				console.error('PG toggle:', err);
				this.showAlert('No se pudo cambiar el estado', 'error');
			}
		});
	}

	toggleG(g: Gestion): void {
		this.gService.cambiarEstado(g.gestion, !g.estado).subscribe({
			next: (res) => {
				if (res.success) {
					this.loadGestiones();
					this.showAlert('Estado actualizado', 'success');
				}
			},
			error: (err) => {
				console.error('G toggle:', err);
				this.showAlert('No se pudo cambiar el estado', 'error');
			}
		});
	}

	// Alertas
	private showAlert(message: string, type: 'success' | 'error' | 'warning'): void {
		this.alertMessage = message;
		this.alertType = type;
		setTimeout(() => (this.alertMessage = ''), 4000);
	}

	private formatBackendErrors(errors: any): string {
		try {
			return Object.keys(errors)
				.map(k => `${k}: ${Array.isArray(errors[k]) ? errors[k].join(', ') : errors[k]}`)
				.join(' | ');
		} catch {
			return 'Error de validación';
		}
	}

	private flattenBackendErrors(errors: any): string[] {
		try {
			return Object.keys(errors).map(k => (Array.isArray(errors[k]) ? errors[k].join(', ') : String(errors[k])));
		} catch {
			return ['Error de validación'];
		}
	}

	private applyBackendErrorsToGForm(errors: any): void {
		if (!errors) return;
		Object.keys(errors).forEach(key => {
			const ctrl = this.gForm.get(key);
			if (ctrl) {
				const msg = Array.isArray(errors[key]) ? errors[key][0] : errors[key];
				ctrl.setErrors({ ...(ctrl.errors || {}), backend: true, message: msg });
				ctrl.markAsTouched();
			}
		});
	}

	private scrollToGFormErrors(): void {
		try {
			setTimeout(() => {
				const container = this.gModalBody?.nativeElement;
				const alertEl = container?.querySelector('.alert-warning');
				if (alertEl && alertEl instanceof HTMLElement) {
					alertEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
				}
			}, 0);
		} catch {}
	}
}
