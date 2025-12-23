import { Component, Input, OnChanges, Pipe, PipeTransform } from '@angular/core';
import { CommonModule } from '@angular/common';

@Pipe({
	name: 'filterNonMensualidades',
	standalone: true
})
export class FilterNonMensualidadesPipe implements PipeTransform {
	transform(items: any[]): any[] {
		if (!Array.isArray(items)) return [];
		return items.filter(item => !item?.id_designacion_costo);
	}
}

@Component({
	selector: 'app-kardex-modal',
	standalone: true,
	imports: [CommonModule, FilterNonMensualidadesPipe],
	templateUrl: './kardex-modal.component.html',
	styleUrls: ['./kardex-modal.component.scss']
})
export class KardexModalComponent implements OnChanges {
	@Input() resumen: any = null;
	@Input() gestion: string = '';
	
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
		
		// Debug logs
		console.log('[Kardex] Days overdue calculation:', {
			fechaVencimiento,
			today: today.toISOString().split('T')[0],
			dueDate: dueDate.toISOString().split('T')[0],
			isFuture: dueDate > today
		});
		
		// Si la fecha de vencimiento es futura, no hay días de mora
		if (dueDate > today) return 0;
		
		// Calcular diferencia en días (solo si está vencida)
		const diffTime = today.getTime() - dueDate.getTime();
		const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
		
		const result = Math.max(0, diffDays);
		console.log('[Kardex] Days overdue result:', { diffTime, diffDays, result });
		
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
			console.log(`[Kardex] Cuota ${item?.numero_cuota} (índice ${i}):`, {
				montoAdeudado,
				diasMora,
				importeMora,
				itemTotal,
				totalAcumulado: total
			});
		}
		
		// Asegurar que sea un número entero sin decimales
		const resultado = Math.round(total);
		console.log(`[Kardex] Resultado final para índice ${currentIndex}:`, resultado);
		return resultado;
	}

	// Calcular total adeudado
	calculateTotalAdeudado(adeudadas: any[]): number {
		if (!adeudadas || adeudadas.length === 0) return 0;
		
		// Obtener el monto acumulado de la última cuota
		const ultimoIndice = adeudadas.length - 1;
		const montoAcumuladoUltimaCuota = this.calculateCumulativeAmount(adeudadas, ultimoIndice);
		
		console.log('[Kardex] Total adeudado (monto acumulado última cuota):', {
			ultimoIndice,
			montoAcumuladoUltimaCuota
		});
		
		return montoAcumuladoUltimaCuota;
	}

	// Formatear cuota con nombre del mes
	getCuotaFormat(numeroCuota: number): string {
		const mes = this.getMesByCuota(numeroCuota);
		return mes ? `${numeroCuota} - ${mes}` : `${numeroCuota}`;
	}

	// Obtener nombre del mes según gestión y número de cuota
	getMesByCuota(numeroCuota: number): string {
		const gestion = this.gestion.toString() || '';
		console.log('[Kardex] getMesByCuota - gestion:', gestion, 'numeroCuota:', numeroCuota);
		
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
			// Asegurar que cobrosItems siempre sea un array iterable
			let cobrosItems: any[] = [];
			try {
				const cobrosData = this.resumen?.cobros?.items;
				console.log('[Kardex] ESTRUCTURA COMPLETA de cobros.items:', cobrosData);
				console.log('[Kardex] Tipo de cobrosData:', typeof cobrosData);
				console.log('[Kardex] ¿Es Array?:', Array.isArray(cobrosData));
				
				if (cobrosData?.items && Array.isArray(cobrosData.items)) {
					// La estructura es {total, count, items: Array}
					cobrosItems = cobrosData.items;
					console.log('[Kardex] cobrosItems desde cobros.items.items:', cobrosItems.length, 'elementos');
					cobrosItems.forEach((item, index) => {
						console.log(`[Kardex] cobrosItems[${index}]:`, item);
					});
				} else if (Array.isArray(cobrosData)) {
					// Si directamente es un array
					cobrosItems = cobrosData;
					console.log('[Kardex] cobrosItems es array con', cobrosItems.length, 'elementos');
					cobrosItems.forEach((item, index) => {
						console.log(`[Kardex] cobrosItems[${index}]:`, item);
					});
				} else if (cobrosData && typeof cobrosData === 'object') {
					// Si es objeto pero no array, intentar convertir
					console.log('[Kardex] cobrosData es objeto, claves:', Object.keys(cobrosData));
					cobrosItems = Object.values(cobrosData).filter(item => item && typeof item === 'object');
					console.log('[Kardex] cobrosItems convertidos:', cobrosItems.length, 'elementos');
				}
				console.log('[Kardex] cobrosItems procesados:', cobrosItems.length, cobrosItems);
			} catch (error) {
				console.warn('[Kardex] Error procesando cobros.items:', error);
				cobrosItems = [];
			}
			
			const expandidos: any[] = [];
			
			// Crear mapa de id_designacion_costo a numero_cuota desde TODAS las asignaciones
			const mapaDesignacionACuota = new Map();
			const todasLasAsignaciones = new Set(); // Guardar IDs de TODAS las asignaciones
			
			for (const asignacion of asignaciones) {
				const idDesignacion = asignacion?.id_asignacion_costo;
				const numeroCuota = asignacion?.numero_cuota;
				const estado = (asignacion?.estado_pago || '').toString().toUpperCase();
				
				if (idDesignacion && numeroCuota) {
					mapaDesignacionACuota.set(Number(idDesignacion), Number(numeroCuota));
					todasLasAsignaciones.add(Number(idDesignacion));
					console.log(`[Kardex] Mapa: id_designacion_costo ${idDesignacion} -> cuota ${numeroCuota} (${estado})`);
				}
			}
			
			// Agrupar todos los pagos por cuota
			const pagosPorCuota = new Map();
			
			// Primero procesar asignaciones COBRADAS únicamente
			for (const asignacion of asignaciones) {
				const estado = (asignacion?.estado_pago || '').toString().toUpperCase();
				
				// SOLO procesar asignaciones COBRADAS, ignorar PARCIALES
				if (estado === 'COBRADO') {
					const cuota = asignacion?.numero_cuota || 1;
					console.log('[Kardex] Procesando asignación:', {
						estado: estado,
						numero_cuota: asignacion?.numero_cuota,
						monto_pagado: asignacion?.monto_pagado,
						fecha_pago: asignacion?.fecha_pago
					});
					
					if (!pagosPorCuota.has(cuota)) {
						pagosPorCuota.set(cuota, []);
					}
					
					// Cada asignación es un pago individual
					pagosPorCuota.get(cuota).push({
						...asignacion,
						fuente: 'asignacion',
						tipo_pago: 'COMPLETO'
					});
				} else if (estado === 'PARCIAL') {
					console.log('[Kardex] Ignorando asignación PARCIAL, solo se mostrarán sus cobros individuales:', {
						numero_cuota: asignacion?.numero_cuota,
						monto_pagado: asignacion?.monto_pagado
					});
				}
			}
			
			// Luego procesar CADA cobro individual de cobros.items
			for (const cobro of cobrosItems) {
				// Usar id_asignacion_costo que es la propiedad correcta en los cobros individuales
				const idDesignacion = cobro?.id_asignacion_costo;
				const idCuota = cobro?.id_cuota;
				const tipoCobro = cobro?.tipo_cobro || cobro?.concepto || '';
				
				console.log('[Kardex] Procesando cobro item INDIVIDUAL:', {
					id_asignacion_costo: idDesignacion,
					id_cuota: idCuota,
					tipo_cobro: tipoCobro,
					monto: cobro?.monto,
					fecha: cobro?.fecha_cobro
				});
				
				// SOLO procesar cobros que tengan id_asignacion_costo (son de mensualidades)
				if (!idDesignacion) {
					console.log('[Kardex] Cobro sin id_asignacion_costo, ignorando (no es mensualidad):', tipoCobro);
					continue;
				}
				
				// Agregar CADA cobro individual si corresponde a una asignación
				if (todasLasAsignaciones.has(Number(idDesignacion))) {
					const cuota = mapaDesignacionACuota.get(Number(idDesignacion));
					console.log(`[Kardex] Cobro individual mensualidad id_asignacion_costo ${idDesignacion} corresponde a cuota ${cuota}`);
					
					if (!pagosPorCuota.has(cuota)) {
						pagosPorCuota.set(cuota, []);
					}
					
					// CADA cobro es una fila separada
					pagosPorCuota.get(cuota).push({
						...cobro,
						fuente: 'cobro_individual',
						tipo_pago: 'PAGO_INDIVIDUAL'
					});
				} else {
					console.log(`[Kardex] Cobro id_asignacion_costo ${idDesignacion} no corresponde a ninguna asignación, ignorando`);
				}
			}
			
			// Procesar los pagos agrupados por cuota
			for (const [cuota, pagos] of pagosPorCuota.entries()) {
				console.log(`[Kardex] Cuota ${cuota} tiene ${pagos.length} pagos individuales`);
				
				// Si hay múltiples pagos para la misma cuota, mostrar cada uno como fila separada
				for (let i = 0; i < pagos.length; i++) {
					const pago = pagos[i];
					const pagoExpandido = {
						...pago,
						numero_cuota: cuota,
						numero_pago: i + 1, // Numeración consecutiva: 1, 2, 3...
						es_multipago: pagos.length > 1,
						es_completo: pago?.tipo_pago === 'COMPLETO' ? 'Si' : 'No',
						fecha_pago: pago?.fecha_pago || pago?.fecha_cobro || pago?.fecha || null,
						monto_pagado: pago?.monto_pagado || pago?.monto || 0,
						nro_factura: pago?.nro_factura || '-',
						nro_recibo: pago?.nro_recibo || '0',
						observaciones: this.getObservacionesPorTipo(pago?.tipo_pago, pago?.fuente)
					};
					console.log(`[Kardex] Fila ${i+1} de cuota ${cuota} (pago #${pagoExpandido.numero_pago}):`, {
						monto: pagoExpandido.monto_pagado,
						fecha: pagoExpandido.fecha_pago,
						fuente: pagoExpandido.fuente,
						tipo_pago: pagoExpandido.tipo_pago
					});
					expandidos.push(pagoExpandido);
				}
			}
			
			// Ordenar por número de cuota y luego por número de pago
			expandidos.sort((a, b) => {
				const cuotaA = a.numero_cuota || 0;
				const cuotaB = b.numero_cuota || 0;
				if (cuotaA !== cuotaB) return cuotaA - cuotaB;
				return (a.numero_pago || 0) - (b.numero_pago || 0);
			});
			
			console.log('[Kardex] Resultado final expandidos:', expandidos);
			
			// Guardar en cache
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
}
