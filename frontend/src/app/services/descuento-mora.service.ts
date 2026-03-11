import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../environments/environment';

@Injectable({
	providedIn: 'root'
})
export class DescuentoMoraService {
	private apiUrl = `${environment.apiUrl}/descuentos-mora`;

	constructor(private http: HttpClient) {}

	getAll(): Observable<any> {
		return this.http.get<any>(this.apiUrl);
	}

	getPorEstudiante(codCeta: number | string): Observable<any> {
		return this.http.get<any>(`${this.apiUrl}/estudiante/${codCeta}`);
	}

	createMultiple(descuentos: any[], observaciones: string): Observable<any> {
		return this.http.post<any>(`${this.apiUrl}/batch`, { descuentos, observaciones });
	}

	toggleStatus(id: number | string): Observable<any> {
		return this.http.patch<any>(`${this.apiUrl}/${id}/toggle-status`, {});
	}
}
