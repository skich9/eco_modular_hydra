import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router, ActivatedRoute } from '@angular/router';
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
		<div class="login-container">
			<div class="login-card">
				<!-- Header -->
				<div class="login-header">
					<div class="login-logo">
						<img src="assets/images/logo-ceta.png" alt="Logo CETA" class="login-logo-image">
					</div>
					<h2 class="login-title">Sistema de Cobros</h2>
					<p class="login-subtitle">Instituto Tecnológico CETA</p>
				</div>

				<!-- Login Form -->
				<form [formGroup]="loginForm" (ngSubmit)="onSubmit()" class="login-form">
					<!-- Mensaje de sesión expirada -->
					<div *ngIf="sessionExpiredMessage" class="alert alert-warning d-flex align-items-center" role="alert">
						<i class="fas fa-clock me-2"></i>
						<span>{{ sessionExpiredMessage }}</span>
					</div>

					<!-- Errores -->
					<div *ngIf="errorMessage" class="alert alert-danger d-flex align-items-center" role="alert">
						<i class="fas fa-exclamation-triangle me-2"></i>
						<span>{{ errorMessage }}</span>
					</div>

					<!-- Usuario -->
					<div class="login-form-group">
						<label for="nickname" class="login-label">Usuario</label>
						<div class="position-relative">
							<input
								id="nickname"
								formControlName="nickname"
								type="text"
								class="login-input"
								[class.is-invalid]="loginForm.get('nickname')?.invalid && loginForm.get('nickname')?.touched"
								placeholder="Ingrese su usuario"

							>
							<div class="position-absolute top-50 end-0 translate-middle-y pe-3">
								<i class="fas fa-user text-muted"></i>
							</div>
						</div>
						<div *ngIf="loginForm.get('nickname')?.invalid && loginForm.get('nickname')?.touched" class="text-danger small mt-1">
							Usuario requerido
						</div>
					</div>

					<!-- Contraseña -->
					<div class="login-form-group">
						<label for="contrasenia" class="login-label">Contraseña</label>
						<div class="login-password-container">
							<input
								id="contrasenia"
								formControlName="contrasenia"
								[type]="showPassword ? 'text' : 'password'"
								class="login-input"
								[class.is-invalid]="loginForm.get('contrasenia')?.invalid && loginForm.get('contrasenia')?.touched"
								placeholder="••••••••"
							>
							<button
								type="button"
								class="login-password-toggle btn btn-link p-0"
								(click)="togglePasswordVisibility()"
							>
								<i class="fas" [ngClass]="showPassword ? 'fa-eye-slash' : 'fa-eye'"></i>
							</button>
						</div>
						<div *ngIf="loginForm.get('contrasenia')?.invalid && loginForm.get('contrasenia')?.touched" class="text-danger small mt-1">
							Contraseña requerida
						</div>
					</div>

					<!-- Botón de Iniciar Sesión -->
					<div class="d-grid">
						<button type="submit" [disabled]="loginForm.invalid || isLoading" class="login-button btn btn-primary btn-lg">
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
				<div class="login-footer text-center">
					<small>© {{ currentYear }} Instituto Tecnológico CETA. Todos los derechos reservados.</small>
				</div>
			</div>
		</div>
	`,
	styles: []

})
export class LoginComponent implements OnInit {
	loginForm: FormGroup;
	isLoading = false;
	errorMessage = '';
	sessionExpiredMessage = '';
	showPassword = false;
	currentYear = new Date().getFullYear();
	private targetUrlAfterLogin = '/dashboard';

	constructor(
		private fb: FormBuilder,
		private authService: AuthService,
		private authMockService: AuthMockService,
		private router: Router,
		private route: ActivatedRoute
	) {
		this.loginForm = this.fb.group({
			nickname: ['', [Validators.required]],
			contrasenia: ['', [Validators.required]]
		});
	}

	ngOnInit(): void {
		// Verificar si viene de una sesión expirada
		this.route.queryParams.subscribe(params => {
			if (params['sessionExpired'] === 'true') {
				this.sessionExpiredMessage = 'Tu sesión ha expirado. Por favor, inicia sesión nuevamente.';
				// Limpiar el mensaje después de 10 segundos
				setTimeout(() => {
					this.sessionExpiredMessage = '';
				}, 10000);
			}

			const returnUrl = (params['returnUrl'] || '').toString();
			this.targetUrlAfterLogin = returnUrl || '/dashboard';

			const directSsoToken = (params['sso_token'] || '').toString().trim();
			const directCodCeta = (params['cod_ceta'] || '').toString().trim();
			const directGestion = (params['gestion'] || '').toString().trim();

			const returnUrlData = this.parseReturnUrl(returnUrl);
			const ssoToken = directSsoToken || returnUrlData.ssoToken;
			const codCeta = directCodCeta || returnUrlData.codCeta;
			const gestion = directGestion || returnUrlData.gestion;

			console.info('[Login][SSO] Query recibido', {
				hasSsoToken: !!ssoToken,
				ssoTokenLength: ssoToken ? ssoToken.length : 0,
				codCeta: codCeta || null,
				gestion: gestion || null,
				returnUrl: returnUrl || null,
				returnBasePath: returnUrlData.basePath || null
			});

			if (ssoToken) {
				this.autoLoginWithSsoToken(ssoToken, codCeta, gestion, returnUrlData.basePath);
			}
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
					this.router.navigateByUrl(this.targetUrlAfterLogin || '/dashboard');
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

	private autoLoginWithSsoToken(ssoToken: string, codCeta: string, gestion: string, returnBasePath: string): void {
		if (this.isLoading) {
			return;
		}

		this.isLoading = true;
		this.errorMessage = '';
		console.info('[Login][SSO] Iniciando validacion de token', {
			ssoTokenLength: ssoToken ? ssoToken.length : 0,
			codCeta: codCeta || null,
			gestion: gestion || null,
			returnBasePath: returnBasePath || '/cobros'
		});

		// Evitar conflicto con cualquier token previo durante validación SSO.
		this.authService.clearSession();

		this.authService.loginWithSsoToken(ssoToken).subscribe({
			next: (response) => {
				this.isLoading = false;
				if (!response?.success) {
					console.warn('[Login][SSO] Token rechazado por backend', {
						hasResponse: !!response,
						message: response?.message || null
					});
					this.errorMessage = response?.message || 'Token SSO inválido o expirado';
					return;
				}

				const targetBase = returnBasePath || '/cobros';
				const queryParams: any = {};
				if (codCeta) queryParams.cod_ceta = codCeta;
				if (gestion) queryParams.gestion = gestion;

				this.router.navigate([targetBase], {
					queryParams,
					replaceUrl: true
				});
				console.info('[Login][SSO] Login OK, redirigiendo', {
					targetBase,
					codCeta: codCeta || null,
					gestion: gestion || null
				});
			},
			error: (error) => {
				this.isLoading = false;
				console.error('[Login][SSO] Error en validacion de token', {
					status: error?.status,
					message: error?.error?.message || error?.message || null
				});
				this.errorMessage = error?.error?.message || 'No se pudo validar el token SSO';
			}
		});
	}

	private parseReturnUrl(returnUrl: string): { ssoToken: string; codCeta: string; gestion: string; basePath: string } {
		const fallback = { ssoToken: '', codCeta: '', gestion: '', basePath: '' };
		if (!returnUrl) {
			return fallback;
		}

		try {
			const tree = this.router.parseUrl(returnUrl);
			const primary = tree.root.children['primary'];
			const basePath = primary ? `/${primary.segments.map(s => s.path).join('/')}` : '';

			return {
				ssoToken: (tree.queryParams['sso_token'] || '').toString().trim(),
				codCeta: (tree.queryParams['cod_ceta'] || '').toString().trim(),
				gestion: (tree.queryParams['gestion'] || '').toString().trim(),
				basePath
			};
		} catch {
			return fallback;
		}
	}
}
