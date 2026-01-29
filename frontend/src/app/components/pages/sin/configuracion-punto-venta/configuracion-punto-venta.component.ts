import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { AddPuntoVentaModalComponent } from './add-punto-venta-modal/add-punto-venta-modal.component';
import { DeletePuntoVentaModalComponent } from './delete-punto-venta-modal/delete-punto-venta-modal.component';
import { AddUsersModalComponent } from './add-users-modal/add-users-modal.component';
import { PuntoVentaService, ApiResponse, PuntoVenta } from '../../../../services/punto-venta.service';

declare var bootstrap: any;

interface PuntoVentaRow {
	codigo_punto_venta: number | string;
	nombre: string;
	descripcion?: string | null;
	sucursal?: number | string | null;
	codigo_cuis_genera?: string | null;
	tipo?: number | null;
	activo: boolean;
}

@Component({
	selector: 'app-configuracion-punto-venta',
	standalone: true,
	imports: [CommonModule, FormsModule, AddPuntoVentaModalComponent, DeletePuntoVentaModalComponent, AddUsersModalComponent],
	templateUrl: './configuracion-punto-venta.component.html',
	styleUrls: ['./configuracion-punto-venta.component.scss']
})
export class ConfiguracionPuntoVentaComponent implements OnInit {
	searchTerm: string = '';
	puntosVenta: PuntoVentaRow[] = [];
	isLoading: boolean = false;
	showSuccessAlert: boolean = false;
	showErrorAlert: boolean = false;
	alertMessage: string = '';
	puntoVentaToDelete: PuntoVentaRow | null = null;
	puntoVentaForUsers: PuntoVentaRow | null = null;

	// Paginación
	currentPage: number = 1;
	itemsPerPage: number = 20;
	totalPages: number = 1;
	Math = Math;

	constructor(private puntoVentaService: PuntoVentaService) {}

	ngOnInit(): void {
		this.loadPuntosVenta();
	}

	loadPuntosVenta(): void {
		this.isLoading = true;
		this.puntoVentaService.getPuntosVenta().subscribe({
			next: (response: ApiResponse<PuntoVenta[]>) => {
				this.isLoading = false;
				if (response.success && response.data) {
					// Ordenar por código ascendente
					this.puntosVenta = response.data.sort((a, b) => {
						const codigoA = typeof a.codigo_punto_venta === 'number' ? a.codigo_punto_venta : parseInt(String(a.codigo_punto_venta));
						const codigoB = typeof b.codigo_punto_venta === 'number' ? b.codigo_punto_venta : parseInt(String(b.codigo_punto_venta));
						return codigoA - codigoB;
					});
					this.updatePagination();
				}
			},
			error: (error: any) => {
				this.isLoading = false;
				console.error('Error al cargar puntos de venta:', error);
			}
		});
	}

	get filteredPuntosVenta(): PuntoVentaRow[] {
		const term = (this.searchTerm || '').toString().trim().toLowerCase();
		let filtered = this.puntosVenta;

		if (term) {
			filtered = this.puntosVenta.filter(pv => {
				const codigo = String(pv.codigo_punto_venta || '').toLowerCase();
				const nombre = (pv.nombre || '').toLowerCase();
				const desc = (pv.descripcion || '').toLowerCase();
				const suc = pv.sucursal != null ? String(pv.sucursal).toLowerCase() : '';
				const cuis = (pv.codigo_cuis_genera || '').toLowerCase();
				const tipo = pv.tipo != null ? String(pv.tipo).toLowerCase() : '';
				return (
					codigo.includes(term) ||
					nombre.includes(term) ||
					desc.includes(term) ||
					suc.includes(term) ||
					cuis.includes(term) ||
					tipo.includes(term)
				);
			});
		}

		return filtered;
	}

	get paginatedPuntosVenta(): PuntoVentaRow[] {
		const filtered = this.filteredPuntosVenta;
		const startIndex = (this.currentPage - 1) * this.itemsPerPage;
		const endIndex = startIndex + this.itemsPerPage;
		return filtered.slice(startIndex, endIndex);
	}

	updatePagination(): void {
		const filtered = this.filteredPuntosVenta;
		this.totalPages = Math.ceil(filtered.length / this.itemsPerPage);
		if (this.currentPage > this.totalPages && this.totalPages > 0) {
			this.currentPage = this.totalPages;
		}
	}

	onSearchChange(): void {
		this.currentPage = 1;
		this.updatePagination();
	}

	goToPage(page: number): void {
		if (page >= 1 && page <= this.totalPages) {
			this.currentPage = page;
		}
	}

	get pageNumbers(): number[] {
		const pages: number[] = [];
		const maxVisible = 5;
		let start = Math.max(1, this.currentPage - Math.floor(maxVisible / 2));
		let end = Math.min(this.totalPages, start + maxVisible - 1);

		if (end - start + 1 < maxVisible) {
			start = Math.max(1, end - maxVisible + 1);
		}

		for (let i = start; i <= end; i++) {
			pages.push(i);
		}
		return pages;
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
		// Mostrar notificación de éxito
		this.showSuccess('Punto de venta creado exitosamente');
		// Recargar la lista
		this.loadPuntosVenta();
	}

	onPuntoVentaError(message: string): void {
		this.showError(message || 'Error al crear punto de venta');
	}

	showSuccess(message: string): void {
		this.alertMessage = message;
		this.showSuccessAlert = true;
		setTimeout(() => {
			this.showSuccessAlert = false;
		}, 5000);
	}

	showError(message: string): void {
		this.alertMessage = message;
		this.showErrorAlert = true;
		setTimeout(() => {
			this.showErrorAlert = false;
		}, 5000);
	}

	getTipoNombre(tipo: number | null | undefined): string {
		if (tipo === null || tipo === undefined) return '—';
		const tipos: { [key: number]: string } = {
			1: 'Comisionista',
			2: 'Ventanilla',
			3: 'Móvil',
			4: 'YPFB',
			5: 'Cajero',
			6: 'Conjunta'
		};
		return tipos[tipo] || `Tipo ${tipo}`;
	}

	openAddUsersModal(puntoVenta: PuntoVentaRow): void {
		this.puntoVentaForUsers = puntoVenta;
		const modalElement = document.getElementById('addUsersModal');
		if (modalElement) {
			const modal = new bootstrap.Modal(modalElement);
			modal.show();
		}
	}

	openDeleteModal(puntoVenta: PuntoVentaRow): void {
		this.puntoVentaToDelete = puntoVenta;
		const modalElement = document.getElementById('deletePuntoVentaModal');
		if (modalElement) {
			const modal = new bootstrap.Modal(modalElement);
			modal.show();
		}
	}

	onConfirmDelete(puntoVenta: any): void {
		const modalComponent = document.querySelector('app-delete-punto-venta-modal');
		if (modalComponent) {
			(modalComponent as any).isDeleting = true;
		}

		this.puntoVentaService.deletePuntoVenta(puntoVenta.codigo_punto_venta).subscribe({
			next: (response: ApiResponse<any>) => {
				if (response.success) {
					this.showSuccess(`Punto de venta "${puntoVenta.nombre}" cerrado exitosamente`);
					this.loadPuntosVenta();
				} else {
					this.showError(response.message || 'Error al cerrar punto de venta');
				}
				this.puntoVentaToDelete = null;
				const modalElement = document.getElementById('deletePuntoVentaModal');
				if (modalElement) {
					const modal = bootstrap.Modal.getInstance(modalElement);
					if (modal) {
						modal.hide();
					}
				}
			},
			error: (error: any) => {
				console.error('Error al eliminar punto de venta:', error);
				this.showError(error.error?.message || 'Error al cerrar punto de venta');
				this.puntoVentaToDelete = null;
				const modalElement = document.getElementById('deletePuntoVentaModal');
				if (modalElement) {
					const modal = bootstrap.Modal.getInstance(modalElement);
					if (modal) {
						modal.hide();
					}
				}
			}
		});
	}

	onCancelDelete(): void {
		this.puntoVentaToDelete = null;
	}

	onUsersAdded(data: any): void {
		console.log('Usuarios agregados:', data);
		this.showSuccess(`Usuarios agregados al punto de venta "${data.puntoVenta.nombre}"`);
		this.puntoVentaForUsers = null;
	}
}
