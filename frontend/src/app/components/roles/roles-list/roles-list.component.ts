import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { RouterModule } from '@angular/router';
import { Rol } from '../../../models/usuario.model';
import { RolService } from '../../../services/rol.service';

@Component({
	selector: 'app-roles-list',
	standalone: true,
	imports: [CommonModule, FormsModule, RouterModule],
	template: `
		<div class="roles-container">
			<div class="roles-header">
				<h1 class="roles-title">Gestión de Roles</h1>
				<div class="roles-actions">
					<div class="search-container">
						<input 
							type="text" 
							[(ngModel)]="searchTerm" 
							(input)="onSearch()" 
							placeholder="Buscar rol..." 
							class="search-input"
						>
						<button class="search-button" (click)="onSearch()">
							<i class="fas fa-search"></i>
						</button>
					</div>
					<button class="btn-primary" [routerLink]="['/roles/nuevo']">
						<i class="fas fa-plus"></i> Nuevo Rol
					</button>
				</div>
			</div>

			<!-- Tabla de Roles -->
			<div class="table-responsive">
				<table class="roles-table">
					<thead>
						<tr>
							<th>ID</th>
							<th>Nombre</th>
							<th>Descripción</th>
							<th>Estado</th>
							<th>Acciones</th>
						</tr>
					</thead>
					<tbody>
						<tr *ngFor="let rol of roles">
							<td>{{ rol.id_rol }}</td>
							<td>{{ rol.nombre }}</td>
							<td>{{ rol.descripcion || 'Sin descripción' }}</td>
							<td>
								<span class="badge" [ngClass]="rol.estado ? 'badge-active' : 'badge-inactive'">
									{{ rol.estado ? 'Activo' : 'Inactivo' }}
								</span>
							</td>
							<td class="actions-column">
								<button class="btn-icon btn-edit" [routerLink]="['/roles/editar', rol.id_rol]" title="Editar">
									<i class="fas fa-edit"></i>
								</button>
								<button class="btn-icon btn-toggle" (click)="toggleRolStatus(rol)" title="Cambiar estado">
									<i class="fas" [ngClass]="rol.estado ? 'fa-toggle-on' : 'fa-toggle-off'"></i>
								</button>
								<button class="btn-icon btn-delete" (click)="confirmDelete(rol)" title="Eliminar">
									<i class="fas fa-trash"></i>
								</button>
							</td>
						</tr>
						<tr *ngIf="roles.length === 0">
							<td colspan="5" class="no-data">
								<div *ngIf="isLoading">Cargando roles...</div>
								<div *ngIf="!isLoading">No se encontraron roles</div>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<!-- Modal de Confirmación para Eliminar -->
			<div class="modal" *ngIf="showDeleteModal">
				<div class="modal-content">
					<div class="modal-header">
						<h2 class="modal-title">Confirmar Eliminación</h2>
						<button class="modal-close" (click)="cancelDelete()">×</button>
					</div>
					<div class="modal-body">
						<p>¿Está seguro que desea eliminar el rol "{{ rolToDelete?.nombre }}"?</p>
						<p class="modal-warning">Esta acción no se puede deshacer.</p>
						<p class="modal-warning">Nota: Eliminar un rol puede afectar a los usuarios que tienen este rol asignado.</p>
					</div>
					<div class="modal-footer">
						<button class="btn-secondary" (click)="cancelDelete()">Cancelar</button>
						<button class="btn-danger" (click)="deleteRol()">Eliminar</button>
					</div>
				</div>
			</div>
		</div>
	`,
	styles: `
		.roles-container {
			padding: 1rem;
		}

		.roles-header {
			display: flex;
			justify-content: space-between;
			align-items: center;
			margin-bottom: 1.5rem;
			flex-wrap: wrap;
			gap: 1rem;
		}

		.roles-title {
			font-size: 1.5rem;
			font-weight: 600;
			margin: 0;
		}

		.roles-actions {
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

		.roles-table {
			width: 100%;
			border-collapse: collapse;
			margin-bottom: 1rem;
		}

		.roles-table th, .roles-table td {
			padding: 0.75rem;
			text-align: left;
			border-bottom: 1px solid #e9ecef;
		}

		.roles-table th {
			background-color: #f8f9fa;
			font-weight: 600;
		}

		.roles-table tbody tr:hover {
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

		@media (max-width: 767.98px) {
			.roles-header {
				flex-direction: column;
				align-items: flex-start;
			}

			.search-input {
				min-width: auto;
				width: 100%;
			}

			.roles-actions {
				width: 100%;
				flex-direction: column;
			}

			.btn-primary {
				width: 100%;
				justify-content: center;
			}
		}
	`
})
export class RolesListComponent implements OnInit {
	roles: Rol[] = [];
	filteredRoles: Rol[] = [];
	searchTerm = '';
	isLoading = true;
	showDeleteModal = false;
	rolToDelete: Rol | null = null;

	constructor(private rolService: RolService) {}

	ngOnInit(): void {
		this.loadRoles();
	}

	loadRoles(): void {
		this.isLoading = true;
		this.rolService.getAll().subscribe({
			next: (response) => {
				if (response.success && response.data) {
					this.roles = response.data;
					this.filteredRoles = [...this.roles];
				}
				this.isLoading = false;
			},
			error: (error) => {
				console.error('Error al cargar roles:', error);
				this.isLoading = false;
			}
		});
	}

	onSearch(): void {
		if (this.searchTerm.trim() === '') {
			this.roles = [...this.filteredRoles];
		} else {
			const term = this.searchTerm.toLowerCase().trim();
			this.roles = this.filteredRoles.filter(rol => 
				rol.nombre.toLowerCase().includes(term) ||
				(rol.descripcion && rol.descripcion.toLowerCase().includes(term))
			);
		}
	}

	toggleRolStatus(rol: Rol): void {
		this.rolService.toggleStatus(rol.id_rol).subscribe({
			next: (response) => {
				if (response.success && response.data) {
					// Actualizar el rol en la lista local
					const index = this.filteredRoles.findIndex(r => r.id_rol === rol.id_rol);
					if (index !== -1) {
						this.filteredRoles[index].estado = !this.filteredRoles[index].estado;
						this.onSearch(); // Reaplica filtros
					}
				}
			},
			error: (error) => {
				console.error('Error al cambiar estado del rol:', error);
			}
		});
	}

	confirmDelete(rol: Rol): void {
		this.rolToDelete = rol;
		this.showDeleteModal = true;
	}

	cancelDelete(): void {
		this.rolToDelete = null;
		this.showDeleteModal = false;
	}

	deleteRol(): void {
		if (!this.rolToDelete) return;

		this.rolService.delete(this.rolToDelete.id_rol).subscribe({
			next: (response) => {
				if (response.success) {
					// Eliminar el rol de la lista local
					this.filteredRoles = this.filteredRoles.filter(r => r.id_rol !== this.rolToDelete?.id_rol);
					this.onSearch(); // Reaplica filtros
				}
				this.showDeleteModal = false;
				this.rolToDelete = null;
			},
			error: (error) => {
				console.error('Error al eliminar rol:', error);
				this.showDeleteModal = false;
				this.rolToDelete = null;
			}
		});
	}
}
