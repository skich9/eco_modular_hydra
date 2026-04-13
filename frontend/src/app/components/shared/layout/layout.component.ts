import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule } from '@angular/router';
import { NavigationComponent } from '../navigation/navigation.component';
import { ParametrosGeneralesService } from '../../../services/parametros-generales.service';

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
					<span class="text-muted small">&copy; {{ currentYear }} {{ nombreInstitucion }} - Sistema de Cobros</span>
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
export class LayoutComponent implements OnInit {
	currentYear = new Date().getFullYear();
	nombreInstitucion: string = 'Instituto Tecnológico CETA';

	constructor(private pgService: ParametrosGeneralesService) { }

	ngOnInit(): void {
		this.loadInstitucionName();
	}

	private loadInstitucionName(): void {
		this.pgService.getAll().subscribe({
			next: (res) => {
				if (res.success && res.data) {
					// El ID 2 corresponde al nombre en la tabla parametros_generales
					const param = res.data.find(p => p.id_parametros_generales === 2);
					if (param && param.valor) {
						this.nombreInstitucion = param.valor;
					}
				}
			},
			error: (err) => console.error('Error cargando nombre de institución en footer:', err)
		});
	}
}
