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
					cobrosItems = Object.values(cobrosData).filter(item => item && typeof item === 'object');
				}
			} catch (error) {
				console.warn('[Kardex] Error procesando cobros.items:', error);
				cobrosItems = [];
			}

			const expandidos: any[] = [];

			// Agrupar pagos reales por id_asignacion_costo
			const pagosRealesPorAsignacion = new Map();

			// Procesar SOLO los cobros individuales que tengan id_asignacion_costo
			for (const cobro of cobrosItems) {
				const idDesignacion = cobro?.id_asignacion_costo;

				// SOLO procesar cobros que tengan id_asignacion_costo (son de mensualidades)
				if (!idDesignacion) {
					continue;
				}

				// Agrupar por asignación
				if (!pagosRealesPorAsignacion.has(idDesignacion)) {
					pagosRealesPorAsignacion.set(idDesignacion, []);
				}

				pagosRealesPorAsignacion.get(idDesignacion).push(cobro);
			}

			// Procesar los pagos reales agrupados
			for (const [idAsignacion, pagos] of pagosRealesPorAsignacion.entries()) {

				// Ordenar pagos por fecha
				pagos.sort((a: any, b: any) => {
					const fechaA = new Date(a?.fecha_cobro || a?.fecha_pago || 0);
					const fechaB = new Date(b?.fecha_cobro || b?.fecha_pago || 0);
					return fechaA.getTime() - fechaB.getTime();
				});

				// Mostrar cada pago como fila separada
				for (let i = 0; i < pagos.length; i++) {
					const pago = pagos[i];
					const esUltimoPago = i === pagos.length - 1;

					// Buscar información de la asignación para obtener número de cuota y tipo
					const asignacion = asignaciones.find((a: any) => a?.id_asignacion_costo == idAsignacion);

					const pagoExpandido = {
						...pago,
						numero_cuota: asignacion?.numero_cuota || 1,
						numero_pago: i + 1,
						tipo_inscripcion: asignacion?.tipo_inscripcion || 'NORMAL',
						es_multipago: pagos.length > 1,
						es_completo: esUltimoPago ? 'Si' : 'No',
						fecha_pago: pago?.fecha_cobro || pago?.fecha_pago || null,
						monto_pagado: pago?.monto || 0,
						nro_factura: pago?.nro_factura || '-',
						nro_recibo: pago?.nro_recibo || '0',
						observaciones: this.getObservacionesExtendidas(pago)
					};

					expandidos.push(pagoExpandido);
				}
			}

			// Ordenar por número de cuota y luego por número de pago
			expandidos.sort((a: any, b: any) => {
				const cuotaA = a.numero_cuota || 0;
				const cuotaB = b.numero_cuota || 0;
				if (cuotaA !== cuotaB) return cuotaA - cuotaB;
				return (a.numero_pago || 0) - (b.numero_pago || 0);
			});

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

	// Obtener nombre completo del método de pago a partir del código
	getNombreFormaCobro(idFormaCobro: string): string {
		const metodosPago: { [key: string]: string } = {
			'EF': 'EFECTIVO',
			'TA': 'TARJETA',
			'CH': 'CHEQUE',
			'DE': 'DEPOSITO',
			'TR': 'TRANSFERENCIA',
			'QR': 'QR',
			'OT': 'OTRO'
		};

		return metodosPago[idFormaCobro] || 'EFECTIVO';
	}

	// Obtener observaciones extendidas según el método de pago
	getObservacionesExtendidas(pago: any): string {
		const idFormaCobro = pago?.id_forma_cobro;
		const obsOriginal = pago?.observaciones || '';

		// Si es efectivo, solo mostrar observaciones si existen
		if (idFormaCobro === 'EF') {
			return obsOriginal || '';
		}

		// Para otros métodos, concatenar información adicional
		let infoAdicional = '';

		switch (idFormaCobro) {
			case 'TA': // TARJETA
				infoAdicional = `Tarjeta: ${pago?.nro_tarjeta || 'N/A'} - Autorización: ${pago?.nro_autorizacion || 'N/A'}`;
				break;
			case 'CH': // CHEQUE
				infoAdicional = `Cheque N°: ${pago?.nro_cheque || 'N/A'} - Banco: ${pago?.banco || 'N/A'}`;
				break;
			case 'DE': // DEPOSITO
				infoAdicional = `Depósito - N° Cuenta: ${pago?.nro_cuenta || 'N/A'} - Banco: ${pago?.banco || 'N/A'} - Referencia: ${pago?.nro_referencia || 'N/A'}`;
				break;
			case 'TR': // TRANSFERENCIA
				infoAdicional = `Transferencia - N° Cuenta: ${pago?.nro_cuenta || 'N/A'} - Banco: ${pago?.banco || 'N/A'} - Referencia: ${pago?.nro_referencia || 'N/A'}`;
				break;
			case 'QR': // QR
				infoAdicional = `QR - Código: ${pago?.codigo_qr || 'N/A'} - Fecha: ${pago?.fecha_qr || 'N/A'}`;
				break;
			case 'OT': // OTRO
				infoAdicional = `Otro: ${pago?.detalle_otro || 'N/A'}`;
				break;
		}

		// Combinar observaciones originales con información adicional
		if (obsOriginal && infoAdicional) {
			return `${obsOriginal} | ${infoAdicional}`;
		} else if (infoAdicional) {
			return infoAdicional;
		} else {
			return obsOriginal || '';
		}
	}
}
