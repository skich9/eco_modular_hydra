import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { map } from 'rxjs/operators';
import { environment } from '../../environments/environment';
import { DefDescuento } from '../models/def-descuento.model';

@Injectable({ providedIn: 'root' })
export class DefDescuentosService {
	private apiUrl = `${environment.apiUrl}/def-descuentos`;

	constructor(private http: HttpClient) {}

	private normalize(d: any): DefDescuento {
		return {
			cod_descuento: d?.cod_descuento != null ? Number(d.cod_descuento) : d?.cod_descuento,
			nombre_descuento: d?.nombre_descuento ?? '',
			descripcion: d?.descripcion ?? null,
			monto: d?.monto != null && d.monto !== '' ? Number(d.monto) : 0,
			porcentaje: typeof d?.porcentaje === 'boolean' ? d.porcentaje : d?.porcentaje == 1,
			estado: typeof d?.estado === 'boolean' ? d.estado : d?.estado == 1,
			d_i: typeof d?.d_i === 'boolean' ? d.d_i : d?.d_i == 1,
			created_at: d?.created_at,
			updated_at: d?.updated_at,
		};
	}

	getAll(): Observable<{ success: boolean; data: DefDescuento[]; message?: string }> {
		return this.http.get<any>(this.apiUrl).pipe(
			map((res: any) => {
				const normalize = (arr: any[]) => (arr || []).map((i) => this.normalize(i));
				if (Array.isArray(res)) return { success: true, data: normalize(res) };
				if (res && Array.isArray(res.data)) return { success: !!res.success, data: normalize(res.data), message: res?.message };
				return { success: false, data: [] as DefDescuento[] };
			})
		);
	}

	toggleStatus(id: number): Observable<{ success: boolean; data: DefDescuento; message?: string }> {
		return this.http.patch<any>(`${this.apiUrl}/${id}/toggle-status`, {}).pipe(
			map((res: any) => ({ success: !!res?.success, data: this.normalize(res?.data), message: res?.message }))
		);
	}

	create(payload: Partial<DefDescuento>): Observable<{ success: boolean; data: DefDescuento; message?: string }> {
		return this.http.post<any>(this.apiUrl, payload).pipe(
			map((res: any) => ({ success: !!res?.success, data: this.normalize(res?.data), message: res?.message }))
		);
	}

	update(id: number, payload: Partial<DefDescuento>): Observable<{ success: boolean; data: DefDescuento; message?: string }> {
		return this.http.put<any>(`${this.apiUrl}/${id}`, payload).pipe(
			map((res: any) => ({ success: !!res?.success, data: this.normalize(res?.data), message: res?.message }))
		);
	}

	delete(id: number): Observable<{ success: boolean; message?: string }> {
		return this.http.delete<any>(`${this.apiUrl}/${id}`).pipe(
			map((res: any) => ({ success: !!res?.success, message: res?.message }))
		);
	}
}
