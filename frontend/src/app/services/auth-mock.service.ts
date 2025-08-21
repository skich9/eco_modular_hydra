import { Injectable } from '@angular/core';
import { Observable, of } from 'rxjs';
import { AuthResponse, LoginRequest, Usuario } from '../models/usuario.model';

/**
 * Servicio mock para simular autenticación sin backend
 * Solo para pruebas
 */
@Injectable({
	providedIn: 'root'
})
export class AuthMockService {
	private tokenKey = 'auth_mock_token';
	private userKey = 'auth_mock_user';

	constructor() { }

	login(credentials: LoginRequest): Observable<AuthResponse> {
		console.log('Mock Auth Service - Credenciales:', credentials);
		
		// Simulamos un pequeño delay como si fuera una petición real
		return of({
			success: true,
			message: 'Login exitoso (MOCK)',
			token: 'mock_token_12345',
			usuario: {
				id_usuario: 1,
				nickname: credentials.nickname,
				nombre: 'Usuario',
				ap_paterno: 'Demo',
				ap_materno: '',
				ci: '12345678',
				estado: true,
				id_rol: 1,
				nombre_completo: 'Usuario Demo',
				rol: {
					id_rol: 1,
					nombre: 'admin',
					descripcion: 'Administrador del sistema',
					estado: true
				}
			}
		});
	}

	logout(): Observable<any> {
		localStorage.removeItem(this.tokenKey);
		localStorage.removeItem(this.userKey);
		return of({ success: true });
	}

	getToken(): string | null {
		return localStorage.getItem(this.tokenKey);
	}

	isAuthenticated(): boolean {
		return !!this.getToken();
	}
}
