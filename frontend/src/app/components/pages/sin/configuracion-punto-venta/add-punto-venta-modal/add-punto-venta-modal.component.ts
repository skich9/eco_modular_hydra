import { Component, EventEmitter, Output } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';

@Component({
	selector: 'app-add-punto-venta-modal',
	standalone: true,
	imports: [CommonModule, ReactiveFormsModule],
	templateUrl: './add-punto-venta-modal.component.html',
	styleUrls: ['./add-punto-venta-modal.component.scss']
})
export class AddPuntoVentaModalComponent {
	@Output() puntoVentaCreated = new EventEmitter<any>();

	form: FormGroup;
	submitError: string = '';

	tiposPuntoVenta: any[] = [
		{ id: 1, nombre: 'Punto de Venta Fijo' },
		{ id: 2, nombre: 'Punto de Venta Movil' },
		{ id: 3, nombre: 'Punto de Venta Virtual' }
	];

	sucursales: any[] = [
		{ codigo: 0, nombre: 'Casa Matriz' },
		{ codigo: 1, nombre: 'Sucursal 1' },
		{ codigo: 2, nombre: 'Sucursal 2' }
	];

	usuarios: any[] = [
		{ id: 1, nombre: 'Usuario Admin' },
		{ id: 2, nombre: 'Usuario Cajero' },
		{ id: 3, nombre: 'Usuario Operador' }
	];

	constructor(private fb: FormBuilder) {
		this.form = this.fb.group({
			sucursal: ['', [Validators.required]],
			tipo_punto_venta: ['', [Validators.required]],
			cuis_creacion: ['', [Validators.required]],
			nombre_punto_venta: ['', [Validators.required, Validators.maxLength(100)]],
			descripcion: ['', [Validators.maxLength(255)]],
			usuario: ['', [Validators.required]],
			fecha_finalizacion: ['']
		});
	}

	onSubmit(): void {
		this.submitError = '';

		if (this.form.invalid) {
			this.form.markAllAsTouched();
			this.submitError = 'Por favor complete todos los campos requeridos.';
			return;
		}

		const formData = this.form.value;
		this.puntoVentaCreated.emit(formData);
		this.resetForm();
	}

	resetForm(): void {
		this.form.reset();
		this.submitError = '';
	}

	closeModal(): void {
		this.resetForm();
	}
}
