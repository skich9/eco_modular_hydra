import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { RouterModule } from '@angular/router';
import { Usuario } from '../../../models/usuario.model';
import { UsuarioService } from '../../../services/usuario.service';

@Component({
	selector: 'app-usuarios-list',
	standalone: true,
	imports: [CommonModule, FormsModule, RouterModule],
	template: `
		<div class="usuarios-container">
			<div class="usuarios-header">
				<h1 class="usuarios-title">Gestión de Usuarios</h1>
				<div class="usuarios-actions">
					<div class="search-container">
						<input 
							type="text" 
							[(ngModel)]="searchTerm" 
							(input)="onSearch()" 
							placeholder="Buscar usuario..." 
							class="search-input"
						>
						<button class="search-button" (click)="onSearch()">
							<i class="fas fa-search"></i>
						</button>
					</div>
					<button class="btn-primary" [routerLink]="['/usuarios/nuevo']">
						<i class="fas fa-plus"></i> Nuevo Usuario
					</button>
				</div>
			</div>

			<!-- Tabla de Usuarios -->
			<div class="table-responsive">
				<table class="usuarios-table">
					<thead>
						<tr>
							<th>ID</th>
							<th>Nickname<th>
							<th>Nombre Completo</th>
							<th>CI</th>
							<th>Rol</th>
							<th>Estado</th>
							<th>Acciones</th>
						</tr>
					</thead>
					<tbody>
						<tr *ngFor="let usuario of usuarios">
							<td>{{ usuario.id_usuario }}</td>
							<td>{{ usuario.nickname }}</td>
							<td>{{ usuario.nombre }} {{ usuario.ap_paterno }} {{ usuario.ap_materno }}</td>
							<td>{{ usuario.ci }}</td>
							<td>{{ usuario.rol?.nombre || 'Sin rol' }}</td>
							<td>
								<span class="badge" [ngClass]="usuario.estado ? 'badge-active' : 'badge-inactive'">
									{{ usuario.estado ? 'Activo' : 'Inactivo' }}
								</span>
							</td>
							<td class="actions-column">
								<button class="btn-icon btn-edit" [routerLink]="['/usuarios/editar', usuario.id_usuario]" title="Editar">
									<i class="fas fa-edit"></i>
								</button>
								<button class="btn-icon btn-toggle" (click)="toggleUsuarioStatus(usuario)" title="Cambiar estado">
									<i class="fas" [ngClass]="usuario.estado ? 'fa-toggle-on' : 'fa-toggle-off'"></i>
								</button>
								<button class="btn-icon btn-delete" (click)="confirmDelete(usuario)" title="Eliminar">
									<i class="fas fa-trash"></i>
								</button>
							</td>
						</tr>
						<tr *ngIf="usuarios.length === 0">
							<td colspan="7" class="no-data">
								<div *ngIf="isLoading">Cargando usuarios...</div>
								<div *ngIf="!isLoading">No se encontraron usuarios</div>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<!-- Paginación -->
			<div class="pagination" *ngIf="usuarios.length > 0">
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
						<p>¿Está seguro que desea eliminar el usuario "{{ usuarioToDelete?.nickname }}"?</p>
						<p class="modal-warning">Esta acción no se puede deshacer.</p>
					</div>
					<div class="modal-footer">
						<button class="btn-secondary" (click)="cancelDelete()">Cancelar</button>
						<button class="btn-danger" (click)="deleteUsuario()">Eliminar</button>
					</div>
				</div>
			</div>
		</div>
	`,
	styles: `
		.usuarios-container {
			padding: 1rem;
		}

		.usuarios-header {
			display: flex;
			justify-content: space-between;
			align-items: center;
			margin-bottom: 1.5rem;
			flex-wrap: wrap;
			gap: 1rem;
		}

		.usuarios-title {
			font-size: 1.5rem;
			font-weight: 600;
			margin: 0;
		}

		.usuarios-actions {
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

		.usuarios-table {
			width: 100%;
			border-collapse: collapse;
			margin-bottom: 1rem;
		}

		.usuarios-table th, .usuarios-table td {
			padding: 0.75rem;
			text-align: left;
			border-bottom: 1px solid #e9ecef;
		}

		.usuarios-table th {
			background-color: #f8f9fa;
			font-weight: 600;
		}

		.usuarios-table tbody tr:hover {
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

		@media (max-width: 767.98px) {
			.usuarios-header {
				flex-direction: column;
				align-items: flex-start;
			}

			.search-input {
				min-width: auto;
				width: 100%;
			}

			.usuarios-actions {
				width: 100%;
				flex-direction: column;
			}

			.btn-primary {
				width: 100%;
				justify-content: center;
			}

			.actions-column {
				flex-direction: column;
			}
		}
	`
})
export class UsuariosListComponent implements OnInit {
	usuarios: Usuario[] = [];
	filteredUsuarios: Usuario[] = [];
	searchTerm = '';
	isLoading = true;
	showDeleteModal = false;
	usuarioToDelete: Usuario | null = null;
	
	// Paginación
	currentPage = 1;
	pageSize = 10;
	totalPages = 1;

	constructor(private usuarioService: UsuarioService) {}

	ngOnInit(): void {
		this.loadUsuarios();
	}

	loadUsuarios(): void {
		this.isLoading = true;
		this.usuarioService.getAll().subscribe({
			next: (response) => {
				if (response.success && response.data) {
					this.usuarios = response.data;
					this.filteredUsuarios = [...this.usuarios];
					this.applyPagination();
				}
				this.isLoading = false;
			},
			error: (error) => {
				console.error('Error al cargar usuarios:', error);
				this.isLoading = false;
			}
		});
	}

	onSearch(): void {
		if (this.searchTerm.trim() === '') {
			this.filteredUsuarios = [...this.usuarios];
		} else {
			const term = this.searchTerm.toLowerCase().trim();
			this.filteredUsuarios = this.usuarios.filter(usuario => 
				usuario.nickname.toLowerCase().includes(term) ||
				usuario.nombre.toLowerCase().includes(term) ||
				usuario.ap_paterno.toLowerCase().includes(term) ||
				usuario.ci.toLowerCase().includes(term) ||
				(usuario.rol?.nombre && usuario.rol.nombre.toLowerCase().includes(term))
			);
		}
		this.currentPage = 1;
		this.applyPagination();
	}

	toggleUsuarioStatus(usuario: Usuario): void {
		this.usuarioService.toggleStatus(usuario.id_usuario).subscribe({
			next: (response) => {
				if (response.success && response.data) {
					// Actualizar el usuario en la lista local
					const index = this.usuarios.findIndex(u => u.id_usuario === usuario.id_usuario);
					if (index !== -1) {
						this.usuarios[index].estado = !this.usuarios[index].estado;
						this.onSearch(); // Reaplica filtros y paginación
					}
				}
			},
			error: (error) => {
				console.error('Error al cambiar estado del usuario:', error);
			}
		});
	}

	confirmDelete(usuario: Usuario): void {
		this.usuarioToDelete = usuario;
		this.showDeleteModal = true;
	}

	cancelDelete(): void {
		this.usuarioToDelete = null;
		this.showDeleteModal = false;
	}

	deleteUsuario(): void {
		if (!this.usuarioToDelete) return;

		this.usuarioService.delete(this.usuarioToDelete.id_usuario).subscribe({
			next: (response) => {
				if (response.success) {
					// Eliminar el usuario de la lista local
					this.usuarios = this.usuarios.filter(u => u.id_usuario !== this.usuarioToDelete?.id_usuario);
					this.onSearch(); // Reaplica filtros y paginación
				}
				this.showDeleteModal = false;
				this.usuarioToDelete = null;
			},
			error: (error) => {
				console.error('Error al eliminar usuario:', error);
				this.showDeleteModal = false;
				this.usuarioToDelete = null;
			}
		});
	}

	applyPagination(): void {
		this.totalPages = Math.ceil(this.filteredUsuarios.length / this.pageSize) || 1;
		
		// Asegurarse de que la página actual es válida
		if (this.currentPage < 1) this.currentPage = 1;
		if (this.currentPage > this.totalPages) this.currentPage = this.totalPages;
		
		const startIndex = (this.currentPage - 1) * this.pageSize;
		const endIndex = startIndex + this.pageSize;
		this.usuarios = this.filteredUsuarios.slice(startIndex, endIndex);
	}

	goToPage(page: number): void {
		if (page >= 1 && page <= this.totalPages && page !== this.currentPage) {
			this.currentPage = page;
			this.applyPagination();
		}
	}
}
