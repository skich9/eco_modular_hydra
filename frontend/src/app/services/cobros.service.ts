import { Injectable } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import { map } from 'rxjs/operators';
import { environment } from '../../environments/environment';

interface CobroResumenResponse {
	success: boolean;
	data: any;
	message?: string;
}

@Injectable({ providedIn: 'root' })
export class CobrosService {
	private baseUrl = `${environment.apiUrl}/cobros`;

	constructor(private http: HttpClient) {}

	getResumen(cod_ceta: string, gestion?: string): Observable<CobroResumenResponse> {
		let params = new HttpParams().set('cod_ceta', cod_ceta);
		if (gestion) params = params.set('gestion', gestion);
		return this.http.get<CobroResumenResponse>(`${this.baseUrl}/resumen`, { params }).pipe(
			map((res: any) => ({ success: !!res?.success, data: res?.data, message: res?.message }))
		);
	}

	batchStore(payload: any): Observable<any> {
		return this.http.post<any>(`${this.baseUrl}/batch`, payload).pipe(
			map((res: any) => ({ success: !!res?.success, data: res?.data, message: res?.message }))
		);
	}
}
