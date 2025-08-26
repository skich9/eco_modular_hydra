import { Injectable } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import { map } from 'rxjs/operators';
import { environment } from '../../environments/environment';
import { Descuento } from '../models/descuento.model';

@Injectable({
	providedIn: 'root'
})
export class DescuentosService {
	private apiUrl = `${environment.apiUrl}/descuentos`;

	constructor(private http: HttpClient) {}

	private normalize(d: any): Descuento {
		return {
			...d,
			id_descuentos: d?.id_descuentos != null ? Number(d.id_descuentos) : d?.id_descuentos,
			cod_ceta: d?.cod_ceta != null ? Number(d.cod_ceta) : d?.cod_ceta,
			cod_pensum: d?.cod_pensum,
			cod_inscrip: d?.cod_inscrip != null ? Number(d.cod_inscrip) : d?.cod_inscrip,
			id_usuario: d?.id_usuario != null ? Number(d.id_usuario) : d?.id_usuario,
			porcentaje: d?.porcentaje != null && d.porcentaje !== '' ? Number(d.porcentaje) : 0,
			estado: typeof d?.estado === 'boolean' ? d.estado : d?.estado == 1
		} as Descuento;
	}

	private serializePayload(p: any): any {
		return {
			...p,
			cod_ceta: p?.cod_ceta != null && p.cod_ceta !== '' ? Number(p.cod_ceta) : null,
			cod_pensum: p?.cod_pensum ?? null,
			cod_inscrip: p?.cod_inscrip != null && p.cod_inscrip !== '' ? Number(p.cod_inscrip) : null,
			id_usuario: p?.id_usuario != null && p.id_usuario !== '' ? Number(p.id_usuario) : null,
			porcentaje: p?.porcentaje != null && p.porcentaje !== '' ? Number(p.porcentaje) : 0,
			estado: !!p?.estado
		};
	}

	getAll(filter?: { estado?: boolean; cod_pensum?: string; cod_ceta?: number }): Observable<{ success: boolean; data: Descuento[]; message?: string }> {
		let params = new HttpParams();
		if (filter) {
			if (filter.estado !== undefined) params = params.set('estado', String(filter.estado));
			if (filter.cod_pensum) params = params.set('cod_pensum', filter.cod_pensum);
			if (filter.cod_ceta !== undefined) params = params.set('cod_ceta', String(filter.cod_ceta));
		}

		return this.http.get<any>(this.apiUrl, { params }).pipe(
			map((res: any) => {
				const normalize = (arr: any[]) => (arr || []).map((i) => this.normalize(i));
				if (Array.isArray(res)) {
					return { success: true, data: normalize(res) };
				}
				if (res && Array.isArray(res.data)) {
					return { success: typeof res.success === 'boolean' ? res.success : true, data: normalize(res.data), message: res?.message };
				}
				return { success: false, data: [] as Descuento[] };
			})
		);
	}

	getActive(): Observable<{ success: boolean; data: Descuento[]; message?: string }> {
		return this.http.get<any>(`${this.apiUrl}/active`).pipe(
			map((res: any) => ({ success: !!res?.success, data: (res?.data || []).map((i: any) => this.normalize(i)), message: res?.message }))
		);
	}

	getById(id: number): Observable<{ success: boolean; data: Descuento; message?: string }> {
		return this.http.get<any>(`${this.apiUrl}/${id}`).pipe(
			map((res: any) => {
				if (res && res.data) return { success: !!res?.success, data: this.normalize(res.data), message: res?.message };
				return { success: true, data: this.normalize(res) };
			})
		);
	}

	create(item: Partial<Descuento>): Observable<{ success: boolean; data: Descuento; message: string }> {
		return this.http.post<any>(this.apiUrl, this.serializePayload(item)).pipe(
			map((res: any) => ({ success: !!res?.success, data: this.normalize(res.data), message: res?.message || '' }))
		);
	}

	update(id: number, item: Partial<Descuento>): Observable<{ success: boolean; data: Descuento; message: string }> {
		return this.http.put<any>(`${this.apiUrl}/${id}`, this.serializePayload(item)).pipe(
			map((res: any) => ({ success: !!res?.success, data: this.normalize(res.data), message: res?.message || '' }))
		);
	}

	delete(id: number): Observable<{ success: boolean; message: string }> {
		return this.http.delete<any>(`${this.apiUrl}/${id}`).pipe(
			map((res: any) => ({ success: !!res?.success, message: res?.message || '' }))
		);
	}

	toggleStatus(id: number): Observable<{ success: boolean; data: Descuento; message: string }> {
		return this.http.patch<any>(`${this.apiUrl}/${id}/toggle-status`, {}).pipe(
			map((res: any) => ({ success: !!res?.success, data: this.normalize(res.data), message: res?.message || '' }))
		);
	}
}
