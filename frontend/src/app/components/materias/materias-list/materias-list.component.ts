import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { RouterModule } from '@angular/router';
import { Materia } from '../../../models/materia.model';
import { MateriaService } from '../../../services/materia.service';

@Component({
	selector: 'app-materias-list',
	standalone: true,
	imports: [CommonModule, FormsModule, RouterModule],
	template: `
		<div class="materias-container">
			<div class="materias-header">
				<h1 class="materias-title">Gestión de Materias</h1>
				<div class="materias-actions">
					<div class="search-container">
						<input 
							type="text" 
							[(ngModel)]="searchTerm" 
							(input)="onSearch()" 
							placeholder="Buscar materia..." 
							class="search-input"
						>
						<button class="search-button" (click)="onSearch()">
							<i class="fas fa-search"></i>
						</button>
					</div>
					<div class="filter-container">
						<select [(ngModel)]="pensumsFilter" (change)="onFilterChange()" class="filter-select">
							<option value="">Todos los Pensums</option>
							<option *ngFor="let pensum of pensumsList" [value]="pensum.cod_pensum">
								{{ pensum.nombre }}
							</option>
						</select>
					</div>
					<button class="btn-primary" [routerLink]="['/materias/nuevo']">
						<i class="fas fa-plus"></i> Nueva Materia
					</button>
				</div>
			</div>

			<!-- Tabla de Materias -->
			<div class="table-responsive">
				<table class="materias-table">
					<thead>
						<tr>
							<th>Sigla</th>
							<th>Nombre</th>
							<th>Pensum</th>
							<th>Créditos</th>
							<th>Estado</th>
							<th>Acciones</th>
						</tr>
					</thead>
					<tbody>
						<tr *ngFor="let materia of materias">
							<td>{{ materia.sigla_materia }}</td>
							<td>{{ materia.nombre_materia }}</td>
							<td>{{ materia.pensum?.nombre || 'Sin pensum' }}</td>
							<td>{{ materia.nro_creditos }}</td>
							<td>
								<span class="badge" [ngClass]="materia.estado ? 'badge-active' : 'badge-inactive'">
									{{ materia.estado ? 'Activa' : 'Inactiva' }}
								</span>
							</td>
							<td class="actions-column">
								<button class="btn-icon btn-edit" [routerLink]="['/materias/editar', materia.sigla_materia, materia.cod_pensum]" title="Editar">
									<i class="fas fa-edit"></i>
								</button>
								<button class="btn-icon btn-toggle" (click)="toggleMateriaStatus(materia)" title="Cambiar estado">
									<i class="fas" [ngClass]="materia.estado ? 'fa-toggle-on' : 'fa-toggle-off'"></i>
								</button>
								<button class="btn-icon btn-delete" (click)="confirmDelete(materia)" title="Eliminar">
									<i class="fas fa-trash"></i>
								</button>
							</td>
						</tr>
						<tr *ngIf="materias.length === 0">
							<td colspan="6" class="no-data">
								<div *ngIf="isLoading">Cargando materias...</div>
								<div *ngIf="!isLoading">No se encontraron materias</div>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<!-- Paginación -->
			<div class="pagination" *ngIf="materias.length > 0">
				<button class="pagination-button" [disabled]="currentPage === 1" (click)="goToPage(currentPage - 1)">
					<i class="fas fa-chevron-left"></i>
				</button>
				<span class="pagination-info">
					Página {{ currentPage }} de {{ totalPages }}
				</span>
				<button class="pagination-button" [disabled]="currentPage === totalPages" (click)="goToPage(currentPage + 1)">
					<i class="fas fa-chevron-right"></i>
				</button>
			</div>

			<!-- Modal de Confirmación para Eliminar -->
			<div class="modal" *ngIf="showDeleteModal">
				<div class="modal-content">
					<div class="modal-header">
						<h2 class="modal-title">Confirmar Eliminación</h2>
						<button class="modal-close" (click)="cancelDelete()">×</button>
					</div>
					<div class="modal-body">
						<p>¿Está seguro que desea eliminar la materia "{{ materiaToDelete?.nombre_materia }}"?</p>
						<p class="modal-warning">Esta acción no se puede deshacer.</p>
					</div>
					<div class="modal-footer">
						<button class="btn-secondary" (click)="cancelDelete()">Cancelar</button>
						<button class="btn-danger" (click)="deleteMateria()">Eliminar</button>
					</div>
				</div>
			</div>
		</div>
	`,
	styles: `
		.materias-container {
			padding: 1rem;
		}

		.materias-header {
			display: flex;
			justify-content: space-between;
			align-items: center;
			margin-bottom: 1.5rem;
			flex-wrap: wrap;
			gap: 1rem;
		}

		.materias-title {
			font-size: 1.5rem;
			font-weight: 600;
			margin: 0;
		}

		.materias-actions {
			display: flex;
			gap: 0.75rem;
			align-items: center;
		}

		.search-container {
			position: relative;
		}

		.search-input {
			padding: 0.5rem 2rem 0.5rem 0.75rem;
			border: 1px solid #ddd;
			border-radius: 4px;
			min-width: 250px;
		}

		.search-button {
			position: absolute;
			right: 0.5rem;
			top: 50%;
			transform: translateY(-50%);
			background: none;
			border: none;
			cursor: pointer;
			color: #6c757d;
		}

		.filter-select {
			padding: 0.5rem;
			border: 1px solid #ddd;
			border-radius: 4px;
			min-width: 200px;
		}

		.btn-primary {
			background-color: #0275d8;
			color: white;
			border: none;
			padding: 0.5rem 1rem;
			border-radius: 4px;
			cursor: pointer;
			display: flex;
			align-items: center;
			gap: 0.5rem;
		}

		.btn-primary:hover {
			background-color: #0069d9;
		}

		.table-responsive {
			overflow-x: auto;
		}

		.materias-table {
			width: 100%;
			border-collapse: collapse;
			margin-bottom: 1rem;
		}

		.materias-table th, .materias-table td {
			padding: 0.75rem;
			text-align: left;
			border-bottom: 1px solid #e9ecef;
		}

		.materias-table th {
			background-color: #f8f9fa;
			font-weight: 600;
		}

		.materias-table tbody tr:hover {
			background-color: #f8f9fa;
		}

		.badge {
			display: inline-block;
			padding: 0.25rem 0.5rem;
			border-radius: 0.25rem;
			font-size: 0.75rem;
		}

		.badge-active {
			background-color: #28a745;
			color: white;
		}

		.badge-inactive {
			background-color: #dc3545;
			color: white;
		}

		.actions-column {
			white-space: nowrap;
			display: flex;
			gap: 0.5rem;
		}

		.btn-icon {
			background: none;
			border: none;
			cursor: pointer;
			padding: 0.25rem 0.5rem;
			border-radius: 4px;
		}

		.btn-edit {
			color: #0275d8;
		}

		.btn-toggle {
			color: #6c757d;
		}

		.btn-delete {
			color: #dc3545;
		}

		.no-data {
			text-align: center;
			padding: 2rem;
			color: #6c757d;
		}

		.pagination {
			display: flex;
			justify-content: center;
			align-items: center;
			margin-top: 1rem;
			gap: 0.5rem;
		}

		.pagination-button {
			background: none;
			border: 1px solid #ddd;
			padding: 0.25rem 0.5rem;
			border-radius: 4px;
			cursor: pointer;
		}

		.pagination-button:disabled {
			opacity: 0.5;
			cursor: not-allowed;
		}

		.pagination-info {
			color: #6c757d;
		}

		.modal {
			position: fixed;
			top: 0;
			left: 0;
			width: 100%;
			height: 100%;
			background-color: rgba(0, 0, 0, 0.5);
			display: flex;
			justify-content: center;
			align-items: center;
			z-index: 1000;
		}

		.modal-content {
			background-color: white;
			border-radius: 8px;
			max-width: 500px;
			width: 90%;
		}

		.modal-header {
			display: flex;
			justify-content: space-between;
			align-items: center;
			padding: 1rem;
			border-bottom: 1px solid #e9ecef;
		}

		.modal-title {
			margin: 0;
			font-size: 1.25rem;
		}

		.modal-close {
			background: none;
			border: none;
			font-size: 1.5rem;
			cursor: pointer;
			color: #6c757d;
		}

		.modal-body {
			padding: 1rem;
		}

		.modal-warning {
			color: #dc3545;
			font-weight: 500;
		}

		.modal-footer {
			display: flex;
			justify-content: flex-end;
			gap: 0.5rem;
			padding: 1rem;
			border-top: 1px solid #e9ecef;
		}

		.btn-secondary {
			background-color: #6c757d;
			color: white;
			border: none;
			padding: 0.5rem 1rem;
			border-radius: 4px;
			cursor: pointer;
		}

		.btn-danger {
			background-color: #dc3545;
			color: white;
			border: none;
			padding: 0.5rem 1rem;
			border-radius: 4px;
			cursor: pointer;
		}

		@media (max-width: 991.98px) {
			.materias-header {
				flex-direction: column;
				align-items: flex-start;
			}

			.materias-actions {
				width: 100%;
				flex-direction: column;
			}

			.search-input, .filter-select {
				min-width: auto;
				width: 100%;
			}

			.btn-primary {
				width: 100%;
				justify-content: center;
			}
		}
	`
})
export class MateriasListComponent implements OnInit {
	materias: Materia[] = [];
	allMaterias: Materia[] = [];
	filteredMaterias: Materia[] = [];
	pensumsList: { cod_pensum: string; nombre: string }[] = [];
	searchTerm = '';
	pensumsFilter = '';
	isLoading = true;
	showDeleteModal = false;
	materiaToDelete: Materia | null = null;
	
	// Paginación
	currentPage = 1;
	pageSize = 10;
	totalPages = 1;

	constructor(private materiaService: MateriaService) {}

	ngOnInit(): void {
		this.loadMaterias();
		// En un escenario real, cargaríamos la lista de pensums desde un servicio
		this.loadPensums();
	}

	loadMaterias(): void {
		this.isLoading = true;
		this.materiaService.getAll().subscribe({
			next: (response) => {
				if (response.success && response.data) {
					this.allMaterias = response.data;
					this.applyFilters();
				}
				this.isLoading = false;
			},
			error: (error) => {
				console.error('Error al cargar materias:', error);
				this.isLoading = false;
			}
		});
	}

	// En un escenario real, cargaríamos los pensums desde un servicio específico
	loadPensums(): void {
		// Datos de ejemplo
		this.pensumsList = [
			{ cod_pensum: 'ING-SIS-2020', nombre: 'Ingeniería de Sistemas 2020' },
			{ cod_pensum: 'CONT-2019', nombre: 'Contaduría 2019' },
			{ cod_pensum: 'ADM-EMP-2021', nombre: 'Administración de Empresas 2021' }
		];
	}

	onSearch(): void {
		this.applyFilters();
	}

	onFilterChange(): void {
		this.applyFilters();
	}

	applyFilters(): void {
		let filtered = [...this.allMaterias];
		
		// Aplicar búsqueda por texto
		if (this.searchTerm.trim() !== '') {
			const term = this.searchTerm.toLowerCase().trim();
			filtered = filtered.filter(materia => 
				materia.sigla_materia.toLowerCase().includes(term) ||
				materia.nombre_materia.toLowerCase().includes(term) ||
				(materia.pensum?.nombre && materia.pensum.nombre.toLowerCase().includes(term))
			);
		}
		
		// Aplicar filtro por pensum
		if (this.pensumsFilter !== '') {
			filtered = filtered.filter(materia => materia.cod_pensum === this.pensumsFilter);
		}
		
		this.filteredMaterias = filtered;
		this.currentPage = 1;
		this.applyPagination();
	}

	toggleMateriaStatus(materia: Materia): void {
		this.materiaService.toggleStatus(materia.sigla_materia, materia.cod_pensum).subscribe({
			next: (response) => {
				if (response.success && response.data) {
					// Actualizar la materia en la lista completa y reaplicar filtros/paginación
					const indexAll = this.allMaterias.findIndex(m => m.sigla_materia === response.data.sigla_materia && m.cod_pensum === response.data.cod_pensum);
					if (indexAll !== -1) {
						this.allMaterias[indexAll] = response.data;
					}
					this.applyFilters();
				}
			},
			error: (error) => {
				console.error('Error al cambiar estado de la materia:', error);
			}
		});
	}

	confirmDelete(materia: Materia): void {
		this.materiaToDelete = materia;
		this.showDeleteModal = true;
	}

	cancelDelete(): void {
		this.materiaToDelete = null;
		this.showDeleteModal = false;
	}

	deleteMateria(): void {
		if (!this.materiaToDelete) return;

		this.materiaService.delete(this.materiaToDelete.sigla_materia, this.materiaToDelete.cod_pensum).subscribe({
			next: (response) => {
				if (response.success) {
					// Eliminar la materia de la lista completa y reaplicar filtros
					this.allMaterias = this.allMaterias.filter(m => !(m.sigla_materia === this.materiaToDelete?.sigla_materia && m.cod_pensum === this.materiaToDelete?.cod_pensum));
					this.applyFilters(); // Reaplica filtros y paginación
				}
				this.showDeleteModal = false;
				this.materiaToDelete = null;
			},
			error: (error) => {
				console.error('Error al eliminar materia:', error);
				this.showDeleteModal = false;
				this.materiaToDelete = null;
			}
		});
	}

	applyPagination(): void {
		this.totalPages = Math.ceil(this.filteredMaterias.length / this.pageSize) || 1;
		
		// Asegurarse de que la página actual es válida
		if (this.currentPage < 1) this.currentPage = 1;
		if (this.currentPage > this.totalPages) this.currentPage = this.totalPages;
		
		const startIndex = (this.currentPage - 1) * this.pageSize;
		const endIndex = startIndex + this.pageSize;
		this.materias = this.filteredMaterias.slice(startIndex, endIndex);
	}

	goToPage(page: number): void {
		if (page >= 1 && page <= this.totalPages && page !== this.currentPage) {
			this.currentPage = page;
			this.applyPagination();
		}
	}
}
