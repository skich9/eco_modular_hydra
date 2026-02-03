import { Component, EventEmitter, Output, Input, OnInit, OnChanges, SimpleChanges } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule, ReactiveFormsModule, FormBuilder, FormGroup, Validators } from '@angular/forms';
import { PuntoVentaService, Usuario, ApiResponse, AssignUserRequest, AsignacionPuntoVenta, UpdateAsignacionRequest } from '../../../../../services/punto-venta.service';

@Component({
	selector: 'app-add-users-modal',
	standalone: true,
	imports: [CommonModule, FormsModule, ReactiveFormsModule],
	templateUrl: './add-users-modal.component.html',
	styleUrls: ['./add-users-modal.component.scss']
})
export class AddUsersModalComponent implements OnInit, OnChanges {
	@Input() puntoVenta: any = null;
	@Output() usersAdded = new EventEmitter<any>();

	form: FormGroup;
	usuarios: Usuario[] = [];
	filteredUsuarios: Usuario[] = [];
	searchTerm: string = '';
	isLoading: boolean = false;
	isSaving: boolean = false;
	submitError: string = '';
	isEditMode: boolean = false;
	asignacionActual: AsignacionPuntoVenta | null = null;
	isLoadingAsignacion: boolean = false;
	private lastPuntoVentaCode: string | number | null = null;

	constructor(
		private fb: FormBuilder,
		private puntoVentaService: PuntoVentaService
	) {
		this.form = this.fb.group({
			id_usuario: ['', Validators.required],
			vencimiento_asig: ['', Validators.required],
			activo: [true]
		});
	}

	ngOnInit(): void {
		this.loadUsuarios();
		this.setupModalListeners();
	}

	setupModalListeners(): void {
		const modalElement = document.getElementById('addUsersModal');
		if (modalElement) {
			modalElement.addEventListener('hidden.bs.modal', () => {
				this.lastPuntoVentaCode = null;
				this.isEditMode = false;
				this.asignacionActual = null;
				this.isLoadingAsignacion = false;
				this.resetForm();
			});
		}
	}

	ngOnChanges(changes: SimpleChanges): void {
		if (changes['puntoVenta'] && this.puntoVenta) {
			const currentCode = this.puntoVenta.codigo_punto_venta;

			// Solo procesar si es un punto de venta diferente o es la primera vez
			if (currentCode !== this.lastPuntoVentaCode) {
				this.lastPuntoVentaCode = currentCode;
				this.isEditMode = false;
				this.asignacionActual = null;
				this.resetForm();
				if (this.puntoVenta.usuario_asignado) {
					this.isLoadingAsignacion = true;
					this.checkExistingAsignacion();
				} else {
					this.isLoadingAsignacion = false;
				}
			}
		}
	}

	checkExistingAsignacion(): void {
		if (!this.puntoVenta) {
			this.isLoadingAsignacion = false;
			return;
		}

		this.puntoVentaService.getAsignacionPuntoVenta(this.puntoVenta.codigo_punto_venta).subscribe({
			next: (response: ApiResponse<AsignacionPuntoVenta>) => {
				this.isLoadingAsignacion = false;

				if (response.success && response.data) {
					// Modo edición: hay asignación existente
					this.isEditMode = true;
					this.asignacionActual = response.data;
					this.loadEditForm();
				} else {
					// Modo creación: no hay asignación
					this.isEditMode = false;
					this.asignacionActual = null;
				}
			},
			error: (error: any) => {
				this.isLoadingAsignacion = false;
				this.isEditMode = false;
				this.asignacionActual = null;
				console.error('Error al verificar asignación:', error);
			}
		});
	}

	loadEditForm(): void {
		if (!this.asignacionActual) return;

		const vencimiento = this.asignacionActual.vencimiento_asig;
		const fechaFormateada = vencimiento ? this.formatDateForInput(vencimiento) : '';

		this.form.patchValue({
			id_usuario: this.asignacionActual.id_usuario,
			vencimiento_asig: fechaFormateada,
			activo: this.asignacionActual.activo === 1
		});

		this.form.get('id_usuario')?.disable();
	}

	formatDateForInput(dateString: string): string {
		const date = new Date(dateString);
		const year = date.getFullYear();
		const month = String(date.getMonth() + 1).padStart(2, '0');
		const day = String(date.getDate()).padStart(2, '0');
		const hours = String(date.getHours()).padStart(2, '0');
		const minutes = String(date.getMinutes()).padStart(2, '0');
		return `${year}-${month}-${day}T${hours}:${minutes}`;
	}

	getUsuarioNombreCompleto(usuario: Usuario): string {
		return `${usuario.nombre} ${usuario.ap_materno || ''}`;
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

	onSave(): void {
		if (this.isEditMode) {
			this.updateAsignacion();
		} else {
			this.createAsignacion();
		}
	}

	createAsignacion(): void {
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

	updateAsignacion(): void {
		if (!this.asignacionActual) {
			this.submitError = 'No hay asignación para actualizar';
			return;
		}

		const vencimientoControl = this.form.get('vencimiento_asig');
		if (!vencimientoControl || !vencimientoControl.value) {
			this.submitError = 'La fecha de vencimiento es requerida';
			return;
		}

		this.isSaving = true;
		this.submitError = '';

		const requestData: UpdateAsignacionRequest = {
			vencimiento_asig: vencimientoControl.value,
			activo: this.form.value.activo ? 1 : 0
		};

		this.puntoVentaService.updateAsignacionPuntoVenta(this.asignacionActual.id, requestData).subscribe({
			next: (response: ApiResponse<any>) => {
				this.isSaving = false;
				if (response.success) {
					this.usersAdded.emit({
						puntoVenta: this.puntoVenta,
						message: response.message
					});
					this.closeModal();
				} else {
					this.submitError = response.message || 'Error al actualizar asignación';
				}
			},
			error: (error: any) => {
				this.isSaving = false;
				console.error('Error al actualizar asignación:', error);
				this.submitError = error.error?.message || 'Error al actualizar la asignación';
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
	}

	resetForm(): void {
		this.form.reset({
			id_usuario: '',
			vencimiento_asig: '',
			activo: true
		});
		this.form.get('id_usuario')?.enable();
		this.searchTerm = '';
		this.submitError = '';
		this.filteredUsuarios = this.usuarios;
		this.isEditMode = false;
		this.asignacionActual = null;
	}
}
