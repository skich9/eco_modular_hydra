import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Router, RouterModule } from '@angular/router';
import { AuthService } from '../../../services/auth.service';
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
	imports: [CommonModule, RouterModule],
	template: `
		<!-- Navigation Header -->
		<nav class="nav-menu">
			<div class="container-fluid px-4">
				<div class="d-flex justify-content-between align-items-center" style="height: 4rem;">
					<div class="d-flex align-items-center">
						<a routerLink="/dashboard" class="nav-logo text-decoration-none">
							<div class="nav-logo-icon">
								<img src="assets/images/logo-ceta.png" alt="Logo CETA" class="login-logo-image">
							</div>
							<div class="nav-logo-text">
								<span class="nav-logo-title">Sistema de Cobros</span>
								<p class="nav-logo-subtitle mb-0">Instituto Tecnológico CETA</p>
							</div>
						</a>
					</div>

					<div class="nav-user-info" *ngIf="currentUser">
						<div class="text-sm me-3">
							<span class="nav-user-name">{{ currentUser.nombre_completo }}</span>
							<span class="nav-user-role">({{ currentUser.rol?.nombre }})</span>
						</div>
						
						<div class="position-relative">
							<button 
								type="button" 
								class="btn btn-link p-0 d-flex align-items-center text-decoration-none"
								(click)="toggleDropdown()"
							>
								<div class="nav-user-avatar">
									{{ getUserInitial() }}
								</div>
							</button>
							
							<div 
								[class]="'nav-dropdown' + (showUserDropdown ? '' : ' d-none')"
								id="userDropdown"
							>
								<a href="#" class="nav-dropdown-item text-decoration-none" (click)="changePassword($event)">
									<i class="fas fa-key me-2"></i>Cambiar Contraseña
								</a>
								<a href="#" class="nav-dropdown-item text-decoration-none" (click)="logout($event)">
									<i class="fas fa-sign-out-alt me-2"></i>Cerrar Sesión
								</a>
							</div>
						</div>
					</div>
				</div>
			</div>
		</nav>

		<!-- Secondary Navigation -->
		<nav class="nav-menu-secondary">
			<div class="container-fluid px-4">
				<div class="d-flex">
					<div 
						*ngFor="let item of menuItems; let i = index" 
						class="nav-menu-item position-relative"
					>
						<button 
							type="button" 
							class="nav-menu-button btn btn-link text-decoration-none"
							(click)="toggleConfigMenu(i)"
						>
							<i class="{{ item.icon }} nav-menu-icon"></i>
							{{ item.name }}
							<i class="fas fa-chevron-down ms-2" style="font-size: 0.75rem;"></i>
						</button>
						
						<div 
							[class]="'nav-submenu' + (activeSubmenu === i ? '' : ' d-none')"
							[id]="'configDropdown' + i"
						>
							<a 
								*ngFor="let subitem of item.submenu"
								[routerLink]="subitem.route"
								class="nav-submenu-item text-decoration-none"
								[class.active]="isActiveRoute(subitem.route)"
								(click)="closeAllMenus()"
							>
								<i class="fas {{ subitem.icon }} me-2"></i>{{ subitem.name }}
							</a>
						</div>
					</div>
				</div>
			</div>
		</nav>
	`,
	styles: []
})
export class NavigationComponent implements OnInit {
	currentUser: Usuario | null = null;
	showUserDropdown = false;
	activeSubmenu: number | null = null;

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
			submenu: [
				{ name: 'Materias', icon: 'fa-book', route: '/materias' },
				{ name: 'Carreras', icon: 'fa-university', route: '/carreras' }
			]
		},
        {
			name: 'Configuración',
			icon: 'fas fa-cog',
			submenu: [
				{ name: 'Usuarios', icon: 'fa-users', route: '/usuarios' },
				{ name: 'Roles', icon: 'fa-user-shield', route: '/roles' },
				{ name: 'Parámetros', icon: 'fa-sliders-h', route: '/parametros' }
			]
		}
	];

	constructor(
		private authService: AuthService,
		private router: Router
	) {}

	ngOnInit(): void {
		// Suscribirse a los cambios del usuario actual
		this.authService.currentUser$.subscribe(user => {
			this.currentUser = user;
		});

		// Cerrar menús al hacer clic fuera
		document.addEventListener('click', (event) => {
			const target = event.target as HTMLElement;
			const isInsideMenu = target.closest('.nav-dropdown, .nav-submenu, .nav-menu-button, [data-dropdown-toggle]');
			
			if (!isInsideMenu) {
				this.closeAllMenus();
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

	changePassword(event: Event): void {
		event.preventDefault();
		this.closeAllMenus();
		this.router.navigate(['/change-password']);
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
