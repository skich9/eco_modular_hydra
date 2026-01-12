import { Component, EventEmitter, Input, OnInit, Output } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, FormGroup, ReactiveFormsModule, FormsModule } from '@angular/forms';

@Component({
	selector: 'app-descuento-form-modal',
	standalone: true,
	imports: [CommonModule, ReactiveFormsModule, FormsModule],
	templateUrl: './descuento-form-modal.component.html',
	styleUrls: []
})
export class DescuentoFormModalComponent implements OnInit {
	@Input() turnoOptions: string[] = ['Mañana', 'Tarde', 'Noche'];
	@Input() resumen: any = null;
	@Output() guardar = new EventEmitter<{ cod_ceta: string; nombre: string; gestion: string; pensum: string; turno: string; observaciones?: string }>();

	form: FormGroup;
	hasEstudiante = false;
	hasPendingWithDiscount = false;
	cuotasConDescuento: number[] = [];

	constructor(private fb: FormBuilder) {
		this.form = this.fb.group({
			cod_ceta: [''],
			nombre: [''],
			gestion: [''],
			pensum: [''],
			turno: [''],
			observaciones: ['']
		});
	}

	ngOnInit(): void {}

	open(defaults?: Partial<{ cod_ceta: string; nombre: string; gestion: string; pensum: string; turno: string; observaciones: string }>): void {
		if (defaults) this.form.patchValue(defaults, { emitEvent: false });
		const cod = String(defaults?.cod_ceta ?? '').trim();
		this.hasEstudiante = !!cod;
		if (this.hasEstudiante) {
			this.form.enable({ emitEvent: false });
			this.form.get('cod_ceta')?.disable({ emitEvent: false });
			this.form.get('nombre')?.disable({ emitEvent: false });
			this.form.get('gestion')?.disable({ emitEvent: false });
			this.form.get('pensum')?.disable({ emitEvent: false });
			this.form.get('turno')?.disable({ emitEvent: false });

			try {
				const asigs: any[] = Array.isArray(this.resumen?.asignaciones) ? this.resumen!.asignaciones : [];
				const pendientes = (asigs || []).filter((a: any) => {
					const st = (a?.estado_pago || '').toString().trim().toUpperCase();
					const num = Number(a?.numero_cuota || 0);
					return num >= 1 && st !== 'COBRADO';
				});
				const conDesc = pendientes.filter((a: any) => Number(a?.descuento || 0) > 0);
				this.hasPendingWithDiscount = conDesc.length > 0;
				this.cuotasConDescuento = conDesc.map((a: any) => Number(a?.numero_cuota || 0)).filter((n: number) => isFinite(n) && n > 0);
			} catch {}
		} else {
			this.form.disable({ emitEvent: false });
		}
		try { console.log('[DescuentoModal] open()', { defaults, hasEstudiante: this.hasEstudiante, raw: this.form.getRawValue() }); } catch {}
		const el = document.getElementById('descuentoFormModal');
		const bs = (window as any).bootstrap;
		if (el && bs?.Modal) new bs.Modal(el).show();
	}

	close(): void {
		const el = document.getElementById('descuentoFormModal');
		const bs = (window as any).bootstrap;
		if (el && bs?.Modal) {
			const instance = bs.Modal.getInstance(el) || new bs.Modal(el);
			instance.hide();
		}
	}

	onGuardar(): void {
		if (!this.hasEstudiante) { this.close(); return; }
		try { console.log('[DescuentoModal] onGuardar()', this.form.getRawValue()); } catch {}
		this.guardar.emit(this.form.getRawValue());
		this.close();
	}
}
