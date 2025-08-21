import { Component } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router } from '@angular/router';
// Importamos ambos servicios
import { AuthService } from '../../../services/auth.service';
import { AuthMockService } from '../../../services/auth-mock.service';

// Variable de entorno para determinar si usamos mock
const USE_MOCK_AUTH = false; // Cambiar a false para usar el servicio real

@Component({
	selector: 'app-login',
	standalone: true,
	imports: [CommonModule, ReactiveFormsModule],
	template: `
		<div class="min-vh-100 d-flex align-items-center justify-content-center bg-primary">
			<div class="card shadow-lg" style="max-width: 400px; width: 100%;">
				<div class="card-body p-4">
					<!-- Header -->
					<div class="text-center mb-4">
						<img src="assets/images/logo-ceta.png" alt="Logo CETA" class="mb-3" style="height: 80px;">
						<h2 class="h4 text-dark mb-1">Sistema de Cobros</h2>
						<p class="text-muted small">Instituto Tecnológico CETA</p>
					</div>

					<!-- Login Form -->
					<form [formGroup]="loginForm" (ngSubmit)="onSubmit()">
						<!-- Errores -->
						<div *ngIf="errorMessage" class="alert alert-danger d-flex align-items-center" role="alert">
							<i class="fas fa-exclamation-triangle me-2"></i>
							<span>{{ errorMessage }}</span>
						</div>

						<!-- Usuario -->
						<div class="mb-3">
							<label for="nickname" class="form-label">Usuario</label>
							<div class="input-group">
								<span class="input-group-text">
									<i class="fas fa-user"></i>
								</span>
								<input 
									id="nickname" 
									formControlName="nickname" 
									type="text" 
									class="form-control"
									[class.is-invalid]="loginForm.get('nickname')?.invalid && loginForm.get('nickname')?.touched"
									placeholder="Ingrese su usuario o CI"
								>
							</div>
							<div *ngIf="loginForm.get('nickname')?.invalid && loginForm.get('nickname')?.touched" class="invalid-feedback d-block">
								Usuario requerido
							</div>
						</div>

						<!-- Contraseña -->
						<div class="mb-3">
							<label for="contrasenia" class="form-label">Contraseña</label>
							<div class="input-group">
								<span class="input-group-text">
									<i class="fas fa-lock"></i>
								</span>
								<input 
									id="contrasenia" 
									formControlName="contrasenia" 
									[type]="showPassword ? 'text' : 'password'" 
									class="form-control"
									[class.is-invalid]="loginForm.get('contrasenia')?.invalid && loginForm.get('contrasenia')?.touched"
									placeholder="••••••••"
								>
								<button 
									type="button" 
									class="btn btn-outline-secondary"
									(click)="togglePasswordVisibility()"
								>
									<i class="fas" [ngClass]="showPassword ? 'fa-eye-slash' : 'fa-eye'"></i>
								</button>
							</div>
							<div *ngIf="loginForm.get('contrasenia')?.invalid && loginForm.get('contrasenia')?.touched" class="invalid-feedback d-block">
								Contraseña requerida
							</div>
						</div>

						<!-- Botón de Iniciar Sesión -->
						<div class="d-grid">
							<button type="submit" [disabled]="loginForm.invalid || isLoading" class="btn btn-primary btn-lg">
								<span class="d-flex align-items-center justify-content-center">
									<i class="fas fa-sign-in-alt me-2" *ngIf="!isLoading"></i>
									<span class="spinner-border spinner-border-sm me-2" *ngIf="isLoading"></span>
									<span *ngIf="isLoading">Cargando...</span>
									<span *ngIf="!isLoading">Iniciar Sesión</span>
								</span>
							</button>
						</div>
					</form>

					<!-- Footer -->
					<div class="text-center mt-4">
						<small class="text-muted">
							© {{ currentYear }} - Laravel + Angular Integration
						</small>
					</div>
				</div>
			</div>
		</div>
	`,
	styles: []

})
export class LoginComponent {
	loginForm: FormGroup;
	isLoading = false;
	errorMessage = '';
	showPassword = false;
	currentYear = new Date().getFullYear();

	constructor(
		private fb: FormBuilder,
		private authService: AuthService,
		private authMockService: AuthMockService,
		private router: Router
	) {
		this.loginForm = this.fb.group({
			nickname: ['', [Validators.required]],
			contrasenia: ['', [Validators.required]]
		});
	}

	togglePasswordVisibility(): void {
		this.showPassword = !this.showPassword;
	}

	onSubmit(): void {
		if (this.loginForm.invalid) {
			return;
		}

		this.isLoading = true;
		this.errorMessage = '';

		// Usar el servicio mock o el servicio real según la configuración
		const authServiceToUse = USE_MOCK_AUTH ? this.authMockService : this.authService;
		console.log('Usando servicio:', USE_MOCK_AUTH ? 'Mock Auth Service' : 'Auth Service real');

		authServiceToUse.login(this.loginForm.value).subscribe({
			next: (response) => {
				this.isLoading = false;
				if (response.success) {
					this.router.navigate(['/dashboard']);
				} else {
					this.errorMessage = response.message || 'Error de autenticación';
				}
			},
			error: (error) => {
				this.isLoading = false;
				this.errorMessage = error.error?.message || 'Error al conectar con el servidor';
				console.error('Error de login:', error);
			}
		});
	}
}
