import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule, ReactiveFormsModule, FormBuilder, FormGroup, Validators } from '@angular/forms';
import { Router, ActivatedRoute, RouterModule } from '@angular/router';
import { Rol } from '../../../models/usuario.model';
import { RolService } from '../../../services/rol.service';
import { FuncionService } from '../../../services/funcion.service';

@Component({
	selector: 'app-rol-form',
	standalone: true,
	imports: [CommonModule, FormsModule, ReactiveFormsModule, RouterModule],
	template: `
		<div class="rol-form-container">
			<div class="form-header">
				<h1 class="form-title">{{ isEditMode ? 'Editar Rol' : 'Nuevo Rol' }}</h1>
				<button class="btn-secondary" routerLink="/roles">
					<i class="fas fa-arrow-left"></i> Volver
				</button>
			</div>

			<div class="alert alert-danger" *ngIf="error">
				{{ error }}
			</div>

			<form [formGroup]="rolForm" (ngSubmit)="onSubmit()" class="rol-form">
				<!-- Layout de dos columnas -->
				<div class="form-row-two-cols">
					<!-- Columna izquierda: Nombre y Estado -->
					<div class="form-col-left">
						<div class="form-group">
							<label for="nombre">Nombre del Rol *</label>
							<input
								type="text"
								id="nombre"
								formControlName="nombre"
								class="form-control"
								[class.is-invalid]="submitted && f['nombre'].errors"
							>
							<div class="invalid-feedback" *ngIf="submitted && f['nombre'].errors">
								<span *ngIf="f['nombre'].errors['required']">Nombre del rol es requerido</span>
							</div>
						</div>

						<div class="form-group" *ngIf="isEditMode">
							<label for="estado">Estado</label>
							<div class="toggle-switch">
								<input
									type="checkbox"
									id="estado"
									formControlName="estado"
									class="toggle-input"
								>
								<label for="estado" class="toggle-label">
									<span class="toggle-inner"></span>
									<span class="toggle-switch-indicator"></span>
								</label>
								<span class="toggle-status">
									{{ rolForm.get('estado')?.value ? 'Activo' : 'Inactivo' }}
								</span>
							</div>
						</div>
					</div>

					<!-- Columna derecha: Descripción -->
					<div class="form-col-right">
						<div class="form-group">
							<label for="descripcion">Descripción</label>
							<textarea
								id="descripcion"
								formControlName="descripcion"
								class="form-control"
								rows="5"
							></textarea>
						</div>
					</div>
				</div>

				<!-- Funciones del Sistema -->
				<div class="funciones-section" *ngIf="isEditMode">
					<h3 class="section-title">Funciones del Sistema</h3>

					<!-- Búsqueda -->
					<div class="search-box">
						<input
							type="text"
							[(ngModel)]="funcionesSearchTerm"
							[ngModelOptions]="{standalone: true}"
							(input)="filterFunciones()"
							placeholder="Buscar funciones..."
							class="search-input"
						>
					</div>

					<!-- Contador de funciones seleccionadas -->
					<div class="selected-count">
						<strong>Funciones seleccionadas:</strong> {{ selectedFunciones.length }}
					</div>

					<!-- Tabs por módulo -->
					<div class="tabs-container">
						<button
							type="button"
							*ngFor="let modulo of modulos"
							class="tab-button"
							[class.active]="selectedModulo === modulo"
							[class.has-selected]="moduloHasFuncionesSeleccionadas(modulo)"
							(click)="selectedModulo = modulo; filterFunciones()">
							{{ modulo }}
						</button>
					</div>

					<!-- Loading -->
					<div *ngIf="loadingFunciones" class="loading-container">
						<div class="spinner-border text-primary" role="status">
							<span class="visually-hidden">Cargando...</span>
						</div>
					</div>

					<!-- Lista de funciones -->
					<div *ngIf="!loadingFunciones" class="funciones-list">
						<!-- Checkbox seleccionar todas -->
						<div class="select-all-container">
							<label class="checkbox-container">
								<input
									type="checkbox"
									[checked]="allFuncionesSelected()"
									(change)="toggleAllFunciones($event)"
								>
								<span class="checkmark"></span>
								<span class="label-text">Seleccionar todas</span>
							</label>
						</div>

						<!-- Items de funciones -->
						<div class="funciones-items">
							<div *ngFor="let funcion of filteredFuncionesDisponibles" class="funcion-item">
								<label class="checkbox-container">
									<input
										type="checkbox"
										[checked]="isFuncionSelected(funcion.id_funcion)"
										(change)="toggleFuncion(funcion.id_funcion)"
									>
									<span class="checkmark"></span>
									<div class="funcion-details">
										<div class="funcion-header">
											<span class="funcion-codigo">{{ funcion.codigo }}</span>
										</div>
										<div class="funcion-nombre">{{ funcion.nombre }}</div>
										<div class="funcion-descripcion" *ngIf="funcion.descripcion">{{ funcion.descripcion }}</div>
									</div>
								</label>
							</div>
							<div *ngIf="filteredFuncionesDisponibles.length === 0" class="no-funciones">
								No se encontraron funciones
							</div>
						</div>
					</div>
				</div>

				<div class="form-actions">
					<button type="button" class="btn-secondary" routerLink="/roles">Cancelar</button>
					<button type="submit" class="btn-primary" [disabled]="isSubmitting">
						<span *ngIf="isSubmitting">
							<i class="fas fa-spinner fa-spin"></i> Guardando...
						</span>
						<span *ngIf="!isSubmitting">
							<i class="fas fa-save"></i> {{ isEditMode ? 'Actualizar' : 'Guardar' }}
						</span>
					</button>
				</div>
			</form>
		</div>
	`,
	styles: `
		.rol-form-container {
			padding: 1rem;
			max-width: 800px;
			margin: 0 auto;
		}

		.form-header {
			display: flex;
			justify-content: space-between;
			align-items: center;
			margin-bottom: 1.5rem;
		}

		.form-title {
			font-size: 1.5rem;
			font-weight: 600;
			margin: 0;
		}

		.alert {
			padding: 0.75rem 1rem;
			margin-bottom: 1rem;
			border-radius: 4px;
		}

		.alert-danger {
			background-color: #f8d7da;
			color: #721c24;
			border: 1px solid #f5c6cb;
		}

		.rol-form {
			background-color: #fff;
			padding: 1.5rem;
			border-radius: 8px;
			box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
		}

		.form-row {
			display: flex;
			gap: 1rem;
			margin-bottom: 1rem;
		}

		.form-row-two-cols {
			display: grid;
			grid-template-columns: 1fr 2fr;
			gap: 1.5rem;
			margin-bottom: 1.5rem;
		}

		.form-col-left,
		.form-col-right {
			display: flex;
			flex-direction: column;
			gap: 1rem;
		}

		.form-group {
			flex: 1;
			display: flex;
			flex-direction: column;
		}

		label {
			font-weight: 500;
			margin-bottom: 0.5rem;
		}

		.form-control {
			padding: 0.5rem 0.75rem;
			border: 1px solid #ddd;
			border-radius: 4px;
			font-size: 1rem;
		}

		.form-control:focus {
			outline: none;
			border-color: #0275d8;
			box-shadow: 0 0 0 2px rgba(2, 117, 216, 0.25);
		}

		.form-control.is-invalid {
			border-color: #dc3545;
		}

		.invalid-feedback {
			color: #dc3545;
			font-size: 0.875rem;
			margin-top: 0.25rem;
		}

		textarea.form-control {
			resize: vertical;
			min-height: 100px;
		}

		.toggle-switch {
			display: flex;
			align-items: center;
			gap: 0.5rem;
		}

		.toggle-input {
			display: none;
		}

		.toggle-label {
			position: relative;
			display: inline-block;
			width: 50px;
			height: 24px;
			cursor: pointer;
		}

		.toggle-inner {
			position: absolute;
			top: 0;
			left: 0;
			right: 0;
			bottom: 0;
			background-color: #ccc;
			border-radius: 34px;
			transition: background-color 0.2s;
		}

		.toggle-switch-indicator {
			position: absolute;
			content: "";
			height: 18px;
			width: 18px;
			left: 3px;
			bottom: 3px;
			background-color: white;
			border-radius: 50%;
			transition: transform 0.2s;
		}

		.toggle-input:checked + .toggle-label .toggle-inner {
			background-color: #28a745;
		}

		.toggle-input:checked + .toggle-label .toggle-switch-indicator {
			transform: translateX(26px);
		}

		.toggle-status {
			font-size: 0.875rem;
			color: #6c757d;
		}

		.permissions-note {
			padding: 1rem;
			background-color: #f8f9fa;
			border: 1px dashed #ddd;
			border-radius: 4px;
		}

		.permissions-note p {
			margin: 0;
			color: #6c757d;
		}

		.funciones-section {
			margin-top: 1.5rem;
			padding-top: 1.5rem;
			border-top: 2px solid #e9ecef;
		}

		.section-title {
			font-size: 1.15rem;
			font-weight: 600;
			margin-bottom: 0.75rem;
			color: #2c3e50;
		}

		.search-box {
			margin-bottom: 0.75rem;
		}

		.search-input {
			width: 100%;
			padding: 0.75rem;
			border: 1px solid #ddd;
			border-radius: 4px;
			font-size: 0.95rem;
		}

		.search-input:focus {
			outline: none;
			border-color: #0275d8;
			box-shadow: 0 0 0 0.2rem rgba(2, 117, 216, 0.25);
		}

		.tabs-container {
			display: flex;
			gap: 0;
			flex-wrap: wrap;
			border-bottom: 1px solid #dee2e6;
			margin-bottom: 1rem;
		}

		.tab-button {
			background: none;
			border: none;
			padding: 0.75rem 1.25rem;
			cursor: pointer;
			border-bottom: 3px solid transparent;
			color: #6c757d;
			font-size: 0.9rem;
			transition: all 0.2s;
		}

		.tab-button:hover {
			background-color: #f8f9fa;
			color: #495057;
		}

		.tab-button.active {
			color: #0275d8;
			border-bottom-color: #0275d8;
			font-weight: 500;
		}

		.tab-button.has-selected {
			font-weight: 600;
			color: #0275d8;
		}

		.tab-button.has-selected:not(.active) {
			opacity: 0.7;
		}

		.loading-container {
			text-align: center;
			padding: 3rem 0;
		}

		.funciones-list {
			margin-top: 0.5rem;
		}

		.select-all-container {
			padding: 1rem;
			background-color: #f8f9fa;
			border: 1px solid #dee2e6;
			border-bottom: none;
			border-radius: 4px 4px 0 0;
		}

		.funciones-items {
			max-height: 400px;
			overflow-y: auto;
			border: 1px solid #dee2e6;
			border-radius: 0 0 4px 4px;
		}

		.funcion-item {
			padding: 1rem;
			border-bottom: 1px solid #e9ecef;
			transition: background-color 0.2s;
		}

		.funcion-item:last-child {
			border-bottom: none;
		}

		.funcion-item:hover {
			background-color: #f8f9fa;
		}

		.checkbox-container {
			display: flex;
			align-items: flex-start;
			gap: 1rem;
			cursor: pointer;
			position: relative;
			padding-left: 2rem;
		}

		.checkbox-container input[type="checkbox"] {
			position: absolute;
			opacity: 0;
			cursor: pointer;
			height: 0;
			width: 0;
		}

		.checkbox-container input[type="checkbox"]:checked ~ .checkmark {
			background-color: #0275d8;
			border-color: #0275d8;
		}

		.checkbox-container input[type="checkbox"]:checked ~ .checkmark:after {
			display: block;
		}

		.checkbox-container:hover input ~ .checkmark {
			background-color: #f0f0f0;
		}

		.checkmark {
			position: absolute;
			left: 0;
			top: 2px;
			height: 20px;
			width: 20px;
			background-color: white;
			border: 2px solid #ddd;
			border-radius: 3px;
			transition: all 0.2s;
		}

		.checkmark:after {
			content: "";
			position: absolute;
			display: none;
			left: 6px;
			top: 2px;
			width: 5px;
			height: 10px;
			border: solid white;
			border-width: 0 2px 2px 0;
			transform: rotate(45deg);
		}

		.label-text {
			font-weight: 500;
			color: #495057;
		}

		.funcion-details {
			display: flex;
			flex-direction: column;
			gap: 0.25rem;
			flex: 1;
		}

		.funcion-header {
			display: flex;
			align-items: center;
			gap: 0.5rem;
		}

		.funcion-codigo {
			font-family: 'Courier New', monospace;
			font-weight: 600;
			color: #6c757d;
			font-size: 0.85rem;
			background-color: #f8f9fa;
			padding: 0.15rem 0.4rem;
			border-radius: 3px;
		}

		.funcion-nombre {
			font-weight: 500;
			color: #212529;
			font-size: 0.95rem;
		}

		.funcion-descripcion {
			font-size: 0.85rem;
			color: #6c757d;
			line-height: 1.4;
		}

		.no-funciones {
			text-align: center;
			padding: 3rem 2rem;
			color: #6c757d;
			font-size: 0.95rem;
		}

		.selected-count {
			padding: 0.6rem 0.9rem;
			background-color: #e7f3ff;
			border-left: 3px solid #0275d8;
			border-radius: 4px;
			color: #0275d8;
			font-size: 0.875rem;
			font-weight: 500;
			margin-bottom: 0.75rem;
		}

		.form-actions {
			display: flex;
			justify-content: flex-end;
			gap: 1rem;
			margin-top: 1.5rem;
		}

		.btn-primary, .btn-secondary {
			padding: 0.5rem 1rem;
			border: none;
			border-radius: 4px;
			cursor: pointer;
			font-weight: 500;
			display: flex;
			align-items: center;
			gap: 0.5rem;
		}

		.btn-primary {
			background-color: #0275d8;
			color: white;
		}

		.btn-primary:hover {
			background-color: #0069d9;
		}

		.btn-primary:disabled {
			background-color: #80bdff;
			cursor: not-allowed;
		}

		.btn-secondary {
			background-color: #6c757d;
			color: white;
		}

		.btn-secondary:hover {
			background-color: #5a6268;
		}

		@media (max-width: 767.98px) {
			.form-row {
				flex-direction: column;
				gap: 0;
			}

			.form-row-two-cols {
				grid-template-columns: 1fr;
				gap: 1rem;
			}

			.form-group {
				margin-bottom: 1rem;
			}

			.rol-form {
				padding: 1rem;
			}

			.tabs-container {
				overflow-x: auto;
			}
		}
	`
})
export class RolFormComponent implements OnInit {
	rolForm: FormGroup;
	isEditMode = false;
	submitted = false;
	isSubmitting = false;
	error = '';
	rolId: number | null = null;

	// Gestión de funciones
	funcionesDisponibles: any[] = [];
	filteredFuncionesDisponibles: any[] = [];
	funcionesSearchTerm = '';
	selectedFunciones: number[] = [];
	loadingFunciones = false;
	modulos: string[] = [];
	selectedModulo = 'Todos';

	constructor(
		private formBuilder: FormBuilder,
		private rolService: RolService,
		private funcionService: FuncionService,
		private router: Router,
		private route: ActivatedRoute
	) {
		this.rolForm = this.formBuilder.group({
			nombre: ['', Validators.required],
			descripcion: [''],
			estado: [true]
		});
	}

	ngOnInit(): void {
		// Verificar si estamos en modo edición
		const id = this.route.snapshot.paramMap.get('id');
		if (id && !isNaN(+id)) {
			this.isEditMode = true;
			this.rolId = +id;
			this.loadRol(this.rolId);
			this.loadAllFunciones();
			this.loadRolFunciones(this.rolId);
		}
	}

	loadAllFunciones(): void {
		this.loadingFunciones = true;
		this.funcionService.getFunciones(true).subscribe({
			next: (response) => {
				if (response.success && response.data) {
					this.funcionesDisponibles = response.data;
					this.modulos = ['Todos', ...new Set(this.funcionesDisponibles.map(f => f.modulo))];
					this.filterFunciones();
				}
				this.loadingFunciones = false;
			},
			error: (error) => {
				console.error('Error al cargar funciones:', error);
				this.loadingFunciones = false;
			}
		});
	}

	loadRolFunciones(rolId: number): void {
		this.rolService.getRolFunciones(rolId).subscribe({
			next: (response) => {
				if (response.success && response.data) {
					this.selectedFunciones = response.data.map((f: any) => f.id_funcion);
				}
			},
			error: (error) => {
				console.error('Error al cargar funciones del rol:', error);
			}
		});
	}

	filterFunciones(): void {
		let filtered = [...this.funcionesDisponibles];

		if (this.selectedModulo !== 'Todos') {
			filtered = filtered.filter(f => f.modulo === this.selectedModulo);
		}

		if (this.funcionesSearchTerm.trim() !== '') {
			const term = this.funcionesSearchTerm.toLowerCase().trim();
			filtered = filtered.filter(f =>
				f.codigo.toLowerCase().includes(term) ||
				f.nombre.toLowerCase().includes(term) ||
				(f.descripcion && f.descripcion.toLowerCase().includes(term))
			);
		}

		this.filteredFuncionesDisponibles = filtered;
	}

	isFuncionSelected(funcionId: number): boolean {
		return this.selectedFunciones.includes(funcionId);
	}

	toggleFuncion(funcionId: number): void {
		const index = this.selectedFunciones.indexOf(funcionId);
		if (index > -1) {
			this.selectedFunciones.splice(index, 1);
		} else {
			this.selectedFunciones.push(funcionId);
		}
	}

	allFuncionesSelected(): boolean {
		if (this.filteredFuncionesDisponibles.length === 0) return false;
		return this.filteredFuncionesDisponibles.every(f =>
			this.selectedFunciones.includes(f.id_funcion)
		);
	}

	moduloHasFuncionesSeleccionadas(modulo: string): boolean {
		if (modulo === 'Todos') {
			return this.selectedFunciones.length > 0;
		}
		const funcionesDelModulo = this.funcionesDisponibles.filter(f => f.modulo === modulo);
		return funcionesDelModulo.some(f => this.selectedFunciones.includes(f.id_funcion));
	}

	toggleAllFunciones(event: any): void {
		if (event.target.checked) {
			this.filteredFuncionesDisponibles.forEach(f => {
				if (!this.selectedFunciones.includes(f.id_funcion)) {
					this.selectedFunciones.push(f.id_funcion);
				}
			});
		} else {
			this.filteredFuncionesDisponibles.forEach(f => {
				const index = this.selectedFunciones.indexOf(f.id_funcion);
				if (index > -1) {
					this.selectedFunciones.splice(index, 1);
				}
			});
		}
	}

	loadRol(id: number): void {
		this.rolService.getById(id).subscribe({
			next: (response) => {
				if (response.success && response.data) {
					const rol = response.data;
					this.rolForm.patchValue({
						nombre: rol.nombre,
						descripcion: rol.descripcion,
						estado: rol.estado
					});
				}
			},
			error: (error) => {
				console.error('Error al cargar rol:', error);
				this.error = 'No se pudo cargar la información del rol. Intente nuevamente más tarde.';
			}
		});
	}

	get f() {
		return this.rolForm.controls;
	}

	onSubmit(): void {
		this.submitted = true;

		if (this.rolForm.invalid) {
			return;
		}

		this.isSubmitting = true;
		const formData = this.rolForm.value;

		if (this.isEditMode && this.rolId) {
			// En modo edición, actualizar rol existente
			this.rolService.update(this.rolId, formData).subscribe({
				next: (response) => {
					if (response.success) {
						// Guardar funciones seleccionadas
						this.saveFunciones();
					} else {
						this.error = response.message || 'Error al actualizar rol';
						this.isSubmitting = false;
					}
				},
				error: (error) => {
					console.error('Error al actualizar rol:', error);
					this.error = error.error?.message || 'Error al actualizar rol. Intente nuevamente más tarde.';
					this.isSubmitting = false;
				}
			});
		} else {
			// En modo creación, crear nuevo rol
			this.rolService.create(formData).subscribe({
				next: (response) => {
					if (response.success) {
						this.router.navigate(['/roles']);
					} else {
						this.error = response.message || 'Error al crear rol';
					}
					this.isSubmitting = false;
				},
				error: (error) => {
					console.error('Error al crear rol:', error);
					this.error = error.error?.message || 'Error al crear rol. Intente nuevamente más tarde.';
					this.isSubmitting = false;
				}
			});
		}
	}

	saveFunciones(): void {
		if (!this.rolId) {
			this.router.navigate(['/roles']);
			return;
		}

		this.rolService.assignFunciones(this.rolId, this.selectedFunciones).subscribe({
			next: (response: any) => {
				if (response.success) {
					this.router.navigate(['/roles']);
				} else {
					this.error = 'Error al guardar funciones';
				}
				this.isSubmitting = false;
			},
			error: (error: any) => {
				console.error('Error al guardar funciones:', error);
				this.error = 'Error al guardar funciones';
				this.isSubmitting = false;
			}
		});
	}
}
