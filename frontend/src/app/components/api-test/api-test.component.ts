import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ApiService } from '../../services/api.service';

@Component({
	selector: 'app-api-test',
	standalone: true,
	imports: [CommonModule],
	template: `
		<div class="api-test">
			<h2>Prueba de Conexión API</h2>
			
			<div class="card">
				<h3>Estado de la API</h3>
				<div *ngIf="loading" class="loading">Cargando...</div>
				<div *ngIf="error" class="error">Error: {{ error }}</div>
				<div *ngIf="apiResponse" class="success">
					<pre>{{ apiResponse | json }}</pre>
				</div>
				<button (click)="testApiConnection()">Probar Conexión</button>
			</div>
		</div>
	`,
	styles: `
		.api-test {
			padding: 20px;
		}
		.card {
			background: #fff;
			border-radius: 8px;
			padding: 20px;
			box-shadow: 0 4px 6px rgba(0,0,0,0.1);
			margin-bottom: 20px;
		}
		.loading {
			color: #666;
			margin: 10px 0;
		}
		.error {
			color: #d9534f;
			margin: 10px 0;
		}
		.success {
			background: #f5f5f5;
			padding: 15px;
			border-radius: 4px;
			margin: 10px 0;
			overflow: auto;
		}
		button {
			background: #0275d8;
			color: white;
			border: none;
			padding: 10px 15px;
			border-radius: 4px;
			cursor: pointer;
			font-size: 14px;
		}
		button:hover {
			background: #0269c2;
		}
	`
})
export class ApiTestComponent implements OnInit {
	apiResponse: any = null;
	loading = false;
	error: string | null = null;

	constructor(private apiService: ApiService) {}

	ngOnInit(): void {}

	testApiConnection(): void {
		this.loading = true;
		this.error = null;
		this.apiResponse = null;

		this.apiService.testApi().subscribe({
			next: (response) => {
				this.apiResponse = response;
				this.loading = false;
			},
			error: (err) => {
				this.error = err.message || 'Error desconocido al conectar con la API';
				this.loading = false;
				console.error('Error al conectar con la API', err);
			}
		});
	}
}
