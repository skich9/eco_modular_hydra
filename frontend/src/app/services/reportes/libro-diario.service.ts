import { Injectable, Inject, PLATFORM_ID } from '@angular/core';
import { isPlatformBrowser } from '@angular/common';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable, catchError, switchMap, of } from 'rxjs';
import { map } from 'rxjs/operators';
import { environment } from '../../../environments/environment';
import { CobrosService } from '../cobros.service';

export interface LibroDiarioRequest {
	usuario: string;
	/** Código de carrera para filtrar (ej: EEA, MEA). Requerido para mostrar solo una carrera. */
	codigo_carrera?: string;
	/**
	 * Fecha única en formato d/m/Y (compatibilidad hacia atrás).
	 * Si se especifica un rango, se priorizan fecha_inicio/fecha_fin.
	 */
	fecha?: string;
	/**
	 * Inicio de rango de fechas (d/m/Y).
	 */
	fecha_inicio?: string;
	/**
	 * Fin de rango de fechas (d/m/Y).
	 */
	fecha_fin?: string;
}

export interface Carrera {
	codigo_carrera: string;
	nombre: string;
	descripcion?: string;
}

export interface LibroDiarioItem {
	numero: number;
	recibo: string;
	factura: string;
	concepto: string;
	razon: string;
	nit: string;
	cod_ceta: string;
	hora: string;
	ingreso: number;
	egreso: number;
	tipo_doc?: string; // 'F' factura, 'R' recibo, u otro
	tipo_pago?: string; // E, L, etc.
	observaciones?: string; // Efectivo, Tarjeta, etc.
	/** Backend marca true si cod_tipo_cobro es MORA/RECARGO/INTERES/PENALIDAD/NIVELACION. */
	es_mora?: boolean;
	/** Monto de mora de la fila (igual a `ingreso` si `es_mora`, 0 en caso contrario). */
	monto_mora?: number;
}

export interface LibroDiarioResponse {
	success: boolean;
	data: {
		datos: LibroDiarioItem[];
		totales: {
			ingresos: number;
			egresos: number;
		};
		usuario_info: {
			nombre: string;
			hora_apertura: string;
			hora_cierre: string;
		};
		resumen?: Record<string, unknown>;
	};
	message?: string;
}

export interface Usuario {
	id_usuario: string;
	nombre?: string;
	nickname?: string;
	/** Activo en tabla `usuarios` (1 / true). */
	estado?: boolean | number | string;
}

@Injectable({
	providedIn: 'root'
})
export class LibroDiarioService {
	private apiUrl: string;

	constructor(
		private http: HttpClient,
		private cobrosService: CobrosService,
		@Inject(PLATFORM_ID) private platformId: Object
	) {
		if (isPlatformBrowser(this.platformId)) {
			this.apiUrl = environment.apiUrl;
		} else {
			this.apiUrl = environment.apiUrl;
		}
	}

	/**
	 * Obtiene la lista de carreras (Electrónica Automotriz, Mecánica Automotriz, etc.)
	 */
	getCarreras(): Observable<{ success: boolean; data: Carrera[]; message?: string }> {
		return this.http.get<any>(`${this.apiUrl}/carreras`).pipe(
			map((res: any) => ({
				success: true,
				data: res?.data || res || []
			})),
			catchError(() => of({ success: false, data: [] }))
		);
	}

	/**
	 * Obtiene la lista de usuarios activos del sistema local de cobros
	 */
	getUsuarios(): Observable<{ success: boolean; data: Usuario[]; message?: string }> {
		const url = `${this.apiUrl}/usuarios`;
		const params = new HttpParams().set('para', 'libro_diario');

		return this.http.get<any>(url, { params }).pipe(
			map((res: any) => {
				const usuarios = res?.data || res || [];
				const usuariosArray = Array.isArray(usuarios) ? usuarios : [];

				const activos = usuariosArray.filter((user: any) => {
					const e = user?.estado;
					return e === true || e === 1 || e === '1';
				});

				return {
					success: true,
					data: activos.map((user: any) => ({
						id_usuario: String(user.id_usuario ?? user),
						nombre: user.nombre || user.id_usuario || user,
						nickname: user.nickname || user.nombre || user.id_usuario || '',
						estado: user.estado
					}))
				};
			}),
			catchError(() => of({ success: false, data: [], message: 'Error al cargar usuarios' }))
		);
	}

	/**
	 * Obtiene los datos del Libro Diario desde el endpoint único del backend.
	 * El backend agrega cobros, facturas, QR, recibos y otros ingresos con deduplicación
	 * y cálculo de totales en una sola respuesta.
	 */
	getLibroDiario(request: LibroDiarioRequest): Observable<LibroDiarioResponse> {
		const { fechaDesde, fechaHasta } = this.normalizarRangoFechas(request);
		const codigoCarrera = request.codigo_carrera || '';

		return this.obtenerIdUsuario(request.usuario).pipe(
			switchMap((idUsuario: string) => {
				if (!idUsuario) {
					return of(this.respuestaVacia(request.usuario));
				}

				let params = new HttpParams()
					.set('id_usuario', idUsuario)
					.set('fecha_inicio', fechaDesde)
					.set('fecha_fin', fechaHasta);
				if (codigoCarrera) {
					params = params.set('codigo_carrera', codigoCarrera);
				}

				return this.http.get<any>(`${this.apiUrl}/reportes/libro-diario`, { params }).pipe(
					map((res: any) => {
						const data = res?.data || {};
						return {
							success: res?.success !== false,
							message: typeof res?.message === 'string' ? res.message : undefined,
							data: {
								datos: Array.isArray(data.datos) ? data.datos : [],
								totales: {
									ingresos: Number(data?.totales?.ingresos || 0),
									egresos: Number(data?.totales?.egresos || 0)
								},
								usuario_info: {
									nombre: String(data?.usuario_info?.nombre ?? request.usuario ?? ''),
									hora_apertura: String(data?.usuario_info?.hora_apertura ?? ''),
									hora_cierre: String(data?.usuario_info?.hora_cierre ?? '')
								},
								resumen: data?.resumen || {}
							}
						} as LibroDiarioResponse;
					}),
					catchError((err: any) => {
						const status = err?.status;
						const msg =
							err?.error?.message ||
							(typeof err?.error === 'string' ? err.error : '') ||
							'Error al obtener el libro diario';
						if (status === 403 || status === 401) {
							return of({
								success: false,
								message: msg,
								data: {
									datos: [],
									totales: { ingresos: 0, egresos: 0 },
									usuario_info: {
										nombre: String(request.usuario ?? ''),
										hora_apertura: '',
										hora_cierre: ''
									},
									resumen: {}
								}
							} as LibroDiarioResponse);
						}
						return of(this.respuestaVacia(request.usuario));
					})
				);
			}),
			catchError(() => of(this.respuestaVacia(request.usuario)))
		);
	}

	/**
	 * Obtiene el id_usuario a partir del nombre de usuario
	 */
	private obtenerIdUsuario(nombreUsuario: string): Observable<string> {
		const params = new HttpParams().set('para', 'libro_diario');
		return this.http.get<any>(`${this.apiUrl}/usuarios`, { params }).pipe(
			map((response: any) => {
				const usuarios = response?.data || response || [];
				const usuario = usuarios.find((u: any) => {
					const idUsuarioMatch = String(u.id_usuario) === String(nombreUsuario);
					const nombreMatch = u.nombre === nombreUsuario;
					const usernameMatch = u.username === nombreUsuario;

					return idUsuarioMatch || nombreMatch || usernameMatch;
				});

				let idUsuario = '';
				if (usuario) {
					idUsuario = String(usuario.id_usuario || '');
				} else {
					const usuarioPorId = usuarios.find((u: any) => String(u.id_usuario) === String(nombreUsuario));
					if (usuarioPorId) {
						idUsuario = String(nombreUsuario);
					}
				}

				return idUsuario;
			}),
			catchError(() => {
				const userMap: { [key: string]: string } = {
					'Administrador': '1',
					'admin': '1',
					'Admin': '1',
					'Cajero': '2',
					'cajero': '2'
				};

				let idUsuario = userMap[nombreUsuario] || '1';
				if (/^\d+$/.test(nombreUsuario)) {
					idUsuario = String(nombreUsuario);
				}

				return of(idUsuario);
			})
		);
	}

	private respuestaVacia(nombreUsuario: string): LibroDiarioResponse {
		return {
			success: true,
			data: {
				datos: [],
				totales: { ingresos: 0, egresos: 0 },
				usuario_info: { nombre: nombreUsuario, hora_apertura: '', hora_cierre: '' },
				resumen: {}
			}
		};
	}

	/**
	 * Normaliza un rango de fechas del request al formato ISO (Y-m-d).
	 * Si solo se recibe una fecha, se usa como inicio y fin.
	 */
	private normalizarRangoFechas(request: LibroDiarioRequest): { fechaDesde: string; fechaHasta: string } {
		const parseFecha = (valor?: string): string => {
			if (!valor) return '';
			if (valor.includes('/')) {
				const partes = valor.split('/');
				if (partes.length === 3) {
					return `${partes[2]}-${partes[1]}-${partes[0]}`;
				}
				return valor;
			}
			if (valor.includes('-')) {
				const partes = valor.split('-');
				if (partes.length === 3 && partes[0].length === 4) {
					return valor;
				}
			}
			const d = new Date(valor);
			if (!isNaN(d.getTime())) {
				const day = d.getDate().toString().padStart(2, '0');
				const month = (d.getMonth() + 1).toString().padStart(2, '0');
				const year = d.getFullYear();
				return `${year}-${month}-${day}`;
			}
			return '';
		};

		let fechaDesde = parseFecha(request.fecha_inicio || request.fecha);
		let fechaHasta = parseFecha(request.fecha_fin || request.fecha);

		if (fechaDesde && !fechaHasta) {
			fechaHasta = fechaDesde;
		}
		if (!fechaDesde && fechaHasta) {
			fechaDesde = fechaHasta;
		}

		return { fechaDesde, fechaHasta };
	}

	/**
	 * Cierra la caja del usuario (usando BD local de cobros).
	 * Retorna orden_cierre e id_libro_diario_cierre (correlativo único en BD) para el código RD-{carrera}-{mes}-{id}.
	 */
	cerrarCaja(request: LibroDiarioRequest): Observable<{
		success: boolean;
		message?: string;
		orden_cierre?: number;
		id_libro_diario_cierre?: number;
		correlativo?: number;
		correlativo_padded?: string;
		codigo_rd?: string;
		hora_cierre?: string;
	}> {
		const url = `${this.apiUrl}/cobros/cerrar-caja`;

		const body: Record<string, unknown> = {
			usuario: request.usuario,
			fecha: request.fecha
		};
		if (request.codigo_carrera) {
			body['codigo_carrera'] = request.codigo_carrera;
		}

		return this.http.post<any>(url, body).pipe(
			map((res: any) => ({
				success: res === 'exito' || res?.success,
				message: res === 'exito' ? 'Caja cerrada exitosamente' : res?.message || 'Error al cerrar caja',
				orden_cierre: typeof res?.orden_cierre === 'number' ? res.orden_cierre : undefined,
				id_libro_diario_cierre:
					typeof res?.id_libro_diario_cierre === 'number' ? res.id_libro_diario_cierre : undefined,
				correlativo:
					typeof res?.correlativo === 'number'
						? res.correlativo
						: typeof res?.correlativo === 'string' && /^\d+$/.test(res.correlativo)
							? parseInt(res.correlativo, 10)
							: undefined,
				correlativo_padded:
					typeof res?.correlativo_padded === 'string' && res.correlativo_padded.trim() !== ''
						? res.correlativo_padded.trim()
						: undefined,
				codigo_rd:
					typeof res?.codigo_rd === 'string' && res.codigo_rd.trim() !== '' ? res.codigo_rd.trim() : undefined,
				hora_cierre: typeof res?.hora_cierre === 'string' && res.hora_cierre.trim() !== '' ? res.hora_cierre.trim() : undefined
			})),
			catchError((err: any) => {
				const status = err?.status;
				const backendMsg =
					err?.error?.message ||
					(typeof err?.error === 'string' ? err.error : '') ||
					err?.message ||
					'Error desconocido';
				return of({
					success: false,
					message: `Error HTTP${status ? ' ' + status : ''} al cerrar caja (${url}). ${backendMsg}`,
					orden_cierre: undefined,
					id_libro_diario_cierre: undefined,
					correlativo: undefined,
					correlativo_padded: undefined,
					codigo_rd: undefined,
					hora_cierre: undefined
				});
			})
		);
	}

	/**
	 * Genera el PDF del libro diario (usando BD local de cobros)
	 * request.resumen: totales Factura/Recibo por método para plantilla SGA
	 */
	imprimirLibroDiario(request: {
		contenido: string;
		datos?: LibroDiarioItem[];
		usuario: string;
		usuario_display?: string;
		fecha: string;
		t_ingresos: string;
		t_egresos: string;
		totales: string;
		resumen?: Record<string, unknown>;
		/** Filas de datos por página del PDF (5–80 en backend). */
		filas_por_pagina?: number;
	}): Observable<{ success: boolean; url: string; message?: string }> {
		const url = `${this.apiUrl}/reportes/libro-diario/imprimir`;

		return this.http.post<any>(url, request).pipe(
			map((res: any) => {
				if (typeof res === 'string') {
					return {
						success: !!res,
						url: res,
						message: res ? 'PDF generado exitosamente' : 'Error al generar PDF'
					};
				}
				if (res && typeof res === 'object') {
					const success = res.success !== undefined ? !!res.success : !!res.url;
					return {
						success,
						url: res.url || '',
						message: res.message || (success ? 'PDF generado exitosamente' : 'Error al generar PDF')
					};
				}
				return {
					success: false,
					url: '',
					message: 'Respuesta vacía o no válida al generar PDF'
				};
			})
		);
	}
}
