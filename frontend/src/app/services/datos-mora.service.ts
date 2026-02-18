import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { map } from 'rxjs/operators';
import { environment } from '../../environments/environment';
import { DatosMora } from '../models/datos-mora.model';
import { DatosMoraDetalle } from '../models/datos-mora-detalle.model';

@Injectable({ providedIn: 'root' })
export class DatosMoraService {
	private apiUrl = `${environment.apiUrl}/datos-mora`;
	private detallesUrl = `${environment.apiUrl}/datos-mora-detalle`;

	constructor(private http: HttpClient) {}

	getAll(): Observable<{ success: boolean; data: DatosMora[] }> {
		return this.http.get<any>(this.apiUrl).pipe(
			map((res: any) => {
				if (Array.isArray(res)) return { success: true, data: res as DatosMora[] };
				if (res && Array.isArray(res.data)) return { success: typeof res.success === 'boolean' ? res.success : true, data: res.data as DatosMora[] };
				return { success: false, data: [] as DatosMora[] };
			})
		);
	}

	getAllDetalles(): Observable<{ success: boolean; data: DatosMoraDetalle[] }> {
		return this.http.get<any>(this.detallesUrl).pipe(
			map((res: any) => {
				if (Array.isArray(res)) return { success: true, data: res as DatosMoraDetalle[] };
				if (res && Array.isArray(res.data)) return { success: typeof res.success === 'boolean' ? res.success : true, data: res.data as DatosMoraDetalle[] };
				return { success: false, data: [] as DatosMoraDetalle[] };
			})
		);
	}

	getById(id: number): Observable<{ success: boolean; data: DatosMora }> {
		return this.http.get<{ success: boolean; data: DatosMora }>(`${this.apiUrl}/${id}`);
	}

	create(item: DatosMora): Observable<{ success: boolean; data: DatosMora; message: string }> {
		return this.http.post<{ success: boolean; data: DatosMora; message: string }>(this.apiUrl, item);
	}

	update(id: number, item: DatosMora): Observable<{ success: boolean; data: DatosMora; message: string }> {
		return this.http.put<{ success: boolean; data: DatosMora; message: string }>(`${this.apiUrl}/${encodeURIComponent(String(id))}`, item);
	}

	delete(id: number): Observable<{ success: boolean; message: string }> {
		return this.http.delete<{ success: boolean; message: string }>(`${this.apiUrl}/${encodeURIComponent(String(id))}`);
	}

	toggleStatus(id: number): Observable<{ success: boolean; data: DatosMora; message: string }> {
		return this.http.patch<{ success: boolean; data: DatosMora; message: string }>(`${this.apiUrl}/${encodeURIComponent(String(id))}/toggle-status`, {});
	}

	// Métodos para DatosMoraDetalle
	createDetalle(item: DatosMoraDetalle): Observable<{ success: boolean; data: DatosMoraDetalle; message: string }> {
		return this.http.post<{ success: boolean; data: DatosMoraDetalle; message: string }>(this.detallesUrl, item);
	}

	updateDetalle(id: number, item: DatosMoraDetalle): Observable<{ success: boolean; data: DatosMoraDetalle; message: string }> {
		return this.http.put<{ success: boolean; data: DatosMoraDetalle; message: string }>(`${this.detallesUrl}/${encodeURIComponent(String(id))}`, item);
	}

	toggleStatusDetalle(id: number): Observable<{ success: boolean; data: DatosMoraDetalle; message: string }> {
		return this.http.patch<{ success: boolean; data: DatosMoraDetalle; message: string }>(`${this.detallesUrl}/${encodeURIComponent(String(id))}/toggle-status`, {});
	}

	// Buscar o crear datos_mora por gestión
	findOrCreateByGestion(gestion: string): Observable<{ success: boolean; data: DatosMora; message?: string }> {
		return this.http.post<{ success: boolean; data: DatosMora; message?: string }>(`${this.apiUrl}/find-or-create`, { gestion });
	}
}
