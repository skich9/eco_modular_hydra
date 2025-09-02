import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormArray, FormBuilder, FormGroup, FormsModule, ReactiveFormsModule, Validators } from '@angular/forms';
import { CobrosService } from '../../../services/cobros.service';

@Component({
	selector: 'app-cobros-page',
	standalone: true,
	imports: [CommonModule, ReactiveFormsModule, FormsModule],
	templateUrl: './cobros.component.html',
	styleUrls: ['./cobros.component.scss']
})
export class CobrosComponent implements OnInit {
	// Estado UI
	loading = false;
	alertMessage = '';
	alertType: 'success' | 'error' | 'warning' = 'success';

	// Formularios
	searchForm: FormGroup;
	batchForm: FormGroup;

	// Datos
	resumen: any = null;

	constructor(
		private fb: FormBuilder,
		private cobrosService: CobrosService
	) {
		this.searchForm = this.fb.group({
			cod_ceta: ['', Validators.required],
			gestion: ['']
		});

		this.batchForm = this.fb.group({
			cabecera: this.fb.group({
				cod_ceta: ['', Validators.required],
				cod_pensum: ['', Validators.required],
				tipo_inscripcion: ['', Validators.required],
				gestion: [''],
				id_forma_cobro: [''],
				id_cuentas_bancarias: [''],
				id_usuario: ['', Validators.required]
			}),
			pagos: this.fb.array([])
		});
	}

	ngOnInit(): void {}

	// Helpers
	get pagos(): FormArray {
		return this.batchForm.get('pagos') as FormArray;
	}

	addPago(): void {
		this.pagos.push(this.fb.group({
			nro_cobro: ['', Validators.required],
			id_cuota: [null],
			id_item: [null],
			monto: [null, [Validators.required, Validators.min(0)]],
			fecha_cobro: ['', Validators.required],
			observaciones: ['']
		}));
	}

	removePago(i: number): void {
		this.pagos.removeAt(i);
	}

	// Acciones
	loadResumen(): void {
		if (!this.searchForm.valid) return;
		this.loading = true;
		const { cod_ceta, gestion } = this.searchForm.value;
		this.cobrosService.getResumen(cod_ceta, gestion).subscribe({
			next: (res) => {
				if (res.success) {
					this.resumen = res.data;
					this.showAlert('Resumen cargado', 'success');
				} else {
					this.resumen = null;
					this.showAlert(res.message || 'No se pudo obtener el resumen', 'warning');
				}
				this.loading = false;
			},
			error: (err) => {
				console.error('Resumen error:', err);
				this.resumen = null;
				this.showAlert('Error al obtener resumen', 'error');
				this.loading = false;
			}
		});
	}

	openKardexModal(): void {
		const modalEl = document.getElementById('kardexModal');
		if (modalEl && (window as any).bootstrap?.Modal) {
			const modal = new (window as any).bootstrap.Modal(modalEl);
			modal.show();
		}
	}

	submitBatch(): void {
		if (!this.batchForm.valid || this.pagos.length === 0) {
			this.showAlert('Complete los datos y agregue al menos un pago', 'warning');
			return;
		}
		this.loading = true;
		const { cabecera, pagos } = this.batchForm.value;
		const payload = { ...cabecera, pagos };
		this.cobrosService.batchStore(payload).subscribe({
			next: (res) => {
				if (res.success) {
					this.showAlert('Cobros registrados', 'success');
					this.batchForm.reset({ cabecera: {}, pagos: [] });
					(this.batchForm.get('pagos') as FormArray).clear();
				} else {
					this.showAlert(res.message || 'No se pudo registrar', 'warning');
				}
				this.loading = false;
			},
			error: (err) => {
				console.error('Batch error:', err);
				const msg = err?.error?.message || 'Error al registrar cobros';
				this.showAlert(msg, 'error');
				this.loading = false;
			}
		});
	}

	private showAlert(message: string, type: 'success' | 'error' | 'warning'): void {
		this.alertMessage = message;
		this.alertType = type;
		setTimeout(() => (this.alertMessage = ''), 4000);
	}
}
