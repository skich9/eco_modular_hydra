import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { Usuario } from '../models/usuario.model';

@Injectable({
	providedIn: 'root'
})
export class UsuarioService {
	private apiUrl = 'http://localhost:8080/api/usuarios';

	constructor(private http: HttpClient) {}

	// Obtener todos los usuarios
	getAll(): Observable<{ success: boolean; data: Usuario[] }> {
		return this.http.get<{ success: boolean; data: Usuario[] }>(this.apiUrl);
	}

	// Obtener un usuario por ID
	getById(id: number): Observable<{ success: boolean; data: Usuario }> {
		return this.http.get<{ success: boolean; data: Usuario }>(`${this.apiUrl}/${id}`);
	}

	// Crear un nuevo usuario
	create(usuario: Usuario): Observable<{ success: boolean; data: Usuario; message: string }> {
		return this.http.post<{ success: boolean; data: Usuario; message: string }>(this.apiUrl, usuario);
	}

	// Actualizar un usuario
	update(id: number, usuario: Usuario): Observable<{ success: boolean; data: Usuario; message: string }> {
		return this.http.put<{ success: boolean; data: Usuario; message: string }>(`${this.apiUrl}/${id}`, usuario);
	}

	// Eliminar un usuario
	delete(id: number): Observable<{ success: boolean; message: string }> {
		return this.http.delete<{ success: boolean; message: string }>(`${this.apiUrl}/${id}`);
	}

	// Buscar usuarios
	search(term: string): Observable<{ success: boolean; data: Usuario[] }> {
		return this.http.get<{ success: boolean; data: Usuario[] }>(`${this.apiUrl}/search`, {
			params: { term }
		});
	}

	// Obtener usuarios por rol
	getByRol(idRol: number): Observable<{ success: boolean; data: Usuario[] }> {
		return this.http.get<{ success: boolean; data: Usuario[] }>(`${this.apiUrl}/rol/${idRol}`);
	}

	// Cambiar el estado de un usuario
	toggleStatus(id: number): Observable<{ success: boolean; data: Usuario; message: string }> {
		return this.http.patch<{ success: boolean; data: Usuario; message: string }>(`${this.apiUrl}/${id}/toggle-status`, {});
	}

	// Cambiar la contraseña de un usuario (para administradores)
	resetPassword(id: number, nuevaContrasenia: string): Observable<{ success: boolean; message: string }> {
		return this.http.post<{ success: boolean; message: string }>(`${this.apiUrl}/${id}/reset-password`, { 
			contrasenia: nuevaContrasenia 
		});
	}
}
