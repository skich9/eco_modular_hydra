import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { map } from 'rxjs/operators';
import { ParametroEconomico } from '../models/parametro-economico.model';
import { environment } from '../../environments/environment';

@Injectable({
	providedIn: 'root'
})
export class ParametrosEconomicosService {
	private apiUrl = `${environment.apiUrl}/parametros-economicos`;

	constructor(private http: HttpClient) {}

	// Obtener todos los parámetros económicos
	getAll(): Observable<{ success: boolean; data: ParametroEconomico[] }> {
		return this.http.get<any>(this.apiUrl).pipe(
			map((res: any) => {
				if (Array.isArray(res)) {
					return { success: true, data: res as ParametroEconomico[] };
				}
				if (res && Array.isArray(res.data)) {
					return { success: typeof res.success === 'boolean' ? res.success : true, data: res.data as ParametroEconomico[] };
				}
				return { success: false, data: [] as ParametroEconomico[] };
			})
		);
	}

	// Obtener un parámetro económico por ID
	getById(id: number, nombre?: string): Observable<{ success: boolean; data: ParametroEconomico }> {
		const qs = nombre ? `?nombre=${encodeURIComponent(nombre)}` : '';
		return this.http.get<{ success: boolean; data: ParametroEconomico }>(`${this.apiUrl}/${id}${qs}`);
	}

	// Crear un nuevo parámetro económico
	create(parametro: ParametroEconomico): Observable<{ success: boolean; data: ParametroEconomico; message: string }> {
		return this.http.post<{ success: boolean; data: ParametroEconomico; message: string }>(this.apiUrl, parametro);
	}

	// Actualizar un parámetro económico
	update(id: number, parametro: ParametroEconomico, nombre?: string): Observable<{ success: boolean; data: ParametroEconomico; message: string }> {
		const qs = nombre ? `?nombre=${encodeURIComponent(nombre)}` : '';
		return this.http.put<{ success: boolean; data: ParametroEconomico; message: string }>(`${this.apiUrl}/${id}${qs}`, parametro);
	}

	// Eliminar un parámetro económico
	delete(id: number, nombre?: string): Observable<{ success: boolean; message: string }> {
		const qs = nombre ? `?nombre=${encodeURIComponent(nombre)}` : '';
		return this.http.delete<{ success: boolean; message: string }>(`${this.apiUrl}/${id}${qs}`);
	}

	// Cambiar el estado de un parámetro económico
	toggleStatus(id: number, nombre?: string): Observable<{ success: boolean; data: ParametroEconomico; message: string }> {
		const qs = nombre ? `?nombre=${encodeURIComponent(nombre)}` : '';
		return this.http.patch<{ success: boolean; data: ParametroEconomico; message: string }>(`${this.apiUrl}/${id}/toggle-status${qs}`, {});
	}
}
