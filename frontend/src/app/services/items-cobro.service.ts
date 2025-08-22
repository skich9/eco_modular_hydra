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

	// Obtener todos los items de cobro
	getAll(): Observable<{ success: boolean; data: ItemCobro[] }> {
		return this.http.get<any>(this.apiUrl).pipe(
			map((res: any) => {
				if (Array.isArray(res)) {
					return { success: true, data: res as ItemCobro[] };
				}
				if (res && Array.isArray(res.data)) {
					return { success: typeof res.success === 'boolean' ? res.success : true, data: res.data as ItemCobro[] };
				}
				return { success: false, data: [] as ItemCobro[] };
			})
		);
	}

	// Obtener un item de cobro por ID
	getById(id: number): Observable<{ success: boolean; data: ItemCobro }> {
		return this.http.get<{ success: boolean; data: ItemCobro }>(`${this.apiUrl}/${id}`);
	}

	// Crear un nuevo item de cobro
	create(item: ItemCobro): Observable<{ success: boolean; data: ItemCobro; message: string }> {
		return this.http.post<{ success: boolean; data: ItemCobro; message: string }>(this.apiUrl, item);
	}

	// Actualizar un item de cobro
	update(id: number, item: ItemCobro): Observable<{ success: boolean; data: ItemCobro; message: string }> {
		return this.http.put<{ success: boolean; data: ItemCobro; message: string }>(`${this.apiUrl}/${id}`, item);
	}

	// Eliminar un item de cobro
	delete(id: number): Observable<{ success: boolean; message: string }> {
		return this.http.delete<{ success: boolean; message: string }>(`${this.apiUrl}/${id}`);
	}

	// Cambiar el estado de un item de cobro
	toggleStatus(id: number): Observable<{ success: boolean; data: ItemCobro; message: string }> {
		return this.http.patch<{ success: boolean; data: ItemCobro; message: string }>(`${this.apiUrl}/${id}/toggle-status`, {});
	}
}
