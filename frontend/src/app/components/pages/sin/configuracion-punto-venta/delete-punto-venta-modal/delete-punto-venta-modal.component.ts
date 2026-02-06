import { Component, EventEmitter, Output, Input } from '@angular/core';
import { CommonModule } from '@angular/common';

@Component({
	selector: 'app-delete-punto-venta-modal',
	standalone: true,
	imports: [CommonModule],
	templateUrl: './delete-punto-venta-modal.component.html',
	styleUrls: ['./delete-punto-venta-modal.component.scss']
})
export class DeletePuntoVentaModalComponent {
	@Input() puntoVenta: any = null;
	@Output() confirmDelete = new EventEmitter<any>();
	@Output() cancelDelete = new EventEmitter<void>();

	isDeleting: boolean = false;

	onConfirm(): void {
		if (this.puntoVenta) {
			this.isDeleting = true;
			this.confirmDelete.emit(this.puntoVenta);
		}
	}

	onCancel(): void {
		this.cancelDelete.emit();
		this.closeModal();
	}

	closeModal(): void {
		const modalElement = document.getElementById('deletePuntoVentaModal');
		if (modalElement) {
			const modal = (window as any).bootstrap.Modal.getInstance(modalElement);
			if (modal) {
				modal.hide();
			}
		}
		this.isDeleting = false;
	}
}
