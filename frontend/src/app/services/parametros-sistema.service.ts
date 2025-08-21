import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { ParametroSistema } from '../models/parametro-sistema.model';

@Injectable({
	providedIn: 'root'
})
export class ParametrosSistemaService {
	private apiUrl = 'http://localhost:8080/api/parametros-sistema';

	constructor(private http: HttpClient) {}

	// Obtener todos los parámetros del sistema
	getAll(): Observable<{ success: boolean; data: ParametroSistema[] }> {
		return this.http.get<{ success: boolean; data: ParametroSistema[] }>(this.apiUrl);
	}

	// Obtener un parámetro del sistema por ID
	getById(id: number): Observable<{ success: boolean; data: ParametroSistema }> {
		return this.http.get<{ success: boolean; data: ParametroSistema }>(`${this.apiUrl}/${id}`);
	}

	// Obtener un parámetro del sistema por nombre
	getByNombre(nombre: string): Observable<{ success: boolean; data: ParametroSistema }> {
		return this.http.get<{ success: boolean; data: ParametroSistema }>(`${this.apiUrl}/nombre/${nombre}`);
	}

	// Actualizar un parámetro del sistema
	update(id: number, parametro: ParametroSistema): Observable<{ success: boolean; data: ParametroSistema; message: string }> {
		return this.http.put<{ success: boolean; data: ParametroSistema; message: string }>(`${this.apiUrl}/${id}`, parametro);
	}

	// Obtener parámetros del sistema activos
	getActive(): Observable<{ success: boolean; data: ParametroSistema[] }> {
		return this.http.get<{ success: boolean; data: ParametroSistema[] }>(`${this.apiUrl}/active`);
	}

	// Crear un nuevo parámetro del sistema (solo administradores)
	create(parametro: ParametroSistema): Observable<{ success: boolean; data: ParametroSistema; message: string }> {
		return this.http.post<{ success: boolean; data: ParametroSistema; message: string }>(this.apiUrl, parametro);
	}

	// Cambiar el estado de un parámetro del sistema
	toggleStatus(id: number): Observable<{ success: boolean; data: ParametroSistema; message: string }> {
		return this.http.patch<{ success: boolean; data: ParametroSistema; message: string }>(`${this.apiUrl}/${id}/toggle-status`, {});
	}
}
