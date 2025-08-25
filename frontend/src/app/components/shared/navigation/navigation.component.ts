import { Component, OnInit, HostListener } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Router, RouterModule } from '@angular/router';
import { ReactiveFormsModule, FormBuilder, FormGroup, Validators } from '@angular/forms';
import { AuthService } from '../../../services/auth.service';
import { Carrera } from '../../../models/carrera.model';
import { CarreraService } from '../../../services/carrera.service';
import { Usuario } from '../../../models/usuario.model';

interface MenuItem {
	name: string;
	icon: string;
	submenu: SubMenuItem[];
}

interface SubMenuItem {
	name: string;
	icon: string;
	route: string;
	params?: any;
}

@Component({
	selector: 'app-navigation',
	standalone: true,
	imports: [CommonModule, RouterModule, ReactiveFormsModule],
	templateUrl: './navigation.component.html',
	styleUrls: ['./navigation.component.scss']
})
export class NavigationComponent implements OnInit {
	currentUser: Usuario | null = null;
	showUserDropdown = false;
	activeSubmenu: number | null = null;
	
	// Change password form
	changePasswordForm: FormGroup;
	changePasswordError: string = '';
	changePasswordSuccess: string = '';
	isChangingPassword: boolean = false;

	carreras: Carrera[] = [];
	loadingCarreras = false;

	menuItems: MenuItem[] = [
		{
			name: 'Cobros',
			icon: 'fas fa-money-bill-wave',
			submenu: [
				{ name: 'Gestionar Cobros', icon: 'fa-cash-register', route: '/cobros' },
				{ name: 'Reportes', icon: 'fa-chart-bar', route: '/reportes' }
			]
		},
        {
            name: 'Académico',
            icon: 'fas fa-graduation-cap',
            submenu: []
        },
        {
			name: 'Configuración',
			icon: 'fas fa-cog',
			submenu: [
				{ name: 'Usuarios', icon: 'fa-users', route: '/usuarios' },
				{ name: 'Roles', icon: 'fa-user-shield', route: '/roles' },
				{ name: 'Parámetros de Sistema', icon: 'fa-sliders-h', route: '/parametros' }
			]
		}
	];

	constructor(
		private authService: AuthService,
		private router: Router,
		private formBuilder: FormBuilder,
		private carreraService: CarreraService
	) {
		this.changePasswordForm = this.formBuilder.group({
			currentPassword: ['', [Validators.required]],
			newPassword: ['', [Validators.required, Validators.minLength(6)]],
			confirmPassword: ['', [Validators.required]]
		}, { validators: this.passwordMatchValidator });
	}

	ngOnInit(): void {
		// Suscribirse a los cambios del usuario actual
		this.authService.currentUser$.subscribe(user => {
			this.currentUser = user;
		});

		// Cerrar menús al hacer clic fuera
		document.addEventListener('click', (event) => {
			const target = event.target as HTMLElement;
			const isInsideMenu = target.closest('.dropdown, .dropdown-menu, .dropdown-item, .nav-user-avatar, .btn[data-user-dropdown]');
			
			if (!isInsideMenu) {
				this.closeAllMenus();
			}
		});

		// Cargar carreras para el menú académico
		this.loadCarreras();
	}

	private loadCarreras(): void {
		this.loadingCarreras = true;
		this.carreraService.getAll().subscribe({
			next: (res) => {
				this.carreras = res.data || [];
				const idx = this.menuItems.findIndex(mi => mi.name === 'Académico');
				if (idx !== -1) {
					this.menuItems[idx].submenu = this.carreras.map(c => ({
						name: c.nombre,
						icon: 'fa-university',
						route: `/academico/${c.codigo_carrera}`
					}));
				}
			},
			error: (err) => {
				console.error('Error cargando carreras:', err);
			},
			complete: () => {
				this.loadingCarreras = false;
			}
		});
	}

	getUserInitial(): string {
		if (this.currentUser?.nombre_completo) {
			return this.currentUser.nombre_completo.charAt(0).toUpperCase();
		}
		return 'U';
	}

	toggleDropdown(): void {
		this.activeSubmenu = null; // Cerrar submenús
		this.showUserDropdown = !this.showUserDropdown;
	}

	toggleConfigMenu(index: number): void {
		this.showUserDropdown = false; // Cerrar dropdown de usuario
		
		if (this.activeSubmenu === index) {
			this.activeSubmenu = null;
		} else {
			this.activeSubmenu = index;
		}
	}

	closeAllMenus(): void {
		this.showUserDropdown = false;
		this.activeSubmenu = null;
	}

	isActiveRoute(route: string): boolean {
		return this.router.url.startsWith(route);
	}

	passwordMatchValidator(form: FormGroup) {
		const newPassword = form.get('newPassword');
		const confirmPassword = form.get('confirmPassword');
		
		if (newPassword && confirmPassword && newPassword.value !== confirmPassword.value) {
			confirmPassword.setErrors({ mismatch: true });
			return { mismatch: true };
		}
		
		if (confirmPassword?.hasError('mismatch')) {
			confirmPassword.setErrors(null);
		}
		
		return null;
	}

	changePassword(event: Event): void {
		event.preventDefault();
		this.closeAllMenus();
		
		// Reset form and messages
		this.changePasswordForm.reset();
		this.changePasswordError = '';
		this.changePasswordSuccess = '';
		
		// Show modal (using Bootstrap modal)
		const modal = document.getElementById('changePasswordModal');
		if (modal) {
			const bootstrapModal = new (window as any).bootstrap.Modal(modal);
			bootstrapModal.show();
		}
	}

	onChangePassword(): void {
		if (this.changePasswordForm.invalid) {
			return;
		}

		this.isChangingPassword = true;
		this.changePasswordError = '';
		this.changePasswordSuccess = '';

		const formData = this.changePasswordForm.value;

		// Aquí iría la llamada al servicio para cambiar la contraseña
		// Por ahora simularemos la respuesta
		setTimeout(() => {
			this.isChangingPassword = false;
			this.changePasswordSuccess = 'Contraseña cambiada exitosamente';
			
			// Cerrar modal después de 2 segundos
			setTimeout(() => {
				const modal = document.getElementById('changePasswordModal');
				if (modal) {
					const bootstrapModal = (window as any).bootstrap.Modal.getInstance(modal);
					if (bootstrapModal) {
						bootstrapModal.hide();
					}
				}
			}, 2000);
		}, 1500);
	}

	logout(event: Event): void {
		event.preventDefault();
		this.closeAllMenus();
		
		this.authService.logout().subscribe({
			next: () => {
				this.router.navigate(['/login']);
			},
			error: (error) => {
				console.error('Error al cerrar sesión:', error);
				// Limpiar sesión localmente aunque falle la petición al servidor
				this.authService.clearSession();
				this.router.navigate(['/login']);
			}
		});
	}
}
