import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, FormControl, FormGroup, FormsModule, ReactiveFormsModule, Validators } from '@angular/forms';
import { CobrosService } from '../../../services/cobros.service';
import { AuthService } from '../../../services/auth.service';
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

	// Tabs y datos de costo_semestral
	activePensumTab: string | null = null; // legado (no usado en nueva UI)
	costoSemestralMap: Record<string, any[]> = {};

	// Nueva UI: tabs por carrera y botones por pensum
	activeCarreraTab: string | null = null;
	pensumsByCarreraMap: Record<string, any[]> = {};
	activePensumForTable: string | null = null;

	// Busqueda y orden para la tabla
	searchQuery: string = '';
	semSortAsc: boolean = true;

	// Modal de edición (UI-only)
	editOpen = false;
	editForm: FormGroup;
	editingRow: any = null;

	// Modal de creación de nuevo costo (UI-only)
	createOpen = false;
	createForm: FormGroup;

	// Modal de confirmación de eliminación
	deleteOpen = false;
	deleting = false;
	deletingRow: any = null;

	// Modal de gestión/edición de parámetros de costos (lista completa)
	manageOpen = false;
	manageLoading = false;
	manageList: Array<{ id_parametro_costo: number; nombre_costo: string; nombre_oficial: string; descripcion?: string | null; activo: boolean }>= [];
	private manageOriginal: Record<number, { nombre_costo: string; nombre_oficial: string; descripcion?: string | null; activo: boolean }> = {};

	turnos = [
		{ key: 'MANANA', label: 'Mañana' },
		{ key: 'TARDE', label: 'Tarde' },
		{ key: 'NOCHE', label: 'Noche' }
	];

	costosCatalogo: Array<{ key: string; label: string; id: number; nombre_costo?: string }>= [];

	constructor(
		private fb: FormBuilder,
		private cobrosService: CobrosService,
		private carreraService: CarreraService,
		private auth: AuthService
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

		// Formulario del modal de edición (solo UI)
		this.editForm = this.fb.group({
			tipo_costo: [{ value: '', disabled: true }],
			monto_semestre: [''],
			semestre: [{ value: '', disabled: true }],
			turno: [{ value: 'MANANA', disabled: true }],
		});

		// Formulario del modal de creación (solo UI)
		this.createForm = this.fb.group({
			nombre_costo: ['', Validators.required],
			nombre_oficial: ['', Validators.required],
			descripcion: [''],
			activo: [true],
		});
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


		// Al cambiar gestión, recargar la tabla del pensum activo (nueva UI)
		this.form.get('gestion')?.valueChanges.subscribe(() => {
			if (this.activePensumForTable) {
				this.loadCostoSemestral(this.activePensumForTable);
			}
		});


		// Si cambia el pensum desde el selector superior, actualizar SOLO la tabla
		this.form.get('pensum')?.valueChanges.subscribe((cod: string) => {
			// Desacoplado: el select de pensum no afecta la tabla
			// Este select se usa solo para el formulario de asignación de costos
		});

		// Marcar todos los semestres
		this.form.get('marcarTodosSemestres')?.valueChanges.subscribe((v: boolean) => {
			['sem1','sem2','sem3','sem4','sem5','sem6'].forEach(k => this.form.get(k)?.setValue(!!v, { emitEvent: false }));
		});
	}

	loadCarreras(): void {
		this.loading = true;
		this.carreraService.getAll().subscribe({
			next: (res: any) => {
				this.carreras = res?.data || [];
				// Inicializar pestaña de carrera por defecto
				const firstCarrera = this.carreras[0]?.codigo_carrera;
				if (!this.activeCarreraTab && firstCarrera) {
					this.selectCarreraTab(firstCarrera);
				}
			},
			error: () => {},
			complete: () => { this.loading = false; }
		});
	}

	onCarreraChange(): void {
		const codigo = this.form.get('carrera')?.value;
		this.form.patchValue({ pensum: '' });
		this.pensums = [];
		if (!codigo) return;
		// Cargar pensums SOLO para el selector (sin afectar la tabla)
		this.loadPensumsForSelect(codigo);
	}

	private normalizePensum(p: any): { cod_pensum: string; nombre?: string; descripcion?: string } {
		const cod = p?.cod_pensum || p?.codigo_pensum || p?.codigo || p?.cod || p?.id || p?.pensum;
		return {
			cod_pensum: cod,
			nombre: p?.nombre || p?.nombre_pensum || p?.titulo || p?.descripcion,
			descripcion: p?.descripcion || p?.detalle || p?.observacion
		};
	}

	loadGestiones(): void {
		this.cobrosService.getGestionesActivas().subscribe({
			next: (res) => { this.gestiones = res?.data || []; },
			error: () => { this.gestiones = []; }
		});
	}

	selectPensumTab(codPensum: string): void {
		// legado (usado por UI previa)
		this.activePensumTab = codPensum;
		if (!this.costoSemestralMap[codPensum]) this.loadCostoSemestral(codPensum);
	}

	// Nueva UI: seleccionar pestaña de carrera (solo tabla)
	selectCarreraTab(codigoCarrera: string): void {
		this.loadPensumsForTable(codigoCarrera, true);
	}

	// Nueva UI: seleccionar pensum (botón) y actualizar tabla
	selectPensumForTable(codPensum: string): void {
		// No sincronizar con el select superior: sólo actualizar la tabla
		this.updateTableForPensum(codPensum);
	}

	private loadCostoSemestral(codPensum: string): void {
		const gestion = this.form.get('gestion')?.value || undefined;
		this.cobrosService.getCostoSemestralByPensum(codPensum, gestion).subscribe({
			next: (res) => { this.costoSemestralMap[codPensum] = res?.data || []; },
			error: () => { this.costoSemestralMap[codPensum] = []; }
		});
	}

	// --- Selección (solo para el select superior) ---
	private loadPensumsForSelect(codigoCarrera: string): void {
		this.cobrosService.getPensumsByCarrera(codigoCarrera).subscribe({
			next: (res) => {
				const raw = (res?.data || []) as any[];
				this.pensums = raw.map(p => this.normalizePensum(p)).filter(p => !!p?.cod_pensum);
				// No tocar tabla ni estado de pestañas
			},
			error: () => { this.pensums = []; }
		});
	}

	// --- Tabla (independiente del select superior) ---
	private loadPensumsForTable(codigoCarrera: string, autoSelectFirst: boolean = true): void {
		this.activeCarreraTab = codigoCarrera;
		this.activePensumForTable = null;
		const assignAndMaybeSelect = () => {
			const list = this.pensumsByCarreraMap[codigoCarrera] || [];
			const first = list[0]?.cod_pensum;
			if (autoSelectFirst && first) {
				this.activePensumForTable = first;
				this.loadCostoSemestral(first);
			}
		};

		if (!this.pensumsByCarreraMap[codigoCarrera]) {
			this.cobrosService.getPensumsByCarrera(codigoCarrera).subscribe({
				next: (res) => {
					const raw = (res?.data || []) as any[];
					this.pensumsByCarreraMap[codigoCarrera] = raw.map(p => this.normalizePensum(p)).filter(p => !!p?.cod_pensum);
					assignAndMaybeSelect();
				},
				error: () => {
					this.pensumsByCarreraMap[codigoCarrera] = [];
					this.activePensumForTable = null;
				}
			});
		} else {
			assignAndMaybeSelect();
		}
	}

	private updateTableForPensum(codPensum: string): void {
		this.activePensumForTable = codPensum;
		// Reset de búsqueda al cambiar de pensum para claridad
		this.searchQuery = '';
		this.loadCostoSemestral(codPensum);
	}

	// Etiquetas amigables para la tabla
	semLabel(val: number | string): string {
		const n = Number(val);
		switch (n) {
			case 1: return '1er Semestre';
			case 2: return '2do Semestre';
			case 3: return '3er Semestre';
			case 4: return '4to Semestre';
			case 5: return '5to Semestre';
			case 6: return '6to Semestre';
			default: return String(val ?? '');
		}
	}

	displayTurno(t: string): string {
		const map: Record<string, string> = { MANANA: 'Mañana', TARDE: 'Tarde', NOCHE: 'Noche' };
		return map[t] || t;
	}

	// Datos derivados para la tabla (filtrado + orden)
	getRows(ap: string): any[] {
		const base = this.costoSemestralMap[ap] || [];
		let rows = base as any[];
		const q = (this.searchQuery || '').toString().trim().toLowerCase();
		if (q) {
			rows = rows.filter(r => {
				const costo = (r?.tipo_costo || '').toString().toLowerCase();
				const turno = this.displayTurno(r?.turno || '').toLowerCase();
				const sem = this.semLabel(r?.semestre || '').toLowerCase();
				return costo.includes(q) || turno.includes(q) || sem.includes(q);
			});
		}
		return [...rows].sort((a, b) => {
			const sa = Number(a?.semestre) || 0;
			const sb = Number(b?.semestre) || 0;
			return this.semSortAsc ? sa - sb : sb - sa;
		});
	}

	getBaseCount(ap: string): number {
		return (this.costoSemestralMap[ap] || []).length;
	}

	toggleSemSort(): void {
		this.semSortAsc = !this.semSortAsc;
	}

	openEdit(row: any): void {
		this.editingRow = row;
		this.editForm.setValue({
			tipo_costo: row?.tipo_costo || '',
			monto_semestre: row?.monto_semestre ?? '',
			semestre: row?.semestre || '',
			turno: row?.turno || 'MANANA',
		});
		this.editOpen = true;
	}

	closeEdit(): void { this.editOpen = false; this.editingRow = null; }

	// --- Creación de nuevo costo (UI-only) ---
	openCreate(): void {
		this.createForm.reset({ nombre_costo: '', nombre_oficial: '', descripcion: '', activo: true });
		this.createOpen = true;
	}

	closeCreate(): void { this.createOpen = false; }


	saveCreate(): void {
		if (this.createForm.invalid) {
			this.createForm.markAllAsTouched();
			return;
		}
		const val = this.createForm.value as any;
		const nombre_costo = String(val.nombre_costo || '').trim();
		const nombre_oficial = String(val.nombre_oficial || '').trim();
		const descripcion = (val.descripcion ?? '').toString();
		const activo = !!val.activo;
		if (!nombre_costo || !nombre_oficial) return;

		// Evitar duplicado por nombre_costo (case-insensitive) en el catálogo actual
		if (this.costosCatalogo.some(c => (c.nombre_costo || '').toLowerCase() === nombre_costo.toLowerCase())) {
			alert('Ya existe un costo con ese nombre.');
			return;
		}

		// Guardar en backend
		this.cobrosService.createParametroCosto({ nombre_costo, nombre_oficial, descripcion, activo }).subscribe({
			next: (res) => {
				const item = res?.data || null;
				if (item && item.activo) {
					const mapped = { id: item.id_parametro_costo, key: `pc_${item.id_parametro_costo}`, label: item.nombre_oficial || item.nombre_costo, nombre_costo: item.nombre_costo };
					this.costosCatalogo = [...this.costosCatalogo, mapped];
				}
				// Asegurar controles
				this.ensureCostControls();
				this.createOpen = false;
			},
			error: () => { alert('No se pudo crear el costo.'); }
		});
	}

	deleteRow(row: any): void {
		this.deletingRow = row;
		this.deleteOpen = true;
	}

	closeDelete(): void { this.deleteOpen = false; this.deletingRow = null; this.deleting = false; }

	confirmDelete(): void {
		if (!this.deletingRow) { return; }
		const id = this.deletingRow?.id_costo_semestral;
		if (!id) { alert('No se encontró el identificador del registro.'); return; }
		this.deleting = true;
		this.cobrosService.deleteCostoSemestral(Number(id)).subscribe({
			next: () => {
				const cp = this.deletingRow?.cod_pensum as string | undefined;
				if (cp && this.costoSemestralMap[cp]) {
					this.costoSemestralMap[cp] = (this.costoSemestralMap[cp] || []).filter(r => r.id_costo_semestral !== id);
				}
				this.closeDelete();
				alert('Registro eliminado correctamente.');
			},
			error: (err) => {
				console.error('Error al eliminar costo semestral', err);
				this.deleting = false;
				alert('No se pudo eliminar el registro.');
			}
		});
	}

	private loadParametrosCostosActivos(): void {
		this.cobrosService.getParametrosCostosActivos().subscribe({
			next: (res) => {
				const data = res?.data || [];
				// Mapear a la estructura usada por el template (key, label, nombre_costo)
				this.costosCatalogo = data.map((d: any) => ({
					id: d.id_parametro_costo,
					key: `pc_${d.id_parametro_costo}`,
					label: d.nombre_oficial || d.nombre_costo || `Costo ${d.id_parametro_costo}`,
					nombre_costo: d.nombre_costo,
				}));
				// Asegurar que existan controles y suscripciones para cada costo
				this.ensureCostControls();
			},
			error: () => { this.costosCatalogo = []; }
		});
	}

	// --- Gestión masiva de parámetros de costos ---
	openManage(): void {
		this.manageLoading = true;
		this.cobrosService.getParametrosCostosAll().subscribe({
			next: (res) => {
				this.manageList = (res?.data || []).map((r: any) => ({
					id_parametro_costo: r.id_parametro_costo,
					nombre_costo: r.nombre_costo || '',
					nombre_oficial: r.nombre_oficial || '',
					descripcion: r.descripcion ?? '',
					activo: !!r.activo,
				}));
				this.manageOriginal = {};
				for (const it of this.manageList) {
					this.manageOriginal[it.id_parametro_costo] = {
						nombre_costo: it.nombre_costo,
						nombre_oficial: it.nombre_oficial,
						descripcion: it.descripcion ?? '',
						activo: !!it.activo,
					};
				}
				this.manageOpen = true;
			},
			error: () => { this.manageList = []; },
			complete: () => { this.manageLoading = false; }
		});
	}

	closeManage(): void { this.manageOpen = false; }

	private isRowChanged(row: { id_parametro_costo: number; nombre_costo: string; nombre_oficial: string; descripcion?: string | null; activo: boolean }): boolean {
		const o = this.manageOriginal[row.id_parametro_costo];
		if (!o) return true;
		return (
			(o.nombre_costo || '') !== (row.nombre_costo || '') ||
			(o.nombre_oficial || '') !== (row.nombre_oficial || '') ||
			(o.descripcion || '') !== (row.descripcion || '') ||
			Boolean(o.activo) !== Boolean(row.activo)
		);
	}

	saveManage(): void {
		if (!this.manageList || this.manageList.length === 0) { this.manageOpen = false; return; }
		const changed = this.manageList.filter(r => this.isRowChanged(r));
		if (changed.length === 0) { this.manageOpen = false; return; }
		this.manageLoading = true;
		let pending = changed.length;
		let failed = 0;
		for (const r of changed) {
			const payload = {
				nombre_costo: r.nombre_costo,
				nombre_oficial: r.nombre_oficial,
				descripcion: r.descripcion ?? '',
				activo: !!r.activo,
			};
			this.cobrosService.updateParametroCosto(r.id_parametro_costo, payload).subscribe({
				next: () => {},
				error: () => { failed++; },
				complete: () => {
					pending--;
					if (pending === 0) {
						this.manageLoading = false;
						if (failed > 0) alert(`Algunos registros no se pudieron guardar (${failed}).`);
						else alert('Parámetros de costos actualizados.');
						this.manageOpen = false;
						this.loadParametrosCostosActivos();
					}
				}
			});
		}
	}

	saveEdit(): void {
		if (this.editForm.invalid) {
			this.editForm.markAllAsTouched();
			return;
		}
		const row = this.editingRow;
		const id = row?.id_costo_semestral;
		if (!id) { alert('No se encontró el identificador del registro.'); return; }
		const v = this.editForm.getRawValue();
		const payload = { monto_semestre: Number(v.monto_semestre) };
		this.cobrosService.updateCostoSemestral(Number(id), payload).subscribe({
			next: () => {
				this.editOpen = false;
				// Refrescar sólo el pensum afectado, usando la gestión original del row
				const cp = row?.cod_pensum as string | undefined;
				const gs = row?.gestion as string | undefined;
				if (cp) {
					this.cobrosService.getCostoSemestralByPensum(cp, gs).subscribe({
						next: (res) => { this.costoSemestralMap[cp] = res?.data || []; },
						error: () => { /* mantener datos previos si falla */ },
					});
				}
				this.editingRow = null;
				alert('Registro actualizado correctamente.');
			},
			error: (err) => {
				console.error('Error al actualizar costo semestral', err);
				alert('Error al actualizar costo semestral.');
			}
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
		const cod_pensum: string = this.form.get('pensum')?.value || this.activePensumForTable as string;
		const gestion: string = this.form.get('gestion')?.value;
		if (!cod_pensum || !gestion) {
			alert('Seleccione Gestión y Pensum antes de guardar.');
			return;
		}

		// Semestres marcados
		const semestres: number[] = [];
		(['sem1','sem2','sem3','sem4','sem5','sem6'] as const).forEach((k, idx) => {
			if (this.form.get(k)?.value) semestres.push(idx + 1);
		});
		if (semestres.length === 0) {
			alert('Marque al menos un semestre.');
			return;
		}

		// Construir filas por cada costo habilitado y semestre marcado
		const costosGroup = this.form.get('costos') as FormGroup;
		const rows: Array<{ semestre: number; tipo_costo: string; monto_semestre: number; turno: string }> = [];
		for (const c of this.costosCatalogo) {
			const enabled = costosGroup.get(`${c.key}_enabled`)?.value === true;
			if (!enabled) continue;
			const monto = parseFloat(String(costosGroup.get(`${c.key}_monto`)?.value || 0));
			const turnoSel = String(costosGroup.get(`${c.key}_turno`)?.value || 'TODOS');
			const turnosToUse = (turnoSel === 'TODOS') ? this.turnos.map(t => t.key) : [turnoSel];
			for (const s of semestres) {
				for (const tKey of turnosToUse) {
					rows.push({ semestre: s, tipo_costo: c.label, monto_semestre: isNaN(monto) ? 0 : monto, turno: tKey });
				}
			}
		}
		if (rows.length === 0) {
			alert('Active al menos un costo y establezca su valor.');
			return;
		}

		const currentUser = this.auth.getCurrentUser();
		const id_usuario = currentUser?.id_usuario;
		const payload = {
			cod_pensum,
			gestion,
			costo_fijo: 1,
			valor_credito: 0,
			id_usuario,
			rows
		};

		this.cobrosService.saveCostoSemestralBatch(payload).subscribe({
			next: (res) => {
				console.log('Guardado costo_semestral:', res?.data);
				alert('Costos semestrales guardados correctamente.');
				// Refrescar tabla del pensum activo (nueva UI) o fallback legado
				const ap = this.activePensumForTable || this.activePensumTab;
				if (ap) this.loadCostoSemestral(ap);
				// Limpiar selecciones de Semestres y Costos
				(['sem1','sem2','sem3','sem4','sem5','sem6','marcarTodosSemestres'] as const).forEach(k => this.form.get(k)?.setValue(false, { emitEvent: false }));
				for (const c of this.costosCatalogo) {
					const enCtrl = this.form.get(['costos', `${c.key}_enabled`]);
					const moCtrl = this.form.get(['costos', `${c.key}_monto`]);
					const tuCtrl = this.form.get(['costos', `${c.key}_turno`]);
					enCtrl?.setValue(false, { emitEvent: true }); // disparará disable de monto/turno por suscripción
					moCtrl?.setValue('', { emitEvent: false });
					tuCtrl?.setValue('TODOS', { emitEvent: false });
				}
			},
			error: (err) => {
				console.error('Error al guardar costo_semestral', err);
				alert('Error al guardar costo semestral. Revise consola.');
			}
		});
	}

	// Getter tipado para evitar AbstractControl | null en la plantilla
	get costosGroup(): FormGroup {
		return this.form.get('costos') as FormGroup;
	}
}
