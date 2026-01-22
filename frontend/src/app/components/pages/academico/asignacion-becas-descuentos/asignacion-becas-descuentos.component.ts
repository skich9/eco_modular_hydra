import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule, ReactiveFormsModule, FormBuilder, FormGroup, Validators } from '@angular/forms';
import { RouterModule } from '@angular/router';
import { CobrosService } from '../../../../services/cobros.service';
import { AuthService } from '../../../../services/auth.service';
import { forkJoin, of } from 'rxjs';

interface DefBeca {
	cod_beca: number;
	nombre_beca: string;
	descripcion?: string;
	monto?: number;
	porcentaje?: boolean;
	estado?: boolean;
}

interface DefDescuento {
	cod_descuento: number;
	nombre_descuento: string;
	descripcion?: string;
	monto?: number;
	porcentaje?: boolean;
	estado?: boolean;
}

interface AsignacionPreview {
	nro: number;
	tipo: 'BECA' | 'DESCUENTO';
	nombre: string;
	valor: string;
	inscripcion: string;
	estado: 'Activo' | 'Inactivo';
	cuotas: string;
	cuotasDetalle?: Array<{ numero_cuota: number; monto_descuento: number }>;
}

@Component({
	selector: 'app-asignacion-becas-descuentos',
	standalone: true,
	imports: [CommonModule, FormsModule, ReactiveFormsModule, RouterModule],
	templateUrl: './asignacion-becas-descuentos.component.html',
	styleUrls: ['./asignacion-becas-descuentos.component.scss']
})
export class AsignacionBecasDescuentosComponent implements OnInit {
	// Búsqueda de estudiante
	searchForm: FormGroup;
	student = {
		ap_paterno: '',
		ap_materno: '',
		nombres: '',
		pensum: '',
		gestion: '',
		grupos: [] as string[]
	};

	codInscrip: number | null = null;
	codInscripNormal: number | null = null;
	codInscripArrastre: number | null = null;
	codPensumSelected: string = '';
	gestionesDisponibles: string[] = [];
	noInscrito: boolean = false;
	gruposDetalle: Array<{ curso: string; tipo: string }> = [];

	// Totales y contexto de asignación (placeholder)
	contextForm: FormGroup;
	submitted = false;
	defSelectError: string | null = null;

	// Catálogo de definiciones
	selectedTipo: 'beca' | 'descuento' = 'beca';
	defSearch = '';
	defBecas: DefBeca[] = [];
	defDescuentos: DefDescuento[] = [];
	selectedRowKey: string | null = null;

	asignaciones: AsignacionPreview[] = [];
	gestionesCatalogo: string[] = [];
	carrerasCatalogo: Array<{ codigo_carrera: string; nombre: string }> = [];
	tipoInscripcionOptions: string[] = [];
	cuotas: Array<{ numero_cuota: number; monto: number; monto_bruto: number; estado_pago: string; observacion: string; selected: boolean; id_cuota?: number | null; monto_pagado?: number; descuento_existente?: number; monto_neto?: number; descuento_manual?: number }> = [];
	private cuotasNormal: Array<{ numero_cuota: number; monto: number; monto_bruto: number; estado_pago: string; observacion: string; selected: boolean; id_cuota?: number | null; monto_pagado?: number; descuento_existente?: number; monto_neto?: number; descuento_manual?: number }> = [];
	private cuotasArrastre: Array<{ numero_cuota: number; monto: number; monto_bruto: number; estado_pago: string; observacion: string; selected: boolean; id_cuota?: number | null; monto_pagado?: number; descuento_existente?: number; monto_neto?: number; descuento_manual?: number }> = [];
	allSelected: boolean = false;

	constructor(private fb: FormBuilder, private cobrosService: CobrosService,private auth:AuthService) {
		this.searchForm = this.fb.group({
			cod_ceta: [''],
			gestion: ['']
		});
		this.contextForm = this.fb.group({
			montoReferencia: [{ value: 0, disabled: true }],
			descuento: [{ value: 0, disabled: true }],
			totalPagar: [{ value: 0, disabled: true }],
			tipoInscripcion: [''],
			codigoArchivo: [''],
			fechaSolicitud: ['', Validators.required],
			meses: [''],
			observaciones: ['', Validators.required]
		});
	}

	private switchCuotasForTipo(tipo: string): void {
		const up = (tipo || '').toString().toUpperCase();
		const list = up === 'ARRASTRE' ? this.cuotasArrastre : this.cuotasNormal;
		this.cuotas = (list || []).map(c => ({ ...c }));
		this.allSelected = this.cuotas.filter(c => this.isSelectable(c)).every(c => !!c.selected);

		const selectedDef = this.getSelectedDef();
		if (selectedDef) {
			this.aplicarDescuentoAutomatico(selectedDef);
		}
	}

	onTipoInscripcionChange(tipo: string): void {
		this.switchCuotasForTipo(tipo);
		const up = (tipo || '').toString().toUpperCase();
		if (up === 'ARRASTRE') {
			this.codInscrip = this.codInscripArrastre ?? this.codInscripNormal ?? null;
		} else {
			this.codInscrip = this.codInscripNormal ?? null;
		}
		this.recalcMontoReferenciaFromCuotas();
	}

	ngOnInit(): void {
		this.loadCatalogo();
		this.loadCatalogosGlobales();
		const ctrl = this.contextForm.get('tipoInscripcion');
		ctrl?.valueChanges.subscribe((val) => {
			this.onTipoInscripcionChange(String(val || ''));
		});
	}

	private loadCatalogo(): void {
		this.cobrosService.getDefBecas().subscribe({
			next: (res) => { this.defBecas = Array.isArray(res?.data) ? res.data : []; },
			error: () => { this.defBecas = []; }
		});
		this.cobrosService.getDefDescuentos().subscribe({
			next: (res) => { this.defDescuentos = Array.isArray(res?.data) ? res.data : []; },
			error: () => { this.defDescuentos = []; }
		});
	}

	private loadCatalogosGlobales(): void {
		// Gestiones activas (orden descendente para mostrar las más recientes primero)
		this.cobrosService.getGestionesActivas().subscribe({
			next: (res) => {
				const rows = Array.isArray(res?.data) ? res.data : [];
				const sorted = [...rows].sort((a: any, b: any) => (Number(b?.orden || 0) - Number(a?.orden || 0)));
				this.gestionesCatalogo = sorted.map((r: any) => String(r?.gestion || '')).filter((g: string) => !!g);
			},
			error: () => { this.gestionesCatalogo = []; }
		});
		// Carreras (para futuros filtros/visualización)
		this.cobrosService.getCarreras().subscribe({
			next: (res) => {
				const rows = Array.isArray(res?.data) ? res.data : [];
				this.carrerasCatalogo = rows.map((r: any) => ({ codigo_carrera: String(r?.codigo_carrera || ''), nombre: String(r?.nombre || '') }))
					.filter((r: any) => !!r.codigo_carrera);
			},
			error: () => { this.carrerasCatalogo = []; }
		});
	}

	get definicionesFiltradas(): Array<DefBeca | DefDescuento> {
		const q = (this.defSearch || '').toLowerCase().trim();
		const list = this.selectedTipo === 'beca' ? this.defBecas : this.defDescuentos;
		return list.filter((d: any) => {
			const name = (d.nombre_beca || d.nombre_descuento || '').toLowerCase();
			return !q || name.includes(q);
		});
	}

	keyOf(row: any): string {
		return this.selectedTipo === 'beca' ? `B-${row.cod_beca}` : `D-${row.cod_descuento}`;
	}

	selectDef(row: any): void {
		this.toggleSelect(row);
	}

	toggleSelect(row: any): void {
		const key = this.keyOf(row);
		if (this.selectedRowKey === key) {
			this.selectedRowKey = null;
			this.limpiarDescuentosManuales();
			this.updateDescuentoTotal(null);
			return;
		}
		this.selectedRowKey = key;
		this.defSelectError = null;
		this.aplicarDescuentoAutomatico(row);
		this.updateDescuentoTotal(row);
	}

	updateDescuentoTotal(row: any | null): void {
		const base = this.consideredCuotas();
		const montoRef = base.reduce((acc, c) => acc + this.refMonto(c), 0);
		let descuentoVal = 0;
		if (row) {
			descuentoVal = base.reduce((acc, c) => acc + this.descuentoPorCuota(c, row), 0);
		}
		const total = Math.max(montoRef - descuentoVal, 0);
		this.contextForm.patchValue({
			montoReferencia: this.round2(montoRef),
			descuento: this.round2(descuentoVal),
			totalPagar: this.round2(total)
		});
	}

	getSelectedDef(): any | null {
		if (!this.selectedRowKey) return null;
		return this.definicionesFiltradas.find(r => this.keyOf(r as any) === this.selectedRowKey) as any || null;
	}

	descuentoPorCuota(c: { numero_cuota: number; monto: number; monto_bruto?: number; estado_pago: string; monto_pagado?: number; descuento_existente?: number; monto_neto?: number }, defOverride?: any): number {
		const def = defOverride || this.getSelectedDef();
		if (!def) return 0;
		const sel = this.consideredCuotas();
		if (!sel.find(x => x.numero_cuota === c.numero_cuota)) return 0;
		const baseRef = this.refMonto(c);
		if (baseRef <= 0) return 0;
		const isPorc = !!def?.porcentaje;
		const defMonto = Number((def?.monto ?? 0) as any) || 0;
		if (isPorc) {
			// Validación: porcentajes > 100 no se aplican
			if (defMonto > 100) return 0;
			return this.round2((baseRef * defMonto) / 100);
		}
		// Monto fijo: no distribuir ni sobrepasar el saldo de la cuota. Si supera el saldo, NO aplicar.
		if (defMonto <= 0) return 0;
		if (defMonto > baseRef) return 0;
		return this.round2(defMonto);
	}

	totalPorCuota(c: { numero_cuota: number; monto: number; monto_bruto?: number; estado_pago: string; monto_pagado?: number; descuento_existente?: number; monto_neto?: number }): number {
		const ref = this.refMonto(c);
		const d = this.descuentoPorCuota(c);
		const total = Math.max(ref - d, 0);
		return this.round2(total);
	}

	private selectedCount(): number {
		return this.cuotas.filter(c => this.isSelectable(c) && !!c.selected).length;
	}

	private consideredCuotas(): Array<{ numero_cuota: number; monto: number; monto_bruto: number; estado_pago: string; observacion: string; selected: boolean; id_cuota?: number | null; monto_pagado?: number; descuento_existente?: number; monto_neto?: number }>{
		const sel = this.cuotas.filter(c => !!c.selected);
		if (sel.length > 0) return sel;
		return this.cuotas.filter(c => ['PENDIENTE', 'PARCIAL'].includes(String(c.estado_pago || '').toUpperCase()));
	}

	// fila seleccionable si no está COBRADO
	isSelectable(c: { estado_pago: string }): boolean {
		return String(c?.estado_pago || '').toUpperCase() !== 'COBRADO';
	}

	toggleCuota(index: number, checked: boolean): void {
		if (index < 0 || index >= this.cuotas.length) return;
		if (!this.isSelectable(this.cuotas[index])) return;
		this.cuotas[index].selected = !!checked;
		const eligibles = this.cuotas.filter(c => this.isSelectable(c));
		this.allSelected = eligibles.length > 0 && eligibles.every(c => !!c.selected);
		this.recalcMontoReferenciaFromCuotas();
	}

	toggleAll(checked: boolean): void {
		this.allSelected = !!checked;
		this.cuotas = this.cuotas.map(c => this.isSelectable(c) ? ({ ...c, selected: this.allSelected }) : ({ ...c, selected: false }));
		this.recalcMontoReferenciaFromCuotas();
	}

	onChangeObservacion(index: number): void {
		if (index < 0 || index >= this.cuotas.length) return;
		const count = this.selectedCount();
		if (count < 2) return; // solo copiar cuando hay 2+ seleccionadas
		const text = this.cuotas[index].observacion || '';
		this.cuotas = this.cuotas.map((c) => c.selected ? { ...c, observacion: text } : c);
	}

	recalcMontoReferenciaFromCuotas(): void {
		let selectedRow: any | null = null;
		if (this.selectedRowKey) {
			selectedRow = this.definicionesFiltradas.find(r => this.keyOf(r as any) === this.selectedRowKey) as any || null;
		}
		this.updateDescuentoTotal(selectedRow);
	}

// Eliminado: selección por cuota / seleccionar todo

	private round2(n: number): number {
		return Math.round((n + Number.EPSILON) * 100) / 100;
	}

	buscarPorCodCeta(): void {
		const code = (this.searchForm.value?.cod_ceta || '').toString().trim();
		if (!code) return;
		const gesRaw = (this.searchForm.value?.gestion || '').toString().trim();
		const ges = this.gestionesCatalogo.includes(gesRaw) ? gesRaw : '';
		this.cobrosService.getResumen(code, ges || undefined).subscribe({
			next: (res: any) => {
				this.applyResumenData(res, ges);
			},
			error: (err) => {
				const msg = (err?.error?.message || '').toString().toLowerCase();
				// Si la gestión seleccionada no aplica para este estudiante, limpiamos y recuperamos sin gestión
				if (msg.includes('no tiene inscripción en la gestión solicitada')) {
					this.searchForm.patchValue({ gestion: '' });
					this.cobrosService.getResumen(code).subscribe({
						next: (res2) => { this.applyResumenData(res2, ''); },
						error: (err2) => {
							this.resetResumenState(err2);
						}
					});
					return;
				}
				this.resetResumenState(err);
			}
		});
	}

	private applyResumenData(res: any, gesInput: string): void {
		const est = res?.data?.estudiante || {};
		const gestion = res?.data?.gestion || '';
		const insc = res?.data?.inscripcion || null;
		const inscArr = res?.data?.arrastre?.inscripcion || null;
		const inscripciones = Array.isArray(res?.data?.inscripciones) ? res.data.inscripciones : [];
		const gestionesAll = Array.isArray(res?.data?.gestiones_all) ? (res.data.gestiones_all as string[]) : [];
		const gestiones = (gestionesAll.length ? gestionesAll : Array.from(new Set((inscripciones as any[]).map((i: any) => String(i?.gestion || '')).filter((g: string) => !!g)))) as string[];
		const selectedGestion = (gesInput || gestion) as string;
		const pensumNombre = (insc?.pensum?.nombre || est?.pensum?.nombre || '') as string;
		// Garantizar que el selector incluya la gestión por defecto (última inscripción) aunque no esté en el catálogo activo
		if (selectedGestion && !this.gestionesCatalogo.includes(selectedGestion)) {
			this.gestionesCatalogo = [selectedGestion, ...this.gestionesCatalogo];
		}
		const gruposDetalleRaw = (inscripciones as any[])
			.filter((i: any) => String(i?.gestion || '') === String(selectedGestion || ''))
			.map((i: any) => ({ curso: String(i?.cod_curso || ''), tipo: String(i?.tipo_inscripcion || '') }))
			.filter((g: any) => !!g.curso);
		const gruposMap = new Map<string, { curso: string; tipo: string }>();
		for (const g of gruposDetalleRaw) { if (!gruposMap.has(g.curso)) gruposMap.set(g.curso, g); }
		this.gruposDetalle = Array.from(gruposMap.values());
		const gruposUnique = this.gruposDetalle.map(g => g.curso);
		this.student = {
			ap_paterno: est?.ap_paterno || '',
			ap_materno: est?.ap_materno || '',
			nombres: est?.nombres || '',
			pensum: pensumNombre,
			gestion: gestion,
			grupos: gruposUnique
		};
		this.codInscripNormal = insc?.cod_inscrip ?? null;
		this.codInscripArrastre = inscArr?.cod_inscrip ?? null;
		this.codInscrip = this.codInscripNormal;
		this.codPensumSelected = (insc?.cod_pensum ?? est?.cod_pensum ?? '') as string;
		const asignacionesCuotas = Array.isArray(res?.data?.asignaciones) ? res.data.asignaciones : [];
		const asignacionesArrastre = Array.isArray(res?.data?.asignaciones_arrastre) ? res.data.asignaciones_arrastre : [];
		this.cuotasNormal = (asignacionesCuotas as any[]).map((a: any) => {
			const estado = String(a?.estado_pago || '').toUpperCase();
			const selected = estado === 'PENDIENTE' || estado === 'PARCIAL';
			const bruto = Number(a?.monto || 0) || 0;
			const desc = Number(a?.descuento || 0) || 0;
			const neto = (a?.monto_neto !== undefined && a?.monto_neto !== null)
				? (Number(a?.monto_neto) || 0)
				: Math.max(0, bruto - desc);
			const pag = Number(a?.monto_pagado || 0) || 0;
			const saldo = Math.max(0, neto - pag);
			return {
				numero_cuota: Number(a?.numero_cuota || 0) || 0,
				monto: saldo,
				monto_bruto: bruto,
				estado_pago: String(a?.estado_pago || ''),
				observacion: '',
				selected,
				obsLocked: false,
				id_cuota: (a?.id_cuota_template ? (Number(a.id_cuota_template) || null) : null),
				monto_pagado: pag,
				descuento_existente: desc,
				monto_neto: neto,
				descuento_manual: desc > 0 ? desc : 0,
			};
		});
		this.cuotasArrastre = (asignacionesArrastre as any[]).map((a: any) => {
			const estado = String(a?.estado_pago || '').toUpperCase();
			const selected = estado === 'PENDIENTE' || estado === 'PARCIAL';
			const bruto = Number(a?.monto || 0) || 0;
			const desc = Number(a?.descuento || 0) || 0;
			const neto = (a?.monto_neto !== undefined && a?.monto_neto !== null)
				? (Number(a?.monto_neto) || 0)
				: Math.max(0, bruto - desc);
			const pag = Number(a?.monto_pagado || 0) || 0;
			const saldo = Math.max(0, neto - pag);
			return {
				numero_cuota: Number(a?.numero_cuota || 0) || 0,
				monto: saldo,
				monto_bruto: bruto,
				estado_pago: String(a?.estado_pago || ''),
				observacion: '',
				selected,
				obsLocked: false,
				id_cuota: (a?.id_cuota_template ? (Number(a.id_cuota_template) || null) : null),
				monto_pagado: pag,
				descuento_existente: desc,
				monto_neto: neto,
				descuento_manual: desc > 0 ? desc : 0,
			};
		});
		const baseForInitial = this.consideredCuotas();
		const sumCuotas = baseForInitial.reduce((acc, c) => acc + this.refMonto(c), 0);
		const costoSem = Number(res?.data?.costo_semestral || 0) || 0;
		const paramMonto = Number(res?.data?.parametros_costos?.monto_fijo || 0) || 0;
		const paramCuotas = Number(res?.data?.parametros_costos?.nro_cuotas || 0) || 0;
		const fallbackCalc = costoSem || (paramMonto && paramCuotas ? (paramMonto * paramCuotas) : 0);
		const montoRef = (sumCuotas > 0 ? sumCuotas : (this.cuotas.length ? 0 : fallbackCalc)) || 0;
		const tipos = (inscripciones as any[])
			.filter((i: any) => String(i?.gestion || '') === String(selectedGestion || ''))
			.map((i: any) => String(i?.tipo_inscripcion || '').toUpperCase())
			.filter((t: string) => !!t);
		this.tipoInscripcionOptions = Array.from(new Set(tipos));
		const tipoDefault = (insc?.tipo_inscripcion || this.tipoInscripcionOptions[0] || '') as string;
		this.contextForm.patchValue({ montoReferencia: this.round2(montoRef), descuento: 0, totalPagar: this.round2(montoRef), tipoInscripcion: tipoDefault });
		this.switchCuotasForTipo(tipoDefault);
		if ((tipoDefault || '').toUpperCase() === 'ARRASTRE') {
			this.codInscrip = this.codInscripArrastre ?? this.codInscripNormal ?? null;
		} else {
			this.codInscrip = this.codInscripNormal ?? null;
		}
		let selectedRow: any | null = null;
		if (this.selectedRowKey) {
			selectedRow = this.definicionesFiltradas.find(r => this.keyOf(r as any) === this.selectedRowKey) as any || null;
		}
		this.updateDescuentoTotal(selectedRow);
		this.gestionesDisponibles = gestiones;
		if (!gesInput && gestion) {
			this.searchForm.patchValue({ gestion });
		}
		this.noInscrito = gestiones.length === 0;
		this.loadDescuentosAsignados();
	}

	refMonto(c: { monto: number; monto_bruto?: number; monto_pagado?: number; descuento_existente?: number; monto_neto?: number }): number {
		// Monto referencial = monto BRUTO (sin descuento aplicado)
		return Number(c.monto_bruto || c.monto || 0) || 0;
	}

	private resetResumenState(err?: any): void {
		this.student = { ap_paterno: '', ap_materno: '', nombres: '', pensum: '', gestion: '', grupos: [] };
		this.codInscrip = null;
		this.codInscripNormal = null;
		this.codInscripArrastre = null;
		this.codPensumSelected = '';
		this.gestionesDisponibles = [];
		const msg = (err?.error?.message || '').toString().toLowerCase();
		this.noInscrito = msg.includes('no posee inscrip') || msg.includes('no tiene inscripción');
		this.gruposDetalle = [];
		this.asignaciones = [];
	}

	onGestionChange(): void {
		this.buscarPorCodCeta();
	}

	private loadDescuentosAsignados(): void {
		const codCetaRaw = this.searchForm.get('cod_ceta')?.value;
		const codCeta = Number(codCetaRaw || 0);
		if (!codCeta) { this.asignaciones = []; return; }
		this.cobrosService.getDescuentos({ cod_ceta: codCeta, cod_pensum: this.codPensumSelected, estado: undefined as any }).subscribe({
			next: (res) => {
				const items = Array.isArray(res?.data) ? res.data : [];
				this.asignaciones = items.map((it: any, idx: number) => {
					// Usar tipo_descuento calculado por el backend
					const tipo = (it?.tipo_descuento || 'BECA') as 'BECA' | 'DESCUENTO';
					const cuotasDetalle = Array.isArray(it?.cuotas_detalle) ? it.cuotas_detalle : [];

					return {
						id: it?.id_descuentos,
						nro: idx + 1,
						tipo: tipo,
						nombre: it?.nombre || (it?.beca?.nombre_beca || it?.definicion?.nombre_descuento || ''),
						valor: '',
						cuotasDetalle: cuotasDetalle,
						inscripcion: String((it?.tipo ?? it?.tipo_inscripcion ?? '') || '').toUpperCase(),
						estado: (it?.estado ? 'Activo' : 'Inactivo') as 'Activo' | 'Inactivo',
						cuotas: String(it?.cuotas || '')
					};
				});
			},
			error: () => { this.asignaciones = []; }
		});
	}

	asignarSeleccion(): void {
		this.submitted = true;
		this.defSelectError = null;
		if (this.contextForm.get('observaciones')?.invalid) return;
		if (this.contextForm.get('fechaSolicitud')?.invalid) return;

		const sel = this.getSelectedDef();
		const tieneSeleccion = !!this.selectedRowKey && !!sel;

		let cod_beca = null;
		let nombre = 'Descuento manual';

		if (tieneSeleccion) {
			const isBeca = this.selectedTipo === 'beca';
			cod_beca = isBeca ? Number(sel.cod_beca) : Number(sel.cod_descuento);
			nombre = isBeca ? (sel.nombre_beca || '') : (sel.nombre_descuento || '');
		}

		// cuotas seleccionadas; si no hay, usar pendientes/parciales
		let cuotas = this.cuotas.filter(c => c.selected);
		if (cuotas.length === 0) cuotas = this.consideredCuotas();

		const obsGlobal = String(this.contextForm.get('observaciones')?.value || '');
		const cuotasPayload = cuotas
			.filter(c => this.isSelectable(c))
			.map(c => ({
				numero_cuota: Number(c.numero_cuota || 0),
				id_cuota: (c.id_cuota != null ? Number(c.id_cuota) : null),
				monto_descuento: tieneSeleccion ? Number(this.descuentoPorCuota(c) || 0) : Number(c.descuento_manual || 0),
				observaciones: obsGlobal
			}));

		const idUsuario = Number(this.auth?.getCurrentUser()?.id_usuario || 0);
		const payload: any = {
			cod_ceta: String(this.searchForm.get('cod_ceta')?.value || ''),
			cod_pensum: this.codPensumSelected,
			cod_inscrip: Number(this.codInscrip || 0),
			id_usuario: idUsuario,
			nombre,
			porcentaje: Number(this.contextForm.get('descuento')?.value || 0),
			observaciones: obsGlobal,
			codigoArchivo: String(this.contextForm.get('codigoArchivo')?.value || ''),
			fechaSolicitud: String(this.contextForm.get('fechaSolicitud')?.value || ''),
			meses: String(this.contextForm.get('meses')?.value || ''),
			tipo_inscripcion: String(this.contextForm.get('tipoInscripcion')?.value || ''),
			cuotas: cuotasPayload
		};

		if (cod_beca !== null) {
			payload.cod_beca = cod_beca;
		}

		this.cobrosService.assignDescuento(payload).subscribe({
			next: () => {
				this.limpiarFormulario();
				this.submitted = false;
			},
			error: (err) => {
				console.error('Error asignando descuento', err);
				const msg = err?.error?.message || 'Error al asignar descuento';
				this.defSelectError = msg;
				this.submitted = false;
			}
		});
	}

	cancelarSeleccion(): void {
		this.selectedRowKey = null;
		this.limpiarDescuentosManuales();
		this.updateDescuentoTotal(null);
		this.submitted = false;
		this.defSelectError = null;
	}

	limpiarFormulario(): void {
		this.searchForm.reset();
		this.student = { ap_paterno: '', ap_materno: '', nombres: '', pensum: '', gestion: '', grupos: [] };
		this.contextForm.reset({ montoReferencia: 0, descuento: 0, totalPagar: 0, tipoInscripcion: '', observaciones: '' });
		this.codInscrip = null;
		this.codPensumSelected = '';
		this.gestionesDisponibles = [];
		this.noInscrito = false;
		this.selectedRowKey = null;
		this.gruposDetalle = [];
		this.cuotas = [];
		this.asignaciones = [];
		this.allSelected = false;
		this.defSearch = '';
		this.tipoInscripcionOptions = [];
	}

	eliminarAsignacion(row: any): void {
		const id = Number(row?.id || 0);
		if (!id) return;
		const ok = window.confirm('¿Eliminar esta asignación y sus detalles?');
		if (!ok) return;
		this.cobrosService.deleteDescuento(id).subscribe({
			next: () => {
				this.loadDescuentosAsignados();
			},
			error: (err) => {
				console.error('Error eliminando descuento', err);
			}
		});
	}

	// Cantidad de arrastre: mostrar número de cuotas cuando el tipo de inscripción es ARRASTRE
	get arrastreCantidad(): number {
		if ((this.contextForm.get('tipoInscripcion')?.value || '').toString().toUpperCase() !== 'ARRASTRE') return 0;
		return Array.isArray(this.cuotas) ? this.cuotas.length : 0;
	}

	calcularSaldo(c: { monto_bruto: number; monto_pagado?: number; descuento_manual?: number; descuento_existente?: number }): number {
		const precioUnitario = Number(c.monto_bruto || 0);
		const pagado = Number(c.monto_pagado || 0);
		const descuentoManual = Number(c.descuento_manual || 0);
		const descuentoExistente = Number(c.descuento_existente || 0);
		const descuentoTotal = descuentoExistente > 0 ? descuentoExistente : descuentoManual;
		const saldo = Math.max(0, precioUnitario - pagado - descuentoTotal);
		return this.round2(saldo);
	}

	tieneDescuentoExistente(c: { descuento_existente?: number }): boolean {
		return Number(c.descuento_existente || 0) > 0;
	}

	onDescuentoChange(index: number, event: any): void {
		if (index < 0 || index >= this.cuotas.length) return;
		const cuota = this.cuotas[index];
		const value = Number(event.target.value || 0);
		const precioUnitario = Number(cuota.monto_bruto || 0);

		// Validar: no puede ser negativo, debe ser entero, y no puede exceder el precio unitario
		let intValue = Math.max(0, Math.floor(value));
		if (intValue > precioUnitario) {
			intValue = precioUnitario;
		}

		this.cuotas[index].descuento_manual = intValue;
		event.target.value = intValue;
		this.recalcMontoReferenciaFromCuotas();
	}

	limpiarDescuentosManuales(): void {
		this.cuotas = this.cuotas.map(c => ({ ...c, descuento_manual: 0 }));
	}

	aplicarDescuentoAutomatico(def: any): void {
		if (!def) return;
		const isPorc = !!def?.porcentaje;
		const defMonto = Number((def?.monto ?? 0) as any) || 0;

		this.cuotas = this.cuotas.map(c => {
			const precioUnitario = Number(c.monto_bruto || 0);
			if (precioUnitario <= 0) return { ...c, descuento_manual: 0 };

			let descuentoCalculado = 0;
			if (isPorc) {
				if (defMonto > 0 && defMonto <= 100) {
					descuentoCalculado = (precioUnitario * defMonto) / 100;
				}
			} else {
				if (defMonto > 0 && defMonto <= precioUnitario) {
					descuentoCalculado = defMonto;
				}
			}

			const descuentoEntero = Math.floor(descuentoCalculado);
			return { ...c, descuento_manual: descuentoEntero };
		});
	}
}
