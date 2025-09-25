import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { Materia } from '../models/materia.model';
import { environment } from '../../environments/environment';

@Injectable({
	providedIn: 'root'
})
export class MateriaService {
	private apiUrl = `${environment.apiUrl}/materias`;

	constructor(private http: HttpClient) {}

	// Obtener todas las materias
	getAll(): Observable<{ success: boolean; data: Materia[] }> {
		return this.http.get<{ success: boolean; data: Materia[] }>(this.apiUrl);
	}

	// Obtener una materia por clave compuesta (sigla, pensum)
	getOne(sigla: string, pensum: string): Observable<{ success: boolean; data: Materia }> {
		return this.http.get<{ success: boolean; data: Materia }>(`${this.apiUrl}/${sigla}/${pensum}`);
	}

	// Crear una nueva materia
	create(materia: Materia): Observable<{ success: boolean; data: Materia; message: string }> {
		return this.http.post<{ success: boolean; data: Materia; message: string }>(this.apiUrl, materia);
	}

	// Actualizar una materia
	update(sigla: string, pensum: string, materia: Materia): Observable<{ success: boolean; data: Materia; message: string }> {
		return this.http.put<{ success: boolean; data: Materia; message: string }>(`${this.apiUrl}/${sigla}/${pensum}`, materia);
	}

	// Eliminar una materia
	delete(sigla: string, pensum: string): Observable<{ success: boolean; message: string }> {
		return this.http.delete<{ success: boolean; message: string }>(`${this.apiUrl}/${sigla}/${pensum}`);
	}

	// Buscar materias por nombre o sigla
	search(term: string): Observable<{ success: boolean; data: Materia[] }> {
		return this.http.get<{ success: boolean; data: Materia[] }>(`${this.apiUrl}/search`, {
			params: { term }
		});
	}

	// Obtener materias por pensum
	getByPensum(codPensum: string): Observable<{ success: boolean; data: Materia[] }> {
		return this.http.get<{ success: boolean; data: Materia[] }>(`${this.apiUrl}/pensum/${codPensum}`);
	}

	// Cambiar el estado de una materia
	toggleStatus(sigla: string, pensum: string): Observable<{ success: boolean; data: Materia; message: string }> {
		return this.http.put<{ success: boolean; data: Materia; message: string }>(`${this.apiUrl}/${sigla}/${pensum}/toggle-status`, {});
	}

	// Actualizar créditos en lote
	batchUpdateCredits(items: Array<{ sigla_materia: string; cod_pensum: string; nro_creditos: number }>): Observable<{ success: boolean; data: { updated: number; not_found: number } }> {
		return this.http.post<{ success: boolean; data: { updated: number; not_found: number } }>(`${this.apiUrl}/credits/batch`, { items });
	}
}
