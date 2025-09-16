import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { map } from 'rxjs/operators';
import { ItemCobro } from '../models/item-cobro.model';
import { environment } from '../../environments/environment';

@Injectable({
	providedIn: 'root'
})
export class ItemsCobroService {
	private apiUrl = `${environment.apiUrl}/items-cobro`;

	constructor(private http: HttpClient) {}

	// Normaliza valores numéricos/booleanos que pueden venir como string desde Laravel (casts decimal)
	private normalizeItem(i: any): ItemCobro {
		return {
			...i,
			// IDs y numéricos
			id_item: i?.id_item != null ? Number(i.id_item) : i?.id_item,
			codigo_producto_impuesto: i?.codigo_producto_impuesto != null && i.codigo_producto_impuesto !== '' ? Number(i.codigo_producto_impuesto) : undefined,
			unidad_medida: i?.unidad_medida != null ? Number(i.unidad_medida) : i?.unidad_medida,
			nro_creditos: i?.nro_creditos != null ? Number(i.nro_creditos) : 0,
			costo: i?.costo != null && i.costo !== '' ? Number(i.costo) : null,
			id_parametro_economico: i?.id_parametro_economico != null ? Number(i.id_parametro_economico) : i?.id_parametro_economico,
			// Booleanos
			estado: typeof i?.estado === 'boolean' ? i.estado : i?.estado == 1,
			facturado: typeof i?.facturado === 'boolean' ? i.facturado : i?.facturado == 1
		} as ItemCobro;
	}

	// Prepara payload para Laravel (coerción a números/booleanos)
	private serializePayload(item: any): any {
		const aeRaw = (item?.actividad_economica ?? '').toString();
		const descRaw = (item?.descripcion ?? '').toString();
		const ae = aeRaw.trim();
		const desc = descRaw.trim();
		return {
			...item,
			// Strings opcionales: enviar null si están vacíos
			actividad_economica: ae.length > 0 ? ae : null,
			descripcion: desc.length > 0 ? desc : null,
			// Numéricos
			codigo_producto_impuesto: item?.codigo_producto_impuesto != null && item.codigo_producto_impuesto !== '' ? Number(item.codigo_producto_impuesto) : null,
			unidad_medida: item?.unidad_medida != null && item.unidad_medida !== '' ? Number(item.unidad_medida) : null,
			nro_creditos: item?.nro_creditos != null && item.nro_creditos !== '' ? Number(item.nro_creditos) : 0,
			costo: item?.costo != null && item.costo !== '' ? Number(item.costo) : null,
			id_parametro_economico: item?.id_parametro_economico != null && item.id_parametro_economico !== '' ? Number(item.id_parametro_economico) : null,
			// Booleanos
			estado: !!item?.estado,
			facturado: !!item?.facturado
		};
	}

	// Obtener todos los items de cobro
	getAll(): Observable<{ success: boolean; data: ItemCobro[] }> {
		return this.http.get<any>(this.apiUrl).pipe(
			map((res: any) => {
				const normalize = (arr: any[]) => (arr || []).map((i) => this.normalizeItem(i));
				if (Array.isArray(res)) {
					return { success: true, data: normalize(res) };
				}
				if (res && Array.isArray(res.data)) {
					return { success: typeof res.success === 'boolean' ? res.success : true, data: normalize(res.data) };
				}
				return { success: false, data: [] as ItemCobro[] };
			})
		);
	}

	// Obtener un item de cobro por ID
	getById(id: number): Observable<{ success: boolean; data: ItemCobro }> {
		return this.http.get<any>(`${this.apiUrl}/${id}`).pipe(
			map((res: any) => {
				if (res && res.data) {
					return { success: typeof res.success === 'boolean' ? res.success : true, data: this.normalizeItem(res.data) };
				}
				return { success: false, data: this.normalizeItem(res) };
			})
		);
	}

	// Crear un nuevo item de cobro
	create(item: ItemCobro): Observable<{ success: boolean; data: ItemCobro; message: string }> {
		return this.http.post<any>(this.apiUrl, this.serializePayload(item)).pipe(
			map((res: any) => ({ success: !!res?.success, data: this.normalizeItem(res.data), message: res?.message || '' }))
		);
	}

	// Actualizar un item de cobro
	update(id: number, item: ItemCobro): Observable<{ success: boolean; data: ItemCobro; message: string }> {
		return this.http.put<any>(`${this.apiUrl}/${id}`, this.serializePayload(item)).pipe(
			map((res: any) => ({ success: !!res?.success, data: this.normalizeItem(res.data), message: res?.message || '' }))
		);
	}

	// Eliminar un item de cobro
	delete(id: number): Observable<{ success: boolean; message: string }> {
		return this.http.delete<any>(`${this.apiUrl}/${id}`).pipe(
			map((res: any) => ({ success: !!res?.success, message: res?.message || '' }))
		);
	}

	// Cambiar el estado de un item de cobro
	toggleStatus(id: number): Observable<{ success: boolean; data: ItemCobro; message: string }> {
		return this.http.patch<any>(`${this.apiUrl}/${id}/toggle-status`, {}).pipe(
			map((res: any) => ({ success: !!res?.success, data: this.normalizeItem(res.data), message: res?.message || '' }))
		);
	}
}

