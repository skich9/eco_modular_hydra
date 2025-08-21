import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { AuthService } from '../../services/auth.service';
import { Usuario } from '../../models/usuario.model';

@Component({
	selector: 'app-dashboard',
	standalone: true,
	imports: [CommonModule],
	template: `
		<div class="dashboard-container">
			<div class="welcome-section">
				<h1>Bienvenido/a, {{ currentUser?.nombre || 'Usuario' }}</h1>
				<p class="welcome-text">Panel de control del Sistema de Cobros CETA</p>
			</div>

			<div class="dashboard-cards">
				<!-- Tarjeta de Estadísticas -->
				<div class="dashboard-card">
					<div class="card-header">
						<i class="fas fa-chart-line card-icon"></i>
						<h3>Estadísticas</h3>
					</div>
					<div class="card-body">
						<div class="stat-item">
							<span class="stat-label">Estudiantes activos</span>
							<span class="stat-value">352</span>
						</div>
						<div class="stat-item">
							<span class="stat-label">Materias registradas</span>
							<span class="stat-value">45</span>
						</div>
						<div class="stat-item">
							<span class="stat-label">Cobros del mes</span>
							<span class="stat-value">124</span>
						</div>
					</div>
				</div>

				<!-- Tarjeta de Accesos Rápidos -->
				<div class="dashboard-card">
					<div class="card-header">
						<i class="fas fa-bolt card-icon"></i>
						<h3>Accesos Rápidos</h3>
					</div>
					<div class="card-body">
						<div class="quick-links">
							<a class="quick-link">
								<i class="fas fa-user-graduate"></i>
								<span>Estudiantes</span>
							</a>
							<a class="quick-link">
								<i class="fas fa-book"></i>
								<span>Materias</span>
							</a>
							<a class="quick-link">
								<i class="fas fa-money-bill-wave"></i>
								<span>Cobros</span>
							</a>
							<a class="quick-link">
								<i class="fas fa-cog"></i>
								<span>Configuración</span>
							</a>
						</div>
					</div>
				</div>

				<!-- Tarjeta de Actividad Reciente -->
				<div class="dashboard-card">
					<div class="card-header">
						<i class="fas fa-history card-icon"></i>
						<h3>Actividad Reciente</h3>
					</div>
					<div class="card-body">
						<div class="activity-list">
							<div class="activity-item">
								<span class="activity-time">Hace 2 horas</span>
								<span class="activity-description">Se registró un nuevo estudiante</span>
							</div>
							<div class="activity-item">
								<span class="activity-time">Hace 3 horas</span>
								<span class="activity-description">Se actualizó el parámetro económico "Matrícula"</span>
							</div>
							<div class="activity-item">
								<span class="activity-time">Ayer</span>
								<span class="activity-description">Se generaron reportes mensuales</span>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
	`,
	styles: `
		.dashboard-container {
			padding: 1.5rem;
		}

		.welcome-section {
			margin-bottom: 2rem;
		}

		.welcome-text {
			color: #6c757d;
			font-size: 1.1rem;
		}

		.dashboard-cards {
			display: grid;
			grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
			gap: 1.5rem;
		}

		.dashboard-card {
			background-color: #fff;
			border-radius: 8px;
			box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
			overflow: hidden;
		}

		.card-header {
			background-color: #f8f9fa;
			padding: 1rem;
			display: flex;
			align-items: center;
			border-bottom: 1px solid #e9ecef;
		}

		.card-icon {
			font-size: 1.25rem;
			color: #0275d8;
			margin-right: 0.75rem;
		}

		.card-header h3 {
			margin: 0;
			font-size: 1.1rem;
			font-weight: 600;
		}

		.card-body {
			padding: 1rem;
		}

		/* Estilos para estadísticas */
		.stat-item {
			display: flex;
			justify-content: space-between;
			margin-bottom: 0.75rem;
			padding-bottom: 0.75rem;
			border-bottom: 1px solid #e9ecef;
		}

		.stat-item:last-child {
			margin-bottom: 0;
			padding-bottom: 0;
			border-bottom: none;
		}

		.stat-label {
			color: #6c757d;
		}

		.stat-value {
			font-weight: 600;
			color: #0275d8;
		}

		/* Estilos para accesos rápidos */
		.quick-links {
			display: grid;
			grid-template-columns: 1fr 1fr;
			gap: 1rem;
		}

		.quick-link {
			display: flex;
			flex-direction: column;
			align-items: center;
			padding: 1rem;
			background-color: #f8f9fa;
			border-radius: 8px;
			text-decoration: none;
			color: #343a40;
			transition: all 0.2s ease;
			cursor: pointer;
		}

		.quick-link:hover {
			background-color: #e9ecef;
			transform: translateY(-2px);
		}

		.quick-link i {
			font-size: 1.5rem;
			color: #0275d8;
			margin-bottom: 0.5rem;
		}

		/* Estilos para actividad reciente */
		.activity-list {
			display: flex;
			flex-direction: column;
		}

		.activity-item {
			display: flex;
			flex-direction: column;
			padding: 0.75rem 0;
			border-bottom: 1px solid #e9ecef;
		}

		.activity-item:last-child {
			border-bottom: none;
		}

		.activity-time {
			font-size: 0.875rem;
			color: #6c757d;
			margin-bottom: 0.25rem;
		}

		.activity-description {
			color: #343a40;
		}

		/* Responsive */
		@media (max-width: 991.98px) {
			.dashboard-cards {
				grid-template-columns: 1fr;
			}
		}
	`
})
export class DashboardComponent implements OnInit {
	currentUser: Usuario | null = null;

	constructor(private authService: AuthService) {}

	ngOnInit(): void {
		this.authService.currentUser$.subscribe(user => {
			this.currentUser = user;
		});
	}
}
