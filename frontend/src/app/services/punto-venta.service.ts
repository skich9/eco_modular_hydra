import { Injectable, Inject, PLATFORM_ID } from '@angular/core';
import { isPlatformBrowser } from '@angular/common';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../environments/environment';

export interface TipoPuntoVenta {
	codigo_clasificador: number;
	descripcion: string;
}

export interface PuntoVenta {
	codigo_punto_venta: number;
	nombre: string;
	descripcion?: string;
	sucursal: number;
	codigo_cuis_genera?: string;
	id_usuario_crea?: string;
	tipo: number;
	ip?: string;
	activo: boolean;
	fecha_creacion?: string;
	crear_cufd?: boolean;
	autocrear_cufd?: boolean;
	codigo_ambiente: number;
}

export interface CreatePuntoVentaRequest {
	codigo_ambiente: number;
	codigo_sucursal: number;
	codigo_tipo_punto_venta: number;
	nombre_punto_venta: string;
	descripcion: string;
	id_usuario: number;
	codigo_punto_venta: number;
}

export interface Usuario {
	id_usuario: number;
	nombre: string;
	ap_materno: string;
	nickname?: string;
}

export interface AssignUserRequest {
	id_usuario: number;
	codigo_punto_venta: string | number;
	codigo_sucursal: number;
	vencimiento_asig: string | null;
	usuario_crea: number;
}

export interface AsignacionPuntoVenta {
	id: number;
	id_usuario: number;
	codigo_punto_venta: string;
	codigo_sucursal: number;
	codigo_ambiente: number;
	vencimiento_asig: string;
	activo: number;
	nickname: string;
	nombre: string;
	ap_materno: string;
}

export interface UpdateAsignacionRequest {
	vencimiento_asig: string | null;
	activo: number;
}

export interface MiSucursalAsignacion {
	codigo_sucursal: number;
	codigo_punto_venta: string;
	vencimiento_asig: string | null;
	nombre_punto_venta: string;
}

export interface MisSucursalesResponse {
	success: boolean;
	apoyoCobranzas: boolean;
	data: MiSucursalAsignacion[];
}

export interface ApiResponse<T> {
	success: boolean;
	data?: T;
	message?: string;
}

export interface CuisData {
	codigo_cuis: string;
	fecha_vigencia: string;
}

@Injectable({
	providedIn: 'root'
})
export class PuntoVentaService {
	private apiUrl: string;

	constructor(private http: HttpClient, @Inject(PLATFORM_ID) private platformId: Object) {
		if (isPlatformBrowser(this.platformId)) {
			const protocol = typeof window !== 'undefined' && window.location ? (window.location.protocol || 'http:') : 'http:';
			const host = typeof window !== 'undefined' && window.location ? (window.location.hostname || 'localhost') : 'localhost';
			const port = environment.apiPort || '8069';
			this.apiUrl = `${protocol}//${host}:${port}/api`;
			console.log('[PuntoVentaService] apiUrl:', this.apiUrl);
		} else {
			this.apiUrl = environment.apiUrl;
      console.log('esta ingresando al else');
		}
	}

	getTiposPuntoVenta(): Observable<ApiResponse<TipoPuntoVenta[]>> {
		return this.http.get<ApiResponse<TipoPuntoVenta[]>>(`${this.apiUrl}/sin/tipos-punto-venta`);
	}

	getPuntosVenta(codigoSucursal?: number, codigoAmbiente?: number): Observable<ApiResponse<PuntoVenta[]>> {
		let url = `${this.apiUrl}/sin/puntos-venta`;
		const params: string[] = [];

		if (codigoSucursal !== undefined) {
			params.push(`codigo_sucursal=${codigoSucursal}`);
		}
		if (codigoAmbiente !== undefined) {
			params.push(`codigo_ambiente=${codigoAmbiente}`);
		}

		if (params.length > 0) {
			url += '?' + params.join('&');
		}

		return this.http.get<ApiResponse<PuntoVenta[]>>(url);
	}

	createPuntoVenta(data: CreatePuntoVentaRequest): Observable<ApiResponse<any>> {
		return this.http.post<ApiResponse<any>>(`${this.apiUrl}/sin/puntos-venta`, data);
	}

	deletePuntoVenta(codigoPuntoVenta: number | string): Observable<ApiResponse<any>> {
		return this.http.delete<ApiResponse<any>>(`${this.apiUrl}/sin/puntos-venta/${codigoPuntoVenta}`);
	}

	getUsuarios(): Observable<ApiResponse<Usuario[]>> {
		return this.http.get<ApiResponse<Usuario[]>>(`${this.apiUrl}/sin/usuarios`);
	}

	assignUserToPuntoVenta(data: AssignUserRequest): Observable<ApiResponse<any>> {
		return this.http.post<ApiResponse<any>>(`${this.apiUrl}/sin/puntos-venta/assign-user`, data);
	}

	getAsignacionPuntoVenta(codigoPuntoVenta: string | number): Observable<ApiResponse<AsignacionPuntoVenta>> {
		return this.http.get<ApiResponse<AsignacionPuntoVenta>>(`${this.apiUrl}/sin/puntos-venta/${codigoPuntoVenta}/asignacion`);
	}

	updateAsignacionPuntoVenta(id: number, data: UpdateAsignacionRequest): Observable<ApiResponse<any>> {
		return this.http.put<ApiResponse<any>>(`${this.apiUrl}/sin/puntos-venta/asignacion/${id}`, data);
	}

	getMisSucursales(): Observable<MisSucursalesResponse> {
		return this.http.get<MisSucursalesResponse>(`${this.apiUrl}/sin/mis-sucursales`);
	}

	syncPuntosVenta(idUsuario: number): Observable<ApiResponse<any>> {
		return this.http.post<ApiResponse<any>>(`${this.apiUrl}/sin/sync/puntos-venta`, {
			id_usuario: idUsuario
		});
	}

	getStatus(codigoPuntoVenta: number = 0, codigoSucursal: number = 0): Observable<ApiResponse<{ cuis: CuisData; cufd: any; codigo_sucursal: number; codigo_punto_venta: number }>> {
		return this.http.get<ApiResponse<any>>(`${this.apiUrl}/sin/status?codigo_punto_venta=${codigoPuntoVenta}&codigo_sucursal=${codigoSucursal}`);
	}
}
