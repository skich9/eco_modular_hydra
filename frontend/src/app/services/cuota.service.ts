import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { map } from 'rxjs/operators';
import { environment } from '../../environments/environment';
import { Cuota } from '../models/cuota.model';

@Injectable({ providedIn: 'root' })
export class CuotaService {
	private apiUrl = `${environment.apiUrl}/cuotas`;

	constructor(private http: HttpClient) {}

	getAll(): Observable<{ success: boolean; data: Cuota[] }> {
		return this.http.get<any>(this.apiUrl).pipe(
			map((res: any) => {
				if (Array.isArray(res)) return { success: true, data: res as Cuota[] };
				if (res && Array.isArray(res.data)) return { success: typeof res.success === 'boolean' ? res.success : true, data: res.data as Cuota[] };
				return { success: false, data: [] as Cuota[] };
			})
		);
	}
}
