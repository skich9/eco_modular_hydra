import { Component, EventEmitter, Output, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { PuntoVentaService, TipoPuntoVenta, ApiResponse } from '../../../../../services/punto-venta.service';

@Component({
	selector: 'app-add-punto-venta-modal',
	standalone: true,
	imports: [CommonModule, ReactiveFormsModule],
	templateUrl: './add-punto-venta-modal.component.html',
	styleUrls: ['./add-punto-venta-modal.component.scss']
})
export class AddPuntoVentaModalComponent implements OnInit {
	@Output() puntoVentaCreated = new EventEmitter<any>();
	@Output() puntoVentaError = new EventEmitter<string>();

	form: FormGroup;
	submitError: string = '';
	isLoading: boolean = false;

	tiposPuntoVenta: TipoPuntoVenta[] = [];
	sucursales: any[] = [
		{ codigo: 0, nombre: 'Casa Matriz' },
		{ codigo: 1, nombre: 'Sucursal' }
	];

	cuisVigente: string = '';
	loadingCuis: boolean = false;

	constructor(
		private fb: FormBuilder,
		private puntoVentaService: PuntoVentaService
	) {
		this.form = this.fb.group({
			sucursal: [0, [Validators.required]],
			tipo_punto_venta: ['', [Validators.required]],
			cuis_creacion: [{ value: '', disabled: true }],
			nombre_punto_venta: ['', [Validators.required, Validators.maxLength(100)]],
			descripcion: ['', [Validators.required, Validators.maxLength(255)]]
		});
	}

	ngOnInit(): void {
		this.loadTiposPuntoVenta();
		this.loadCuisVigente();
	}

	loadTiposPuntoVenta(): void {
		this.puntoVentaService.getTiposPuntoVenta().subscribe({
			next: (response: ApiResponse<TipoPuntoVenta[]>) => {
				if (response.success && response.data) {
					this.tiposPuntoVenta = response.data;
				}
			},
			error: (error: any) => {
				console.error('Error al cargar tipos de punto de venta:', error);
				this.submitError = 'Error al cargar tipos de punto de venta';
			}
		});
	}

	loadCuisVigente(): void {
		this.loadingCuis = true;
		const codigoSucursal = this.form.get('sucursal')?.value || 0;

		this.puntoVentaService.getStatus(0, codigoSucursal).subscribe({
			next: (response: any) => {
				this.loadingCuis = false;
				if (response.success && response.data?.cuis) {
					this.cuisVigente = response.data.cuis.codigo_cuis;
					this.form.patchValue({
						cuis_creacion: this.cuisVigente
					});
				}
			},
			error: (error: any) => {
				this.loadingCuis = false;
				console.error('Error al cargar CUIS vigente:', error);
				this.cuisVigente = 'Error al cargar CUIS';
			}
		});
	}

	onSucursalChange(): void {
		this.loadCuisVigente();
	}

	onSubmit(): void {
		this.submitError = '';

		if (this.form.invalid) {
			this.form.markAllAsTouched();
			this.submitError = 'Por favor complete todos los campos requeridos.';
			return;
		}

		this.isLoading = true;
		const formData = this.form.getRawValue();

		const requestData = {
			codigo_ambiente: 2,
			codigo_sucursal: formData.sucursal,
			codigo_tipo_punto_venta: formData.tipo_punto_venta,
			nombre_punto_venta: formData.nombre_punto_venta,
			descripcion: formData.descripcion,
			id_usuario: 1,
			codigo_punto_venta: 0
		};

		this.puntoVentaService.createPuntoVenta(requestData).subscribe({
			next: (response: ApiResponse<any>) => {
				this.isLoading = false;
				if (response.success) {
					this.puntoVentaCreated.emit(response);
					this.resetForm();
					const modalElement = document.getElementById('addPuntoVentaModal');
					if (modalElement) {
						const modal = (window as any).bootstrap.Modal.getInstance(modalElement);
						if (modal) {
							modal.hide();
						}
					}
				} else {
					const errorMsg = response.message || 'Error al crear punto de venta';
					this.submitError = errorMsg;
					this.puntoVentaError.emit(errorMsg);
				}
			},
			error: (error: any) => {
				this.isLoading = false;
				const errorMsg = error.error?.message || 'Error al crear punto de venta';
				console.error('Error al crear punto de venta:', error);
				this.submitError = errorMsg;
				this.puntoVentaError.emit(errorMsg);
			}
		});
	}

	resetForm(): void {
		this.form.reset({
			sucursal: 0,
			tipo_punto_venta: '',
			cuis_creacion: '',
			nombre_punto_venta: '',
			descripcion: ''
		});
		this.submitError = '';
		this.loadCuisVigente();
	}

	closeModal(): void {
		this.resetForm();
	}
}
