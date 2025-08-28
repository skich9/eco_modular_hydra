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

	// UI estado - Descuentos (crear/editar/eliminar)
	showDefModal = false;
	defEditing: DefDescuento | null = null;
	defForm: Partial<DefDescuento> = {};
	defSaving = false;
	defError: string | null = null;
	showDefDeleteModal = false;
	defToDelete: DefDescuento | null = null;
	defDeleting = false;

	// UI estado - Becas (crear/editar/eliminar)
	showBecaModal = false;
	becaEditing: DefDescuentoBeca | null = null;
	becaForm: Partial<DefDescuentoBeca> = {};
	becaSaving = false;
	becaError: string | null = null;
	showBecaDeleteModal = false;
	becaToDelete: DefDescuentoBeca | null = null;
	becaDeleting = false;

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

	// CRUD Descuentos
	openNewDef(): void {
		this.defEditing = null;
		this.defForm = { nombre_descuento: '', descripcion: null, porcentaje: true, monto: 0, estado: true } as Partial<DefDescuento>;
		this.defError = null;
		this.showDefModal = true;
	}

	openEditDef(item: DefDescuento): void {
		this.defEditing = item;
		this.defForm = { ...item };
		this.defError = null;
		this.showDefModal = true;
	}

	closeDefModal(): void {
		this.showDefModal = false;
		this.defEditing = null;
		this.defForm = {};
		this.defSaving = false;
		this.defError = null;
	}

	saveDef(): void {
		if (this.defSaving) return;
		this.defSaving = true;
		this.defError = null;
		const payload: any = {
			nombre_descuento: this.defForm.nombre_descuento,
			descripcion: this.defForm.descripcion ?? null,
			monto: Number(this.defForm.monto ?? 0),
			porcentaje: !!this.defForm.porcentaje,
			estado: !!this.defForm.estado,
		};
		const obs = this.defEditing
			? this.defSrv.update(this.defEditing.cod_descuento, payload)
			: this.defSrv.create(payload);
		obs.subscribe({
			next: (res) => {
				if (res?.success && res.data) {
					if (this.defEditing) {
						const idx = this.defAll.findIndex(x => x.cod_descuento === res.data.cod_descuento);
						if (idx !== -1) this.defAll[idx] = res.data;
					} else {
						this.defAll = [res.data, ...this.defAll];
					}
					this.applyFiltersDef();
					this.closeDefModal();
				}
				this.defSaving = false;
			},
			error: (err) => {
				this.defError = err?.error?.message || 'Error al guardar';
				this.defSaving = false;
			}
		});
	}

	confirmDeleteDef(item: DefDescuento): void {
		this.defToDelete = item;
		this.showDefDeleteModal = true;
	}

	cancelDeleteDef(): void {
		this.showDefDeleteModal = false;
		this.defToDelete = null;
		this.defDeleting = false;
	}

	deleteDef(): void {
		if (!this.defToDelete || this.defDeleting) return;
		this.defDeleting = true;
		this.defSrv.delete(this.defToDelete.cod_descuento).subscribe({
			next: (res) => {
				if (res?.success) {
					this.defAll = this.defAll.filter(x => x.cod_descuento !== this.defToDelete!.cod_descuento);
					this.applyFiltersDef();
				}
				this.cancelDeleteDef();
			},
			error: () => { this.cancelDeleteDef(); }
		});
	}

	// CRUD Becas
	openNewBeca(): void {
		this.becaEditing = null;
		this.becaForm = { nombre_beca: '', descripcion: null, porcentaje: true, monto: 0, estado: true } as Partial<DefDescuentoBeca>;
		this.becaError = null;
		this.showBecaModal = true;
	}

	openEditBeca(item: DefDescuentoBeca): void {
		this.becaEditing = item;
		this.becaForm = { ...item };
		this.becaError = null;
		this.showBecaModal = true;
	}

	closeBecaModal(): void {
		this.showBecaModal = false;
		this.becaEditing = null;
		this.becaForm = {};
		this.becaSaving = false;
		this.becaError = null;
	}

	saveBeca(): void {
		if (this.becaSaving) return;
		this.becaSaving = true;
		this.becaError = null;
		const payload: any = {
			nombre_beca: this.becaForm.nombre_beca,
			descripcion: this.becaForm.descripcion ?? null,
			monto: Number(this.becaForm.monto ?? 0),
			porcentaje: !!this.becaForm.porcentaje,
			estado: !!this.becaForm.estado,
		};
		const obs = this.becaEditing
			? this.becaSrv.update(this.becaEditing.cod_beca, payload)
			: this.becaSrv.create(payload);
		obs.subscribe({
			next: (res) => {
				if (res?.success && res.data) {
					if (this.becaEditing) {
						const idx = this.becaAll.findIndex(x => x.cod_beca === res.data.cod_beca);
						if (idx !== -1) this.becaAll[idx] = res.data;
					} else {
						this.becaAll = [res.data, ...this.becaAll];
					}
					this.applyFiltersBeca();
					this.closeBecaModal();
				}
				this.becaSaving = false;
			},
			error: (err) => {
				this.becaError = err?.error?.message || 'Error al guardar';
				this.becaSaving = false;
			}
		});
	}

	confirmDeleteBeca(item: DefDescuentoBeca): void {
		this.becaToDelete = item;
		this.showBecaDeleteModal = true;
	}

	cancelDeleteBeca(): void {
		this.showBecaDeleteModal = false;
		this.becaToDelete = null;
		this.becaDeleting = false;
	}

	deleteBeca(): void {
		if (!this.becaToDelete || this.becaDeleting) return;
		this.becaDeleting = true;
		this.becaSrv.delete(this.becaToDelete.cod_beca).subscribe({
			next: (res) => {
				if (res?.success) {
					this.becaAll = this.becaAll.filter(x => x.cod_beca !== this.becaToDelete!.cod_beca);
					this.applyFiltersBeca();
				}
				this.cancelDeleteBeca();
			},
			error: () => { this.cancelDeleteBeca(); }
		});
	}
}
