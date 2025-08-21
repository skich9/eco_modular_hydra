import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule, ReactiveFormsModule, FormBuilder, FormGroup, Validators } from '@angular/forms';
import { Router, ActivatedRoute, RouterModule } from '@angular/router';
import { Usuario, Rol } from '../../../models/usuario.model';
import { UsuarioService } from '../../../services/usuario.service';
import { RolService } from '../../../services/rol.service';

@Component({
	selector: 'app-usuario-form',
	standalone: true,
	imports: [CommonModule, FormsModule, ReactiveFormsModule, RouterModule],
	template: `
		<div class="usuario-form-container">
			<div class="form-header">
				<h1 class="form-title">{{ isEditMode ? 'Editar Usuario' : 'Nuevo Usuario' }}</h1>
				<button class="btn-secondary" routerLink="/usuarios">
					<i class="fas fa-arrow-left"></i> Volver
				</button>
			</div>

			<div class="alert alert-danger" *ngIf="error">
				{{ error }}
			</div>

			<form [formGroup]="usuarioForm" (ngSubmit)="onSubmit()" class="usuario-form">
				<div class="form-row">
					<div class="form-group">
						<label for="nickname">Nickname *</label>
						<input 
							type="text" 
							id="nickname" 
							formControlName="nickname" 
							class="form-control"
							[class.is-invalid]="submitted && f['nickname'].errors"
						>
						<div class="invalid-feedback" *ngIf="submitted && f['nickname'].errors">
							<span *ngIf="f['nickname'].errors['required']">Nickname es requerido</span>
						</div>
					</div>

					<div class="form-group">
						<label for="ci">CI *</label>
						<input 
							type="text" 
							id="ci" 
							formControlName="ci" 
							class="form-control"
							[class.is-invalid]="submitted && f['ci'].errors"
						>
						<div class="invalid-feedback" *ngIf="submitted && f['ci'].errors">
							<span *ngIf="f['ci'].errors['required']">CI es requerido</span>
						</div>
					</div>
				</div>

				<div class="form-row">
					<div class="form-group">
						<label for="nombre">Nombre *</label>
						<input 
							type="text" 
							id="nombre" 
							formControlName="nombre" 
							class="form-control"
							[class.is-invalid]="submitted && f['nombre'].errors"
						>
						<div class="invalid-feedback" *ngIf="submitted && f['nombre'].errors">
							<span *ngIf="f['nombre'].errors['required']">Nombre es requerido</span>
						</div>
					</div>

					<div class="form-group">
						<label for="ap_paterno">Apellido Paterno *</label>
						<input 
							type="text" 
							id="ap_paterno" 
							formControlName="ap_paterno" 
							class="form-control"
							[class.is-invalid]="submitted && f['ap_paterno'].errors"
						>
						<div class="invalid-feedback" *ngIf="submitted && f['ap_paterno'].errors">
							<span *ngIf="f['ap_paterno'].errors['required']">Apellido Paterno es requerido</span>
						</div>
					</div>
				</div>

				<div class="form-row">
					<div class="form-group">
						<label for="ap_materno">Apellido Materno</label>
						<input 
							type="text" 
							id="ap_materno" 
							formControlName="ap_materno" 
							class="form-control"
						>
					</div>

					<div class="form-group">
						<label for="id_rol">Rol *</label>
						<select 
							id="id_rol" 
							formControlName="id_rol" 
							class="form-control"
							[class.is-invalid]="submitted && f['id_rol'].errors"
						>
							<option [ngValue]="null" disabled>Seleccione un rol</option>
							<option *ngFor="let rol of roles" [ngValue]="rol.id_rol">
								{{ rol.nombre }}
							</option>
						</select>
						<div class="invalid-feedback" *ngIf="submitted && f['id_rol'].errors">
							<span *ngIf="f['id_rol'].errors['required']">Rol es requerido</span>
						</div>
					</div>
				</div>

				<div class="form-row" *ngIf="!isEditMode">
					<div class="form-group">
						<label for="password">Contraseña *</label>
						<div class="password-input-wrapper">
							<input 
								[type]="showPassword ? 'text' : 'password'" 
								id="password" 
								formControlName="password" 
								class="form-control"
								[class.is-invalid]="submitted && f['password'].errors"
							>
							<button 
								type="button" 
								class="password-toggle-btn" 
								(click)="togglePasswordVisibility()"
							>
								<i class="fas" [ngClass]="showPassword ? 'fa-eye-slash' : 'fa-eye'"></i>
							</button>
						</div>
						<div class="invalid-feedback" *ngIf="submitted && f['password'].errors">
							<span *ngIf="f['password'].errors['required']">Contraseña es requerida</span>
							<span *ngIf="f['password'].errors['minlength']">Contraseña debe tener al menos 6 caracteres</span>
						</div>
					</div>

					<div class="form-group">
						<label for="password_confirmation">Confirmar Contraseña *</label>
						<div class="password-input-wrapper">
							<input 
								[type]="showConfirmPassword ? 'text' : 'password'" 
								id="password_confirmation" 
								formControlName="password_confirmation" 
								class="form-control"
								[class.is-invalid]="submitted && f['password_confirmation'].errors"
							>
							<button 
								type="button" 
								class="password-toggle-btn" 
								(click)="toggleConfirmPasswordVisibility()"
							>
								<i class="fas" [ngClass]="showConfirmPassword ? 'fa-eye-slash' : 'fa-eye'"></i>
							</button>
						</div>
						<div class="invalid-feedback" *ngIf="submitted && f['password_confirmation'].errors">
							<span *ngIf="f['password_confirmation'].errors['required']">Confirmar contraseña es requerido</span>
							<span *ngIf="f['password_confirmation'].errors['matching']">Las contraseñas no coinciden</span>
						</div>
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
								{{ usuarioForm.get('estado')?.value ? 'Activo' : 'Inactivo' }}
							</span>
						</div>
					</div>
				</div>

				<div class="form-actions">
					<button type="button" class="btn-secondary" routerLink="/usuarios">Cancelar</button>
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
		.usuario-form-container {
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

		.usuario-form {
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

		.password-input-wrapper {
			position: relative;
		}

		.password-toggle-btn {
			position: absolute;
			right: 0.75rem;
			top: 50%;
			transform: translateY(-50%);
			background: none;
			border: none;
			cursor: pointer;
			color: #6c757d;
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
			
			.usuario-form {
				padding: 1rem;
			}
		}
	`
})
export class UsuarioFormComponent implements OnInit {
	usuarioForm: FormGroup;
	roles: Rol[] = [];
	isEditMode = false;
	submitted = false;
	isSubmitting = false;
	error = '';
	usuarioId: number | null = null;
	showPassword = false;
	showConfirmPassword = false;

	constructor(
		private formBuilder: FormBuilder,
		private usuarioService: UsuarioService,
		private rolService: RolService,
		private router: Router,
		private route: ActivatedRoute
	) {
		this.usuarioForm = this.formBuilder.group({
			nickname: ['', Validators.required],
			nombre: ['', Validators.required],
			ap_paterno: ['', Validators.required],
			ap_materno: [''],
			ci: ['', Validators.required],
			id_rol: [null, Validators.required],
			estado: [true],
			password: ['', [Validators.required, Validators.minLength(6)]],
			password_confirmation: ['', [Validators.required]]
		}, {
			validators: this.passwordMatchValidator
		});
	}

	ngOnInit(): void {
		this.loadRoles();
		
		// Verificar si estamos en modo edición
		const id = this.route.snapshot.paramMap.get('id');
		if (id) {
			this.isEditMode = true;
			this.usuarioId = +id;
			this.loadUsuario(this.usuarioId);
			
			// No requerir contraseña en modo edición
			this.usuarioForm.get('password')?.clearValidators();
			this.usuarioForm.get('password_confirmation')?.clearValidators();
			this.usuarioForm.get('password')?.updateValueAndValidity();
			this.usuarioForm.get('password_confirmation')?.updateValueAndValidity();
		}
	}

	loadRoles(): void {
		this.rolService.getActiveRoles().subscribe({
			next: (response: {success: boolean; data: Rol[]}) => {
				if (response.success && response.data) {
					this.roles = response.data;
				}
			},
			error: (error: any) => {
				console.error('Error al cargar roles:', error);
				this.error = 'No se pudieron cargar los roles. Intente nuevamente más tarde.';
			}
		});
	}

	loadUsuario(id: number): void {
		this.usuarioService.getById(id).subscribe({
			next: (response) => {
				if (response.success && response.data) {
					const usuario = response.data;
					this.usuarioForm.patchValue({
						nickname: usuario.nickname,
						nombre: usuario.nombre,
						ap_paterno: usuario.ap_paterno,
						ap_materno: usuario.ap_materno,
						ci: usuario.ci,
						id_rol: usuario.id_rol,
						estado: usuario.estado
					});
				}
			},
			error: (error) => {
				console.error('Error al cargar usuario:', error);
				this.error = 'No se pudo cargar la información del usuario. Intente nuevamente más tarde.';
			}
		});
	}

	get f() { 
		return this.usuarioForm.controls; 
	}

	passwordMatchValidator(formGroup: FormGroup) {
		const password = formGroup.get('password')?.value;
		const passwordConfirmation = formGroup.get('password_confirmation')?.value;
		
		if (password === passwordConfirmation) {
			return null;
		}
		
		return { matching: true };
	}

	togglePasswordVisibility(): void {
		this.showPassword = !this.showPassword;
	}

	toggleConfirmPasswordVisibility(): void {
		this.showConfirmPassword = !this.showConfirmPassword;
	}

	onSubmit(): void {
		this.submitted = true;

		if (this.usuarioForm.invalid) {
			return;
		}

		this.isSubmitting = true;
		const formData = this.usuarioForm.value;

		if (this.isEditMode && this.usuarioId) {
			// En modo edición, actualizar usuario existente
			this.usuarioService.update(this.usuarioId, formData).subscribe({
				next: (response) => {
					if (response.success) {
						this.router.navigate(['/usuarios']);
					} else {
						this.error = response.message || 'Error al actualizar usuario';
					}
					this.isSubmitting = false;
				},
				error: (error) => {
					console.error('Error al actualizar usuario:', error);
					this.error = error.error?.message || 'Error al actualizar usuario. Intente nuevamente más tarde.';
					this.isSubmitting = false;
				}
			});
		} else {
			// En modo creación, crear nuevo usuario
			this.usuarioService.create(formData).subscribe({
				next: (response) => {
					if (response.success) {
						this.router.navigate(['/usuarios']);
					} else {
						this.error = response.message || 'Error al crear usuario';
					}
					this.isSubmitting = false;
				},
				error: (error) => {
					console.error('Error al crear usuario:', error);
					this.error = error.error?.message || 'Error al crear usuario. Intente nuevamente más tarde.';
					this.isSubmitting = false;
				}
			});
		}
	}
}
