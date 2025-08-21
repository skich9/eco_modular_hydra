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
			<app-navigation></app-navigation>
			
			<main class="main-content">
				<div class="container-fluid py-4">
					<router-outlet></router-outlet>
				</div>
			</main>
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

		.main-content {
			flex: 1;
			background-color: #f3f4f6;
		}
	`
})
export class LayoutComponent {}
