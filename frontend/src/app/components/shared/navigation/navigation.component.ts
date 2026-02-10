import { Component, OnInit, HostListener } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Router, RouterModule } from '@angular/router';
import { ReactiveFormsModule, FormBuilder, FormGroup, Validators } from '@angular/forms';
import { AuthService } from '../../../services/auth.service';
import { PermissionService } from '../../../services/permission.service';
import { Carrera } from '../../../models/carrera.model';
import { CarreraService } from '../../../services/carrera.service';
import { Usuario } from '../../../models/usuario.model';

interface MenuItem {
	name: string;
	icon: string;
	submenu: SubMenuItem[];
	modulo?: string; // Nombre del módulo para verificar permisos
}

interface SubMenuItem {
	name: string;
	icon: string;
	route: string;
	params?: any;
	permissionCode?: string; // Código de permiso para mostrar la opción
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

	// Definición completa de menús con módulos
	private allMenuItems: MenuItem[] = [
		{
			name: 'Cobros',
			icon: 'fas fa-money-bill-wave',
			modulo: 'Cobros',
			submenu: [
				{ name: 'Gestionar Cobros', icon: 'fa-cash-register', route: '/cobros', permissionCode: 'cobros_gestionar' }
			]
		},
		{
			name: 'Reportes',
			icon: 'fas fa-chart-bar',
			modulo: 'Reportes',
			submenu: [
				{ name: 'Libro Diario', icon: 'fa-book', route: '/reportes/libro-diario', permissionCode: 'reportes_libro_diario' }
			]
		},
		{
			name: 'Reimpresión',
			icon: 'fas fa-print',
			modulo: 'Reimpresión',
			submenu: [
				{ name: 'Facturación posterior', icon: 'fa-file-invoice-dollar', route: '/reimpresion/facturacion-posterior', permissionCode: 'reimpresion_facturacion_posterior' }
			]
		},
		{
			name: 'Académico',
			icon: 'fas fa-graduation-cap',
			modulo: 'Académico',
			submenu: []
		},
		{
			name: 'SIN',
			icon: 'fas fa-file-invoice',
			modulo: 'SIN',
			submenu: [
				{ name: 'Estado de Factura / Anulación', icon: 'fa-file-signature', route: '/sin/estado-factura', permissionCode: 'sin_estado_factura' },
				{ name: 'Contingencias', icon: 'fa-exclamation-triangle', route: '/sin/contingencias', permissionCode: 'sin_contingencias' },
				{ name: 'Configuración Punto de Venta', icon: 'fa-store', route: '/sin/configuracion-punto-venta', permissionCode: 'sin_configuracion_punto_venta' }
			]
		},
		{
			name: 'Configuración',
			icon: 'fas fa-cog',
			modulo: 'Configuración',
			submenu: [
				{ name: 'Usuarios', icon: 'fa-users', route: '/usuarios', permissionCode: 'configuracion_usuarios' },
				{ name: 'Roles', icon: 'fa-user-shield', route: '/roles', permissionCode: 'configuracion_roles' },
				{ name: 'Parámetros de Sistema', icon: 'fa-sliders-h', route: '/parametros', permissionCode: 'configuracion_parametros' },
				{ name: 'Configuración de Descuentos', icon: 'fa-percent', route: '/descuentos', permissionCode: 'configuracion_descuentos' },
				{ name: 'Configuración de Costos', icon: 'fa-coins', route: '/costos', permissionCode: 'configuracion_costos' },
				{ name: 'Configuración de Costos por Créditos', icon: 'fa-calculator', route: '/costos-creditos', permissionCode: 'configuracion_costos_creditos' },
				{ name: 'Configuraciones Generales', icon: 'fa-cogs', route: '/configuraciones-generales', permissionCode: 'configuracion_generales' }
			]
		}
	];

	// Menús filtrados según permisos del usuario
	menuItems: MenuItem[] = [];

	constructor(
		private authService: AuthService,
		private permissionService: PermissionService,
		private router: Router,
		private formBuilder: FormBuilder,
		private carreraService: CarreraService
	) {
		this.changePasswordForm = this.formBuilder.group({
			contraseniaActual: ['', [Validators.required]],
			contraseniaNueva: ['', [Validators.required, Validators.minLength(6)]],
			contraseniaNuevaConfirm: ['', [Validators.required]]
		}, { validators: this.contraseniaMatchValidator });
	}

	ngOnInit(): void {
		// Suscribirse a los cambios del usuario actual
		this.authService.currentUser$.subscribe((user: Usuario | null) => {
			this.currentUser = user;
			// Filtrar menús según permisos del usuario
			this.filterMenusByPermissions();
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

	private filterMenusByPermissions(): void {
		this.menuItems = this.allMenuItems
			.map(menu => {
				// Filtrar submenús según permisos
				const filteredSubmenu = menu.submenu.filter(subitem => {
					if (subitem.permissionCode) {
						return this.permissionService.hasPermission(subitem.permissionCode);
					}
					return true; // Si no tiene código de permiso, mostrar por defecto
				});

				// Si el menú tiene módulo, verificar si el usuario tiene al menos una función de ese módulo
				if (menu.modulo) {
					const hasFuncionDelModulo = this.permissionService.hasAnyFunctionFromModule(menu.modulo);
					if (!hasFuncionDelModulo) {
						return null;
					}
				}

				// Si el menú no tiene submenús después del filtrado, no mostrarlo
				if (filteredSubmenu.length === 0 && menu.submenu.length > 0) {
					return null;
				}

				return {
					...menu,
					submenu: filteredSubmenu
				};
			})
			.filter(menu => menu !== null) as MenuItem[];

		// Cargar carreras para el menú académico si está visible
		const academicoIndex = this.menuItems.findIndex(mi => mi.name === 'Académico');
		if (academicoIndex !== -1) {
			this.loadCarreras();
		}
	}

	private loadCarreras(): void {
		this.loadingCarreras = true;
		this.carreraService.getAll().subscribe({
			next: (res: any) => {
				this.carreras = res.data || [];
				const idx = this.menuItems.findIndex(mi => mi.name === 'Académico');
				if (idx !== -1) {
					const fixedItem = {
						name: 'Asignación de Becas/Descuentos',
						icon: 'fa-percent',
						route: '/academico/asignacion-becas-descuentos',
						permissionCode: 'academico_asignacion_becas'
					};

					// Filtrar item fijo según permisos
					const submenuItems: SubMenuItem[] = [];
					if (!fixedItem.permissionCode || this.permissionService.hasPermission(fixedItem.permissionCode)) {
						submenuItems.push(fixedItem);
					}

					// Agregar carreras (sin permiso específico por ahora)
					submenuItems.push(...this.carreras.map(c => ({
						name: c.nombre,
						icon: 'fa-university',
						route: `/academico/${c.codigo_carrera}`
					})));

					this.menuItems[idx].submenu = submenuItems;
				}
			},
			error: (err: any) => {
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

	contraseniaMatchValidator(form: FormGroup) {
		const nueva = form.get('contraseniaNueva');
		const confirmar = form.get('contraseniaNuevaConfirm');

		if (nueva && confirmar && nueva.value !== confirmar.value) {
			confirmar.setErrors({ mismatch: true });
			return { mismatch: true };
		}

		if (confirmar?.hasError('mismatch')) {
			confirmar.setErrors(null);
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

		const { contraseniaActual, contraseniaNueva, contraseniaNuevaConfirm } = this.changePasswordForm.value;

		this.authService.changePassword(contraseniaActual, contraseniaNueva, contraseniaNuevaConfirm).subscribe({
			next: () => {
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
			},
			error: (err: any) => {
				this.isChangingPassword = false;
				this.changePasswordError = err?.error?.message || 'Error al cambiar la contraseña';
			}
		});
	}

	logout(event: Event): void {
		event.preventDefault();
		this.closeAllMenus();

		this.authService.logout().subscribe({
			next: () => {
				this.router.navigate(['/login']);
			},
			error: (error: any) => {
				console.error('Error al cerrar sesión:', error);
				// Limpiar sesión localmente aunque falle la petición al servidor
				this.authService.clearSession();
				this.router.navigate(['/login']);
			}
		});
	}
}
