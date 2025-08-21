import { Component } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule } from '@angular/router';
import { SidebarComponent } from '../sidebar/sidebar.component';

@Component({
	selector: 'app-layout',
	standalone: true,
	imports: [CommonModule, RouterModule, SidebarComponent],
	template: `
		<div class="layout-container">
			<app-sidebar></app-sidebar>
			
			<div class="content-container">
				<div class="top-bar">
					<button class="mobile-menu-toggle" (click)="toggleSidebar()">
						<i class="fas fa-bars"></i>
					</button>
					<h2 class="page-title">{{ pageTitle }}</h2>
				</div>
				
				<main class="main-content">
					<router-outlet></router-outlet>
				</main>
			</div>
		</div>
	`,
	styles: `
		:host {
			display: block;
			height: 100vh;
		}

		.layout-container {
			display: flex;
			height: 100%;
		}

		.content-container {
			flex: 1;
			margin-left: 250px;
			transition: margin-left 0.3s ease;
			display: flex;
			flex-direction: column;
			overflow-x: hidden;
		}

		.sidebar-collapsed + .content-container {
			margin-left: 60px;
		}

		.top-bar {
			height: 64px;
			display: flex;
			align-items: center;
			padding: 0 1.5rem;
			background-color: #fff;
			border-bottom: 1px solid #e9ecef;
		}

		.mobile-menu-toggle {
			display: none;
			background: none;
			border: none;
			font-size: 1.25rem;
			cursor: pointer;
			margin-right: 1rem;
		}

		.page-title {
			font-size: 1.25rem;
			font-weight: 600;
			margin: 0;
		}

		.main-content {
			padding: 1.5rem;
			overflow-y: auto;
			flex: 1;
		}

		@media (max-width: 991.98px) {
			.content-container {
				margin-left: 0 !important;
			}

			.mobile-menu-toggle {
				display: block;
			}
		}
	`
})
export class LayoutComponent {
	pageTitle = 'Dashboard';

	toggleSidebar() {
		const sidebar = document.getElementById('sidebar');
		if (sidebar) {
			sidebar.classList.toggle('sidebar-open');
			const overlay = document.querySelector('.sidebar-overlay') as HTMLElement;
			if (overlay) {
				overlay.style.display = overlay.style.display === 'none' ? 'block' : 'none';
			}
		}
	}
}
