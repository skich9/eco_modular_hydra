import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { map } from 'rxjs/operators';
import { environment } from '../../environments/environment';
import { DefDescuentoBeca } from '../models/def-descuento-beca.model';

@Injectable({ providedIn: 'root' })
export class DefDescuentosBecaService {
	private apiUrl = `${environment.apiUrl}/def-descuentos-beca`;

	constructor(private http: HttpClient) {}

	private normalize(d: any): DefDescuentoBeca {
		return {
			cod_beca: d?.cod_beca != null ? Number(d.cod_beca) : d?.cod_beca,
			nombre_beca: d?.nombre_beca ?? '',
			descripcion: d?.descripcion ?? null,
			monto: d?.monto != null && d.monto !== '' ? Number(d.monto) : 0,
			porcentaje: typeof d?.porcentaje === 'boolean' ? d.porcentaje : d?.porcentaje == 1,
			estado: typeof d?.estado === 'boolean' ? d.estado : d?.estado == 1,
			created_at: d?.created_at,
			updated_at: d?.updated_at,
		};
	}

	getAll(): Observable<{ success: boolean; data: DefDescuentoBeca[]; message?: string }> {
		return this.http.get<any>(this.apiUrl).pipe(
			map((res: any) => {
				const normalize = (arr: any[]) => (arr || []).map((i) => this.normalize(i));
				if (Array.isArray(res)) return { success: true, data: normalize(res) };
				if (res && Array.isArray(res.data)) return { success: !!res.success, data: normalize(res.data), message: res?.message };
				return { success: false, data: [] as DefDescuentoBeca[] };
			})
		);
	}

	toggleStatus(id: number): Observable<{ success: boolean; data: DefDescuentoBeca; message?: string }> {
		return this.http.patch<any>(`${this.apiUrl}/${id}/toggle-status`, {}).pipe(
			map((res: any) => ({ success: !!res?.success, data: this.normalize(res?.data), message: res?.message }))
		);
	}
}
