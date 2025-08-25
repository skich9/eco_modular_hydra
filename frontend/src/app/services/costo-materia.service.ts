import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { map } from 'rxjs/operators';
import { environment } from '../../environments/environment';
import { CostoMateria } from '../models/costo-materia.model';

@Injectable({ providedIn: 'root' })
export class CostoMateriaService {
	private apiUrl = `${environment.apiUrl}/costo-materia`;

	constructor(private http: HttpClient) {}

	private normalize(item: any): CostoMateria {
		return {
			...item,
			id_costo_materia: item?.id_costo_materia != null ? Number(item.id_costo_materia) : item?.id_costo_materia,
			nro_creditos: item?.nro_creditos != null && item.nro_creditos !== '' ? Number(item.nro_creditos) : undefined,
			monto_materia: item?.monto_materia != null && item.monto_materia !== '' ? Number(item.monto_materia) : 0
		} as CostoMateria;
	}

	getByGestion(gestion: string): Observable<{ success: boolean; data: CostoMateria[] }> {
		return this.http.get<any>(`${this.apiUrl}/gestion/${encodeURIComponent(gestion)}`).pipe(
			map((res: any) => {
				const normalize = (arr: any[]) => (arr || []).map((i) => this.normalize(i));
				if (Array.isArray(res)) return { success: true, data: normalize(res) };
				if (res && Array.isArray(res.data)) return { success: !!res.success, data: normalize(res.data) };
				return { success: false, data: [] as CostoMateria[] };
			})
		);
	}
}
