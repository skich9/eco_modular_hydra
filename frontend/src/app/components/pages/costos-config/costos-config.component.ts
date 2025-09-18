import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, FormControl, FormGroup, FormsModule, ReactiveFormsModule, Validators } from '@angular/forms';
import { CobrosService } from '../../../services/cobros.service';
import { CarreraService } from '../../../services/carrera.service';

@Component({
	selector: 'app-costos-config',
	standalone: true,
	imports: [CommonModule, ReactiveFormsModule, FormsModule],
	templateUrl: './costos-config.component.html',
	styleUrls: ['./costos-config.component.scss']
})
export class CostosConfigComponent implements OnInit {
	form: FormGroup;
	carreras: any[] = [];
	pensums: any[] = [];
	gestiones: any[] = [];
	loading = false;
	private wiredKeys = new Set<string>();

	turnos = [
		{ key: 'MANANA', label: 'Mañana' },
		{ key: 'TARDE', label: 'Tarde' },
		{ key: 'NOCHE', label: 'Noche' }
	];

	costosCatalogo: Array<{ key: string; label: string; id: number }>= [];

	constructor(
		private fb: FormBuilder,
		private cobrosService: CobrosService,
		private carreraService: CarreraService
	) {
		this.form = this.fb.group({
			carrera: ['', Validators.required],
			pensum: ['', Validators.required],
			gestion: ['', Validators.required],
			// Costos semestrales por turno
			turno_MANANA_enabled: [false],
			turno_MANANA_monto: [{ value: '', disabled: true }],
			turno_TARDE_enabled: [false],
			turno_TARDE_monto: [{ value: '', disabled: true }],
			turno_NOCHE_enabled: [false],
			turno_NOCHE_monto: [{ value: '', disabled: true }],
			// Costos dinámicos (se cargan desde backend)
			costos: this.fb.group({}),
			// Semestres
			marcarTodosSemestres: [false],
			sem1: [false], sem2: [false], sem3: [false], sem4: [false], sem5: [false], sem6: [false]
		});

		// Los controles por costo se agregarán dinámicamente al cargar desde backend
	}

	ngOnInit(): void {
		// Cargar parámetros de costos activos primero
		this.loadParametrosCostosActivos();
		this.loadCarreras();
		this.loadGestiones();

		// Habilitar/deshabilitar monto por turno
		for (const t of this.turnos) {
			this.form.get(`turno_${t.key}_enabled`)?.valueChanges.subscribe((v: boolean) => {
				const ctrl = this.form.get(`turno_${t.key}_monto`);
				if (v) ctrl?.enable({ emitEvent: false }); else ctrl?.disable({ emitEvent: false });
			});
		}

		// Suscripciones por costo se conectan dinámicamente cuando se cargan los costos

		// Marcar todos los semestres
		this.form.get('marcarTodosSemestres')?.valueChanges.subscribe((v: boolean) => {
			['sem1','sem2','sem3','sem4','sem5','sem6'].forEach(k => this.form.get(k)?.setValue(!!v, { emitEvent: false }));
		});
	}

	loadCarreras(): void {
		this.loading = true;
		this.carreraService.getAll().subscribe({
			next: (res: any) => { this.carreras = res?.data || []; },
			error: () => {},
			complete: () => { this.loading = false; }
		});
	}

	onCarreraChange(): void {
		const codigo = this.form.get('carrera')?.value;
		this.form.patchValue({ pensum: '' });
		this.pensums = [];
		if (!codigo) return;
		this.cobrosService.getPensumsByCarrera(codigo).subscribe({
			next: (res) => { this.pensums = res?.data || []; },
			error: () => { this.pensums = []; }
		});
	}

	loadGestiones(): void {
		this.cobrosService.getGestionesActivas().subscribe({
			next: (res) => { this.gestiones = res?.data || []; },
			error: () => { this.gestiones = []; }
		});
	}

	private loadParametrosCostosActivos(): void {
		this.cobrosService.getParametrosCostosActivos().subscribe({
			next: (res) => {
				const data = res?.data || [];
				// Mapear a la estructura usada por el template (key, label)
				this.costosCatalogo = data.map((d: any) => ({
					id: d.id_parametro_costo,
					key: `pc_${d.id_parametro_costo}`,
					label: d.nombre_oficial || d.nombre_costo || `Costo ${d.id_parametro_costo}`,
				}));
				// Asegurar que existan controles y suscripciones para cada costo
				this.ensureCostControls();
			},
			error: () => { this.costosCatalogo = []; }
		});
	}

	private ensureCostControls(): void {
		const costosGroup = this.form.get('costos') as FormGroup;
		for (const c of this.costosCatalogo) {
			// Crear controles si no existen
			if (!costosGroup.contains(`${c.key}_enabled`)) costosGroup.addControl(`${c.key}_enabled`, new FormControl(false));
			if (!costosGroup.contains(`${c.key}_monto`)) costosGroup.addControl(`${c.key}_monto`, new FormControl({ value: '', disabled: true }));
			if (!costosGroup.contains(`${c.key}_turno`)) costosGroup.addControl(`${c.key}_turno`, new FormControl({ value: 'TODOS', disabled: true }));

			// Conectar suscripción una sola vez
			if (!this.wiredKeys.has(c.key)) {
				this.form.get(['costos', `${c.key}_enabled`])?.valueChanges.subscribe((v: boolean) => {
					const montoCtrl = this.form.get(['costos', `${c.key}_monto`]);
					const turnoCtrl = this.form.get(['costos', `${c.key}_turno`]);
					if (v) { montoCtrl?.enable({ emitEvent: false }); turnoCtrl?.enable({ emitEvent: false }); }
					else { montoCtrl?.disable({ emitEvent: false }); turnoCtrl?.disable({ emitEvent: false }); }
				});
				this.wiredKeys.add(c.key);
			}
		}
	}

	asignarCostos(): void {
		// Solo UI - sin lógica de persistencia aún
		console.log('Payload visual de costos:', this.form.getRawValue());
	}

	// Getter tipado para evitar AbstractControl | null en la plantilla
	get costosGroup(): FormGroup {
		return this.form.get('costos') as FormGroup;
	}
}
