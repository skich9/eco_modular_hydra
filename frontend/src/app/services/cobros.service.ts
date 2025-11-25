import { Injectable, Inject, PLATFORM_ID } from '@angular/core';
import { isPlatformBrowser } from '@angular/common';
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
	private apiUrl: string;
	private baseUrl: string;

	constructor(private http: HttpClient, @Inject(PLATFORM_ID) private platformId: Object) {
		if (isPlatformBrowser(this.platformId)) {
			const protocol = typeof window !== 'undefined' && window.location ? (window.location.protocol || 'http:') : 'http:';
			const host = typeof window !== 'undefined' && window.location ? (window.location.hostname || 'localhost') : 'localhost';
			const port = environment.apiPort || '8069';
			this.apiUrl = `${protocol}//${host}:${port}/api`;
		} else {
			this.apiUrl = environment.apiUrl;
		}
		this.baseUrl = `${this.apiUrl}/cobros`;
	}

	// ===================== SGA Reincorporación =====================
	getReincorporacionEstado(params: { cod_ceta: string | number; cod_pensum: string; gestion?: string }): Observable<any> {
		let httpParams = new HttpParams()
			.set('cod_ceta', String(params.cod_ceta))
			.set('cod_pensum', params.cod_pensum);
		if (params.gestion) httpParams = httpParams.set('gestion', String(params.gestion));
		const url = `${this.apiUrl}/sga/eco_hydra/Reincorporacion/estado`;
		return this.http.get<any>(url, { params: httpParams }).pipe(
			map((res: any) => ({ success: !!res?.success, data: res?.data || res, message: res?.message }))
		);
	}

	// ===================== Segunda Instancia (local) =====================
	getSegundaInstElegibilidad(params: { cod_inscrip: string | number; materias?: string[] | string }): Observable<any> {
		let httpParams = new HttpParams().set('cod_inscrip', String(params.cod_inscrip));
		if (Array.isArray(params.materias)) {
			const list = params.materias.filter((s) => !!s).join(',');
			if (list) httpParams = httpParams.set('materias', list);
		} else if (typeof params.materias === 'string' && params.materias.trim() !== '') {
			httpParams = httpParams.set('materias', params.materias.trim());
		}
		const url = `${this.apiUrl}/segunda-instancia/elegibilidad`;
		return this.http.get<any>(url, { params: httpParams }).pipe(
			map((res: any) => ({ success: !!res?.success, data: res?.data || res, message: res?.message }))
		);
	}

	getResumen(cod_ceta: string, gestion?: string): Observable<CobroResumenResponse> {
		let params = new HttpParams().set('cod_ceta', cod_ceta);
		if (gestion) params = params.set('gestion', gestion);
		return this.http.get<CobroResumenResponse>(`${this.baseUrl}/resumen`, { params }).pipe(
			map((res: any) => ({ success: !!res?.success, data: res?.data, message: res?.message }))
		);
	}

	// ===================== Estudiantes =====================
	searchEstudiantes(params: { ap_paterno?: string; ap_materno?: string; nombres?: string; ci?: string; page?: number; per_page?: number }): Observable<any> {
		let httpParams = new HttpParams();
		Object.entries(params || {}).forEach(([k, v]) => {
			if (v !== undefined && v !== null && String(v).trim() !== '') {
				httpParams = httpParams.set(k, String(v));
			}
		});
		return this.http.get<any>(`${this.apiUrl}/estudiantes/search`, { params: httpParams }).pipe(
			map((res: any) => ({ success: !!res?.success, data: res?.data || [], meta: res?.meta || null, message: res?.message }))
		);
	}

	// ===================== Parámetros de Cuotas =====================
	getParametrosCuotasActivas(): Observable<any> {
		return this.http.get<any>(`${this.apiUrl}/parametros-cuota/activos`).pipe(
			map((res: any) => ({ success: !!res?.success, data: res?.data || [], message: res?.message }))
		);
	}

	getParametrosCuotasAll(): Observable<any> {
		return this.http.get<any>(`${this.apiUrl}/parametros-cuota`).pipe(
			map((res: any) => ({ success: !!res?.success, data: res?.data || [], message: res?.message }))
		);
	}

	createParametroCuota(payload: { nombre_cuota: string; fecha_vencimiento: string; activo: boolean }): Observable<any> {
		return this.http.post<any>(`${this.apiUrl}/parametros-cuota`, payload).pipe(
			map((res: any) => ({ success: !!res?.success, data: res?.data || null, message: res?.message }))
		);
	}

	updateParametroCuota(id: number, payload: { fecha_vencimiento: string }): Observable<any> {
		return this.http.put<any>(`${this.apiUrl}/parametros-cuota/${id}`, payload).pipe(
			map((res: any) => ({ success: !!res?.success, data: res?.data || null, message: res?.message }))
		);
	}

	batchStore(payload: any): Observable<any> {
		return this.http.post<any>(`${this.baseUrl}/batch`, payload).pipe(
			map((res: any) => ({ success: !!res?.success, data: res?.data, message: res?.message }))
		);
	}

	// ===================== Descargas =====================
	downloadReciboPdf(anio: number, nroRecibo: number): Observable<Blob> {
		const url = `${this.apiUrl}/recibos/${anio}/${nroRecibo}/pdf`;
		return this.http.get(url, { responseType: 'blob' });
	}

	// Descarga de factura computarizada (si backend expone el endpoint)
	downloadFacturaPdf(anio: number, nroFactura: number): Observable<Blob> {
		const url = `${this.apiUrl}/facturas/${anio}/${nroFactura}/pdf`;
		return this.http.get(url, { responseType: 'blob' });
	}

	// Obtener metadatos de factura (incluye CUF)
	getFacturaMeta(anio: number, nroFactura: number): Observable<any> {
		const url = `${this.apiUrl}/facturas/${anio}/${nroFactura}/meta`;
		return this.http.get(url);
	}

	// SIN: URL base del QR (centralizada en backend .env)
	getSinQrUrl(): Observable<string> {
		const url = `${this.apiUrl}/sin/qr-url`;
		return this.http.get<any>(url).pipe(
			map((res: any) => {
				const u = res && res.data ? (res.data.url || '') : '';
				return String(u || '');
			})
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

	initiateQr(payload: {
		cod_ceta: number | string;
		cod_pensum: string;
		tipo_inscripcion: string;
		id_usuario: number | string;
		id_cuentas_bancarias: number | string;
		amount: number;
		detalle: string;
		moneda: 'BOB' | 'USD';
		gestion?: string;
		items?: any[];
	}): Observable<any> {
		return this.http.post<any>(`${this.apiUrl}/qr/initiate`, payload).pipe(
			map((res: any) => ({ success: !!res?.success, data: res?.data || null, message: res?.message, meta: res?.meta }))
		);
	}

	statusQr(alias: string): Observable<any> {
		return this.http.post<any>(`${this.apiUrl}/qr/status`, { alias }).pipe(
			map((res: any) => ({ success: !!res?.success, data: res?.data || null, message: res?.message }))
		);
	}

	syncQrByCodCeta(payload: { cod_ceta: number | string; id_usuario?: number | string }): Observable<any> {
		return this.http.post<any>(`${this.apiUrl}/qr/sync-by-codceta`, payload).pipe(
			map((res: any) => ({ success: !!res?.success, data: res?.data || null, message: res?.message }))
		);
	}

	stateQrByCodCeta(payload: { cod_ceta: number | string }): Observable<any> {
		return this.http.post<any>(`${this.apiUrl}/qr/state-by-codceta`, payload).pipe(
			map((res: any) => ({ success: !!res?.success, data: res?.data || null, message: res?.message }))
		);
	}

	disableQr(alias: string, id_usuario?: number | string): Observable<any> {
		const body: any = { alias };
		if (id_usuario !== undefined) body.id_usuario = id_usuario;
		return this.http.post<any>(`${this.apiUrl}/qr/disable`, body).pipe(
			map((res: any) => ({ success: !!res?.success, data: res?.data || null, message: res?.message, meta: res?.meta }))
		);
	}

	saveQrLote(payload: {
		alias?: string;
		cod_ceta?: number | string;
		id_usuario?: number | string;
		id_cuentas_bancarias?: number | string;
		moneda?: string;
		gestion?: string;
		items: any[];
	}): Observable<any> {
		return this.http.post<any>(`${this.apiUrl}/qr/save-lote`, payload).pipe(
			map((res: any) => ({ success: !!res?.success, data: res?.data || null, message: res?.message }))
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

	getQrTransactions(params: { cod_ceta?: string | number; alias?: string; estado?: string; desde?: string; hasta?: string; limit?: number; page?: number } = {}): Observable<any> {
		let httpParams = new HttpParams();
		Object.entries(params).forEach(([k, v]) => { if (v !== undefined && v !== null && String(v) !== '') httpParams = httpParams.set(k, String(v)); });
		return this.http.get<any>(`${this.apiUrl}/qr/transactions`, { params: httpParams }).pipe(
			map((res: any) => ({ success: !!res?.success, data: res?.data || null, message: res?.message }))
		);
	}

	getQrTransactionDetail(id: number | string): Observable<any> {
		return this.http.get<any>(`${this.apiUrl}/qr/transactions/${id}`).pipe(
			map((res: any) => ({ success: !!res?.success, data: res?.data || null, message: res?.message }))
		);
	}

	getQrRespuestas(params: { id_qr_transaccion?: number | string; alias?: string } = {}): Observable<any> {
		let httpParams = new HttpParams();
		Object.entries(params).forEach(([k, v]) => { if (v !== undefined && v !== null && String(v) !== '') httpParams = httpParams.set(k, String(v)); });
		return this.http.get<any>(`${this.apiUrl}/qr/respuestas`, { params: httpParams }).pipe(
			map((res: any) => ({ success: !!res?.success, data: res?.data || [], message: res?.message }))
		);
	}

	getQrConfig(cod_pensum?: string): Observable<any> {
		let httpParams = new HttpParams();
		if (cod_pensum) httpParams = httpParams.set('cod_pensum', cod_pensum);
		return this.http.get<any>(`${this.apiUrl}/qr/config`, { params: httpParams }).pipe(
			map((res: any) => ({ success: !!res?.success, data: res?.data || [], message: res?.message }))
		);
	}

	upsertQrConfig(payload: { cod_pensum?: string; tiempo_expiracion_minutos?: number; monto_minimo?: number; permite_pago_parcial?: boolean; template_mensaje?: string; estado?: boolean; }): Observable<any> {
		return this.http.post<any>(`${this.apiUrl}/qr/config`, payload).pipe(
			map((res: any) => ({ success: !!res?.success, data: res?.data || null, message: res?.message }))
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

	// Crear Cuotas en lote
	createCuotasBatch(payload: {
		cod_pensum: string;
		gestion: string;
		cuotas: Array<{
			nombre: string;
			descripcion?: string | null;
			semestre: string;
			monto: number;
			fecha_vencimiento: string;
			tipo?: string;
			turno?: string;
			activo?: boolean;
		}>;
	}): Observable<any> {
		return this.http.post<any>(`${this.apiUrl}/cuotas/batch`, payload).pipe(
			map((res: any) => ({ success: !!res?.success, data: res?.data || null, message: res?.message }))
		);
	}

	// Actualizar cuotas por contexto
	updateCuotasByContext(payload: {
		cod_pensum: string;
		gestion: string;
		semestre: number | string;
		monto: number;
		tipo?: string;
		turno?: string;
		activo?: boolean;
	}): Observable<any> {
		return this.http.put<any>(`${this.apiUrl}/cuotas/context`, payload).pipe(
			map((res: any) => ({ success: !!res?.success, data: res?.data || null, message: res?.message }))
		);
	}

	// Eliminar cuotas por contexto
	deleteCuotasByContext(payload: {
		cod_pensum: string;
		gestion: string;
		semestre: number | string;
		tipo?: string;
		turno?: string;
	}): Observable<any> {
		return this.http.post<any>(`${this.apiUrl}/cuotas/context/delete`, payload).pipe(
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

	// ===================== SGA Recuperación =====================
	getRecuperacionElegibilidad(params: { cod_ceta: string | number; cod_pensum: string; gestion?: string }): Observable<any> {
		let httpParams = new HttpParams()
			.set('cod_ceta', String(params.cod_ceta))
			.set('cod_pensum', params.cod_pensum);
		if (params.gestion) httpParams = httpParams.set('gestion', String(params.gestion));
		const url = `${this.apiUrl}/sga/eco_hydra/Recuperacion/elegibilidad`;
		return this.http.get<any>(url, { params: httpParams }).pipe(
			map((res: any) => ({ success: !!res?.success, data: res?.data || res, message: res?.message }))
		);
	}

	getRecuperacionAutorizaciones(params: { cod_ceta: string | number; cod_pensum: string }): Observable<any> {
		const httpParams = new HttpParams()
			.set('cod_ceta', String(params.cod_ceta))
			.set('cod_pensum', params.cod_pensum);
		const url = `${this.apiUrl}/sga/eco_hydra/Recuperacion/autorizaciones`;
		return this.http.get<any>(url, { params: httpParams }).pipe(
			map((res: any) => ({ success: !!res?.success, data: res?.data || res, message: res?.message }))
		);
	}

	// ===================== Kardex Notas =====================
	getKardexMaterias(params: { cod_ceta: string | number; cod_pensum: string; cod_inscrip?: string | number; tipo_incripcion?: string; tipo_inscripcion?: string; gestion?: string }): Observable<any> {
		let httpParams = new HttpParams()
			.set('cod_ceta', String(params.cod_ceta))
			.set('cod_pensum', params.cod_pensum);
		if (params.cod_inscrip != null && params.cod_inscrip !== '') httpParams = httpParams.set('cod_inscrip', String(params.cod_inscrip));
		// soportar ambas variantes
		const tipoVal = (params as any).tipo_incripcion ?? (params as any).tipo_inscripcion;
		if (tipoVal) httpParams = httpParams.set('tipo_incripcion', String(tipoVal));
		if (params.gestion) httpParams = httpParams.set('gestion', String(params.gestion));
		return this.http.get<any>(`${this.apiUrl}/kardex-notas/materias`, { params: httpParams }).pipe(
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
