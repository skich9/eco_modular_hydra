import { Component } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { AddPuntoVentaModalComponent } from './add-punto-venta-modal/add-punto-venta-modal.component';

declare var bootstrap: any;

interface PuntoVentaRow {
	codigo_punto_venta: number | string;
	nombre: string;
	descripcion?: string | null;
	sucursal?: number | string | null;
	cuis?: string | null;
	tipo_pv?: string | null;
	usuario?: string | null;
	fecha_finalizacion?: string | null;
	activo: boolean;
}

@Component({
	selector: 'app-configuracion-punto-venta',
	standalone: true,
	imports: [CommonModule, FormsModule, AddPuntoVentaModalComponent],
	templateUrl: './configuracion-punto-venta.component.html',
	styleUrls: ['./configuracion-punto-venta.component.scss']
})
export class ConfiguracionPuntoVentaComponent {
	searchTerm: string = '';

	// Datos de ejemplo mientras se conecta el backend
	puntosVenta: PuntoVentaRow[] = [
		{
			codigo_punto_venta: 0,
			nombre: 'Punto de Venta Principal',
			descripcion: 'Caja central',
			sucursal: 0,
			cuis: '12345678',
			tipo_pv: 'Punto de Venta Fijo',
			usuario: 'usuario_admin',
			fecha_finalizacion: null,
			activo: true
		},
	];

	get filteredPuntosVenta(): PuntoVentaRow[] {
		const term = (this.searchTerm || '').toString().trim().toLowerCase();
		if (!term) {
			return this.puntosVenta;
		}
		return this.puntosVenta.filter(pv => {
			const codigo = String(pv.codigo_punto_venta || '').toLowerCase();
			const nombre = (pv.nombre || '').toLowerCase();
			const desc = (pv.descripcion || '').toLowerCase();
			const suc = pv.sucursal != null ? String(pv.sucursal).toLowerCase() : '';
			const cuis = (pv.cuis || '').toLowerCase();
			const tipo = (pv.tipo_pv || '').toLowerCase();
			const usuario = (pv.usuario || '').toLowerCase();
			const fechaFin = (pv.fecha_finalizacion || '').toLowerCase();
			return (
				codigo.includes(term) ||
				nombre.includes(term) ||
				desc.includes(term) ||
				suc.includes(term) ||
				cuis.includes(term) ||
				tipo.includes(term) ||
				usuario.includes(term) ||
				fechaFin.includes(term)
			);
		});
	}

	addPuntoVenta(): void {
		const modalElement = document.getElementById('addPuntoVentaModal');
		if (modalElement) {
			const modal = new bootstrap.Modal(modalElement);
			modal.show();
		}
	}

	onPuntoVentaCreated(data: any): void {
		console.log('Punto de venta creado:', data);
		const modalElement = document.getElementById('addPuntoVentaModal');
		if (modalElement) {
			const modal = bootstrap.Modal.getInstance(modalElement);
			if (modal) {
				modal.hide();
			}
		}
	}
}
