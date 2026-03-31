import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../environments/environment';

@Injectable({ providedIn: 'root' })
export class OtrosIngresosService {
	private readonly baseReg = `${environment.apiUrl}/economico/otros-ingresos`;
	private readonly baseMod = `${environment.apiUrl}/economico/mod-otros-ingresos`;

	constructor(private http: HttpClient) {}

	getInitial(): Observable<any> {
		return this.http.get(`${this.baseReg}/initial`);
	}

	/** Siguiente Nº de recibo correlativo (solo otros ingresos). */
	getSiguienteNumRecibo(): Observable<{ success?: boolean; siguiente: number }> {
		return this.http.get<{ success?: boolean; siguiente: number }>(`${this.baseReg}/siguiente-num-recibo`);
	}

	getSiguienteNumFactura(): Observable<{ success?: boolean; siguiente: number }> {
		return this.http.get<{ success?: boolean; siguiente: number }>(`${this.baseReg}/siguiente-num-factura`);
	}

	getAutorizaciones(codPensum: string): Observable<any[]> {
		const body = { cod_pensum: codPensum };
		return this.http.post<any[]>(`${this.baseReg}/get-autorizaciones`, body);
	}

	/** Directivas activas por gestión; si no hay en BD, el backend devuelve las del pensum. */
	getDirectivasGestion(gestion: string, codPensum: string): Observable<any[]> {
		return this.http.post<any[]>(`${this.baseReg}/get-directivas`, { gestion, cod_pensum: codPensum });
	}

	perteneceDirectiva(
		factura: number,
		autorizacion: string,
		gestion?: string,
		codPensum?: string,
	): Observable<string> {
		const body: Record<string, unknown> = { factura, autorizacion };
		if (gestion) body['gestion'] = gestion;
		if (codPensum) body['cod_pensum'] = codPensum;
		return this.http.post(`${this.baseReg}/pertenece-directiva`, body, { responseType: 'text' });
	}

	facturaExiste(factura: number, autorizacion: string): Observable<string> {
		return this.http.post(`${this.baseReg}/factura-existe`, { factura, autorizacion }, { responseType: 'text' });
	}

	reciboExiste(recibo: number): Observable<string> {
		return this.http.post(`${this.baseReg}/recibo-existe`, { recibo }, { responseType: 'text' });
	}

	registrar(payload: Record<string, unknown>): Observable<any> {
		return this.http.post(`${this.baseReg}/registrar`, payload);
	}

	/** GET del PDF por URL firmada (tras registrar; mismo origen que `apiUrl` + Bearer si aplica). */
	downloadNotaPdfSignedUrl(absoluteUrl: string): Observable<Blob> {
		return this.http.get(absoluteUrl, { responseType: 'blob' });
	}

	getModInitial(): Observable<any> {
		return this.http.get(`${this.baseMod}/initial`);
	}

	buscar(documento: string): Observable<any> {
		return this.http.post(`${this.baseMod}/buscar`, { documento });
	}

	eliminar(id: number): Observable<any> {
		return this.http.post(`${this.baseMod}/eliminar`, { id });
	}

	registrarMod(payload: Record<string, unknown>): Observable<any> {
		return this.http.post(`${this.baseMod}/registrar-mod`, payload);
	}
}
