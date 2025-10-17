import { Component, EventEmitter, Input, OnInit, Output } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators, FormsModule } from '@angular/forms';
import { debounceTime, distinctUntilChanged } from 'rxjs/operators';

@Component({
	selector: 'app-busqueda-estudiante-modal',
	standalone: true,
	imports: [CommonModule, ReactiveFormsModule, FormsModule],
	templateUrl: './busqueda-estudiante-modal.component.html',
	styleUrls: ['./busqueda-estudiante-modal.component.scss']
})
export class BusquedaEstudianteModalComponent implements OnInit {
	@Input() loading = false;
	@Input() results: Array<any> = [];
	@Input() meta: { page: number; per_page: number; total: number; last_page: number } | null = null;
	@Input() page = 1;
	@Input() perPage = 20;
	@Output() buscar = new EventEmitter<{ ap_paterno?: string; ap_materno?: string; nombres?: string; ci?: string }>();
	@Output() seleccionar = new EventEmitter<any>();
	@Output() pageChange = new EventEmitter<number>();
	@Output() perPageChange = new EventEmitter<number>();

	form: FormGroup;

	constructor(private fb: FormBuilder) {
		this.form = this.fb.group({
			ap_paterno: [''],
			ap_materno: [''],
			nombres: [''],
			ci: ['']
		});
	}

	ngOnInit(): void {
		// Escuchar cambios en los campos de texto (solo letras) y disparar búsqueda automática con debounce
		const apPatCtrl = this.form.get('ap_paterno');
		const apMatCtrl = this.form.get('ap_materno');
		const nomCtrl = this.form.get('nombres');
		const ciCtrl = this.form.get('ci');
		[apPatCtrl, apMatCtrl, nomCtrl].forEach(ctrl => {
			ctrl?.valueChanges?.pipe(debounceTime(300), distinctUntilChanged()).subscribe((val: any) => {
				// Normalizar casing: Primera letra mayúscula, resto minúscula
				const current = String(val ?? '');
				const cased = this.normalizeCase(current);
				if (cased !== current) ctrl.patchValue(cased, { emitEvent: false });

				const criteria = this.normalizedCriteria();
				// Buscar si hay al menos una letra en alguno de los 3 campos (ya con casing normalizado)
				if (this.hasLetters(criteria.ap_paterno) || this.hasLetters(criteria.ap_materno) || this.hasLetters(criteria.nombres)) {
					this.buscar.emit(criteria);
				}
			});
		});
	}

	open(): void {
		const el = document.getElementById('buscarEstudianteModal');
		const bs = (window as any).bootstrap;
		if (el && bs?.Modal) new bs.Modal(el).show();
	}

	close(): void {
		const el = document.getElementById('buscarEstudianteModal');
		const bs = (window as any).bootstrap;
		if (el && bs?.Modal) {
			const instance = bs.Modal.getInstance(el) || new bs.Modal(el);
			instance.hide();
		}
	}

	onBuscar(): void {
		const payload = this.normalizedCriteria() as any;
		// Normalizar vacíos a undefined para no enviar basura
		Object.keys(payload).forEach(k => { if (payload[k] !== 0 && !payload[k]) delete payload[k]; });
		this.buscar.emit(payload);
	}

	selectRow(row: any): void {
		this.seleccionar.emit(row);
	}

	// Helpers
	private normalizedCriteria(): { ap_paterno?: string; ap_materno?: string; nombres?: string; ci?: string } {
		const v = this.form.value || {} as any;
		const norm = (s: any) => (String(s ?? '')).trim();
		return {
			ap_paterno: this.normalizeCase(norm(v.ap_paterno)),
			ap_materno: this.normalizeCase(norm(v.ap_materno)),
			nombres: this.normalizeCase(norm(v.nombres)),
			ci: norm(v.ci)
		};
	}

	hasLetters(val?: string): boolean {
		if (!val) return false;
		return /[A-Za-zÁÉÍÓÚÜÑáéíóúüñ]/.test(val);
	}

	onKeypressLettersOnly(event: KeyboardEvent): void {
		const char = event.key;
		const allowed = /[A-Za-zÁÉÍÓÚÜÑáéíóúüñ\s]/;
		if (!allowed.test(char)) {
			event.preventDefault();
		}
	}

	private normalizeCase(s: string): string {
		s = (s || '').trim();
		if (!s) return s;
		const lower = s.toLowerCase();
		return lower.charAt(0).toUpperCase() + lower.slice(1);
	}

	// Pagination handlers
	onPrevPage(): void { if ((this.meta?.page || this.page) > 1) this.pageChange.emit((this.meta?.page || this.page) - 1); }
	onNextPage(): void { const lp = this.meta?.last_page || 1; const p = this.meta?.page || this.page; if (p < lp) this.pageChange.emit(p + 1); }
	setPage(p: number): void { if (p >= 1) this.pageChange.emit(p); }
	setPerPage(n: number): void { this.perPageChange.emit(n); }
}
