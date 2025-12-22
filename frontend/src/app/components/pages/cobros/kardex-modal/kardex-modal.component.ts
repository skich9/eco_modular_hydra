import { Component, Input } from '@angular/core';
import { CommonModule } from '@angular/common';

@Component({
	selector: 'app-kardex-modal',
	standalone: true,
	imports: [CommonModule],
	templateUrl: './kardex-modal.component.html',
	styleUrls: ['./kardex-modal.component.scss']
})
export class KardexModalComponent {
	@Input() resumen: any = null;

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
}
