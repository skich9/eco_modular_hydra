import { Component, OnInit } from '@angular/core';
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
		if (!t) return this.gestiones;
		return this.gestiones.filter(g =>
			g.gestion.toLowerCase().includes(t) ||
			(g.fecha_ini && g.fecha_ini.toLowerCase().includes(t)) ||
			(g.fecha_fin && g.fecha_fin.toLowerCase().includes(t))
		);
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
		this.gForm.reset({ estado: true, orden: 1 });
		this.gForm.get('gestion')?.enable(); // permitir ingresar clave en creación
		this.showGModal = true;
	}

	openEditG(g: Gestion): void {
		this.editingG = g;
		this.gForm.patchValue(g);
		this.gForm.get('gestion')?.disable(); // clave primaria inmutable en edición
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
		const data = this.gForm.value as Gestion;
		if (this.editingG) {
			this.gService.update(this.editingG.gestion, data).subscribe({
				next: (res) => {
					if (res.success) {
						this.loadGestiones();
						this.closeModals();
						this.showAlert('Gestión actualizada', 'success');
					}
				},
				error: (err) => {
					console.error('G update:', err);
					this.showAlert('No se pudo actualizar la gestión', 'error');
				}
			});
		} else {
			this.gService.create(data).subscribe({
				next: (res) => {
					if (res.success) {
						this.loadGestiones();
						this.closeModals();
						this.showAlert('Gestión creada', 'success');
					}
				},
				error: (err) => {
					console.error('G create:', err);
					this.showAlert('No se pudo crear la gestión', 'error');
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
}
