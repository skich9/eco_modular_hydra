import { Injectable, Inject, PLATFORM_ID } from '@angular/core';
import { isPlatformBrowser } from '@angular/common';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable, catchError, switchMap, of, forkJoin } from 'rxjs';
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
			// const protocol = typeof window !== 'undefined' && window.location ? (window.location.protocol || 'http:') : 'http:';
			// const host = typeof window !== 'undefined' && window.location ? (window.location.hostname || 'localhost') : 'localhost';
			// const port = environment.apiPort || '8069';
			// this.apiUrl = `${protocol}//${host}:${port}/api`;
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
		// Usar endpoint local para obtener usuarios
		const url = `${this.apiUrl}/usuarios`;

		return this.http.get<any>(url).pipe(
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
			catchError(() => {
				// Si el endpoint /usuarios no funciona, devolver usuarios por defecto
				return of({
					success: true,
					data: [
						{ id_usuario: '1', nombre: 'Administrador', nickname: 'Admin' },
						{ id_usuario: '2', nombre: 'Cajero', nickname: 'Cajero' }
					]
				});
			})
		);
	}

	/**
	 * Obtiene los datos del libro diario para un usuario.
	 * Soporta tanto una sola fecha como un rango de fechas.
	 */
	getLibroDiario(request: LibroDiarioRequest): Observable<LibroDiarioResponse> {
		// Normalizar rango de fechas al formato Y-m-d (inclusive)
		const { fechaDesde, fechaHasta } = this.normalizarRangoFechas(request);
		const codigoCarrera = request.codigo_carrera || '';

		// Primero obtener el id_usuario del nombre de usuario seleccionado
		return this.obtenerIdUsuario(request.usuario).pipe(
			switchMap((idUsuario: string) => {
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

				const baseParams: any = {
					id_usuario: idUsuario,
					fecha: fechaDesde || fechaHasta || ''
				};
				if (codigoCarrera) baseParams.codigo_carrera = codigoCarrera;

				// Obtener datos en paralelo (solo endpoints necesarios, sin toda la BD)
				return forkJoin({
					cobro: this.http.get<any>(`${this.apiUrl}/cobros`, {
						params: baseParams
					}),
					facturas: this.http.get<any>(`${this.apiUrl}/facturas`, {
						params: {
							...baseParams,
							per_page: 100,
							anio: fechaDesde ? fechaDesde.substring(0, 4) : new Date().getFullYear()
						}
					}),
					transactions: this.http.get<any>(`${this.apiUrl}/qr/transactions`, {
						params: {
							id_usuario: idUsuario,
							desde: fechaDesde || fechaHasta || '',
							hasta: fechaHasta || fechaDesde || fechaHasta || '',
							...(codigoCarrera ? { codigo_carrera: codigoCarrera } : {})
						}
					}),
					recibos: this.http.get<any>(`${this.apiUrl}/recibos`).pipe(
						catchError(() =>
							this.http.get<any>(`${this.apiUrl}/recibo`).pipe(
								catchError(() =>
									this.http.get<any>(`${this.apiUrl}/voucher`).pipe(
										catchError(() => of([]))
									)
								)
							)
						)
					)
				}).pipe(
					map(({ cobro, facturas, transactions, recibos }) => {
						const datosCobro = cobro?.data || cobro || [];
						const datosFacturas = facturas?.data || facturas || [];
						const datosTransactions = transactions?.data?.items || transactions?.items || [];
						const todosLosRecibos = recibos?.data || recibos || [];
						const esDelUsuario = (registro: any): boolean => {
							const id = String(idUsuario || '').trim();
							if (!id) return true;
							const candidatos = [
								registro?.id_usuario,
								registro?.usuario_id,
								registro?.cod_usuario,
								registro?.usuario?.id_usuario
							]
								.filter((v: any) => v !== undefined && v !== null && String(v).trim() !== '')
								.map((v: any) => String(v).trim());
							// Si no trae campo de usuario, no bloquear para mantener compatibilidad de fuentes legadas.
							if (candidatos.length === 0) return true;
							return candidatos.includes(id);
						};

						// Mapa de facturas (usamos facturas filtradas por fecha - evita cargar toda la BD)
						const mapaFacturas = new Map();
						(datosFacturas || []).forEach((factura: any) => {
							if (factura.nro_factura && factura.cliente) {
								mapaFacturas.set(String(factura.nro_factura), {
									razon_social: factura.cliente, // Razón social desde factura
									nit: factura.nit || factura.nro_documento_cobro || '0' // NIT desde factura
								});
							}
						});

						// Crear mapa de recibos para buscar clientes por número de recibo
						const mapaRecibos = new Map();
						todosLosRecibos.forEach((recibo: any) => {
							if (recibo.nro_recibo && recibo.cliente) {
								mapaRecibos.set(String(recibo.nro_recibo), {
									razon_social: recibo.cliente, // Razón social desde recibo
									nit: recibo.nro_documento_cobro || '0' // NIT desde recibo
								});
							}
						});

						/** Montos por nro_factura: si el cobro trae `monto` 0/null, se usa el de la factura. */
						const mapaMontosFactura = new Map<string, number>();
						(datosFacturas || []).forEach((factura: any) => {
							if (!factura.nro_factura) {
								return;
							}
							const nro = String(factura.nro_factura);
							const m = this.parseMontoFlexible(
								factura.monto_total ?? factura.total ?? factura.importe ?? factura.monto
							);
							if (m > 0) {
								mapaMontosFactura.set(nro, m);
							}
						});

						const datosFacturasFiltrados = datosFacturas.filter((factura: any) => {
							const fechaFactura = factura.fecha_emision
								? this.fechaYmdLocal(factura.fecha_emision)
								: '';
							const nroFactura = factura.nro_factura ? String(factura.nro_factura) : '';
							// Solo considerar facturas con número válido (>0) para el libro diario
							if (!nroFactura || nroFactura === '0') {
								return false;
							}
							// Filtrar por rango de fechas si está disponible
							return this.estaEnRango(fechaFactura, fechaDesde, fechaHasta) && esDelUsuario(factura);
						});

						const datosCobroFiltrados = datosCobro.filter((cobro: any) => {
							const fechaCobro = cobro.fecha_cobro ? this.fechaYmdLocal(cobro.fecha_cobro) : '';
							return this.estaEnRango(fechaCobro, fechaDesde, fechaHasta) && esDelUsuario(cobro);
						});

						// Evitar duplicados: si una factura ya tiene un cobro asociado con el mismo nro_factura,
						// solo debe mostrarse la línea del cobro en el libro diario.
						const facturasConCobro = new Set<string>();
						datosCobroFiltrados.forEach((cobro: any) => {
							if (cobro.nro_factura && cobro.nro_factura !== '0') {
								facturasConCobro.add(String(cobro.nro_factura));
							}
						});

						const datosFacturasSinCobro = datosFacturasFiltrados.filter((factura: any) => {
							const nro = factura.nro_factura ? String(factura.nro_factura) : '';
							// Nunca incluir facturas sin número en el libro diario
							if (!nro || nro === '0') {
								return false;
							}
							return !facturasConCobro.has(nro);
						});

						const datosTransactionsFiltrados = datosTransactions.filter((transaction: any) => {
							const fechaTransaction = transaction.fecha_generacion
								? this.fechaYmdLocal(transaction.fecha_generacion)
								: '';
							return this.estaEnRango(fechaTransaction, fechaDesde, fechaHasta) && esDelUsuario(transaction);
						});

						const todasLasTransacciones = [
							// Usar solo facturas que no tienen un cobro asociado para evitar filas duplicadas
							...datosFacturasSinCobro.map((item: any) => ({ ...item, fuente: 'factura' })),
							...datosTransactionsFiltrados.map((item: any) => ({ ...item, fuente: 'transaction' })),
							...datosCobroFiltrados.map((item: any) => ({ ...item, fuente: 'cobro' }))
						];

						// Transformar al formato del libro diario
						const items: LibroDiarioItem[] = todasLasTransacciones.map((trans: any, index: number): LibroDiarioItem => {
							// Mapeo específico según la fuente de datos
							if (trans.fuente === 'factura') {
								// Buscar datos del cliente en el mapa de facturas
								let datosCliente = null;
								if (trans.nro_factura && trans.nro_factura !== '0') {
									datosCliente = mapaFacturas.get(String(trans.nro_factura));
								}

								// Si no encuentra, usar datos directos de la factura
								if (!datosCliente) {
									datosCliente = {
										razon_social: trans.cliente || trans.nombre_cliente || 'SIN DATOS',
										nit: trans.nro_documento_cobro || trans.nit || '0'
									};
								}

								// Determinar tipo_pago para la factura según su método de pago
								// Usar los mismos códigos que en cobros: D Deposito, E Efectivo, T Traspaso, C Cheque, L Tarjeta, B Transferencia, O Otro
								let tipoPagoFactura = 'E';
								const idFormaFactura = (trans.id_forma_cobro || '').toString().toUpperCase();
								if (idFormaFactura) {
									// Mapear códigos de forma de cobro de factura a los códigos del libro diario
									if (idFormaFactura === 'E' || idFormaFactura === 'EF' || idFormaFactura.includes('EFECT')) {
										tipoPagoFactura = 'E'; // Efectivo
									} else if (idFormaFactura === 'L' || idFormaFactura === 'TA' || idFormaFactura.includes('TARJ')) {
										tipoPagoFactura = 'L'; // Tarjeta
									} else if (idFormaFactura === 'D' || idFormaFactura === 'DE' || idFormaFactura.includes('DEPOS')) {
										tipoPagoFactura = 'D'; // Depósito
									} else if (idFormaFactura === 'C' || idFormaFactura === 'CH' || idFormaFactura.includes('CHEQ')) {
										tipoPagoFactura = 'C'; // Cheque
									} else if (idFormaFactura === 'B' || idFormaFactura === 'TR' || idFormaFactura.includes('TRANS') || idFormaFactura.includes('BANC')) {
										tipoPagoFactura = 'B'; // Transferencia bancaria
									} else if (idFormaFactura === 'T' || idFormaFactura.includes('TRASP')) {
										tipoPagoFactura = 'T'; // Traspaso
									} else {
										tipoPagoFactura = 'O'; // Otro
									}
								} else {
									// Fallback: intentar deducir desde un texto de método de pago si existiera
									const metodoFactura = (trans.metodo_pago || '').toString().toUpperCase();
									if (metodoFactura.includes('EFECTIVO')) {
										tipoPagoFactura = 'E';
									} else if (metodoFactura.includes('TARJETA')) {
										tipoPagoFactura = 'L';
									} else if (metodoFactura.includes('DEPOSITO') || metodoFactura.includes('DEPÓSITO')) {
										tipoPagoFactura = 'D';
									} else if (metodoFactura.includes('CHEQUE')) {
										tipoPagoFactura = 'C';
									} else if (metodoFactura.includes('TRANSFERENCIA') || metodoFactura.includes('BANCARIA')) {
										tipoPagoFactura = 'B';
									} else if (metodoFactura.includes('TRASPASO')) {
										tipoPagoFactura = 'T';
									} else if (metodoFactura) {
										// Si tiene algún texto pero no coincide con los anteriores, marcar como Otro
										tipoPagoFactura = 'O';
									}
								}

								return {
									numero: index + 1,
									recibo: '0', // Las facturas no tienen recibo
									factura: String(trans.nro_factura || '0'),
									concepto: 'Factura',
									razon: datosCliente?.razon_social || 'SIN DATOS',
									nit: datosCliente?.nit || '0',
									cod_ceta: trans.cod_ceta || '0',
									hora: this.horaHmsLocal(trans.fecha_emision),
									ingreso: this.parseMontoFlexible(
										trans.monto_total ?? trans.total ?? trans.monto ?? trans.importe
									),
									egreso: 0,
									tipo_doc: 'F',
									tipo_pago: tipoPagoFactura,
									observaciones: trans.metodo_pago || 'Efectivo'
								} as LibroDiarioItem;
							} else if (trans.fuente === 'cobro') {
								// Lógica mejorada: usar datos directos del cobro como lo hace el kardex
								let datosCliente = null;

								// PRIORIDAD 1: Si tiene nro_factura, buscar en tabla factura
								if (trans.nro_factura && trans.nro_factura !== '0') {
									datosCliente = mapaFacturas.get(String(trans.nro_factura));
								}
								// PRIORIDAD 2: Si tiene nro_recibo, buscar en tabla recibo
								else if (trans.nro_recibo && trans.nro_recibo !== '0') {
									// Buscar en el mapa de recibos desde la base de datos
									datosCliente = mapaRecibos.get(String(trans.nro_recibo));

									// Si no encuentra, intentar búsqueda en cobros
									if (!datosCliente) {
										// Primero buscar en cobros filtrados del mismo día
										const cobroConRecibo = datosCobroFiltrados.find((cobro: any) => cobro.nro_recibo == trans.nro_recibo);

										// Si no encuentra en cobros del día, buscar en todos los cobros
										let cobroGlobal = null;
										if (!cobroConRecibo) {
											cobroGlobal = datosCobro.find((cobro: any) => cobro.nro_recibo == trans.nro_recibo);
										}

										const cobroEncontrado = cobroConRecibo || cobroGlobal;
										if (cobroEncontrado && cobroEncontrado.nro_factura && cobroEncontrado.nro_factura !== '0') {
											datosCliente = mapaFacturas.get(String(cobroEncontrado.nro_factura));
										}
									}
								}
								// PRIORIDAD 3: Usar datos directos del cobro (como lo hace el kardex)
								else if (trans.cliente || trans.nro_documento_cobro) {
									datosCliente = this.getRazonSocialNIT(trans);
								}

								// Si no encuentra en ninguna tabla, intentar obtener datos del estudiante
								if (!datosCliente && trans.cod_ceta && trans.cod_ceta !== '0') {
									// Usar el servicio de cobros para obtener datos del estudiante
									// NOTA: Esto debe ser síncrono para el procesamiento actual
									// Por ahora, usaremos datos básicos del estudiante
									datosCliente = {
										razon_social: `Estudiante ${trans.cod_ceta}`,
										nit: trans.cod_ceta || '0'
									};
								}

								// Si no encuentra en ninguna tabla, dejar campos vacíos
								if (!datosCliente) {
									datosCliente = {
										razon_social: '', // Campo vacío
										nit: '' // Campo vacío
									};
								}

								// Determinar tipo_pago basado en id_forma_cobro
								// Códigos oficiales:
								// D Deposito, E Efectivo, T Traspaso, C Cheque, L Tarjeta, B Transferencia, O Otro
								let tipoPago = 'E'; // Por defecto Efectivo
								if (trans.id_forma_cobro) {
									const formaCobro = String(trans.id_forma_cobro).toUpperCase();
									// Mapear los mismos códigos que en facturas:
									//  - EF, E, "EFECTIVO" -> E (Efectivo)
									//  - TC, L, "TARJ"    -> L (Tarjeta)
									//  - D, DE, "DEPOS"   -> D (Depósito)
									//  - C, CH, "CHEQ"    -> C (Cheque)
									//  - B, TR, "TRANS"   -> B (Transferencia)
									//  - T, "TRASP"       -> T (Traspaso)
									if (formaCobro === 'E' || formaCobro === 'EF' || formaCobro.includes('EFECT')) {
										tipoPago = 'E'; // Efectivo
									} else if (formaCobro === 'L' || formaCobro === 'TC' || formaCobro.includes('TARJ')) {
										tipoPago = 'L'; // Tarjeta
									} else if (formaCobro === 'D' || formaCobro === 'DE' || formaCobro.includes('DEPOS')) {
										tipoPago = 'D'; // Depósito
									} else if (formaCobro === 'C' || formaCobro === 'CH' || formaCobro.includes('CHEQ')) {
										tipoPago = 'C'; // Cheque
									} else if (formaCobro === 'B' || formaCobro === 'TR' || formaCobro.includes('TRANS') || formaCobro.includes('BANC')) {
										tipoPago = 'B'; // Transferencia bancaria
									} else if (formaCobro === 'T' || formaCobro.includes('TRASP')) {
										tipoPago = 'T'; // Traspaso
									} else {
										tipoPago = 'O'; // Otro para cualquier forma no estándar
									}
								}

								// Construir observaciones extendidas similares al kardex
								const observacionesExt = this.getObservacionesExtendidasLibroDiario(trans);

								return {
									numero: index + 1,
									recibo: String(trans.nro_recibo || '0'),
									factura: String(trans.nro_factura || '0'),
									concepto: trans.concepto || trans.observaciones || 'Cobro',
									razon: datosCliente?.razon_social || '',
									nit: datosCliente?.nit || '',
									cod_ceta: String(trans.cod_ceta || '0'),
									hora: this.horaHmsLocal(trans.fecha_cobro),
									ingreso: this.montoIngresoCobro(trans, mapaMontosFactura),
									egreso: 0,
									tipo_doc: ((): string => {
										const raw = (trans.tipo_documento || '').toString().toUpperCase();
										if (raw === 'F' || raw === 'R') { return raw; }
										// Fallback: si tiene nro_factura>0, tratar como Factura; caso contrario como Recibo
										const nroFac = String(trans.nro_factura || '0');
										if (nroFac && nroFac !== '0') { return 'F'; }
										return 'R';
									})(),
									tipo_pago: tipoPago,
									observaciones: observacionesExt || (trans.forma_cobro?.nombre || trans.id_forma_cobro || 'Efectivo')
								} as LibroDiarioItem;
							} else if (trans.fuente === 'transaction') {
								// Buscar datos del cliente en el mapa de facturas primero
								let datosCliente = null;
								if (trans.nro_factura && trans.nro_factura !== '0') {
									datosCliente = mapaFacturas.get(String(trans.nro_factura));
								}

								// Si no encuentra en factura, usar datos directos de la transaction
								if (!datosCliente) {
									datosCliente = {
										razon_social: trans.cliente || trans.nombre_cliente || 'SIN DATOS',
										nit: trans.nro_documento_cobro || trans.nit || '0'
									};
								}

								return {
									numero: index + 1,
									recibo: String(trans.id_qr_transaccion || '0'),
									factura: String(trans.nro_factura || '0'),
									concepto: trans.detalle_glosa || 'Transacción QR',
									razon: datosCliente?.razon_social || 'SIN DATOS',
									nit: datosCliente?.nit || '0',
									cod_ceta: String(trans.cod_ceta || '0'),
									hora: this.horaHmsLocal(trans.fecha_generacion),
									ingreso: this.parseMontoFlexible(
										trans.monto_total ?? trans.total ?? trans.monto ?? trans.importe
									),
									egreso: 0,
									tipo_pago: trans.metodo_pago === 'TARJETA' ? 'L' : 'E', // L = Tarjeta, E = Efectivo
									tipo_doc: 'F',
									observaciones: trans.metodo_pago || 'Efectivo'
								} as LibroDiarioItem;
							} else {
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
								} as LibroDiarioItem;
							}
						}).filter(item => item !== undefined);

						// Calcular totales
						const totales = items.reduce((acc, item) => ({
							ingresos: acc.ingresos + item.ingreso,
							egresos: acc.egresos + item.egreso
						}), { ingresos: 0, egresos: 0 });

						// Hora apertura: primera transacción del día (hora más temprana); fallback 08:00:00
						const horasConValor = items.filter(i => i.hora && i.hora.trim() !== '').map(i => i.hora.trim());
						let horaApertura = horasConValor.length > 0
							? horasConValor.reduce((min, h) => (h < min ? h : min), horasConValor[0])
							: '08:00:00';
						if (horaApertura.length === 5 && horaApertura.match(/^\d{1,2}:\d{2}$/)) {
							horaApertura += ':00';
						}

						const result = {
							success: true,
							data: {
								datos: items,
								totales: totales,
								usuario_info: {
									nombre: request.usuario,
									hora_apertura: horaApertura,
									hora_cierre: ''
								}
							}
						} as LibroDiarioResponse;

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
					hora: fact.fecha_emision
						? this.horaHmsLocal(fact.fecha_emision)
						: fact.fecha
							? this.horaHmsLocal(fact.fecha)
							: '',
					ingreso: parseFloat(fact.total || fact.monto || fact.importe || 0),
					egreso: 0
				}));

				const totales = items.reduce((acc, item) => ({
					ingresos: acc.ingresos + item.ingreso,
					egresos: acc.egresos + item.egreso
				}), { ingresos: 0, egresos: 0 });

				const horasConValor = items.filter((i: LibroDiarioItem) => i.hora && i.hora.trim() !== '').map((i: LibroDiarioItem) => i.hora.trim());
				let horaApertura = horasConValor.length > 0
					? horasConValor.reduce((min: string, h: string) => (h < min ? h : min), horasConValor[0])
					: '08:00:00';
				if (horaApertura.length === 5 && /^\d{1,2}:\d{2}$/.test(horaApertura)) {
					horaApertura += ':00';
				}

				return {
					success: true,
					data: {
						datos: items,
						totales: totales,
						usuario_info: {
							nombre: nombreUsuario,
							hora_apertura: horaApertura,
							hora_cierre: ''
						}
					}
				} as LibroDiarioResponse;
			})
		);
	}

	/**
	 * Convierte un monto desde API (número o string con coma/punto) a número finito.
	 */
	private parseMontoFlexible(val: any): number {
		if (val === null || val === undefined || val === '') {
			return 0;
		}
		if (typeof val === 'number') {
			return Number.isFinite(val) ? val : 0;
		}
		const s = String(val).trim().replace(/\s/g, '');
		if (s === '') {
			return 0;
		}
		// Formato tipo 1.234,56 (miles con punto, decimal con coma)
		if (/^\d{1,3}(\.\d{3})*(,\d+)?$/.test(s)) {
			const n = parseFloat(s.replace(/\./g, '').replace(',', '.'));
			return Number.isFinite(n) ? n : 0;
		}
		const n2 = parseFloat(s.replace(',', '.'));
		return Number.isFinite(n2) ? n2 : 0;
	}

	/**
	 * Monto para filas de cobro: usa `cobro.monto`; si falta o es 0, la factura enlazada o el mapa del listado.
	 */
	private montoIngresoCobro(trans: any, mapaMontosFactura: Map<string, number>): number {
		let m = this.parseMontoFlexible(trans.monto);
		if (m > 0) {
			return m;
		}
		const nested = trans.factura;
		if (nested) {
			m = this.parseMontoFlexible(nested.monto_total ?? nested.total ?? nested.monto ?? nested.importe);
			if (m > 0) {
				return m;
			}
		}
		const nro = trans.nro_factura != null ? String(trans.nro_factura) : '';
		if (nro && nro !== '0') {
			const fromMap = mapaMontosFactura.get(nro);
			if (fromMap !== undefined && fromMap > 0) {
				return fromMap;
			}
		}
		return this.parseMontoFlexible(trans.monto);
	}

	/**
	 * Fecha calendario Y-m-d en la zona horaria **local** del navegador.
	 * No usar `substring(0, 10)` sobre ISO UTC: un cobro a las 21:10 local puede ser
	 * `...T01:10:00.000Z` del día siguiente en UTC y filtrarse mal.
	 */
	private fechaYmdLocal(raw: string | null | undefined): string {
		if (raw === null || raw === undefined) {
			return '';
		}
		const s = String(raw).trim();
		if (!s) {
			return '';
		}
		const d = new Date(s);
		if (!isNaN(d.getTime())) {
			const y = d.getFullYear();
			const m = String(d.getMonth() + 1).padStart(2, '0');
			const day = String(d.getDate()).padStart(2, '0');
			return `${y}-${m}-${day}`;
		}
		const m2 = s.match(/^(\d{4}-\d{2}-\d{2})/);
		return m2 ? m2[1] : '';
	}

	/** Hora HH:mm:ss en zona horaria local (coherente con `fechaYmdLocal`). */
	private horaHmsLocal(raw: string | null | undefined): string {
		if (raw === null || raw === undefined) {
			return '';
		}
		const s = String(raw).trim();
		if (!s) {
			return '';
		}
		const d = new Date(s);
		if (!isNaN(d.getTime())) {
			const hh = String(d.getHours()).padStart(2, '0');
			const mm = String(d.getMinutes()).padStart(2, '0');
			const ss = String(d.getSeconds()).padStart(2, '0');
			return `${hh}:${mm}:${ss}`;
		}
		const m = s.match(/(\d{1,2}):(\d{2}):(\d{2})/);
		if (m) {
			return `${m[1].padStart(2, '0')}:${m[2]}:${m[3]}`;
		}
		return '';
	}

	// Obtener solo el nombre del banco (sin número de cuenta) para libro diario
	private getBancoSoloNombreLibro(trans: any): string {
		try {
			const raw = (trans?.banco_nb || trans?.banco || '').toString().trim();
			if (!raw) return '';
			// En nota_bancaria se guarda como "BANCO X - 123456"; nos quedamos con la parte antes de " - "
			const partes = raw.split(' - ');
			return (partes[0] || raw).trim();
		} catch {
			return '';
		}
	}

	/**
	 * Normaliza un rango de fechas del request al formato ISO (Y-m-d).
	 * Si solo se recibe una fecha, se usa como inicio y fin.
	 */
	private normalizarRangoFechas(request: LibroDiarioRequest): { fechaDesde: string; fechaHasta: string } {
		const parseFecha = (valor?: string): string => {
			if (!valor) return '';
			// d/m/Y
			if (valor.includes('/')) {
				const partes = valor.split('/');
				if (partes.length === 3) {
					return `${partes[2]}-${partes[1]}-${partes[0]}`;
				}
				return valor;
			}
			// Y-m-d u otros formatos compatibles con Date
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

		// Si sólo hay una fecha válida, usarla como inicio y fin
		if (fechaDesde && !fechaHasta) {
			fechaHasta = fechaDesde;
		}
		if (!fechaDesde && fechaHasta) {
			fechaDesde = fechaHasta;
		}

		return { fechaDesde, fechaHasta };
	}

	/**
	 * Verifica si una fecha (Y-m-d) está dentro del rango [desde, hasta].
	 * Si el rango no está definido, siempre devuelve true.
	 */
	private estaEnRango(fecha: string, desde: string, hasta: string): boolean {
		if (!fecha) return false;
		if (!desde || !hasta) {
			// Si no hay rango definido, no filtrar por fecha
			return true;
		}
		// Asumimos que fecha, desde y hasta están en formato Y-m-d
		return fecha >= desde && fecha <= hasta;
	}

	// Obtener observaciones extendidas para libro diario según el método de pago (igual que kardex)
	private getObservacionesExtendidasLibroDiario(trans: any): string {
		let idFormaCobro = trans?.id_forma_cobro;
		const obsOriginal = trans?.observaciones || '';

		// Normalizar códigos antiguos de forma de cobro a los códigos nuevos
		let codigo = (idFormaCobro || '').toString().toUpperCase();
		switch (codigo) {
			case 'E':
				codigo = 'EF';
				break;
			case 'T':
				codigo = 'TA';
				break;
			case 'D':
				codigo = 'DE';
				break;
			case 'C':
				codigo = 'CH';
				break;
			case 'L':
			case 'TC':
				codigo = 'TA';
				break;
			case 'B':
				codigo = 'TR';
				break;
		}
		idFormaCobro = codigo;

		// Si es efectivo, y existen observaciones, usar formato "{Tipo de Pago}: {Observaciones}"
		if (idFormaCobro === 'EF') {
			const tipoPagoEf = trans.forma_cobro?.nombre || 'EFECTIVO';
			if (obsOriginal) {
				const obsTrim = obsOriginal.toString().trim();
				// Evitar duplicar si ya viene con el tipo de pago al inicio
				if (obsTrim.toUpperCase().startsWith(tipoPagoEf.toString().toUpperCase())) {
					return obsTrim;
				}
				return `${tipoPagoEf}: ${obsTrim}`;
			}
			return tipoPagoEf;
		}

		// Para otros métodos, concatenar información adicional
		let infoAdicional = '';

		switch (idFormaCobro) {
			case 'TA': // TARJETA
				// Tarjeta: {banco}-{nro_transaccion}-{fecha_deposito} NL:0 {observaciones}
				const bancoTarjeta = this.getBancoSoloNombreLibro(trans);
				const nroTransaccionTarjeta = (trans?.nro_transaccion || trans?.nro_deposito || '').toString();
				const fechaDepositoTarjeta = (trans?.fecha_deposito || trans?.fecha_nota || '').toString();
				const obsTarjeta = obsOriginal ? obsOriginal.toString().trim() : '';

				if (bancoTarjeta && nroTransaccionTarjeta && fechaDepositoTarjeta) {
					infoAdicional = `Tarjeta: ${bancoTarjeta}-${nroTransaccionTarjeta}-${fechaDepositoTarjeta} NL:0`;
					if (obsTarjeta) {
						infoAdicional += ` ${obsTarjeta}`;
					}
				} else {
					infoAdicional = `Tarjeta: ${trans?.nro_tarjeta || 'N/A'} - Autorización: ${trans?.nro_autorizacion || 'N/A'}`;
					if (obsTarjeta) {
						infoAdicional += ` ${obsTarjeta}`;
					}
				}
				break;
			case 'CH': // CHEQUE
				infoAdicional = `Cheque N°: ${trans?.nro_cheque || 'N/A'} - Banco: ${this.getBancoSoloNombreLibro(trans) || 'N/A'}`;
				break;
			case 'DE': // DEPOSITO
				// Deposito: {banco}-{nro_transaccion}-{fecha_deposito} ND:{correlativo} {observaciones}
				const bancoDeposito = this.getBancoSoloNombreLibro(trans);
				const nroDeposito = (trans?.nro_transaccion || trans?.nro_deposito || '').toString();
				const fechaDeposito = (trans?.fecha_deposito || trans?.fecha_nota || '').toString();
				let correlativoNd = (trans?.correlativo_nb || trans?.nro_referencia || '').toString();
				// Limpiar prefijos tipo "NB:", "ND:" o similares para no duplicar
				if (correlativoNd) {
					correlativoNd = correlativoNd.replace(/^N[BD][:\s]*/i, '').trim();
				}
				const obsDeposito = obsOriginal ? obsOriginal.toString().trim() : '';
				if (bancoDeposito && nroDeposito && fechaDeposito) {
					infoAdicional = correlativoNd
						? `Deposito: ${bancoDeposito}-${nroDeposito}-${fechaDeposito} ND:${correlativoNd}`
						: `Deposito: ${bancoDeposito}-${nroDeposito}-${fechaDeposito}`;
					if (obsDeposito) {
						infoAdicional += ` ${obsDeposito}`;
					}
				} else {
					infoAdicional = `Depósito - N° Cuenta: ${trans?.nro_cuenta || 'N/A'} - Banco: ${this.getBancoSoloNombreLibro(trans) || 'N/A'} - Referencia: ${trans?.nro_referencia || 'N/A'}`;
					if (obsDeposito) {
						infoAdicional += ` ${obsDeposito}`;
					}
				}
				break;
			case 'TR': // TRANSFERENCIA
				// Transferencia: {banco}-{nro_transaccion}-{fecha_deposito} NB:{correlativo} {observaciones}
				const bancoTransferencia = this.getBancoSoloNombreLibro(trans);
				const nroTransferencia = (trans?.nro_transaccion || trans?.nro_deposito || '').toString();
				const fechaTransferencia = (trans?.fecha_deposito || trans?.fecha_nota || '').toString();
				let correlativoNb = (trans?.correlativo_nb || trans?.nro_referencia || '').toString();
				// Limpiar prefijos tipo "NB:" o "NB " que puedan venir desde nro_referencia para no duplicar
				if (correlativoNb) {
					correlativoNb = correlativoNb.replace(/^NB[:\s]*/i, '').trim();
				}
				const obsTransferencia = obsOriginal ? obsOriginal.toString().trim() : '';

				if (bancoTransferencia && nroTransferencia && fechaTransferencia) {
					infoAdicional = correlativoNb
						? `Transferencia: ${bancoTransferencia}-${nroTransferencia}-${fechaTransferencia} NB:${correlativoNb}`
						: `Transferencia: ${bancoTransferencia}-${nroTransferencia}-${fechaTransferencia}`;
					if (obsTransferencia) {
						infoAdicional += ` ${obsTransferencia}`;
					}
				} else {
					infoAdicional = `Transferencia - N° Cuenta: ${trans?.nro_cuenta || 'N/A'} - Banco: ${this.getBancoSoloNombreLibro(trans) || 'N/A'} - Referencia: ${trans?.nro_referencia || 'N/A'}`;
					if (obsTransferencia) {
						infoAdicional += ` ${obsTransferencia}`;
					}
				}
				break;
			case 'QR': // QR
				infoAdicional = `QR - Código: ${trans?.codigo_qr || 'N/A'} - Fecha: ${trans?.fecha_qr || 'N/A'}`;
				break;
			case 'OT': // OTRO
				infoAdicional = `Otro: ${trans?.detalle_otro || 'N/A'}`;
				break;
		}

		// Combinar observaciones originales con información adicional
		// Regla:
		// - Si existe infoAdicional (formato bancario), mostrar SOLO ese texto para evitar duplicados.
		// - Si no hay infoAdicional pero hay observaciones, usar "{Tipo de Pago}: {Observaciones}".
		// - Si no hay nada, mostrar solo el nombre del método o el código.
		const tipoPago = trans.forma_cobro?.nombre || trans.id_forma_cobro || '';

		if (infoAdicional) {
			return infoAdicional;
		}

		if (obsOriginal) {
			const obsTrim = obsOriginal.toString().trim();
			if (tipoPago) {
				// Evitar duplicar si ya empieza con el tipo de pago
				if (obsTrim.toUpperCase().startsWith(tipoPago.toString().toUpperCase())) {
					return obsTrim;
				}
				return `${tipoPago}: ${obsTrim}`;
			}
			return obsTrim;
		}

		return tipoPago;
	}

	/**
	 * Método para obtener razón social/NIT desde cobro (igual que el kardex)
	 * Devuelve las claves razon_social y nit para ser compatible con el resto del código.
	 */
	private getRazonSocialNIT(cobro: any): { razon_social: string; nit: string } {
		// Usar cliente/nro_documento_cobro (campos que vendrán del backend)
		const cliente = cobro?.cliente || 'SIN DATOS';
		const nroDoc = cobro?.nro_documento_cobro || '0';

		return {
			razon_social: cliente,
			nit: nroDoc
		};
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
		// Usar endpoint local para cerrar caja
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
	}): Observable<{ success: boolean; url: string; message?: string }> {
		// Usar endpoint local para generar PDF
		const url = `${this.apiUrl}/reportes/libro-diario/imprimir`;

		return this.http.post<any>(url, request).pipe(
			map((res: any) => {
				// Soportar diferentes formatos de respuesta:
				// - string con URL directa
				// - { success: bool, url: string, message?: string }
				// - { url: string }
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


