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
	private apiUrl = environment.apiUrl;

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

	// Catálogos
	getGestionesActivas(): Observable<any> {
		return this.http.get<any>(`${this.apiUrl}/gestiones/estado/activas`).pipe(
			map((res: any) => ({ success: !!res?.success, data: res?.data || [], message: res?.message }))
		);
	}

	getPensumsByCarrera(codigoCarrera: string): Observable<any> {
		return this.http.get<any>(`${this.apiUrl}/carreras/${encodeURIComponent(codigoCarrera)}/pensums`).pipe(
			map((res: any) => ({ success: !!res?.success, data: res?.data || [], message: res?.message }))
		);
	}

	getFormasCobro(): Observable<any> {
		return this.http.get<any>(`${this.apiUrl}/formas-cobro`).pipe(
			map((res: any) => ({ success: !!res?.success, data: res?.data || [], message: res?.message }))
		);
	}

	// Razón Social
	buscarRazonSocial(numero: string, tipoId: number): Observable<any> {
		let params = new HttpParams().set('numero', numero).set('tipo_id', String(tipoId));
		return this.http.get<any>(`${this.apiUrl}/razon-social/search`, { params }).pipe(
			map((res: any) => ({ success: !!res?.success, data: res?.data || null, message: res?.message }))
		);
	}

	guardarRazonSocial(payload: { nit: string; tipo_id: number; razon_social?: string | null; complemento?: string | null; }): Observable<any> {
		return this.http.post<any>(`${this.apiUrl}/razon-social`, payload).pipe(
			map((res: any) => ({ success: !!res?.success, data: res?.data || null, message: res?.message }))
		);
	}
}
