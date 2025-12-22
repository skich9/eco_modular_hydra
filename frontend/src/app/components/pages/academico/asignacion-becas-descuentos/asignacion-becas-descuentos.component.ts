import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule, ReactiveFormsModule, FormBuilder, FormGroup } from '@angular/forms';
import { RouterModule } from '@angular/router';
import { CobrosService } from '../../../../services/cobros.service';

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
	estado: 'Activo' | 'Inactivo';
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
	codPensumSelected: string = '';
	gestionesDisponibles: string[] = [];
	noInscrito: boolean = false;
	gruposDetalle: Array<{ curso: string; tipo: string }> = [];

	// Totales y contexto de asignación (placeholder)
	contextForm: FormGroup;

	// Catálogo de definiciones
	selectedTipo: 'beca' | 'descuento' = 'beca';
	defSearch = '';
	defBecas: DefBeca[] = [];
	defDescuentos: DefDescuento[] = [];
	selectedRowKey: string | null = null;

	asignaciones: AsignacionPreview[] = [];
	gestionesCatalogo: string[] = [];
	carrerasCatalogo: Array<{ codigo_carrera: string; nombre: string }> = [];
	tipoInscripcionOptions: string[] = ['NORMAL', 'RECUPERACION', 'REINCORPORACION'];

	constructor(private fb: FormBuilder, private cobrosService: CobrosService) {
		this.searchForm = this.fb.group({
			cod_ceta: [''],
			gestion: ['']
		});
		this.contextForm = this.fb.group({
			montoReferencia: [{ value: 0, disabled: true }],
			descuento: [{ value: 0, disabled: true }],
			totalPagar: [{ value: 0, disabled: true }],
			tipoInscripcion: [''],
			observaciones: ['']
		});
	}

	ngOnInit(): void {
		this.loadCatalogo();
		this.loadCatalogosGlobales();
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
		this.selectedRowKey = this.keyOf(row);
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
		this.codInscrip = insc?.cod_inscrip ?? null;
		this.codPensumSelected = (insc?.cod_pensum ?? est?.cod_pensum ?? '') as string;
		this.contextForm.patchValue({ montoReferencia: 0, descuento: 0, totalPagar: 0, tipoInscripcion: insc?.tipo_inscripcion || '' });
		this.gestionesDisponibles = gestiones;
		if (!gesInput && gestion) {
			this.searchForm.patchValue({ gestion });
		}
		this.noInscrito = gestiones.length === 0;
		this.loadDescuentosAsignados();
	}

	private resetResumenState(err?: any): void {
		this.student = { ap_paterno: '', ap_materno: '', nombres: '', pensum: '', gestion: '', grupos: [] };
		this.codInscrip = null;
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
		if (!this.codInscrip) { this.asignaciones = []; return; }
		this.cobrosService.getDescuentos({ cod_inscrip: this.codInscrip }).subscribe({
			next: (res) => {
				const items = Array.isArray(res?.data) ? res.data : [];
				this.asignaciones = items.map((it: any, idx: number) => ({
					nro: idx + 1,
					tipo: (it?.cod_beca ? 'BECA' : 'DESCUENTO') as 'BECA' | 'DESCUENTO',
					nombre: it?.nombre || (it?.beca?.nombre_beca || it?.definicion?.nombre_descuento || ''),
					valor: `${it?.porcentaje ?? 0}%`,
					estado: (it?.estado ? 'Activo' : 'Inactivo') as 'Activo' | 'Inactivo'
				}));
			},
			error: () => { this.asignaciones = []; }
		});
	}

	asignarSeleccion(): void {
		if (!this.selectedRowKey) return;
		const sel = this.definicionesFiltradas.find(r => this.keyOf(r as any) === this.selectedRowKey) as any;
		if (!sel) return;
		const isBeca = this.selectedTipo === 'beca';
		const nombre = isBeca ? sel.nombre_beca : sel.nombre_descuento;
		const valor = sel.porcentaje ? `${sel.monto || 0}%` : `${sel.monto || 0} Bs`;
		this.asignaciones = [
			...this.asignaciones,
			{ nro: this.asignaciones.length + 1, tipo: isBeca ? 'BECA' : 'DESCUENTO', nombre, valor, estado: 'Activo' }
		];
	}

	cancelarSeleccion(): void {
		this.selectedRowKey = null;
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
	}
}
