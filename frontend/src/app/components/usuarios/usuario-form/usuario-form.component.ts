import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule, ReactiveFormsModule, FormBuilder, FormGroup, Validators } from '@angular/forms';
import { Router, ActivatedRoute, RouterModule } from '@angular/router';
import { Usuario, Rol } from '../../../models/usuario.model';
import { UsuarioService } from '../../../services/usuario.service';
import { RolService } from '../../../services/rol.service';

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

	constructor(
		private formBuilder: FormBuilder,
		private usuarioService: UsuarioService,
		private rolService: RolService,
		private router: Router,
		private route: ActivatedRoute
	) {
		this.usuarioForm = this.formBuilder.group({
			nickname: ['', Validators.required],
			nombre: ['', Validators.required],
			ap_paterno: ['', Validators.required],
			ap_materno: [''],
			ci: ['', Validators.required],
			id_rol: [null, Validators.required],
			estado: [true],
			contrasenia: ['', [Validators.required, Validators.minLength(6)]],
			contraseniaConfirm: ['', [Validators.required]]
		}, {
			validators: this.passwordMatchValidator
		});
	}

	ngOnInit(): void {
		this.loadRoles();
		
		// Verificar si estamos en modo edición
		const id = this.route.snapshot.paramMap.get('id');
		if (id) {
			this.isEditMode = true;
			this.usuarioId = +id;
			this.loadUsuario(this.usuarioId);
			
			// No requerir contraseña en modo edición
			this.usuarioForm.get('contrasenia')?.clearValidators();
			this.usuarioForm.get('contraseniaConfirm')?.clearValidators();
			this.usuarioForm.get('contrasenia')?.updateValueAndValidity();
			this.usuarioForm.get('contraseniaConfirm')?.updateValueAndValidity();
		}
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

	togglePasswordVisibility(): void {
		this.showPassword = !this.showPassword;
	}

	toggleConfirmPasswordVisibility(): void {
		this.showConfirmPassword = !this.showConfirmPassword;
	}

	onSubmit(): void {
		this.submitted = true;

		if (this.usuarioForm.invalid) {
			return;
		}

		this.isSubmitting = true;
		const formData = this.usuarioForm.value as any;

		if (this.isEditMode && this.usuarioId) {
			// En modo edición, actualizar usuario existente
			const { contraseniaConfirm, ...payload } = formData;
			if (!payload.contrasenia || (typeof payload.contrasenia === 'string' && payload.contrasenia.trim() === '')) {
				delete payload.contrasenia;
			}
			this.usuarioService.update(this.usuarioId, payload).subscribe({
				next: (response: { success: boolean; data: Usuario; message: string }) => {
					if (response.success) {
						this.router.navigate(['/usuarios']);
					} else {
						this.error = response.message || 'Error al actualizar usuario';
					}
					this.isSubmitting = false;
				},
				error: (error: any) => {
					console.error('Error al actualizar usuario:', error);
					this.error = error.error?.message || 'Error al actualizar usuario. Intente nuevamente más tarde.';
					this.isSubmitting = false;
				}
			});
		} else {
			// En modo creación, crear nuevo usuario
			const { contraseniaConfirm, ...payload } = formData;
			this.usuarioService.create(payload).subscribe({
				next: (response: { success: boolean; data: Usuario; message: string }) => {
					if (response.success) {
						this.router.navigate(['/usuarios']);
					} else {
						this.error = response.message || 'Error al crear usuario';
					}
					this.isSubmitting = false;
				},
				error: (error: any) => {
					console.error('Error al crear usuario:', error);
					this.error = error.error?.message || 'Error al crear usuario. Intente nuevamente más tarde.';
					this.isSubmitting = false;
				}
			});
		}
	}
}
