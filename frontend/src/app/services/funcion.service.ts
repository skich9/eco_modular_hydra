import { Injectable, Inject, PLATFORM_ID } from '@angular/core';
import { isPlatformBrowser } from '@angular/common';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../environments/environment';
import {
	Funcion,
	UsuarioFuncion,
	AsignarFuncionRequest,
	CopiarFuncionesRolRequest,
	FuncionesAgrupadasPorModulo,
	ApiResponse
} from '../models/funcion.model';

@Injectable({
	providedIn: 'root'
})
export class FuncionService {
	private apiUrl: string;

	constructor(
		private http: HttpClient,
		@Inject(PLATFORM_ID) private platformId: Object
	) {
		if (isPlatformBrowser(this.platformId)) {
			const protocol = typeof window !== 'undefined' && window.location ? (window.location.protocol || 'http:') : 'http:';
			const host = typeof window !== 'undefined' && window.location ? (window.location.hostname || 'localhost') : 'localhost';
			const port = environment.apiPort || '8069';
			this.apiUrl = `${protocol}//${host}:${port}/api`;
		} else {
			this.apiUrl = environment.apiUrl;
		}
	}

	getFunciones(activo?: boolean, modulo?: string): Observable<ApiResponse<Funcion[]>> {
		let params: any = {};
		if (activo !== undefined) params.activo = activo;
		if (modulo) params.modulo = modulo;

		return this.http.get<ApiResponse<Funcion[]>>(`${this.apiUrl}/funciones`, { params });
	}

	getFuncionesByModule(): Observable<ApiResponse<FuncionesAgrupadasPorModulo>> {
		return this.http.get<ApiResponse<FuncionesAgrupadasPorModulo>>(`${this.apiUrl}/funciones/by-module`);
	}

	getFuncion(id: number): Observable<ApiResponse<Funcion>> {
		return this.http.get<ApiResponse<Funcion>>(`${this.apiUrl}/funciones/${id}`);
	}

	createFuncion(funcion: Partial<Funcion>): Observable<ApiResponse<Funcion>> {
		return this.http.post<ApiResponse<Funcion>>(`${this.apiUrl}/funciones`, funcion);
	}

	updateFuncion(id: number, funcion: Partial<Funcion>): Observable<ApiResponse<Funcion>> {
		return this.http.put<ApiResponse<Funcion>>(`${this.apiUrl}/funciones/${id}`, funcion);
	}

	deleteFuncion(id: number): Observable<ApiResponse<void>> {
		return this.http.delete<ApiResponse<void>>(`${this.apiUrl}/funciones/${id}`);
	}

	getUsuarioFunciones(usuarioId: number): Observable<ApiResponse<UsuarioFuncion[]>> {
		return this.http.get<ApiResponse<UsuarioFuncion[]>>(`${this.apiUrl}/usuarios/${usuarioId}/funciones`);
	}

	getUsuarioFuncionesByModule(usuarioId: number): Observable<ApiResponse<any>> {
		return this.http.get<ApiResponse<any>>(`${this.apiUrl}/usuarios/${usuarioId}/funciones/by-module`);
	}

	asignarFuncion(usuarioId: number, request: AsignarFuncionRequest): Observable<ApiResponse<void>> {
		return this.http.post<ApiResponse<void>>(`${this.apiUrl}/usuarios/${usuarioId}/funciones`, request);
	}

	actualizarFuncion(usuarioId: number, funcionId: number, request: Partial<AsignarFuncionRequest>): Observable<ApiResponse<void>> {
		return this.http.put<ApiResponse<void>>(`${this.apiUrl}/usuarios/${usuarioId}/funciones/${funcionId}`, request);
	}

	quitarFuncion(usuarioId: number, funcionId: number): Observable<ApiResponse<void>> {
		return this.http.delete<ApiResponse<void>>(`${this.apiUrl}/usuarios/${usuarioId}/funciones/${funcionId}`);
	}

	copiarFuncionesDeRol(usuarioId: number, request: CopiarFuncionesRolRequest): Observable<ApiResponse<void>> {
		return this.http.post<ApiResponse<void>>(`${this.apiUrl}/usuarios/${usuarioId}/funciones/copy-from-role`, request);
	}

	verificarPermiso(usuarioId: number, codigo: string): Observable<ApiResponse<{ has_permission: boolean }>> {
		return this.http.post<ApiResponse<{ has_permission: boolean }>>(
			`${this.apiUrl}/usuarios/${usuarioId}/funciones/check-permission`,
			{ codigo }
		);
	}
}
