import { Component, EventEmitter, Output, Input, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';

interface Usuario {
	id: number;
	nombre: string;
	email: string;
	asignado: boolean;
}

@Component({
	selector: 'app-add-users-modal',
	standalone: true,
	imports: [CommonModule, FormsModule],
	templateUrl: './add-users-modal.component.html',
	styleUrls: ['./add-users-modal.component.scss']
})
export class AddUsersModalComponent implements OnInit {
	@Input() puntoVenta: any = null;
	@Output() usersAdded = new EventEmitter<any>();

	usuarios: Usuario[] = [];
	searchTerm: string = '';
	isLoading: boolean = false;
	isSaving: boolean = false;

	ngOnInit(): void {
		this.loadUsuarios();
	}

	loadUsuarios(): void {
		this.isLoading = true;
		// TODO: Cargar usuarios desde el backend
		// Por ahora, datos de ejemplo
		setTimeout(() => {
			this.usuarios = [
				{ id: 1, nombre: 'Admin Usuario', email: 'admin@example.com', asignado: false },
				{ id: 2, nombre: 'Cajero 1', email: 'cajero1@example.com', asignado: false },
				{ id: 3, nombre: 'Cajero 2', email: 'cajero2@example.com', asignado: false },
				{ id: 4, nombre: 'Supervisor', email: 'supervisor@example.com', asignado: false }
			];
			this.isLoading = false;
		}, 500);
	}

	get filteredUsuarios(): Usuario[] {
		const term = this.searchTerm.toLowerCase().trim();
		if (!term) {
			return this.usuarios;
		}
		return this.usuarios.filter(u => 
			u.nombre.toLowerCase().includes(term) || 
			u.email.toLowerCase().includes(term)
		);
	}

	get selectedCount(): number {
		return this.usuarios.filter(u => u.asignado).length;
	}

	toggleUsuario(usuario: Usuario): void {
		usuario.asignado = !usuario.asignado;
	}

	selectAll(): void {
		const allSelected = this.filteredUsuarios.every(u => u.asignado);
		this.filteredUsuarios.forEach(u => u.asignado = !allSelected);
	}

	onSave(): void {
		const usuariosSeleccionados = this.usuarios.filter(u => u.asignado);
		if (usuariosSeleccionados.length === 0) {
			alert('Debe seleccionar al menos un usuario');
			return;
		}

		this.isSaving = true;
		// TODO: Enviar al backend
		setTimeout(() => {
			this.usersAdded.emit({
				puntoVenta: this.puntoVenta,
				usuarios: usuariosSeleccionados
			});
			this.isSaving = false;
			this.closeModal();
		}, 1000);
	}

	closeModal(): void {
		const modalElement = document.getElementById('addUsersModal');
		if (modalElement) {
			const modal = (window as any).bootstrap.Modal.getInstance(modalElement);
			if (modal) {
				modal.hide();
			}
		}
		this.resetForm();
	}

	resetForm(): void {
		this.searchTerm = '';
		this.usuarios.forEach(u => u.asignado = false);
	}
}
