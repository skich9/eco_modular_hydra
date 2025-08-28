import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { map } from 'rxjs/operators';
import { environment } from '../../environments/environment';
import { ParametroGeneral } from '../models/parametro-general.model';

@Injectable({ providedIn: 'root' })
export class ParametrosGeneralesService {
	private apiUrl = `${environment.apiUrl}/parametros-generales`;

	constructor(private http: HttpClient) {}

	getAll(): Observable<{ success: boolean; data: ParametroGeneral[] }> {
		return this.http.get<any>(this.apiUrl).pipe(
			map((res: any) => {
				if (Array.isArray(res)) return { success: true, data: res as ParametroGeneral[] };
				if (res && Array.isArray(res.data)) return { success: typeof res.success === 'boolean' ? res.success : true, data: res.data as ParametroGeneral[] };
				return { success: false, data: [] as ParametroGeneral[] };
			})
		);
	}

	getById(id: number): Observable<{ success: boolean; data: ParametroGeneral }> {
		return this.http.get<{ success: boolean; data: ParametroGeneral }>(`${this.apiUrl}/${id}`);
	}

	create(item: ParametroGeneral): Observable<{ success: boolean; data: ParametroGeneral; message: string }> {
		return this.http.post<{ success: boolean; data: ParametroGeneral; message: string }>(this.apiUrl, item);
	}

	update(id: number, item: ParametroGeneral): Observable<{ success: boolean; data: ParametroGeneral; message: string }> {
		return this.http.put<{ success: boolean; data: ParametroGeneral; message: string }>(`${this.apiUrl}/${encodeURIComponent(String(id))}`, item);
	}

	delete(id: number): Observable<{ success: boolean; message: string }> {
		return this.http.delete<{ success: boolean; message: string }>(`${this.apiUrl}/${encodeURIComponent(String(id))}`);
	}

	toggleStatus(id: number): Observable<{ success: boolean; data: ParametroGeneral; message: string }> {
		return this.http.patch<{ success: boolean; data: ParametroGeneral; message: string }>(`${this.apiUrl}/${encodeURIComponent(String(id))}/toggle-status`, {});
	}
}
