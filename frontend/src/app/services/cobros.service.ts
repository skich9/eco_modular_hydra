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

	getSinDocumentosIdentidad(): Observable<any> {
		return this.http.get<any>(`${this.apiUrl}/sin/documentos-identidad`).pipe(
			map((res: any) => ({ success: !!res?.success, data: res?.data || [], message: res?.message }))
		);
	}

	getFormasCobro(): Observable<any> {
		return this.http.get<any>(`${this.apiUrl}/formas-cobro`).pipe(
			map((res: any) => ({ success: !!res?.success, data: res?.data || [], message: res?.message }))
		);
	}

	getCuentasBancarias(onlyEnabled: boolean = true): Observable<any> {
		const params = new HttpParams().set('only_enabled', String(onlyEnabled));
		return this.http.get<any>(`${this.apiUrl}/cuentas-bancarias`, { params }).pipe(
			map((res: any) => ({ success: !!res?.success, data: res?.data || [], message: res?.message }))
		);
	}

	// Parámetros de costos activos
	getParametrosCostosActivos(): Observable<any> {
		return this.http.get<any>(`${this.apiUrl}/parametros-costos/activos`).pipe(
			map((res: any) => ({ success: !!res?.success, data: res?.data || [], message: res?.message }))
		);
	}

	// Parámetros de costos (todos)
	getParametrosCostosAll(): Observable<any> {
		return this.http.get<any>(`${this.apiUrl}/parametros-costos`).pipe(
			map((res: any) => ({ success: !!res?.success, data: res?.data || [], message: res?.message }))
		);
	}

	// Actualizar un parámetro de costo
	updateParametroCosto(id: number, payload: { nombre_costo?: string; nombre_oficial?: string; descripcion?: string; activo?: boolean; }): Observable<any> {
		return this.http.put<any>(`${this.apiUrl}/parametros-costos/${id}`, payload).pipe(
			map((res: any) => ({ success: !!res?.success, data: res?.data || null, message: res?.message }))
		);
	}

	// Crear parámetro de costo
	createParametroCosto(payload: {
		nombre_costo: string;
		nombre_oficial: string;
		descripcion?: string | null;
		activo: boolean;
	}): Observable<any> {
		return this.http.post<any>(`${this.apiUrl}/parametros-costos`, payload).pipe(
			map((res: any) => ({ success: !!res?.success, data: res?.data || null, message: res?.message }))
		);
	}

	// Costo semestral por pensum (gestion opcional)
	getCostoSemestralByPensum(codPensum: string, gestion?: string): Observable<any> {
		let params = new HttpParams();
		if (gestion) params = params.set('gestion', gestion);
		return this.http.get<any>(`${this.apiUrl}/costo-semestral/pensum/${encodeURIComponent(codPensum)}`, { params }).pipe(
			map((res: any) => ({ success: !!res?.success, data: res?.data || [], message: res?.message }))
		);
	}

	// Guardar costo_semestral en lote
	saveCostoSemestralBatch(payload: {
		cod_pensum: string;
		gestion: string;
		costo_fijo?: number;
		valor_credito?: number;
		id_usuario?: number;
		rows: Array<{ semestre: number; tipo_costo: string; monto_semestre: number; turno: string }>;
	}): Observable<any> {
		return this.http.post<any>(`${this.apiUrl}/costo-semestral/batch`, payload).pipe(
			map((res: any) => ({ success: !!res?.success, data: res?.data || null, message: res?.message }))
		);
	}

	// Actualizar costo_semestral por ID
	updateCostoSemestral(id: number, payload: { monto_semestre: number; tipo_costo?: string; turno?: string }): Observable<any> {
		return this.http.put<any>(`${this.apiUrl}/costo-semestral/${id}`, payload).pipe(
			map((res: any) => ({ success: !!res?.success, data: res?.data || null, message: res?.message }))
		);
	}

	// Eliminar costo_semestral por ID
	deleteCostoSemestral(id: number): Observable<any> {
		return this.http.delete<any>(`${this.apiUrl}/costo-semestral/${id}`).pipe(
			map((res: any) => ({ success: !!res?.success, data: res?.data || null, message: res?.message }))
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
