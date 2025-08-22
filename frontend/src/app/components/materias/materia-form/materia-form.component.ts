import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule, ReactiveFormsModule, FormBuilder, FormGroup, Validators } from '@angular/forms';
import { Router, ActivatedRoute, RouterModule } from '@angular/router';
import { Materia, Pensum, ParametroEconomico } from '../../../models/materia.model';
import { MateriaService } from '../../../services/materia.service';

@Component({
	selector: 'app-materia-form',
	standalone: true,
	imports: [CommonModule, FormsModule, ReactiveFormsModule, RouterModule],
	template: `
		<div class="materia-form-container">
			<div class="form-header">
				<h1 class="form-title">{{ isEditMode ? 'Editar Materia' : 'Nueva Materia' }}</h1>
				<button class="btn-secondary" routerLink="/materias">
					<i class="fas fa-arrow-left"></i> Volver
				</button>
			</div>

			<div class="alert alert-danger" *ngIf="error">
				{{ error }}
			</div>

			<form [formGroup]="materiaForm" (ngSubmit)="onSubmit()" class="materia-form">
				<div class="form-row">
					<div class="form-group">
						<label for="sigla_materia">Sigla *</label>
						<input 
							type="text" 
							id="sigla_materia" 
							formControlName="sigla_materia" 
							class="form-control"
							[class.is-invalid]="submitted && f['sigla_materia'].errors"
							[disabled]="isEditMode"
						>
						<div class="invalid-feedback" *ngIf="submitted && f['sigla_materia'].errors">
							<span *ngIf="f['sigla_materia'].errors['required']">Sigla es requerida</span>
						</div>
					</div>

					<div class="form-group">
						<label for="nombre_materia">Nombre *</label>
						<input 
							type="text" 
							id="nombre_materia" 
							formControlName="nombre_materia" 
							class="form-control"
							[class.is-invalid]="submitted && f['nombre_materia'].errors"
						>
						<div class="invalid-feedback" *ngIf="submitted && f['nombre_materia'].errors">
							<span *ngIf="f['nombre_materia'].errors['required']">Nombre es requerido</span>
						</div>
					</div>
				</div>

				<div class="form-row">
					<div class="form-group">
						<label for="nombre_material_oficial">Nombre Material Oficial *</label>
						<input 
							type="text" 
							id="nombre_material_oficial" 
							formControlName="nombre_material_oficial" 
							class="form-control"
							[class.is-invalid]="submitted && f['nombre_material_oficial'].errors"
						>
						<div class="invalid-feedback" *ngIf="submitted && f['nombre_material_oficial'].errors">
							<span *ngIf="f['nombre_material_oficial'].errors['required']">Nombre Material Oficial es requerido</span>
						</div>
					</div>

					<div class="form-group">
						<label for="cod_pensum">Pensum *</label>
						<select 
							id="cod_pensum" 
							formControlName="cod_pensum" 
							class="form-control"
							[disabled]="isEditMode"
							[class.is-invalid]="submitted && f['cod_pensum'].errors"
						>
							<option [ngValue]="null" disabled>Seleccione un pensum</option>
							<option *ngFor="let pensum of pensumsList" [ngValue]="pensum.cod_pensum">
								{{ pensum.nombre }}
							</option>
						</select>
						<div class="invalid-feedback" *ngIf="submitted && f['cod_pensum'].errors">
							<span *ngIf="f['cod_pensum'].errors['required']">Pensum es requerido</span>
						</div>
					</div>
				</div>

				<div class="form-row">
					<div class="form-group">
						<label for="id_parametro_economico">Parámetro Económico *</label>
						<select 
							id="id_parametro_economico" 
							formControlName="id_parametro_economico" 
							class="form-control"
							[class.is-invalid]="submitted && f['id_parametro_economico'].errors"
						>
							<option [ngValue]="null" disabled>Seleccione un parámetro económico</option>
							<option *ngFor="let parametro of parametrosEconomicos" [ngValue]="parametro.id_parametro_economico">
								{{ parametro.nombre }} - {{ parametro.valor }}
							</option>
						</select>
						<div class="invalid-feedback" *ngIf="submitted && f['id_parametro_economico'].errors">
							<span *ngIf="f['id_parametro_economico'].errors['required']">Parámetro económico es requerido</span>
						</div>
					</div>

					<div class="form-group">
						<label for="nro_creditos">Número de Créditos *</label>
						<input 
							type="number" 
							id="nro_creditos" 
							formControlName="nro_creditos" 
							class="form-control"
							[class.is-invalid]="submitted && f['nro_creditos'].errors"
							min="1"
						>
						<div class="invalid-feedback" *ngIf="submitted && f['nro_creditos'].errors">
							<span *ngIf="f['nro_creditos'].errors['required']">Número de créditos es requerido</span>
							<span *ngIf="f['nro_creditos'].errors['min']">Número de créditos debe ser mayor a 0</span>
						</div>
					</div>
				</div>

				<div class="form-row">
					<div class="form-group">
						<label for="orden">Orden *</label>
						<input 
							type="number" 
							id="orden" 
							formControlName="orden" 
							class="form-control"
							[class.is-invalid]="submitted && f['orden'].errors"
							min="1"
						>
						<div class="invalid-feedback" *ngIf="submitted && f['orden'].errors">
							<span *ngIf="f['orden'].errors['required']">Orden es requerido</span>
							<span *ngIf="f['orden'].errors['min']">Orden debe ser mayor a 0</span>
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
								{{ materiaForm.get('estado')?.value ? 'Activa' : 'Inactiva' }}
							</span>
						</div>
					</div>
				</div>

				<div class="form-actions">
					<button type="button" class="btn-secondary" routerLink="/materias">Cancelar</button>
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
		.materia-form-container {
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

		.materia-form {
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
			
			.materia-form {
				padding: 1rem;
			}
		}
	`
})
export class MateriaFormComponent implements OnInit {
	materiaForm: FormGroup;
	pensumsList: Pensum[] = [];
	parametrosEconomicos: ParametroEconomico[] = [];
	isEditMode = false;
	submitted = false;
	isSubmitting = false;
	error = '';
	materiaSigla: string | null = null;
	materiaPensum: string | null = null;

	constructor(
		private formBuilder: FormBuilder,
		private materiaService: MateriaService,
		private router: Router,
		private route: ActivatedRoute
	) {
		this.materiaForm = this.formBuilder.group({
			sigla_materia: ['', Validators.required],
			nombre_materia: ['', Validators.required],
			nombre_material_oficial: ['', Validators.required],
			cod_pensum: [null, Validators.required],
			id_parametro_economico: [null, Validators.required],
			nro_creditos: [null, [Validators.required, Validators.min(1)]],
			orden: [null, [Validators.required, Validators.min(1)]],
			descripcion: [''],
			estado: [true]
		});
	}

	ngOnInit(): void {
		this.loadPensums();
		this.loadParametrosEconomicos();
		
		// Verificar si estamos en modo edición
		const sigla = this.route.snapshot.paramMap.get('sigla');
		const pensum = this.route.snapshot.paramMap.get('pensum');
		if (sigla && pensum) {
			this.isEditMode = true;
			this.materiaSigla = sigla;
			this.materiaPensum = pensum;
			this.loadMateria(this.materiaSigla, this.materiaPensum);
		}
	}

	// En un escenario real, estos métodos cargarían datos desde servicios específicos
	loadPensums(): void {
		// Datos de ejemplo - En producción se usaría un servicio para pensums
		this.pensumsList = [
			{ cod_pensum: 'ING-SIS-2020', codigo_carrera: 'ING-SIS', nombre: 'Ingeniería de Sistemas 2020', descripcion: '' },
			{ cod_pensum: 'CONT-2019', codigo_carrera: 'CONT', nombre: 'Contaduría 2019', descripcion: '' },
			{ cod_pensum: 'ADM-EMP-2021', codigo_carrera: 'ADM-EMP', nombre: 'Administración de Empresas 2021', descripcion: '' }
		];
	}

	loadParametrosEconomicos(): void {
		// Datos de ejemplo - En producción se usaría un servicio para parámetros económicos
		this.parametrosEconomicos = [
			{ id_parametro_economico: 1, nombre: 'Básico', tipo: 'materia', valor: 100, estado: true },
			{ id_parametro_economico: 2, nombre: 'Intermedio', tipo: 'materia', valor: 150, estado: true },
			{ id_parametro_economico: 3, nombre: 'Avanzado', tipo: 'materia', valor: 200, estado: true }
		];
	}

	loadMateria(sigla: string, pensum: string): void {
		this.materiaService.getOne(sigla, pensum).subscribe({
			next: (response) => {
				if (response.success && response.data) {
					const materia = response.data;
					this.materiaForm.patchValue({
						sigla_materia: materia.sigla_materia,
						nombre_materia: materia.nombre_materia,
						nombre_material_oficial: materia.nombre_material_oficial,
						cod_pensum: materia.cod_pensum,
						id_parametro_economico: materia.id_parametro_economico,
						nro_creditos: materia.nro_creditos,
						orden: materia.orden,
						descripcion: materia.descripcion,
						estado: materia.estado
					});
				}
			},
			error: (error) => {
				console.error('Error al cargar materia:', error);
				this.error = 'No se pudo cargar la información de la materia. Intente nuevamente más tarde.';
			}
		});
	}

	get f() { 
		return this.materiaForm.controls; 
	}

	onSubmit(): void {
		this.submitted = true;

		if (this.materiaForm.invalid) {
			return;
		}

		this.isSubmitting = true;
		const formData = this.materiaForm.value;

		if (this.isEditMode && this.materiaSigla && this.materiaPensum) {
			// En modo edición, actualizar materia existente
			this.materiaService.update(this.materiaSigla, this.materiaPensum, formData).subscribe({
				next: (response) => {
					if (response.success) {
						this.router.navigate(['/materias']);
					} else {
						this.error = response.message || 'Error al actualizar materia';
					}
					this.isSubmitting = false;
				},
				error: (error) => {
					console.error('Error al actualizar materia:', error);
					this.error = error.error?.message || 'Error al actualizar materia. Intente nuevamente más tarde.';
					this.isSubmitting = false;
				}
			});
		} else {
			// En modo creación, crear nueva materia
			this.materiaService.create(formData).subscribe({
				next: (response) => {
					if (response.success) {
						this.router.navigate(['/materias']);
					} else {
						this.error = response.message || 'Error al crear materia';
					}
					this.isSubmitting = false;
				},
				error: (error) => {
					console.error('Error al crear materia:', error);
					this.error = error.error?.message || 'Error al crear materia. Intente nuevamente más tarde.';
					this.isSubmitting = false;
				}
			});
		}
	}
}
