import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ReactiveFormsModule, FormBuilder, FormGroup, Validators } from '@angular/forms';
import { DescuentosService } from '../../../services/descuentos.service';
import { Descuento } from '../../../models/descuento.model';

@Component({
	selector: 'app-descuentos',
	standalone: true,
	imports: [CommonModule, FormsModule, ReactiveFormsModule],
	templateUrl: './descuentos.component.html',
	styleUrls: ['./descuentos.component.scss']
})
export class DescuentosComponent implements OnInit {
	// Estado de lista
	all: Descuento[] = [];
	filtered: Descuento[] = [];
	descuentos: Descuento[] = [];
	isLoading = true;
	searchTerm = '';

	// Paginación
	currentPage = 1;
	pageSize = 10;
	totalPages = 1;

	// Formulario
	form!: FormGroup;
	showFormModal = false;
	isSaving = false;
	editing: Descuento | null = null;

	// Eliminación
	showDeleteModal = false;
	itemToDelete: Descuento | null = null;

	constructor(private descuentosService: DescuentosService, private fb: FormBuilder) {}

	ngOnInit(): void {
		this.buildForm();
		this.load();
	}

	buildForm(): void {
		this.form = this.fb.group({
			cod_ceta: [null, [Validators.required]],
			cod_pensum: ['', [Validators.required]],
			cod_inscrip: [null, [Validators.required]],
			id_usuario: [null, [Validators.required]],
			nombre: ['', [Validators.required, Validators.maxLength(255)]],
			observaciones: [null],
			porcentaje: [0, [Validators.required, Validators.min(0), Validators.max(100)]],
			tipo: [null],
			estado: [true]
		});
	}

	load(): void {
		this.isLoading = true;
		this.descuentosService.getAll().subscribe({
			next: (res) => {
				if (res.success) {
					this.all = res.data;
					this.applyFilters();
				}
				this.isLoading = false;
			},
			error: (err) => {
				console.error('Error cargando descuentos:', err);
				this.isLoading = false;
			}
		});
	}

	onSearch(): void { this.applyFilters(); }

	applyFilters(): void {
		let data = [...this.all];
		const term = this.searchTerm.trim().toLowerCase();
		if (term) {
			data = data.filter(d =>
				(d.nombre || '').toLowerCase().includes(term) ||
				(d.tipo || '').toLowerCase().includes(term) ||
				(d.cod_pensum || '').toLowerCase().includes(term) ||
				String(d.cod_ceta || '').includes(term) ||
				String(d.cod_inscrip || '').includes(term)
			);
		}
		this.filtered = data;
		this.currentPage = 1;
		this.applyPagination();
	}

	applyPagination(): void {
		this.totalPages = Math.ceil(this.filtered.length / this.pageSize) || 1;
		if (this.currentPage < 1) this.currentPage = 1;
		if (this.currentPage > this.totalPages) this.currentPage = this.totalPages;
		const start = (this.currentPage - 1) * this.pageSize;
		const end = start + this.pageSize;
		this.descuentos = this.filtered.slice(start, end);
	}

	goToPage(p: number): void { if (p >= 1 && p <= this.totalPages && p !== this.currentPage) { this.currentPage = p; this.applyPagination(); } }

	openCreate(): void { this.editing = null; this.form.reset({ estado: true, porcentaje: 0 }); this.showFormModal = true; }
	openEdit(d: Descuento): void {
		this.editing = d;
		this.form.reset({
			cod_ceta: d.cod_ceta,
			cod_pensum: d.cod_pensum,
			cod_inscrip: d.cod_inscrip,
			id_usuario: d.id_usuario,
			nombre: d.nombre,
			observaciones: d.observaciones ?? null,
			porcentaje: d.porcentaje,
			tipo: d.tipo ?? null,
			estado: d.estado
		});
		this.showFormModal = true;
	}

	closeForm(): void { this.showFormModal = false; this.isSaving = false; }

	save(): void {
		if (this.form.invalid) return;
		this.isSaving = true;
		const payload = this.form.value;
		if (this.editing) {
			this.descuentosService.update(this.editing.id_descuentos, payload).subscribe({
				next: (res) => {
					if (res.success) {
						const idx = this.all.findIndex(x => x.id_descuentos === res.data.id_descuentos);
						if (idx !== -1) this.all[idx] = res.data;
						this.applyFilters();
					}
					this.closeForm();
				},
				error: (err) => { console.error('Error al actualizar:', err); this.isSaving = false; }
			});
		} else {
			this.descuentosService.create(payload).subscribe({
				next: (res) => {
					if (res.success) {
						this.all.unshift(res.data);
						this.applyFilters();
					}
					this.closeForm();
				},
				error: (err) => { console.error('Error al crear:', err); this.isSaving = false; }
			});
		}
	}

	toggle(d: Descuento): void {
		this.descuentosService.toggleStatus(d.id_descuentos).subscribe({
			next: (res) => {
				if (res.success) {
					const idx = this.all.findIndex(x => x.id_descuentos === res.data.id_descuentos);
					if (idx !== -1) this.all[idx] = res.data;
					this.applyFilters();
				}
			},
			error: (err) => console.error('Error al cambiar estado:', err)
		});
	}

	confirmDelete(d: Descuento): void { this.itemToDelete = d; this.showDeleteModal = true; }
	cancelDelete(): void { this.itemToDelete = null; this.showDeleteModal = false; }
	deleteConfirm(): void {
		if (!this.itemToDelete) return;
		this.descuentosService.delete(this.itemToDelete.id_descuentos).subscribe({
			next: (res) => {
				if (res.success) {
					this.all = this.all.filter(x => x.id_descuentos !== this.itemToDelete!.id_descuentos);
					this.applyFilters();
				}
				this.cancelDelete();
			},
			error: (err) => { console.error('Error al eliminar:', err); this.cancelDelete(); }
		});
	}
}
