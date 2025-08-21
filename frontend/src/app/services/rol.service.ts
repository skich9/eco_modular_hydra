import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { Rol } from '../models/usuario.model';

@Injectable({
	providedIn: 'root'
})
export class RolService {
	private apiUrl = 'http://localhost:8080/api/roles';

	constructor(private http: HttpClient) {}

	// Obtener todos los roles
	getAll(): Observable<{ success: boolean; data: Rol[] }> {
		return this.http.get<{ success: boolean; data: Rol[] }>(this.apiUrl);
	}

	// Obtener un rol por ID
	getById(id: number): Observable<{ success: boolean; data: Rol }> {
		return this.http.get<{ success: boolean; data: Rol }>(`${this.apiUrl}/${id}`);
	}

	// Crear un nuevo rol
	create(rol: Rol): Observable<{ success: boolean; data: Rol; message: string }> {
		return this.http.post<{ success: boolean; data: Rol; message: string }>(this.apiUrl, rol);
	}

	// Actualizar un rol
	update(id: number, rol: Rol): Observable<{ success: boolean; data: Rol; message: string }> {
		return this.http.put<{ success: boolean; data: Rol; message: string }>(`${this.apiUrl}/${id}`, rol);
	}

	// Eliminar un rol
	delete(id: number): Observable<{ success: boolean; message: string }> {
		return this.http.delete<{ success: boolean; message: string }>(`${this.apiUrl}/${id}`);
	}

	// Cambiar el estado de un rol
	toggleStatus(id: number): Observable<{ success: boolean; data: Rol; message: string }> {
		return this.http.patch<{ success: boolean; data: Rol; message: string }>(`${this.apiUrl}/${id}/toggle-status`, {});
	}

	// Obtener roles activos
	getActive(): Observable<{ success: boolean; data: Rol[] }> {
		return this.http.get<{ success: boolean; data: Rol[] }>(`${this.apiUrl}/active`);
	}

	// Alias para getActive() para mantener compatibilidad con componentes existentes
	getActiveRoles(): Observable<{ success: boolean; data: Rol[] }> {
		return this.getActive();
	}
}
