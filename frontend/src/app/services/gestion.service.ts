import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { map } from 'rxjs/operators';
import { environment } from '../../environments/environment';
import { Gestion } from '../models/gestion.model';

@Injectable({ providedIn: 'root' })
export class GestionService {
	private apiUrl = `${environment.apiUrl}/gestiones`;

	constructor(private http: HttpClient) {}



	// Listar todas las gestiones
	getAll(): Observable<{ success: boolean; data: Gestion[] }> {
		return this.http.get<any>(this.apiUrl).pipe(
			map((res: any) => {
				if (Array.isArray(res)) return { success: true, data: res as Gestion[] };
				if (res && Array.isArray(res.data)) return { success: typeof res.success === 'boolean' ? res.success : true, data: res.data as Gestion[] };
				return { success: false, data: [] as Gestion[] };
			})
		);
	}

	// Obtener la gestión actual (activa)
	getActual(): Observable<{ success: boolean; data: Gestion }> {
		return this.http.get<any>(`${this.apiUrl}/actual/actual`).pipe(
			map((res: any) => {
				if (res && res.data) return { success: !!res.success, data: res.data as Gestion };
				return { success: true, data: res as Gestion };
			})
		);
	}

	// Gestiones activas
	getActivas(): Observable<{ success: boolean; data: Gestion[] }> {
		return this.http.get<any>(`${this.apiUrl}/estado/activas`).pipe(
			map((res: any) => {
				if (Array.isArray(res)) return { success: true, data: res as Gestion[] };
				if (res && Array.isArray(res.data)) return { success: typeof res.success === 'boolean' ? res.success : true, data: res.data as Gestion[] };
				return { success: false, data: [] as Gestion[] };
			})
		);
	}

	// Buscar gestiones por año
	getPorAnio(anio: string): Observable<{ success: boolean; data: Gestion[] }> {
		return this.http.get<any>(`${this.apiUrl}/ano/${encodeURIComponent(anio)}`).pipe(
			map((res: any) => {
				if (Array.isArray(res)) return { success: true, data: res as Gestion[] };
				if (res && Array.isArray(res.data)) return { success: typeof res.success === 'boolean' ? res.success : true, data: res.data as Gestion[] };
				return { success: false, data: [] as Gestion[] };
			})
		);
	}

	// CRUD básico
	getById(gestion: string): Observable<{ success: boolean; data: Gestion }> {
		return this.http.get<{ success: boolean; data: Gestion }>(`${this.apiUrl}/${encodeURIComponent(gestion)}`);
	}

	create(payload: Gestion): Observable<{ success: boolean; data: Gestion; message: string }> {
		return this.http.post<{ success: boolean; data: Gestion; message: string }>(this.apiUrl, payload);
	}

	update(gestion: string, payload: Partial<Gestion>): Observable<{ success: boolean; data: Gestion; message: string }> {
		return this.http.put<{ success: boolean; data: Gestion; message: string }>(`${this.apiUrl}/${encodeURIComponent(gestion)}`, payload);
	}

	delete(gestion: string): Observable<{ success: boolean; message: string }> {
		return this.http.delete<{ success: boolean; message: string }>(`${this.apiUrl}/${encodeURIComponent(gestion)}`);
	}

	// Cambiar estado de una gestión
	cambiarEstado(gestion: string, estado: boolean): Observable<{ success: boolean; data: Gestion; message: string }> {
		return this.http.patch<{ success: boolean; data: Gestion; message: string }>(`${this.apiUrl}/${encodeURIComponent(gestion)}/estado`, { estado });
	}
}
