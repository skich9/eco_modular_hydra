import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, FormControl, FormGroup, FormsModule, ReactiveFormsModule, Validators } from '@angular/forms';
import { CobrosService } from '../../../services/cobros.service';
import { AuthService } from '../../../services/auth.service';
import { CarreraService } from '../../../services/carrera.service';
import { parsePositiveInteger, sanitizeIntegerString } from '../../../utils/numeric-amount.util';

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

	// Cuotas
	cuotas: Array<{ id_parametro_cuota: number; nombre_cuota: string; fecha_vencimiento?: string; activo: boolean }> = [];
	cuotasGroup: FormGroup;
	// Modales de cuotas
	addCuotaOpen = false;
	addCuotaForm: FormGroup;
	editCuotasOpen = false;
	editCuotasLoading = false;
	editCuotasList: Array<{ id_parametro_cuota: number; nombre_cuota: string; fecha_vencimiento?: string; activo: boolean }> = [];
	editCuotasForm: FormGroup;

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

	/** Cuotas (mismo pensum, gestión, semestre; todos los tipos/turnos) en el modal «Editar costo semestral». */
	editModalCuotasLoading = false;
	editModalCuotas: Array<{
		id_cuota?: number;
		nombre: string;
		semestre: string;
		monto: string;
		fecha_vencimiento: string;
		tipo?: string | null;
		turno?: string | null;
	}> = [];

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

	// Lógica de restricción de cuotas se deriva de costos activos (no se usa selector visible)
	cuotasDisabled: boolean = false;
	private restrictiveKeys = new Set<string>(['instancia','reincorporacion','rezagado']);
	costosCatalogo: Array<{ key: string; label: string; id: number; nombre_costo?: string }>= [];

	/** Notificación de error (banner rojo); sin textos bajo cada campo. */
	notificacionCostosError: string | null = null;

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
			sem1: [false], sem2: [false], sem3: [false], sem4: [false], sem5: [false], sem6: [false],
			// Cuotas
			marcarTodasCuotas: [false],
		});

		// Los controles por costo se agregarán dinámicamente al cargar desde backend

		// Formulario del modal de edición (solo UI)
		this.editForm = this.fb.group({
			tipo_costo: [{ value: '', disabled: true }],
			monto_semestre: [''],
			semestre: [{ value: '', disabled: true }],
			turno: [{ value: 'MANANA', disabled: true }],
		});
		this.editForm.valueChanges.subscribe(() => {
			this.notificacionCostosError = null;
		});

		// Formulario del modal de creación (solo UI)
		this.createForm = this.fb.group({
			nombre_costo: ['', Validators.required],
			nombre_oficial: ['', Validators.required],
			descripcion: [''],
			activo: [true],
		});

		// Grupo de cuotas (se llena dinámicamente)
		this.cuotasGroup = this.fb.group({});

		// Formularios de cuotas
		this.addCuotaForm = this.fb.group({
			nombre_cuota: ['', Validators.required],
			fecha_vencimiento: ['', Validators.required],
			activo: [true]
		});
		this.editCuotasForm = this.fb.group({});
	}



	// Exclusión mutua: si hay algún costo NO restrictivo activo, se bloquean los 3 costos restrictivos
	private applyCostosMutualExclusion(): void {
		const costosGroup = this.form.get('costos') as FormGroup;
		const hasNonRestrictiveActive = this.costosCatalogo.some(c => {
			const nameKey = String(c.nombre_costo || '').trim().toLowerCase();
			if (this.restrictiveKeys.has(nameKey)) return false;
			return costosGroup.get(`${c.key}_enabled`)?.value === true;
		});
		for (const c of this.costosCatalogo) {
			const nameKey = String(c.nombre_costo || '').trim().toLowerCase();
			if (!this.restrictiveKeys.has(nameKey)) continue;
			const enCtrl = costosGroup.get(`${c.key}_enabled`);
			const moCtrl = costosGroup.get(`${c.key}_monto`);
			const tuCtrl = costosGroup.get(`${c.key}_turno`);
			if (hasNonRestrictiveActive) {
				if (enCtrl?.value === true) enCtrl.setValue(false, { emitEvent: false });
				enCtrl?.disable({ emitEvent: false });
				moCtrl?.disable({ emitEvent: false });
				tuCtrl?.disable({ emitEvent: false });
			} else {
				enCtrl?.enable({ emitEvent: false });
				// monto/turno quedan habilitados solo si el enabled está activo; el handler de enabled ya los controla
			}
		}
	}

	// --- Cuotas: Modales y flujos ---
	addCuota(): void {
		this.addCuotaForm.reset({ nombre_cuota: '', fecha_vencimiento: '', activo: true });
		this.addCuotaOpen = true;
	}

	closeAddCuota(): void { this.addCuotaOpen = false; }

	saveAddCuota(): void {
		if (this.addCuotaForm.invalid) {
			this.addCuotaForm.markAllAsTouched();
			return;
		}
		const v = this.addCuotaForm.value as any;
		const payload = {
			nombre_cuota: String(v.nombre_cuota || '').trim(),
			fecha_vencimiento: String(v.fecha_vencimiento || ''),
			activo: !!v.activo
		};
		if (!payload.nombre_cuota || !payload.fecha_vencimiento) return;
		this.cobrosService.createParametroCuota(payload).subscribe({
			next: (res) => {
				const item = res?.data;
				if (item) {
					// Si está activo, agregar a la lista visible
					if (item.activo) {
						this.cuotas = [...this.cuotas, item];
						this.ensureCuotaControl(Number(item.id_parametro_cuota));
					}
				}
				this.addCuotaOpen = false;
			},
			error: () => { alert('No se pudo crear la cuota.'); }
		});
	}

	editCuota(): void {
		this.editCuotasLoading = true;
		this.cobrosService.getParametrosCuotasAll().subscribe({
			next: (res) => {
				this.editCuotasList = (res?.data || []) as any[];
				// Construir controles para fechas
				const controls: Record<string, FormControl> = {};
				for (const c of this.editCuotasList) {
					controls[`fecha_${c.id_parametro_cuota}`] = new FormControl(this.toDateInput(c.fecha_vencimiento || ''));
					controls[`activo_${c.id_parametro_cuota}`] = new FormControl(!!c.activo);
				}
				this.editCuotasForm = this.fb.group(controls);
				this.editCuotasOpen = true;
			},
			error: () => { this.editCuotasList = []; },
			complete: () => { this.editCuotasLoading = false; }
		});
	}

	closeEditCuotas(): void { this.editCuotasOpen = false; this.editCuotasList = []; this.editCuotasForm.reset({}); }

	saveEditCuotas(): void {
		if (!this.editCuotasList || this.editCuotasList.length === 0) { this.editCuotasOpen = false; return; }
		this.editCuotasLoading = true;
		let pending = this.editCuotasList.length;
		let failed = 0;
		for (const c of this.editCuotasList) {
			const ctrlKey = `fecha_${c.id_parametro_cuota}`;
			const newDate = String(this.editCuotasForm.get(ctrlKey)?.value || '').trim();
			const oldDate = this.toDateInput(c.fecha_vencimiento || '');
			const activoKey = `activo_${c.id_parametro_cuota}`;
			const newActivo = this.editCuotasForm.get(activoKey)?.value === true;
			const oldActivo = !!c.activo;

			const payload: any = {};
			if (newDate && newDate !== oldDate) payload.fecha_vencimiento = newDate;
			if (newActivo !== oldActivo) payload.activo = newActivo;
			if (Object.keys(payload).length === 0) { pending--; if (pending===0) this.finishEditBatch(failed); continue; }

			this.cobrosService.updateParametroCuota(Number(c.id_parametro_cuota), payload).subscribe({
				next: () => {},
				error: () => { failed++; },
				complete: () => {
					pending--;
					if (pending === 0) this.finishEditBatch(failed);
				}
			});
		}
	}

	private finishEditBatch(failed: number): void {
		this.editCuotasLoading = false;
		this.editCuotasOpen = false;
		if (failed > 0) alert(`Algunas cuotas no se pudieron actualizar (${failed}).`);
		else alert('Cuotas actualizadas correctamente.');
		// Refrescar lista visible de cuotas activas (fechas)
		this.loadCuotasActivas();
	}

	private toDateInput(val: string): string {
		if (!val) return '';
		// Normalizar formatos 'YYYY-MM-DD', 'YYYY-MM-DD HH:mm:ss', ISO
		const s = String(val);
		if (s.includes('T')) return s.substring(0, 10);
		if (s.includes(' ')) return s.substring(0, 10);
		return s;
	}

	// Normaliza tipo de costo visible (label) a la clave usada en cuotas
	private normalizeTipoKeyFromLabel(label: string): string | undefined {
		const s = (label || '').trim().toLowerCase();
		if (s.includes('mensual')) return 'costo_mensual';
		if (s.includes('arrastre') || s === 'materia') return 'materia';
		return undefined;
	}

	ngOnInit(): void {
		// Cargar parámetros de costos activos primero
		this.loadParametrosCostosActivos();
		this.loadCarreras();
		this.loadGestiones();
		this.loadCuotasActivas();

		// Habilitar/deshabilitar monto por turno
		for (const t of this.turnos) {
			this.form.get(`turno_${t.key}_enabled`)?.valueChanges.subscribe((v: boolean) => {
				const ctrl = this.form.get(`turno_${t.key}_monto`);
				if (v) ctrl?.enable({ emitEvent: false }); else ctrl?.disable({ emitEvent: false });
			});
		}

		// Suscripciones por costo se conectan dinámicamente cuando se cargan los costos
		// Además, escuchar cualquier cambio del grupo 'costos' para aplicar restricción siempre
		this.form.get('costos')?.valueChanges.subscribe(() => {
			this.applyCuotasRestrictionIfNeeded();
			this.applyCostosMutualExclusion();
		});

		this.form.valueChanges.subscribe(() => {
			this.notificacionCostosError = null;
		});

		this.cuotasGroup.valueChanges.subscribe(() => {
			this.notificacionCostosError = null;
		});

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

		// Marcar todas las cuotas
		this.form.get('marcarTodasCuotas')?.valueChanges.subscribe((v: boolean) => {
			for (const c of this.cuotas) {
				const key = `cuota_${c.id_parametro_cuota}`;
				this.cuotasGroup.get(key)?.setValue(!!v, { emitEvent: false });
			}
		});

		// No hay selector de lógica; la restricción se aplica dinámicamente al activar costos (ver ensureCostControls)
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
			next: (res) => {
				const data: any[] = res?.data || [];
				// Ordenar gestiones de la más reciente a la más antigua
				// Formato esperado: "SEMESTRE/AÑO" ej: "1/2025", "2/2025"
				this.gestiones = data.sort((a, b) => {
					const parsarGestion = (g: any): { anio: number; semestre: number } => {
						const texto = String(g?.gestion || '');
						const partes = texto.split('/');
						const semestre = parseInt(partes[0], 10) || 0;
						const anio = parseInt(partes[1], 10) || 0;
						return { anio, semestre };
					};
					const gestionA = parsarGestion(a);
					const gestionB = parsarGestion(b);
					// Primero comparar por año descendente, luego por semestre descendente
					if (gestionB.anio !== gestionA.anio) {
						return gestionB.anio - gestionA.anio;
					}
					return gestionB.semestre - gestionA.semestre;
				});
			},
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

	private ensureCuotaControl(id: number): void {
		const key = `cuota_${id}`;
		if (!this.cuotasGroup.contains(key)) {
			this.cuotasGroup.addControl(key, new FormControl(false));
		}
	}

	private loadCuotasActivas(): void {
		this.cobrosService.getParametrosCuotasActivas().subscribe({
			next: (res) => {
				this.cuotas = res?.data || [];
				for (const c of this.cuotas) this.ensureCuotaControl(Number(c.id_parametro_cuota));
				// Aplicar restricción inmediatamente si hay costos restrictivos activos
				this.applyCuotasRestrictionIfNeeded();
			},
			error: () => { this.cuotas = []; }
		});
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
		this.editModalCuotas = [];
		this.editModalCuotasLoading = false;
		const rawM = row?.monto_semestre;
		let montoStr = '';
		if (rawM !== null && rawM !== undefined && `${rawM}`.trim() !== '') {
			const t = Math.trunc(Number(rawM));
			montoStr = Number.isFinite(t) && t > 0 ? String(t) : '';
		}
		this.editForm.setValue({
			tipo_costo: row?.tipo_costo || '',
			monto_semestre: montoStr,
			semestre: row?.semestre || '',
			turno: row?.turno || 'MANANA',
		});
		this.editOpen = true;
		this.loadEditModalCuotas(row);
	}

	/** Todas las filas `cuotas` del mismo pensum, gestión y semestre (sin filtrar por tipo ni turno). */
	private loadEditModalCuotas(row: any): void {
		const cp = String(row?.cod_pensum || '').trim();
		const gs = String(row?.gestion || '').trim();
		const sem = row?.semestre;
		if (!cp || !gs) {
			this.editModalCuotas = [];
			return;
		}
		this.editModalCuotasLoading = true;
		this.cobrosService
			.getCuotas({
				gestion: gs,
				cod_pensum: cp,
				semestre: sem,
			})
			.subscribe({
				next: (res) => {
					const list = (res?.data || []) as any[];
					const mapped = list.map((c: any) => ({
						id_cuota: c.id_cuota,
						nombre: String(c.nombre || ''),
						semestre: String(c.semestre ?? sem ?? ''),
						monto:
							c.monto != null && `${c.monto}`.trim() !== ''
								? String(Math.trunc(Number(c.monto)))
								: '',
						fecha_vencimiento: this.toDateInput(c.fecha_vencimiento || ''),
						tipo: c.tipo != null && `${c.tipo}`.trim() !== '' ? String(c.tipo) : null,
						turno: c.turno != null && `${c.turno}`.trim() !== '' ? String(c.turno) : null,
					}));
					mapped.sort((a, b) => {
						const n = a.nombre.localeCompare(b.nombre);
						if (n !== 0) return n;
						const t = String(a.tipo || '').localeCompare(String(b.tipo || ''));
						if (t !== 0) return t;
						return String(a.turno || '').localeCompare(String(b.turno || ''));
					});
					this.editModalCuotas = mapped;
				},
				error: () => {
					this.editModalCuotas = [];
				},
				complete: () => {
					this.editModalCuotasLoading = false;
				},
			});
	}

	closeEdit(): void {
		this.editOpen = false;
		this.editingRow = null;
		this.editModalCuotas = [];
		this.editModalCuotasLoading = false;
	}

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
				const gs = this.deletingRow?.gestion as string | undefined;
				const sem = this.deletingRow?.semestre;
				const turnoKey = String(this.deletingRow?.turno || '').trim();
				const tipoKey = this.normalizeTipoKeyFromLabel(String(this.deletingRow?.tipo_costo || ''));
				if (cp && this.costoSemestralMap[cp]) {
					this.costoSemestralMap[cp] = (this.costoSemestralMap[cp] || []).filter(r => r.id_costo_semestral !== id);
				}
				this.closeDelete();
				alert('Registro eliminado correctamente.');
				// Encadenar eliminación de cuotas por contexto
				try {
					if (cp && gs && sem && (tipoKey === 'costo_mensual' || tipoKey === 'materia')) {
						this.cobrosService.deleteCuotasByContext({
							cod_pensum: cp,
							gestion: gs,
							semestre: sem,
							tipo: tipoKey,
							turno: turnoKey || undefined,
						}).subscribe({ next: () => {}, error: () => {} });
					}
				} catch {}
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
		const montoOk = parsePositiveInteger(v.monto_semestre);
		if (montoOk === null) {
			this.notificacionCostosError = 'Solo se permiten valores enteros mayores a cero.';
			this.editForm.get('monto_semestre')?.markAsTouched();
			return;
		}
		const tipoKey = this.normalizeTipoKeyFromLabel(String(row?.tipo_costo || ''));
		const cp = row?.cod_pensum as string | undefined;
		const gs = row?.gestion as string | undefined;
		const turnoKey = String(row?.turno || '').trim();

		if (this.editModalCuotas.length > 0) {
			for (const cq of this.editModalCuotas) {
				const mq = parsePositiveInteger(cq.monto);
				if (mq === null) {
					this.notificacionCostosError = 'En cuotas, cada monto debe ser un entero mayor a cero.';
					return;
				}
				const fv = String(cq.fecha_vencimiento || '').trim();
				if (!fv) {
					this.notificacionCostosError = 'En cuotas, indique la fecha de vencimiento en cada fila.';
					return;
				}
			}
		}

		const payload = { monto_semestre: montoOk };
		this.cobrosService.updateCostoSemestral(Number(id), payload).subscribe({
			next: () => {
				const refreshMap = () => {
					if (cp) {
						this.cobrosService.getCostoSemestralByPensum(cp, gs).subscribe({
							next: (res) => { this.costoSemestralMap[cp] = res?.data || []; },
							error: () => { /* mantener datos previos si falla */ },
						});
					}
				};

				const finishOk = () => {
					refreshMap();
					this.closeEdit();
					alert('Registro actualizado correctamente.');
				};

				const syncCuotas = () => {
					if (!cp || !gs) {
						finishOk();
						return;
					}
					if (this.editModalCuotas.length > 0) {
						const cuotasPayload = this.editModalCuotas.map((cq) => ({
							nombre: cq.nombre,
							descripcion: '',
							semestre: String(cq.semestre || row.semestre),
							monto: parsePositiveInteger(cq.monto) as number,
							fecha_vencimiento: String(cq.fecha_vencimiento || '').substring(0, 10),
							tipo: cq.tipo || undefined,
							turno: cq.turno || undefined,
						}));
						this.cobrosService.createCuotasBatch({ cod_pensum: cp, gestion: gs, cuotas: cuotasPayload }).subscribe({
							next: () => finishOk(),
							error: (e) => {
								console.warn('Error al guardar cuotas desde el modal de edición', e);
								this.notificacionCostosError = 'El costo semestral se guardó, pero no se pudieron actualizar las cuotas.';
								refreshMap();
							},
						});
						return;
					}
					if (
						tipoKey &&
						(tipoKey === 'costo_mensual' || tipoKey === 'materia') &&
						row?.semestre != null
					) {
						this.cobrosService
							.updateCuotasByContext({
								cod_pensum: cp,
								gestion: gs,
								semestre: row.semestre,
								monto: montoOk,
								tipo: tipoKey,
								turno: turnoKey || undefined,
							})
							.subscribe({
								next: () => finishOk(),
								error: (e) => {
									console.warn('Actualización de cuotas por contexto falló', e);
									this.notificacionCostosError =
										'El costo semestral se guardó, pero no se pudieron sincronizar las cuotas (mismo monto).';
									refreshMap();
								},
							});
						return;
					}
					finishOk();
				};

				syncCuotas();
			},
			error: (err) => {
				console.error('Error al actualizar costo semestral', err);
				this.notificacionCostosError = 'No se pudo actualizar el costo.';
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
					// Aplicar restricciones dinámicas
					this.applyCuotasRestrictionIfNeeded();
					this.applyCostosMutualExclusion();
				});
				this.wiredKeys.add(c.key);
			}
		}

		// Aplicar estado inicial de restricciones
		this.applyCuotasRestrictionIfNeeded();
		this.applyCostosMutualExclusion();
	}

	private isRestrictiveLogicActive(): boolean {
		const costosGroup = this.form.get('costos') as FormGroup;
		for (const c of this.costosCatalogo) {
			const enabled = costosGroup.get(`${c.key}_enabled`)?.value === true;
			if (!enabled) continue;
			const key = String(c.nombre_costo || '').trim().toLowerCase();
			// Chequeo por clave exacta del catálogo
			if (key === 'instancia' || key === 'reincorporacion' || key === 'rezagado') {
				return true;
			}
			// Fallback por texto visible por si cambian claves pero el label mantiene semántica
			const name = String(c.label || '').trim().toLowerCase();
			if (name.includes('recupera') || name.includes('reincorp') || name.includes('rezagad')) {
				return true;
			}
		}
		return false;
	}

	private applyCuotasRestrictionIfNeeded(): void {
		const restricted = this.isRestrictiveLogicActive();
		this.cuotasDisabled = restricted;
		// Marcar/desmarcar y deshabilitar/rehabilitar controles según restricción
		const allCtrl = this.form.get('marcarTodasCuotas');
		if (restricted) {
			this.cuotasGroup.disable({ emitEvent: false });
			allCtrl?.setValue(false, { emitEvent: false });
			allCtrl?.disable({ emitEvent: false });
			for (const c of this.cuotas) {
				const key = `cuota_${c.id_parametro_cuota}`;
				const ctrl = this.cuotasGroup.get(key);
				ctrl?.setValue(false, { emitEvent: false });
				ctrl?.disable({ emitEvent: false });
			}
		} else {
			this.cuotasGroup.enable({ emitEvent: false });
			allCtrl?.enable({ emitEvent: false });
			for (const c of this.cuotas) {
				const key = `cuota_${c.id_parametro_cuota}`;
				const ctrl = this.cuotasGroup.get(key);
				ctrl?.enable({ emitEvent: false });
			}
		}
	}

	/** Estado del formulario de asignación (montos numéricos > 0 en filas activas). */
	private computeAsignarCostosState(): { ok: true; rows: Array<{ semestre: number; tipo_costo: string; monto_semestre: number; turno: string }> } | { ok: false; message: string } {
		const cod_pensum: string = (this.form.get('pensum')?.value || this.activePensumForTable || '') as string;
		const gestion: string = (this.form.get('gestion')?.value || '') as string;
		if (!cod_pensum?.trim() || !gestion?.trim()) {
			return { ok: false, message: 'Seleccione gestión y pensum antes de guardar.' };
		}

		const semestres: number[] = [];
		(['sem1', 'sem2', 'sem3', 'sem4', 'sem5', 'sem6'] as const).forEach((k, idx) => {
			if (this.form.get(k)?.value) semestres.push(idx + 1);
		});
		if (semestres.length === 0) {
			return { ok: false, message: 'Marque al menos un semestre.' };
		}

		const costosGroup = this.form.get('costos') as FormGroup;
		const rows: Array<{ semestre: number; tipo_costo: string; monto_semestre: number; turno: string }> = [];

		for (const c of this.costosCatalogo) {
			const enabled = costosGroup.get(`${c.key}_enabled`)?.value === true;
			if (!enabled) continue;

			const rawMonto = costosGroup.get(`${c.key}_monto`)?.value;
			const monto = parsePositiveInteger(rawMonto);
			if (monto === null) {
				return {
					ok: false,
					message: 'Los costos activos requieren valores enteros mayores a cero.',
				};
			}

			const turnoSel = String(costosGroup.get(`${c.key}_turno`)?.value || 'TODOS');
			const turnosToUse = turnoSel === 'TODOS' ? this.turnos.map(t => t.key) : [turnoSel];
			for (const s of semestres) {
				for (const tKey of turnosToUse) {
					rows.push({
						semestre: s,
						tipo_costo: (c.nombre_costo || '').toString(),
						monto_semestre: monto,
						turno: tKey,
					});
				}
			}
		}

		if (rows.length === 0) {
			return { ok: false, message: 'Active al menos un costo con valor entero mayor a cero.' };
		}

		// Con costos no restrictivos el usuario debe elegir al menos una cuota (las cuotas están habilitadas).
		if (!this.cuotasDisabled) {
			const algunaCuota = this.cuotas.some(
				(c) => this.cuotasGroup.get(`cuota_${c.id_parametro_cuota}`)?.value === true
			);
			if (!algunaCuota) {
				return { ok: false, message: 'Marque al menos una cuota.' };
			}
		}

		return { ok: true, rows };
	}

	get puedeAsignarCostos(): boolean {
		return this.computeAsignarCostosState().ok;
	}

	onCostoMontoInput(catalogKey: string, event: Event): void {
		const el = event.target as HTMLInputElement;
		const cleaned = sanitizeIntegerString(el.value);
		if (cleaned !== el.value) {
			this.costosGroup.get(`${catalogKey}_monto`)?.setValue(cleaned, { emitEvent: true });
		}
	}

	asignarCostos(): void {
		const state = this.computeAsignarCostosState();
		if (!state.ok) {
			this.notificacionCostosError = state.message;
			return;
		}
		this.notificacionCostosError = null;
		const { rows } = state;
		const cod_pensum: string = (this.form.get('pensum')?.value || this.activePensumForTable || '') as string;
		const gestion: string = (this.form.get('gestion')?.value || '') as string;

		const semestres: number[] = [];
		(['sem1', 'sem2', 'sem3', 'sem4', 'sem5', 'sem6'] as const).forEach((k, idx) => {
			if (this.form.get(k)?.value) semestres.push(idx + 1);
		});

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
				// Si corresponde, crear cuotas automáticamente
				try {
					// Detectar costos habilitados relevantes (mensual y/o arrastre) y construir lista
					const costosGroup = this.form.get('costos') as FormGroup;
					const selectedCosts: Array<{ tipo: string; monto: number; turno: string; }>= [];
					for (const c of this.costosCatalogo) {
						const enabled = costosGroup.get(`${c.key}_enabled`)?.value === true;
						if (!enabled) continue;
						// Normalizar tipo a claves del catálogo
						const nameKey = (c.nombre_costo || '').toString().toLowerCase();
						if (nameKey !== 'costo_mensual' && nameKey !== 'materia') continue;
						const monto = parsePositiveInteger(costosGroup.get(`${c.key}_monto`)?.value);
						if (monto === null) continue;
						const turnoSel = String(costosGroup.get(`${c.key}_turno`)?.value || 'TODOS');
						selectedCosts.push({ tipo: nameKey, monto, turno: turnoSel });
					}

					const cuotasDisabled = this.cuotasDisabled;
					if (selectedCosts.length > 0 && !cuotasDisabled) {
						// recoger cuotas seleccionadas
						const seleccionadas = this.cuotas.filter(c => this.cuotasGroup.get(`cuota_${c.id_parametro_cuota}`)?.value === true);
						if (seleccionadas.length > 0) {
							const cuotasPayload: Array<{ nombre: string; descripcion?: string | null; semestre: string; monto: number; fecha_vencimiento: string; tipo?: string; turno?: string; }> = [];
							for (const cost of selectedCosts) {
								const turnosToUse = (cost.turno === 'TODOS') ? this.turnos.map(t => t.key) : [cost.turno];
								for (const s of semestres) {
									for (const q of seleccionadas) {
										const fv = this.toDateInput(q.fecha_vencimiento || '');
										if (!fv) continue;
										for (const tu of turnosToUse) {
											cuotasPayload.push({
												nombre: q.nombre_cuota,
												descripcion: '',
												semestre: String(s),
												monto: cost.monto,
												fecha_vencimiento: fv,
												tipo: cost.tipo,
												turno: tu,
											});
										}
									}
								}
							}
							if (cuotasPayload.length > 0) {
								this.cobrosService.createCuotasBatch({ cod_pensum, gestion, cuotas: cuotasPayload }).subscribe({
									next: (r) => { console.log('Cuotas batch creado/actualizado:', r?.data); },
									error: (e) => { console.error('Error al crear cuotas en lote', e); },
									complete: () => {
										// Limpiar selecciones de cuotas
										this.form.get('marcarTodasCuotas')?.setValue(false, { emitEvent: false });
										for (const c of this.cuotas) {
											const key = `cuota_${c.id_parametro_cuota}`;
											this.cuotasGroup.get(key)?.setValue(false, { emitEvent: false });
										}
									}
								});
							}
						}
					}
				} catch (e) {
					console.warn('No se pudo procesar creación de cuotas automáticas:', e);
				}

				this.notificacionCostosError = null;
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
				// Limpiar selecciones de cuotas independientemente
				this.form.get('marcarTodasCuotas')?.setValue(false, { emitEvent: false });
				for (const c of this.cuotas) {
					const key = `cuota_${c.id_parametro_cuota}`;
					this.cuotasGroup.get(key)?.setValue(false, { emitEvent: false });
				}
			},
			error: (err) => {
				console.error('Error al guardar costo_semestral', err);
				this.notificacionCostosError = 'No se pudieron guardar los costos. Revise los datos o intente de nuevo.';
			}
		});
	}

	// Getter tipado para evitar AbstractControl | null en la plantilla
	get costosGroup(): FormGroup {
		return this.form.get('costos') as FormGroup;
	}

	// Getter para el form group de cuotas en la plantilla
	get cuotasForm(): FormGroup {
		return this.cuotasGroup;
	}
}
