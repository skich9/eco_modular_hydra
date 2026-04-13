import { Injectable, Inject, PLATFORM_ID } from '@angular/core';
import { isPlatformBrowser } from '@angular/common';
import { HttpClient, HttpHeaders } from '@angular/common/http';
import { Router } from '@angular/router';
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

	constructor(
		private http: HttpClient,
		private router: Router,
		@Inject(PLATFORM_ID) private platformId: Object
	) {
		// Cargar usuario del localStorage al iniciar
		this.loadUserFromStorage();
		if (isPlatformBrowser(this.platformId)) {
			// const protocol = typeof window !== 'undefined' && window.location ? (window.location.protocol || 'http:') : 'http:';
			// const host = typeof window !== 'undefined' && window.location ? (window.location.hostname || 'localhost') : 'localhost';
			// const port = environment.apiPort || '8069';
			// this.apiUrl = `${protocol}//${host}:${port}/api`;
      this.apiUrl = environment.apiUrl;
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
				if (this.isTokenExpired()) {
					console.warn('[AuthService] Token expirado al cargar. Limpiando sesión.');
					this.clearSession();
				} else {
					this.currentUserSubject.next(JSON.parse(storedUser));
				}
			}
		}
	}

	login(credentials: LoginRequest): Observable<AuthResponse> {
		return this.http.post<AuthResponse>(`${this.apiUrl}/login`, credentials)
			.pipe(
				tap((response: AuthResponse) => {
					if (response.success && response.token && response.usuario) {
						if (typeof localStorage !== 'undefined') {
							localStorage.setItem(this.tokenKey, response.token);
							if (response.expires_at) {
								localStorage.setItem(this.expiresAtKey, response.expires_at);
							}
							localStorage.setItem(this.userKey, JSON.stringify(response.usuario));
						}
						this.currentUserSubject.next(response.usuario);
						if (this.expirationCheckInterval) {
							clearInterval(this.expirationCheckInterval);
						}
						this.startExpirationCheck();
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

	refreshToken(): Observable<any> {
		return this.http.post<any>(`${this.apiUrl}/refresh-token`, {})
			.pipe(
				tap((response: any) => {
					if (response.success && response.expires_at) {
						if (typeof localStorage !== 'undefined') {
							localStorage.setItem(this.expiresAtKey, response.expires_at);
						}
					}
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
			this.expirationCheckInterval = null;
		}
		// NO reiniciar el intervalo aquí, solo después del login
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

	/**
	 * Verifica si el token necesita ser refrescado (le quedan menos de 5 minutos)
	 */
	shouldRefreshToken(): boolean {
		if (typeof localStorage === 'undefined') return false;

		const expiresAt = localStorage.getItem(this.expiresAtKey);
		if (!expiresAt) return false;

		const expirationDate = new Date(expiresAt);
		const now = new Date();
		const minutesUntilExpiration = (expirationDate.getTime() - now.getTime()) / 60000;

		// Refrescar si quedan menos de 5 minutos
		return minutesUntilExpiration < 5 && minutesUntilExpiration > 0;
	}

	private isTokenExpired(): boolean {
		if (typeof localStorage === 'undefined') return true;

		const expiresAt = localStorage.getItem(this.expiresAtKey);
		if (!expiresAt) {
			return false;
		}

		const expirationDate = new Date(expiresAt);
		const now = new Date();
		const isExpired = now >= expirationDate;

		if (isExpired) {
			const diffMinutes = Math.floor((now.getTime() - expirationDate.getTime()) / 60000);
			console.warn(`[AuthService] Token expirado hace ${diffMinutes} minutos`);
		}

		return isExpired;
	}

	private startExpirationCheck(): void {
		this.expirationCheckInterval = setInterval(() => {
			const isExpired = this.isTokenExpired();
			const currentUser = this.getCurrentUser();
			const token = this.getToken();

			if (isExpired && currentUser && token) {
				console.warn('[AuthService] ⚠️ Token expirado detectado. Cerrando sesión automáticamente...');
				this.clearSession();

				// Redirigir al login usando Angular Router
				this.router.navigate(['/login'], {
					queryParams: {
						sessionExpired: 'true',
						returnUrl: this.router.url
					}
				});
			}
		}, 10000); // 10 segundos (más frecuente para detectar más rápido)
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
