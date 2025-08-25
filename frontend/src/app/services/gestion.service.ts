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

	getActual(): Observable<{ success: boolean; data: Gestion }> {
		return this.http.get<any>(`${this.apiUrl}/actual/actual`).pipe(
			map((res: any) => {
				if (res && res.data) return { success: !!res.success, data: res.data as Gestion };
				return { success: true, data: res as Gestion };
			})
		);
	}
}
