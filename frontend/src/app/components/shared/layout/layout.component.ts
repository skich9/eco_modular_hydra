import { Component } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule } from '@angular/router';
import { NavigationComponent } from '../navigation/navigation.component';

@Component({
	selector: 'app-layout',
	standalone: true,
	imports: [CommonModule, RouterModule, NavigationComponent],
	template: `
		<div class="layout-container">
			<header class="layout-header">
				<app-navigation></app-navigation>
			</header>

			<main class="layout-body">
				<div class="container-fluid py-4">
					<router-outlet></router-outlet>
				</div>
			</main>

			<footer class="layout-footer">
				<div class="container-fluid px-4 py-2">
					<span class="text-muted small">&copy; {{ currentYear }} Instituto Tecnológico CETA - Sistema de Cobros</span>
				</div>
			</footer>
		</div>
	`,
	styles: `
		:host {
			display: block;
			min-height: 100vh;
		}

		.layout-container {
			display: flex;
			flex-direction: column;
			min-height: 100vh;
		}

		.layout-header {
			flex-shrink: 0;
			order: 1;
		}

		.layout-body {
			flex: 1;
			order: 2;
			background-color: #f3f4f6;
			overflow-y: auto;
		}

		.layout-body .container-fluid {
			padding-top: 1rem;
			padding-bottom: 1rem;
		}

		.layout-footer {
			flex-shrink: 0;
			order: 3;
			background-color: #f5f5f5;
			border-top: 1px solid #e5e7eb;
			font-size: 0.875rem;
			color: #6b7280;
		}
	`
})
export class LayoutComponent {
	currentYear = new Date().getFullYear();
}
