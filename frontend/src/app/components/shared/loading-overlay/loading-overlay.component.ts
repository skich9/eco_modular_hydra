import { Component } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Observable } from 'rxjs';
import { LoadingService } from '../../../services/loading.service';

@Component({
	selector: 'app-loading-overlay',
	standalone: true,
	imports: [CommonModule],
	template: `
		<div class="loading-overlay" *ngIf="loading$ | async">
			<div class="spinner" aria-label="Cargando" role="status"></div>
		</div>
	`,
	styles: [`
		.loading-overlay {
			position: fixed;
			top: 0;
			left: 0;
			right: 0;
			bottom: 0;
			background: rgba(255, 255, 255, 0.6);
			display: flex;
			align-items: center;
			justify-content: center;
			z-index: 10000;
		}
		.spinner {
			width: 56px;
			height: 56px;
			border: 6px solid #e0e0e0;
			border-top-color: #3f51b5;
			border-radius: 50%;
			animation: spin 0.9s linear infinite;
		}
		@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
	`]
})
export class LoadingOverlayComponent {
	loading$: Observable<boolean>;
	constructor(private readonly loading: LoadingService) {
		this.loading$ = this.loading.isLoading$;
	}
}
