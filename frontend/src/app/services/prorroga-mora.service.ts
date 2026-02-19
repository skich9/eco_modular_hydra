import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../environments/environment';
import { ProrrogaMora } from '../models/prorroga-mora.model';

@Injectable({
	providedIn: 'root'
})
export class ProrrogaMoraService {
	private apiUrl = `${environment.apiUrl}/prorrogas-mora`;

	constructor(private http: HttpClient) {}

	getAll(): Observable<any> {
		return this.http.get<any>(this.apiUrl);
	}

	getById(id: number): Observable<any> {
		return this.http.get<any>(`${this.apiUrl}/${id}`);
	}

	getActivas(): Observable<any> {
		return this.http.get<any>(`${this.apiUrl}/activas`);
	}

	getPorEstudiante(codCeta: number): Observable<any> {
		return this.http.get<any>(`${this.apiUrl}/estudiante/${codCeta}`);
	}

	create(prorroga: ProrrogaMora): Observable<any> {
		return this.http.post<any>(this.apiUrl, prorroga);
	}

	update(id: number, prorroga: Partial<ProrrogaMora>): Observable<any> {
		return this.http.put<any>(`${this.apiUrl}/${id}`, prorroga);
	}

	delete(id: number): Observable<any> {
		return this.http.delete<any>(`${this.apiUrl}/${id}`);
	}

	toggleStatus(id: number): Observable<any> {
		return this.http.patch<any>(`${this.apiUrl}/${id}/toggle-status`, {});
	}
}
