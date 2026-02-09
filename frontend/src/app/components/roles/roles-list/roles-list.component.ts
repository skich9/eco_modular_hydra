import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { RouterModule } from '@angular/router';
import { Rol } from '../../../models/usuario.model';
import { RolService } from '../../../services/rol.service';
import { FuncionService } from '../../../services/funcion.service';

@Component({
	selector: 'app-roles-list',
	standalone: true,
	imports: [CommonModule, FormsModule, RouterModule],
	templateUrl: './roles-list.component.html',
	styleUrls: ['./roles-list.component.scss']
})
export class RolesListComponent implements OnInit {
	roles: Rol[] = [];
	filteredRoles: Rol[] = [];
	searchTerm = '';
	isLoading = true;
	showDeleteModal = false;
	rolToDelete: Rol | null = null;

	// Gestión de funciones
	showFuncionesModal = false;
	selectedRol: Rol | null = null;
	funcionesDisponibles: any[] = [];
	filteredFuncionesDisponibles: any[] = [];
	funcionesSearchTerm = '';
	selectedFunciones: number[] = [];
	loadingFunciones = false;
	savingFunciones = false;
	modulos: string[] = [];
	selectedModulo = 'Todos';

	// Alertas
	alertMessage = '';
	alertType: 'success' | 'error' = 'success';

	constructor(
		private rolService: RolService,
		private funcionService: FuncionService
	) {}

	ngOnInit(): void {
		this.loadRoles();
		this.loadAllFunciones();
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

	loadAllFunciones(): void {
		this.funcionService.getFunciones(true).subscribe({
			next: (response) => {
				if (response.success && response.data) {
					this.funcionesDisponibles = response.data;
					this.extractModulos();
				}
			},
			error: (error) => {
				console.error('Error al cargar funciones:', error);
			}
		});
	}

	extractModulos(): void {
		const modulosSet = new Set<string>();
		modulosSet.add('Todos');
		this.funcionesDisponibles.forEach(f => {
			if (f.modulo) {
				modulosSet.add(f.modulo);
			}
		});
		this.modulos = Array.from(modulosSet);
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
					const index = this.filteredRoles.findIndex(r => r.id_rol === rol.id_rol);
					if (index !== -1) {
						this.filteredRoles[index].estado = !this.filteredRoles[index].estado;
						this.onSearch();
					}
					this.showAlert('Estado actualizado exitosamente', 'success');
				}
			},
			error: (error) => {
				console.error('Error al cambiar estado del rol:', error);
				this.showAlert('Error al cambiar estado del rol', 'error');
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
					this.filteredRoles = this.filteredRoles.filter(r => r.id_rol !== this.rolToDelete?.id_rol);
					this.onSearch();
					this.showAlert('Rol eliminado exitosamente', 'success');
				}
				this.showDeleteModal = false;
				this.rolToDelete = null;
			},
			error: (error) => {
				console.error('Error al eliminar rol:', error);
				this.showAlert('Error al eliminar rol', 'error');
				this.showDeleteModal = false;
				this.rolToDelete = null;
			}
		});
	}

	// Gestión de funciones
	verFuncionesRol(rol: Rol): void {
		this.rolService.getRolFunciones(rol.id_rol).subscribe({
			next: (response) => {
				if (response.success && response.data) {
					const funciones = response.data.map((f: any) => `• ${f.nombre} (${f.codigo})`).join('\n');
					alert(`Funciones del rol "${rol.nombre}":\n\n${funciones}`);
				}
			},
			error: (error) => {
				console.error('Error al cargar funciones del rol:', error);
				alert('Error al cargar las funciones del rol');
			}
		});
	}

	openFuncionesModal(rol: Rol): void {
		this.selectedRol = rol;
		this.showFuncionesModal = true;
		this.loadingFunciones = true;
		this.selectedFunciones = [];
		this.funcionesSearchTerm = '';
		this.selectedModulo = 'Todos';

		this.rolService.getRolFunciones(rol.id_rol).subscribe({
			next: (response) => {
				if (response.success && response.data) {
					this.selectedFunciones = response.data.map((f: any) => f.id_funcion);
				}
				this.loadingFunciones = false;
				this.filterFunciones();
			},
			error: (error) => {
				console.error('Error al cargar funciones del rol:', error);
				this.loadingFunciones = false;
				this.filterFunciones();
			}
		});
	}

	closeFuncionesModal(): void {
		this.showFuncionesModal = false;
		this.selectedRol = null;
		this.selectedFunciones = [];
		this.funcionesSearchTerm = '';
		this.selectedModulo = 'Todos';
	}

	filterFunciones(): void {
		let filtered = [...this.funcionesDisponibles];

		// Filtrar por módulo
		if (this.selectedModulo !== 'Todos') {
			filtered = filtered.filter(f => f.modulo === this.selectedModulo);
		}

		// Filtrar por búsqueda
		if (this.funcionesSearchTerm.trim() !== '') {
			const term = this.funcionesSearchTerm.toLowerCase().trim();
			filtered = filtered.filter(f =>
				f.codigo.toLowerCase().includes(term) ||
				f.nombre.toLowerCase().includes(term) ||
				(f.descripcion && f.descripcion.toLowerCase().includes(term))
			);
		}

		this.filteredFuncionesDisponibles = filtered;
	}

	isFuncionSelected(funcionId: number): boolean {
		return this.selectedFunciones.includes(funcionId);
	}

	toggleFuncion(funcionId: number): void {
		const index = this.selectedFunciones.indexOf(funcionId);
		if (index > -1) {
			this.selectedFunciones.splice(index, 1);
		} else {
			this.selectedFunciones.push(funcionId);
		}
	}

	allFuncionesSelected(): boolean {
		if (this.filteredFuncionesDisponibles.length === 0) return false;
		return this.filteredFuncionesDisponibles.every(f =>
			this.selectedFunciones.includes(f.id_funcion)
		);
	}

	moduloHasFuncionesSeleccionadas(modulo: string): boolean {
		if (modulo === 'Todos') {
			return this.selectedFunciones.length > 0;
		}
		const funcionesDelModulo = this.funcionesDisponibles.filter(f => f.modulo === modulo);
		return funcionesDelModulo.some(f => this.selectedFunciones.includes(f.id_funcion));
	}

	toggleAllFunciones(event: any): void {
		if (event.target.checked) {
			this.filteredFuncionesDisponibles.forEach(f => {
				if (!this.selectedFunciones.includes(f.id_funcion)) {
					this.selectedFunciones.push(f.id_funcion);
				}
			});
		} else {
			this.filteredFuncionesDisponibles.forEach(f => {
				const index = this.selectedFunciones.indexOf(f.id_funcion);
				if (index > -1) {
					this.selectedFunciones.splice(index, 1);
				}
			});
		}
	}

	saveFunciones(): void {
		if (!this.selectedRol) return;

		this.savingFunciones = true;
		this.rolService.assignFunciones(this.selectedRol.id_rol, this.selectedFunciones).subscribe({
			next: (response) => {
				if (response.success) {
					this.showAlert('Funciones asignadas exitosamente', 'success');
					this.closeFuncionesModal();
				}
				this.savingFunciones = false;
			},
			error: (error) => {
				console.error('Error al asignar funciones:', error);
				this.showAlert('Error al asignar funciones', 'error');
				this.savingFunciones = false;
			}
		});
	}

	showAlert(message: string, type: 'success' | 'error'): void {
		this.alertMessage = message;
		this.alertType = type;
		setTimeout(() => {
			this.alertMessage = '';
		}, 3000);
	}
}
