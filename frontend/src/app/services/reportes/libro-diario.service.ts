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
	tipo_pago?: string; // E, L, etc.
	observaciones?: string; // Efectivo, Tarjeta, etc.
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
				
				// Obtener datos de los endpoints que existen
				return forkJoin({
					cobro: this.http.get<any>(`${this.apiUrl}/cobros`, {
						params: { id_usuario: idUsuario, fecha: fechaFormateada }
					}),
					facturas: this.http.get<any>(`${this.apiUrl}/facturas`, {
						params: { id_usuario: idUsuario, fecha: fechaFormateada }
					}),
					transactions: this.http.get<any>(`${this.apiUrl}/qr/transactions`, {
						params: { id_usuario: idUsuario, fecha: fechaFormateada }
					}),
					todasFacturas: this.http.get<any>(`${this.apiUrl}/facturas`), // Obtener todas las facturas para buscar clientes
					recibos: this.http.get<any>(`${this.apiUrl}/recibos`).pipe(
						catchError(error => {
							console.log('Endpoint /recibos no disponible, intentando /recibo:', error);
							// Intentar endpoint singular
							return this.http.get<any>(`${this.apiUrl}/recibo`).pipe(
								catchError(error2 => {
									console.log('Endpoint /recibo tampoco disponible, intentando /voucher:', error2);
									// Intentar otro nombre posible
									return this.http.get<any>(`${this.apiUrl}/voucher`).pipe(
										catchError(error3 => {
											console.log('Ningún endpoint de recibos disponible, usando array vacío:', error3);
											return of([]); // Devolver array vacío si ningún endpoint funciona
										})
									);
								})
							);
						})
					)
				}).pipe(
					map(({ cobro, facturas, transactions, todasFacturas, recibos }) => {
						const datosCobro = cobro?.data || cobro || [];
						const datosFacturas = facturas?.data || facturas || [];
						const datosTransactions = transactions?.data?.items || transactions?.items || [];
						const todasLasFacturas = todasFacturas?.data || todasFacturas || [];
						const todosLosRecibos = recibos?.data || recibos || [];
						
						// Crear mapa de facturas para buscar clientes por número de factura
						const mapaFacturas = new Map();
						todasLasFacturas.forEach((factura: any) => {
							if (factura.nro_factura && factura.cliente) {
								mapaFacturas.set(String(factura.nro_factura), {
									cliente: factura.cliente,
									nro_documento_cobro: factura.nro_documento_cobro || '0'
								});
							}
						});
						
						// Crear mapa de recibos para buscar clientes por número de recibo
						const mapaRecibos = new Map();
						todosLosRecibos.forEach((recibo: any) => {
							if (recibo.nro_recibo && recibo.cliente) {
								mapaRecibos.set(String(recibo.nro_recibo), {
									cliente: recibo.cliente,
									nro_documento_cobro: recibo.nro_documento_cobro || '0'
								});
							}
						});
						
						console.log('Mapa de facturas creado:', mapaFacturas);
						console.log('Mapa de recibos creado:', mapaRecibos);
						console.log('Datos de recibos recibidos:', todosLosRecibos);
						
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
						console.log('=== DATOS CRUDOS DE BACKEND ===');
						console.log('Facturas completas:', facturas);
						console.log('Cobros completos:', cobro);
						console.log('Transactions completas:', transactions);
						console.log('=== DATOS FILTRADOS ===');
						console.log('Datos de facturas:', datosFacturasFiltrados);
						console.log('Datos de cobros:', datosCobroFiltrados);
						console.log('Datos de transactions:', datosTransactionsFiltrados);
						
						// Mostrar estructura del primer elemento de cada tipo para depuración
						if (datosFacturasFiltrados.length > 0) {
							console.log('Estructura primera factura:', Object.keys(datosFacturasFiltrados[0]));
							console.log('Datos primera factura:', datosFacturasFiltrados[0]);
							// Buscar específicamente campos de cliente
							console.log('¿Tiene campo cliente?', 'cliente' in datosFacturasFiltrados[0]);
							console.log('¿Tiene campo nro_documento_cobro?', 'nro_documento_cobro' in datosFacturasFiltrados[0]);
						}
						if (datosCobroFiltrados.length > 0) {
							console.log('Estructura primer cobro:', Object.keys(datosCobroFiltrados[0]));
							console.log('Datos primer cobro:', datosCobroFiltrados[0]);
							// Buscar específicamente campos de cliente
							console.log('¿Tiene campo cliente?', 'cliente' in datosCobroFiltrados[0]);
							console.log('¿Tiene campo nro_documento_cobro?', 'nro_documento_cobro' in datosCobroFiltrados[0]);
							
							// Mostrar datos anidados que podrían tener el cliente
							const primerCobro = datosCobroFiltrados[0];
							console.log('Datos de usuario en cobro:', primerCobro.usuario);
							console.log('Datos de item_cobro en cobro:', primerCobro.item_cobro);
							console.log('Datos de cuota en cobro:', primerCobro.cuota);
							
							// Buscar campos posibles en objetos anidados
							if (primerCobro.usuario) {
								console.log('Campos en usuario:', Object.keys(primerCobro.usuario));
							}
							if (primerCobro.item_cobro) {
								console.log('Campos en item_cobro:', Object.keys(primerCobro.item_cobro));
							}
						}
						if (datosTransactionsFiltrados.length > 0) {
							console.log('Estructura primera transaction:', Object.keys(datosTransactionsFiltrados[0]));
							console.log('Datos primera transaction:', datosTransactionsFiltrados[0]);
							// Buscar específicamente campos de cliente
							console.log('¿Tiene campo cliente?', 'cliente' in datosTransactionsFiltrados[0]);
							console.log('¿Tiene campo nro_documento_cobro?', 'nro_documento_cobro' in datosTransactionsFiltrados[0]);
						}
						
						// Si no hay datos de facturas, mostrar la estructura cruda para ver qué hay
						if (todasLasFacturas && todasLasFacturas.length > 0) {
							console.log('Estructura primera factura cruda:', Object.keys(todasLasFacturas[0]));
							console.log('Datos primera factura cruda:', todasLasFacturas[0]);
							// Buscar específicamente campos de cliente en todas las facturas
							console.log('¿Tiene campo cliente en todas las facturas?', 'cliente' in todasLasFacturas[0]);
							console.log('¿Tiene campo nro_documento_cobro en todas las facturas?', 'nro_documento_cobro' in todasLasFacturas[0]);
							
							// Buscar otros campos posibles para el documento
							const posiblesCamposDocumento = ['nro_documento_cobro', 'nit', 'ci', 'documento', 'nro_documento'];
							posiblesCamposDocumento.forEach(campo => {
								if (campo in todasLasFacturas[0]) {
									console.log(`Campo encontrado: ${campo} =`, todasLasFacturas[0][campo]);
								}
							});
						}
						
						// Transformar al formato del libro diario
						const items: LibroDiarioItem[] = todasLasTransacciones.map((trans: any, index: number) => {
							// Mapeo específico según la fuente de datos
							if (trans.fuente === 'factura') {
								console.log('Factura:', trans);
								return {
									numero: index + 1,
									recibo: '0', // Las facturas no tienen recibo
									factura: String(trans.nro_factura || '0'),
									concepto: 'Factura',
									razon: trans.cliente || 'SIN DATOS',
									nit: trans.nro_documento_cobro || '0',
									cod_ceta: trans.cod_ceta || '0',
									hora: trans.fecha_emision ? new Date(trans.fecha_emision).toTimeString().substring(0, 8) : '',
									ingreso: parseFloat(trans.monto_total || 0),
									egreso: 0,
									tipo_pago: 'F', // F = Factura
									observaciones: trans.metodo_pago || 'Efectivo'
								};
							} else if (trans.fuente === 'cobro') {
								console.log('Cobro:', trans);
								
								// Lógica correcta: buscar en factura o recibo según corresponda
								let datosCliente = null;
								
								// Si tiene nro_factura, buscar en tabla factura
								if (trans.nro_factura && trans.nro_factura !== '0') {
									datosCliente = mapaFacturas.get(String(trans.nro_factura));
									console.log('Buscando en factura por nro_factura:', trans.nro_factura);
								}
								
								// Si no encuentra en factura, buscar en recibo por nro_recibo
								if (!datosCliente && trans.nro_recibo && trans.nro_recibo !== '0') {
									datosCliente = mapaRecibos.get(String(trans.nro_recibo));
									console.log('Buscando en recibo por nro_recibo:', trans.nro_recibo);
								}
								
								// Si no encuentra en ninguna tabla, dejar campos vacíos
								if (!datosCliente) {
									console.log('No se encontró cliente ni en factura ni en recibo. Campos quedarán vacíos.');
									datosCliente = {
										cliente: '', // Campo vacío
										nro_documento_cobro: '' // Campo vacío
									};
								}
								
								console.log('Datos de cliente para cobro:', datosCliente);
								
								return {
									numero: index + 1,
									recibo: String(trans.nro_recibo || '0'),
									factura: String(trans.nro_factura || '0'),
									concepto: trans.concepto || trans.observaciones || 'Cobro',
									razon: datosCliente?.cliente || '',
									nit: datosCliente?.nro_documento_cobro || '',
									cod_ceta: String(trans.cod_ceta || '0'),
									hora: trans.fecha_cobro ? new Date(trans.fecha_cobro).toTimeString().substring(0, 8) : '',
									ingreso: parseFloat(trans.monto || 0),
									egreso: 0,
									tipo_pago: 'E', // E = Efectivo
									observaciones: trans.forma_cobro?.nombre || 'Efectivo'
								};
							} else if (trans.fuente === 'transaction') {
								console.log('Transaction:', trans);
								// Los datos de transaction ya están en el nivel correcto
								return {
									numero: index + 1,
									recibo: String(trans.id_qr_transaccion || '0'),
									factura: String(trans.nro_factura || '0'),
									concepto: trans.detalle_glosa || 'Transacción QR',
									razon: trans.cliente || 'SIN DATOS',
									nit: trans.nro_documento_cobro || '0',
									cod_ceta: String(trans.cod_ceta || '0'),
									hora: trans.fecha_generacion ? new Date(trans.fecha_generacion).toTimeString().substring(0, 8) : '',
									ingreso: parseFloat(trans.monto_total || 0),
									egreso: 0,
									tipo_pago: trans.metodo_pago === 'TARJETA' ? 'L' : 'E', // L = Tarjeta, E = Efectivo
									observaciones: trans.metodo_pago || 'Efectivo'
								};
							}
							
							// Fallback por defecto
							return {
								numero: index + 1,
								recibo: '0',
								factura: '0',
								concepto: 'Desconocido',
								razon: 'SIN DATOS',
								nit: '0',
								cod_ceta: '0',
								hora: '',
								ingreso: 0,
								egreso: 0,
								tipo_pago: 'E',
								observaciones: ''
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

	
