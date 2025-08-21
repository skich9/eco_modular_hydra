import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { Materia } from '../models/materia.model';

@Injectable({
	providedIn: 'root'
})
export class MateriaService {
	private apiUrl = 'http://localhost:8080/api/materias';

	constructor(private http: HttpClient) {}

	// Obtener todas las materias
	getAll(): Observable<{ success: boolean; data: Materia[] }> {
		return this.http.get<{ success: boolean; data: Materia[] }>(this.apiUrl);
	}

	// Obtener una materia por sigla
	getBySignature(sigla: string): Observable<{ success: boolean; data: Materia }> {
		return this.http.get<{ success: boolean; data: Materia }>(`${this.apiUrl}/${sigla}`);
	}

	// Crear una nueva materia
	create(materia: Materia): Observable<{ success: boolean; data: Materia; message: string }> {
		return this.http.post<{ success: boolean; data: Materia; message: string }>(this.apiUrl, materia);
	}

	// Actualizar una materia
	update(sigla: string, materia: Materia): Observable<{ success: boolean; data: Materia; message: string }> {
		return this.http.put<{ success: boolean; data: Materia; message: string }>(`${this.apiUrl}/${sigla}`, materia);
	}

	// Eliminar una materia
	delete(sigla: string): Observable<{ success: boolean; message: string }> {
		return this.http.delete<{ success: boolean; message: string }>(`${this.apiUrl}/${sigla}`);
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
	toggleStatus(sigla: string): Observable<{ success: boolean; data: Materia; message: string }> {
		return this.http.patch<{ success: boolean; data: Materia; message: string }>(`${this.apiUrl}/${sigla}/toggle-status`, {});
	}
}
