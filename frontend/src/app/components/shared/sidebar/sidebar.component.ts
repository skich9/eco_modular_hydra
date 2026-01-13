import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule } from '@angular/router';
import { AuthService } from '../../../services/auth.service';
import { Usuario } from '../../../models/usuario.model';

@Component({
	selector: 'app-sidebar',
	standalone: true,
	imports: [CommonModule, RouterModule],
	template: `
		<div class="sidebar" [class.sidebar-collapsed]="isCollapsed" id="sidebar">
			<!-- Header del Sidebar -->
			<div class="sidebar-header">
				<div class="sidebar-brand">
					<i class="fas fa-building sidebar-icon"></i>
					<span *ngIf="!isCollapsed" class="sidebar-title">Sistema CETA</span>
				</div>
				<button class="sidebar-toggle" (click)="toggleSidebar()">
					<i class="fas" [ngClass]="isCollapsed ? 'fa-angle-right' : 'fa-angle-left'"></i>
				</button>
			</div>

			<!-- Información del Usuario -->
			<div class="sidebar-user">
				<div class="sidebar-user-avatar">
					{{ getInitials(currentUser?.nombre || 'Usuario') }}
				</div>
				<div *ngIf="!isCollapsed" class="sidebar-user-info">
					<p class="sidebar-user-name">{{ currentUser?.nombre || 'Usuario' }} {{ currentUser?.ap_paterno || '' }}</p>
					<p class="sidebar-user-role">{{ currentUser?.rol?.nombre || 'Sin rol' }}</p>
				</div>
			</div>

			<!-- Navegación Principal -->
			<nav class="sidebar-nav">
				<!-- Dashboard -->
				<a routerLink="/dashboard" routerLinkActive="active" class="sidebar-nav-item">
					<i class="fas fa-tachometer-alt sidebar-nav-icon"></i>
					<span *ngIf="!isCollapsed" class="sidebar-nav-text">Dashboard</span>
				</a>

				<!-- Gestión de Usuarios -->
				<a *ngIf="hasRole('Administrador')" routerLink="/usuarios" routerLinkActive="active" class="sidebar-nav-item">
					<i class="fas fa-users sidebar-nav-icon"></i>
					<span *ngIf="!isCollapsed" class="sidebar-nav-text">Usuarios</span>
				</a>

				<!-- Gestión de Roles -->
				<a *ngIf="hasRole('Administrador')" routerLink="/roles" routerLinkActive="active" class="sidebar-nav-item">
					<i class="fas fa-user-tag sidebar-nav-icon"></i>
					<span *ngIf="!isCollapsed" class="sidebar-nav-text">Roles</span>
				</a>

				<!-- Gestión de Funciones -->
				<a *ngIf="hasRole('Administrador')" routerLink="/funciones" routerLinkActive="active" class="sidebar-nav-item">
					<i class="fas fa-cogs sidebar-nav-icon"></i>
					<span *ngIf="!isCollapsed" class="sidebar-nav-text">Funciones</span>
				</a>

				<!-- Separador -->
				<div class="sidebar-divider"></div>

				<!-- Gestión de Materias -->
				<a routerLink="/materias" routerLinkActive="active" class="sidebar-nav-item">
					<i class="fas fa-book sidebar-nav-icon"></i>
					<span *ngIf="!isCollapsed" class="sidebar-nav-text">Materias</span>
				</a>

				<!-- Gestión de Parámetros del Sistema -->
				<a *ngIf="hasRole('Administrador')" routerLink="/parametros" routerLinkActive="active" class="sidebar-nav-item">
					<i class="fas fa-sliders-h sidebar-nav-icon"></i>
					<span *ngIf="!isCollapsed" class="sidebar-nav-text">Parámetros Sistema</span>
				</a>

				<!-- Gestión de Parámetros Económicos -->
				<a *ngIf="hasRole('Administrador')" routerLink="/parametros-economicos" routerLinkActive="active" class="sidebar-nav-item">
					<i class="fas fa-money-bill-alt sidebar-nav-icon"></i>
					<span *ngIf="!isCollapsed" class="sidebar-nav-text">Parámetros Económicos</span>
				</a>

				<!-- Reportes -->
				<div class="sidebar-nav-dropdown" [class.open]="isReportesOpen">
					<a href="#" class="sidebar-nav-item sidebar-nav-dropdown-toggle" (click)="toggleReportes($event)">
						<i class="fas fa-chart-bar sidebar-nav-icon"></i>
						<span *ngIf="!isCollapsed" class="sidebar-nav-text">Reportes</span>
						<i *ngIf="!isCollapsed" class="fas fa-chevron-down sidebar-nav-dropdown-icon" [class.rotate]="isReportesOpen"></i>
					</a>
					<div class="sidebar-nav-dropdown-menu" [class.show]="isReportesOpen">
						<a routerLink="/reportes/libro-diario" routerLinkActive="active" class="sidebar-nav-item sidebar-nav-subitem">
							<i class="fas fa-book sidebar-nav-icon"></i>
							<span *ngIf="!isCollapsed" class="sidebar-nav-text">Libro Diario</span>
						</a>
					</div>
				</div>

				<!-- Configuración -->
				<a *ngIf="hasRole('Administrador')" routerLink="/configuracion" routerLinkActive="active" class="sidebar-nav-item">
					<i class="fas fa-cog sidebar-nav-icon"></i>
					<span *ngIf="!isCollapsed" class="sidebar-nav-text">Configuración</span>
				</a>
			</nav>

			<!-- Footer del Sidebar -->
			<div class="sidebar-footer">
				<a routerLink="/change-password" class="sidebar-footer-item">
					<i class="fas fa-key sidebar-footer-icon"></i>
					<span *ngIf="!isCollapsed" class="sidebar-footer-text">Cambiar Contraseña</span>
				</a>
				<a (click)="logout()" class="sidebar-footer-item sidebar-footer-logout">
					<i class="fas fa-sign-out-alt sidebar-footer-icon"></i>
					<span *ngIf="!isCollapsed" class="sidebar-footer-text">Cerrar Sesión</span>
				</a>
			</div>
		</div>

		<!-- Overlay para móvil -->
		<div *ngIf="showOverlay" class="sidebar-overlay" (click)="closeSidebarOnMobile()"></div>
	`,
	styles: `
		:host {
			display: block;
		}

		.sidebar {
			width: 250px;
			height: 100vh;
			position: fixed;
			top: 0;
			left: 0;
			background-color: #ffffff;
			box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
			display: flex;
			flex-direction: column;
			transition: width 0.3s ease;
			z-index: 1000;
		}

		.sidebar-collapsed {
			width: 60px;
		}

		.sidebar-header {
			display: flex;
			align-items: center;
			justify-content: space-between;
			padding: 1rem;
			height: 64px;
			background-color: #0275d8;
			color: white;
		}

		.sidebar-brand {
			display: flex;
			align-items: center;
		}

		.sidebar-icon {
			font-size: 1.25rem;
			margin-right: 0.75rem;
		}

		.sidebar-title {
			font-weight: bold;
			font-size: 1rem;
			white-space: nowrap;
		}

		.sidebar-toggle {
			background: none;
			border: none;
			color: white;
			cursor: pointer;
			display: flex;
			align-items: center;
			justify-content: center;
			width: 24px;
			height: 24px;
			padding: 0;
		}

		.sidebar-user {
			display: flex;
			align-items: center;
			padding: 1rem;
			border-bottom: 1px solid #e9ecef;
		}

		.sidebar-user-avatar {
			width: 40px;
			height: 40px;
			border-radius: 50%;
			background-color: #0275d8;
			color: white;
			display: flex;
			align-items: center;
			justify-content: center;
			font-weight: bold;
			font-size: 1rem;
			flex-shrink: 0;
		}

		.sidebar-user-info {
			margin-left: 0.75rem;
			overflow: hidden;
		}

		.sidebar-user-name {
			font-weight: 600;
			margin: 0;
			white-space: nowrap;
			overflow: hidden;
			text-overflow: ellipsis;
			font-size: 0.875rem;
		}

		.sidebar-user-role {
			margin: 0;
			color: #6c757d;
			font-size: 0.75rem;
		}

		.sidebar-nav {
			flex-grow: 1;
			overflow-y: auto;
			padding: 1rem 0;
		}

		.sidebar-nav-item {
			display: flex;
			align-items: center;
			padding: 0.75rem 1rem;
			color: #343a40;
			text-decoration: none;
			transition: all 0.2s ease;
			border-radius: 0;
		}

		.sidebar-nav-item:hover, .sidebar-nav-item.active {
			background-color: rgba(2, 117, 216, 0.1);
			color: #0275d8;
		}

		.sidebar-nav-item.active .sidebar-nav-icon {
			color: #0275d8;
		}

		.sidebar-nav-icon {
			width: 20px;
			text-align: center;
			margin-right: 0.75rem;
			color: #6c757d;
		}

		.sidebar-nav-text {
			white-space: nowrap;
		}

		/* Dropdown styles */
		.sidebar-nav-dropdown {
			position: relative;
		}

		.sidebar-nav-dropdown-toggle {
			justify-content: space-between;
		}

		.sidebar-nav-dropdown-icon {
			transition: transform 0.3s ease;
			font-size: 0.75rem;
			margin-left: auto;
		}

		.sidebar-nav-dropdown-icon.rotate {
			transform: rotate(180deg);
		}

		.sidebar-nav-dropdown-menu {
			max-height: 0;
			overflow: hidden;
			transition: max-height 0.3s ease;
		}

		.sidebar-nav-dropdown-menu.show {
			max-height: 200px;
		}

		.sidebar-nav-subitem {
			padding-left: 3.5rem !important;
			background-color: rgba(2, 117, 216, 0.05);
		}

		.sidebar-nav-subitem:hover, .sidebar-nav-subitem.active {
			background-color: rgba(2, 117, 216, 0.15);
		}

		.sidebar-divider {
			height: 1px;
			margin: 1rem 0;
			background-color: #e9ecef;
		}

		.sidebar-footer {
			padding: 0.5rem;
			border-top: 1px solid #e9ecef;
		}

		.sidebar-footer-item {
			display: flex;
			align-items: center;
			padding: 0.5rem;
			color: #343a40;
			text-decoration: none;
			border-radius: 4px;
			margin-bottom: 0.25rem;
			cursor: pointer;
		}

		.sidebar-footer-item:hover {
			background-color: #f8f9fa;
		}

		.sidebar-footer-logout {
			color: #dc3545;
		}

		.sidebar-footer-icon {
			width: 20px;
			text-align: center;
			margin-right: 0.75rem;
		}

		.sidebar-overlay {
			position: fixed;
			top: 0;
			left: 0;
			width: 100%;
			height: 100%;
			background-color: rgba(0, 0, 0, 0.5);
			z-index: 999;
		}

		/* Responsive */
		@media (max-width: 991.98px) {
			.sidebar {
				transform: translateX(-100%);
				width: 250px !important;
			}

			.sidebar.sidebar-open {
				transform: translateX(0);
			}

			.sidebar-collapsed {
				width: 250px !important;
			}
		}
	`
})
export class SidebarComponent implements OnInit {
	currentUser: Usuario | null = null;
	isCollapsed = false;
	showOverlay = false;
	isMobile = false;
	isReportesOpen = false;

	constructor(private authService: AuthService) {}

	ngOnInit(): void {
		// Suscribirse al usuario actual
		this.authService.currentUser$.subscribe(user => {
			this.currentUser = user;
		});

		// Detectar si es dispositivo móvil
		this.checkIfMobile();
		window.addEventListener('resize', this.onResize.bind(this));
	}

	checkIfMobile(): void {
		this.isMobile = window.innerWidth < 992;
		if (this.isMobile) {
			this.isCollapsed = true;
		}
	}

	onResize(): void {
		this.checkIfMobile();
	}

	toggleSidebar(): void {
		this.isCollapsed = !this.isCollapsed;
		if (this.isMobile) {
			this.showOverlay = !this.isCollapsed;
		}
	}

	closeSidebarOnMobile(): void {
		if (this.isMobile) {
			this.isCollapsed = true;
			this.showOverlay = false;
		}
	}

	logout(): void {
		this.authService.logout().subscribe({
			next: () => {
				// La redirección la maneja el servicio de autenticación
			},
			error: (err) => {
				console.error('Error al cerrar sesión:', err);
				// Forzar logout local en caso de error con el servidor
				this.authService.clearSession();
			}
		});
	}

	getInitials(name: string): string {
		if (!name) return 'U';
		return name.charAt(0).toUpperCase();
	}

	hasRole(roleName: string): boolean {
		return this.authService.hasRole(roleName);
	}

	toggleReportes(event: Event): void {
		event.preventDefault();
		this.isReportesOpen = !this.isReportesOpen;
	}
}
