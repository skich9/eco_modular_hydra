import { Injectable, Inject, PLATFORM_ID } from '@angular/core';
import { isPlatformBrowser } from '@angular/common';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable, catchError, switchMap, of, forkJoin } from 'rxjs';
import { map } from 'rxjs/operators';
import { environment } from '../../../environments/environment';

export interface LibroDiarioRequest {
	usuario: string;
	fecha: string;
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
	};
	message?: string;
}

export interface Usuario {
	id_usuario: string;
	nombre?: string;
}

@Injectable({
	providedIn: 'root'
})
export class LibroDiarioService {
	private apiUrl: string;

	constructor(private http: HttpClient, @Inject(PLATFORM_ID) private platformId: Object) {
		if (isPlatformBrowser(this.platformId)) {
			const protocol = typeof window !== 'undefined' && window.location ? (window.location.protocol || 'http:') : 'http:';
			const host = typeof window !== 'undefined' && window.location ? (window.location.hostname || 'localhost') : 'localhost';
			const port = environment.apiPort || '8069';
			this.apiUrl = `${protocol}//${host}:${port}/api`;
		} else {
			this.apiUrl = environment.apiUrl;
		}
	}

	/**
	 * Obtiene la lista de usuarios activos del sistema local de cobros
	 */
	getUsuarios(): Observable<{ success: boolean; data: Usuario[]; message?: string }> {
		// Usar endpoint local para obtener usuarios
		const url = `${this.apiUrl}/usuarios`;
		
		return this.http.get<any>(url).pipe(
			map((res: any) => {
				const usuarios = res?.data || res || [];
				const usuariosArray = Array.isArray(usuarios) ? usuarios : [];
				
				return {
					success: true,
					data: usuariosArray.map((user: any) => ({
						id_usuario: user.id_usuario || user,
						nombre: user.nombre || user.id_usuario || user
					}))
				};
			}),
			catchError(() => {
				// Si el endpoint /usuarios no funciona, devolver usuarios por defecto
				return of({
					success: true,
					data: [
						{ id_usuario: '1', nombre: 'Administrador' },
						{ id_usuario: '2', nombre: 'Cajero' }
					]
				});
			})
		);
	}

	/**
	 * Obtiene los datos del libro diario para un usuario y fecha específicos (usando BD local de cobros)
	 */
	getLibroDiario(request: LibroDiarioRequest): Observable<LibroDiarioResponse> {
		// Convertir fecha del formato d/m/Y a Y-m-d para la API local
		let fechaFormateada = '';
		if (typeof request.fecha === 'string') {
			const fechaArray = request.fecha.split('/');
			if (fechaArray.length === 3) {
				fechaFormateada = `${fechaArray[2]}-${fechaArray[1]}-${fechaArray[0]}`;
			} else {
				fechaFormateada = request.fecha;
			}
		} else {
			fechaFormateada = String(request.fecha);
		}
		
		// Primero obtener el id_usuario del nombre de usuario seleccionado
		return this.obtenerIdUsuario(request.usuario).pipe(
			switchMap(idUsuario => {
				if (!idUsuario) {
					// Si no se encuentra el id_usuario, devolver estructura vacía
					return of({
						success: true,
						data: {
							datos: [],
							totales: { ingresos: 0, egresos: 0 },
							usuario_info: { nombre: request.usuario, hora_apertura: '', hora_cierre: '' }
						}
					} as LibroDiarioResponse);
				}
				
				// Obtener datos de los endpoints que existen y tabla cobro con diferentes nombres
				return forkJoin({
					facturas: this.http.get<any>(`${this.apiUrl}/facturas`, {
						params: { id_usuario: idUsuario, fecha: fechaFormateada }
					}),
					transactions: this.http.get<any>(`${this.apiUrl}/qr/transactions`, {
						params: { id_usuario: idUsuario, fecha: fechaFormateada }
					}),
					cobro: this.http.get<any>(`${this.apiUrl}/cobros`, {
						params: { id_usuario: idUsuario, fecha: fechaFormateada }
					})
				}).pipe(
					map(({ facturas, transactions, cobro }) => {
						const datosFacturas = facturas?.data || facturas || [];
						const datosTransactions = transactions?.data?.items || transactions?.items || [];
						const datosCobro = cobro?.data || cobro || [];
						
						const datosFacturasFiltrados = datosFacturas.filter((factura: any) => {
							const fechaFactura = factura.fecha_emision ? factura.fecha_emision.split(' ')[0] : '';
							return fechaFactura === fechaFormateada;
						});
						
						const datosCobroFiltrados = datosCobro.filter((cobro: any) => {
							let fechaCobro = '';
							if (cobro.fecha_cobro) {
								fechaCobro = cobro.fecha_cobro.substring(0, 10);
							}
							return fechaCobro === fechaFormateada;
						});
						
						const datosTransactionsFiltrados = datosTransactions.filter((transaction: any) => {
							const fechaTransaction = transaction.fecha_generacion ? transaction.fecha_generacion.split(' ')[0] : '';
							return fechaTransaction === fechaFormateada;
						});
						
						const todasLasTransacciones = [
							...datosFacturasFiltrados.map((item: any) => ({ ...item, fuente: 'factura' })),
							...datosTransactionsFiltrados.map((item: any) => ({ ...item, fuente: 'transaction' })),
							...datosCobroFiltrados.map((item: any) => ({ ...item, fuente: 'cobro' }))
						];
						
						console.log('Transacciones combinadas:', todasLasTransacciones.length);
						
						// Transformar al formato del libro diario
						const items: LibroDiarioItem[] = todasLasTransacciones.map((trans: any, index: number) => {
							// Mapeo específico según la fuente de datos
							if (trans.fuente === 'factura') {
								return {
									numero: index + 1,
									recibo: '0', // Las facturas no tienen recibo
									factura: String(trans.nro_factura || '0'),
									concepto: 'Factura',
									razon: trans.cliente || '',
									nit: '0', // Las facturas no tienen NIT en los datos mostrados
									cod_ceta: trans.cod_ceta || '0',
									hora: trans.fecha_emision ? new Date(trans.fecha_emision).toTimeString().substring(0, 8) : '',
									ingreso: 0, // Las facturas no tienen monto en los datos mostrados
									egreso: 0
								};
							} else if (trans.fuente === 'cobro') {
								return {
									numero: index + 1,
									recibo: String(trans.nro_recibo || '0'),
									factura: String(trans.nro_factura || '0'),
									concepto: trans.concepto || trans.observaciones || 'Cobro',
									razon: trans.usuario?.nombre || '',
									nit: trans.usuario?.ci || '0',
									cod_ceta: String(trans.cod_ceta || '0'),
									hora: trans.fecha_cobro ? new Date(trans.fecha_cobro).toTimeString().substring(0, 8) : '',
									ingreso: parseFloat(trans.monto || 0),
									egreso: 0
								};
							} else if (trans.fuente === 'transaction') {
								// Los datos de transaction ya están en el nivel correcto
								return {
									numero: index + 1,
									recibo: String(trans.id_qr_transaccion || '0'),
									factura: String(trans.nro_factura || '0'),
									concepto: trans.detalle_glosa || 'Transacción QR',
									razon: trans.nombre_cliente || '',
									nit: String(trans.documento_cliente || '0'),
									cod_ceta: String(trans.cod_ceta || '0'),
									hora: trans.fecha_generacion ? new Date(trans.fecha_generacion).toTimeString().substring(0, 8) : '',
									ingreso: parseFloat(trans.monto_total || 0),
									egreso: 0
								};
							}
							
							// Fallback por defecto
							return {
								numero: index + 1,
								recibo: '0',
								factura: '0',
								concepto: 'Desconocido',
								razon: '',
								nit: '0',
								cod_ceta: '0',
								hora: '',
								ingreso: 0,
								egreso: 0
							};
						});

						console.log('Items transformados:', items);

						// Calcular totales
						const totales = items.reduce((acc, item) => ({
							ingresos: acc.ingresos + item.ingreso,
							egresos: acc.egresos + item.egreso
						}), { ingresos: 0, egresos: 0 });

						console.log('Totales calculados:', totales);

						const result = {
							success: true,
							data: {
								datos: items,
								totales: totales,
								usuario_info: {
									nombre: request.usuario,
									hora_apertura: '08:00:00',
									hora_cierre: ''
								}
							}
						} as LibroDiarioResponse;
						
						console.log('Resultado final:', result);
						return result;
					}),
					catchError(() => {
						// Si todo falla, devolver estructura vacía
						return of({
							success: true,
							data: {
								datos: [],
								totales: { ingresos: 0, egresos: 0 },
								usuario_info: { nombre: request.usuario, hora_apertura: '', hora_cierre: '' }
							}
						} as LibroDiarioResponse);
					})
				);
			}),
			catchError(() => {
				// Si todo falla, devolver estructura vacía
				return of({
					success: true,
					data: {
						datos: [],
						totales: { ingresos: 0, egresos: 0 },
						usuario_info: { nombre: request.usuario, hora_apertura: '', hora_cierre: '' }
					}
				} as LibroDiarioResponse);
			})
		);
	}

	/**
	 * Obtiene el id_usuario a partir del nombre de usuario
	 */
	private obtenerIdUsuario(nombreUsuario: string): Observable<string> {
		return this.http.get<any>(`${this.apiUrl}/usuarios`).pipe(
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
			catchError((error) => {
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

	/**
	 * Obtiene facturas filtradas por id_usuario como alternativa
	 */
	private obtenerFacturasPorUsuario(idUsuario: string, fecha: string, nombreUsuario: string): Observable<LibroDiarioResponse> {
		return this.http.get<any>(`${this.apiUrl}/facturas`, {
			params: {
				id_usuario: idUsuario,
				fecha: fecha
			}
		}).pipe(
			map((response: any) => {
				const facturas = response?.data || response || [];
				
				const items: LibroDiarioItem[] = facturas.map((fact: any, index: number) => ({
					numero: index + 1,
					recibo: fact.nro_recibo || fact.recibo || '0',
					factura: fact.nro_factura || fact.factura || '0',
					concepto: fact.concepto || fact.descripcion || 'Factura',
					razon: fact.razon_social || fact.nombre_cliente || fact.cliente || '',
					nit: fact.nit || fact.ci || fact.documento || '0',
					cod_ceta: fact.cod_ceta || fact.ceta || '0',
					hora: fact.fecha_emision ? new Date(fact.fecha_emision).toTimeString().substring(0, 8) : 
						 fact.fecha ? new Date(fact.fecha).toTimeString().substring(0, 8) : '',
					ingreso: parseFloat(fact.total || fact.monto || fact.importe || 0),
					egreso: 0
				}));

				const totales = items.reduce((acc, item) => ({
					ingresos: acc.ingresos + item.ingreso,
					egresos: acc.egresos + item.egreso
				}), { ingresos: 0, egresos: 0 });

				return {
					success: true,
					data: {
						datos: items,
						totales: totales,
						usuario_info: {
							nombre: nombreUsuario,
							hora_apertura: '08:00:00',
							hora_cierre: ''
						}
					}
				} as LibroDiarioResponse;
			})
		);
	}

	/**
	 * Cierra la caja del usuario (usando BD local de cobros)
	 */
	cerrarCaja(request: LibroDiarioRequest): Observable<{ success: boolean; message?: string }> {
		// Usar endpoint local para cerrar caja
		const url = `${this.apiUrl}/cobros/cerrar-caja`;
		
		const body = {
			usuario: request.usuario,
			fecha: request.fecha
		};

		return this.http.post<any>(url, body).pipe(
			map((res: any) => ({
				success: res === 'exito' || res?.success,
				message: res === 'exito' ? 'Caja cerrada exitosamente' : res?.message || 'Error al cerrar caja'
			}))
		);
	}

	/**
	 * Genera el PDF del libro diario (usando BD local de cobros)
	 */
	imprimirLibroDiario(request: {
		contenido: string;
		usuario: string;
		fecha: string;
		t_ingresos: string;
		t_egresos: string;
		totales: string;
	}): Observable<{ success: boolean; url: string; message?: string }> {
		// Usar endpoint local para generar PDF
		const url = `${this.apiUrl}/reportes/libro-diario/imprimir`;
		
		return this.http.post<any>(url, request).pipe(
			map((res: any) => ({
				success: !!res,
				url: res || '',
				message: res ? 'PDF generado exitosamente' : 'Error al generar PDF'
			}))
		);
	}
}

	
