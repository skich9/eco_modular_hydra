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

	constructor(private http: HttpClient, @Inject(PLATFORM_ID) private platformId: Object) {
		// Cargar usuario del localStorage al iniciar
		this.loadUserFromStorage();
		if (isPlatformBrowser(this.platformId)) {
			const protocol = typeof window !== 'undefined' && window.location ? (window.location.protocol || 'http:') : 'http:';
			const host = typeof window !== 'undefined' && window.location ? (window.location.hostname || 'localhost') : 'localhost';
			const port = environment.apiPort || '8069';
			this.apiUrl = `${protocol}//${host}:${port}/api`;
		} else {
			this.apiUrl = environment.apiUrl;
		}
	}

	private loadUserFromStorage() {
		if (typeof localStorage !== 'undefined') {
			const storedUser = localStorage.getItem(this.userKey);
			const storedToken = localStorage.getItem(this.tokenKey);
			
			if (storedUser && storedToken) {
				this.currentUserSubject.next(JSON.parse(storedUser));
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
						// Guardar token y usuario
						if (typeof localStorage !== 'undefined') {
							localStorage.setItem(this.tokenKey, response.token);
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
		}
		this.currentUserSubject.next(null);
	}

	getToken(): string | null {
		if (typeof localStorage !== 'undefined') {
			return localStorage.getItem(this.tokenKey);
		}
		return null;
	}

	isAuthenticated(): boolean {
		return !!this.getToken();
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
