import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { ParametroEconomico } from '../models/materia.model';

@Injectable({
	providedIn: 'root'
})
export class ParametrosEconomicosService {
	private apiUrl = 'http://localhost:8080/api/parametros-economicos';

	constructor(private http: HttpClient) {}

	// Obtener todos los parámetros económicos
	getAll(): Observable<{ success: boolean; data: ParametroEconomico[] }> {
		return this.http.get<{ success: boolean; data: ParametroEconomico[] }>(this.apiUrl);
	}

	// Obtener un parámetro económico por ID
	getById(id: number): Observable<{ success: boolean; data: ParametroEconomico }> {
		return this.http.get<{ success: boolean; data: ParametroEconomico }>(`${this.apiUrl}/${id}`);
	}

	// Crear un nuevo parámetro económico
	create(parametro: ParametroEconomico): Observable<{ success: boolean; data: ParametroEconomico; message: string }> {
		return this.http.post<{ success: boolean; data: ParametroEconomico; message: string }>(this.apiUrl, parametro);
	}

	// Actualizar un parámetro económico
	update(id: number, parametro: ParametroEconomico): Observable<{ success: boolean; data: ParametroEconomico; message: string }> {
		return this.http.put<{ success: boolean; data: ParametroEconomico; message: string }>(`${this.apiUrl}/${id}`, parametro);
	}

	// Eliminar un parámetro económico
	delete(id: number): Observable<{ success: boolean; message: string }> {
		return this.http.delete<{ success: boolean; message: string }>(`${this.apiUrl}/${id}`);
	}

	// Obtener parámetros económicos por tipo
	getByTipo(tipo: string): Observable<{ success: boolean; data: ParametroEconomico[] }> {
		return this.http.get<{ success: boolean; data: ParametroEconomico[] }>(`${this.apiUrl}/tipo/${tipo}`);
	}

	// Cambiar el estado de un parámetro económico
	toggleStatus(id: number): Observable<{ success: boolean; data: ParametroEconomico; message: string }> {
		return this.http.patch<{ success: boolean; data: ParametroEconomico; message: string }>(`${this.apiUrl}/${id}/toggle-status`, {});
	}

	// Obtener parámetros económicos activos
	getActive(): Observable<{ success: boolean; data: ParametroEconomico[] }> {
		return this.http.get<{ success: boolean; data: ParametroEconomico[] }>(`${this.apiUrl}/active`);
	}
}
