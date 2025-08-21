import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule, ReactiveFormsModule, FormBuilder, FormGroup, Validators } from '@angular/forms';
import { Router, ActivatedRoute, RouterModule } from '@angular/router';
import { Rol } from '../../../models/usuario.model';
import { RolService } from '../../../services/rol.service';

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
				<div class="form-row">
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
				</div>

				<div class="form-row">
					<div class="form-group">
						<label for="descripcion">Descripción</label>
						<textarea 
							id="descripcion" 
							formControlName="descripcion" 
							class="form-control"
							rows="3"
						></textarea>
					</div>
				</div>

				<div class="form-row" *ngIf="isEditMode">
					<div class="form-group">
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

				<!-- Área de permisos - Esta sección se implementaría si existe un sistema de permisos -->
				<!-- En una implementación real, aquí habría una lista de permisos con checkboxes -->
				<div class="form-row">
					<div class="form-group">
						<label>Permisos del Sistema</label>
						<div class="permissions-note">
							<p>Los permisos del rol se configurarán en una fase posterior del desarrollo.</p>
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
			
			.form-group {
				margin-bottom: 1rem;
			}
			
			.rol-form {
				padding: 1rem;
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

	constructor(
		private formBuilder: FormBuilder,
		private rolService: RolService,
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
						this.router.navigate(['/roles']);
					} else {
						this.error = response.message || 'Error al actualizar rol';
					}
					this.isSubmitting = false;
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
}
