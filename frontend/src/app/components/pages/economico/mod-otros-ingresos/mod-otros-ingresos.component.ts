import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { forkJoin, of } from 'rxjs';
import { map, switchMap } from 'rxjs/operators';
import { OtrosIngresosService } from '../../../../services/otros-ingresos.service';
import { CarreraService } from '../../../../services/carrera.service';
import { Carrera } from '../../../../models/carrera.model';
import { Pensum } from '../../../../models/materia.model';

@Component({
	selector: 'app-mod-otros-ingresos',
	standalone: true,
	imports: [CommonModule, FormsModule],
	templateUrl: './mod-otros-ingresos.component.html',
	styleUrls: ['./mod-otros-ingresos.component.scss'],
})
export class ModOtrosIngresosComponent implements OnInit {
	documento = '';
	resultados: any[] = [];
	buscado = false;
	loading = false;

	carreras: Carrera[] = [];
	gestiones: Array<{ gestion: string }> = [];
	/** cod_pensum → codigo_carrera (para mostrar carrera al editar). */
	private pensumToCarrera = new Map<string, string>();

	editId: number | null = null;
	facturaRecibo: 'F' | 'R' = 'R';
	codigoCarrera = '';
	/** Pensum persistido / resuelto al cambiar carrera. */
	codPensum = '';
	gestionSel = '';
	razonSocial = '';
	nit = '';
	numFactura: number | null = 0;
	numRecibo: number | null = 0;
	autorizacion = '';
	importe: number | null = 0;
	descuento: number | null = 0;
	importeNeto = 0;
	/** yyyy-mm-dd — hasta hoy inclusive; sin fechas futuras. */
	fechaEdicionIso = '';
	concepto = '';
	anular = false;
	saving = false;

	alertMsg = '';
	alertOk = true;

	directivas: Array<{ numero_aut: string; tipo_facturacion?: string; label?: string }> = [];

	constructor(
		private readonly svc: OtrosIngresosService,
		private readonly carreraService: CarreraService
	) {}

	get maxFechaIso(): string {
		return this.toIsoLocal(new Date());
	}

	readonly minFechaIso = '1990-01-01';

	private toIsoLocal(d: Date): string {
		const y = d.getFullYear();
		const m = String(d.getMonth() + 1).padStart(2, '0');
		const day = String(d.getDate()).padStart(2, '0');
		return `${y}-${m}-${day}`;
	}

	private rowFechaToIso(val: string | null | undefined): string {
		if (!val) {
			return this.maxFechaIso;
		}
		const dt = new Date(val);
		if (Number.isNaN(dt.getTime())) {
			return this.maxFechaIso;
		}
		return this.toIsoLocal(dt);
	}

	private clampIsoToMax(iso: string): string {
		return iso > this.maxFechaIso ? this.maxFechaIso : iso;
	}

	private dmyFromIso(iso: string): string {
		if (!iso || !/^\d{4}-\d{2}-\d{2}$/.test(iso)) {
			return '';
		}
		const [y, m, d] = iso.split('-');
		return `${d}/${m}/${y}`;
	}

	private fechaIsoPermitida(iso: string): boolean {
		return !!iso && iso <= this.maxFechaIso && iso >= this.minFechaIso;
	}

	ngOnInit(): void {
		this.svc.getModInitial().subscribe({
			next: (res: any) => {
				const d = res?.data ?? res;
				this.gestiones = d.gestiones ?? [];
			},
			error: () => {},
		});

		this.carreraService
			.getAll()
			.pipe(
				switchMap((r) => {
					const list = (r?.data ?? []).filter((c) => c.estado !== false);
					this.carreras = list;
					if (!list.length) {
						return of([]);
					}
					return forkJoin(
						list.map((c) =>
							this.carreraService.getPensums(c.codigo_carrera).pipe(
								map((resp) => ({
									codigo_carrera: c.codigo_carrera,
									pensums: resp?.data ?? [],
								}))
							)
						)
					);
				})
			)
			.subscribe({
				next: (blocks) => {
					this.pensumToCarrera.clear();
					for (const b of blocks) {
						for (const p of b.pensums as Pensum[]) {
							this.pensumToCarrera.set(p.cod_pensum, b.codigo_carrera);
						}
					}
				},
				error: () => {},
			});
	}

	private static numSuffixCodPensum(cod: string): number {
		const m = /-(\d+)$/.exec((cod || '').trim());
		return m ? parseInt(m[1], 10) : 0;
	}

	/** Misma regla que otros-ingresos: pensum más actual (mayor sufijo en código, p. ej. EEA-26). */
	private pickMostCurrentPensum(pensums: Pensum[]): string | null {
		if (!pensums?.length) {
			return null;
		}
		const active = pensums.filter(
			(p) => p.estado !== false && (p as { activo?: boolean }).activo !== false,
		);
		const pool = active.length ? active : [...pensums];
		const ts = (p: Pensum) =>
			(p as { updated_at?: string; created_at?: string }).updated_at ||
			(p as { created_at?: string }).created_at ||
			'';
		const best = pool
			.map((p) => ({
				p,
				n: ModOtrosIngresosComponent.numSuffixCodPensum(p.cod_pensum),
				orden: p.orden ?? 0,
				t: ts(p),
			}))
			.sort((a, b) => {
				if (b.n !== a.n) {
					return b.n - a.n;
				}
				if (b.orden !== a.orden) {
					return b.orden - a.orden;
				}
				return b.t.localeCompare(a.t);
			})[0];
		return best?.p.cod_pensum ?? null;
	}

	onCarreraChange(): void {
		if (!this.codigoCarrera) {
			this.codPensum = '';
			this.directivas = [];
			return;
		}
		this.carreraService.getPensums(this.codigoCarrera).subscribe({
			next: (res) => {
				const pensums = res?.data ?? [];
				const cod = this.pickMostCurrentPensum(pensums);
				if (!cod) {
					this.toast('La carrera no tiene pensum configurado.', false);
					this.codPensum = '';
					this.directivas = [];
					return;
				}
				this.codPensum = cod;
				this.refrescarDirectivasMod();
			},
			error: () => this.toast('No se pudieron cargar pensums de la carrera.', false),
		});
	}

	onGestionChangeMod(): void {
		this.refrescarDirectivasMod();
	}

	private refrescarDirectivasMod(): void {
		if (!this.gestionSel || !this.codPensum) {
			this.directivas = [];
			return;
		}
		this.svc.getDirectivasGestion(this.gestionSel, this.codPensum).subscribe({
			next: (rows) => {
				let list = rows ?? [];
				const aut = (this.autorizacion || '').trim();
				if (aut && !list.some((d) => d.numero_aut === aut)) {
					list = [...list, { numero_aut: aut, label: aut + ' (actual)' }];
				}
				this.directivas = list;
			},
			error: () => {
				this.directivas = [];
			},
		});
	}

	/** Resultados de búsqueda ordenados y agrupados por carrera. */
	get gruposResultados(): { titulo: string; filas: any[] }[] {
		const map = new Map<string, { titulo: string; filas: any[] }>();
		for (const r of this.resultados) {
			const cc = String(r.codigo_carrera || this.pensumToCarrera.get(String(r.cod_pensum ?? '')) || '');
			const titulo =
				this.carreras.find((c) => c.codigo_carrera === cc)?.nombre || (cc ? cc : 'Sin carrera');
			const key = cc || titulo;
			if (!map.has(key)) {
				map.set(key, { titulo, filas: [] });
			}
			map.get(key)!.filas.push(r);
		}
		return [...map.values()].sort((a, b) => a.titulo.localeCompare(b.titulo));
	}

	buscar(): void {
		const d = (this.documento || '').trim();
		if (!d) {
			this.toast('Ingrese un número.', false);
			return;
		}
		this.loading = true;
		this.buscado = false;
		this.svc.buscar(d).subscribe({
			next: (res: any) => {
				this.loading = false;
				this.buscado = true;
				this.resultados = res?.data ?? [];
			},
			error: () => {
				this.loading = false;
				this.toast('Error en la búsqueda.', false);
			},
		});
	}

	formatFecha(iso: string | null | undefined): string {
		if (!iso) {
			return '';
		}
		const dt = new Date(iso);
		if (Number.isNaN(dt.getTime())) {
			return String(iso);
		}
		const dd = String(dt.getDate()).padStart(2, '0');
		const mm = String(dt.getMonth() + 1).padStart(2, '0');
		const yy = dt.getFullYear();
		const hh = String(dt.getHours()).padStart(2, '0');
		const mi = String(dt.getMinutes()).padStart(2, '0');
		const ss = String(dt.getSeconds()).padStart(2, '0');
		return `${dd}/${mm}/${yy} ${hh}:${mi}:${ss}`;
	}

	abrirEdicion(r: any): void {
		this.editId = r.id;
		this.codPensum = r.cod_pensum;
		this.codigoCarrera = this.pensumToCarrera.get(String(r.cod_pensum ?? '')) ?? '';
		this.gestionSel = r.gestion;
		this.razonSocial = r.razon_social ?? '';
		this.nit = r.nit ?? '';
		this.numFactura = r.num_factura;
		this.numRecibo = r.num_recibo;
		this.autorizacion = r.autorizacion ?? '';
		this.importe = Number(r.subtotal) || Number(r.monto) || 0;
		this.descuento = Number(r.descuento) || 0;
		this.calcNeto();
		const rawIso = this.rowFechaToIso(r.fecha);
		this.fechaEdicionIso = this.clampIsoToMax(rawIso);
		this.concepto = r.concepto ?? '';
		this.anular = r.valido === 'A';
		this.facturaRecibo = r.factura_recibo === 'F' ? 'F' : 'R';
		this.refrescarDirectivasMod();
	}

	calcNeto(): void {
		const bruto = Number(this.importe) || 0;
		const desc = Number(this.descuento) || 0;
		this.importeNeto = Math.max(0, bruto - desc);
	}

	cerrarEdicion(): void {
		this.editId = null;
	}

	guardar(): void {
		if (this.editId === null) {
			return;
		}
		if (!this.codigoCarrera || !this.codPensum) {
			this.toast('Seleccione carrera.', false);
			return;
		}
		if (!this.fechaIsoPermitida(this.fechaEdicionIso)) {
			this.toast(
				'La fecha no puede ser futura. La más tardía permitida es ' + this.dmyFromIso(this.maxFechaIso) + '.',
				false,
			);
			return;
		}
		this.saving = true;
		const payload = {
			id: this.editId,
			num_factura: this.facturaRecibo === 'F' ? (Number(this.numFactura) || 0) : 0,
			num_recibo: this.facturaRecibo === 'R' ? (Number(this.numRecibo) || 0) : 0,
			razon_social: this.razonSocial,
			nit: this.nit,
			autorizacion: this.autorizacion,
			fecha: this.dmyFromIso(this.fechaEdicionIso),
			monto: this.importeNeto,
			valido: this.anular ? 'A' : 'S',
			concepto: this.concepto,
			cod_pensum: this.codPensum,
			codigo_carrera: this.codigoCarrera || null,
			gestion: this.gestionSel,
			subtotal: Number(this.importe) || 0,
			descuento: Number(this.descuento) || 0,
			observaciones: this.concepto,
		};
		this.svc.registrarMod(payload).subscribe({
			next: (r: any) => {
				this.saving = false;
				if (r?.estado === 'exito') {
					this.toast('Registro actualizado.', true);
					this.cerrarEdicion();
					this.buscar();
				} else {
					this.toast(r?.estado || 'No se pudo modificar.', false);
				}
			},
			error: (err) => {
				this.saving = false;
				const e = err?.error;
				let m = e?.message ?? 'Error al modificar.';
				if (e?.errors && typeof e.errors === 'object') {
					const first = Object.values(e.errors)[0];
					if (Array.isArray(first) && first[0]) {
						m = String(first[0]);
					}
				}
				this.toast(m, false);
			},
		});
	}

	eliminar(r: any): void {
		if (!confirm('¿Eliminar este registro?')) {
			return;
		}
		this.svc.eliminar(r.id).subscribe({
			next: (res: any) => {
				if (res?.success) {
					this.toast('Eliminado.', true);
					this.buscar();
				} else {
					this.toast(res?.message || 'No se pudo eliminar.', false);
				}
			},
			error: () => this.toast('Error al eliminar.', false),
		});
	}

	private toast(msg: string, ok: boolean): void {
		this.alertMsg = msg;
		this.alertOk = ok;
		setTimeout(() => (this.alertMsg = ''), 4000);
	}
}
