import { Injectable, Inject, PLATFORM_ID } from '@angular/core';
import { isPlatformBrowser } from '@angular/common';
import { HttpClient, HttpHeaders } from '@angular/common/http';
import { BehaviorSubject, Observable, tap } from 'rxjs';
import { AuthResponse, LoginRequest, Usuario } from '../models/usuario.model';
import { environment } from '../../environments/environment';

@Injectable({
	providedIn: 'root'
})
export class AuthService {
	private apiUrl: string;
	private currentUserSubject = new BehaviorSubject<Usuario | null>(null);
	public currentUser$ = this.currentUserSubject.asObservable();
	private tokenKey = 'auth_token';
	private userKey = 'current_user';
	private expiresAtKey = 'token_expires_at';
	private expirationCheckInterval: any;

	constructor(private http: HttpClient, @Inject(PLATFORM_ID) private platformId: Object) {
		// Cargar usuario del localStorage al iniciar
		this.loadUserFromStorage();
		if (isPlatformBrowser(this.platformId)) {
			const protocol = typeof window !== 'undefined' && window.location ? (window.location.protocol || 'http:') : 'http:';
			const host = typeof window !== 'undefined' && window.location ? (window.location.hostname || 'localhost') : 'localhost';
			const port = environment.apiPort || '8069';
			this.apiUrl = `${protocol}//${host}:${port}/api`;
			// Iniciar verificación periódica de expiración
			this.startExpirationCheck();
		} else {
			this.apiUrl = environment.apiUrl;
		}
	}

	private loadUserFromStorage() {
		if (typeof localStorage !== 'undefined') {
			const storedUser = localStorage.getItem(this.userKey);
			const storedToken = localStorage.getItem(this.tokenKey);

			if (storedUser && storedToken) {
				// Verificar si el token ha expirado
				if (this.isTokenExpired()) {
					this.clearSession();
				} else {
					this.currentUserSubject.next(JSON.parse(storedUser));
				}
			}
		}
	}

	login(credentials: LoginRequest): Observable<AuthResponse> {
		console.log('Enviando petición de login a:', `${this.apiUrl}/login`);
		console.log('Credenciales:', credentials);

		return this.http.post<AuthResponse>(`${this.apiUrl}/login`, credentials)
			.pipe(
				tap((response: AuthResponse) => {
					console.log('Respuesta del servidor:', response);

					if (response.success && response.token && response.usuario) {
						console.log('Login exitoso, guardando datos de sesión');
						// Guardar token, expiración y usuario
						if (typeof localStorage !== 'undefined') {
							localStorage.setItem(this.tokenKey, response.token);
							if (response.expires_at) {
								localStorage.setItem(this.expiresAtKey, response.expires_at);
							}
							localStorage.setItem(this.userKey, JSON.stringify(response.usuario));
						}
						this.currentUserSubject.next(response.usuario);
					} else {
						console.log('Respuesta no válida o login fallido');
					}
				})
			);
	}

	logout(): Observable<any> {
		return this.http.post<any>(`${this.apiUrl}/logout`, {})
			.pipe(
				tap(() => {
					// Limpiar datos de sesión
					this.clearSession();
				})
			);
	}

	clearSession(): void {
		if (typeof localStorage !== 'undefined') {
			localStorage.removeItem(this.tokenKey);
			localStorage.removeItem(this.userKey);
			localStorage.removeItem(this.expiresAtKey);
		}
		this.currentUserSubject.next(null);
		if (this.expirationCheckInterval) {
			clearInterval(this.expirationCheckInterval);
		}
	}

	getToken(): string | null {
		if (typeof localStorage !== 'undefined') {
			return localStorage.getItem(this.tokenKey);
		}
		return null;
	}

	isAuthenticated(): boolean {
		const hasToken = !!this.getToken();
		if (!hasToken) return false;

		// Verificar si el token ha expirado
		if (this.isTokenExpired()) {
			this.clearSession();
			return false;
		}

		return true;
	}

	getCurrentUser(): Usuario | null {
		return this.currentUserSubject.getValue();
	}

	hasRole(roleName: string): boolean {
		const currentUser = this.getCurrentUser();
		if (!currentUser || !currentUser.rol) return false;
		return currentUser.rol.nombre === roleName;
	}

	hasFunction(functionName: string): boolean {
		// Implementar cuando tengamos el modelo de funciones
		return false;
	}

	private isTokenExpired(): boolean {
		if (typeof localStorage === 'undefined') return true;

		const expiresAt = localStorage.getItem(this.expiresAtKey);
		if (!expiresAt) return false; // Si no hay fecha de expiración, asumir que no expira

		const expirationDate = new Date(expiresAt);
		const now = new Date();

		return now >= expirationDate;
	}

	private startExpirationCheck(): void {
		// Verificar cada 30 segundos si el token ha expirado
		this.expirationCheckInterval = setInterval(() => {
			if (this.isTokenExpired() && this.getCurrentUser()) {
				console.log('Token expirado, cerrando sesión automáticamente');
				this.clearSession();
				// Redirigir al login si es necesario
				if (typeof window !== 'undefined') {
					window.location.href = '/login';
				}
			}
		}, 30000); // 30 segundos
	}

	// Método para cambiar la contraseña
	changePassword(contraseniaActual: string, contraseniaNueva: string, contraseniaNuevaConfirm: string): Observable<any> {
		const token = this.getToken();
		const options = token ? { headers: new HttpHeaders({ Authorization: `Bearer ${token}` }) } : {};

		return this.http.post<any>(`${this.apiUrl}/change-password`, {
			contrasenia_actual: contraseniaActual,
			contrasenia_nueva: contraseniaNueva,
			contrasenia_nueva_confirmation: contraseniaNuevaConfirm
		}, options);
	}
}
