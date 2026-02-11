import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ActivatedRoute } from '@angular/router';
import { AuthService } from '../../../services/auth.service';
import { Usuario } from '../../../models/usuario.model';

@Component({
	selector: 'app-dashboard',
	standalone: true,
	imports: [CommonModule],
	templateUrl: './dashboard.component.html',
	styleUrls: ['./dashboard.component.scss']
})
export class DashboardComponent implements OnInit {
	currentUser: Usuario | null = null;
	errorMessage: string | null = null;

	constructor(
		private authService: AuthService,
		private route: ActivatedRoute
	) {}

	ngOnInit(): void {
		this.authService.currentUser$.subscribe(user => {
			this.currentUser = user;
		});

		// Mostrar mensaje de error si viene de una redirección por falta de permisos
		this.route.queryParams.subscribe(params => {
			if (params['error'] === 'no_permission') {
				this.errorMessage = params['message'] || 'No tienes permisos para acceder a esa sección';
				// Limpiar el mensaje después de 5 segundos
				setTimeout(() => {
					this.errorMessage = null;
				}, 5000);
			}
		});
	}
}
