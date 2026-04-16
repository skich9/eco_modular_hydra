import { Component, Input, OnChanges, Pipe, PipeTransform } from '@angular/core';
import { CommonModule } from '@angular/common';

interface CobroItem {
    id_cobro?: number;
    id?: number;
    cod_tipo_cobro?: string;
    concepto?: string;
    monto?: string | number;
    fecha_cobro?: string;
    nro_cobro?: number | string;
    observaciones?: string;
    id_forma_cobro?: string;
    nro_factura?: string | null;
    nro_recibo?: string | number;
    cliente?: string;
    nro_documento_cobro?: string;
}

@Pipe({
    name: 'filterNonMensualidades',
    standalone: true
})
export class FilterNonMensualidadesPipe implements PipeTransform {
    transform(data: any): CobroItem[] {
        console.group('=== FILTRO MATERIAL EXTRA ===');

        if (!data) {
            console.log('No hay datos');
            console.groupEnd();
            return [];
        }

        // Extraer el array de items del objeto
        const items: CobroItem[] = Array.isArray(data) ? data : (data.items || []);

        console.log('Total de items a filtrar:', items.length);

        if (items.length === 0) {
            console.log('El array de items está vacío');
            console.groupEnd();
            return [];
        }

        console.log('Estructura del primer item (REINCORPORACION):', items[0] ? JSON.parse(JSON.stringify(items[0])) : 'No hay primer item');

        const filtered = items.filter((item: CobroItem, index: number) => {
            const isMaterialExtra = item?.cod_tipo_cobro === 'MATERIAL_EXTRA';

            if (isMaterialExtra) {
                console.log(`Item ${index} - MATERIAL EXTRA ENCONTRADO:`, {
                    id: item?.id_cobro || item?.id,
                    cod_tipo_cobro: item?.cod_tipo_cobro,
                    concepto: item?.concepto,
                    monto: item?.monto,
                    fecha_cobro: item?.fecha_cobro
                });
            }

            return isMaterialExtra;
        });

        console.log(`Total de items filtrados (MATERIAL_EXTRA): ${filtered.length} de ${items.length}`);

        if (filtered.length === 0) {
            console.warn('No se encontraron registros con cod_tipo_cobro = "MATERIAL_EXTRA"');
            console.log('Tipos de cobro encontrados:',
                [...new Set(items.map((item: CobroItem) => item?.cod_tipo_cobro))]
            );
        }

        console.groupEnd();
        return filtered;
    }
}

@Pipe({
    name: 'filterReincorporacion',
    standalone: true
})
export class FilterReincorporacionPipe implements PipeTransform {
    transform(data: any): CobroItem[] {
        console.group('=== FILTRO REINCORPORACION ===');

        if (!data) {
            console.log('No hay datos');
            console.groupEnd();
            return [];
        }

        // Extraer el array de items del objeto
        const items: CobroItem[] = Array.isArray(data) ? data : (data.items || []);

        console.log('Total de items a filtrar:', items.length);

        if (items.length === 0) {
            console.log('El array de items está vacío');
            console.groupEnd();
            return [];
        }

        console.log('Estructura del primer item (REINCORPORACION):', items[0] ? JSON.parse(JSON.stringify(items[0])) : 'No hay primer item');

        const filtered = items.filter((item: CobroItem, index: number) => {
            const isReincorporacion = item?.cod_tipo_cobro === 'REINCORPORACION';

            if (isReincorporacion) {
                console.log(`Item ${index} - REINCORPORACION ENCONTRADO:`, {
                    id: item?.id_cobro || item?.id,
                    cod_tipo_cobro: item?.cod_tipo_cobro,
                    concepto: item?.concepto,
                    monto: item?.monto,
                    fecha_cobro: item?.fecha_cobro,
                    nro_factura: item?.nro_factura,
                    nro_recibo: item?.nro_recibo,
                    cliente: item?.cliente,
                    nro_documento_cobro: item?.nro_documento_cobro,
                    todasLasPropiedades: Object.keys(item),
                    objetoCompleto: item
                });
            }

            return isReincorporacion;
        });

        console.log(`Total de items filtrados (REINCORPORACION): ${filtered.length} de ${items.length}`);

        if (filtered.length === 0) {
            console.warn('No se encontraron registros con cod_tipo_cobro = "REINCORPORACION"');
            console.log('Tipos de cobro encontrados:',
                [...new Set(items.map((item: CobroItem) => item?.cod_tipo_cobro))]
            );
        }

        console.groupEnd();
        return filtered;
    }
}

@Component({
	selector: 'app-kardex-modal',
	standalone: true,
	imports: [CommonModule, FilterNonMensualidadesPipe, FilterReincorporacionPipe],
	templateUrl: './kardex-modal.component.html',
	styleUrls: ['./kardex-modal.component.scss']
})
export class KardexModalComponent implements OnChanges {
	// Método para calcular el total de material extra
	calcularTotalMaterialExtra(items: any[]): number {
		if (!Array.isArray(items)) return 0;
		return items
			.filter(item => item?.cod_tipo_cobro === 'MATERIAL_EXTRA')
			.reduce((sum, item) => sum + (parseFloat(item?.monto) || 0), 0);
	}

	// Método para calcular el total de matrícula o reincorporación
	calcularTotalReincorporacion(items: any[]): number {
		if (!Array.isArray(items)) return 0;
		return items
			.filter(item => item?.cod_tipo_cobro === 'REINCORPORACION')
			.reduce((sum, item) => sum + (parseFloat(item?.monto) || 0), 0);
	}

	@Input() resumen: any = null;
	@Input() gestion: string = '';
	/**
	 * Si es true, en la cabecera solo se listan inscripciones/grupos de la gestión consultada
	 * (p. ej. tras vencer la fecha límite de descuento institucional — alineado con `mostrarBotonDescuento`).
	 */
	@Input() filtrarInscripcionesPorGestion = false;

	// Cache para pagos expandidos
	private _pagosExpandidosCache: any[] | null = null;
	private _lastResumenHash: string = '';

	// Limpiar cache cuando cambia el resumen
	ngOnChanges() {
		if (this.resumen) {
			const currentHash = JSON.stringify(this.resumen);
			if (currentHash !== this._lastResumenHash) {
				this._pagosExpandidosCache = null;
				this._lastResumenHash = currentHash;
			}
		}
	}

	/**
	 * Filas para la tarjeta «Boleta de Rezagados» (API: `resumen.boleta_rezagados`).
	 */
	boletaRezagadosFilas(): any[] {
		const raw = this.resumen?.boleta_rezagados;
		return Array.isArray(raw) ? raw : [];
	}

	formatParcialRezagado(row: any): string {
		if (row == null) return '-';
		const v = row.parcial ?? row.es_parcial;
		if (typeof v === 'boolean') return v ? 'Sí' : 'No';
		const s = `${v}`.trim().toUpperCase();
		if (s === '1' || s === 'S' || s === 'SI' || s === 'SÍ') return 'Sí';
		if (s === '0' || s === 'N' || s === 'NO') return 'No';
		return v !== undefined && v !== null && `${v}` !== '' ? `${v}` : '-';
	}

	/**
	 * Nombre de carrera + código de pensum de la inscripción del resumen (p. ej. "Mecánica Automotriz | Pensum: 04-MTZ-23").
	 */
	carreraConPensumDisplay(r: any): string {
		const raw = r?.estudiante?.carrera;
		const nombre =
			typeof raw === 'string'
				? raw.trim()
				: `${raw?.nombre ?? raw?.nombre_carrera ?? ''}`.trim();
		const pensum = `${r?.inscripcion?.cod_pensum ?? r?.estudiante?.cod_pensum ?? ''}`.trim();
		if (!nombre && !pensum) {
			return '—';
		}
		if (!pensum) {
			return nombre || '—';
		}
		if (!nombre) {
			return `Pensum: ${pensum}`;
		}
		return `${nombre} | Pensum: ${pensum}`;
	}

	/** Inscripciones mostradas en el bloque "Grupos" (toda la historia o solo la gestión actual). */
	get inscripcionesParaKardex(): any[] {
		const list = Array.isArray(this.resumen?.inscripciones) ? this.resumen.inscripciones : [];
		if (!this.filtrarInscripcionesPorGestion) {
			return list;
		}
		const g = `${this.gestion ?? ''}`.trim();
		if (!g) {
			return list;
		}
		return list.filter((ins: any) => `${ins?.gestion ?? ''}`.trim() === g);
	}

	open(): void {
		const modalEl = document.getElementById('kardexModal');
		const bs = (window as any).bootstrap;
		if (modalEl && bs?.Modal) {
			const modal = new bs.Modal(modalEl);
			modal.show();
		}
	}

	// Calcular días de mora
	calculateDaysOverdue(fechaVencimiento: string): number {
		if (!fechaVencimiento) return 0;

		const today = new Date();
		const dueDate = new Date(fechaVencimiento);


		// Si la fecha de vencimiento es futura, no hay días de mora
		if (dueDate > today) return 0;

		// Calcular diferencia en días (solo si está vencida)
		const diffTime = today.getTime() - dueDate.getTime();
		const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));

		const result = Math.max(0, diffDays);
		return result;
	}

	// Calcular monto acumulado
	calculateCumulativeAmount(adeudadas: any[], currentIndex: number): number {
		if (!adeudadas || currentIndex < 0) return 0;

		let total = 0;
		for (let i = 0; i <= currentIndex; i++) {
			const item = adeudadas[i];

			// Calcular monto adeudado (asegurar que sea número)
			let montoAdeudado = 0;
			if (item?.estado_pago === 'PARCIAL') {
				// Para pagos parciales, usar el saldo restante
				montoAdeudado = parseFloat((item?.monto || 0)) - parseFloat((item?.monto_pagado || 0));
			} else {
				// Para otros estados, usar el monto completo
				montoAdeudado = parseFloat(item?.monto || 0);
			}

			// Calcular importe por mora (igual a días de mora)
			const diasMora = this.calculateDaysOverdue(item?.fecha_vencimiento);
			const importeMora = diasMora;

			// Lógica corregida según el ejemplo:
			// - Para todas las cuotas: acumular monto adeudado + importe por mora
			// - Esto significa que cada cuota acumula todo lo anterior
			const itemTotal = montoAdeudado + importeMora;
			total += itemTotal;

			// Debug para cada paso
		}

		// Asegurar que sea un número entero sin decimales
		const resultado = Math.round(total);
		return resultado;
	}

	// Calcular total adeudado
	calculateTotalAdeudado(adeudadas: any[]): number {
		if (!adeudadas || adeudadas.length === 0) return 0;

		// Obtener el monto acumulado de la última cuota
		const ultimoIndice = adeudadas.length - 1;
		const montoAcumuladoUltimaCuota = this.calculateCumulativeAmount(adeudadas, ultimoIndice);

		return montoAcumuladoUltimaCuota;
	}

	// Formatear cuota con nombre del mes
	getCuotaFormat(numeroCuota: number): string {
		const mes = this.getMesByCuota(numeroCuota);
		return mes ? `${numeroCuota} - ${mes}` : `${numeroCuota}`;
	}

	/** Cobro de mora: puede venir como MORA o NIVELACION; a veces con id_asignacion_costo (se muestra en fila de cuota con etiqueta MORA). */
	private isCobroMora(cobro: any): boolean {
		const cod = String(cobro?.cod_tipo_cobro || '').toUpperCase();
		if (cod === 'MORA') return true;
		if (String(cobro?.tipo_pago || '').toUpperCase() === 'MORA') return true;
		if (Number(cobro?.id_asignacion_mora || 0) > 0 && !cobro?.id_asignacion_costo) return true;
		// NIVELACION en BD = mora de nivelación; no depender del texto de detalle (p. ej. "Nivelación" no coincide con \bNiv\b).
		if (cod === 'NIVELACION' && !cobro?.id_asignacion_costo) return true;
		return false;
	}

	/** Número de cuota (1–12) desde detalle/concepto de mora (p. ej. "Mens. (Marzo) Niv", "Cuota 3"). */
	private numeroCuotaFromMoraDetalle(cobro: any): number {
		const raw = `${cobro?.detalle ?? ''} ${cobro?.concepto ?? ''}`.trim();
		const meses = ['Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
		const mesMatch = raw.match(/\b(Enero|Febrero|Marzo|Abril|Mayo|Junio|Julio|Agosto|Septiembre|Octubre|Noviembre|Diciembre)\b/i);
		if (mesMatch) {
			const idx = meses.findIndex(m => m.toLowerCase() === mesMatch[1].toLowerCase());
			if (idx >= 0) return idx + 1;
		}
		const cuotaMatch = raw.match(/Cuota\s*(\d+)/i);
		if (cuotaMatch) return parseInt(cuotaMatch[1], 10) || 1;
		return 1;
	}

	// Obtener nombre del mes según gestión y número de cuota
	getMesByCuota(numeroCuota: number): string {
		const gestion = this.gestion.toString() || '';

		// Extraer el número de gestión del formato completo (ej: '2025-1' -> '1', '2/2025' -> '2')
		let gestionNumber = gestion;
		if (gestion.includes('-')) {
			gestionNumber = gestion.split('-')[1]; // '2025-1' -> '1'
		} else if (gestion.includes('/')) {
			gestionNumber = gestion.split('/')[0]; // '2/2025' -> '2'
		}

		// TEMPORAL: Forzar un valor para probar
		// return 'febrero'; // Comentar esta línea después de probar

		// Gestion 1/año: 1=Febrero, 2=Marzo, 3=Abril, 4=Mayo, 5=Junio
		if (gestionNumber === '1') {
			const meses = ['', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio'];
			return meses[numeroCuota] || '';
		}

		// Gestion 2/año: 1=Julio, 2=Agosto, 3=Septiembre, 4=Octubre, 5=Noviembre
		if (gestionNumber === '2') {
			const meses = ['', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre'];
			return meses[numeroCuota] || '';
		}

		return '';
	}

	// Expande los pagos múltiples de una misma cuota en filas separadas
	getPagosExpandidos(): any[] {
		// Si ya hay cache y los datos no cambiaron, retornar cache
		if (this._pagosExpandidosCache && this._cacheValid()) {
			return this._pagosExpandidosCache;
		}

		try {
			const asignaciones = this.resumen?.asignaciones || [];

			// DEBUG: Ver qué asignaciones están disponibles
			console.log('[Kardex DEBUG] Total asignaciones:', asignaciones.length);
			console.log('[Kardex DEBUG] IDs de asignaciones:', asignaciones.map((a: any) => a?.id_asignacion_costo));

			// Asegurar que cobrosItems siempre sea un array iterable
			let cobrosItems: any[] = [];
			try {
				const cobrosData = this.resumen?.cobros?.items;
				if (cobrosData?.items && Array.isArray(cobrosData.items)) {
					// La estructura es {total, count, items: Array}
					cobrosItems = cobrosData.items;
				} else if (Array.isArray(cobrosData)) {
					// Si directamente es un array
					cobrosItems = cobrosData;
				} else if (cobrosData && typeof cobrosData === 'object') {
					// Si es objeto pero no array, intentar convertir
					cobrosItems = Object.values(cobrosData).filter((item: any) => item && typeof item === 'object');
				}
			} catch (error) {
				console.warn('[Kardex] Error procesando cobros.items:', error);
				cobrosItems = [];
			}

			const expandidos: any[] = [];
			// Agrupar pagos reales por id_asignacion_costo
			const pagosRealesPorAsignacion = new Map<any, any[]>();
			for (const cobro of cobrosItems) {
				const idAsignacion = cobro?.id_asignacion_costo;
				if (!idAsignacion) {
					continue; // Solo mensualidades
				}
				if (!pagosRealesPorAsignacion.has(idAsignacion)) {
					pagosRealesPorAsignacion.set(idAsignacion, []);
				}
				pagosRealesPorAsignacion.get(idAsignacion)!.push(cobro);
			}

			// Procesar pagos agrupados por asignación
			for (const [idAsignacion, pagos] of pagosRealesPorAsignacion.entries()) {
				const asignacion = asignaciones.find((a: any) => a?.id_asignacion_costo == idAsignacion);
				const estadoCuota = (asignacion?.estado_pago || '').toString().toUpperCase();
				const cuotaEsCompleta = estadoCuota === 'COBRADO';

				// Ordenar pagos por fecha
				pagos.sort((a: any, b: any) => {
					const fechaA = new Date(a?.fecha_cobro || a?.fecha_pago || 0).getTime();
					const fechaB = new Date(b?.fecha_cobro || b?.fecha_pago || 0).getTime();
					return fechaA - fechaB;
				});

				for (let i = 0; i < pagos.length; i++) {
					const pago = pagos[i];
					const esUltimoPago = i === pagos.length - 1;

					// DEBUG: Log para verificar datos
					if (pago?.cod_tipo_cobro === 'ARRASTRE') {
						console.log('[Kardex DEBUG] Arrastre:', {
							id_asignacion: idAsignacion,
							asignacion_encontrada: !!asignacion,
							estado_pago: asignacion?.estado_pago,
							estadoCuota: estadoCuota,
							cuotaEsCompleta: cuotaEsCompleta,
							esUltimoPago: esUltimoPago,
							detalle: pago?.detalle,
							concepto: pago?.concepto
						});
					}

					// El pago total solo se marca como completo si la cuota está COBRADA y es el último pago
					const esCompleto = (cuotaEsCompleta && esUltimoPago) ? 'Si' : 'No';

					// Tipo mostrado: la cuota es NORMAL/ARRASTRE, pero MORA/NIVELACION deben verse como MORA (coincide con cod_tipo_cobro en cobro).
					const codTipo = String(pago?.cod_tipo_cobro || '').toUpperCase();
					let tipoInscripcion = asignacion?.tipo_inscripcion || 'NORMAL';
					if (codTipo === 'ARRASTRE') {
						tipoInscripcion = 'ARRASTRE';
					} else if (codTipo === 'MORA' || codTipo === 'NIVELACION') {
						tipoInscripcion = 'MORA';
					}

					// Determinar número de cuota: para ARRASTRE, extraer del detalle
					let numeroCuota = asignacion?.numero_cuota || 1;
					if (pago?.cod_tipo_cobro === 'ARRASTRE') {
						const detalle = (pago?.detalle || pago?.concepto || '').toString();
						const mesMatch = detalle.match(/\b(Enero|Febrero|Marzo|Abril|Mayo|Junio|Julio|Agosto|Septiembre|Octubre|Noviembre|Diciembre)\b/i);
						if (mesMatch) {
							const meses = ['Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
							const mesNombre = mesMatch[1];
							const mesIndex = meses.findIndex(m => m.toLowerCase() === mesNombre.toLowerCase());
							if (mesIndex >= 0) {
								numeroCuota = mesIndex + 1;
							}
						}
					}

					const pagoExpandido = {
						...pago,
						numero_cuota: numeroCuota,
						numero_pago: i + 1,
						tipo_inscripcion: tipoInscripcion,
						es_multipago: pagos.length > 1,
						es_completo: esCompleto,
						estado_pago: estadoCuota || pago?.estado_pago || '',
						fecha_pago: pago?.fecha_cobro || pago?.fecha_pago || null,
						monto_pagado: pago?.monto || 0,
						nro_factura: pago?.nro_factura || '-',
						nro_recibo: pago?.nro_recibo || '0',
						observaciones: this.getObservacionesExtendidas(pago),
					};

					expandidos.push(pagoExpandido);
				}
			}

			// Moras pagadas: no tienen id_asignacion_costo; agrupar por id_asignacion_mora o por clave de cobro
			const pagosMoraPorClave = new Map<string, any[]>();
			for (const cobro of cobrosItems) {
				if (!this.isCobroMora(cobro)) continue;
				const idM = Number(cobro?.id_asignacion_mora || 0);
				const key =
					idM > 0
						? `mora_${idM}`
						: `mora_cobro_${cobro?.nro_cobro ?? ''}_${cobro?.anio_cobro ?? ''}_${cobro?.tipo_inscripcion ?? ''}`;
				if (!pagosMoraPorClave.has(key)) pagosMoraPorClave.set(key, []);
				pagosMoraPorClave.get(key)!.push(cobro);
			}

			for (const [, pagosM] of pagosMoraPorClave.entries()) {
				pagosM.sort((a: any, b: any) => {
					const fa = new Date(a?.fecha_cobro || a?.fecha_pago || 0).getTime();
					const fb = new Date(b?.fecha_cobro || b?.fecha_pago || 0).getTime();
					return fa - fb;
				});
				const numeroCuota = this.numeroCuotaFromMoraDetalle(pagosM[0]);
				for (let i = 0; i < pagosM.length; i++) {
					const pago = pagosM[i];
					const esUltimo = i === pagosM.length - 1;
					const completo = pago?.cobro_completo === true || pago?.cobro_completo === 1 || esUltimo;
					expandidos.push({
						...pago,
						numero_cuota: numeroCuota,
						numero_pago: i + 1,
						tipo_inscripcion: 'MORA',
						es_multipago: pagosM.length > 1,
						es_completo: completo ? 'Si' : 'No',
						estado_pago: 'COBRADO',
						fecha_pago: pago?.fecha_cobro || pago?.fecha_pago || null,
						monto_pagado: pago?.monto || 0,
						nro_factura: pago?.nro_factura || '-',
						nro_recibo: pago?.nro_recibo || '0',
						observaciones: this.getObservacionesExtendidas(pago),
					});
				}
			}

			// Ordenar por número de cuota y luego por número de pago
			expandidos.sort((a: any, b: any) => {
				const cuotaA = a.numero_cuota || 0;
				const cuotaB = b.numero_cuota || 0;
				if (cuotaA !== cuotaB) return cuotaA - cuotaB;
				return (a.numero_pago || 0) - (b.numero_pago || 0);
			});

			this._pagosExpandidosCache = expandidos;
			return expandidos;
		} catch (error) {
			console.error('[Kardex] Error en getPagosExpandidos:', error);
			return [];
		}
	}

	// Verificar si el cache es válido
	private _cacheValid(): boolean {
		// Implementar lógica para verificar si los datos cambiaron
		// Por ahora, siempre regeneramos para evitar problemas
		return false;
	}

	// Determinar observaciones según el tipo de pago y fuente
	private getObservacionesPorTipo(tipoPago: string, fuente: string): string {
		if (tipoPago === 'COMPLETO') return 'Efectivo: PAGO COMPLETO';
		if (tipoPago === 'PARCIAL') return 'Efectivo: PAGO PARCIAL';
		if (tipoPago === 'PARCIAL_ADICIONAL') return 'Efectivo: PAGO ADICIONAL';
		if (tipoPago === 'PAGO_INDIVIDUAL') return 'Efectivo: PAGO INDIVIDUAL';
		if (tipoPago === 'PENDIENTE') return 'Efectivo: PENDIENTE DE PAGO';
		return 'Efectivo:';
	}

	// Método para obtener razón social/NIT desde factura o recibo
	getRazonSocialNIT(cobro: any): string {
		// Usar cliente/nro_documento_cobro (campos que vendrán del backend)
		const cliente = cobro?.cliente || '-';
		const nroDoc = cobro?.nro_documento_cobro || '';
		const resultado = nroDoc ? `${cliente} / ${nroDoc}` : cliente;

		// Debug temporal para verificar que lleguen los datos
		if (cobro?.cliente || cobro?.nro_documento_cobro) {
			console.log('✅ Razón Social/NIT encontrado:', resultado);
		}

		return resultado;
	}

	// Obtener nombre completo del método de pago a partir del código
	getNombreFormaCobro(idFormaCobro: string | undefined | null): string {
		if (!idFormaCobro) return 'EFECTIVO';

		const metodosPago: { [key: string]: string } = {
			// Códigos "nuevos" de varias letras
			'EF': 'EFECTIVO',
			'TR': 'TRANSFERENCIA',
			'TA': 'TARJETA',
			'TC': 'TARJETA',
			'DE': 'DEPOSITO',
			'CH': 'CHEQUE',
			'QR': 'QR',
			'OT': 'OTRO',
			// Códigos de una letra que se usan en la tabla cobro
			'E': 'EFECTIVO',      // Efectivo
			'D': 'DEPOSITO',      // Depósito bancario
			'C': 'CHEQUE',        // Cheque
			'L': 'TARJETA',       // Tarjeta débito/crédito
			'B': 'TRANSFERENCIA', // Transferencia bancaria
			'O': 'OTRO',          // Otro
			'T': 'TRASPASO',      // Traspaso de carrera
		};

		const codigo = idFormaCobro.toUpperCase();
		return metodosPago[codigo] || 'EFECTIVO';
	}

	// Obtener solo el nombre del banco (sin número de cuenta)
	private getBancoSoloNombre(pago: any): string {
		try {
			const raw = (pago?.banco_nb || pago?.banco || '').toString().trim();
			if (!raw) return '';
			// En nota_bancaria se guarda como "BANCO X - 123456"; nos quedamos con la parte antes de " - "
			const partes = raw.split(' - ');
			return (partes[0] || raw).trim();
		} catch {
			return '';
		}
	}

	// Obtener observaciones extendidas según el método de pago
	getObservacionesExtendidas(pago: any): string {
		let idFormaCobro = pago?.id_forma_cobro;
		const obsOriginal = pago?.observaciones || '';

		// Si es efectivo, solo mostrar observaciones si existen
		if (idFormaCobro === 'EF') {
			return obsOriginal || '';
		}

		// Para otros métodos, concatenar información adicional
		let infoAdicional = '';

		switch (idFormaCobro) {
			case 'TA': // TARJETA
				// {Tipo de Pago}: {banco}-{nro_transaccion}-{fecha_deposito} NL:0
				const bancoTarjeta = this.getBancoSoloNombre(pago);
				const nroTransaccionTarjeta = (pago?.nro_transaccion || pago?.nro_deposito || '').toString();
				const fechaDepositoTarjeta = (pago?.fecha_deposito || pago?.fecha_nota || '').toString();

				if (bancoTarjeta && nroTransaccionTarjeta && fechaDepositoTarjeta) {
					infoAdicional = `Tarjeta: ${bancoTarjeta}-${nroTransaccionTarjeta}-${fechaDepositoTarjeta} NL:0`;
				} else {
					infoAdicional = `Tarjeta: ${pago?.nro_tarjeta || 'N/A'} - Autorización: ${pago?.nro_autorizacion || 'N/A'}`;
				}
				break;
			case 'CH': // CHEQUE
				infoAdicional = `Cheque N°: ${pago?.nro_cheque || 'N/A'} - Banco: ${this.getBancoSoloNombre(pago) || 'N/A'}`;
				break;
			case 'DE': // DEPOSITO
				// Deposito: {banco}-{nro_transaccion}-{fecha_deposito} ND:{correlativo}
				const bancoDeposito = this.getBancoSoloNombre(pago);
				const nroDeposito = (pago?.nro_transaccion || pago?.nro_deposito || '').toString();
				const fechaDeposito = (pago?.fecha_deposito || pago?.fecha_nota || '').toString();
				let correlativoNd = (pago?.correlativo_nb || pago?.nro_referencia || '').toString();
				// Limpiar prefijos tipo "NB:", "ND:" o similares para no duplicar
				if (correlativoNd) {
					correlativoNd = correlativoNd.replace(/^N[BD][:\s]*/i, '').trim();
				}
				if (bancoDeposito && nroDeposito && fechaDeposito) {
					infoAdicional = correlativoNd
						? `Deposito: ${bancoDeposito}-${nroDeposito}-${fechaDeposito} ND:${correlativoNd}`
						: `Deposito: ${bancoDeposito}-${nroDeposito}-${fechaDeposito}`;
				} else {
					infoAdicional = `Depósito - N° Cuenta: ${pago?.nro_cuenta || 'N/A'} - Banco: ${this.getBancoSoloNombre(pago) || 'N/A'} - Referencia: ${pago?.nro_referencia || 'N/A'}`;
				}
				break;
			case 'TR': // TRANSFERENCIA
				// {Tipo de Pago}: {banco}-{nro_transaccion}-{fecha_deposito} NB:{correlativo}
				const bancoTransferencia = this.getBancoSoloNombre(pago);
				const nroTransferencia = (pago?.nro_transaccion || pago?.nro_deposito || '').toString();
				const fechaTransferencia = (pago?.fecha_deposito || pago?.fecha_nota || '').toString();
				let correlativoNb = (pago?.correlativo_nb || pago?.nro_referencia || '').toString();
				// Limpiar prefijos tipo "NB:" o "NB " que puedan venir desde nro_referencia para no duplicar
				if (correlativoNb) {
					correlativoNb = correlativoNb.replace(/^NB[:\s]*/i, '').trim();
				}

				if (bancoTransferencia && nroTransferencia && fechaTransferencia) {
					infoAdicional = correlativoNb
						? `Transferencia: ${bancoTransferencia}-${nroTransferencia}-${fechaTransferencia} NB:${correlativoNb}`
						: `Transferencia: ${bancoTransferencia}-${nroTransferencia}-${fechaTransferencia}`;
				} else {
					infoAdicional = `Transferencia - N° Cuenta: ${pago?.nro_cuenta || 'N/A'} - Banco: ${this.getBancoSoloNombre(pago) || 'N/A'} - Referencia: ${pago?.nro_referencia || 'N/A'}`;
				}
				break;
			case 'QR': // QR
				infoAdicional = `QR - Código: ${pago?.codigo_qr || 'N/A'} - Fecha: ${pago?.fecha_qr || 'N/A'}`;
				break;
			case 'OT': // OTRO
				infoAdicional = `Otro: ${pago?.detalle_otro || 'N/A'}`;
				break;
		}

		// Combinar observaciones originales con información adicional
		// Regla:
		// - Si existe infoAdicional (formato bancario), mostrar SOLO ese texto para evitar duplicados.
		// - Si no hay infoAdicional, mostrar las observaciones originales.
		if (infoAdicional) {
			return infoAdicional;
		}
		return obsOriginal || '';
	}

}