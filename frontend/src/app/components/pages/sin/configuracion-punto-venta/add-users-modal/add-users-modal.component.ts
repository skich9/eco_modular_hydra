import { Component, EventEmitter, Output, Input, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule, ReactiveFormsModule, FormBuilder, FormGroup, Validators } from '@angular/forms';
import { PuntoVentaService, Usuario, ApiResponse, AssignUserRequest } from '../../../../../services/punto-venta.service';

@Component({
	selector: 'app-add-users-modal',
	standalone: true,
	imports: [CommonModule, FormsModule, ReactiveFormsModule],
	templateUrl: './add-users-modal.component.html',
	styleUrls: ['./add-users-modal.component.scss']
})
export class AddUsersModalComponent implements OnInit {
	@Input() puntoVenta: any = null;
	@Output() usersAdded = new EventEmitter<any>();

	form: FormGroup;
	usuarios: Usuario[] = [];
	filteredUsuarios: Usuario[] = [];
	searchTerm: string = '';
	isLoading: boolean = false;
	isSaving: boolean = false;
	submitError: string = '';

	constructor(
		private fb: FormBuilder,
		private puntoVentaService: PuntoVentaService
	) {
		this.form = this.fb.group({
			id_usuario: ['', Validators.required],
			vencimiento_asig: ['', Validators.required]
		});
	}

	ngOnInit(): void {
		this.loadUsuarios();
	}

	loadUsuarios(): void {
		this.isLoading = true;
		this.puntoVentaService.getUsuarios().subscribe({
			next: (response: ApiResponse<Usuario[]>) => {
				this.isLoading = false;
				if (response.success && response.data) {
					this.usuarios = response.data;
					this.filteredUsuarios = response.data;
				}
			},
			error: (error: any) => {
				this.isLoading = false;
				console.error('Error al cargar usuarios:', error);
			}
		});
	}

	filterUsuarios(): void {
		const term = this.searchTerm.toLowerCase().trim();
		if (!term) {
			this.filteredUsuarios = this.usuarios;
		} else {
			this.filteredUsuarios = this.usuarios.filter(u =>
				u.nombre.toLowerCase().includes(term) ||
				(u.ap_materno && u.ap_materno.toLowerCase().includes(term))
			);
		}
	}

	getUsuarioNombreCompleto(usuario: Usuario): string {
		return `${usuario.nombre} ${usuario.ap_materno || ''}`;
	}

	onSave(): void {
		if (this.form.invalid) {
			this.submitError = 'Por favor complete todos los campos requeridos';
			return;
		}

		if (!this.puntoVenta) {
			this.submitError = 'No se ha seleccionado un punto de venta';
			return;
		}

		this.isSaving = true;
		this.submitError = '';

		const requestData: AssignUserRequest = {
			id_usuario: this.form.value.id_usuario,
			codigo_punto_venta: this.puntoVenta.codigo_punto_venta,
			codigo_sucursal: this.puntoVenta.sucursal || 0,
			vencimiento_asig: this.form.value.vencimiento_asig,
			usuario_crea: 1
		};

		this.puntoVentaService.assignUserToPuntoVenta(requestData).subscribe({
			next: (response: ApiResponse<any>) => {
				this.isSaving = false;
				if (response.success) {
					this.usersAdded.emit({
						puntoVenta: this.puntoVenta,
						message: response.message
					});
					this.closeModal();
				} else {
					this.submitError = response.message || 'Error al asignar usuario';
				}
			},
			error: (error: any) => {
				this.isSaving = false;
				console.error('Error al asignar usuario:', error);
				this.submitError = error.error?.message || 'Error al asignar usuario al punto de venta';
			}
		});
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
		this.form.reset();
		this.searchTerm = '';
		this.submitError = '';
		this.filteredUsuarios = this.usuarios;
	}
}
