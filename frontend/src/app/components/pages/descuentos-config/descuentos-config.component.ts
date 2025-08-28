import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { DefDescuento } from '../../../models/def-descuento.model';
import { DefDescuentoBeca } from '../../../models/def-descuento-beca.model';
import { DefDescuentosService } from '../../../services/def-descuentos.service';
import { DefDescuentosBecaService } from '../../../services/def-descuentos-beca.service';

@Component({
	selector: 'app-descuentos-config',
	standalone: true,
	imports: [CommonModule, FormsModule],
	templateUrl: './descuentos-config.component.html',
	styleUrls: ['./descuentos-config.component.scss']
})
export class DescuentosConfigComponent implements OnInit {
	activeTab: 'descuentos' | 'becas' = 'descuentos';
	searchTerm = '';

	// Descuentos
	defAll: DefDescuento[] = [];
	defFiltered: DefDescuento[] = [];
	defPage: DefDescuento[] = [];
	defLoading = false;
	defPageSize = 10;
	defCurrentPage = 1;
	defTotalPages = 1;

	// Becas
	becaAll: DefDescuentoBeca[] = [];
	becaFiltered: DefDescuentoBeca[] = [];
	becaPage: DefDescuentoBeca[] = [];
	becaLoading = false;
	becaPageSize = 10;
	becaCurrentPage = 1;
	becaTotalPages = 1;

	constructor(
		private defSrv: DefDescuentosService,
		private becaSrv: DefDescuentosBecaService
	) {}

	ngOnInit(): void {
		this.loadDef();
		this.loadBeca();
	}

	setTab(tab: 'descuentos' | 'becas'): void {
		this.activeTab = tab;
		this.onSearch();
	}

	// Carga
	loadDef(): void {
		this.defLoading = true;
		this.defSrv.getAll().subscribe({
			next: (res: { success: boolean; data: DefDescuento[]; message?: string }) => {
				this.defAll = res.data || [];
				this.applyFiltersDef();
				this.defLoading = false;
			},
			error: (err: any) => { console.error('Error cargando def_descuentos', err); this.defLoading = false; }
		});
	}

	loadBeca(): void {
		this.becaLoading = true;
		this.becaSrv.getAll().subscribe({
			next: (res: { success: boolean; data: DefDescuentoBeca[]; message?: string }) => {
				this.becaAll = res.data || [];
				this.applyFiltersBeca();
				this.becaLoading = false;
			},
			error: (err: any) => { console.error('Error cargando def_descuentos_beca', err); this.becaLoading = false; }
		});
	}

	// Búsqueda
	onSearch(): void {
		if (this.activeTab === 'descuentos') this.applyFiltersDef();
		else this.applyFiltersBeca();
	}

	private normalizeText(v: any): string { return (v ?? '').toString().trim().toLowerCase(); }

	applyFiltersDef(): void {
		const term = this.normalizeText(this.searchTerm);
		let data = [...this.defAll];
		if (term) {
			data = data.filter(i =>
				this.normalizeText(i.nombre_descuento).includes(term) ||
				this.normalizeText(i.descripcion).includes(term) ||
				String(i.cod_descuento).includes(term)
			);
		}
		this.defFiltered = data;
		this.defCurrentPage = 1;
		this.applyPaginationDef();
	}

	applyPaginationDef(): void {
		this.defTotalPages = Math.ceil(this.defFiltered.length / this.defPageSize) || 1;
		if (this.defCurrentPage < 1) this.defCurrentPage = 1;
		if (this.defCurrentPage > this.defTotalPages) this.defCurrentPage = this.defTotalPages;
		const start = (this.defCurrentPage - 1) * this.defPageSize;
		const end = start + this.defPageSize;
		this.defPage = this.defFiltered.slice(start, end);
	}

	applyFiltersBeca(): void {
		const term = this.normalizeText(this.searchTerm);
		let data = [...this.becaAll];
		if (term) {
			data = data.filter(i =>
				this.normalizeText(i.nombre_beca).includes(term) ||
				this.normalizeText(i.descripcion).includes(term) ||
				String(i.cod_beca).includes(term)
			);
		}
		this.becaFiltered = data;
		this.becaCurrentPage = 1;
		this.applyPaginationBeca();
	}

	applyPaginationBeca(): void {
		this.becaTotalPages = Math.ceil(this.becaFiltered.length / this.becaPageSize) || 1;
		if (this.becaCurrentPage < 1) this.becaCurrentPage = 1;
		if (this.becaCurrentPage > this.becaTotalPages) this.becaCurrentPage = this.becaTotalPages;
		const start = (this.becaCurrentPage - 1) * this.becaPageSize;
		const end = start + this.becaPageSize;
		this.becaPage = this.becaFiltered.slice(start, end);
	}

	goToPageDef(p: number): void { if (p >= 1 && p <= this.defTotalPages && p !== this.defCurrentPage) { this.defCurrentPage = p; this.applyPaginationDef(); } }
	goToPageBeca(p: number): void { if (p >= 1 && p <= this.becaTotalPages && p !== this.becaCurrentPage) { this.becaCurrentPage = p; this.applyPaginationBeca(); } }

	// Acciones
	toggleDef(item: DefDescuento): void {
		this.defSrv.toggleStatus(item.cod_descuento).subscribe({
			next: (res: { success: boolean; data: DefDescuento; message?: string }) => {
				if (res?.success && res.data) {
					const idx = this.defAll.findIndex(x => x.cod_descuento === res.data.cod_descuento);
					if (idx !== -1) this.defAll[idx] = res.data;
					this.applyFiltersDef();
				}
			},
			error: (err: any) => console.error('Error al cambiar estado', err)
		});
	}

	toggleBeca(item: DefDescuentoBeca): void {
		this.becaSrv.toggleStatus(item.cod_beca).subscribe({
			next: (res: { success: boolean; data: DefDescuentoBeca; message?: string }) => {
				if (res?.success && res.data) {
					const idx = this.becaAll.findIndex(x => x.cod_beca === res.data.cod_beca);
					if (idx !== -1) this.becaAll[idx] = res.data;
					this.applyFiltersBeca();
				}
			},
			error: (err: any) => console.error('Error al cambiar estado', err)
		});
	}
}
