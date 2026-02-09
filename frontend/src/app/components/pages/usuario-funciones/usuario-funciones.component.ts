import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, FormGroup, Validators, ReactiveFormsModule, FormsModule } from '@angular/forms';
import { FuncionService } from '../../../services/funcion.service';
import { AuthService } from '../../../services/auth.service';
import { PermissionService } from '../../../services/permission.service';
import { UsuarioFuncion } from '../../../models/usuario.model';
import { Funcion, AsignarFuncionRequest } from '../../../models/funcion.model';

@Component({
	selector: 'app-usuario-funciones',
	standalone: true,
	imports: [CommonModule, ReactiveFormsModule, FormsModule],
	templateUrl: './usuario-funciones.component.html',
	styleUrls: ['./usuario-funciones.component.scss']
})
export class UsuarioFuncionesComponent implements OnInit {
	funciones: UsuarioFuncion[] = [];
	funcionesDisponibles: Funcion[] = [];
	funcionesAgrupadas: { [modulo: string]: UsuarioFuncion[] } = {};

	loading = false;
	alertMessage = '';
	alertType: 'success' | 'error' | 'warning' = 'success';

	showAddModal = false;
	showEditModal = false;
	showDeleteModal = false;

	funcionForm: FormGroup;
	editingFuncion: UsuarioFuncion | null = null;
	deletingFuncion: UsuarioFuncion | null = null;

	usuarioId: number | null = null;
	searchTerm = '';

	constructor(
		private fb: FormBuilder,
		private funcionService: FuncionService,
		private authService: AuthService,
		private permissionService: PermissionService
	) {
		this.funcionForm = this.fb.group({
			id_funcion: ['', Validators.required],
			fecha_ini: ['', Validators.required],
			fecha_fin: [''],
			observaciones: ['']
		});
	}

	ngOnInit(): void {
		this.authService.currentUser$.subscribe(user => {
			console.log('👤 Usuario actual:', user);
			if (user) {
				this.usuarioId = user.id_usuario;
				console.log('🔑 ID de usuario:', this.usuarioId);
				this.loadFunciones();
			} else {
				console.warn('⚠️ No hay usuario autenticado');
			}
		});
		this.loadFuncionesDisponibles();
	}

	loadFunciones(): void {
		if (!this.usuarioId) {
			console.warn('⚠️ No se puede cargar funciones: usuarioId es null');
			return;
		}

		console.log('🔄 Cargando funciones para usuario:', this.usuarioId);
		this.loading = true;
		this.funcionService.getUsuarioFunciones(this.usuarioId).subscribe({
			next: (response) => {
				console.log('✅ Respuesta del servidor:', response);
				if (response.success && response.data) {
					this.funciones = response.data;
					console.log('📋 Funciones cargadas:', this.funciones.length, 'funciones');
					this.agruparPorModulo();
				} else {
					console.warn('⚠️ Respuesta sin datos:', response);
				}
				this.loading = false;
			},
			error: (error) => {
				console.error('❌ Error al cargar funciones:', error);
				console.error('Detalles del error:', {
					status: error.status,
					message: error.message,
					error: error.error
				});
				this.showAlert('Error al cargar funciones', 'error');
				this.loading = false;
			}
		});
	}

	loadFuncionesDisponibles(): void {
		this.funcionService.getFunciones(true).subscribe({
			next: (response) => {
				if (response.success && response.data) {
					this.funcionesDisponibles = response.data;
				}
			},
			error: (error) => {
				console.error('Error al cargar funciones disponibles:', error);
			}
		});
	}

	agruparPorModulo(): void {
		this.funcionesAgrupadas = {};
		this.funciones.forEach(funcion => {
			const modulo = funcion.modulo || 'sin_modulo';
			if (!this.funcionesAgrupadas[modulo]) {
				this.funcionesAgrupadas[modulo] = [];
			}
			this.funcionesAgrupadas[modulo].push(funcion);
		});
	}

	get modulos(): string[] {
		return Object.keys(this.funcionesAgrupadas);
	}

	get funcionesFiltradas(): UsuarioFuncion[] {
		if (!this.searchTerm) return this.funciones;

		const term = this.searchTerm.toLowerCase();
		return this.funciones.filter(f =>
			f.nombre.toLowerCase().includes(term) ||
			f.codigo.toLowerCase().includes(term) ||
			f.modulo.toLowerCase().includes(term)
		);
	}

	openAddModal(): void {
		this.funcionForm.reset({
			fecha_ini: new Date().toISOString().split('T')[0]
		});
		this.showAddModal = true;
	}

	closeAddModal(): void {
		this.showAddModal = false;
		this.funcionForm.reset();
	}

	openEditModal(funcion: UsuarioFuncion): void {
		this.editingFuncion = funcion;
		this.funcionForm.patchValue({
			id_funcion: funcion.id_funcion,
			fecha_ini: funcion.fecha_ini,
			fecha_fin: funcion.fecha_fin || '',
			observaciones: funcion.observaciones || ''
		});
		this.showEditModal = true;
	}

	closeEditModal(): void {
		this.showEditModal = false;
		this.editingFuncion = null;
		this.funcionForm.reset();
	}

	openDeleteModal(funcion: UsuarioFuncion): void {
		this.deletingFuncion = funcion;
		this.showDeleteModal = true;
	}

	closeDeleteModal(): void {
		this.showDeleteModal = false;
		this.deletingFuncion = null;
	}

	asignarFuncion(): void {
		if (this.funcionForm.invalid || !this.usuarioId) return;

		const request: AsignarFuncionRequest = {
			id_funcion: this.funcionForm.value.id_funcion,
			fecha_ini: this.funcionForm.value.fecha_ini,
			fecha_fin: this.funcionForm.value.fecha_fin || null,
			observaciones: this.funcionForm.value.observaciones
		};

		this.loading = true;
		this.funcionService.asignarFuncion(this.usuarioId, request).subscribe({
			next: (response) => {
				if (response.success) {
					this.showAlert('Función asignada exitosamente', 'success');
					this.loadFunciones();
					this.closeAddModal();
				}
				this.loading = false;
			},
			error: (error) => {
				console.error('Error al asignar función:', error);
				this.showAlert('Error al asignar función', 'error');
				this.loading = false;
			}
		});
	}

	actualizarFuncion(): void {
		if (this.funcionForm.invalid || !this.usuarioId || !this.editingFuncion) return;

		const request = {
			fecha_ini: this.funcionForm.value.fecha_ini,
			fecha_fin: this.funcionForm.value.fecha_fin || null,
			observaciones: this.funcionForm.value.observaciones
		};

		this.loading = true;
		this.funcionService.actualizarFuncion(
			this.usuarioId,
			this.editingFuncion.id_funcion,
			request
		).subscribe({
			next: (response) => {
				if (response.success) {
					this.showAlert('Función actualizada exitosamente', 'success');
					this.loadFunciones();
					this.closeEditModal();
				}
				this.loading = false;
			},
			error: (error) => {
				console.error('Error al actualizar función:', error);
				this.showAlert('Error al actualizar función', 'error');
				this.loading = false;
			}
		});
	}

	quitarFuncion(): void {
		if (!this.usuarioId || !this.deletingFuncion) return;

		this.loading = true;
		this.funcionService.quitarFuncion(
			this.usuarioId,
			this.deletingFuncion.id_funcion
		).subscribe({
			next: (response) => {
				if (response.success) {
					this.showAlert('Función removida exitosamente', 'success');
					this.loadFunciones();
					this.closeDeleteModal();
				}
				this.loading = false;
			},
			error: (error) => {
				console.error('Error al quitar función:', error);
				this.showAlert('Error al quitar función', 'error');
				this.loading = false;
			}
		});
	}

	estaExpirada(funcion: UsuarioFuncion): boolean {
		if (!funcion.fecha_fin) return false;
		return new Date(funcion.fecha_fin) < new Date();
	}

	esTemporal(funcion: UsuarioFuncion): boolean {
		return !!funcion.fecha_fin;
	}

	showAlert(message: string, type: 'success' | 'error' | 'warning'): void {
		this.alertMessage = message;
		this.alertType = type;
		setTimeout(() => {
			this.alertMessage = '';
		}, 3000);
	}
}
