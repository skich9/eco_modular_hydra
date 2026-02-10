import { Component, OnInit, Input, Output, EventEmitter } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule, ReactiveFormsModule, FormBuilder, FormGroup, Validators } from '@angular/forms';
import { Router, ActivatedRoute, RouterModule } from '@angular/router';
import { Usuario, Rol } from '../../../models/usuario.model';
import { UsuarioService } from '../../../services/usuario.service';
import { RolService } from '../../../services/rol.service';
import { FuncionService } from '../../../services/funcion.service';

@Component({
	selector: 'app-usuario-form',
	standalone: true,
	imports: [CommonModule, FormsModule, ReactiveFormsModule, RouterModule],
	templateUrl: './usuario-form.component.html',
	styleUrls: ['./usuario-form.component.scss']
})
export class UsuarioFormComponent implements OnInit {
	usuarioForm: FormGroup;
	roles: Rol[] = [];
	isEditMode = false;
	submitted = false;
	isSubmitting = false;
	error = '';
	usuarioId: number | null = null;
	showPassword = false;
	showConfirmPassword = false;

	// Gestión de funciones
	showFuncionesModal = false;
	funcionesDisponibles: any[] = [];
	filteredFuncionesDisponibles: any[] = [];
	funcionesSearchTerm = '';
	selectedFunciones: number[] = [];
	loadingFunciones = false;
	modulos: string[] = [];
	selectedModulo = 'Todos';
	funcionesCount = 0;
	selectedRolId: number | null = null;
	loadingRolFunciones = false;

	// Modal support
	@Input() modalMode: boolean = false;
	@Input() usuarioIdInput: number | null = null;
	@Output() closed = new EventEmitter<boolean>();

	constructor(
		private formBuilder: FormBuilder,
		private usuarioService: UsuarioService,
		private rolService: RolService,
		private funcionService: FuncionService,
		private router: Router,
		private route: ActivatedRoute
	) {
		this.usuarioForm = this.formBuilder.group({
			nickname: ['', Validators.required],
			nombre: ['', Validators.required],
			ap_paterno: ['', Validators.required],
			ap_materno: [''],
			ci: ['', Validators.required],
			id_rol: [null],
			estado: [true],
			contrasenia: ['', [Validators.required, Validators.minLength(6)]],
			contraseniaConfirm: ['', [Validators.required]],
			resetPassword: [false]
		}, {
			validators: this.passwordMatchValidator
		});
	}

	ngOnInit(): void {
		this.loadRoles();

		// Verificar si estamos en modo edición (por ruta o por @Input)
		const idFromRoute = this.route.snapshot.paramMap.get('id');
		const resolvedId = this.usuarioIdInput ?? (idFromRoute ? +idFromRoute : null);
		if (resolvedId) {
			this.isEditMode = true;
			this.usuarioId = resolvedId;
			this.loadUsuario(this.usuarioId);
			this.loadUsuarioFunciones(this.usuarioId);

			// No requerir contraseña en modo edición
			this.usuarioForm.get('contrasenia')?.clearValidators();
			this.usuarioForm.get('contraseniaConfirm')?.clearValidators();
			this.usuarioForm.get('contrasenia')?.updateValueAndValidity();
			this.usuarioForm.get('contraseniaConfirm')?.updateValueAndValidity();

			// Suscripción para activar/desactivar validaciones al marcar "Restablecer contraseña"
			const resetCtrl = this.usuarioForm.get('resetPassword');
			resetCtrl?.valueChanges.subscribe((checked: boolean) => {
				const pwd = this.usuarioForm.get('contrasenia');
				const conf = this.usuarioForm.get('contraseniaConfirm');
				if (checked) {
					pwd?.setValidators([Validators.required, Validators.minLength(6)]);
					conf?.setValidators([Validators.required]);
				} else {
					pwd?.clearValidators();
					conf?.clearValidators();
					pwd?.setValue('');
					conf?.setValue('');
					pwd?.setErrors(null);
					conf?.setErrors(null);
				}
				pwd?.updateValueAndValidity();
				conf?.updateValueAndValidity();
			});
		}

		// Cargar siempre todas las funciones disponibles para el modal
		this.loadAllFunciones();
	}

	loadRoles(): void {
		this.rolService.getActiveRoles().subscribe({
			next: (response: { success: boolean; data: Rol[] }) => {
				if (response.success && response.data) {
					this.roles = response.data;
				}
			},
			error: (error: any) => {
				console.error('Error al cargar roles:', error);
				this.error = 'No se pudieron cargar los roles. Intente nuevamente más tarde.';
			}
		});
	}

	loadUsuario(id: number): void {
		this.usuarioService.getById(id).subscribe({
			next: (response: { success: boolean; data: Usuario }) => {
				if (response.success && response.data) {
					const usuario = response.data;
					this.usuarioForm.patchValue({
						nickname: usuario.nickname,
						nombre: usuario.nombre,
						ap_paterno: usuario.ap_paterno,
						ap_materno: usuario.ap_materno,
						ci: usuario.ci,
						id_rol: usuario.id_rol,
						estado: usuario.estado
					});
				}
			},
			error: (error: any) => {
				console.error('Error al cargar usuario:', error);
				this.error = 'No se pudo cargar la información del usuario. Intente nuevamente más tarde.';
			}
		});
	}

	get f() {
		return this.usuarioForm.controls;
	}

	passwordMatchValidator(formGroup: FormGroup) {
		const password = formGroup.get('contrasenia')?.value;
		const passwordConfirmation = formGroup.get('contraseniaConfirm')?.value;

		if (password === passwordConfirmation) {
			return null;
		}

		return { matching: true };
	}

	private clearServerErrors(): void {
		Object.keys(this.usuarioForm.controls).forEach((name) => {
			const control = this.usuarioForm.get(name);
			if (control?.errors?.['server']) {
				const e = { ...control.errors } as any;
				delete e['server'];
				const hasKeys = Object.keys(e).length > 0;
				control.setErrors(hasKeys ? e : null);
			}
		});
	}

	private setServerErrors(errors: any): void {
		if (!errors || typeof errors !== 'object') return;
		Object.keys(errors).forEach((field) => {
			const control = this.usuarioForm.get(field);
			if (control) {
				const raw = Array.isArray(errors[field]) ? errors[field].join(' ') : String(errors[field]);
				const message = this.translateServerMessage(field, raw);
				const curr = control.errors || {};
				control.setErrors({ ...curr, server: message });
				control.markAsTouched();
			}
		});
	}

	private translateServerMessage(field: string, msg: string): string {
		const label = this.fieldLabel(field);
		let translated = msg;

		// Patrón: requerido
		translated = translated.replace(/The ([^ ]+) field is required\./i, `El ${label} es requerido.`);
		// Patrón: ya en uso (unique)
		if (/has already been taken/i.test(translated)) {
			translated = `${label} ya está en uso.`;
		}
		// Patrón: mínimo de caracteres
		translated = translated.replace(/must be at least (\d+) characters\./i, (_m, p1) => `Debe tener al menos ${p1} caracteres.`);
		// Patrón: máximo de caracteres
		translated = translated.replace(/may not be greater than (\d+) characters\./i, (_m, p1) => `No debe superar ${p1} caracteres.`);
		// Patrón: selección inválida
		translated = translated.replace(/The selected ([^ ]+) is invalid\./i, `El valor seleccionado para ${label} no es válido.`);
		// Patrón: confirmación contraseña
		translated = translated.replace(/The password confirmation does not match\./i, 'La confirmación de contraseña no coincide.');

		return translated;
	}

	private fieldLabel(field: string): string {
		switch (field) {
			case 'nickname': return 'Usuario';
			case 'nombre': return 'Nombre';
			case 'ap_paterno': return 'Apellido paterno';
			case 'ap_materno': return 'Apellido materno';
			case 'ci': return 'CI';
			case 'id_rol': return 'Rol';
			case 'contrasenia': return 'Contraseña';
			case 'contraseniaConfirm': return 'Confirmación de contraseña';
			default: return field;
		}
	}

	private handleHttpError(error: any): void {
		console.error('HTTP error:', error);
		if (error?.status === 422 && error.error?.errors) {
			this.setServerErrors(error.error.errors);
			this.error = 'Error de validación';
		} else {
			this.error = error?.error?.message || 'Ocurrió un error. Intente nuevamente más tarde.';
		}
		this.isSubmitting = false;
	}

	togglePasswordVisibility(): void {
		this.showPassword = !this.showPassword;
	}

	toggleConfirmPasswordVisibility(): void {
		this.showConfirmPassword = !this.showConfirmPassword;
	}

	onSubmit(): void {
		this.submitted = true;
		this.clearServerErrors();

		if (this.usuarioForm.invalid) {
			return;
		}

		// El backend requiere id_rol. En creación lo tomamos del selector de rol del modal.
		const rolControl = this.usuarioForm.get('id_rol');
		if (!this.isEditMode && !this.selectedRolId) {
			this.error = 'Debe seleccionar un rol en la sección "Funciones del Usuario".';
			rolControl?.setErrors({ required: true });
			rolControl?.markAsTouched();
			return;
		}

		// Si el usuario eligió un rol en el modal, sincronizarlo con el formulario
		if (this.selectedRolId) {
			this.usuarioForm.patchValue({ id_rol: this.selectedRolId });
		}

		this.isSubmitting = true;
		const formData = this.usuarioForm.value as any;

		if (this.isEditMode && this.usuarioId) {
			// En modo edición, actualizar usuario existente
			const { contraseniaConfirm, resetPassword, ...payload } = formData;
			if (!resetPassword) {
				delete payload.contrasenia;
			}
			this.usuarioService.update(this.usuarioId, payload).subscribe({
				next: (response: { success: boolean; data: Usuario; message: string }) => {
					if (response.success) {
						this.saveFunciones(this.usuarioId!);
					} else {
						this.error = response.message || 'Error al actualizar usuario';
						this.isSubmitting = false;
					}
				},
				error: (error: any) => this.handleHttpError(error)
			});
		} else {
			// En modo creación, crear nuevo usuario
			const { contraseniaConfirm, resetPassword: _rp, ...payload } = formData;
			this.usuarioService.create(payload).subscribe({
				next: (response: { success: boolean; data: Usuario; message: string }) => {
					if (response.success && response.data) {
						this.saveFunciones(response.data.id_usuario);
					} else {
						this.error = response.message || 'Error al crear usuario';
						this.isSubmitting = false;
					}
				},
				error: (error: any) => this.handleHttpError(error)
			});
		}
	}

	saveFunciones(usuarioId: number): void {
		if (this.selectedFunciones.length === 0) {
			// Si no hay funciones seleccionadas, solo navegar
			this.finalizarGuardado();
			return;
		}

		// Asignar cada función seleccionada al usuario
		let funcionesAsignadas = 0;
		let erroresAsignacion = 0;

		this.selectedFunciones.forEach((funcionId, index) => {
			const request = {
				id_funcion: funcionId,
				fecha_ini: new Date().toISOString().split('T')[0],
				fecha_fin: null,
				observaciones: 'Asignado desde formulario de usuario'
			};

			this.funcionService.asignarFuncion(usuarioId, request).subscribe({
				next: (response) => {
					funcionesAsignadas++;
					if (funcionesAsignadas + erroresAsignacion === this.selectedFunciones.length) {
						this.finalizarGuardado();
					}
				},
				error: (error) => {
					console.error('Error al asignar función:', error);
					erroresAsignacion++;
					if (funcionesAsignadas + erroresAsignacion === this.selectedFunciones.length) {
						this.finalizarGuardado();
					}
				}
			});
		});
	}

	finalizarGuardado(): void {
		this.isSubmitting = false;
		if (this.modalMode) {
			this.closed.emit(true);
		} else {
			this.router.navigate(['/usuarios']);
		}
	}

	onCancel(): void {
		if (this.modalMode) {
			this.closed.emit(false);
		} else {
			this.router.navigate(['/usuarios']);
		}
	}

	// Gestión de funciones
	openFuncionesModal(): void {
		this.showFuncionesModal = true;
	}

	closeFuncionesModal(): void {
		this.showFuncionesModal = false;
	}

	loadAllFunciones(): void {
		this.loadingFunciones = true;
		this.funcionService.getFunciones(true).subscribe({
			next: (response) => {
				if (response.success && response.data) {
					this.funcionesDisponibles = response.data;
					this.modulos = ['Todos', ...new Set(response.data.map((f: any) => f.modulo).filter((m: any) => m))];
					this.filterFunciones();
				}
				this.loadingFunciones = false;
			},
			error: (error) => {
				console.error('Error al cargar funciones:', error);
				this.loadingFunciones = false;
			}
		});
	}

	loadUsuarioFunciones(usuarioId: number): void {
		this.funcionService.getUsuarioFunciones(usuarioId).subscribe({
			next: (response) => {
				if (response.success && response.data) {
					this.selectedFunciones = response.data.map((f: any) => f.id_funcion);
					this.funcionesCount = this.selectedFunciones.length;
				}
			},
			error: (error) => {
				console.error('Error al cargar funciones del usuario:', error);
			}
		});
	}

	filterFunciones(): void {
		let filtered = this.funcionesDisponibles;

		if (this.selectedModulo !== 'Todos') {
			filtered = filtered.filter(f => f.modulo === this.selectedModulo);
		}

		if (this.funcionesSearchTerm) {
			const term = this.funcionesSearchTerm.toLowerCase();
			filtered = filtered.filter(f =>
				f.nombre.toLowerCase().includes(term) ||
				f.codigo.toLowerCase().includes(term) ||
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
		this.funcionesCount = this.selectedFunciones.length;
	}

	moduloHasFuncionesSeleccionadas(modulo: string): boolean {
		if (modulo === 'Todos') {
			return this.selectedFunciones.length > 0;
		}
		const funcionesDelModulo = this.funcionesDisponibles.filter(f => f.modulo === modulo);
		return funcionesDelModulo.some(f => this.isFuncionSelected(f.id_funcion));
	}

	allFuncionesSelected(): boolean {
		if (!this.filteredFuncionesDisponibles || this.filteredFuncionesDisponibles.length === 0) {
			return false;
		}
		return this.filteredFuncionesDisponibles.every(f => this.isFuncionSelected(f.id_funcion));
	}

	toggleAllFunciones(event: any): void {
		const checked = event.target.checked;
		if (checked) {
			this.filteredFuncionesDisponibles.forEach(f => {
				if (!this.isFuncionSelected(f.id_funcion)) {
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
		this.funcionesCount = this.selectedFunciones.length;
	}

	onRolChange(): void {
		if (!this.selectedRolId) {
			return;
		}

		this.loadingRolFunciones = true;
		this.rolService.getRolFunciones(this.selectedRolId).subscribe({
			next: (response) => {
				if (response.success && response.data) {
					const funcionesDelRol = response.data.map((f: any) => f.id_funcion);

					// Agregar funciones del rol a las seleccionadas (sin duplicar)
					funcionesDelRol.forEach((funcionId: number) => {
						if (!this.selectedFunciones.includes(funcionId)) {
							this.selectedFunciones.push(funcionId);
						}
					});

					this.funcionesCount = this.selectedFunciones.length;
				}
				this.loadingRolFunciones = false;
			},
			error: (error) => {
				console.error('Error al cargar funciones del rol:', error);
				this.loadingRolFunciones = false;
			}
		});
	}
}
